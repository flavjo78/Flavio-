/* =========================================================================
   PREDIZIONE — Autorisponditore MULTI-CASELLA (AWS Lambda, Node.js)
   -------------------------------------------------------------------------
   Ogni minuto (lo lancia una pianificazione):
   1) gestisce la casella "di casa" io@abraka.it (utente 001), con le
      credenziali nelle variabili d'ambiente;
   2) se e' impostata la password admin (PANEL_ADMIN_PASS), chiede a stato.php
      l'elenco delle ALTRE caselle configurate (002, 003, ...) con le loro
      credenziali e stato, e gestisce anche quelle, ognuna sulla sua casella.

   Per OGNI casella, solo se l'autorisponditore di QUELL'utente e' ON e il
   gioco e' "in corso" (fase avviato/cambiato): legge le mail non lette, segna
   ciascuna come letta PRIMA di rispondere (una mail = una risposta), risponde
   con l'immagine dinamica  image.php?u=UTENTE&s=SESSIONE&id=MITTENTE , registra
   il mittente (mail_log) e aggiorna i conteggi (mail_report) nel pannello.

   Tutte le impostazioni sono VARIABILI D'AMBIENTE (nessuna da toccare nel
   codice). IMPORTANTE: gli indirizzi (STATO_URL / IMAGE_URL_BASE) vanno con
   "www", altrimenti il redirect del sito fa perdere il corpo delle POST.
   ========================================================================= */

import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import { SESClient, SendEmailCommand } from '@aws-sdk/client-ses';
import nodemailer from 'nodemailer';

const {
  IMAP_HOST = 'pop.tophost.it',
  IMAP_PORT = '993',
  IMAP_USER,                 // nome della MAILBOX di casa (es. abraka.it)
  IMAP_PASS,                 // password della casella di casa
  SES_FROM = 'Predizione <io@abraka.it>',
  // IMPORTANTE: indirizzi CANONICI con "www" (senza www le POST perdono i dati).
  STATO_URL = 'https://www.abraka.it/predizione/stato.php',
  IMAGE_URL_BASE = 'https://www.abraka.it/predizione/image.php',
  MAIL_SUBJECT = 'La tua predizione',            // di riserva se il pannello non ha un oggetto
  MAIL_INTRO = 'Grazie per aver scritto. Ecco la tua predizione.', // idem per il testo
  MAIL_FOOTER = 'Hai ricevuto questa mail perché hai scritto durante lo spettacolo. Se non desideri altre comunicazioni, rispondi a questa mail con la parola CANCELLA.',
  AWS_REGION = 'eu-west-1',
  // --- casella di casa: invio dalla CASELLA (SMTP Tophost) ---
  SMTP_HOST = 'mail.tophost.it',
  SMTP_PORT = '587',
  SMTP_USER,                 // se vuoto usa IMAP_USER
  SMTP_PASS,                 // se vuoto usa IMAP_PASS
  // --- casella di casa: invio via BREVO (eventi grandi) ---
  BREVO_HOST = 'smtp-relay.brevo.com',
  BREVO_PORT = '587',
  BREVO_USER,
  BREVO_PASS,
  // --- conteggi/registro nel pannello (casella di casa = 001) ---
  PANEL_USER = '001',
  PANEL_PASS,                // password dell'utente 001
  // --- MULTI-CASELLA: password dell'amministratore (utente 000) ---
  // Se impostata, il robottino chiede a stato.php l'elenco delle altre caselle
  // (002, 003, ...) e le gestisce. Se vuota, lavora solo sulla casella di casa.
  PANEL_ADMIN_PASS,
} = process.env;

const ses = new SESClient({ region: AWS_REGION });

// indirizzo email "di casa" (estratto da SES_FROM, es. "Predizione <io@abraka.it>")
const CASA_FROM_EMAIL = (String(SES_FROM).match(/<([^>]+)>/) || [, SES_FROM])[1];

/* trasporti SMTP in cache (uno per host+porta+utente) */
const txCache = new Map();
function smtpTransport(host, port, user, pass) {
  const key = host + ':' + port + ':' + user;
  if (!txCache.has(key)) {
    txCache.set(key, nodemailer.createTransport({
      host,
      port: Number(port),
      secure: Number(port) === 465,   // 465 = SSL, 587 = STARTTLS
      auth: { user, pass },
    }));
  }
  return txCache.get(key);
}

/* testo dell'utente -> HTML sicuro (niente tag iniettati, a capo = <br>) */
function testoSicuro(s) {
  return String(s || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/\r?\n/g, '<br>');
}

/* id "pulito" per il destinatario (anti-cache + log lato image.php) */
function idPulito(email) {
  const base = (email || 'x').replace(/[^A-Za-z0-9_\-]/g, '');
  return (base.slice(0, 36) || 'x') + '-' + Date.now().toString(36).slice(-4);
}

/* stato del gioco della casella di casa (utente 001) */
async function statoCasa() {
  const r = await fetch(STATO_URL + '?azione=stato&u=' + encodeURIComponent(PANEL_USER), { cache: 'no-store' });
  const j = await r.json();
  return (j && j.stato) ? j.stato : null;
}

/* elenco delle ALTRE caselle (002, 003, ...) — serve la password admin */
async function caselleExtra() {
  if (!PANEL_ADMIN_PASS) return [];
  try {
    const rb = new URLSearchParams();
    rb.set('azione', 'admin_caselle');
    rb.set('utente', '000');
    rb.set('password', PANEL_ADMIN_PASS);
    const r = await fetch(STATO_URL, { method: 'POST', body: rb });
    const j = await r.json();
    return (j && j.ok && Array.isArray(j.caselle)) ? j.caselle : [];
  } catch (e) {
    console.log('admin_caselle fallito:', e.message);
    return [];
  }
}

/* corpo HTML della risposta, con l'immagine dinamica (porta ?u=UTENTE) */
function corpoHtml(utente, sessione, id, intro) {
  const src = `${IMAGE_URL_BASE}?u=${encodeURIComponent(utente)}&s=${encodeURIComponent(sessione)}&id=${encodeURIComponent(id)}`;
  return `<!doctype html><html><body style="margin:0;background:#0a0908;color:#ece0c9;font-family:Georgia,'Times New Roman',serif">
  <div style="max-width:560px;margin:0 auto;padding:26px 20px">
    <p style="font-size:16px;line-height:1.6;margin:0 0 18px">${testoSicuro(intro)}</p>
    <img src="${src}" width="520" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:10px" alt="">
    <p style="font-size:12px;line-height:1.5;color:#8a8069;margin:22px 0 0;border-top:1px solid #2a2620;padding-top:14px">${MAIL_FOOTER}</p>
  </div>
</body></html>`;
}

/* piccola POST a stato.php (mail_log / mail_report) per un dato utente */
async function postPannello(campi) {
  const b = new URLSearchParams();
  for (const k in campi) b.set(k, String(campi[k]));
  await fetch(STATO_URL, { method: 'POST', body: b });
}

/* Gestisce UNA casella: legge le mail non lette e, a gioco attivo, risponde.
   cfg = {
     utente, imap:{host,port,user,pass},
     stato:{sessione,fase,autorisponditore,oggetto,intro},
     invia: async (dest, oggetto, html) => {...},   // come spedire da questa casella
     panelUser, panelPass                            // per scrivere conteggi/registro
   }
*/
async function processaCasella(cfg) {
  const { utente, imap, stato, invia, panelUser, panelPass } = cfg;

  // ANTI-LOOP: indirizzi "propri" a cui NON rispondere mai (altrimenti la
  // risposta rientra come nuova mail e parte un giro infinito). Include
  // l'indirizzo della casella e quello da cui spediamo.
  const selfEmails = [cfg.selfEmail, imap.user]
    .filter(Boolean).map((s) => String(s).toLowerCase());

  if (!stato.autorisponditore) {
    return { utente, saltato: true, sessione: stato.sessione, fase: stato.fase };
  }
  // risponde solo a gioco ATTIVO (avviato = mostra A, cambiato = mostra B)
  const gioco_attivo = stato.sessione > 0 && (stato.fase === 'avviato' || stato.fase === 'cambiato');

  const client = new ImapFlow({
    host: imap.host,
    port: Number(imap.port),
    secure: true,
    auth: { user: imap.user, pass: imap.pass },
    logger: false,
  });
  await client.connect();

  let lette = 0, risposte = 0, errori = 0;
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const uids = await client.search({ seen: false }, { uid: true });
      for (const uid of uids) {
        // ANTI-DOPPIONE: segna LETTA prima di rispondere (una mail = una risposta)
        try {
          await client.messageFlagsAdd(uid, ['\\Seen'], { uid: true });
        } catch (e) {
          console.log('claim (Seen) fallito', utente, uid, e.message, '-> salto');
          continue;
        }

        let mittente = '', mOggetto = '', mTesto = '', arrivataMs = 0;
        try {
          const msg = await client.fetchOne(uid, { source: true, internalDate: true }, { uid: true });
          const parsed = await simpleParser(msg.source);
          mittente = parsed?.from?.value?.[0]?.address || '';
          mOggetto = parsed?.subject || '';
          mTesto   = parsed?.text || '';
          if (msg.internalDate) arrivataMs = new Date(msg.internalDate).getTime();
        } catch (e) {
          console.log('parsing mail fallito', utente, uid, e.message);
        }

        // ANTI-LOOP: se la mail arriva dal nostro stesso indirizzo (o e' una
        // nostra risposta rientrata), la ignoriamo. E' gia' segnata \Seen sopra,
        // quindi non verra' riletta: niente conteggio, niente risposta.
        if (mittente && selfEmails.includes(mittente.toLowerCase())) {
          console.log('salto auto-mail', utente, '<-', mittente);
          continue;
        }

        // SALTA POSTA VECCHIA: se la mail e' ARRIVATA PRIMA dell'orario di "Avvia"
        // di questa sessione, non e' uno spettatore di adesso -> la ignoriamo
        // (gia' \Seen): niente conteggio, niente risposta. Cosi' la sessione conta
        // solo chi scrive DOPO l'avvio (niente arretrati ne' pubblicita' pregresse).
        if (stato.avvioMs && arrivataMs && arrivataMs < stato.avvioMs) {
          console.log('salto mail precedente all\'avvio', utente, '<-', mittente);
          continue;
        }
        lette++;

        if (gioco_attivo && mittente) {
          try {
            const id = idPulito(mittente);
            await invia(mittente, stato.oggetto, corpoHtml(utente, stato.sessione, id, stato.intro));
            risposte++;
            // registra il mittente per il report di sessione di QUESTO utente
            if (panelPass) {
              try {
                await postPannello({
                  azione: 'mail_log', utente: panelUser, password: panelPass,
                  sessione: stato.sessione, mittente, oggetto: mOggetto, testo: mTesto, id,
                });
              } catch (e) { console.log('mail_log fallito', utente, e.message); }
            }
          } catch (e) {
            errori++;
            console.log('invio fallito', utente, '->', mittente, e.message);
          }
        }
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }

  // conteggi nel pannello di QUESTO utente
  if (panelPass && (lette > 0 || risposte > 0)) {
    try {
      await postPannello({
        azione: 'mail_report', utente: panelUser, password: panelPass,
        ricevute: lette, inviate: risposte,
      });
    } catch (e) { console.log('mail_report fallito', utente, e.message); }
  }

  return { utente, sessione: stato.sessione, fase: stato.fase, lette, risposte, errori };
}

/* invio dalla casella di CASA (001): sceglie il canale (casella/brevo/ses) */
async function inviaCasa(modo, dest, oggetto, html) {
  if (modo === 'ses') {
    await ses.send(new SendEmailCommand({
      Source: SES_FROM,
      Destination: { ToAddresses: [dest] },
      Message: {
        Subject: { Data: oggetto, Charset: 'UTF-8' },
        Body: { Html: { Data: html, Charset: 'UTF-8' } },
      },
    }));
    return;
  }
  const tx = (modo === 'brevo')
    ? smtpTransport(BREVO_HOST, BREVO_PORT, BREVO_USER, BREVO_PASS)
    : smtpTransport(SMTP_HOST, SMTP_PORT, SMTP_USER || IMAP_USER, SMTP_PASS || IMAP_PASS);
  await tx.sendMail({
    from: SES_FROM, to: dest, subject: oggetto,
    html, text: 'Apri questa mail con la visualizzazione immagini attiva.',
  });
}

export const handler = async () => {
  const esiti = [];

  // ---------- casella di CASA (001 / io@abraka.it) ----------
  let stato = null;
  try { stato = await statoCasa(); } catch (e) { console.log('stato.php non raggiungibile:', e.message); }
  if (stato) {
    const modoRaw = String(stato.invio_modo || 'casella');
    const modo    = (modoRaw === 'ses') ? 'ses' : (modoRaw === 'brevo') ? 'brevo' : 'casella';
    try {
      esiti.push(await processaCasella({
        utente: PANEL_USER,
        imap: { host: IMAP_HOST, port: IMAP_PORT, user: IMAP_USER, pass: IMAP_PASS },
        stato: {
          sessione: Number(stato.sessione || 0),
          fase: String(stato.fase || 'spento'),
          autorisponditore: stato.autorisponditore === true,
          oggetto: stato.mail_oggetto ? String(stato.mail_oggetto) : MAIL_SUBJECT,
          intro:   stato.mail_testo   ? String(stato.mail_testo)   : MAIL_INTRO,
          avvioMs: Number(stato.avvio_ts || 0) * 1000,   // orario di "Avvia" (ms): salta la posta precedente
        },
        invia: (dest, ogg, html) => inviaCasa(modo, dest, ogg, html),
        panelUser: PANEL_USER, panelPass: PANEL_PASS,
        selfEmail: CASA_FROM_EMAIL,            // io@abraka.it: non rispondere a se stessa
      }));
    } catch (e) {
      console.log('casella di casa fallita:', e.message);
      esiti.push({ utente: PANEL_USER, errore: e.message });
    }
  }

  // ---------- caselle EXTRA (002, 003, ...) ----------
  const extra = await caselleExtra();
  for (const c of extra) {
    try {
      esiti.push(await processaCasella({
        utente: c.utente,
        imap: { host: c.imap_host, port: c.imap_port, user: c.imap_user, pass: c.imap_pass },
        stato: {
          sessione: Number(c.sessione || 0),
          fase: String(c.fase || 'spento'),
          autorisponditore: c.autorisponditore === true,
          oggetto: c.mail_oggetto ? String(c.mail_oggetto) : MAIL_SUBJECT,
          intro:   c.mail_testo   ? String(c.mail_testo)   : MAIL_INTRO,
          avvioMs: Number(c.avvio_ts || 0) * 1000,   // orario di "Avvia" (ms): salta la posta precedente
        },
        // gli utenti con casella propria inviano dalla LORO casella (SMTP = stesse
        // credenziali dell'IMAP; per Gmail: indirizzo + password per app).
        // "from": se c.from e' un'email la uso cosi'; se e' solo un nome diventa
        // "Nome <indirizzo>"; se manca uso l'indirizzo della casella.
        invia: (dest, ogg, html) => smtpTransport(c.smtp_host, c.smtp_port, c.imap_user, c.imap_pass).sendMail({
          from: (c.from && c.from.includes('@')) ? c.from
                : (c.from ? { name: c.from, address: c.imap_user } : c.imap_user),
          to: dest, subject: ogg,
          html, text: 'Apri questa mail con la visualizzazione immagini attiva.',
        }),
        panelUser: c.utente, panelPass: c.pass_pannello,
        selfEmail: (c.from && c.from.includes('@')) ? c.from : c.imap_user, // non rispondere a se stessa
      }));
    } catch (e) {
      console.log('casella fallita', c.utente, e.message);
      esiti.push({ utente: c.utente, errore: e.message });
    }
  }

  console.log('ESITO', JSON.stringify(esiti));
  return { caselle: esiti };
};
