<?php
/* =========================================================================
   PREDIZIONE — caricamento delle foto dal pannello
   -------------------------------------------------------------------------
   slot = A  -> Foto A NEUTRA (persistente): resta in memoria, riusata ogni
               sessione. La ricarichi solo quando vuoi cambiarla.
   slot = B  -> RIVELAZIONE per la PROSSIMA sessione: viene messa "in attesa"
               e verra' consumata quando premi "Avvia".

   Richiede la password. Vedi config.php.
   ========================================================================= */

require __DIR__ . '/config.php';

header('Cache-Control: no-store');

$utente   = predizione_set_utente($_POST['utente'] ?? $_GET['u'] ?? '');  // contesto utente
$password = $_POST['password'] ?? null;
$slot     = strtoupper((string)($_POST['slot'] ?? ''));   // 'A' oppure 'B'

if (!predizione_auth($utente, is_string($password) ? $password : null)) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}
if ($slot !== 'A' && $slot !== 'B' && $slot !== 'BASE') {
    predizione_json(['ok' => false, 'errore' => 'Slot non valido (usa A, B o BASE)'], 400);
}
if (!isset($_FILES['foto']) || !is_array($_FILES['foto']) || ($_FILES['foto']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    predizione_json(['ok' => false, 'errore' => 'Nessun file ricevuto o errore di upload'], 400);
}

$tmp  = $_FILES['foto']['tmp_name'];
$info = @getimagesize($tmp);
if ($info === false) {
    predizione_json(['ok' => false, 'errore' => 'Il file non e\' un\'immagine valida'], 400);
}
$mime = $info['mime'] ?? '';
if (!isset(TIPI_IMG[$mime])) {
    predizione_json(['ok' => false, 'errore' => 'Formato non supportato (usa JPG, PNG, GIF o WEBP)'], 400);
}
$ext = TIPI_IMG[$mime];

predizione_prepara_cartella();

/* nome base secondo lo slot */
$nomeBase = ($slot === 'A') ? 'foto_a' : (($slot === 'BASE') ? 'foto_base' : 'rivelazione_pronta');

/* togli eventuali versioni con estensione diversa */
$base = predizione_data_dir() . '/' . $nomeBase;
foreach (array_values(TIPI_IMG) as $vecchia) {
    if (is_file($base . '.' . $vecchia)) { @unlink($base . '.' . $vecchia); }
}
$dest = $base . '.' . $ext;

if (!move_uploaded_file($tmp, $dest)) {
    predizione_json(['ok' => false, 'errore' => 'Impossibile salvare il file'], 500);
}

/* aggiorna lo stato */
$stato = predizione_leggi_stato();
if      ($slot === 'A')    { $stato['foto_a'] = $dest; }
elseif  ($slot === 'BASE') { $stato['foto_base'] = $dest; }   // per lo strumento foto
else                       { $stato['rivelazione_pronta'] = $dest; }
predizione_scrivi_stato($stato);

predizione_json([
    'ok'        => true,
    'slot'      => $slot,
    'tipo'      => $ext,
    'larghezza' => $info[0] ?? null,
    'altezza'   => $info[1] ?? null,
]);
