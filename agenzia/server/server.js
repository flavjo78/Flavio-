'use strict';
// AGENZIA — server. Avvio: node server/server.js   (oppure agenzia.exe)
// Opzioni via variabili d'ambiente: AGENZIA_PORTA (default 8787), AGENZIA_DATI (cartella dati), AGENZIA_WEB (cartella web)
// Nasconde l'avviso "SQLite is an experimental feature" (il modulo è stabile per il nostro uso).
const _emitWarning = process.emitWarning;
process.emitWarning = function (w, ...a) { const nome = typeof w === 'object' && w ? w.name : (typeof a[0] === 'string' ? a[0] : a[0] && a[0].type); if (nome === 'ExperimentalWarning') return; return _emitWarning.call(process, w, ...a); };
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const db = require('./db');
const { AZIONI, ErroreApi } = require('./api');

let inExe = false;
try { inExe = require('node:sea').isSea(); } catch (e) { inExe = false; }
const BASE = inExe ? path.dirname(process.execPath) : path.resolve(__dirname, '..');
const DIR_DATI = process.env.AGENZIA_DATI || path.join(BASE, '_dati');
const DIR_WEB = process.env.AGENZIA_WEB || path.join(BASE, 'web');
const PORTA = parseInt(process.env.AGENZIA_PORTA || '8787', 10);
const VERSIONE = '1.1.0';

db.apri(DIR_DATI);

// Recupero password dimenticata (solo chi ha accesso al PC centrale): creare in _dati un file
// RESET-PASSWORD.txt con una riga "username nuovapassword" oppure "pannello nuovapassword",
// riavviare il programma: la password viene cambiata e il file cancellato.
try {
  const fileReset = path.join(DIR_DATI, 'RESET-PASSWORD.txt');
  if (fs.existsSync(fileReset)) {
    const { resetPassword } = require('./api');
    for (const riga of fs.readFileSync(fileReset, 'utf8').split(/\r?\n/)) {
      const [chi, ...resto] = riga.trim().split(/\s+/);
      if (chi && resto.length) console.log(resetPassword(chi, resto.join(' ')));
    }
    fs.unlinkSync(fileReset);
  }
} catch (e) { console.error('RESET-PASSWORD.txt non applicato:', e.message); }

const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.json': 'application/json', '.woff2': 'font/woff2' };

function leggiCookie(req) {
  const out = {};
  for (const parte of (req.headers.cookie || '').split(';')) {
    const i = parte.indexOf('=');
    if (i > 0) out[parte.slice(0, i).trim()] = decodeURIComponent(parte.slice(i + 1).trim());
  }
  return out;
}
function leggiCorpoRaw(req, max = 200 * 1024 * 1024) {
  return new Promise((resolve, reject) => {
    const parti = []; let tot = 0;
    req.on('data', d => { tot += d.length; if (tot > max) { reject(new ErroreApi(413, 'File troppo grande')); req.destroy(); } else parti.push(d); });
    req.on('end', () => resolve(Buffer.concat(parti)));
    req.on('error', reject);
  });
}
function leggiCorpo(req) {
  return new Promise((resolve, reject) => {
    const parti = [];
    let tot = 0;
    req.on('data', d => { tot += d.length; if (tot > 8 * 1024 * 1024) { reject(new ErroreApi(413, 'Richiesta troppo grande')); req.destroy(); } else parti.push(d); });
    req.on('end', () => {
      if (!parti.length) return resolve({});
      try { resolve(JSON.parse(Buffer.concat(parti).toString('utf8'))); }
      catch (e) { reject(new ErroreApi(400, 'JSON non valido')); }
    });
    req.on('error', reject);
  });
}
function rispondiJson(res, codice, obj, cookie) {
  const h = { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' };
  if (cookie !== undefined) h['Set-Cookie'] = cookie;
  res.writeHead(codice, h);
  res.end(JSON.stringify(obj));
}

function sessioneDa(req) {
  const sid = leggiCookie(req).agenzia_sid;
  if (!sid) return null;
  const s = db.get('SELECT * FROM sessioni WHERE sid = ?', sid);
  if (!s) return null;
  // sessione valida 30 giorni dall'ultimo uso
  if (Date.now() - Date.parse(s.ultimo_ts) > 30 * 86400000) { db.run('DELETE FROM sessioni WHERE sid = ?', sid); return null; }
  if (Date.now() - Date.parse(s.ultimo_ts) > 60000) db.run('UPDATE sessioni SET ultimo_ts = ? WHERE sid = ?', db.adesso(), sid);
  return s;
}

async function gestisciApi(req, res, url) {
  const nome = url.pathname.slice('/api/'.length);
  const az = AZIONI[nome];
  if (!az) return rispondiJson(res, 404, { errore: `Azione sconosciuta: ${nome}` });
  if (az.post && req.method !== 'POST') return rispondiJson(res, 405, { errore: 'Serve POST' });
  const query = Object.fromEntries(url.searchParams.entries());
  const corpoRaw = (az.raw && req.method === 'POST') ? await leggiCorpoRaw(req) : null;
  const corpo = (req.method === 'POST' && !az.raw) ? await leggiCorpo(req) : {};
  const sessione = sessioneDa(req);
  let utente = null;
  if (sessione) {
    utente = db.get('SELECT * FROM utenti WHERE id = ? AND attivo = 1', sessione.utente_id);
    if (!utente) db.run('DELETE FROM sessioni WHERE sid = ?', sessione.sid);
  }
  if (!az.pubblica && !utente) return rispondiJson(res, 401, { errore: 'Devi entrare con nome utente e password', login: true });
  let cookie;
  const ctx = {
    metodo: req.method, query, corpo, corpoRaw, utente, sessione: utente ? sessione : null,
    setSessione: sid => { cookie = sid ? `agenzia_sid=${sid}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${30 * 86400}` : 'agenzia_sid=; Path=/; HttpOnly; Max-Age=0'; }
  };
  const r = await az.fn(ctx);
  if (r && r.__file) {
    // risposta = un file da scaricare
    res.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Disposition': `attachment; filename="${r.__nome}"`, 'Cache-Control': 'no-store' });
    const st = fs.createReadStream(r.__file);
    st.pipe(res);
    st.on('close', () => { if (r.__cancella) fs.unlink(r.__file, () => {}); });
    return;
  }
  rispondiJson(res, 200, r === undefined ? { ok: true } : r, cookie);
}

function serviFile(res, file) {
  const ext = path.extname(file).toLowerCase();
  fs.readFile(file, (e, dati) => {
    if (e) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); return res.end('Non trovato'); }
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
    res.end(dati);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  try {
    if (url.pathname.startsWith('/api/')) return await gestisciApi(req, res, url);
    let p = decodeURIComponent(url.pathname);
    if (p === '/') p = '/index.html';
    const file = path.normalize(path.join(DIR_WEB, p));
    if (!file.startsWith(path.normalize(DIR_WEB + path.sep))) { res.writeHead(403); return res.end(); }
    serviFile(res, file);
  } catch (e) {
    if (e instanceof ErroreApi) return rispondiJson(res, e.codice, { errore: e.message });
    console.error(new Date().toISOString(), 'ERRORE', req.url, e);
    rispondiJson(res, 500, { errore: 'Errore interno: ' + (e && e.message) });
  }
});

// ---- backup automatico giornaliero all'ora impostata (controllo ogni 5 minuti) ----
let ultimoGiornoBackup = '';
async function controllaBackup() {
  try {
    const b = db.impostazione('backup');
    if (!b.cartella) return;
    const ora = new Date();
    const oggi = db.oggi();
    const hhmm = `${String(ora.getHours()).padStart(2, '0')}:${String(ora.getMinutes()).padStart(2, '0')}`;
    const giaOggi = (b.ultimo || '').startsWith(oggi) || ultimoGiornoBackup === oggi;
    if (giaOggi) return;
    if (hhmm >= (b.ora || '20:00')) {
      ultimoGiornoBackup = oggi;
      const dest = await db.eseguiBackup(b.cartella);
      console.log(new Date().toISOString(), 'backup automatico →', dest);
    }
  } catch (e) { console.error('backup automatico fallito:', e.message); }
}
setInterval(controllaBackup, 5 * 60 * 1000);
setTimeout(controllaBackup, 15000);

server.listen(PORTA, '0.0.0.0', () => {
  const nomi = [];
  for (const [k, v] of Object.entries(os.networkInterfaces())) for (const a of v) if (a.family === 'IPv4' && !a.internal) nomi.push(a.address);
  console.log(`AGENZIA v${VERSIONE} in ascolto sulla porta ${PORTA}`);
  console.log(`  su questo PC:        http://localhost:${PORTA}`);
  for (const ip of nomi) console.log(`  dagli altri PC:      http://${ip}:${PORTA}   (oppure http://${os.hostname()}:${PORTA})`);
  console.log(`  dati in:             ${DIR_DATI}`);
});
server.on('error', e => {
  if (e.code === 'EADDRINUSE') console.error(`La porta ${PORTA} è già occupata: il programma è forse già avviato?`);
  else console.error(e);
  process.exit(1);
});
