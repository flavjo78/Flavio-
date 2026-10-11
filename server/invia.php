<?php
/* =========================================================================
   PREDIZIONE — INVIO MANUALE a pochi indirizzi (dalla casella dell'utente)
   -------------------------------------------------------------------------
   Il performer scrive 1..N indirizzi e spedisce a ciascuno la mail con la
   foto del gioco (link image.php). Usa la SUA casella (es. Gmail + password
   per app). Per eventi piccoli: per i grandi c'è il canale "Palco".

   POST:  azione=invia  utente=NNN  password=...  (oppure token assistente)
          indirizzi="a@x.it, b@y.it\n..."    (massimo PRED_INVIO_MAX)
   ========================================================================= */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smtp.php';

const PRED_INVIO_MAX = 10;               // massimo indirizzi per invio manuale
const PRED_IMG_BASE  = 'https://www.abraka.it/predizione/image.php';  // SEMPRE con www

function iv_p(string $k): string { $v = $_POST[$k] ?? $_GET[$k] ?? ''; return is_string($v) ? trim($v) : ''; }

$utente = predizione_set_utente(iv_p('utente') ?: iv_p('u'));
$stato  = predizione_leggi_stato();

/* --- accesso: password dell'utente OPPURE token assistente -------------- */
$pass  = $_POST['password'] ?? $_GET['password'] ?? null;
$token = iv_p('token');
$ok = predizione_auth($utente, is_string($pass) ? $pass : null)
   || predizione_assistente_valido($stato, $token !== '' ? $token : null);
if (!$ok) predizione_json(['ok' => false, 'errore' => 'Accesso negato'], 403);

/* --- casella configurata? ---------------------------------------------- */
$rec = predizione_utente_record($utente);
$m   = ($rec && isset($rec['mail'])) ? (array)$rec['mail'] : [];
$cUser = (string)($m['user'] ?? '');
$cPass = (string)($m['pass'] ?? '');
$cFrom = (string)($m['from'] ?? '');
if ($cUser === '' || $cPass === '') {
    predizione_json(['ok' => false, 'errore' => 'Prima imposta la tua casella (email + password per app)'], 400);
}

/* --- sessione attiva? (serve perché la foto giusta venga mostrata) ------ */
$sid = (int)($stato['sessione_corrente'] ?? 0);
if ($sid <= 0) {
    predizione_json(['ok' => false, 'errore' => 'Avvia prima il gioco: senza sessione la foto sarebbe vuota'], 400);
}

/* --- indirizzi --------------------------------------------------------- */
$raw = iv_p('indirizzi');
$parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
$dest = [];
foreach ($parts as $e) {
    $e = strtolower(trim($e));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $dest, true)) $dest[] = $e;
}
if (!$dest) predizione_json(['ok' => false, 'errore' => 'Nessun indirizzo valido'], 400);
if (count($dest) > PRED_INVIO_MAX) {
    predizione_json(['ok' => false, 'errore' => 'Massimo ' . PRED_INVIO_MAX . ' indirizzi per invio'], 400);
}

/* --- contenuto della mail (oggetto/testo dal pannello) ------------------ */
$oggetto = (string)($stato['mail_oggetto'] ?? '') ?: 'La tua foto';
$testo   = (string)($stato['mail_testo'] ?? '');
$testoHtml = nl2br(htmlspecialchars($testo, ENT_QUOTES, 'UTF-8'));

[$host, $port] = pred_smtp_host($cUser);

/* --- invio uno per uno ------------------------------------------------- */
$inviate = 0; $errori = [];
foreach ($dest as $to) {
    $id  = 'm' . substr(hash('sha256', $to . '|' . microtime(true) . '|' . random_int(0, 1e9)), 0, 14);
    $img = PRED_IMG_BASE . '?u=' . rawurlencode($utente) . '&s=' . $sid . '&id=' . $id;
    $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222">'
          . ($testoHtml !== '' ? '<p>' . $testoHtml . '</p>' : '')
          . '<p><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" width="500" '
          . 'style="display:block;max-width:100%;border:0" alt=""></p></div>';
    $err = null;
    if (pred_smtp_send($host, $port, $cUser, $cPass, $cUser, $cFrom, $to, $oggetto, $body, $err)) {
        $inviate++;
    } else {
        $errori[] = ['to' => $to, 'err' => $err];
    }
}

/* --- consumo mensile + conteggio sessione ------------------------------ */
if ($inviate > 0) {
    $stato['mail_inviate'] = (int)($stato['mail_inviate'] ?? 0) + $inviate;
    predizione_uso_incr($stato, 'mail', $inviate);
    predizione_scrivi_stato($stato);
}

predizione_json(['ok' => $inviate > 0, 'inviate' => $inviate, 'totali' => count($dest), 'errori' => $errori]);
