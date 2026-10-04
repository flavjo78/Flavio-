<?php
/* =========================================================================
   PREDIZIONE — Strumento foto (utenti con la funzione "foto")
   -------------------------------------------------------------------------
   Scrive una frase SUL FOGLIO di una "foto base" (il performer col foglio
   bianco in mano) e la salva come RIVELAZIONE (Foto B) pronta per la sessione.

   Per una calligrafia realistica, sostituisci il file "scrittura.ttf" nella
   cartella degli script con un font a mano libera (es. Caveat/Dancing Script).
   ========================================================================= */

require __DIR__ . '/config.php';
header('Cache-Control: no-store');

function p(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}

$utente   = predizione_set_utente($_POST['utente'] ?? $_GET['u'] ?? '');
$password = $_POST['password'] ?? null;

if (!predizione_auth($utente, is_string($password) ? $password : null)) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}
$flags = predizione_flags($utente);
if (empty($flags['foto'])) {
    predizione_json(['ok' => false, 'errore' => 'Strumento foto non abilitato per questo utente'], 403);
}

$testo = substr((string)(p('testo') ?? ''), 0, 120);
if ($testo === '') {
    predizione_json(['ok' => false, 'errore' => 'Scrivi cosa deve comparire sul foglio'], 400);
}
if (!function_exists('imagettftext')) {
    predizione_json(['ok' => false, 'errore' => 'Il server non supporta la grafica (GD/FreeType)'], 500);
}

/* --- trova la foto base --------------------------------------------------- */
$stato = predizione_leggi_stato();
$base = (!empty($stato['foto_base']) && is_file($stato['foto_base'])) ? $stato['foto_base'] : null;
if (!$base) {
    foreach (['jpg','jpeg','png','gif','webp'] as $e) {
        $f = predizione_data_dir() . '/foto_base.' . $e;
        if (is_file($f)) { $base = $f; break; }
    }
}
if (!$base) {
    predizione_json(['ok' => false, 'errore' => 'Carica prima la Foto base (tu col foglio bianco)'], 400);
}

/* --- carica l'immagine ---------------------------------------------------- */
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
$img = null;
if     ($ext === 'png')  { $img = @imagecreatefrompng($base); }
elseif ($ext === 'gif')  { $img = @imagecreatefromgif($base); }
elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $img = @imagecreatefromwebp($base); }
else                     { $img = @imagecreatefromjpeg($base); }
if (!$img) {
    predizione_json(['ok' => false, 'errore' => 'Foto base non leggibile'], 400);
}
$W = imagesx($img); $H = imagesy($img);

/* --- font (sostituibile con una calligrafia reale: scrittura.ttf) --------- */
$font = __DIR__ . '/scrittura.ttf';
if (!is_file($font)) {
    predizione_json(['ok' => false, 'errore' => 'Manca il font "scrittura.ttf" sul server'], 500);
}

/* --- scrive la frase sul foglio ------------------------------------------- */
$ink = imagecolorallocate($img, 24, 34, 82);   // blu-inchiostro scuro
$angolo = -3;                                   // leggera inclinazione naturale
$boxW = $W * 0.60;                              // larghezza utile (zona foglio)
$cx = $W * 0.50; $cy = $H * 0.52;               // centro del foglio (regolabile)

$size = max(14, (int)($W / 14));
for ($i = 0; $i < 60 && $size > 10; $i++) {
    $bb = imagettfbbox($size, $angolo, $font, $testo);
    $tw = abs($bb[2] - $bb[0]);
    if ($tw <= $boxW) break;
    $size -= 2;
}
$bb = imagettfbbox($size, $angolo, $font, $testo);
$tw = abs($bb[2] - $bb[0]); $th = abs($bb[7] - $bb[1]);
$x = (int)($cx - $tw / 2);
$y = (int)($cy + $th / 2);
imagettftext($img, $size, $angolo, $x, $y, $ink, $font, $testo);

/* --- salva come rivelazione pronta (Foto B) ------------------------------- */
predizione_prepara_cartella();
foreach (array_values(TIPI_IMG) as $v) {
    $old = predizione_data_dir() . '/rivelazione_pronta.' . $v;
    if (is_file($old)) @unlink($old);
}
$dest = predizione_data_dir() . '/rivelazione_pronta.jpg';
if (!imagejpeg($img, $dest, 90)) {
    imagedestroy($img);
    predizione_json(['ok' => false, 'errore' => 'Impossibile salvare la rivelazione'], 500);
}
imagedestroy($img);

$stato = predizione_leggi_stato();
$stato['rivelazione_pronta'] = $dest;
predizione_scrivi_stato($stato);

predizione_json(['ok' => true]);
