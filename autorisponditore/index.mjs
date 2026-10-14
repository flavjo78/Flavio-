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
  // --- canale dedicato "PALCO" per gli eventi grandi (SMTP) ---
  // Ora si usa MAILGUN; i vecchi BREVO_* restano accettati per compatibilita'.
  MAILGUN_HOST,
  MAILGUN_PORT,
  MAILGUN_USER,
  MAILGUN_PASS,
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

// PARALLELO + ANTI-INGORGO (regolabili da variabili d'ambiente):
//  ROBOTTINI   = quante caselle vengono servite INSIEME nello stesso giro (parallelo).
//  MAX_PER_GIRO = quante mail al massimo risponde a UNA casella in un singolo giro,
//                 poi passa oltre (le altre partono al giro dopo): così una casella
//                 con tante mail non "ingolfa" il robottino e tutti ricevono un po'.
const ROBOTTINI    = Math.max(1, Number(process.env.ROBOTTINI || 20));
const MAX_PER_GIRO = Math.max(1, Number(process.env.MAX_PER_GIRO || 25));

// esegue una lista di lavori con AL MASSIMO "limite" in parallelo (pool di worker)
async function inParallelo(lista, limite, fn) {
  const esiti = new Array(lista.length);
  let prossimo = 0;
  async function worker() {
    while (prossimo < lista.length) {
      const i = prossimo++;
      try { esiti[i] = await fn(lista[i]); }
      catch (e) { esiti[i] = { errore: e && e.message ? e.message : String(e) }; }
    }
  }
  const n = Math.min(Math.max(1, limite), lista.length || 1);
  await Promise.all(Array.from({ length: n }, worker));
  return esiti;
}

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

/* riconosce le mail AUTOMATICHE (no-reply, newsletter, notifiche, bounce):
   NON sono spettatori -> niente conteggio, niente risposta. */
function mittenteAutomatico(email) {
  const e = String(email || '').toLowerCase().trim();
  if (!e) return false;
  const at = e.indexOf('@');
  const local = at >= 0 ? e.slice(0, at) : e;
  const dom = at >= 0 ? e.slice(at + 1) : '';
  if (/(^|[._+-])(no-?reply|no_reply|do-?not-?reply|donotreply|noreply|mailer-daemon|postmaster|bounce|bounces|notification|notifications|notify|newsletter|news|updates?|mailer|automated|auto-confirm)([._+-]|$)/.test(local)) {
    return true;
  }
  const domini = ['mailgun.com','mailgun.org','sendgrid.net','mailchimp.com','mcsv.net','sparkpostmail.com','amazonses.com','customer.io','sendinblue.com','brevo.com'];
  for (const d of domini) { if (dom === d || dom.endsWith('.' + d)) return true; }
  return false;
}

/* id "pulito" per il destinatario (anti-cache + log lato image.php) */
function idPulito(email) {
  const base = (email || 'x').replace(/[^A-Za-z0-9_\-]/g, '');
  return (base.slice(0, 36) || 'x') + '-' + Date.now().toString(36).slice(-4);
}

/* hash corto e stabile di una stringa (base36) */
function hashBreve(s) {
  let h = 5381; s = String(s || '');
  for (let i = 0; i < s.length; i++) { h = ((h << 5) + h + s.charCodeAt(i)) >>> 0; }
  return h.toString(36);
}

/* id STABILE del destinatario: dipende SOLO dalla mail e dalla sua chiave
   (Message-ID), non dall'orario. Cosi' l'id con cui registriamo la mail in
   arrivo e' lo STESSO che mettiamo nell'immagine della risposta: nel report la
   riga "mail ricevuta" e la riga "aperture" si uniscono (prima restavano sfalsate
   perche' l'id cambiava tra la registrazione e la risposta). */
function idStabile(email, chiave) {
  const base = (email || 'x').replace(/[^A-Za-z0-9_\-]/g, '').slice(0, 36) || 'x';
  return base + '-' + hashBreve(chiave || email);
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
    return { utente, saltato: true, sessione: stato.sessione, fase: stato.fase, giro_veloce: false };
  }
  // risponde solo a gioco ATTIVO (avviato = mostra A, cambiato = mostra B)
  const gioco_attivo = stato.sessione > 0 && (stato.fase === 'avviato' || stato.fase === 'cambiato');
  // giri VELOCI (ogni ~12s) solo se il gioco è attivo e NON è in fase "rallentato"
  // della sicurezza (vicino allo spegnimento automatico): allora torna lento.
  const giro_veloce = gioco_attivo && stato.sicurezzaFase !== 'rallentato' && stato.sicurezzaFase !== 'fermo';
  // "TASTO RISPONDI" attivo ma non ancora premuto: NON rispondiamo e NON segnamo
  // le mail come lette (restano in attesa del "Rispondi mail"), pero' le LEGGIAMO
  // lo stesso per CONTARE quelle ricevute (il contatore "Mail ricevute" deve salire
  // anche prima della risposta). Il conteggio e' idempotente grazie al 'msgid'.
  const soloConteggio = (stato.rispondiManuale && !stato.rispondiOk);

  const client = new ImapFlow({
    host: imap.host,
    port: Number(imap.port),
    secure: true,
    auth: { user: imap.user, pass: imap.pass },
    logger: false,
  });
  await client.connect();

  let lette = 0, risposte = 0, errori = 0, fatti = 0;
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const uids = await client.search({ seen: false }, { uid: true });
      for (const uid of uids) {
        // ANTI-INGORGO: massimo MAX_PER_GIRO mail a questa casella per giro, poi basta
        // (le altre partono al giro dopo, ~12s). Così una casella piena non blocca le altre.
        if (fatti >= MAX_PER_GIRO) break;
        // ANTI-DOPPIONE: in modo normale segna LETTA prima di rispondere (una mail =
        // una risposta). In modo "solo conteggio" NON la segniamo letta: resta in
        // attesa del "Rispondi mail" (la rileggeremo, ma non la conteremo due volte).
        if (!soloConteggio) {
          try {
            await client.messageFlagsAdd(uid, ['\\Seen'], { uid: true });
          } catch (e) {
            console.log('claim (Seen) fallito', utente, uid, e.message, '-> salto');
            continue;
          }
        }

        let mittente = '', mOggetto = '', mTesto = '', arrivataMs = 0, msgid = '';
        try {
          const msg = await client.fetchOne(uid, { source: true, internalDate: true }, { uid: true });
          const parsed = await simpleParser(msg.source);
          mittente = parsed?.from?.value?.[0]?.address || '';
          mOggetto = parsed?.subject || '';
          mTesto   = parsed?.text || '';
          msgid    = parsed?.messageId || '';
          if (msg.internalDate) arrivataMs = new Date(msg.internalDate).getTime();
        } catch (e) {
          console.log('parsing mail fallito', utente, uid, e.message);
        }
        // chiave STABILE della mail (per non contarla due volte tra un giro e l'altro)
        const chiave = msgid || ('uid-' + utente + '-' + stato.sessione + '-' + uid);
        // id STABILE del destinatario: lo stesso per la registrazione E per l'immagine
        // della risposta, cosi' nel report le righe si uniscono invece di sfalsarsi.
        const id = idStabile(mittente, chiave);

        // ANTI-LOOP: se la mail arriva dal nostro stesso indirizzo (o e' una
        // nostra risposta rientrata), la ignoriamo. E' gia' segnata \Seen sopra,
        // quindi non verra' riletta: niente conteggio, niente risposta.
        if (mittente && selfEmails.includes(mittente.toLowerCase())) {
          console.log('salto auto-mail', utente, '<-', mittente);
          continue;
        }

        // SALTA LE MAIL AUTOMATICHE (no-reply, newsletter, notifiche): non sono
        // spettatori -> niente conteggio, niente risposta (e' gia' \Seen sopra).
        if (mittente && mittenteAutomatico(mittente)) {
          console.log('salto mail automatica', utente, '<-', mittente);
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

        // MODO "SOLO CONTEGGIO" (Rispondi a mano, non ancora premuto): registra la
        // mail come RICEVUTA (idempotente grazie a 'msgid'), NON risponde, NON la
        // segna letta. Cosi' "Mail ricevute" sale e "Mail inviate" resta a 0.
        if (soloConteggio) {
          if (gioco_attivo && mittente && panelPass) {
            try {
              await postPannello({
                azione: 'mail_log', utente: panelUser, password: panelPass,
                sessione: stato.sessione, mittente, oggetto: mOggetto, testo: mTesto,
                id, msgid: chiave,
              });
            } catch (e) { console.log('mail_log (attesa) fallito', utente, e.message); }
          }
          fatti++;   // conta per il tetto "a turni"
          continue;
        }

        if (gioco_attivo && mittente) {
          try {
            await invia(mittente, stato.oggetto, corpoHtml(utente, stato.sessione, id, stato.intro));
            risposte++; fatti++;   // conta per il tetto "a turni"
            // registra il mittente per il report di sessione di QUESTO utente
            if (panelPass) {
              try {
                await postPannello({
                  azione: 'mail_log', utente: panelUser, password: panelPass,
                  sessione: stato.sessione, mittente, oggetto: mOggetto, testo: mTesto, id, msgid: chiave,
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

  // conteggi "inviate" nel pannello di QUESTO utente (le "ricevute" le ha gia'
  // registrate mail_log, una per mail). In modo "solo conteggio" non c'e' nulla
  // da inviare, quindi non chiamiamo mail_report.
  if (panelPass && !soloConteggio && risposte > 0) {
    try {
      await postPannello({
        azione: 'mail_report', utente: panelUser, password: panelPass,
        ricevute: lette, inviate: risposte,
      });
    } catch (e) { console.log('mail_report fallito', utente, e.message); }
  }

  return { utente, sessione: stato.sessione, fase: stato.fase, lette, risposte, errori, giro_veloce };
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
  // canale "Palco": preferisci MAILGUN_*; se vuoti, ripiega sui vecchi BREVO_*
  const grandeHost = MAILGUN_HOST || BREVO_HOST;
  const grandePort = MAILGUN_PORT || BREVO_PORT;
  const grandeUser = MAILGUN_USER || BREVO_USER;
  const grandePass = MAILGUN_PASS || BREVO_PASS;
  const tx = (modo === 'brevo')
    ? smtpTransport(grandeHost, grandePort, grandeUser, grandePass)
    : smtpTransport(SMTP_HOST, SMTP_PORT, SMTP_USER || IMAP_USER, SMTP_PASS || IMAP_PASS);
  await tx.sendMail({
    from: SES_FROM, to: dest, subject: oggetto,
    html, text: 'Apri questa mail con la visualizzazione immagini attiva.',
  });
}

/* UN GIRO: prepara la lista delle caselle (casa 001 + clienti attivi) e le serve
   IN PARALLELO, fino a ROBOTTINI insieme. Ritorna l'elenco degli esiti (ognuno con
   giro_veloce = true se quel gioco vuole essere ricontrollato in fretta). */
async function unGiro() {
  const lavori = [];   // ogni elemento è la "cfg" da passare a processaCasella

  // ---------- casella di CASA (001 / io@abraka.it) ----------
  let stato = null;
  try { stato = await statoCasa(); } catch (e) { console.log('stato.php non raggiungibile:', e.message); }
  if (stato) {
    const modoRaw = String(stato.invio_modo || 'casella');
    const modo    = (modoRaw === 'ses') ? 'ses' : (modoRaw === 'brevo') ? 'brevo' : 'casella';
    lavori.push({
      utente: PANEL_USER,
      imap: { host: IMAP_HOST, port: IMAP_PORT, user: IMAP_USER, pass: IMAP_PASS },
      stato: {
        sessione: Number(stato.sessione || 0),
        fase: String(stato.fase || 'spento'),
        autorisponditore: stato.autorisponditore === true,
        rispondiManuale: stato.rispondi_manuale === true,
        rispondiOk:      stato.rispondi_ok === true,
        sicurezzaFase:   (stato.sicurezza && stato.sicurezza.fase) ? String(stato.sicurezza.fase) : 'ok',
        oggetto: stato.mail_oggetto ? String(stato.mail_oggetto) : MAIL_SUBJECT,
        intro:   stato.mail_testo   ? String(stato.mail_testo)   : MAIL_INTRO,
        avvioMs: Number(stato.avvio_ts || 0) * 1000,
      },
      invia: (dest, ogg, html) => inviaCasa(modo, dest, ogg, html),
      panelUser: PANEL_USER, panelPass: PANEL_PASS,
      selfEmail: CASA_FROM_EMAIL,
    });
  }

  // ---------- caselle EXTRA (002, 003, ...) ----------
  const extra = await caselleExtra();
  for (const c of extra) {
    lavori.push({
      utente: c.utente,
      imap: { host: c.imap_host, port: c.imap_port, user: c.imap_user, pass: c.imap_pass },
      stato: {
        sessione: Number(c.sessione || 0),
        fase: String(c.fase || 'spento'),
        autorisponditore: c.autorisponditore === true,
        rispondiManuale: c.rispondi_manuale === true,
        rispondiOk:      c.rispondi_ok === true,
        sicurezzaFase:   c.sicurezza_fase ? String(c.sicurezza_fase) : 'ok',
        oggetto: c.mail_oggetto ? String(c.mail_oggetto) : MAIL_SUBJECT,
        intro:   c.mail_testo   ? String(c.mail_testo)   : MAIL_INTRO,
        avvioMs: Number(c.avvio_ts || 0) * 1000,
      },
      // gli utenti con casella propria inviano dalla LORO casella (SMTP = credenziali IMAP)
      invia: (dest, ogg, html) => smtpTransport(c.smtp_host, c.smtp_port, c.imap_user, c.imap_pass).sendMail({
        from: (c.from && c.from.includes('@')) ? c.from
              : (c.from ? { name: c.from, address: c.imap_user } : c.imap_user),
        to: dest, subject: ogg,
        html, text: 'Apri questa mail con la visualizzazione immagini attiva.',
      }),
      panelUser: c.utente, panelPass: c.pass_pannello,
      selfEmail: (c.from && c.from.includes('@')) ? c.from : c.imap_user,
    });
  }

  // PARALLELO: fino a ROBOTTINI caselle servite insieme (ognuna con il suo tetto "a turni").
  const esiti = await inParallelo(lavori, ROBOTTINI, (cfg) =>
    processaCasella(cfg).catch((e) => {
      console.log('casella fallita', cfg.utente, e && e.message);
      return { utente: cfg.utente, errore: e && e.message ? e.message : String(e) };
    })
  );
  return esiti;
}

const dormi = (ms) => new Promise((r) => setTimeout(r, ms));

export const handler = async () => {
  // GIRI VELOCI: ogni sveglia (1/minuto) fa più controlli ravvicinati (~12s)
  // FINCHÉ c'è almeno uno show attivo, così le risposte partono in ~10-12s
  // invece di ~60s. I giri si fermano prima del minuto dopo (niente sovrapposizioni),
  // e si fanno SOLO quando serve: a riposo o a show spento si esce subito (gratis).
  const INIZIO = Date.now();
  const PAUSA_MS = 12000;     // ~12 secondi tra un controllo e l'altro
  const MAX_MS   = 50000;     // esci entro ~50s: la sveglia dopo è a 60s
  let esiti = [];
  while (true) {
    esiti = await unGiro();
    const serveVeloce = esiti.some((e) => e && e.giro_veloce);   // qualche show attivo?
    if (!serveVeloce) break;                                     // niente da servire in fretta -> esci
    if (Date.now() - INIZIO + PAUSA_MS > MAX_MS) break;          // tempo quasi finito -> esci
    await dormi(PAUSA_MS);
  }
  console.log('ESITO', JSON.stringify(esiti));
  return { caselle: esiti };
};
