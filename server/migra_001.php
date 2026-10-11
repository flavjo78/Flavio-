<?php
/* =========================================================================
   PREDIZIONE — MIGRAZIONE utente 001 nella sua cartella _dati/u001/
   -------------------------------------------------------------------------
   Sposta SOLO i file di 001 (stato, log, foto, sessioni) dalla radice _dati/
   dentro _dati/u001/, e RISCRIVE i percorsi dentro stato.json. I file
   condivisi (utenti.json, ordini.json, vendite.csv, ricevute/, u002, u003...)
   restano dove sono.

   SICUREZZA:
   - Fa un BACKUP di stato.json prima di toccare nulla.
   - Di default e' una PROVA A VUOTO (mostra cosa farebbe, non muove niente).
     Per eseguire davvero: aggiungere &conferma=1
   - Le foto B gia' viste restano in cache sui client; i nuovi indirizzi senza
     ?u continuano a funzionare perche' image.php usa 001 -> _dati/u001/.

   USO (dal browser, da amministratore):
     migra_001.php?u=000&pass=LA_TUA_PASSWORD_ADMIN            (prova a vuoto)
     migra_001.php?u=000&pass=LA_TUA_PASSWORD_ADMIN&conferma=1 (esegue)
   ========================================================================= */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

/* --- solo amministratore --------------------------------------------- */
$au   = predizione_pulisci_utente($_GET['u'] ?? ($_POST['u'] ?? '000'));
$pass = $_GET['pass'] ?? ($_POST['pass'] ?? '');
if (!predizione_is_admin($au, $pass)) {
    http_response_code(403);
    echo "Accesso negato: serve l'amministratore (000) con la password.\n";
    exit;
}

$conferma = (($_GET['conferma'] ?? $_POST['conferma'] ?? '') === '1');
$root = DATA_DIR;
$dest = DATA_DIR . '/u001';

echo "== MIGRAZIONE 001 -> u001 ==\n";
echo $conferma ? "MODALITA': ESECUZIONE\n\n" : "MODALITA': PROVA A VUOTO (niente verra' spostato)\n\n";

/* gia' migrato? */
if (is_dir($dest) && is_file($dest . '/stato.json')) {
    echo "Sembra gia' migrato: esiste $dest/stato.json. Niente da fare.\n";
    exit;
}

/* File che APPARTENGONO a 001 (gli altri restano sulla radice). */
function migra_appartiene_a_001(string $nome): bool {
    if ($nome === 'stato.json' || $nome === 'aperture.csv') return true;
    foreach (['foto_a.', 'rivelazione_pronta.', 'sess_', 'mail_sess_'] as $pre) {
        if (strncmp($nome, $pre, strlen($pre)) === 0) return true;
    }
    return false;
}

/* --- elenca cosa spostare -------------------------------------------- */
$daSpostare = [];
foreach (scandir($root) ?: [] as $nome) {
    if ($nome === '.' || $nome === '..') continue;
    $full = $root . '/' . $nome;
    if (is_dir($full)) continue;                 // niente sottocartelle (u002, ricevute, ...)
    if (migra_appartiene_a_001($nome)) $daSpostare[] = $nome;
}

echo "File di 001 trovati: " . count($daSpostare) . "\n";
foreach ($daSpostare as $n) echo "  - $n\n";
echo "\n";

if (!$daSpostare) { echo "Niente da spostare (001 non ha ancora dati sulla radice).\n"; exit; }

if (!$conferma) {
    echo "Percorsi che verrebbero riscritti in stato.json: da \"$root/...\" a \"$dest/...\".\n";
    echo "\nPer ESEGUIRE davvero aggiungi &conferma=1 all'indirizzo.\n";
    exit;
}

/* ====================== ESECUZIONE =================================== */

/* 1) backup di stato.json */
if (is_file($root . '/stato.json')) {
    $bk = $root . '/stato.backup.' . date('Ymd_His') . '.json';
    @copy($root . '/stato.json', $bk);
    echo "Backup stato: " . basename($bk) . "\n";
}

/* 2) crea cartella u001 (con .htaccess) */
if (!is_dir($dest)) @mkdir($dest, 0775, true);
$ht = $dest . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht,
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

/* 3) sposta i file */
$mossi = 0;
foreach ($daSpostare as $n) {
    if (@rename($root . '/' . $n, $dest . '/' . $n)) { $mossi++; }
    else { echo "ATTENZIONE: non sono riuscito a spostare $n\n"; }
}
echo "File spostati: $mossi / " . count($daSpostare) . "\n";

/* 4) riscrivi i percorsi dentro stato.json (ora in u001) */
$sf = $dest . '/stato.json';
$stato = json_decode((string)@file_get_contents($sf), true);
if (is_array($stato)) {
    $riscrivi = function ($p) use ($root, $dest) {
        if (!is_string($p) || $p === '') return $p;
        $pref = $root . '/';
        // gia' sotto u001? lascia stare
        if (strncmp($p, $dest . '/', strlen($dest . '/')) === 0) return $p;
        if (strncmp($p, $pref, strlen($pref)) === 0) {
            return $dest . '/' . substr($p, strlen($pref));
        }
        return $p;
    };
    $stato['foto_a']             = $riscrivi($stato['foto_a'] ?? null);
    $stato['rivelazione_pronta'] = $riscrivi($stato['rivelazione_pronta'] ?? null);
    if (!empty($stato['sessioni']) && is_array($stato['sessioni'])) {
        foreach ($stato['sessioni'] as $k => $s) {
            if (!is_array($s)) continue;
            if (isset($s['before'])) $stato['sessioni'][$k]['before'] = $riscrivi($s['before']);
            if (isset($s['after']))  $stato['sessioni'][$k]['after']  = $riscrivi($s['after']);
        }
    }
    @file_put_contents($sf, json_encode($stato, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    echo "Percorsi riscritti dentro u001/stato.json.\n";
} else {
    echo "ATTENZIONE: stato.json non leggibile dopo lo spostamento.\n";
}

echo "\nFATTO. Ora apri un VECCHIO indirizzo di prova (image.php?s=NUMERO&id=...) e\n";
echo "controlla che la foto B si veda ancora. Da questo momento 001 lavora in _dati/u001/.\n";
