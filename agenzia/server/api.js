'use strict';
// Tutte le funzioni dell'API. Ogni handler riceve (ctx) = { metodo, percorso, query, corpo, utente, sessione, setSessione }
const crypto = require('node:crypto');
const fs = require('node:fs');
const db = require('./db');
const VERSIONE = '1.8.1';
// pagine del menù sbloccate temporaneamente con la password del pannello (sid → Set di pagine)
const SBLOCCHI = new Map();
const PAGINE_MENU = ['scadenze', 'pratiche', 'biglietteria', 'clienti', 'fornitori', 'statistiche', 'liste'];
// sessioni che hanno appena verificato la password del pannello per stampare una voce nascosta (sid → ts)
const FIGLI_OK = new Map();

class ErroreApi extends Error {
  constructor(codice, messaggio) { super(messaggio); this.codice = codice; }
}
const err = (codice, msg) => { throw new ErroreApi(codice, msg); };

// ---------- password ----------
function hashPassword(pw) {
  const salt = crypto.randomBytes(16).toString('hex');
  const h = crypto.scryptSync(String(pw), salt, 32).toString('hex');
  return `${salt}$${h}`;
}
function verificaPassword(pw, stored) {
  if (!stored) return false;
  const [salt, h] = String(stored).split('$');
  if (!salt || !h) return false;
  const h2 = crypto.scryptSync(String(pw), salt, 32).toString('hex');
  return crypto.timingSafeEqual(Buffer.from(h, 'hex'), Buffer.from(h2, 'hex'));
}

// ---------- utilità ----------
const S = v => (v === undefined || v === null) ? '' : String(v).trim();
const N = v => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : 0; };
const B = v => (v === true || v === 1 || v === '1' || v === 'true') ? 1 : 0;
// importi: il client manda euro (numero o stringa "1.234,56"); si salvano centesimi
function CENT(v) {
  if (typeof v === 'number') return Math.round(v * 100);
  let s = S(v);
  if (!s) return 0;
  s = s.replace(/\s|€/g, '');
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const n = parseFloat(s);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
}
function DATA(v) {
  const s = S(v);
  if (!s) return '';
  let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  m = s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})$/);
  if (m) {
    let a = m[3]; if (a.length === 2) a = '20' + a;
    return `${a}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  }
  err(400, `Data non valida: ${s}`);
}
function richiedi(cond, msg) { if (!cond) err(400, msg); }

function permessiDi(u) { try { const p = JSON.parse(u.permessi || '[]'); return Array.isArray(p) && p.length ? p : PAGINE_MENU.slice(); } catch (e) { return PAGINE_MENU.slice(); } }
function utentePubblico(u) { return u ? { id: u.id, username: u.username, nome: u.nome, attivo: u.attivo, permessi: permessiDi(u) } : null; }
function listaUtenti() { return db.all('SELECT id, username, nome, attivo FROM utenti ORDER BY nome'); }
function listaUtentiPannello() { return db.all('SELECT id, username, nome, attivo, pass_chiaro, permessi FROM utenti ORDER BY nome').map(u => ({ ...u, permessi: permessiDi(u) })); }
// pagina del menù a cui appartiene un'azione (per i permessi); null = libera
function paginaDiAzione(nome) {
  if (/^(clienti?|cliente_|tessere_tipi|passeggeri_cerca)/.test(nome)) return 'clienti';
  if (/^fornitor/.test(nome)) return 'fornitori';
  if (/^(pratic|acconto|pagamento_fornitore|prossimo_numero$)/.test(nome)) return 'pratiche';
  if (/^(bigliett|prossimo_numero_b)/.test(nome)) return 'biglietteria';
  if (nome === 'scadenze') return 'scadenze';
  if (nome === 'statistiche') return 'statistiche';
  if (nome === 'liste_salva') return 'liste';
  return null;
}
function controllaPermesso(ctx, nome) {
  const pag = paginaDiAzione(nome); if (!pag) return;
  // la ricerca clienti serve anche nelle pratiche e nei biglietti
  if (pag === 'clienti' && ['clienti', 'cliente', 'tessere_tipi', 'passeggeri_cerca', 'cliente_salva'].includes(nome)) { const perm = permessiDi(ctx.utente); if (perm.some(p => ['pratiche', 'biglietteria', 'clienti'].includes(p))) return; }
  if (pag === 'pratiche' && ['pratiche_cerca', 'pratica'].includes(nome) && permessiDi(ctx.utente).includes('biglietteria')) return;
  if (pag === 'fornitori' && nome === 'fornitori' && permessiDi(ctx.utente).some(p => ['pratiche', 'biglietteria'].includes(p))) return;
  if (permessiDi(ctx.utente).includes(pag)) return;
  const sb = ctx.sessione && SBLOCCHI.get(ctx.sessione.sid);
  if (sb && sb.has(pag)) return;
  err(403, 'Questa parte del programma non è abilitata per il tuo utente: serve la password del pannello');
}
// sblocca una pagina del menù per questa sessione con la password del pannello
function pagina_sblocca(ctx) {
  const c = ctx.corpo || {};
  if (!verificaPassword(c.password, db.impostazione('pannello_pass'))) err(401, 'Password del pannello sbagliata');
  const pag = S(c.pagina); richiedi(PAGINE_MENU.includes(pag), 'Pagina non valida');
  if (!SBLOCCHI.has(ctx.sessione.sid)) SBLOCCHI.set(ctx.sessione.sid, new Set());
  SBLOCCHI.get(ctx.sessione.sid).add(pag);
  db.log(ctx.utente, 'pagina_sblocca', '', null, pag);
  return { ok: true, sbloccate: [...SBLOCCHI.get(ctx.sessione.sid)] };
}

// ---------- primo avvio / accesso ----------
function stato_installazione() {
  const n = db.get('SELECT COUNT(*) AS n FROM utenti').n;
  // Elenco degli utenti attivi (solo nome e username) per la schermata d'accesso "scegli chi sei".
  const utenti = db.all('SELECT id, username, nome FROM utenti WHERE attivo = 1 ORDER BY nome');
  return { installato: n > 0, agenzia: db.impostazione('agenzia').insegna, utenti, versione: VERSIONE };
}

function installa(ctx) {
  if (stato_installazione().installato) err(403, 'Il programma è già installato.');
  const c = ctx.corpo || {};
  richiedi(S(c.username).length >= 2, 'Username troppo corto');
  richiedi(S(c.password).length >= 4, 'Password troppo corta (minimo 4)');
  richiedi(S(c.pannello_password).length >= 4, 'Password del pannello troppo corta (minimo 4)');
  if (c.password2 !== undefined) richiedi(c.password === c.password2, 'Le due password personali non coincidono');
  if (c.pannello_password2 !== undefined) richiedi(c.pannello_password === c.pannello_password2, 'Le due password del pannello non coincidono');
  const ts = db.adesso();
  db.run('INSERT INTO utenti(username, nome, pass_hash, pass_chiaro, attivo, creato_ts) VALUES(?,?,?,?,1,?)',
    S(c.username).toLowerCase(), S(c.nome) || S(c.username), hashPassword(c.password), S(c.password), ts);
  db.impostazioneSalva('pannello_pass', hashPassword(c.pannello_password));
  const ag = db.impostazione('agenzia');
  if (S(c.insegna)) ag.insegna = S(c.insegna);
  db.impostazioneSalva('agenzia', ag);
  return login({ ...ctx, corpo: { username: c.username, password: c.password } });
}

function login(ctx) {
  const c = ctx.corpo || {};
  const u = db.get('SELECT * FROM utenti WHERE username = ?', S(c.username).toLowerCase());
  if (!u || !u.attivo || !verificaPassword(c.password, u.pass_hash)) err(401, 'Nome utente o password sbagliati');
  const sid = crypto.randomBytes(24).toString('hex');
  const ts = db.adesso();
  db.run('INSERT INTO sessioni(sid, utente_id, pannello, creato_ts, ultimo_ts) VALUES(?,?,0,?,?)', sid, u.id, ts, ts);
  ctx.setSessione(sid);
  db.log(u, 'login', 'utente', u.id, '');
  return { utente: utentePubblico(u), pannello: false };
}
function logout(ctx) {
  if (ctx.sessione) db.run('DELETE FROM sessioni WHERE sid = ?', ctx.sessione.sid);
  ctx.setSessione('');
  return { ok: true };
}
function me(ctx) {
  const sb = ctx.sessione && SBLOCCHI.get(ctx.sessione.sid);
  return { utente: utentePubblico(ctx.utente), pannello: !!(ctx.sessione && ctx.sessione.pannello), sbloccate: sb ? [...sb] : [] };
}
function cambia_password(ctx) {
  const c = ctx.corpo || {};
  richiedi(verificaPassword(c.vecchia, ctx.utente.pass_hash), 'La password attuale non è giusta');
  richiedi(S(c.nuova).length >= 4, 'Password nuova troppo corta (minimo 4)');
  db.run('UPDATE utenti SET pass_hash = ?, pass_chiaro = ? WHERE id = ?', hashPassword(c.nuova), S(c.nuova), ctx.utente.id);
  db.log(ctx.utente, 'cambia_password', 'utente', ctx.utente.id, '');
  return { ok: true };
}

// ---------- pannello opzioni (password a parte) ----------
function pannello_sblocca(ctx) {
  const c = ctx.corpo || {};
  if (!verificaPassword(c.password, db.impostazione('pannello_pass'))) err(401, 'Password del pannello sbagliata');
  db.run('UPDATE sessioni SET pannello = 1 WHERE sid = ?', ctx.sessione.sid);
  db.log(ctx.utente, 'pannello_sblocca', '', null, '');
  return { pannello: true };
}
function pannello_blocca(ctx) {
  db.run('UPDATE sessioni SET pannello = 0 WHERE sid = ?', ctx.sessione.sid);
  return { pannello: false };
}
// Verifica la password del pannello SENZA sbloccare il pannello: serve solo per le voci nascoste in stampa (vale 15 minuti).
function pannello_verifica(ctx) {
  const c = ctx.corpo || {};
  if (!verificaPassword(c.password, db.impostazione('pannello_pass'))) err(401, 'Password del pannello sbagliata');
  FIGLI_OK.set(ctx.sessione.sid, Date.now());
  db.log(ctx.utente, 'figli_stampa_sblocco', '', null, '');
  return { ok: true };
}
function richiediPannello(ctx) {
  if (!ctx.sessione || !ctx.sessione.pannello) err(403, 'Serve la password del pannello opzioni');
}
function pannello_password(ctx) {
  richiediPannello(ctx);
  richiedi(S(ctx.corpo.nuova).length >= 4, 'Password troppo corta (minimo 4)');
  db.impostazioneSalva('pannello_pass', hashPassword(ctx.corpo.nuova));
  db.log(ctx.utente, 'pannello_password', '', null, '');
  return { ok: true };
}

function impostazioni(ctx) {
  // Lettura: a tutti gli utenti (servono per i menù e la stampa). Mai la password del pannello.
  const t = db.tutteImpostazioni();
  return t;
}
function impostazioni_salva(ctx) {
  richiediPannello(ctx);
  const c = ctx.corpo || {};
  const ammesse = ['agenzia', 'numerazione', 'backup', 'liste', 'testi'];
  for (const k of ammesse) {
    if (c[k] === undefined) continue;
    let v = c[k];
    if (k === 'liste') {
      const cur = db.impostazione('liste');
      for (const nome of Object.keys(cur)) {
        if (Array.isArray(v[nome])) cur[nome] = v[nome].map(S).filter(Boolean);
      }
      v = cur;
    } else if (k === 'numerazione') {
      v = { anno_iniziale: N(v.anno_iniziale) || new Date().getFullYear(), numero_iniziale: Math.max(1, N(v.numero_iniziale) || 1), numero_iniziale_b: Math.max(1, N(v.numero_iniziale_b) || 1) };
    } else if (k === 'backup') {
      const cur = db.impostazione('backup');
      cur.cartella = S(v.cartella); cur.ora = S(v.ora) || '20:00';
      v = cur;
    } else {
      const cur = db.impostazione(k);
      for (const kk of Object.keys(cur)) if (v[kk] !== undefined) cur[kk] = typeof cur[kk] === 'string' ? String(v[kk]) : v[kk];
      v = cur;
    }
    db.impostazioneSalva(k, v);
  }
  db.log(ctx.utente, 'impostazioni_salva', '', null, Object.keys(c).join(','));
  return db.tutteImpostazioni();
}

// Le liste a tendina le può modificare qualunque utente (voce di menù "Liste")
function liste_salva(ctx) {
  const c = ctx.corpo || {};
  const cur = db.impostazione('liste');
  for (const nome of Object.keys(cur)) if (Array.isArray(c[nome])) cur[nome] = c[nome].map(S).filter(Boolean);
  db.impostazioneSalva('liste', cur);
  db.log(ctx.utente, 'liste_salva', '', null, Object.keys(c).join(','));
  return cur;
}
function utenti(ctx) { richiediPannello(ctx); return listaUtentiPannello(); }
function utenti_salva(ctx) {
  richiediPannello(ctx);
  const c = ctx.corpo || {};
  const id = N(c.id);
  const username = S(c.username).toLowerCase();
  richiedi(username.length >= 2, 'Username troppo corto');
  richiedi(/^[a-z0-9._-]+$/.test(username), 'Username: solo lettere, numeri, punto, trattino');
  const nome = S(c.nome) || username;
  if (id) {
    const u = db.get('SELECT * FROM utenti WHERE id = ?', id);
    if (!u) err(404, 'Utente non trovato');
    const altro = db.get('SELECT id FROM utenti WHERE username = ? AND id <> ?', username, id);
    if (altro) err(400, 'Username già usato');
    db.run('UPDATE utenti SET username = ?, nome = ?, attivo = ? WHERE id = ?', username, nome, c.attivo === undefined ? u.attivo : B(c.attivo), id);
    if (Array.isArray(c.permessi)) db.run('UPDATE utenti SET permessi = ? WHERE id = ?', JSON.stringify(c.permessi.filter(p => PAGINE_MENU.includes(p))), id);
    if (S(c.password)) {
      richiedi(S(c.password).length >= 4, 'Password troppo corta (minimo 4)');
      db.run('UPDATE utenti SET pass_hash = ?, pass_chiaro = ? WHERE id = ?', hashPassword(c.password), S(c.password), id);
    }
    db.log(ctx.utente, 'utente_modifica', 'utente', id, username);
    return { id };
  }
  richiedi(S(c.password).length >= 4, 'Password troppo corta (minimo 4)');
  if (db.get('SELECT id FROM utenti WHERE username = ?', username)) err(400, 'Username già usato');
  const r = db.run('INSERT INTO utenti(username, nome, pass_hash, pass_chiaro, attivo, creato_ts, permessi) VALUES(?,?,?,?,1,?,?)', username, nome, hashPassword(c.password), S(c.password), db.adesso(), Array.isArray(c.permessi) ? JSON.stringify(c.permessi.filter(p => PAGINE_MENU.includes(p))) : '');
  db.log(ctx.utente, 'utente_crea', 'utente', Number(r.lastInsertRowid), username);
  return { id: Number(r.lastInsertRowid) };
}

async function backup_ora(ctx) {
  richiediPannello(ctx);
  const b = db.impostazione('backup');
  const cartella = S(ctx.corpo && ctx.corpo.cartella) || b.cartella;
  richiedi(cartella, 'Imposta prima la cartella dei backup');
  const dest = await db.eseguiBackup(cartella);
  db.log(ctx.utente, 'backup', '', null, dest);
  return { file: dest };
}
// Scarica una copia completa dell'archivio (file SQLite) — per backup a mano o per mandarlo all'assistenza.
async function esporta_db(ctx) {
  richiediPannello(ctx);
  const os = require('node:os');
  const path = require('node:path');
  const tmp = path.join(os.tmpdir(), `agenzia-esporta-${process.pid}-${Date.now()}.db`);
  await db.backupSu(tmp);
  const d = new Date();
  const nome = `agenzia-${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}-${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}.db`;
  db.log(ctx.utente, 'esporta_db', '', null, nome);
  return { __file: tmp, __nome: nome, __cancella: true };
}
// Importa un archivio (.db) al posto di quello attuale. Quello attuale viene messo da parte in _dati/prima-import-*.db
function importa_db(ctx) {
  richiediPannello(ctx);
  const buf = ctx.corpoRaw;
  richiedi(buf && buf.length > 100, 'File vuoto');
  richiedi(buf.subarray(0, 15).toString('latin1') === 'SQLite format 3', 'Il file non è un archivio AGENZIA (.db)');
  const os = require('node:os');
  const path = require('node:path');
  const tmp = path.join(os.tmpdir(), `agenzia-importa-${process.pid}-${Date.now()}.db`);
  fs.writeFileSync(tmp, buf);
  // controlla che abbia le nostre tabelle
  const { DatabaseSync } = require('node:sqlite');
  let prova;
  try {
    prova = new DatabaseSync(tmp, { readOnly: true });
    const tab = prova.prepare("SELECT name FROM sqlite_master WHERE type='table'").all().map(r => r.name);
    for (const t of ['utenti', 'clienti', 'pratiche', 'acconti', 'impostazioni']) richiedi(tab.includes(t), `Archivio non valido: manca la tabella ${t}`);
    const n = prova.prepare('SELECT COUNT(*) AS n FROM pratiche').get().n;
    prova.close();
    const copia = db.sostituisciArchivio(tmp);
    try { fs.unlinkSync(tmp); } catch (e) { /* ignora */ }
    db.log(null, 'importa_db', '', null, `importate ${n} pratiche; archivio precedente in ${copia}`);
    return { ok: true, pratiche: n, copia_precedente: copia, rientra: true };
  } catch (e) {
    try { if (prova) prova.close(); } catch (e2) { /* ignora */ }
    try { fs.unlinkSync(tmp); } catch (e2) { /* ignora */ }
    throw e;
  }
}
// Importazione al PRIMO AVVIO (senza login): ammessa solo finché non esiste nessun utente.
function importa_db_iniziale(ctx) {
  if (stato_installazione().installato) err(403, 'Il programma è già installato: importa dal pannello Opzioni');
  // riusa la stessa logica dell'importazione dal pannello, senza il controllo del pannello
  return importa_db({ ...ctx, sessione: { pannello: 1 }, utente: null });
}
function log_operazioni(ctx) {
  richiediPannello(ctx);
  const n = Math.min(500, N(ctx.query.limite) || 200);
  return db.all('SELECT * FROM log_operazioni ORDER BY id DESC LIMIT ?', n);
}

// ---------- clienti ----------
const CAMPI_CLIENTE = ['cognome', 'nome', 'azienda', 'indirizzo', 'cap', 'citta', 'provincia', 'nazione', 'sesso', 'telefono', 'cellulare', 'telefono2', 'email', 'nato_a', 'cf', 'piva', 'doc_tipo', 'doc_numero', 'doc_rilasciato_da', 'note', 'viaggia_con'];
const DATE_CLIENTE = ['nato_il', 'doc_rilasciato_il', 'doc_scade', 'ultimo_contatto'];

function clienti(ctx) {
  const q = S(ctx.query.q).toLowerCase();
  const lim = Math.min(500, N(ctx.query.limite) || 200);
  let sql = `SELECT c.id, c.cognome, c.nome, c.azienda, c.citta, c.telefono, c.cellulare, c.email, c.cf, c.ultimo_contatto,
      (SELECT COUNT(*) FROM pratiche p WHERE p.cliente_id = c.id) AS n_pratiche
    FROM clienti c`;
  const p = [];
  if (q) {
    sql += ` WHERE lower(c.cognome || ' ' || c.nome) LIKE ? OR lower(c.nome || ' ' || c.cognome) LIKE ? OR lower(c.azienda) LIKE ? OR lower(c.cf) LIKE ? OR c.cellulare LIKE ? OR c.telefono LIKE ? OR lower(c.email) LIKE ?
      OR c.id IN (SELECT cliente_id FROM cliente_compagni WHERE lower(nome) LIKE ?)`;
    const like = `%${q}%`;
    p.push(like, like, like, like, like, like, like, like);
  }
  sql += ' ORDER BY c.cognome, c.nome LIMIT ?';
  p.push(lim);
  const lista = db.all(sql, ...p);
  if (q) for (const c of lista) { // se trovato tramite un compagno di viaggio, lo dice
    const comp = db.get('SELECT nome FROM cliente_compagni WHERE cliente_id = ? AND lower(nome) LIKE ? LIMIT 1', c.id, `%${q}%`);
    if (comp && !(`${c.cognome} ${c.nome} ${c.nome} ${c.cognome} ${c.azienda}`.toLowerCase().includes(q))) c.compagno = comp.nome;
  }
  return lista;
}
function cliente(ctx) {
  const id = N(ctx.query.id);
  const c = db.get('SELECT * FROM clienti WHERE id = ?', id);
  if (!c) err(404, 'Cliente non trovato');
  c.pratiche = db.all(`SELECT p.id, p.anno, p.numero, p.stato, p.bozza, p.tipo, p.destinazione, p.partenza, p.rientro, p.data_apertura
     FROM pratiche p WHERE p.cliente_id = ? ORDER BY p.anno DESC, p.numero DESC`, id).map(arricchisciTotali);
  c.statistiche = statisticheCliente(id);
  c.documenti = db.all('SELECT * FROM cliente_documenti WHERE cliente_id = ? ORDER BY ordine, id', id);
  c.contatti = db.all('SELECT * FROM cliente_contatti WHERE cliente_id = ? ORDER BY ordine, id', id);
  c.tessere = db.all('SELECT * FROM cliente_tessere WHERE cliente_id = ? ORDER BY ordine, id', id);
  c.compagni = db.all('SELECT * FROM cliente_compagni WHERE cliente_id = ? ORDER BY ordine, id', id);
  return c;
}
// aggiunge/aggiorna i compagni di viaggio di un cliente (per nome, senza doppioni)
function aggiornaCompagni(cid, lista) {
  for (const q of lista) {
    const nome = S(q.nome); if (!nome) continue;
    const ex = db.get('SELECT id FROM cliente_compagni WHERE cliente_id = ? AND lower(nome) = lower(?)', cid, nome);
    if (ex) db.run("UPDATE cliente_compagni SET nato_il = CASE WHEN ? <> '' THEN ? ELSE nato_il END, doc_tipo = CASE WHEN ? <> '' THEN ? ELSE doc_tipo END, doc_numero = CASE WHEN ? <> '' THEN ? ELSE doc_numero END, doc_scade = CASE WHEN ? <> '' THEN ? ELSE doc_scade END WHERE id = ?",
      DATA(q.nato_il), DATA(q.nato_il), S(q.doc_tipo), S(q.doc_tipo), S(q.doc_numero), S(q.doc_numero), DATA(q.doc_scade), DATA(q.doc_scade), ex.id);
    else db.run('INSERT INTO cliente_compagni(cliente_id, ordine, nome, nato_il, doc_tipo, doc_numero, doc_scade) VALUES(?,?,?,?,?,?,?)', cid, db.get('SELECT COUNT(*) AS n FROM cliente_compagni WHERE cliente_id = ?', cid).n, nome, DATA(q.nato_il), S(q.doc_tipo), S(q.doc_numero), DATA(q.doc_scade));
  }
}
// ricerca passeggeri: compagni del cliente, altri compagni, clienti
function passeggeri_cerca(ctx) {
  const q = S(ctx.query.q).toLowerCase(); const cid = N(ctx.query.cliente_id);
  if (!q) return [];
  const like = `%${q}%`;
  const comp = db.all(`SELECT k.*, c.cognome || ' ' || c.nome AS cliente FROM cliente_compagni k JOIN clienti c ON c.id = k.cliente_id WHERE lower(k.nome) LIKE ? ORDER BY (k.cliente_id = ?) DESC, k.nome LIMIT 10`, like, cid)
    .map(k => ({ tipo: 'compagno', nome: k.nome, nato_il: k.nato_il, doc_tipo: k.doc_tipo, doc_numero: k.doc_numero, doc_scade: k.doc_scade, cliente_id: k.cliente_id, cliente: k.cliente }));
  const cl = db.all(`SELECT c.*, (SELECT tipo FROM cliente_documenti d WHERE d.cliente_id = c.id ORDER BY ordine, id LIMIT 1) AS d_tipo, (SELECT numero FROM cliente_documenti d WHERE d.cliente_id = c.id ORDER BY ordine, id LIMIT 1) AS d_numero, (SELECT scade FROM cliente_documenti d WHERE d.cliente_id = c.id ORDER BY ordine, id LIMIT 1) AS d_scade
    FROM clienti c WHERE lower(c.cognome || ' ' || c.nome) LIKE ? OR lower(c.nome || ' ' || c.cognome) LIKE ? ORDER BY c.cognome, c.nome LIMIT 8`, like, like)
    .map(c => ({ tipo: 'cliente', nome: `${c.cognome} ${c.nome}`.trim(), nato_il: c.nato_il, doc_tipo: c.d_tipo || '', doc_numero: c.d_numero || '', doc_scade: c.d_scade || '', cliente_id: c.id, cliente: '' }));
  return [...comp, ...cl];
}
// salva le liste collegate del cliente (sostituisce tutto)
function salvaListeCliente(cid, c) {
  if (Array.isArray(c.documenti)) {
    db.run('DELETE FROM cliente_documenti WHERE cliente_id = ?', cid);
    let o = 0;
    for (const d of c.documenti) { if (!S(d.tipo) && !S(d.numero) && !S(d.scade)) continue;
      db.run('INSERT INTO cliente_documenti(cliente_id, ordine, tipo, numero, rilasciato_da, rilasciato_il, scade, note) VALUES(?,?,?,?,?,?,?,?)', cid, o++, S(d.tipo), S(d.numero), S(d.rilasciato_da), DATA(d.rilasciato_il), DATA(d.scade), S(d.note)); }
    // il primo documento resta anche nei campi vecchi (compatibilità)
    const primo = db.get('SELECT * FROM cliente_documenti WHERE cliente_id = ? ORDER BY ordine, id LIMIT 1', cid) || {};
    db.run('UPDATE clienti SET doc_tipo = ?, doc_numero = ?, doc_rilasciato_da = ?, doc_rilasciato_il = ?, doc_scade = ? WHERE id = ?', primo.tipo || '', primo.numero || '', primo.rilasciato_da || '', primo.rilasciato_il || '', primo.scade || '', cid);
  }
  if (Array.isArray(c.contatti)) {
    db.run('DELETE FROM cliente_contatti WHERE cliente_id = ?', cid);
    let o = 0;
    for (const d of c.contatti) { if (!S(d.valore)) continue; db.run('INSERT INTO cliente_contatti(cliente_id, ordine, tipo, valore, note) VALUES(?,?,?,?,?)', cid, o++, S(d.tipo), S(d.valore), S(d.note)); }
  }
  if (Array.isArray(c.compagni)) {
    db.run('DELETE FROM cliente_compagni WHERE cliente_id = ?', cid);
    let o = 0;
    for (const d of c.compagni) { if (!S(d.nome)) continue; db.run('INSERT INTO cliente_compagni(cliente_id, ordine, nome, nato_il, doc_tipo, doc_numero, doc_scade, note) VALUES(?,?,?,?,?,?,?,?)', cid, o++, S(d.nome), DATA(d.nato_il), S(d.doc_tipo), S(d.doc_numero), DATA(d.doc_scade), S(d.note)); }
  }
  if (Array.isArray(c.tessere)) {
    db.run('DELETE FROM cliente_tessere WHERE cliente_id = ?', cid);
    let o = 0;
    for (const d of c.tessere) { if (!S(d.tipo) && !S(d.numero)) continue; db.run('INSERT INTO cliente_tessere(cliente_id, ordine, tipo, numero, scade, note) VALUES(?,?,?,?,?,?)', cid, o++, S(d.tipo), S(d.numero), DATA(d.scade), S(d.note)); }
  }
}
function tessere_tipi() {
  // tipi di tessera già usati (per i suggerimenti), finché non ci saranno vincoli
  const base = ['MSC Voyagers Club', 'Costa Club', 'Trenitalia CartaFreccia', 'Italo Più', 'ITA Airways Volare', 'Lufthansa Miles & More', 'Royal Caribbean Crown & Anchor', 'Norwegian Latitudes'];
  const usati = db.all("SELECT DISTINCT tipo FROM cliente_tessere WHERE tipo <> '' ORDER BY tipo").map(r => r.tipo);
  return [...new Set([...usati, ...base])];
}
function cliente_salva(ctx) {
  const c = ctx.corpo || {};
  richiedi(S(c.cognome) || S(c.azienda), 'Serve almeno il cognome (o l\'azienda)');
  const id = N(c.id);
  const ts = db.adesso();
  const vals = {};
  for (const k of CAMPI_CLIENTE) vals[k] = S(c[k]);
  for (const k of DATE_CLIENTE) vals[k] = DATA(c[k]);
  if (vals.cf) vals.cf = vals.cf.toUpperCase();
  return db.transazione(() => {
    if (id) {
      if (!db.get('SELECT id FROM clienti WHERE id = ?', id)) err(404, 'Cliente non trovato');
      const set = Object.keys(vals).map(k => `${k} = ?`).join(', ');
      db.run(`UPDATE clienti SET ${set}, aggiornato_ts = ?, aggiornato_da = ? WHERE id = ?`, ...Object.values(vals), ts, ctx.utente.id, id);
      salvaListeCliente(id, c);
      db.log(ctx.utente, 'cliente_modifica', 'cliente', id, `${vals.cognome} ${vals.nome}`);
      return { id };
    }
    const cols = Object.keys(vals);
    const r = db.run(`INSERT INTO clienti(${cols.join(',')}, creato_ts, creato_da, aggiornato_ts, aggiornato_da) VALUES(${cols.map(() => '?').join(',')},?,?,?,?)`,
      ...Object.values(vals), ts, ctx.utente.id, ts, ctx.utente.id);
    const nid = Number(r.lastInsertRowid);
    salvaListeCliente(nid, c);
    db.log(ctx.utente, 'cliente_crea', 'cliente', nid, `${vals.cognome} ${vals.nome}`);
    return { id: nid };
  });
}
function cliente_elimina(ctx) {
  const id = N(ctx.corpo.id);
  const n = db.get('SELECT COUNT(*) AS n FROM pratiche WHERE cliente_id = ?', id).n;
  if (n > 0) err(400, `Il cliente ha ${n} pratiche: non si può cancellare`);
  db.run('DELETE FROM clienti WHERE id = ?', id);
  for (const t of ['cliente_documenti', 'cliente_contatti', 'cliente_tessere', 'cliente_compagni']) db.run(`DELETE FROM ${t} WHERE cliente_id = ?`, id);
  db.log(ctx.utente, 'cliente_elimina', 'cliente', id, '');
  return { ok: true };
}

// ---------- fornitori ----------
const CAMPI_FORNITORE = ['nome', 'tipo', 'indirizzo', 'cap', 'citta', 'provincia', 'nazione', 'telefono', 'fax', 'email', 'web', 'piva', 'cf', 'referente', 'referente_tel', 'referente_email', 'pagamento', 'iban', 'banca', 'note', 'codice_agenzia'];
function salvaIbanFornitore(fid, c) {
  if (!Array.isArray(c.ibans)) return;
  db.run('DELETE FROM fornitore_iban WHERE fornitore_id = ?', fid);
  let o = 0;
  for (const d of c.ibans) { if (!S(d.iban) && !S(d.banca)) continue; db.run('INSERT INTO fornitore_iban(fornitore_id, ordine, intestazione, banca, iban, note) VALUES(?,?,?,?,?,?)', fid, o++, S(d.intestazione), S(d.banca), S(d.iban).replace(/\s+/g, '').toUpperCase(), S(d.note)); }
  const primo = db.get('SELECT * FROM fornitore_iban WHERE fornitore_id = ? ORDER BY ordine, id LIMIT 1', fid) || {};
  db.run('UPDATE fornitori SET iban = ?, banca = ? WHERE id = ?', primo.iban || '', primo.banca || '', fid);
}

function fornitori(ctx) {
  const q = S(ctx.query.q).toLowerCase();
  const tutti = B(ctx.query.tutti);
  let sql = `SELECT f.id, f.nome, f.tipo, f.citta, f.telefono, f.email, f.referente, f.attivo,
      (SELECT COUNT(DISTINCT pratica_id) FROM pratica_righe r WHERE r.fornitore_id = f.id) AS n_pratiche
    FROM fornitori f WHERE 1=1`;
  const p = [];
  if (!tutti) sql += ' AND f.attivo = 1';
  if (q) { sql += ' AND (lower(f.nome) LIKE ? OR lower(f.tipo) LIKE ? OR lower(f.citta) LIKE ?)'; p.push(`%${q}%`, `%${q}%`, `%${q}%`); }
  sql += ' ORDER BY f.nome';
  return db.all(sql, ...p);
}
function fornitore(ctx) {
  const id = N(ctx.query.id);
  const f = db.get('SELECT * FROM fornitori WHERE id = ?', id);
  if (!f) err(404, 'Fornitore non trovato');
  f.situazione = situazioneFornitore(id);
  f.statistiche = statisticheFornitore(id);
  f.ibans = db.all('SELECT * FROM fornitore_iban WHERE fornitore_id = ? ORDER BY ordine, id', id);
  return f;
}
function fornitore_salva(ctx) {
  const c = ctx.corpo || {};
  richiedi(S(c.nome), 'Serve il nome del fornitore');
  const id = N(c.id);
  const ts = db.adesso();
  const vals = {};
  for (const k of CAMPI_FORNITORE) vals[k] = S(c[k]);
  vals.provvigione = parseFloat(String(c.provvigione || '0').replace(',', '.')) || 0;
  vals.attivo = c.attivo === undefined ? 1 : B(c.attivo);
  if (id) {
    if (!db.get('SELECT id FROM fornitori WHERE id = ?', id)) err(404, 'Fornitore non trovato');
    const set = Object.keys(vals).map(k => `${k} = ?`).join(', ');
    db.run(`UPDATE fornitori SET ${set}, aggiornato_ts = ?, aggiornato_da = ? WHERE id = ?`, ...Object.values(vals), ts, ctx.utente.id, id);
    salvaIbanFornitore(id, c);
    db.log(ctx.utente, 'fornitore_modifica', 'fornitore', id, vals.nome);
    return { id };
  }
  const cols = Object.keys(vals);
  const r = db.run(`INSERT INTO fornitori(${cols.join(',')}, creato_ts, creato_da, aggiornato_ts, aggiornato_da) VALUES(${cols.map(() => '?').join(',')},?,?,?,?)`,
    ...Object.values(vals), ts, ctx.utente.id, ts, ctx.utente.id);
  const nid = Number(r.lastInsertRowid);
  salvaIbanFornitore(nid, c);
  db.log(ctx.utente, 'fornitore_crea', 'fornitore', nid, vals.nome);
  return { id: nid };
}
function fornitore_elimina(ctx) {
  const id = N(ctx.corpo.id);
  const n = db.get('SELECT COUNT(*) AS n FROM pratica_righe WHERE fornitore_id = ?', id).n;
  if (n > 0) err(400, `Il fornitore è usato in ${n} righe di pratica: puoi solo disattivarlo`);
  db.run('DELETE FROM fornitori WHERE id = ?', id);
  db.log(ctx.utente, 'fornitore_elimina', 'fornitore', id, '');
  return { ok: true };
}

// Situazione dare/avere di un fornitore, pratica per pratica (il "pagato" viene dagli acconti del cliente assegnati a lui).
function situazioneFornitore(fid) {
  const ids = db.all(`SELECT DISTINCT p.id FROM pratiche p WHERE p.id IN (SELECT pratica_id FROM pratica_righe WHERE fornitore_id = ?) ORDER BY p.anno DESC, p.numero DESC`, fid).map(r => r.id);
  const righe = [];
  for (const id of ids) {
    const p = caricaPratica(id);
    const f = (p.fornitori || []).find(x => x.fornitore_id === fid); if (!f) continue;
    righe.push({ id: p.id, anno: p.anno, numero: p.numero, stato: p.stato, partenza: p.partenza, destinazione: p.destinazione, cliente_id: p.cliente_id, cliente: p.cliente,
      costo_cent: f.costo_cent, venduto_cent: f.venduto_cent, pagato_cent: f.pagato_cent, cliente_versato_cent: f.cliente_versato_cent,
      dovuto_cent: (p.stato === 'Annullata' || p.stato === 'Preventivo') ? 0 : f.dovuto_cent });
  }
  const tot = { costo_cent: 0, pagato_cent: 0, dovuto_cent: 0, venduto_cent: 0 };
  for (const r of righe) if (r.stato !== 'Annullata' && r.stato !== 'Preventivo') { tot.costo_cent += r.costo_cent; tot.pagato_cent += r.pagato_cent; tot.dovuto_cent += r.dovuto_cent; tot.venduto_cent += r.venduto_cent; }
  return { pratiche: righe, totali: tot };
}
function situazioneFornitoreVecchia(fid) {
  const righe = db.all(`SELECT p.id, p.anno, p.numero, p.stato, p.partenza, p.destinazione, p.cliente_id,
      c.cognome || ' ' || c.nome AS cliente,
      (SELECT COALESCE(SUM(r.costo_cent * r.pax),0) FROM pratica_righe r WHERE r.pratica_id = p.id AND r.fornitore_id = ?) AS costo_cent,
      (SELECT COALESCE(SUM((r.prezzo_cent + COALESCE((SELECT SUM(c.prezzo_cent) FROM pratica_righe c WHERE c.padre_id = r.id),0)) * r.pax),0) FROM pratica_righe r WHERE r.pratica_id = p.id AND r.fornitore_id = ?) AS venduto_cent,
      (SELECT COALESCE(SUM(g.importo_cent),0) FROM pagamenti_fornitore g WHERE g.pratica_id = p.id AND g.fornitore_id = ?) AS pagato_cent,
      (SELECT COALESCE(SUM(a.importo_cent),0) FROM acconti a WHERE a.pratica_id = p.id AND a.fornitore_id = ?) AS cliente_versato_cent
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id
    WHERE p.id IN (SELECT DISTINCT pratica_id FROM pratica_righe WHERE fornitore_id = ?)
    ORDER BY p.anno DESC, p.numero DESC`, fid, fid, fid, fid, fid);
  let tot = { costo_cent: 0, pagato_cent: 0, dovuto_cent: 0, venduto_cent: 0 };
  for (const r of righe) {
    r.dovuto_cent = (r.stato === 'Annullata' || r.stato === 'Preventivo') ? 0 : r.costo_cent - r.pagato_cent;
    if (r.stato !== 'Annullata' && r.stato !== 'Preventivo') {
      tot.costo_cent += r.costo_cent; tot.pagato_cent += r.pagato_cent; tot.dovuto_cent += r.dovuto_cent; tot.venduto_cent += r.venduto_cent;
    }
  }
  return { pratiche: righe, totali: tot };
}

// ---------- pratiche ----------
const STATI = ['Preventivo', 'Confermata', 'Annullata', 'Annullata con penale', 'Chiusa'];
const STATI_VALIDI = ['Confermata', 'Chiusa']; // contano per vendite/scadenze
const CAMPI_PRATICA_TESTO = ['tipo', 'catalogo', 'catalogo_pag', 'riferimento_to', 'operatore_to', 'destinazione', 'partenza_da', 'partenza_a', 'rientro_da', 'rientro_a', 'pnr', 'documenti_necessari', 'richieste', 'note'];

function prossimo_numero(ctx) {
  const anno = N(ctx.query.anno) || new Date().getFullYear();
  return { anno, numero: prossimoNumero(anno) };
}
function prossimoNumero(anno) {
  const num = db.impostazione('numerazione');
  const max = db.get('SELECT MAX(numero) AS m FROM pratiche WHERE anno = ?', anno).m;
  if (max) return max + 1;
  return (anno === N(num.anno_iniziale)) ? Math.max(1, N(num.numero_iniziale)) : 1;
}

// Totali calcolati di una pratica (aggiunti alla riga di lista o alla scheda).
function arricchisciTotali(p) {
  const t = db.get(`SELECT
      (SELECT COALESCE(SUM(prezzo_cent * pax),0) FROM pratica_righe WHERE pratica_id = ?) + (SELECT COALESCE(SUM((importo_cent + adv_fee_cent + commissione_cent) * pax),0) FROM biglietti WHERE pratica_id = ?) AS totale_cent,
      (SELECT COALESCE(SUM(costo_cent * pax),0) FROM pratica_righe WHERE pratica_id = ?) + (SELECT COALESCE(SUM(importo_cent * pax),0) FROM biglietti WHERE pratica_id = ?) AS costo_cent,
      (SELECT COALESCE(SUM(importo_cent),0) FROM acconti WHERE pratica_id = ?) AS versato_cent,
      (SELECT COALESCE(SUM(importo_cent),0) FROM pagamenti_fornitore WHERE pratica_id = ?) AS pagato_fornitori_cent,
      (SELECT abbuono_cent FROM pratiche WHERE id = ?) AS abbuono_cent,
      (SELECT penale_cent FROM pratiche WHERE id = ?) AS penale_cent,
      (SELECT stato FROM pratiche WHERE id = ?) AS stato`, p.id, p.id, p.id, p.id, p.id, p.id, p.id, p.id, p.id);
  p.totale_cent = t.totale_cent;
  p.costo_cent = t.costo_cent;
  p.versato_cent = t.versato_cent;
  p.pagato_fornitori_cent = t.pagato_fornitori_cent;
  // Dovuto dal cliente: totale − abbuono; se annullata con penale → la penale; se annullata → 0
  let dovuto = t.totale_cent - t.abbuono_cent;
  if (t.stato === 'Annullata con penale') dovuto = t.penale_cent;
  if (t.stato === 'Annullata') dovuto = 0;
  p.dovuto_cent = dovuto;
  p.saldo_cent = dovuto - t.versato_cent;
  p.margine_cent = (t.stato === 'Annullata' || t.stato === 'Annullata con penale') ? 0 : t.totale_cent - t.costo_cent;
  return p;
}

function pratiche(ctx) {
  const q = S(ctx.query.q).toLowerCase();
  const anno = N(ctx.query.anno);
  const stato = S(ctx.query.stato);
  const clienteId = N(ctx.query.cliente_id);
  const fornitoreId = N(ctx.query.fornitore_id);
  const lim = Math.min(1000, N(ctx.query.limite) || 300);
  let sql = `SELECT p.id, p.anno, p.numero, p.stato, p.bozza, p.tipo, p.pacchetto_servizio, p.destinazione, p.partenza, p.rientro, p.data_apertura, p.saldo_entro, p.cliente_id,
      (SELECT COUNT(*) FROM biglietti b WHERE b.pratica_id = p.id) AS n_biglietti,
      c.cognome || ' ' || c.nome AS cliente, u.nome AS operatore,
      (SELECT group_concat(DISTINCT f.nome) FROM pratica_righe r JOIN fornitori f ON f.id = r.fornitore_id WHERE r.pratica_id = p.id) AS fornitori
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id LEFT JOIN utenti u ON u.id = p.operatore_id WHERE 1=1`;
  const p = [];
  if (anno) { sql += ' AND p.anno = ?'; p.push(anno); }
  if (stato) { sql += ' AND p.stato = ?'; p.push(stato); }
  if (clienteId) { sql += ' AND p.cliente_id = ?'; p.push(clienteId); }
  if (fornitoreId) { sql += ' AND p.id IN (SELECT pratica_id FROM pratica_righe WHERE fornitore_id = ?)'; p.push(fornitoreId); }
  if (q) {
    sql += ` AND (lower(c.cognome || ' ' || c.nome) LIKE ? OR lower(c.nome || ' ' || c.cognome) LIKE ? OR lower(p.destinazione) LIKE ? OR CAST(p.numero AS TEXT) = ? OR lower(p.riferimento_to) LIKE ?)`;
    p.push(`%${q}%`, `%${q}%`, `%${q}%`, q.replace(/^0+/, ''), `%${q}%`);
  }
  sql += ' ORDER BY p.anno DESC, p.numero DESC LIMIT ?';
  p.push(lim);
  return db.all(sql, ...p).map(arricchisciTotali);
}
function pratiche_anni() {
  return db.all('SELECT DISTINCT anno FROM pratiche ORDER BY anno DESC').map(r => r.anno);
}

function caricaPratica(id) {
  const p = db.get(`SELECT p.*, c.cognome || ' ' || c.nome AS cliente, u.nome AS operatore, u.username AS operatore_username
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id LEFT JOIN utenti u ON u.id = p.operatore_id WHERE p.id = ?`, id);
  if (!p) err(404, 'Pratica non trovata');
  p.cliente_dati = db.get('SELECT * FROM clienti WHERE id = ?', p.cliente_id);
  const tutteRighe = db.all(`SELECT r.*, f.nome AS fornitore FROM pratica_righe r LEFT JOIN fornitori f ON f.id = r.fornitore_id WHERE r.pratica_id = ? ORDER BY r.ordine, r.id`, id);
  p.righe = tutteRighe.filter(r => !r.padre_id).map(r => ({ ...r, figli: tutteRighe.filter(c => c.padre_id === r.id) }));
  for (const r of p.righe) r.nascosti_cent = r.figli.filter(c => !c.stampa).reduce((s, c) => s + c.prezzo_cent, 0); // per persona
  p.partecipanti = db.all('SELECT * FROM pratica_partecipanti WHERE pratica_id = ? ORDER BY ordine, id', id);
  p.biglietti = db.all(`SELECT b.*, u.nome AS operatore FROM biglietti b LEFT JOIN utenti u ON u.id = b.operatore_id WHERE b.pratica_id = ? ORDER BY b.data, b.id`, id);
  p.acconti = db.all(`SELECT a.*, f.nome AS fornitore, u.nome AS operatore,
      CASE WHEN b.id IS NULL THEN NULL ELSE 'Biglietteria · B' || b.numero || '/' || b.anno || ' ' || b.vettore || CASE WHEN b.canale <> '' THEN ' · ' || b.canale ELSE '' END END AS biglietto_rif
    FROM acconti a LEFT JOIN fornitori f ON f.id = a.fornitore_id LEFT JOIN utenti u ON u.id = a.operatore_id LEFT JOIN biglietti b ON b.id = a.biglietto_id WHERE a.pratica_id = ? ORDER BY a.data, a.id`, id);
  p.pagamenti_fornitore = db.all(`SELECT g.*, f.nome AS fornitore, u.nome AS operatore FROM pagamenti_fornitore g LEFT JOIN fornitori f ON f.id = g.fornitore_id LEFT JOIN utenti u ON u.id = g.operatore_id WHERE g.pratica_id = ? ORDER BY g.data, g.id`, id);
  arricchisciTotali(p);
  // Situazione per fornitore dentro la pratica
  const perF = {};
  for (const r of p.righe) {
    if (!r.fornitore_id) continue;
    const k = r.fornitore_id;
    perF[k] = perF[k] || { fornitore_id: k, fornitore: r.fornitore, venduto_cent: 0, costo_cent: 0, pagato_cent: 0, cliente_versato_cent: 0 };
    perF[k].venduto_cent += (r.prezzo_cent + r.figli.reduce((s, c) => s + c.prezzo_cent, 0)) * r.pax;
    perF[k].costo_cent += r.costo_cent * r.pax;
  }
  for (const g of p.pagamenti_fornitore) {
    perF[g.fornitore_id] = perF[g.fornitore_id] || { fornitore_id: g.fornitore_id, fornitore: g.fornitore, venduto_cent: 0, costo_cent: 0, pagato_cent: 0, cliente_versato_cent: 0 };
    perF[g.fornitore_id].pagato_cent += g.importo_cent;
  }
  for (const a of p.acconti) {
    if (a.fornitore_id && perF[a.fornitore_id]) perF[a.fornitore_id].cliente_versato_cent += a.importo_cent;
  }
  for (const b of p.biglietti) { // i biglietti collegati: il loro fornitore (canale o vettore) compare tra i fornitori della pratica
    const k = 'b' + b.id; const nomeF = (b.tipo === 'aereo' && b.canale) ? b.canale : b.vettore;
    perF[k] = { fornitore_id: null, biglietto_id: b.id, fornitore: `${nomeF || 'Biglietto'} · B${b.numero}/${b.anno}`, venduto_cent: (b.importo_cent + b.adv_fee_cent + b.commissione_cent) * (b.pax || 1), costo_cent: b.importo_cent * (b.pax || 1), pagato_cent: 0, cliente_versato_cent: 0 };
    for (const a of p.acconti) if (a.biglietto_id === b.id) perF[k].cliente_versato_cent += a.importo_cent;
  }
  // acconti senza riferimento: ripartiti in proporzione al costo, finché ogni fornitore è coperto; il resto è dell'agenzia
  const lista = Object.values(perF);
  let libero = p.acconti.filter(a => !a.fornitore_id && !a.biglietto_id).reduce((s, a) => s + a.importo_cent, 0);
  const totCosto = lista.reduce((s, f) => s + f.costo_cent, 0);
  for (const f of lista) f.da_riparto_cent = 0;
  if (libero > 0 && totCosto > 0) {
    let resta = libero;
    for (const f of lista) { const quota = Math.min(Math.max(0, f.costo_cent - f.cliente_versato_cent), Math.round(libero * f.costo_cent / totCosto)); f.da_riparto_cent = quota; resta -= quota; }
    // eventuale avanzo del riparto va ai fornitori ancora scoperti, in ordine
    for (const f of lista) { if (resta <= 0) break; const scoperto = f.costo_cent - f.cliente_versato_cent - f.da_riparto_cent; if (scoperto > 0) { const q = Math.min(scoperto, resta); f.da_riparto_cent += q; resta -= q; } }
    p.agenzia_incassato_cent = resta;
  } else p.agenzia_incassato_cent = libero;
  // "Pagato al fornitore" = la parte del suo costo già coperta dagli acconti del cliente (riferiti o ripartiti); "ancora da pagare" = il resto
  p.fornitori = lista.map(f => { const vers = f.cliente_versato_cent + f.da_riparto_cent; const pagato = Math.min(vers, f.costo_cent); return { ...f, cliente_versato_cent: vers, pagato_cent: pagato, dovuto_cent: f.costo_cent - pagato }; });
  // all'agenzia resta tutto ciò che supera le quote dei fornitori (anche se versato "riferito" oltre il costo)
  p.agenzia_incassato_cent = Math.max(0, p.versato_cent - p.fornitori.reduce((s, f) => s + Math.min(f.cliente_versato_cent, f.costo_cent), 0));
  return p;
}
function pratica(ctx) { return caricaPratica(N(ctx.query.id)); }

function pratica_salva(ctx) {
  const c = ctx.corpo || {};
  const id = N(c.id);
  const clienteId = N(c.cliente_id);
  richiedi(clienteId && db.get('SELECT id FROM clienti WHERE id = ?', clienteId), 'Scegli il cliente');
  const stato = S(c.stato) || 'Preventivo';
  richiedi(STATI.includes(stato), 'Stato non valido');
  const ts = db.adesso();
  const vals = {};
  for (const k of CAMPI_PRATICA_TESTO) vals[k] = S(c[k]);
  vals.cliente_id = clienteId;
  vals.stato = stato;
  vals.data_apertura = DATA(c.data_apertura) || db.oggi();
  vals.intermediazione = c.intermediazione === undefined ? 1 : B(c.intermediazione);
  vals.pacchetto_servizio = ['servizio', 'biglietteria'].includes(S(c.pacchetto_servizio)) ? S(c.pacchetto_servizio) : 'pacchetto';
  vals.in_catalogo = c.in_catalogo === undefined ? 1 : B(c.in_catalogo);
  vals.partenza = DATA(c.partenza); vals.rientro = DATA(c.rientro);
  vals.adulti = N(c.adulti); vals.bambini = N(c.bambini); vals.infanti = N(c.infanti);
  vals.durata_giorni = N(c.durata_giorni); vals.durata_notti = N(c.durata_notti);
  if (vals.partenza && vals.rientro && !vals.durata_giorni) {
    const g = Math.round((Date.parse(vals.rientro) - Date.parse(vals.partenza)) / 86400000);
    if (g >= 0) { vals.durata_giorni = g + 1; vals.durata_notti = g; }
  }
  vals.abbuono_cent = CENT(c.abbuono);
  vals.saldo_entro = DATA(c.saldo_entro);
  vals.penale_cent = stato === 'Annullata con penale' ? CENT(c.penale) : 0;
  vals.bozza = B(c.bozza);

  const righe = Array.isArray(c.righe) ? c.righe : [];
  const partecipanti = Array.isArray(c.partecipanti) ? c.partecipanti : [];
  for (const r of righe) {
    if (r.fornitore_id && !db.get('SELECT id FROM fornitori WHERE id = ?', N(r.fornitore_id))) err(400, 'Fornitore della riga non trovato');
  }
  // Mettere "in stampa" una voce nascosta richiede la password del pannello (sbloccato in sessione).
  const figliInStampa = righe.reduce((n, r) => n + (Array.isArray(r.figli) ? r.figli.filter(c => B(c.stampa) && (S(c.descrizione) || CENT(c.prezzo))).length : 0), 0);
  if (figliInStampa > 0) {
    const prima = id ? db.get('SELECT COUNT(*) AS n FROM pratica_righe WHERE pratica_id = ? AND padre_id IS NOT NULL AND stampa = 1', id).n : 0;
    const verificato = ctx.sessione && FIGLI_OK.has(ctx.sessione.sid) && Date.now() - FIGLI_OK.get(ctx.sessione.sid) < 15 * 60 * 1000;
    if (figliInStampa > prima && !verificato) err(403, 'Per mettere in stampa una voce nascosta serve la password del pannello opzioni');
  }

  return db.transazione(() => {
    let pid = id;
    if (id) {
      const old = db.get('SELECT * FROM pratiche WHERE id = ?', id);
      if (!old) err(404, 'Pratica non trovata');
      const set = Object.keys(vals).map(k => `${k} = ?`).join(', ');
      db.run(`UPDATE pratiche SET ${set}, aggiornato_ts = ?, aggiornato_da = ? WHERE id = ?`, ...Object.values(vals), ts, ctx.utente.id, id);
      if (old.stato !== stato) db.log(ctx.utente, 'pratica_stato', 'pratica', id, `${old.stato} → ${stato}`);
      db.log(ctx.utente, 'pratica_modifica', 'pratica', id, `${old.anno}/${old.numero}`);
    } else {
      const anno = N(c.anno) || N(vals.data_apertura.slice(0, 4)) || new Date().getFullYear();
      const numero = prossimoNumero(anno);
      const cols = Object.keys(vals);
      const r = db.run(`INSERT INTO pratiche(anno, numero, operatore_id, ${cols.join(',')}, creato_ts, aggiornato_ts, aggiornato_da)
        VALUES(?,?,?,${cols.map(() => '?').join(',')},?,?,?)`, anno, numero, ctx.utente.id, ...Object.values(vals), ts, ts, ctx.utente.id);
      pid = Number(r.lastInsertRowid);
      db.log(ctx.utente, 'pratica_crea', 'pratica', pid, `${anno}/${numero}`);
    }
    db.run('DELETE FROM pratica_righe WHERE pratica_id = ?', pid);
    let ord = 0;
    for (const r of righe) {
      const desc = S(r.descrizione);
      const prezzo = CENT(r.prezzo);
      const figli = (Array.isArray(r.figli) ? r.figli : []).filter(c => S(c.descrizione) || CENT(c.prezzo));
      if (!desc && !prezzo && !figli.length) continue;
      const fid = N(r.fornitore_id) || null;
      const pax = Math.max(0, N(r.pax === undefined ? 1 : r.pax));
      // Il "costo fornitore" è l'importo del padre quando c'è un fornitore: è quanto dovete a lui.
      const ins = db.run('INSERT INTO pratica_righe(pratica_id, ordine, descrizione, fornitore_id, prezzo_cent, pax, costo_cent, stampa, padre_id, rif_to, operatore_to, stampa_dett) VALUES(?,?,?,?,?,?,?,?,NULL,?,?,?)',
        pid, ord++, desc, fid, prezzo, pax, fid ? prezzo : 0, r.stampa === undefined ? 1 : B(r.stampa), S(r.rif_to), S(r.operatore_to), r.stampa_dett === undefined ? 1 : B(r.stampa_dett));
      const padreId = Number(ins.lastInsertRowid);
      for (const c of figli) {
        db.run('INSERT INTO pratica_righe(pratica_id, ordine, descrizione, fornitore_id, prezzo_cent, pax, costo_cent, stampa, padre_id) VALUES(?,?,?,NULL,?,?,0,?,?)',
          pid, ord++, S(c.descrizione), CENT(c.prezzo), pax, c.stampa === undefined ? 0 : B(c.stampa), padreId);
      }
    }
    db.run('DELETE FROM pratica_partecipanti WHERE pratica_id = ?', pid);
    ord = 0;
    for (const q of partecipanti) {
      const nome = S(q.nome);
      if (!nome) continue;
      db.run('INSERT INTO pratica_partecipanti(pratica_id, ordine, nome, nato_il, doc_tipo, doc_numero, doc_scade) VALUES(?,?,?,?,?,?,?)', pid, ord++, nome, DATA(q.nato_il), S(q.doc_tipo), S(q.doc_numero), DATA(q.doc_scade));
    }
    const cl = db.get('SELECT cognome, nome FROM clienti WHERE id = ?', clienteId);
    const nomeCl = `${cl.cognome} ${cl.nome}`.trim().toLowerCase();
    aggiornaCompagni(clienteId, partecipanti.filter(q => { const n = S(q.nome).toLowerCase(); return n && n !== nomeCl && n !== `${cl.nome} ${cl.cognome}`.trim().toLowerCase(); }));
    db.run('UPDATE clienti SET ultimo_contatto = ? WHERE id = ?', db.oggi(), clienteId);
    return caricaPratica(pid);
  });
}
function pratica_elimina(ctx) {
  const id = N(ctx.corpo.id);
  const p = db.get('SELECT * FROM pratiche WHERE id = ?', id);
  if (!p) err(404, 'Pratica non trovata');
  const nAcc = db.get('SELECT COUNT(*) AS n FROM acconti WHERE pratica_id = ?', id).n;
  if (nAcc > 0) err(400, 'La pratica ha acconti registrati: cancellali prima, oppure mettila in stato Annullata');
  db.transazione(() => {
    db.run('DELETE FROM pratica_righe WHERE pratica_id = ?', id);
    db.run('DELETE FROM pratica_partecipanti WHERE pratica_id = ?', id);
    db.run('DELETE FROM pagamenti_fornitore WHERE pratica_id = ?', id);
    db.run('UPDATE biglietti SET pratica_id = NULL WHERE pratica_id = ?', id);
    db.run('DELETE FROM pratiche WHERE id = ?', id);
  });
  db.log(ctx.utente, 'pratica_elimina', 'pratica', id, `${p.anno}/${p.numero}`);
  return { ok: true };
}

function acconto_salva(ctx) {
  const c = ctx.corpo || {};
  const pid = N(c.pratica_id);
  const bid = N(c.biglietto_id);
  if (!pid && !bid) err(400, 'Manca la pratica o il biglietto');
  if (pid && !db.get('SELECT id FROM pratiche WHERE id = ?', pid)) err(404, 'Pratica non trovata');
  if (bid) {
    const b = db.get('SELECT id, pratica_id FROM biglietti WHERE id = ?', bid);
    if (!b) err(404, 'Biglietto non trovato');
    if (pid) { if (b.pratica_id !== pid) err(400, 'Il biglietto non è collegato a questa pratica'); }
    else if (b.pratica_id) err(400, 'Il biglietto è collegato a una pratica: registra l\'acconto sulla pratica');
  }
  const importo = CENT(c.importo);
  richiedi(importo !== 0, 'Importo mancante');
  const data = DATA(c.data) || db.oggi();
  const fid = N(c.fornitore_id) || null;
  const id = N(c.id);
  const su = pid ? 'pratica' : 'biglietto';
  if (id) {
    db.run('UPDATE acconti SET data = ?, importo_cent = ?, metodo = ?, descrizione = ?, fornitore_id = ?, biglietto_id = ? WHERE id = ?', data, importo, S(c.metodo), S(c.descrizione), fid, bid || null, id);
    db.log(ctx.utente, 'acconto_modifica', su, pid || bid, `${importo / 100} €`);
  } else {
    db.run('INSERT INTO acconti(pratica_id, biglietto_id, data, importo_cent, metodo, descrizione, fornitore_id, operatore_id, creato_ts) VALUES(?,?,?,?,?,?,?,?,?)',
      pid || 0, bid || null, data, importo, S(c.metodo), S(c.descrizione), fid, ctx.utente.id, db.adesso());
    db.log(ctx.utente, 'acconto_crea', su, pid || bid, `${importo / 100} € ${S(c.metodo)}`);
  }
  return pid ? caricaPratica(pid) : biglietto({ query: { id: bid } });
}
function acconto_elimina(ctx) {
  const a = db.get('SELECT * FROM acconti WHERE id = ?', N(ctx.corpo.id));
  if (!a) err(404, 'Acconto non trovato');
  db.run('DELETE FROM acconti WHERE id = ?', a.id);
  db.log(ctx.utente, 'acconto_elimina', a.pratica_id ? 'pratica' : 'biglietto', a.pratica_id || a.biglietto_id, `${a.importo_cent / 100} € del ${a.data}`);
  return a.pratica_id ? caricaPratica(a.pratica_id) : biglietto({ query: { id: a.biglietto_id } });
}
function pagamento_fornitore_salva(ctx) {
  const c = ctx.corpo || {};
  const pid = N(c.pratica_id);
  if (!db.get('SELECT id FROM pratiche WHERE id = ?', pid)) err(404, 'Pratica non trovata');
  const fid = N(c.fornitore_id);
  richiedi(fid && db.get('SELECT id FROM fornitori WHERE id = ?', fid), 'Scegli il fornitore');
  const importo = CENT(c.importo);
  richiedi(importo !== 0, 'Importo mancante');
  const data = DATA(c.data) || db.oggi();
  const id = N(c.id);
  if (id) {
    db.run('UPDATE pagamenti_fornitore SET fornitore_id = ?, data = ?, importo_cent = ?, metodo = ?, descrizione = ? WHERE id = ? AND pratica_id = ?',
      fid, data, importo, S(c.metodo), S(c.descrizione), id, pid);
    db.log(ctx.utente, 'pagamento_fornitore_modifica', 'pratica', pid, `${importo / 100} €`);
  } else {
    db.run('INSERT INTO pagamenti_fornitore(pratica_id, fornitore_id, data, importo_cent, metodo, descrizione, operatore_id, creato_ts) VALUES(?,?,?,?,?,?,?,?)',
      pid, fid, data, importo, S(c.metodo), S(c.descrizione), ctx.utente.id, db.adesso());
    db.log(ctx.utente, 'pagamento_fornitore_crea', 'pratica', pid, `${importo / 100} € a fornitore ${fid}`);
  }
  return caricaPratica(pid);
}
function pagamento_fornitore_elimina(ctx) {
  const g = db.get('SELECT * FROM pagamenti_fornitore WHERE id = ?', N(ctx.corpo.id));
  if (!g) err(404, 'Pagamento non trovato');
  db.run('DELETE FROM pagamenti_fornitore WHERE id = ?', g.id);
  db.log(ctx.utente, 'pagamento_fornitore_elimina', 'pratica', g.pratica_id, `${g.importo_cent / 100} € del ${g.data}`);
  return caricaPratica(g.pratica_id);
}

// ---------- BIGLIETTERIA ----------
const TIPI_BIGLIETTO = ['aereo', 'treno', 'lowcost'];
const OPERAZIONI_BIGLIETTO = ['emissione', 'cambio', 'rimborso'];
function prossimoNumeroB(anno) {
  const num = db.impostazione('numerazione');
  const max = db.get('SELECT MAX(numero) AS m FROM biglietti WHERE anno = ?', anno).m;
  if (max) return max + 1;
  return (anno === N(num.anno_iniziale)) ? Math.max(1, N(num.numero_iniziale_b)) : 1;
}
function prossimo_numero_b(ctx) { const anno = N(ctx.query.anno) || new Date().getFullYear(); return { anno, numero: prossimoNumeroB(anno) }; }
function arricchisciBiglietto(b) {
  b.pax = b.pax || 1;
  b.unitario_cent = b.importo_cent + b.adv_fee_cent + b.commissione_cent;
  b.totale_cent = b.unitario_cent * b.pax;
  b.etichetta = `B${b.numero}/${b.anno}`;
  // acconti propri solo se il biglietto NON è collegato a una pratica (altrimenti si incassa dalla pratica)
  b.versato_cent = b.pratica_id ? 0 : (db.get('SELECT COALESCE(SUM(importo_cent),0) AS v FROM acconti WHERE biglietto_id = ? AND pratica_id = 0', b.id).v);
  b.saldo_cent = b.pratica_id ? 0 : b.totale_cent - b.versato_cent;
  return b;
}
function biglietti(ctx) {
  const q = S(ctx.query.q).toLowerCase();
  const anno = N(ctx.query.anno), mese = N(ctx.query.mese), tipo = S(ctx.query.tipo);
  const clienteId = N(ctx.query.cliente_id), praticaId = N(ctx.query.pratica_id);
  const vettore = S(ctx.query.vettore), canale = S(ctx.query.canale);
  let sql = `SELECT b.*, c.cognome || ' ' || c.nome AS cliente, u.nome AS operatore, p.numero AS pratica_numero, p.anno AS pratica_anno
    FROM biglietti b LEFT JOIN clienti c ON c.id = b.cliente_id LEFT JOIN utenti u ON u.id = b.operatore_id LEFT JOIN pratiche p ON p.id = b.pratica_id WHERE 1=1`;
  const pr = [];
  if (anno) { sql += ' AND b.anno = ?'; pr.push(anno); }
  if (mese) { sql += " AND CAST(substr(b.data,6,2) AS INTEGER) = ?"; pr.push(mese); }
  if (tipo) { sql += ' AND b.tipo = ?'; pr.push(tipo); }
  if (clienteId) { sql += ' AND b.cliente_id = ?'; pr.push(clienteId); }
  if (praticaId) { sql += ' AND b.pratica_id = ?'; pr.push(praticaId); }
  if (B(ctx.query.liberi)) sql += ' AND b.pratica_id IS NULL';
  if (vettore) { sql += ' AND b.vettore = ?'; pr.push(vettore); }
  if (canale) { sql += ' AND b.canale = ?'; pr.push(canale); }
  if (q) { sql += ` AND (lower(c.cognome || ' ' || c.nome) LIKE ? OR lower(b.passeggero) LIKE ? OR lower(b.itinerario) LIKE ? OR lower(b.codice) LIKE ? OR CAST(b.numero AS TEXT) = ? OR lower(b.note) LIKE ?)`; const l = `%${q}%`; pr.push(l, l, l, l, q.replace(/^b/, ''), l); }
  sql += ' ORDER BY b.data DESC, b.id DESC LIMIT 2000';
  const lista = db.all(sql, ...pr).map(arricchisciBiglietto);
  // riepilogo come nell'Excel: per tipo e per canale/vettore
  const tot = {};
  for (const b of lista) {
    const k = b.tipo + '|' + (b.tipo === 'aereo' ? b.canale : b.vettore);
    tot[k] = tot[k] || { tipo: b.tipo, gruppo: b.tipo === 'aereo' ? b.canale : b.vettore, n: 0, importo_cent: 0, adv_fee_cent: 0, commissione_cent: 0, totale_cent: 0, segmenti: 0 };
    const t = tot[k]; t.n++; t.importo_cent += b.importo_cent * b.pax; t.adv_fee_cent += b.adv_fee_cent * b.pax; t.commissione_cent += b.commissione_cent * b.pax; t.totale_cent += b.totale_cent; t.segmenti += b.segmenti;
  }
  return { lista, riepilogo: Object.values(tot), anni: db.all('SELECT DISTINCT anno FROM biglietti ORDER BY anno DESC').map(r => r.anno) };
}
function biglietto(ctx) {
  const b = db.get(`SELECT b.*, c.cognome || ' ' || c.nome AS cliente, u.nome AS operatore, p.numero AS pratica_numero, p.anno AS pratica_anno
    FROM biglietti b LEFT JOIN clienti c ON c.id = b.cliente_id LEFT JOIN utenti u ON u.id = b.operatore_id LEFT JOIN pratiche p ON p.id = b.pratica_id WHERE b.id = ?`, N(ctx.query.id));
  if (!b) err(404, 'Biglietto non trovato');
  arricchisciBiglietto(b);
  b.passeggeri = db.all('SELECT * FROM biglietto_passeggeri WHERE biglietto_id = ? ORDER BY ordine, id', b.id);
  b.acconti = db.all(`SELECT a.*, u.nome AS operatore FROM acconti a LEFT JOIN utenti u ON u.id = a.operatore_id WHERE a.biglietto_id = ? AND a.pratica_id = 0 ORDER BY a.data, a.id`, b.id);
  return b;
}
function biglietto_salva(ctx) {
  const c = ctx.corpo || {};
  const id = N(c.id);
  const tipo = S(c.tipo); richiedi(TIPI_BIGLIETTO.includes(tipo), 'Tipo biglietto non valido');
  const operazione = OPERAZIONI_BIGLIETTO.includes(S(c.operazione)) ? S(c.operazione) : 'emissione';
  const clienteId = N(c.cliente_id) || null;
  if (clienteId && !db.get('SELECT id FROM clienti WHERE id = ?', clienteId)) err(400, 'Cliente non trovato');
  const praticaId = N(c.pratica_id) || null;
  if (praticaId && !db.get('SELECT id FROM pratiche WHERE id = ?', praticaId)) err(400, 'Pratica collegata non trovata');
  const passeggeri = (Array.isArray(c.passeggeri) ? c.passeggeri : []).filter(q => S(q.nome));
  const bozza = B(c.bozza);
  if (!bozza) richiedi(clienteId || passeggeri.length || S(c.passeggero), 'Serve il cliente oppure almeno un passeggero');
  if (!passeggeri.length && S(c.passeggero)) passeggeri.push({ nome: S(c.passeggero) });
  const L = db.impostazione('liste');
  let canale = S(c.canale), modalita = S(c.modalita);
  if (tipo === 'aereo') {
    // Canale = il fornitore (Fancy Tour / Only Ticket). La modalità la decide l'ACQUISIZIONE: Carta di credito = già pagato, Cash = resta un debito.
    canale = canale.split('|')[0].trim();
    const acq = S(c.acquisizione).toLowerCase();
    modalita = acq.startsWith('cash') || acq.startsWith('contant') ? 'debito' : 'pagato';
  } else { canale = ''; modalita = ''; }
  const vals = {
    tipo, operazione, data: DATA(c.data) || db.oggi(), cliente_id: clienteId, pratica_id: praticaId,
    passeggero: passeggeri.length ? S(passeggeri[0].nome) + (passeggeri.length > 1 ? ` +${passeggeri.length - 1}` : '') : S(c.passeggero), vettore: S(c.vettore), canale, modalita, acquisizione: S(c.acquisizione),
    codice: S(c.codice).toUpperCase(), itinerario: S(c.itinerario).toUpperCase(), partenza: DATA(c.partenza), segmenti: N(c.segmenti),
    importo_cent: CENT(c.importo), adv_fee_cent: CENT(c.adv_fee), commissione_cent: CENT(c.commissione), note: S(c.note), bozza,
    pax: Math.max(1, N(c.pax) || passeggeri.length || 1)
  };
  const ts = db.adesso();
  return db.transazione(() => {
    let bid = id;
    if (id) {
      const old = db.get('SELECT * FROM biglietti WHERE id = ?', id);
      if (!old) err(404, 'Biglietto non trovato');
      const set = Object.keys(vals).map(k => `${k} = ?`).join(', ');
      db.run(`UPDATE biglietti SET ${set}, aggiornato_ts = ? WHERE id = ?`, ...Object.values(vals), ts, id);
      // se viene collegato a una pratica, gli acconti già registrati sul biglietto passano alla pratica
      if (praticaId && !old.pratica_id) db.run('UPDATE acconti SET pratica_id = ?, biglietto_id = NULL WHERE biglietto_id = ?', praticaId, id);
      db.log(ctx.utente, 'biglietto_modifica', 'biglietto', id, `B${old.numero}/${old.anno} ${vals.itinerario}`);
    } else {
      const anno = N(c.anno) || N(vals.data.slice(0, 4)) || new Date().getFullYear();
      const numero = prossimoNumeroB(anno);
      const cols = Object.keys(vals);
      const r = db.run(`INSERT INTO biglietti(anno, numero, ${cols.join(',')}, operatore_id, creato_ts, aggiornato_ts) VALUES(?,?,${cols.map(() => '?').join(',')},?,?,?)`, anno, numero, ...Object.values(vals), ctx.utente.id, ts, ts);
      bid = Number(r.lastInsertRowid);
      db.log(ctx.utente, 'biglietto_crea', 'biglietto', bid, `B${numero}/${anno} ${tipo} ${vals.itinerario}`);
    }
    if (clienteId && passeggeri.length > 1) aggiornaCompagni(clienteId, passeggeri.slice(1));
    db.run('DELETE FROM biglietto_passeggeri WHERE biglietto_id = ?', bid);
    passeggeri.forEach((q, i) => db.run('INSERT INTO biglietto_passeggeri(biglietto_id, ordine, nome, nato_il, doc_tipo, doc_numero, doc_scade) VALUES(?,?,?,?,?,?,?)', bid, i, S(q.nome), DATA(q.nato_il), S(q.doc_tipo), S(q.doc_numero), DATA(q.doc_scade)));
    if (clienteId) db.run('UPDATE clienti SET ultimo_contatto = ? WHERE id = ?', db.oggi(), clienteId);
    return biglietto({ query: { id: bid } });
  });
}
function biglietto_elimina(ctx) {
  const b = db.get('SELECT * FROM biglietti WHERE id = ?', N(ctx.corpo.id));
  if (!b) err(404, 'Biglietto non trovato');
  const nAcc = db.get('SELECT COUNT(*) AS n FROM acconti WHERE biglietto_id = ?', b.id).n;
  if (nAcc > 0) err(400, 'Il biglietto ha acconti registrati: cancellali prima');
  db.run('DELETE FROM biglietti WHERE id = ?', b.id);
  db.run('DELETE FROM biglietto_passeggeri WHERE biglietto_id = ?', b.id);
  db.log(ctx.utente, 'biglietto_elimina', 'biglietto', b.id, `B${b.numero}/${b.anno} ${b.itinerario}`);
  return { ok: true };
}
// collega / scollega un biglietto esistente a una pratica (dalla scheda pratica)
function biglietto_collega(ctx) {
  const c = ctx.corpo || {};
  const b = db.get('SELECT * FROM biglietti WHERE id = ?', N(c.id));
  if (!b) err(404, 'Biglietto non trovato');
  const pid = N(c.pratica_id) || null;
  if (pid && !db.get('SELECT id FROM pratiche WHERE id = ?', pid)) err(404, 'Pratica non trovata');
  db.transazione(() => {
    db.run('UPDATE biglietti SET pratica_id = ?, aggiornato_ts = ? WHERE id = ?', pid, db.adesso(), b.id);
    if (pid && !b.pratica_id) db.run('UPDATE acconti SET pratica_id = ?, biglietto_id = NULL WHERE biglietto_id = ?', pid, b.id);
  });
  db.log(ctx.utente, pid ? 'biglietto_collega' : 'biglietto_scollega', 'biglietto', b.id, `B${b.numero}/${b.anno} → pratica ${pid || '—'}`);
  return pid ? caricaPratica(pid) : caricaPratica(b.pratica_id);
}
// cerca una pratica per numero (per collegare un biglietto)
function pratiche_cerca(ctx) {
  const q = S(ctx.query.q).toLowerCase();
  const m = q.match(/^(\d+)(?:\/(\d{2,4}))?$/);
  let sql = `SELECT p.id, p.anno, p.numero, p.stato, p.destinazione, p.partenza, c.cognome || ' ' || c.nome AS cliente FROM pratiche p JOIN clienti c ON c.id = p.cliente_id WHERE 1=1`;
  const pr = [];
  if (m) { sql += ' AND p.numero = ?'; pr.push(N(m[1])); if (m[2]) { sql += ' AND p.anno = ?'; pr.push(N(m[2].length === 2 ? '20' + m[2] : m[2])); } }
  else if (q) { sql += ` AND (lower(c.cognome || ' ' || c.nome) LIKE ? OR lower(p.destinazione) LIKE ?)`; pr.push(`%${q}%`, `%${q}%`); }
  sql += ' ORDER BY p.anno DESC, p.numero DESC LIMIT 12';
  return db.all(sql, ...pr);
}

// ---------- scadenze ----------
function scadenze(ctx) {
  const oggi = db.oggi();
  const fra30 = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
  const fra90 = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
  const attive = db.all(`SELECT p.id, p.anno, p.numero, p.stato, p.destinazione, p.partenza, p.rientro, p.saldo_entro, p.cliente_id,
      c.cognome || ' ' || c.nome AS cliente, c.cellulare, c.telefono
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id
    WHERE p.stato IN ('Confermata','Chiusa','Annullata con penale')`).map(arricchisciTotali);
  const saldiBigl = db.all(`SELECT b.*, c.cognome || ' ' || c.nome AS cliente, c.cellulare FROM biglietti b LEFT JOIN clienti c ON c.id = b.cliente_id WHERE b.pratica_id IS NULL`)
    .map(arricchisciBiglietto).filter(b => b.saldo_cent > 0)
    .map(b => ({ id: b.id, biglietto: true, anno: b.anno, numero: 'B' + b.numero, stato: 'Biglietto', destinazione: `${b.vettore} ${b.itinerario}`.trim(), partenza: b.partenza, cliente: b.cliente || b.passeggero, dovuto_cent: b.totale_cent, versato_cent: b.versato_cent, saldo_cent: b.saldo_cent, scadenza: b.partenza || '', scaduto: !!(b.partenza && b.partenza < oggi) }));
  const saldi = [...attive.filter(p => p.saldo_cent > 0).map(p => ({ ...p, scadenza: p.saldo_entro || p.partenza || '', scaduto: !!((p.saldo_entro || p.partenza) && (p.saldo_entro || p.partenza) < oggi) })), ...saldiBigl]
    .sort((a, b) => (a.scadenza || '9999') < (b.scadenza || '9999') ? -1 : 1);
  const partenze = db.all(`SELECT p.id, p.anno, p.numero, p.stato, p.destinazione, p.partenza, p.rientro, c.cognome || ' ' || c.nome AS cliente, c.cellulare
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id
    WHERE p.stato IN ('Confermata') AND p.partenza >= ? AND p.partenza <= ? ORDER BY p.partenza`, oggi, fra30);
  const fornitori = [];
  for (const pr of db.all(`SELECT id FROM pratiche WHERE stato IN ('Confermata','Chiusa') ORDER BY partenza`)) {
    const p = caricaPratica(pr.id);
    for (const f of p.fornitori) if (f.dovuto_cent > 0) fornitori.push({ fornitore_id: f.fornitore_id, biglietto_id: f.biglietto_id, fornitore: f.fornitore, id: p.id, anno: p.anno, numero: p.numero, partenza: p.partenza, destinazione: p.destinazione, cliente: p.cliente, costo_cent: f.costo_cent, pagato_cent: f.pagato_cent, dovuto_cent: f.dovuto_cent });
  }
  const documenti = db.all(`SELECT c.id, c.cognome || ' ' || c.nome AS cliente, d.tipo AS doc_tipo, d.scade AS doc_scade, c.cellulare FROM cliente_documenti d JOIN clienti c ON c.id = d.cliente_id
    WHERE d.scade <> '' AND d.scade <= ? AND c.id IN (SELECT cliente_id FROM pratiche WHERE stato = 'Confermata' AND partenza >= ?) ORDER BY d.scade`, fra90, oggi);
  return { oggi, saldi, partenze, fornitori, documenti };
}

// ---------- statistiche ----------
function filtroVendite() {
  // Valore "venduto" di una pratica: totale righe, oppure la penale se annullata con penale. Escluse annullate e preventivi.
  return `CASE WHEN p.stato = 'Annullata con penale' THEN p.penale_cent
          WHEN p.stato IN ('Confermata','Chiusa') THEN (SELECT COALESCE(SUM(prezzo_cent*pax),0) FROM pratica_righe r WHERE r.pratica_id = p.id) + (SELECT COALESCE(SUM((importo_cent + adv_fee_cent + commissione_cent) * pax),0) FROM biglietti b WHERE b.pratica_id = p.id) - p.abbuono_cent
          ELSE 0 END`;
}
function filtroCosti() {
  return `CASE WHEN p.stato IN ('Confermata','Chiusa') THEN (SELECT COALESCE(SUM(costo_cent*pax),0) FROM pratica_righe r WHERE r.pratica_id = p.id) + (SELECT COALESCE(SUM(importo_cent * pax),0) FROM biglietti b WHERE b.pratica_id = p.id) ELSE 0 END`;
}
function statistiche(ctx) {
  const anno = N(ctx.query.anno) || new Date().getFullYear();
  const V = filtroVendite(), C = filtroCosti();
  const perAnno = db.all(`SELECT p.anno, COUNT(*) AS n,
      SUM(CASE WHEN p.stato IN ('Confermata','Chiusa','Annullata con penale') THEN 1 ELSE 0 END) AS n_valide,
      SUM(${V}) AS venduto_cent, SUM(${C}) AS costo_cent,
      (SELECT COALESCE(SUM(a.importo_cent),0) FROM acconti a JOIN pratiche q ON q.id = a.pratica_id WHERE q.anno = p.anno) AS incassato_cent
    FROM pratiche p GROUP BY p.anno ORDER BY p.anno`);
  const perMese = db.all(`SELECT p.anno, CAST(substr(p.data_apertura,6,2) AS INTEGER) AS mese, SUM(${V}) AS venduto_cent, COUNT(*) AS n
    FROM pratiche p WHERE p.stato IN ('Confermata','Chiusa','Annullata con penale') GROUP BY p.anno, mese ORDER BY p.anno, mese`);
  const perTipo = db.all(`SELECT COALESCE(NULLIF(p.tipo,''),'(senza tipo)') AS tipo, COUNT(*) AS n, SUM(${V}) AS venduto_cent
    FROM pratiche p WHERE p.anno = ? AND p.stato IN ('Confermata','Chiusa','Annullata con penale') GROUP BY tipo ORDER BY venduto_cent DESC`, anno);
  const perFornitore = db.all(`SELECT f.id, f.nome,
      SUM(CASE WHEN p.anno = ? THEN (r.prezzo_cent + COALESCE((SELECT SUM(c.prezzo_cent) FROM pratica_righe c WHERE c.padre_id = r.id),0))*r.pax ELSE 0 END) AS venduto_cent,
      SUM(CASE WHEN p.anno = ? THEN r.costo_cent*r.pax ELSE 0 END) AS costo_cent,
      COUNT(DISTINCT CASE WHEN p.anno = ? THEN p.id END) AS n,
      SUM(CASE WHEN p.anno = ? THEN (r.prezzo_cent + COALESCE((SELECT SUM(c.prezzo_cent) FROM pratica_righe c WHERE c.padre_id = r.id),0))*r.pax ELSE 0 END) AS venduto_prec_cent,
      COUNT(DISTINCT CASE WHEN p.anno = ? THEN p.id END) AS n_prec
    FROM pratica_righe r JOIN pratiche p ON p.id = r.pratica_id JOIN fornitori f ON f.id = r.fornitore_id
    WHERE p.stato IN ('Confermata','Chiusa') AND p.anno IN (?, ?) GROUP BY f.id ORDER BY venduto_cent DESC`, anno, anno, anno, anno - 1, anno - 1, anno, anno - 1);
  const perOperatore = db.all(`SELECT COALESCE(u.nome, '(nessuno)') AS operatore, COUNT(*) AS n, SUM(${V}) AS venduto_cent, SUM(${V}) - SUM(${C}) AS margine_cent
    FROM pratiche p LEFT JOIN utenti u ON u.id = p.operatore_id WHERE p.anno = ? AND p.stato IN ('Confermata','Chiusa','Annullata con penale') GROUP BY p.operatore_id ORDER BY venduto_cent DESC`, anno);
  const topClienti = db.all(`SELECT c.id, c.cognome || ' ' || c.nome AS cliente, COUNT(*) AS n, SUM(${V}) AS venduto_cent
    FROM pratiche p JOIN clienti c ON c.id = p.cliente_id WHERE p.anno = ? AND p.stato IN ('Confermata','Chiusa','Annullata con penale') GROUP BY c.id ORDER BY venduto_cent DESC LIMIT 15`, anno);
  const perStato = db.all(`SELECT p.stato, COUNT(*) AS n FROM pratiche p WHERE p.anno = ? GROUP BY p.stato`, anno);
  const daIncassare = db.all(`SELECT p.id FROM pratiche p WHERE p.anno = ? AND p.stato IN ('Confermata','Chiusa','Annullata con penale')`, anno)
    .map(arricchisciTotali).reduce((s, p) => s + Math.max(0, p.saldo_cent), 0);
  const daPagare = db.all(`SELECT id FROM pratiche WHERE anno = ? AND stato IN ('Confermata','Chiusa')`, anno).reduce((s, r) => s + caricaPratica(r.id).fornitori.reduce((t, f) => t + Math.max(0, f.dovuto_cent), 0), 0);
  return { anno, anni: pratiche_anni(), perAnno, perMese, perTipo, perFornitore, perOperatore, topClienti, perStato, da_incassare_cent: daIncassare, da_pagare_fornitori_cent: daPagare };
}
function statisticheFornitore(fid) {
  return db.all(`SELECT p.anno, COUNT(DISTINCT p.id) AS n, SUM((r.prezzo_cent + COALESCE((SELECT SUM(c.prezzo_cent) FROM pratica_righe c WHERE c.padre_id = r.id),0))*r.pax) AS venduto_cent, SUM(r.costo_cent*r.pax) AS costo_cent,
      0 AS pagato_cent
    FROM pratica_righe r JOIN pratiche p ON p.id = r.pratica_id WHERE r.fornitore_id = ? AND p.stato IN ('Confermata','Chiusa') GROUP BY p.anno ORDER BY p.anno`, fid).map(r => {
      // pagato nell'anno = quote coperte dagli acconti, pratica per pratica
      r.pagato_cent = db.all(`SELECT DISTINCT p.id FROM pratiche p JOIN pratica_righe x ON x.pratica_id = p.id WHERE x.fornitore_id = ? AND p.anno = ? AND p.stato IN ('Confermata','Chiusa')`, fid, r.anno)
        .reduce((s, q) => { const f = caricaPratica(q.id).fornitori.find(y => y.fornitore_id === fid); return s + (f ? f.pagato_cent : 0); }, 0);
      return r; });
}
function statisticheCliente(cid) {
  const V = filtroVendite();
  const perAnno = db.all(`SELECT p.anno, COUNT(*) AS n, SUM(${V}) AS venduto_cent,
      (SELECT COALESCE(SUM(a.importo_cent),0) FROM acconti a JOIN pratiche q ON q.id = a.pratica_id WHERE q.cliente_id = ? AND q.anno = p.anno) AS versato_cent
    FROM pratiche p WHERE p.cliente_id = ? AND p.stato IN ('Confermata','Chiusa','Annullata con penale') GROUP BY p.anno ORDER BY p.anno`, cid, cid);
  const tot = perAnno.reduce((s, r) => ({ n: s.n + r.n, venduto_cent: s.venduto_cent + r.venduto_cent, versato_cent: s.versato_cent + r.versato_cent }), { n: 0, venduto_cent: 0, versato_cent: 0 });
  const dest = db.all(`SELECT p.destinazione, p.partenza FROM pratiche p WHERE p.cliente_id = ? AND p.stato IN ('Confermata','Chiusa') ORDER BY p.partenza DESC LIMIT 10`, cid);
  return { perAnno, totali: tot, destinazioni: dest };
}

// ---------- tabella delle azioni ----------
// pubbliche: senza login. Le altre richiedono l'utente collegato.
const AZIONI = {
  stato_installazione: { fn: stato_installazione, pubblica: true },
  installa: { fn: installa, pubblica: true, post: true },
  login: { fn: login, pubblica: true, post: true },
  logout: { fn: logout, post: true },
  me: { fn: me },
  cambia_password: { fn: cambia_password, post: true },
  pannello_sblocca: { fn: pannello_sblocca, post: true },
  pannello_blocca: { fn: pannello_blocca, post: true },
  pannello_verifica: { fn: pannello_verifica, post: true },
  pagina_sblocca: { fn: pagina_sblocca, post: true },
  pannello_password: { fn: pannello_password, post: true },
  impostazioni: { fn: impostazioni },
  impostazioni_salva: { fn: impostazioni_salva, post: true },
  utenti: { fn: utenti },
  utenti_salva: { fn: utenti_salva, post: true },
  backup_ora: { fn: backup_ora, post: true },
  log: { fn: log_operazioni },
  clienti: { fn: clienti },
  cliente: { fn: cliente },
  cliente_salva: { fn: cliente_salva, post: true },
  cliente_elimina: { fn: cliente_elimina, post: true },
  tessere_tipi: { fn: tessere_tipi },
  passeggeri_cerca: { fn: passeggeri_cerca },
  liste_salva: { fn: liste_salva, post: true },
  biglietti: { fn: biglietti },
  biglietto: { fn: biglietto },
  biglietto_salva: { fn: biglietto_salva, post: true },
  biglietto_elimina: { fn: biglietto_elimina, post: true },
  biglietto_collega: { fn: biglietto_collega, post: true },
  prossimo_numero_b: { fn: prossimo_numero_b },
  pratiche_cerca: { fn: pratiche_cerca },
  fornitori: { fn: fornitori },
  fornitore: { fn: fornitore },
  fornitore_salva: { fn: fornitore_salva, post: true },
  fornitore_elimina: { fn: fornitore_elimina, post: true },
  pratiche: { fn: pratiche },
  pratiche_anni: { fn: pratiche_anni },
  prossimo_numero: { fn: prossimo_numero },
  esporta_db: { fn: esporta_db },
  importa_db: { fn: importa_db, post: true, raw: true },
  importa_db_iniziale: { fn: importa_db_iniziale, post: true, raw: true, pubblica: true },
  pratica: { fn: pratica },
  pratica_salva: { fn: pratica_salva, post: true },
  pratica_elimina: { fn: pratica_elimina, post: true },
  acconto_salva: { fn: acconto_salva, post: true },
  acconto_elimina: { fn: acconto_elimina, post: true },
  pagamento_fornitore_salva: { fn: pagamento_fornitore_salva, post: true },
  pagamento_fornitore_elimina: { fn: pagamento_fornitore_elimina, post: true },
  scadenze: { fn: scadenze },
  statistiche: { fn: statistiche },
  utenti_lista: { fn: () => listaUtenti() }
};

// Usato all'avvio dal file _dati/RESET-PASSWORD.txt
function resetPassword(chi, nuova) {
  if (String(nuova).length < 4) return `RESET ${chi}: password troppo corta, ignorata`;
  if (chi.toLowerCase() === 'pannello') { db.impostazioneSalva('pannello_pass', hashPassword(nuova)); db.log(null, 'reset_password', 'pannello', null, 'da file RESET-PASSWORD.txt'); return 'RESET: password del pannello cambiata'; }
  const u = db.get('SELECT * FROM utenti WHERE username = ?', chi.toLowerCase());
  if (!u) return `RESET ${chi}: utente non trovato`;
  db.run('UPDATE utenti SET pass_hash = ?, pass_chiaro = ?, attivo = 1 WHERE id = ?', hashPassword(nuova), String(nuova), u.id);
  db.log(null, 'reset_password', 'utente', u.id, 'da file RESET-PASSWORD.txt');
  return `RESET: password di ${u.username} cambiata`;
}

module.exports = { AZIONI, ErroreApi, STATI, resetPassword, VERSIONE, controllaPermesso };
