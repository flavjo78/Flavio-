'use strict';
// Database SQLite (node:sqlite, incorporato in Node >= 22.13): nessuna dipendenza.
const { DatabaseSync, backup } = require('node:sqlite');
const fs = require('node:fs');
const path = require('node:path');

const SCHEMA = `
CREATE TABLE IF NOT EXISTS utenti (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  nome TEXT NOT NULL,
  pass_hash TEXT NOT NULL,
  attivo INTEGER NOT NULL DEFAULT 1,
  creato_ts TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS impostazioni (
  chiave TEXT PRIMARY KEY,
  valore TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS clienti (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cognome TEXT NOT NULL DEFAULT '',
  nome TEXT NOT NULL DEFAULT '',
  azienda TEXT NOT NULL DEFAULT '',
  indirizzo TEXT NOT NULL DEFAULT '',
  cap TEXT NOT NULL DEFAULT '',
  citta TEXT NOT NULL DEFAULT '',
  provincia TEXT NOT NULL DEFAULT '',
  nazione TEXT NOT NULL DEFAULT '',
  sesso TEXT NOT NULL DEFAULT '',
  telefono TEXT NOT NULL DEFAULT '',
  cellulare TEXT NOT NULL DEFAULT '',
  telefono2 TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  nato_il TEXT NOT NULL DEFAULT '',
  nato_a TEXT NOT NULL DEFAULT '',
  cf TEXT NOT NULL DEFAULT '',
  piva TEXT NOT NULL DEFAULT '',
  doc_tipo TEXT NOT NULL DEFAULT '',
  doc_numero TEXT NOT NULL DEFAULT '',
  doc_rilasciato_da TEXT NOT NULL DEFAULT '',
  doc_rilasciato_il TEXT NOT NULL DEFAULT '',
  doc_scade TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  viaggia_con TEXT NOT NULL DEFAULT '',
  ultimo_contatto TEXT NOT NULL DEFAULT '',
  creato_ts TEXT NOT NULL,
  creato_da INTEGER,
  aggiornato_ts TEXT NOT NULL,
  aggiornato_da INTEGER
);
CREATE INDEX IF NOT EXISTS idx_clienti_nome ON clienti(cognome, nome);
CREATE TABLE IF NOT EXISTS fornitori (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  tipo TEXT NOT NULL DEFAULT '',
  indirizzo TEXT NOT NULL DEFAULT '',
  cap TEXT NOT NULL DEFAULT '',
  citta TEXT NOT NULL DEFAULT '',
  provincia TEXT NOT NULL DEFAULT '',
  nazione TEXT NOT NULL DEFAULT '',
  telefono TEXT NOT NULL DEFAULT '',
  fax TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  web TEXT NOT NULL DEFAULT '',
  piva TEXT NOT NULL DEFAULT '',
  cf TEXT NOT NULL DEFAULT '',
  referente TEXT NOT NULL DEFAULT '',
  referente_tel TEXT NOT NULL DEFAULT '',
  referente_email TEXT NOT NULL DEFAULT '',
  provvigione REAL NOT NULL DEFAULT 0,
  pagamento TEXT NOT NULL DEFAULT '',
  iban TEXT NOT NULL DEFAULT '',
  banca TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  attivo INTEGER NOT NULL DEFAULT 1,
  creato_ts TEXT NOT NULL,
  creato_da INTEGER,
  aggiornato_ts TEXT NOT NULL,
  aggiornato_da INTEGER
);
CREATE TABLE IF NOT EXISTS pratiche (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  anno INTEGER NOT NULL,
  numero INTEGER NOT NULL,
  cliente_id INTEGER NOT NULL,
  operatore_id INTEGER,
  tipo TEXT NOT NULL DEFAULT '',
  stato TEXT NOT NULL DEFAULT 'Preventivo',
  data_apertura TEXT NOT NULL,
  intermediazione INTEGER NOT NULL DEFAULT 1,
  pacchetto_servizio TEXT NOT NULL DEFAULT 'pacchetto',
  in_catalogo INTEGER NOT NULL DEFAULT 1,
  catalogo TEXT NOT NULL DEFAULT '',
  catalogo_pag TEXT NOT NULL DEFAULT '',
  riferimento_to TEXT NOT NULL DEFAULT '',
  operatore_to TEXT NOT NULL DEFAULT '',
  destinazione TEXT NOT NULL DEFAULT '',
  partenza TEXT NOT NULL DEFAULT '',
  partenza_da TEXT NOT NULL DEFAULT '',
  partenza_a TEXT NOT NULL DEFAULT '',
  rientro TEXT NOT NULL DEFAULT '',
  rientro_da TEXT NOT NULL DEFAULT '',
  rientro_a TEXT NOT NULL DEFAULT '',
  adulti INTEGER NOT NULL DEFAULT 0,
  bambini INTEGER NOT NULL DEFAULT 0,
  infanti INTEGER NOT NULL DEFAULT 0,
  durata_giorni INTEGER NOT NULL DEFAULT 0,
  durata_notti INTEGER NOT NULL DEFAULT 0,
  pnr TEXT NOT NULL DEFAULT '',
  documenti_necessari TEXT NOT NULL DEFAULT '',
  richieste TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  abbuono_cent INTEGER NOT NULL DEFAULT 0,
  saldo_entro TEXT NOT NULL DEFAULT '',
  penale_cent INTEGER NOT NULL DEFAULT 0,
  creato_ts TEXT NOT NULL,
  aggiornato_ts TEXT NOT NULL,
  aggiornato_da INTEGER,
  UNIQUE(anno, numero)
);
CREATE INDEX IF NOT EXISTS idx_pratiche_cliente ON pratiche(cliente_id);
CREATE INDEX IF NOT EXISTS idx_pratiche_partenza ON pratiche(partenza);
CREATE TABLE IF NOT EXISTS pratica_righe (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pratica_id INTEGER NOT NULL,
  ordine INTEGER NOT NULL DEFAULT 0,
  descrizione TEXT NOT NULL DEFAULT '',
  fornitore_id INTEGER,
  prezzo_cent INTEGER NOT NULL DEFAULT 0,
  pax INTEGER NOT NULL DEFAULT 1,
  costo_cent INTEGER NOT NULL DEFAULT 0,
  stampa INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_righe_pratica ON pratica_righe(pratica_id);
CREATE INDEX IF NOT EXISTS idx_righe_fornitore ON pratica_righe(fornitore_id);
CREATE TABLE IF NOT EXISTS pratica_partecipanti (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pratica_id INTEGER NOT NULL,
  ordine INTEGER NOT NULL DEFAULT 0,
  nome TEXT NOT NULL DEFAULT '',
  nato_il TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_part_pratica ON pratica_partecipanti(pratica_id);
CREATE TABLE IF NOT EXISTS acconti (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pratica_id INTEGER NOT NULL,
  data TEXT NOT NULL,
  importo_cent INTEGER NOT NULL,
  metodo TEXT NOT NULL DEFAULT '',
  descrizione TEXT NOT NULL DEFAULT '',
  fornitore_id INTEGER,
  operatore_id INTEGER,
  creato_ts TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_acconti_pratica ON acconti(pratica_id);
CREATE TABLE IF NOT EXISTS pagamenti_fornitore (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  pratica_id INTEGER NOT NULL,
  fornitore_id INTEGER NOT NULL,
  data TEXT NOT NULL,
  importo_cent INTEGER NOT NULL,
  metodo TEXT NOT NULL DEFAULT '',
  descrizione TEXT NOT NULL DEFAULT '',
  operatore_id INTEGER,
  creato_ts TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pagf_pratica ON pagamenti_fornitore(pratica_id);
CREATE INDEX IF NOT EXISTS idx_pagf_fornitore ON pagamenti_fornitore(fornitore_id);
CREATE TABLE IF NOT EXISTS log_operazioni (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL,
  utente_id INTEGER,
  utente TEXT NOT NULL DEFAULT '',
  azione TEXT NOT NULL,
  entita TEXT NOT NULL DEFAULT '',
  entita_id INTEGER,
  dettaglio TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS sessioni (
  sid TEXT PRIMARY KEY,
  utente_id INTEGER NOT NULL,
  pannello INTEGER NOT NULL DEFAULT 0,
  creato_ts TEXT NOT NULL,
  ultimo_ts TEXT NOT NULL
);
`;

// Valori di fabbrica delle impostazioni (modificabili dal pannello opzioni).
const IMPOSTAZIONI_DEFAULT = {
  agenzia: {
    insegna: 'Agenzia Viaggi',
    ragione_sociale: '',
    titolare: '',
    indirizzo: '',
    cap: '', citta: '', provincia: '',
    piva: '', cf: '',
    telefono: '', email: '', web: '',
    sede_legale: '',
    iban: '',
    logo: ''   // immagine (data URL) facoltativa per la stampa
  },
  numerazione: { anno_iniziale: new Date().getFullYear(), numero_iniziale: 1 },
  backup: { cartella: '', ora: '20:00', ultimo: '' },
  liste: {
    tipi_pratica: ['Villaggio', 'Soggiorno', 'Tour', 'Crociera', 'Volo + hotel', 'Biglietteria', 'Altro'],
    tipi_fornitore: ['Tour Operator', 'Hotel', 'Assicurazione', 'Compagnia aerea', 'Autonoleggio', 'Navale', 'Trasporti', 'Ferrovie', 'Guide', 'Ristorante', 'Corrispondenti', 'Servizi vari'],
    metodi_pagamento: ['Contanti', 'Bonifico', 'Carta / POS', 'Assegno', 'Altro'],
    documenti_necessari: ["Carta d'identità", "Carta d'identità valida per l'espatrio", 'Passaporto', 'Passaporto + visto'],
    descrizioni_servizi: ['Quota pacchetto', 'Quota soggiorno', 'Quota volo', 'Tasse aeroportuali', 'Assicurazione', 'Transfer', 'Noleggio auto', 'Escursione', 'ADV fee', "Diritti d'agenzia"],
    tipi_documento_cliente: ["Carta d'identità", 'Passaporto', 'Patente']
  },
  testi: {
    titolo: 'PROPOSTA DI COMPRAVENDITA DI PACCHETTO/SERVIZIO TURISTICO',
    sottotitolo: "Modulo da utilizzare per adempiere alle disposizioni dell'art. 35 del Codice del Turismo.",
    diritto_copia: 'Il cliente ha diritto di ricevere copia del presente contratto di compravendita di pacchetto/servizio turistico.',
    in_nome: 'in nome e per conto proprio, oltre che in nome e per conto delle persone di seguito elencate:',
    dichiara: [
      "di aver ricevuto per iscritto tutte le informazioni di cui all'art. 37 comma 1 del Codice del Turismo e di impegnarsi a verificarne l'attualità prima della partenza;",
      "di essere a conoscenza delle indicazioni ufficiali di carattere generale - ivi comprese quelle relative alla situazione di sicurezza, anche sanitaria - fornite dal Ministero Affari Esteri tramite il sito www.viaggiaresicuri.it e la Centrale Operativa Telefonica al numero 06 491115;",
      "di essere a conoscenza che le informazioni di cui all'articolo 37 comma 2 del Codice del Turismo verranno fornite prima dell'inizio del viaggio;",
      "di avere ricevuto copia e preso visione del catalogo, ovvero del programma fuori catalogo o viaggio su misura, relativo al pacchetto o servizio proposto;",
      "di conoscere ed accettare espressamente le condizioni generali, la scheda tecnica e le penali riportate nel catalogo, depliant, opuscolo, sito web dell'organizzatore, programma da fuori catalogo o viaggio su misura;",
      "di riconoscere che il contratto si intenderà concluso per effetto dell'accettazione da parte dell'organizzatore della presente proposta"
    ].join('\n'),
    propone: "all'Organizzatore / Intermediario",
    stipula: 'la stipulazione del contratto avente ad oggetto il seguente:',
    validita: 'La presente proposta è formulata dal cliente in modo irrevocabile ed impegnativo fino alla data del ..................... Laddove la data non sia indicata, la presente proposta è intesa essere irrevocabile ed impegnativa per un massimo di 7 giorni a partire dalla data odierna.',
    firma: 'Firma del proponente',
    firma_nota: 'in nome proprio oltre che in nome e per conto delle persone sopra elencate (per i minorenni è necessaria la firma di chi esercita la potestà genitoriale)',
    privacy: "Informativa ai sensi degli artt. 13 e 14 del Regolamento UE 2016/679 (GDPR): i dati personali del sottoscrittore e degli altri partecipanti, il cui conferimento è necessario per garantire agli stessi la fruizione dei servizi oggetto del pacchetto/servizio turistico acquistato, saranno trattati in forma manuale e/o elettronica nel rispetto della normativa. L'eventuale rifiuto nel conferimento dei dati comporta l'impossibilità di concludere il contratto e fornire i relativi servizi. Titolari del trattamento sono - salvo separate e diverse indicazioni, eventualmente visibili anche sul catalogo e/o nei rispettivi siti web - l'Agenzia venditrice e l'Organizzatore. Laddove necessaria, la comunicazione dei dati sarà effettuata solo verso autorità competenti, assicurazioni, corrispondenti o preposti locali del venditore o dell'organizzatore, fornitori dei servizi parte del pacchetto turistico o comunque a soggetti per i quali la trasmissione dei dati sia necessaria in relazione alla conclusione del contratto e fruizione dei relativi servizi. I dati potranno inoltre essere comunicati a consulenti fiscali, contabili e legali per l'assolvimento degli obblighi di legge e/o per l'esercizio dei diritti in sede legale. L'interessato può esercitare in ogni momento i diritti di cui agli artt. 15-22 del GDPR (accesso, rettifica, cancellazione, limitazione, portabilità, opposizione) rivolgendosi al Titolare ai recapiti indicati in intestazione."
  }
};

let db = null;
let DIR_DATI = null;

function apri(dirDati) {
  DIR_DATI = dirDati;
  fs.mkdirSync(dirDati, { recursive: true });
  db = new DatabaseSync(path.join(dirDati, 'agenzia.db'));
  db.exec('PRAGMA journal_mode = WAL');
  db.exec('PRAGMA foreign_keys = ON');
  db.exec('PRAGMA busy_timeout = 5000');
  db.exec(SCHEMA);
  migra();
  return db;
}

function migra() {
  // Spazio per le migrazioni future (PRAGMA user_version).
  const v = db.prepare('PRAGMA user_version').get().user_version;
  if (v < 1) {
    db.exec('PRAGMA user_version = 1');
  }
}

function adesso() { return new Date().toISOString(); }
function oggi() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function get(sql, ...p) { return db.prepare(sql).get(...p); }
function all(sql, ...p) { return db.prepare(sql).all(...p); }
function run(sql, ...p) { return db.prepare(sql).run(...p); }
function transazione(fn) {
  db.exec('BEGIN');
  try { const r = fn(); db.exec('COMMIT'); return r; }
  catch (e) { db.exec('ROLLBACK'); throw e; }
}

// ---- impostazioni (JSON per chiave) ----
function impostazione(chiave) {
  const r = get('SELECT valore FROM impostazioni WHERE chiave = ?', chiave);
  const def = IMPOSTAZIONI_DEFAULT[chiave];
  if (!r) return def === undefined ? null : JSON.parse(JSON.stringify(def));
  const v = JSON.parse(r.valore);
  // Unisce con i default così le chiavi nuove compaiono anche su archivi vecchi.
  if (def && typeof def === 'object' && !Array.isArray(def)) {
    const out = JSON.parse(JSON.stringify(def));
    for (const k of Object.keys(v)) out[k] = v[k];
    return out;
  }
  return v;
}
function impostazioneSalva(chiave, valore) {
  run('INSERT INTO impostazioni(chiave, valore) VALUES(?, ?) ON CONFLICT(chiave) DO UPDATE SET valore = excluded.valore',
    chiave, JSON.stringify(valore));
}
function tutteImpostazioni() {
  const out = {};
  for (const k of Object.keys(IMPOSTAZIONI_DEFAULT)) out[k] = impostazione(k);
  return out;
}

// ---- backup: copia coerente del database in una cartella ----
async function eseguiBackup(cartella) {
  fs.mkdirSync(cartella, { recursive: true });
  const d = new Date();
  const nome = `agenzia-${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}-${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}.db`;
  const dest = path.join(cartella, nome);
  await backup(db, dest);
  // Tiene al massimo gli ultimi 30 backup nella cartella.
  const vecchi = fs.readdirSync(cartella).filter(f => /^agenzia-\d{8}-\d{4}\.db$/.test(f)).sort();
  while (vecchi.length > 30) fs.unlinkSync(path.join(cartella, vecchi.shift()));
  const b = impostazione('backup');
  b.ultimo = adesso();
  impostazioneSalva('backup', b);
  return dest;
}

function log(utente, azione, entita, entitaId, dettaglio) {
  run('INSERT INTO log_operazioni(ts, utente_id, utente, azione, entita, entita_id, dettaglio) VALUES(?,?,?,?,?,?,?)',
    adesso(), utente ? utente.id : null, utente ? utente.username : '', azione, entita || '', entitaId || null,
    typeof dettaglio === 'string' ? dettaglio : JSON.stringify(dettaglio || ''));
}

module.exports = {
  apri, get, all, run, transazione, adesso, oggi,
  impostazione, impostazioneSalva, tutteImpostazioni, IMPOSTAZIONI_DEFAULT,
  eseguiBackup, log,
  dirDati: () => DIR_DATI
};
