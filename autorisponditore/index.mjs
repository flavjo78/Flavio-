/* =========================================================================
   PREDIZIONE — Autorisponditore (AWS Lambda, Node.js)
   -------------------------------------------------------------------------
   Ogni volta che viene eseguito (lo lancia una pianificazione ogni minuto):
   1) legge lo STATO del gioco da stato.php (sessione corrente + fase);
   2) si collega alla casella io@abraka.it via IMAP e legge le mail NON lette;
   3) per ogni mail, SOLO se il gioco e' "IN CORSO" (fase = avviato), invia una
      risposta via Amazon SES con l'immagine dinamica  image.php?s=SESSIONE&id=MITTENTE ;
   4) segna la mail come letta (cosi' non risponde due volte).

   INTERRUTTORE GENERALE: nel pannello (Impostazioni) c'e' un interruttore
   "Autorisponditore" ON/OFF. Se e' OFF, questa funzione si sveglia ma esce
   subito senza fare nulla (non legge la posta, non risponde): cosi' non serve
   spegnere la pianificazione su AWS quando non c'e' spettacolo.

   Con l'interruttore ON, risponde SOLO mentre il gioco e' "in corso":
   - PRIMA di "Avvia" (spento)  -> non invia nulla;
   - dopo "Finisci" (terminato) -> non invia piu' nulla (sessione chiusa).
   In entrambi i casi segna comunque le mail come lette (non verranno processate
   di nuovo). Nota operativa: premi "Finisci" ~1 minuto dopo l'ultima mail, cosi'
   tutte le mail arrivate durante il gioco fanno in tempo a ricevere la risposta.

   Tutte le impostazioni sono VARIABILI D'AMBIENTE (si impostano nella
   configurazione della Lambda, senza toccare il codice) — vedi la guida.
   ========================================================================= */

import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import { SESClient, SendEmailCommand } from '@aws-sdk/client-ses';
import nodemailer from 'nodemailer';

const {
  IMAP_HOST = 'pop.tophost.it',
  IMAP_PORT = '993',
  IMAP_USER,                 // nome della MAILBOX (es. abraka.it)
  IMAP_PASS,                 // password della casella
  SES_FROM = 'Predizione <io@abraka.it>',
  STATO_URL = 'https://abraka.it/predizione/stato.php',
  IMAGE_URL_BASE = 'https://abraka.it/predizione/image.php',
  MAIL_SUBJECT = 'La tua predizione',            // usato solo se il pannello non ha un oggetto
  MAIL_INTRO = 'Grazie per aver scritto. Ecco la tua predizione.', // idem per il testo
  MAIL_FOOTER = 'Hai ricevuto questa mail perché hai scritto a io@abraka.it durante lo spettacolo. Se non desideri altre comunicazioni, rispondi a questa mail con la parola CANCELLA. Contatto: io@abraka.it',
  AWS_REGION = 'eu-west-1',
  // --- invio dalla CASELLA (SMTP Tophost) per gli eventi piccoli ---
  // Tophost: server in uscita mail.tophost.it, porta 587 (STARTTLS), utente = abraka.it
  SMTP_HOST = 'mail.tophost.it',
  SMTP_PORT = '587',
  SMTP_USER,                 // se vuoto usa IMAP_USER
  SMTP_PASS,                 // se vuoto usa IMAP_PASS
} = process.env;

const ses = new SESClient({ region: AWS_REGION });

/* trasporto SMTP creato una sola volta, solo quando serve */
let smtpTx = null;
function smtpTransport() {
  if (!smtpTx) {
    smtpTx = nodemailer.createTransport({
      host: SMTP_HOST,
      port: Number(SMTP_PORT),
      secure: Number(SMTP_PORT) === 465,     // 465 = SSL, 587 = STARTTLS
      auth: { user: SMTP_USER || IMAP_USER, pass: SMTP_PASS || IMAP_PASS },
    });
  }
  return smtpTx;
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

/* stato del gioco letto da stato.php */
async function statoCorrente() {
  const r = await fetch(STATO_URL + '?azione=stato', { cache: 'no-store' });
  const j = await r.json();
  return (j && j.stato) ? j.stato : null;
}

/* corpo HTML della risposta, con l'immagine dinamica.
   intro = testo scritto dal pannello (o MAIL_INTRO di riserva). */
function corpoHtml(sessione, id, intro) {
  const src = `${IMAGE_URL_BASE}?s=${encodeURIComponent(sessione)}&id=${encodeURIComponent(id)}`;
  return `<!doctype html><html><body style="margin:0;background:#0a0908;color:#ece0c9;font-family:Georgia,'Times New Roman',serif">
  <div style="max-width:560px;margin:0 auto;padding:26px 20px">
    <p style="font-size:16px;line-height:1.6;margin:0 0 18px">${testoSicuro(intro)}</p>
    <img src="${src}" width="520" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:10px" alt="">
    <p style="font-size:12px;line-height:1.5;color:#8a8069;margin:22px 0 0;border-top:1px solid #2a2620;padding-top:14px">${MAIL_FOOTER}</p>
  </div>
</body></html>`;
}

/* invia UNA mail, scegliendo la via: 'smtp' (casella Tophost) o 'ses' (Amazon). */
async function inviaMail(modo, dest, oggetto, html) {
  if (modo === 'smtp') {
    await smtpTransport().sendMail({
      from: SES_FROM, to: dest, subject: oggetto,
      html, text: 'Apri questa mail con la visualizzazione immagini attiva.',
    });
  } else {
    await ses.send(new SendEmailCommand({
      Source: SES_FROM,
      Destination: { ToAddresses: [dest] },
      Message: {
        Subject: { Data: oggetto, Charset: 'UTF-8' },
        Body: { Html: { Data: html, Charset: 'UTF-8' } },
      },
    }));
  }
}

export const handler = async () => {
  // 1) stato del gioco
  let stato = null;
  try { stato = await statoCorrente(); } catch (e) { console.log('stato.php non raggiungibile:', e.message); }
  const sessione = stato ? Number(stato.sessione || 0) : 0;
  const fase = stato ? String(stato.fase || 'spento') : 'spento';

  // INTERRUTTORE dal pannello: se l'autorisponditore e' SPENTO, la Lambda si sveglia
  // ma non fa NULLA (non legge la posta, non risponde). Cosi' "non lo usi se non serve".
  const auto_on = stato ? (stato.autorisponditore === true) : false;
  if (!auto_on) {
    const esito = { autorisponditore: false, saltato: true, sessione, fase };
    console.log('ESITO', JSON.stringify(esito));
    return esito;
  }

  // risponde mentre il gioco e' ATTIVO: fase "avviato" (mostra A) o "cambiato" (mostra B).
  // NON risponde prima di "Avvia" (spento) ne' dopo "Finisci" (terminato).
  const gioco_attivo = sessione > 0 && (fase === 'avviato' || fase === 'cambiato');

  // impostazioni scelte dal pannello (via stato.php):
  //  - modo di invio: 'smtp' (casella, eventi piccoli) o 'ses' (Amazon, eventi grandi)
  //  - oggetto e testo personalizzati (se vuoti, si usano i valori di riserva)
  const modo    = (stato && stato.invio_modo === 'smtp') ? 'smtp' : 'ses';
  const oggetto = (stato && stato.mail_oggetto) ? String(stato.mail_oggetto) : MAIL_SUBJECT;
  const intro   = (stato && stato.mail_testo)   ? String(stato.mail_testo)   : MAIL_INTRO;

  // 2) connessione IMAP
  const client = new ImapFlow({
    host: IMAP_HOST,
    port: Number(IMAP_PORT),
    secure: true,
    auth: { user: IMAP_USER, pass: IMAP_PASS },
    logger: false,
  });
  await client.connect();

  let lette = 0, risposte = 0, errori = 0;
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const uids = await client.search({ seen: false }, { uid: true });
      for (const uid of uids) {
        // ANTI-DOPPIONE: segna la mail come LETTA *prima* di rispondere.
        // Cosi' ogni mail viene "presa" una volta sola: se un altro giro parte
        // nel frattempo (o l'invio e' lento), non la rivede e non la rifa'.
        // Se non riusciamo a segnarla, la saltiamo (niente invio) per non
        // rischiare di risponderle all'infinito.
        try {
          await client.messageFlagsAdd(uid, ['\\Seen'], { uid: true });
        } catch (e) {
          console.log('claim (Seen) fallito uid', uid, e.message, '-> salto');
          continue;
        }

        let mittente = '';
        try {
          const msg = await client.fetchOne(uid, { source: true }, { uid: true });
          const parsed = await simpleParser(msg.source);
          mittente = parsed?.from?.value?.[0]?.address || '';
        } catch (e) {
          console.log('parsing mail fallito uid', uid, e.message);
        }
        lette++;

        if (gioco_attivo && mittente) {
          try {
            const id = idPulito(mittente);
            await inviaMail(modo, mittente, oggetto, corpoHtml(sessione, id, intro));
            risposte++;
          } catch (e) {
            errori++;
            console.log('invio (' + modo + ') fallito verso', mittente, e.message);
          }
        }
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }

  const esito = { autorisponditore: true, modo, gioco_attivo, sessione, fase, lette, risposte, errori };
  console.log('ESITO', JSON.stringify(esito));
  return esito;
};
