#!/usr/bin/env node
'use strict';
// STRUMENTO PERSONALE DELLO SVILUPPATORE — non va messo in nessun pacchetto per i clienti
// (non lo fa nessuno script di costruzione: controllare sempre con `grep recupera-password costruisci/*.sh`).
//
// Serve per quando un cliente ha dimenticato TUTTE le password e non ha un backup recente:
//   1. Il cliente manda il file `agenzia.db` (sta nella sua cartella `_dati`), per mail o WhatsApp,
//      in una cartella VUOTA sul tuo computer (nessun altro file agenzia.db dentro).
//   2. Esegui:  node costruisci/recupera-password.js <cartella> <utente|pannello>
//      Ti chiede la nuova password (non la scrivi nella riga di comando: resta solo a video).
//   3. Rimandi al cliente il file agenzia.db di quella cartella (aggiornato sul posto).
//   4. Il cliente, sulla schermata di accesso, clicca "Password dimenticata o dati da ripristinare?",
//      sceglie quel file e scrive SOSTITUISCI per confermare. Il resto lo fa il programma (dalla 1.14.1).
//
// Usa la STESSA funzione (resetPassword) che usa già il file RESET-PASSWORD.txt dentro il programma:
// nessuna logica nuova, solo applicata a un file che non sta girando sul server del cliente.
const path = require('node:path');
const fs = require('node:fs');
const readline = require('node:readline');

const [, , cartella, chi] = process.argv;
if (!cartella || !chi) {
  console.log('Uso: node recupera-password.js <cartella-con-agenzia.db> <utente|pannello>');
  console.log('  <utente>   = username dell\'utente (es. fabio)');
  console.log('  pannello   = per resettare la password del pannello Opzioni');
  process.exit(1);
}
const fileDb = path.join(cartella, 'agenzia.db');
if (!fs.existsSync(fileDb)) { console.error('Non trovo agenzia.db dentro', cartella); process.exit(1); }

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
rl.question(`Nuova password per "${chi}" (almeno 4 caratteri): `, nuova => {
  rl.close();
  nuova = String(nuova || '').trim();
  if (nuova.length < 4) { console.error('Password troppo corta: non cambiato niente.'); process.exit(1); }
  const db = require('../server/db');
  db.apri(cartella);
  const { resetPassword } = require('../server/api');
  console.log(resetPassword(chi, nuova));
  db.run('PRAGMA wal_checkpoint(TRUNCATE)'); // porta tutte le scritture dentro agenzia.db: basta mandare quel solo file
  console.log('Fatto. Manda al cliente il file:', fileDb);
  console.log('Lo importa dalla schermata di accesso ("Password dimenticata…"), scrivendo SOSTITUISCI per confermare.');
});
