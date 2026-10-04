<?php
/* =========================================================================
   PREDIZIONE — Strumento foto (utenti con la funzione "foto")
   -------------------------------------------------------------------------
   Scrive una frase SUL FOGLIO di una "foto base" e la salva come RIVELAZIONE.

   Se l'utente ha marcato i 4 ANGOLI del foglio (foto_quad), la scritta viene
   adattata in PROSPETTIVA (si appoggia sul piano del foglio). Altrimenti usa
   un piazzamento centrale semplice.

   Calligrafia: font "scrittura.ttf" (sostituibile con un font a mano libera).
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
if (empty(predizione_flags($utente)['foto'])) {
    predizione_json(['ok' => false, 'errore' => 'Strumento foto non abilitato per questo utente'], 403);
}
$testo = substr((string)(p('testo') ?? ''), 0, 120);
if ($testo === '') {
    predizione_json(['ok' => false, 'errore' => 'Scrivi cosa deve comparire sul foglio'], 400);
}
if (!function_exists('imagettftext')) {
    predizione_json(['ok' => false, 'errore' => 'Il server non supporta la grafica (GD/FreeType)'], 500);
}

/* --- foto base ------------------------------------------------------------ */
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
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
if     ($ext === 'png')  { $img = @imagecreatefrompng($base); }
elseif ($ext === 'gif')  { $img = @imagecreatefromgif($base); }
elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $img = @imagecreatefromwebp($base); }
else                     { $img = @imagecreatefromjpeg($base); }
if (!$img) { predizione_json(['ok' => false, 'errore' => 'Foto base non leggibile'], 400); }
$W = imagesx($img); $H = imagesy($img);

$font = __DIR__ . '/scrittura.ttf';
if (!is_file($font)) { predizione_json(['ok' => false, 'errore' => 'Manca il font "scrittura.ttf" sul server'], 500); }

/* --- i 4 angoli del foglio (se marcati) come frazioni 0..1 --------------- */
$quad = null;
if (!empty($stato['foto_quad']) && is_array($stato['foto_quad']) && count($stato['foto_quad']) === 4) {
    $quad = [];
    foreach ($stato['foto_quad'] as $pt) {
        $quad[] = [ (float)$pt[0] * $W, (float)$pt[1] * $H ];
    }
}
/* la "gomma": poligono (es. la mano) dove NON scrivere */
$gomma = null;
if (!empty($stato['foto_gomma']) && is_array($stato['foto_gomma']) && count($stato['foto_gomma']) >= 3) {
    $gomma = [];
    foreach ($stato['foto_gomma'] as $pt) {
        $gomma[] = [ (float)$pt[0] * $W, (float)$pt[1] * $H ];
    }
}

/* =========================================================================
   Caso A: 4 ANGOLI -> scrittura in PROSPETTIVA
   ========================================================================= */
if ($quad) {
    predizione_scrivi_prospettiva($img, $W, $H, $quad, $testo, $font, $gomma);
} else {
/* =========================================================================
   Caso B (ripiego): piazzamento piatto centrale
   ========================================================================= */
    $ink = imagecolorallocate($img, 24, 34, 82);
    $angolo = -3; $boxW = $W * 0.60; $cx = $W * 0.50; $cy = $H * 0.52;
    $size = max(14, (int)($W / 14));
    for ($i = 0; $i < 60 && $size > 10; $i++) {
        $bb = imagettfbbox($size, $angolo, $font, $testo);
        if (abs($bb[2] - $bb[0]) <= $boxW) break;
        $size -= 2;
    }
    $bb = imagettfbbox($size, $angolo, $font, $testo);
    $tw = abs($bb[2] - $bb[0]); $th = abs($bb[7] - $bb[1]);
    imagettftext($img, $size, $angolo, (int)($cx - $tw/2), (int)($cy + $th/2), $ink, $font, $testo);
}

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

$preview = '';
$raw = @file_get_contents($dest);
if ($raw !== false) { $preview = 'data:image/jpeg;base64,' . base64_encode($raw); }

predizione_json(['ok' => true, 'preview' => $preview]);


/* =========================================================================
   FUNZIONI: prospettiva (GD puro)
   ========================================================================= */
function predizione_gauss(array $A, array $b): array {
    $n = count($b);
    for ($i = 0; $i < $n; $i++) {
        $p = $i;
        for ($r = $i+1; $r < $n; $r++) if (abs($A[$r][$i]) > abs($A[$p][$i])) $p = $r;
        [$A[$i], $A[$p]] = [$A[$p], $A[$i]]; [$b[$i], $b[$p]] = [$b[$p], $b[$i]];
        $d = $A[$i][$i]; if (abs($d) < 1e-12) $d = 1e-12;
        for ($r = 0; $r < $n; $r++) {
            if ($r == $i) continue;
            $f = $A[$r][$i] / $d;
            for ($c = $i; $c < $n; $c++) $A[$r][$c] -= $f * $A[$i][$c];
            $b[$r] -= $f * $b[$i];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($i = 0; $i < $n; $i++) $x[$i] = $b[$i] / ($A[$i][$i] ?: 1e-12);
    return $x;
}
/* omografia che mappa dst(4) -> src(4) */
function predizione_homography(array $dst, array $src): array {
    $A = []; $b = [];
    for ($i = 0; $i < 4; $i++) {
        [$dx,$dy] = $dst[$i]; [$sx,$sy] = $src[$i];
        $A[] = [$dx,$dy,1,0,0,0,-$dx*$sx,-$dy*$sx]; $b[] = $sx;
        $A[] = [0,0,0,$dx,$dy,1,-$dx*$sy,-$dy*$sy]; $b[] = $sy;
    }
    return predizione_gauss($A, $b);
}
function predizione_inside(float $px, float $py, array $q): bool {
    $s = 0;
    for ($i = 0; $i < 4; $i++) {
        $a = $q[$i]; $c = $q[($i+1)%4];
        $cross = ($c[0]-$a[0])*($py-$a[1]) - ($c[1]-$a[1])*($px-$a[0]);
        $sg = $cross > 0 ? 1 : ($cross < 0 ? -1 : 0);
        if ($sg != 0) { if ($s == 0) $s = $sg; elseif ($s != $sg) return false; }
    }
    return true;
}
/* punto dentro un poligono qualsiasi (ray casting) — per la "gomma" */
function predizione_in_poly(float $px, float $py, array $poly): bool {
    $n = count($poly); $in = false;
    for ($i = 0, $j = $n-1; $i < $n; $j = $i++) {
        $xi=$poly[$i][0]; $yi=$poly[$i][1]; $xj=$poly[$j][0]; $yj=$poly[$j][1];
        if ((($yi > $py) != ($yj > $py)) &&
            ($px < ($xj-$xi)*($py-$yi)/(($yj-$yi) ?: 1e-9) + $xi)) $in = !$in;
    }
    return $in;
}
function predizione_scrivi_prospettiva($img, int $W, int $H, array $quad, string $testo, string $font, ?array $gomma = null): void {
    $dist = function($a,$b){ return sqrt(($a[0]-$b[0])**2 + ($a[1]-$b[1])**2); };
    $lW = (int)max(50, max($dist($quad[0],$quad[1]), $dist($quad[3],$quad[2])));
    $lH = (int)max(40, max($dist($quad[0],$quad[3]), $dist($quad[1],$quad[2])));

    // etichetta trasparente col testo (nero), poi tinta al compositing
    $label = imagecreatetruecolor($lW, $lH);
    imagesavealpha($label, true);
    imagefill($label, 0, 0, imagecolorallocatealpha($label, 0, 0, 0, 127));
    $black = imagecolorallocate($label, 0, 0, 0);
    $size = (int)($lH * 0.42);
    for ($i = 0; $i < 40 && $size > 6; $i++) {
        $bb = imagettfbbox($size, 0, $font, $testo);
        if (abs($bb[2]-$bb[0]) <= $lW * 0.9) break;
        $size -= 2;
    }
    $bb = imagettfbbox($size, 0, $font, $testo);
    $tw = abs($bb[2]-$bb[0]); $th = abs($bb[7]-$bb[1]);
    imagettftext($label, $size, 0, (int)(($lW-$tw)/2), (int)(($lH+$th)/2), $black, $font, $testo);

    $src = [[0,0],[$lW,0],[$lW,$lH],[0,$lH]];
    [$a,$b2,$c,$d,$e,$f,$g,$h] = predizione_homography($quad, $src); // dst(foglio)->src(etichetta)

    $minx = (int)floor(min($quad[0][0],$quad[1][0],$quad[2][0],$quad[3][0]));
    $maxx = (int)ceil (max($quad[0][0],$quad[1][0],$quad[2][0],$quad[3][0]));
    $miny = (int)floor(min($quad[0][1],$quad[1][1],$quad[2][1],$quad[3][1]));
    $maxy = (int)ceil (max($quad[0][1],$quad[1][1],$quad[2][1],$quad[3][1]));
    $inkR = 20; $inkG = 30; $inkB = 90;

    for ($y = max(0,$miny); $y <= min($H-1,$maxy); $y++) {
        for ($x = max(0,$minx); $x <= min($W-1,$maxx); $x++) {
            if (!predizione_inside($x+0.5, $y+0.5, $quad)) continue;
            if ($gomma && predizione_in_poly($x+0.5, $y+0.5, $gomma)) continue; // "gomma": salta (es. la mano)
            $den = $g*$x + $h*$y + 1; if (abs($den) < 1e-9) continue;
            $sx = ($a*$x + $b2*$y + $c)/$den; $sy = ($d*$x + $e*$y + $f)/$den;
            if ($sx < 0 || $sy < 0 || $sx >= $lW-1 || $sy >= $lH-1) continue;
            $x0=(int)$sx; $y0=(int)$sy; $fx=$sx-$x0; $fy=$sy-$y0;
            $al = function($px,$py) use($label){ $cc=imagecolorat($label,$px,$py); $aa=($cc>>24)&0x7F; return 1-($aa/127); };
            $cov = ($al($x0,$y0)*(1-$fx)+$al($x0+1,$y0)*$fx)*(1-$fy) + ($al($x0,$y0+1)*(1-$fx)+$al($x0+1,$y0+1)*$fx)*$fy;
            if ($cov <= 0.02) continue;
            $cov *= 0.92;
            $bse = imagecolorat($img,$x,$y); $br=($bse>>16)&255; $bg=($bse>>8)&255; $bb3=$bse&255;
            $lum = ($br*0.3 + $bg*0.59 + $bb3*0.11)/255;
            $ir = (int)($inkR*$lum + $br*(1-$lum)*0.15);
            $ig = (int)($inkG*$lum + $bg*(1-$lum)*0.15);
            $ib = (int)($inkB*$lum + $bb3*(1-$lum)*0.15);
            $nr = (int)max(0,min(255,$br*(1-$cov)+$ir*$cov));
            $ng = (int)max(0,min(255,$bg*(1-$cov)+$ig*$cov));
            $nb = (int)max(0,min(255,$bb3*(1-$cov)+$ib*$cov));
            imagesetpixel($img,$x,$y,imagecolorallocate($img,$nr,$ng,$nb));
        }
    }
    imagedestroy($label);
}
