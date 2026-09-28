<?php
/* =========================================================================
   PREDIZIONE — immagine dinamica
   -------------------------------------------------------------------------
   Questo script decide QUALE foto mostrare nel momento in cui lo spettatore
   apre la mail: prima dell'orario dello scambio mostra la Foto A, dopo mostra
   la Foto B. La foto viene ricostruita a ogni apertura, quindi non e' "dentro"
   la mail: e' qui.

   COSA DEVI FARE:
   1) Carica questo file e le due foto nella stessa cartella del tuo hosting.
   2) Rinomina le foto in  foto_a.jpg  (prima)  e  foto_b.jpg  (dopo).
   3) Imposta qui sotto data e ora dello scambio.
   Nella mail metti come immagine il link a questo file (vedi ISTRUZIONI).
   ========================================================================= */

/* ============================ IMPOSTAZIONI ============================== */

date_default_timezone_set('Europe/Rome');          // fuso orario dello show

$ORARIO_SCAMBIO = '2026-09-27 21:00:00';           // <-- data e ora del cambio (A -> B)

$FOTO_A = __DIR__ . '/foto_a.jpg';                 // mostrata PRIMA dell'orario
$FOTO_B = __DIR__ . '/foto_b.jpg';                 // mostrata DOPO  l'orario

$ABILITA_LOG = true;                               // registra le aperture (cattura)
$LOG         = __DIR__ . '/aperture.csv';          // file dove salva il log

/* ------------------------------------------------------------------------
   OPZIONALE — forzatura manuale.
   Se crei nella stessa cartella un file chiamato  forza.txt  contenente
   solo la lettera  A  oppure  B , lo scambio segue quello e IGNORA l'orario.
   Cancella il file per tornare alla modalita' automatica a orario.
   ------------------------------------------------------------------------ */
$FILE_FORZA = __DIR__ . '/forza.txt';

/* ========================================================================= */


/* --- identificativo dello spettatore (solo per il log), ripulito --------- */
$id = isset($_GET['id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', $_GET['id']) : '';
$id = substr($id, 0, 40);

/* --- decide A o B -------------------------------------------------------- */
$scelta = null;

if (is_file($FILE_FORZA)) {                         // 1) forzatura manuale
    $f = strtoupper(trim(@file_get_contents($FILE_FORZA)));
    if ($f === 'A' || $f === 'B') { $scelta = $f; }
}
if ($scelta === null) {                             // 2) automatico a orario
    $scelta = (time() < strtotime($ORARIO_SCAMBIO)) ? 'A' : 'B';
}

$foto = ($scelta === 'A') ? $FOTO_A : $FOTO_B;

/* --- log dell'apertura (la "cattura") ------------------------------------ */
if ($ABILITA_LOG) {
    $ua   = str_replace(array("\r", "\n", '"'), '', ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $riga = date('Y-m-d H:i:s') . ',' . $id . ',' . $scelta . ',' . $ip . ',"' . $ua . "\"\n";
    @file_put_contents($LOG, $riga, FILE_APPEND | LOCK_EX);
}

/* --- header ANTI-CACHE (fondamentali perche' lo scambio funzioni) -------- */
$ext  = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
$mime = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg'));

header('Content-Type: ' . $mime);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

/* --- invia l'immagine ---------------------------------------------------- */
if (is_file($foto)) {
    header('Content-Length: ' . filesize($foto));
    readfile($foto);
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Foto non trovata: controlla che ' . basename($foto) . ' sia nella cartella.';
}
