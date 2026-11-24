'use strict';
// RECUPERO PASSWORD — AGENZIA  (strumento personale dello sviluppatore, NON va dato ai clienti)
// Programma a sé: si apre con un doppio clic (recupera-password.exe), mostra una pagina nel browser,
// si carica il file agenzia.db ricevuto dal cliente, si sceglie un utente (o le Opzioni), si scrive la
// nuova password e si salva il file aggiornato. Il cliente lo mette al posto di _dati\agenzia.db e riavvia.
// Non serve installare Node: l'exe se lo porta dentro. Usa lo stesso modo di cifrare del programma vero.
const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { exec } = require('node:child_process');
const { DatabaseSync } = require('node:sqlite');

// --- stessa cifratura del programma (scrypt, salt$hash) ---
function hashPassword(pw) {
  const salt = crypto.randomBytes(16).toString('hex');
  const h = crypto.scryptSync(String(pw), salt, 32).toString('hex');
  return `${salt}$${h}`;
}

let STATO = null; // { file: percorso temporaneo del db caricato }
function puliziaTmp() { try { if (STATO && STATO.dir) fs.rmSync(STATO.dir, { recursive: true, force: true }); } catch (e) {} STATO = null; }

function leggiCorpo(req) {
  return new Promise((risolvi, rifiuta) => {
    const parti = []; let n = 0;
    req.on('data', c => { n += c.length; if (n > 200 * 1024 * 1024) { rifiuta(new Error('file troppo grande')); req.destroy(); } parti.push(c); });
    req.on('end', () => risolvi(Buffer.concat(parti)));
    req.on('error', rifiuta);
  });
}
function json(res, codice, ogg) { const b = Buffer.from(JSON.stringify(ogg)); res.writeHead(codice, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': b.length }); res.end(b); }

// carica il file db, controlla che sia un archivio AGENZIA, restituisce agenzia + utenti + "Opzioni"
function carica(buf) {
  if (buf.subarray(0, 15).toString('latin1') !== 'SQLite format 3') throw new Error('Questo non è un file agenzia.db');
  puliziaTmp();
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'recupero-'));
  const file = path.join(dir, 'agenzia.db');
  fs.writeFileSync(file, buf);
  const db = new DatabaseSync(file);
  try {
    const tab = db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all().map(r => r.name);
    for (const t of ['utenti', 'impostazioni']) if (!tab.includes(t)) throw new Error('File non valido: manca la tabella ' + t);
    const utenti = db.prepare('SELECT username, nome, pass_chiaro, attivo FROM utenti ORDER BY nome').all();
    let agenzia = 'Agenzia';
    try { const r = db.prepare("SELECT valore FROM impostazioni WHERE chiave='agenzia'").get(); if (r) agenzia = (JSON.parse(r.valore).insegna) || agenzia; } catch (e) {}
    const haPannello = !!db.prepare("SELECT 1 FROM impostazioni WHERE chiave='pannello_pass'").get();
    db.close();
    STATO = { dir, file };
    return { agenzia, utenti, haPannello };
  } catch (e) { db.close(); throw e; }
}

// cambia la password (utente per username, oppure 'pannello') e restituisce il db aggiornato in bytes
function cambia(chi, nuova) {
  if (!STATO) throw new Error('Carica prima il file');
  if (String(nuova).length < 4) throw new Error('La password deve avere almeno 4 caratteri');
  const db = new DatabaseSync(STATO.file);
  try {
    if (chi === 'pannello') {
      db.prepare("INSERT INTO impostazioni(chiave, valore) VALUES('pannello_pass', ?) ON CONFLICT(chiave) DO UPDATE SET valore = excluded.valore").run(JSON.stringify(hashPassword(nuova)));
    } else {
      const u = db.prepare('SELECT id FROM utenti WHERE username = ?').get(chi);
      if (!u) throw new Error('Utente non trovato');
      db.prepare('UPDATE utenti SET pass_hash = ?, pass_chiaro = ?, attivo = 1 WHERE id = ?').run(hashPassword(nuova), String(nuova), u.id);
    }
    db.exec('PRAGMA wal_checkpoint(TRUNCATE)'); // tutto dentro il singolo file agenzia.db
    db.close();
  } catch (e) { db.close(); throw e; }
  return fs.readFileSync(STATO.file);
}

const PAGINA = `<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recupero password · AGENZIA</title><style>
*{box-sizing:border-box} body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:#eef1f5;color:#1a1d21}
.wrap{max-width:720px;margin:28px auto;padding:0 16px}
.card{background:#fff;border:1px solid #dfe3e8;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:22px 24px;margin-bottom:18px}
h1{font-size:22px;margin:0 0 4px} .sub{color:#5b6470;margin:0 0 18px}
.passo{font-size:13px;font-weight:700;color:#1f6feb;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px}
label{display:block;font-size:13px;color:#5b6470;margin:12px 0 4px}
input[type=text],input[type=password],select{width:100%;padding:10px 12px;border:1px solid #c9cfd6;border-radius:8px;font-size:15px;background:#fff}
input[type=file]{width:100%}
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:8px;padding:11px 18px;font-size:15px;font-weight:600;cursor:pointer}
.btn.primario{background:#1f6feb;color:#fff} .btn.primario:disabled{background:#9db8e8;cursor:default}
.riga{display:flex;gap:10px;align-items:center}
.occhio{border:1px solid #c9cfd6;background:#f6f8fb;border-radius:8px;padding:10px 12px;cursor:pointer;font-size:16px}
.hidden{display:none} .ok{background:#e7f6ec;color:#1a7f37;border:1px solid #abdcb8;padding:12px 14px;border-radius:8px}
.err{background:#fde8e8;color:#b42318;border:1px solid #f3b4b4;padding:12px 14px;border-radius:8px;margin-top:10px}
.muted{color:#8a93a0;font-size:13px} b{color:#1a1d21}
.chi{font-weight:600} .chipw{color:#8a93a0;font-weight:400;font-size:13px}
</style></head><body><div class="wrap">
<div class="card"><h1>Recupero password</h1><p class="sub">Apri un file <b>agenzia.db</b>, scegli chi, metti la nuova password e salva il file aggiornato.</p>

<div id="p1"><div class="passo">1 · Apri il file</div>
  <label>File agenzia.db ricevuto dal cliente (è dentro la cartella _dati)</label>
  <input type="file" id="file" accept=".db">
  <div id="e1" class="err hidden"></div>
</div>

<div id="p2" class="hidden"><div class="passo">2 · Scegli chi e la nuova password</div>
  <div class="muted">Archivio di: <b id="agN"></b></div>
  <label>Chi</label>
  <select id="chi"></select>
  <label>Nuova password</label>
  <div class="riga"><input type="password" id="pw" autocomplete="off" placeholder="almeno 4 caratteri"><button type="button" class="occhio" id="occhio" title="Mostra">👁</button></div>
  <div id="e2" class="err hidden"></div>
  <div style="margin-top:16px"><button class="btn primario" id="salva">Cambia e salva il file</button></div>
</div>

<div id="p3" class="hidden"><div class="passo">3 · Fatto</div>
  <div class="ok" id="fatto"></div>
  <p class="muted" style="margin-top:12px">Il cliente, sul <b>computer centrale</b>: chiude il programma, mette questo file al posto di <b>_dati\\agenzia.db</b> (tenendo da parte il vecchio), riavvia. Entra con la password appena impostata.</p>
  <div style="margin-top:14px"><button class="btn primario" id="altro">Cambia un'altra password</button></div>
</div>
</div>
<p class="muted" style="text-align:center">Strumento personale dello sviluppatore. Non va dato ai clienti.</p>
</div><script>
const $ = s => document.querySelector(s);
let dati = null;
$('#file').onchange = async e => {
  const f = e.target.files[0]; if (!f) return; $('#e1').classList.add('hidden');
  try {
    const r = await fetch('/carica', { method:'POST', headers:{'Content-Type':'application/octet-stream'}, body: await f.arrayBuffer() });
    dati = await r.json(); if (!r.ok) throw new Error(dati.errore || 'Errore');
    $('#agN').textContent = dati.agenzia;
    const sel = $('#chi'); sel.innerHTML = '';
    for (const u of dati.utenti) { const o = document.createElement('option'); o.value = 'u:' + u.username; o.textContent = (u.nome || u.username) + (u.pass_chiaro ? '   (password attuale: ' + u.pass_chiaro + ')' : '') + (u.attivo ? '' : '  — disattivato'); sel.appendChild(o); }
    if (dati.haPannello) { const o = document.createElement('option'); o.value = 'pannello'; o.textContent = 'Opzioni (password del pannello di controllo)'; sel.appendChild(o); }
    $('#p1').classList.add('hidden'); $('#p2').classList.remove('hidden'); $('#p3').classList.add('hidden'); $('#pw').value=''; $('#pw').focus();
  } catch (err) { $('#e1').textContent = err.message; $('#e1').classList.remove('hidden'); }
};
$('#occhio').onclick = () => { const p = $('#pw'); p.type = p.type === 'password' ? 'text' : 'password'; };
$('#salva').onclick = async () => {
  $('#e2').classList.add('hidden'); const v = $('#chi').value, pw = $('#pw').value;
  const chi = v === 'pannello' ? 'pannello' : v.slice(2);
  if (pw.length < 4) { $('#e2').textContent = 'La password deve avere almeno 4 caratteri'; $('#e2').classList.remove('hidden'); return; }
  try {
    const r = await fetch('/cambia', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ chi, nuova: pw }) });
    if (!r.ok) { const j = await r.json(); throw new Error(j.errore || 'Errore'); }
    const blob = await r.blob(); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'agenzia.db'; document.body.appendChild(a); a.click(); a.remove();
    const nome = v === 'pannello' ? 'la password del pannello di controllo (Opzioni)' : 'la password di ' + (dati.utenti.find(u=>u.username===chi)||{}).nome;
    $('#fatto').innerHTML = 'Cambiata ' + nome + '. Il file <b>agenzia.db</b> è stato salvato (di solito nella cartella Download).';
    $('#p2').classList.add('hidden'); $('#p3').classList.remove('hidden');
  } catch (err) { $('#e2').textContent = err.message; $('#e2').classList.remove('hidden'); }
};
$('#altro').onclick = () => { $('#p3').classList.add('hidden'); $('#p2').classList.remove('hidden'); $('#pw').value=''; $('#pw').focus(); };
</script></body></html>`;

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && (req.url === '/' || req.url === '/index.html')) { const b = Buffer.from(PAGINA); res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Length': b.length }); return res.end(b); }
    if (req.method === 'POST' && req.url === '/carica') { const buf = await leggiCorpo(req); return json(res, 200, carica(buf)); }
    if (req.method === 'POST' && req.url === '/cambia') { const c = JSON.parse((await leggiCorpo(req)).toString('utf8') || '{}'); const out = cambia(String(c.chi), String(c.nuova)); res.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Disposition': 'attachment; filename="agenzia.db"', 'Content-Length': out.length }); return res.end(out); }
    res.writeHead(404); res.end('non trovato');
  } catch (e) { json(res, 400, { errore: e.message }); }
});
server.listen(0, '127.0.0.1', () => {
  const porta = server.address().port;
  const url = `http://localhost:${porta}`;
  console.log('RECUPERO PASSWORD - AGENZIA');
  console.log('Apri nel browser:', url, '  (non chiudere questa finestra finché lavori)');
  if (process.platform === 'win32') exec(`start "" "${url}"`);
  else if (process.platform === 'darwin') exec(`open "${url}"`);
});
process.on('exit', puliziaTmp);
