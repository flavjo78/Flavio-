<?php
/* =========================================================================
   PREDIZIONE — configurazione condivisa
   -------------------------------------------------------------------------
   Questo file e' incluso da image.php, stato.php e carica.php.
   Qui dentro c'e' l'unica cosa che DEVI cambiare a mano: la password.
   ========================================================================= */

/* ---- 1) LA TUA PASSWORD (cambiala!) ------------------------------------
   E' la password che protegge il pannello: serve per avviare/finire il
   gioco e per caricare le foto. Scegline una tua, difficile da indovinare,
   e mettila anche nel pannello quando ti viene chiesta. NON condividerla. */
const PREDIZIONE_PASSWORD = 'CAMBIA_QUESTA_PASSWORD';

/* ---- 2) Fuso orario ----------------------------------------------------- */
date_default_timezone_set('Europe/Rome');

/* ---- 3) Cartella dei dati (foto, stato, log) ---------------------------
   E' una sottocartella protetta: nessuno puo' aprirla dal browser. */
const DATA_DIR = __DIR__ . '/_dati';

/* ---- 4) Log delle aperture (la "cattura") ------------------------------- */
const ABILITA_LOG = true;

/* ========================================================================= */
/* ==============  Da qui in giu' non serve toccare nulla  ================== */
/* ========================================================================= */

const STATO_FILE = DATA_DIR . '/stato.json';
const LOG_FILE   = DATA_DIR . '/aperture.csv';

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
        'fase'            => 'spento',   // spento | avviato | terminato
        'orario_scambio'  => null,       // timestamp (int) di sicurezza, oppure null
        'forza'           => null,       // 'A' | 'B' | null  (scorciatoia test)
        'avvio_ts'        => null,       // quando e' stato premuto Avvia
        'foto_a_file'     => null,       // percorso della Foto A caricata
        'foto_b_file'     => null,       // percorso della Foto B caricata
        'aggiornato'      => null,
    ];
}

function predizione_leggi_stato(): array {
    predizione_prepara_cartella();
    if (!is_file(STATO_FILE)) {
        return predizione_stato_default();
    }
    $raw = @file_get_contents(STATO_FILE);
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

/* Confronto password a tempo costante (evita di rivelarla a tentativi). */
function predizione_password_ok(?string $inserita): bool {
    if (!is_string($inserita) || $inserita === '') return false;
    return hash_equals(PREDIZIONE_PASSWORD, $inserita);
}

/* Conta quante aperture sono state registrate nel log (righe del CSV). */
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
