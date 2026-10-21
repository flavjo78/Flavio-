<?php
/* =========================================================================
   PREDIZIONE — anteprima della foto SALVATA di uno slot (A / B / BASE)
   -------------------------------------------------------------------------
   Serve al pannello (e all'assistente) per RIVEDERE la foto caricata.
   Accesso: password dell'utente OPPURE lasciapassare assistente.
   NON mostra la foto del gioco agli spettatori: quello è image.php.
   Uso:  anteprima.php?u=NNN&slot=A|B|BASE&password=...   (o &assistente=TOKEN)
   ========================================================================= */

require __DIR__ . '/config.php';

predizione_set_utente($_POST['utente'] ?? $_GET['u'] ?? '');
$password = $_POST['password'] ?? $_GET['password'] ?? null;
$token    = $_POST['assistente'] ?? $_GET['assistente'] ?? null;

$stato = predizione_leggi_stato();
$ok = predizione_auth(predizione_utente(), is_string($password) ? $password : null)
   || predizione_assistente_valido($stato, is_string($token) ? $token : null);
if (!$ok) { http_response_code(403); exit; }

$slot = strtoupper((string)($_GET['slot'] ?? $_POST['slot'] ?? ''));
if ($slot === 'A')        { $path = $stato['foto_a'] ?? null; $fallback = __DIR__ . '/neutro.png'; }
elseif ($slot === 'BASE') { $path = $stato['foto_base'] ?? null; $fallback = null; }
else {
    // Rivelazione (B): a gioco AVVIATO la foto viene "congelata" nella sessione
    // (rivelazione_pronta diventa vuoto) -> mostra quella della sessione attiva,
    // altrimenti la rivelazione in attesa. Così la miniatura combacia con la mail.
    $sess = predizione_sessione_corrente($stato);
    $path = ($sess && !empty($sess['after']) && is_file($sess['after']))
            ? $sess['after']
            : ($stato['rivelazione_pronta'] ?? null);
    $fallback = null;
}

if ((!$path || !is_file($path)) && $fallback && is_file($fallback)) { $path = $fallback; }
if (!$path || !is_file($path)) { http_response_code(404); exit; }

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ($ext === 'png') ? 'image/png'
      : (($ext === 'gif') ? 'image/gif'
      : (($ext === 'webp') ? 'image/webp' : 'image/jpeg'));
header('Content-Type: ' . $mime);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Length: ' . filesize($path));
readfile($path);
