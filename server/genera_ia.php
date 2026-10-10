<?php
/* =========================================================================
   PREDIZIONE — Generatore IA della rivelazione (Foto B)
   -------------------------------------------------------------------------
   Prende la "foto base" (il performer col foglio bianco) e, usando Google
   Nano Banana 2.1, ci fa scrivere SOPRA la frase della rivelazione con una
   grafia naturale a penna. Salva il risultato come rivelazione pronta.

   Sostituisce, per gli utenti con la funzione "foto", la vecchia scrittura
   grafica (genera.php). Nessun altro file del sistema viene toccato.

   SICUREZZA: la CHIAVE API non sta nel codice (il repo e' pubblico). Va messa
   in un file sul server, dentro la cartella blindata dei dati:
       server/_dati/gemini.key   -> contiene SOLO la chiave (una riga)
   Facoltativo, per cambiare modello senza toccare il codice:
       server/_dati/gemini.model -> es. "gemini-nano-banana-2.1" (default)
   ========================================================================= */

require __DIR__ . '/config.php';
header('Cache-Control: no-store');
@set_time_limit(180);

function pia(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}

/* ---- accesso: password dell'utente OPPURE lasciapassare assistente ------ */
$utente    = predizione_set_utente($_POST['utente'] ?? $_GET['u'] ?? '');
$password  = $_POST['password'] ?? null;
$assistTok = $_POST['assistente'] ?? $_GET['assistente'] ?? null;
$statoAuth = predizione_leggi_stato();

$autorizzato = predizione_auth($utente, is_string($password) ? $password : null)
    || predizione_assistente_valido($statoAuth, is_string($assistTok) ? $assistTok : null);
if (!$autorizzato) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}
if (empty(predizione_flags($utente)['foto'])) {
    predizione_json(['ok' => false, 'errore' => 'Funzione foto non abilitata per questo utente'], 403);
}

/* ---- frase da scrivere -------------------------------------------------- */
$testo = substr((string)(pia('testo') ?? ''), 0, 200);
if ($testo === '') {
    predizione_json(['ok' => false, 'errore' => 'Scrivi cosa deve comparire sul foglio'], 400);
}

/* ---- chiave API (dal file protetto, mai nel codice) --------------------- */
$keyFile = DATA_DIR . '/gemini.key';
$apiKey  = is_file($keyFile) ? trim((string)@file_get_contents($keyFile)) : '';
if ($apiKey === '') {
    predizione_json(['ok' => false, 'errore' => 'Manca la chiave IA sul server (file _dati/gemini.key)'], 500);
}
$modelFile = DATA_DIR . '/gemini.model';
$model = is_file($modelFile) ? trim((string)@file_get_contents($modelFile)) : '';
if ($model === '') { $model = 'gemini-nano-banana-2.1'; }

/* ---- foto base (il performer col foglio bianco) ------------------------- */
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
$raw = @file_get_contents($base);
if ($raw === false || $raw === '') {
    predizione_json(['ok' => false, 'errore' => 'Foto base non leggibile'], 400);
}
$ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'][$ext] ?? 'image/jpeg';

/* ---- il prompt (ricetta collaudata) con la frase inserita --------------- */
/* La frase viene inserita SENZA parentesi quadre (vedi guide/prompt-foto-ia.md).
   Se il performer ha scritto piu' righe (tasto "Vai a capo"), le scriviamo una
   sotto l'altra, come una lista a mano. */
$righe = preg_split('/\r\n|\r|\n/', $testo);
$righe = array_map(function ($r) { return trim(str_replace('"', ' ', (string)$r)); }, $righe);
$righe = array_values(array_filter($righe, function ($r) { return $r !== ''; }));
if (count($righe) <= 1) {
    $frase = $righe[0] ?? '';
    $istruzioneFrase =
        "Scrivi sul foglio bianco al centro, in un'unica riga orizzontale, la frase: $frase . " .
        "La frase va scritta una sola volta, senza alcuna ripetizione.";
} else {
    $elenco = implode("\n", array_map(function ($r) { return '- ' . $r; }, $righe));
    $istruzioneFrase =
        "Scrivi sul foglio bianco, a mano, le seguenti righe UNA SOTTO L'ALTRA (ognuna su una " .
        "riga nuova, come una lista scritta a mano, ben spaziate e allineate a sinistra):\n$elenco\n" .
        "Scrivi esattamente queste parole, ogni voce una sola volta, SENZA trattini, numeri, " .
        "puntini o altri simboli davanti.";
}
$prompt =
"Modifica l'immagine allegata mantenendo tutto il resto (posa, mano, dita, sfondo) " .
"completamente invariato, senza aggiungere alcun oggetto come penne o altro. $istruzioneFrase " .
"Lascia del tutto visibili e scoperte le dita che tengono la carta. Scrivi esclusivamente le " .
"parole indicate, senza virgolette, parentesi o altri simboli.\n" .
"La grafia deve essere un corsivo quotidiano, veloce, informale e imperfetto (non scolastico " .
"o calligrafico), come un appunto frettoloso. Lettere collegate in modo rapido, altezze " .
"irregolari e spontanee, la scritta tende a stringersi verso la fine della riga.\n" .
"Penna a sfera blu (stile Bic) con inchiostro non uniforme: tratti leggermente sbiaditi, " .
"micro-interruzioni e piccoli accumuli di inchiostro tipici della scrittura a mano su carta. " .
"Nessun allineamento perfetto: la scritta pende o si curva leggermente in modo naturale. " .
"La scritta si integra con la stessa grana, luce, ombra e leggera sfocatura dell'immagine " .
"originale, come se fosse parte originaria della foto.";

/* ---- chiamata a Gemini -------------------------------------------------- */
$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($raw)]],
        ],
    ]],
    'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
];
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

if (!function_exists('curl_init')) {
    predizione_json(['ok' => false, 'errore' => 'Il server non supporta cURL'], 500);
}
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 170,
    CURLOPT_CONNECTTIMEOUT => 20,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    predizione_json(['ok' => false, 'errore' => 'Connessione a Gemini fallita: ' . $cerr], 502);
}
$j = json_decode($resp, true);
if ($http !== 200 || !is_array($j)) {
    $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : ('Risposta IA non valida (HTTP ' . $http . ')');
    predizione_json(['ok' => false, 'errore' => 'Gemini: ' . $msg], 502);
}

/* ---- estrai l'immagine generata ---------------------------------------- */
$imgData = null; $imgMime = 'image/png';
$parts = $j['candidates'][0]['content']['parts'] ?? [];
if (is_array($parts)) {
    foreach ($parts as $p) {
        $inline = $p['inline_data'] ?? $p['inlineData'] ?? null;
        if (is_array($inline) && !empty($inline['data'])) {
            $imgData = base64_decode((string)$inline['data']);
            $imgMime = (string)($inline['mime_type'] ?? $inline['mimeType'] ?? 'image/png');
            break;
        }
    }
}
if ($imgData === null || $imgData === false || $imgData === '') {
    predizione_json(['ok' => false, 'errore' => 'Gemini non ha restituito un\'immagine (riprova)'], 502);
}

/* ---- salva come rivelazione pronta (Foto B) ----------------------------- */
predizione_prepara_cartella();
foreach (array_values(TIPI_IMG) as $v) {
    $old = predizione_data_dir() . '/rivelazione_pronta.' . $v;
    if (is_file($old)) @unlink($old);
}
$dest = predizione_data_dir() . '/rivelazione_pronta.jpg';
$salvata = false;
/* se possibile, ri-codifichiamo in JPEG (coerente col resto del sistema) */
if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
    $im = @imagecreatefromstring($imgData);
    if ($im) {
        $salvata = @imagejpeg($im, $dest, 90);
        imagedestroy($im);
    }
}
if (!$salvata) {
    /* ripiego: salva coi byte grezzi, con l'estensione giusta */
    $ext2 = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'][$imgMime] ?? 'png';
    $dest = predizione_data_dir() . '/rivelazione_pronta.' . $ext2;
    if (@file_put_contents($dest, $imgData) === false) {
        predizione_json(['ok' => false, 'errore' => 'Impossibile salvare la rivelazione'], 500);
    }
}

$stato = predizione_leggi_stato();
$stato['rivelazione_pronta'] = $dest;
predizione_scrivi_stato($stato);

$preview = '';
$rawOut = @file_get_contents($dest);
if ($rawOut !== false) {
    $m = (substr($dest, -3) === 'jpg') ? 'image/jpeg' : $imgMime;
    $preview = 'data:' . $m . ';base64,' . base64_encode($rawOut);
}

predizione_json(['ok' => true, 'preview' => $preview]);
