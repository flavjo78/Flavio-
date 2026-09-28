<?php
/* =========================================================================
   PREDIZIONE — caricamento delle foto dal pannello
   -------------------------------------------------------------------------
   Il pannello invia qui la Foto A o la Foto B (una alla volta).
   Il file viene salvato nella cartella protetta _dati/ e il suo percorso
   viene registrato nello stato, cosi' image.php sa quale mostrare.

   Richiede la password. Niente da configurare qui: vedi config.php.
   ========================================================================= */

require __DIR__ . '/config.php';

header('Cache-Control: no-store');

$password = $_POST['password'] ?? null;
$slot     = strtoupper((string)($_POST['slot'] ?? '')); // 'A' o 'B'

if (!predizione_password_ok(is_string($password) ? $password : null)) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}
if ($slot !== 'A' && $slot !== 'B') {
    predizione_json(['ok' => false, 'errore' => 'Slot non valido (usa A o B)'], 400);
}
if (!isset($_FILES['foto']) || !is_array($_FILES['foto']) || ($_FILES['foto']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    predizione_json(['ok' => false, 'errore' => 'Nessun file ricevuto o errore di upload'], 400);
}

$tmp = $_FILES['foto']['tmp_name'];

/* --- controllo che sia davvero un'immagine ------------------------------- */
$info = @getimagesize($tmp);
if ($info === false) {
    predizione_json(['ok' => false, 'errore' => 'Il file non e\' un\'immagine valida'], 400);
}
$mime2ext = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$mime = $info['mime'] ?? '';
if (!isset($mime2ext[$mime])) {
    predizione_json(['ok' => false, 'errore' => 'Formato non supportato (usa JPG, PNG, GIF o WEBP)'], 400);
}
$ext = $mime2ext[$mime];

predizione_prepara_cartella();

/* --- salva come foto_a.<ext> / foto_b.<ext> ------------------------------
   Prima rimuovo eventuali versioni con estensione diversa, per non lasciare
   in giro una vecchia foto in un altro formato. */
$base = DATA_DIR . '/foto_' . strtolower($slot);
foreach (['jpg', 'png', 'gif', 'webp'] as $vecchia) {
    if (is_file($base . '.' . $vecchia)) { @unlink($base . '.' . $vecchia); }
}
$dest = $base . '.' . $ext;

if (!move_uploaded_file($tmp, $dest)) {
    predizione_json(['ok' => false, 'errore' => 'Impossibile salvare il file'], 500);
}

/* --- aggiorna lo stato con il percorso della foto ------------------------ */
$stato = predizione_leggi_stato();
if ($slot === 'A') { $stato['foto_a_file'] = $dest; }
else               { $stato['foto_b_file'] = $dest; }
predizione_scrivi_stato($stato);

predizione_json([
    'ok'    => true,
    'slot'  => $slot,
    'tipo'  => $ext,
    'larghezza' => $info[0] ?? null,
    'altezza'   => $info[1] ?? null,
]);
