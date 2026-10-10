<?php
/* =========================================================================
   PREDIZIONE — configurazione condivisa (MULTI-UTENTE)
   -------------------------------------------------------------------------
   Incluso da image.php, stato.php, carica.php, genera.php, admin.php.

   NOVITA' MULTI-UTENTE
   - Ogni utente ha un NUMERO (001, 002, 003, ...) e una password.
   - L'elenco utenti sta nel "registro" protetto: _dati/utenti.json
     (si crea da solo al primo avvio, con 000 admin + 001/002/003 di prova).
   - I dati di ogni utente stanno in cartelle separate:
       utente 001 -> _dati/           (resta sulla radice, per non toccare il live)
       utente 002 -> _dati/u002/
       utente 003 -> _dati/u003/   ...
   - Le PASSWORD ora vivono nel registro (non piu' qui): questo file e'
     diventato "neutro" e si puo' caricare senza timori.
   ========================================================================= */

/* ---- Fuso orario ------------------------------------------------------- */
date_default_timezone_set('Europe/Rome');

/* ---- Cartella dei dati (radice, condivisa) — protetta ------------------ */
const DATA_DIR = __DIR__ . '/_dati';

/* ---- Registro utenti (dentro _dati, protetto) -------------------------- */
const UTENTI_FILE = DATA_DIR . '/utenti.json';

/* ---- Password admin e utenti PREDEFINITE (solo al primo avvio) --------- */
const PRED_ADMIN_PASS   = '!!!';   // utente 000 (amministratore)
const PRED_DEFAULT_PASS = 'aaa';   // utenti 001/002/003

/* ---- Log delle aperture (la "cattura") --------------------------------- */
const ABILITA_LOG = true;

/* (compatibilita': vecchia costante, non piu' usata per l'accesso) */
const PREDIZIONE_PASSWORD = 'CAMBIA_QUESTA_PASSWORD';

const TIPI_IMG = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/* =========================================================================
   ==============  Da qui in giu' non serve toccare nulla  ================== */

/* -------------------- UTENTE CORRENTE (contesto) ------------------------ */
/* Impostato dagli script d'ingresso (stato/image/carica) in base al
   parametro ricevuto. Determina in quale cartella si leggono/scrivono i dati. */
$GLOBALS['PRED_U'] = '001';

function predizione_pulisci_utente($u): string {
    $u = preg_replace('/[^0-9]/', '', (string)$u);
    $u = substr($u, 0, 6);
    return ($u === '') ? '001' : $u;
}
function predizione_set_utente($u): string {
    $u = predizione_pulisci_utente($u);
    $GLOBALS['PRED_U'] = $u;
    return $u;
}
function predizione_utente(): string {
    return $GLOBALS['PRED_U'] ?? '001';
}

/* Cartella dati dell'utente corrente. 001 resta sulla radice (compatibilita'). */
function predizione_data_dir(): string {
    $u = predizione_utente();
    return ($u === '001') ? DATA_DIR : (DATA_DIR . '/u' . $u);
}
function predizione_stato_file(): string { return predizione_data_dir() . '/stato.json'; }
function predizione_log_file():   string { return predizione_data_dir() . '/aperture.csv'; }

/* Crea la cartella radice e quella dell'utente, e le blinda con .htaccess. */
function predizione_prepara_cartella(): void {
    foreach ([DATA_DIR, predizione_data_dir()] as $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents(
                $ht,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }
    }
}

/* -------------------- REGISTRO UTENTI ----------------------------------- */
function predizione_utenti_default(): array {
    return [
        '000' => ['pass' => PRED_ADMIN_PASS,   'admin' => true,  'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => false], 'mail' => new stdClass()],
        '001' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => false], 'mail' => new stdClass()],
        '002' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => true,  'foto' => false], 'mail' => new stdClass()],
        '003' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => true],  'mail' => new stdClass()],
    ];
}

function predizione_utenti_leggi(): array {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    if (!is_file(UTENTI_FILE)) {
        predizione_prepara_cartella();
        predizione_utenti_scrivi(predizione_utenti_default());
    }
    $raw = @file_get_contents(UTENTI_FILE);
    $dati = json_decode((string)$raw, true);
    return is_array($dati) ? $dati : predizione_utenti_default();
}
function predizione_utenti_scrivi(array $utenti): bool {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    $json = json_encode($utenti, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return @file_put_contents(UTENTI_FILE, $json, LOCK_EX) !== false;
}
function predizione_utente_record(string $u): ?array {
    $utenti = predizione_utenti_leggi();
    return isset($utenti[$u]) && is_array($utenti[$u]) ? $utenti[$u] : null;
}

/* Verifica accesso di un utente (numero + password, e che sia attivo). */
function predizione_auth(string $u, ?string $pass): bool {
    if (!is_string($pass) || $pass === '') return false;
    $rec = predizione_utente_record($u);
    if (!$rec || empty($rec['attivo'])) return false;
    return hash_equals((string)($rec['pass'] ?? ''), $pass);
}
/* Verifica che l'utente sia l'AMMINISTRATORE. */
function predizione_is_admin(string $u, ?string $pass): bool {
    $rec = predizione_utente_record($u);
    return $rec && !empty($rec['admin']) && predizione_auth($u, $pass);
}
/* Interruttori (flags) dell'utente, con valori di default. */
function predizione_flags(string $u): array {
    $rec = predizione_utente_record($u);
    $f = ($rec && isset($rec['flags']) && is_array($rec['flags'])) ? $rec['flags'] : [];
    return ['mail' => !empty($f['mail']), 'foto' => !empty($f['foto'])];
}

/* -------------------- ASSISTENTE DI SCENA (link temporaneo) ------------- */
/* Il performer crea un "lasciapassare" (token) salvato nello stato dell'utente.
   Vale finche' e' presente: lo si cancella alla fine della sessione ("Finisci")
   o con la revoca. Permette i comandi del gioco e la preparazione della
   rivelazione SENZA password e SENZA accedere a impostazioni o report. */
function predizione_assistente_valido(array $stato, ?string $token): bool {
    $t = (string)($stato['assistente_token'] ?? '');
    return $t !== '' && is_string($token) && $token !== '' && hash_equals($t, $token);
}

/* -------------------- STATO DEL GIOCO (per-utente) ---------------------- */
function predizione_stato_default(): array {
    return [
        'foto_a'             => null,
        'rivelazione_pronta' => null,
        'sessione_corrente'  => 0,
        'ultimo_id'          => 0,
        'forza'              => null,
        'autorisponditore'   => false,
        'sessioni'           => [],
        'aggiornato'         => null,
    ];
}

function predizione_leggi_stato(): array {
    predizione_prepara_cartella();
    $file = predizione_stato_file();
    if (!is_file($file)) {
        return predizione_stato_default();
    }
    $raw  = @file_get_contents($file);
    $dati = json_decode((string)$raw, true);
    if (!is_array($dati)) {
        return predizione_stato_default();
    }
    return array_merge(predizione_stato_default(), $dati);
}

function predizione_scrivi_stato(array $stato): bool {
    predizione_prepara_cartella();
    $stato['aggiornato'] = date('c');
    $json = json_encode($stato, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return @file_put_contents(predizione_stato_file(), $json, LOCK_EX) !== false;
}

function predizione_sessione_corrente(array $stato): ?array {
    $id = (int)($stato['sessione_corrente'] ?? 0);
    if ($id <= 0) return null;
    $s = $stato['sessioni'][(string)$id] ?? null;
    return is_array($s) ? $s : null;
}

/* Confronto password a tempo costante (compatibilita'; non piu' usato per l'accesso). */
function predizione_password_ok(?string $inserita): bool {
    if (!is_string($inserita) || $inserita === '') return false;
    return hash_equals(PREDIZIONE_PASSWORD, $inserita);
}

/* Conta le aperture registrate nel log dell'utente corrente (righe del CSV). */
function predizione_conta_aperture(): int {
    $file = predizione_log_file();
    if (!is_file($file)) return 0;
    $n = 0;
    $fh = @fopen($file, 'r');
    if (!$fh) return 0;
    while (($line = fgets($fh)) !== false) {
        if (trim($line) !== '') $n++;
    }
    fclose($fh);
    return $n;
}

/* Risposta JSON breve e uscita. */
function predizione_json($dati, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dati, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
