<?php
/* =========================================================================
   PREDIZIONE — configurazione condivisa
   -------------------------------------------------------------------------
   Incluso da image.php, stato.php e carica.php.
   L'unica cosa da cambiare a mano e' la PASSWORD (qui sotto).

   MODELLO A SESSIONI
   - Foto A (neutra): caricata una volta, resta in memoria, riusata sempre.
     La puoi ricaricare quando vuoi cambiarla.
   - Rivelazione: diversa ogni sessione. La carichi prima di avviare.
   - "Avvia" crea una NUOVA sessione con (Foto A neutra + quella rivelazione).
   - Ogni sessione conserva le SUE foto: chi ha ricevuto la rivelazione di una
     sessione continua a vederla per sempre, anche quando ne avvii altre.
   ========================================================================= */

/* ---- 1) LA TUA PASSWORD (cambiala!) ------------------------------------ */
const PREDIZIONE_PASSWORD = 'CAMBIA_QUESTA_PASSWORD';

/* ---- 2) Fuso orario ----------------------------------------------------- */
date_default_timezone_set('Europe/Rome');

/* ---- 3) Cartella dei dati (foto, stato, log) — protetta ----------------- */
const DATA_DIR = __DIR__ . '/_dati';

/* ---- 4) Log delle aperture (la "cattura") ------------------------------- */
const ABILITA_LOG = true;

/* ========================================================================= */
/* ==============  Da qui in giu' non serve toccare nulla  ================== */
/* ========================================================================= */

const STATO_FILE = DATA_DIR . '/stato.json';
const LOG_FILE   = DATA_DIR . '/aperture.csv';

const TIPI_IMG = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/* Crea la cartella dati se manca, e la blinda con un .htaccess. */
function predizione_prepara_cartella(): void {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
        );
    }
}

/* Stato di partenza quando il file non esiste ancora. */
function predizione_stato_default(): array {
    return [
        'foto_a'             => null,   // Foto A neutra (persistente, riusata)
        'rivelazione_pronta' => null,   // rivelazione in attesa per la PROSSIMA sessione
        'sessione_corrente'  => 0,      // 0 = nessuna sessione attiva
        'ultimo_id'          => 0,      // ultimo numero di sessione assegnato
        'forza'              => null,   // 'A' | 'B' | null  (scorciatoia test)
        'sessioni'           => [],     // { "1": {fase, before, after, orario_scambio, avvio_ts}, ... }
        'aggiornato'         => null,
    ];
}

function predizione_leggi_stato(): array {
    predizione_prepara_cartella();
    if (!is_file(STATO_FILE)) {
        return predizione_stato_default();
    }
    $raw  = @file_get_contents(STATO_FILE);
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
    return @file_put_contents(STATO_FILE, $json, LOCK_EX) !== false;
}

/* Ritorna la sessione attiva/corrente, oppure null. */
function predizione_sessione_corrente(array $stato): ?array {
    $id = (int)($stato['sessione_corrente'] ?? 0);
    if ($id <= 0) return null;
    $s = $stato['sessioni'][(string)$id] ?? null;
    return is_array($s) ? $s : null;
}

/* Confronto password a tempo costante. */
function predizione_password_ok(?string $inserita): bool {
    if (!is_string($inserita) || $inserita === '') return false;
    return hash_equals(PREDIZIONE_PASSWORD, $inserita);
}

/* Conta le aperture registrate nel log (righe del CSV). */
function predizione_conta_aperture(): int {
    if (!is_file(LOG_FILE)) return 0;
    $n = 0;
    $fh = @fopen(LOG_FILE, 'r');
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
