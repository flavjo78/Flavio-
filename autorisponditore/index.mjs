/* =========================================================================
   PREDIZIONE — Autorisponditore (AWS Lambda, Node.js)
   -------------------------------------------------------------------------
   Ogni volta che viene eseguito (lo lancia una pianificazione ogni minuto):
   1) legge lo STATO del gioco da stato.php (sessione corrente + fase);
   2) si collega alla casella io@abraka.it via IMAP e legge le mail NON lette;
   3) per ogni mail, SOLO se il gioco e' "IN CORSO" (fase = avviato), invia una
      risposta via Amazon SES con l'immagine dinamica  image.php?s=SESSIONE&id=MITTENTE ;
   4) segna la mail come letta (cosi' non risponde due volte).

   Risponde SOLO mentre il gioco e' "in corso":
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

const {
  IMAP_HOST = 'pop.tophost.it',
  IMAP_PORT = '993',
  IMAP_USER,                 // nome della MAILBOX (es. abraka.it)
  IMAP_PASS,                 // password della casella
  SES_FROM = 'Predizione <io@abraka.it>',
  STATO_URL = 'https://abraka.it/predizione/stato.php',
  IMAGE_URL_BASE = 'https://abraka.it/predizione/image.php',
  MAIL_SUBJECT = 'La tua predizione',
  MAIL_INTRO = 'Grazie per aver scritto. Ecco la tua predizione.',
  AWS_REGION = 'eu-west-1',
} = process.env;

const ses = new SESClient({ region: AWS_REGION });

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

/* corpo HTML della risposta, con l'immagine dinamica */
function corpoHtml(sessione, id) {
  const src = `${IMAGE_URL_BASE}?s=${encodeURIComponent(sessione)}&id=${encodeURIComponent(id)}`;
  return `<!doctype html><html><body style="margin:0;background:#0a0908;color:#ece0c9;font-family:Georgia,'Times New Roman',serif">
  <div style="max-width:560px;margin:0 auto;padding:26px 20px">
    <p style="font-size:16px;line-height:1.6;margin:0 0 18px">${MAIL_INTRO}</p>
    <img src="${src}" width="520" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:10px" alt="">
  </div>
</body></html>`;
}

export const handler = async () => {
  // 1) stato del gioco
  let stato = null;
  try { stato = await statoCorrente(); } catch (e) { console.log('stato.php non raggiungibile:', e.message); }
  const sessione = stato ? Number(stato.sessione || 0) : 0;
  const fase = stato ? String(stato.fase || 'spento') : 'spento';
  // risponde mentre il gioco e' ATTIVO: fase "avviato" (mostra A) o "cambiato" (mostra B).
  // NON risponde prima di "Avvia" (spento) ne' dopo "Finisci" (terminato).
  const gioco_attivo = sessione > 0 && (fase === 'avviato' || fase === 'cambiato');

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
            await ses.send(new SendEmailCommand({
              Source: SES_FROM,
              Destination: { ToAddresses: [mittente] },
              Message: {
                Subject: { Data: MAIL_SUBJECT, Charset: 'UTF-8' },
                Body: { Html: { Data: corpoHtml(sessione, id), Charset: 'UTF-8' } },
              },
            }));
            risposte++;
          } catch (e) {
            errori++;
            console.log('invio SES fallito verso', mittente, e.message);
          }
        }

        // segna come letta (processata) in ogni caso
        try { await client.messageFlagsAdd(uid, ['\\Seen'], { uid: true }); }
        catch (e) { console.log('flag Seen fallito uid', uid, e.message); }
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }

  const esito = { gioco_attivo, sessione, fase, lette, risposte, errori };
  console.log('ESITO', JSON.stringify(esito));
  return esito;
};
