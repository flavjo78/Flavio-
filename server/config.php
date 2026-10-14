<?php
/* =========================================================================
   PREDIZIONE — configurazione condivisa (MULTI-UTENTE)
   -------------------------------------------------------------------------
   Incluso da image.php, stato.php, carica.php, genera.php, admin.php.

   NOVITA' MULTI-UTENTE
   - Ogni utente ha un NUMERO (001, 002, 003, ...) e una password.
   - L'elenco utenti sta nel "registro" protetto: _dati/utenti.json
     (si crea da solo al primo avvio, con 000 admin + 001/002/003 di prova).
   - I dati di ogni utente stanno in cartelle separate:
       utente 001 -> _dati/           (resta sulla radice, per non toccare il live)
       utente 002 -> _dati/u002/
       utente 003 -> _dati/u003/   ...
   - Le PASSWORD ora vivono nel registro (non piu' qui): questo file e'
     diventato "neutro" e si puo' caricare senza timori.
   ========================================================================= */

/* ---- Fuso orario ------------------------------------------------------- */
date_default_timezone_set('Europe/Rome');

/* ---- Cartella dei dati (radice, condivisa) — protetta ------------------ */
const DATA_DIR = __DIR__ . '/_dati';

/* ---- Registro utenti (dentro _dati, protetto) -------------------------- */
const UTENTI_FILE = DATA_DIR . '/utenti.json';

/* ---- Password admin e utenti PREDEFINITE (solo al primo avvio) --------- */
const PRED_ADMIN_PASS   = '!!!';   // utente 000 (amministratore)
const PRED_DEFAULT_PASS = 'aaa';   // utenti 001/002/003

/* ---- Log delle aperture (la "cattura") --------------------------------- */
const ABILITA_LOG = true;

/* (compatibilita': vecchia costante, non piu' usata per l'accesso) */
const PREDIZIONE_PASSWORD = 'CAMBIA_QUESTA_PASSWORD';

const TIPI_IMG = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/* =========================================================================
   ==============  Da qui in giu' non serve toccare nulla  ================== */

/* -------------------- UTENTE CORRENTE (contesto) ------------------------ */
/* Impostato dagli script d'ingresso (stato/image/carica) in base al
   parametro ricevuto. Determina in quale cartella si leggono/scrivono i dati. */
$GLOBALS['PRED_U'] = '001';

/* Nome utente "pulito" e sicuro. Ora può essere NUMERICO (001, 002 — i vecchi) o
   BASATO SUL NOME DELLA MAIL (es. mario.rossi). Teniamo solo lettere/cifre/._-,
   tutto minuscolo, niente ".." o punti ai bordi (per non creare cartelle strane).
   Vuoto -> '001' (così i vecchi indirizzi image.php senza ?u restano su 001). */
function predizione_pulisci_utente($u): string {
    $u = strtolower((string)$u);
    $u = preg_replace('/[^a-z0-9._-]/', '', $u);     // solo caratteri sicuri
    $u = preg_replace('/\.{2,}/', '.', $u);          // niente ".."
    $u = trim($u, '._-');                            // niente punti/trattini ai bordi
    $u = substr($u, 0, 32);
    return ($u === '') ? '001' : $u;
}
function predizione_set_utente($u): string {
    $u = predizione_pulisci_utente($u);
    $GLOBALS['PRED_U'] = $u;
    return $u;
}
function predizione_utente(): string {
    return $GLOBALS['PRED_U'] ?? '001';
}

/* Cartella dati dell'utente corrente.
   001: storicamente sulla radice _dati/. Dopo la migrazione (quando esiste
   _dati/u001/) passa automaticamente a quella cartella, SENZA rompere i vecchi
   indirizzi (image.php con ?u mancante usa comunque 001 -> questa cartella). */
function predizione_data_dir(): string {
    $u = predizione_utente();
    if ($u === '001') {
        $u001 = DATA_DIR . '/u001';
        return is_dir($u001) ? $u001 : DATA_DIR;
    }
    return DATA_DIR . '/u' . $u;
}
function predizione_stato_file(): string { return predizione_data_dir() . '/stato.json'; }
function predizione_log_file():   string { return predizione_data_dir() . '/aperture.csv'; }

/* Crea la cartella radice e quella dell'utente, e le blinda con .htaccess. */
function predizione_prepara_cartella(): void {
    foreach ([DATA_DIR, predizione_data_dir()] as $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents(
                $ht,
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }
    }
}

/* -------------------- RIDIMENSIONA LE FOTO ------------------------------ */
/* Riduce automaticamente una foto troppo grande a una misura "giusta":
   lato lungo massimo $maxLato px, salvata in JPEG di qualita' $q. Cosi' le
   foto scattate col telefono (anche 10-12 MP, vari MB) diventano leggere e
   si aprono in fretta nella mail, senza che il performer debba fare nulla.
   Se GD non c'e' o l'immagine e' gia' piccola, lascia il file com'e'.
   Ritorna il percorso del file risultante (puo' cambiare estensione in .jpg). */
function predizione_ridimensiona(string $path, int $maxLato = 1280, int $q = 85): string {
    if (!is_file($path)) return $path;
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return $path;
    $info = @getimagesize($path);
    if ($info === false) return $path;
    [$w, $h] = $info;
    if ($w <= 0 || $h <= 0) return $path;

    $lato = max($w, $h);
    // gia' piccola E gia' JPEG: non serve rifare nulla
    $estCorr = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($lato <= $maxLato && ($estCorr === 'jpg' || $estCorr === 'jpeg')) return $path;

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $path;
    $im = @imagecreatefromstring($raw);
    if (!$im) return $path;

    if ($lato > $maxLato) {
        $scala = $maxLato / $lato;
        $nw = max(1, (int)round($w * $scala));
        $nh = max(1, (int)round($h * $scala));
        $dst = imagecreatetruecolor($nw, $nh);
        // sfondo bianco (per eventuali PNG/GIF con trasparenza)
        $bianco = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $bianco);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }

    $destJpg = preg_replace('/\.[^.]+$/', '', $path) . '.jpg';
    $ok = @imagejpeg($im, $destJpg, $q);
    imagedestroy($im);
    if (!$ok) return $path;
    // se l'originale aveva un'altra estensione, togli il vecchio file
    if ($destJpg !== $path && is_file($path)) @unlink($path);
    return $destJpg;
}

/* -------------------- REGISTRO UTENTI ----------------------------------- */
function predizione_utenti_default(): array {
    return [
        '000' => ['pass' => PRED_ADMIN_PASS,   'admin' => true,  'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => false], 'mail' => new stdClass()],
        '001' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => false], 'mail' => new stdClass()],
        '002' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => true,  'foto' => false], 'mail' => new stdClass()],
        '003' => ['pass' => PRED_DEFAULT_PASS, 'admin' => false, 'attivo' => true,
                  'flags' => ['mail' => false, 'foto' => true],  'mail' => new stdClass()],
    ];
}

function predizione_utenti_leggi(): array {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    if (!is_file(UTENTI_FILE)) {
        predizione_prepara_cartella();
        predizione_utenti_scrivi(predizione_utenti_default());
    }
    $raw = @file_get_contents(UTENTI_FILE);
    $dati = json_decode((string)$raw, true);
    return is_array($dati) ? $dati : predizione_utenti_default();
}
function predizione_utenti_scrivi(array $utenti): bool {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    $json = json_encode($utenti, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return @file_put_contents(UTENTI_FILE, $json, LOCK_EX) !== false;
}
function predizione_utente_record(string $u): ?array {
    $utenti = predizione_utenti_leggi();
    return isset($utenti[$u]) && is_array($utenti[$u]) ? $utenti[$u] : null;
}

/* Verifica accesso di un utente (numero + password, e che sia attivo). */
function predizione_auth(string $u, ?string $pass): bool {
    if (!is_string($pass) || $pass === '') return false;
    $rec = predizione_utente_record($u);
    if (!$rec || empty($rec['attivo'])) return false;
    return hash_equals((string)($rec['pass'] ?? ''), $pass);
}
/* Verifica che l'utente sia l'AMMINISTRATORE. */
function predizione_is_admin(string $u, ?string $pass): bool {
    $rec = predizione_utente_record($u);
    return $rec && !empty($rec['admin']) && predizione_auth($u, $pass);
}
/* Interruttori (flags) dell'utente, con valori di default. */
function predizione_flags(string $u): array {
    $rec = predizione_utente_record($u);
    $f = ($rec && isset($rec['flags']) && is_array($rec['flags'])) ? $rec['flags'] : [];
    return ['mail' => !empty($f['mail']), 'foto' => !empty($f['foto'])];
}

/* -------------------- ASSISTENTE DI SCENA (link temporaneo) ------------- */
/* Il performer crea un "lasciapassare" (token) salvato nello stato dell'utente.
   Vale finche' e' presente: lo si cancella alla fine della sessione ("Finisci")
   o con la revoca. Permette i comandi del gioco e la preparazione della
   rivelazione SENZA password e SENZA accedere a impostazioni o report. */
function predizione_assistente_valido(array $stato, ?string $token): bool {
    $t = (string)($stato['assistente_token'] ?? '');
    return $t !== '' && is_string($token) && $token !== '' && hash_equals($t, $token);
}

/* -------------------- STATO DEL GIOCO (per-utente) ---------------------- */
function predizione_stato_default(): array {
    return [
        'foto_a'             => null,
        'rivelazione_pronta' => null,
        'sessione_corrente'  => 0,
        'ultimo_id'          => 0,
        'forza'              => null,
        'autorisponditore'   => false,
        'blocca_foto_a'      => true,   // prima volta: la Foto A resta dopo "Finisci" (spunta accesa)
        'sessioni'           => [],
        'aggiornato'         => null,
    ];
}

function predizione_leggi_stato(): array {
    predizione_prepara_cartella();
    $file = predizione_stato_file();
    if (!is_file($file)) {
        return predizione_stato_default();
    }
    $raw  = @file_get_contents($file);
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
    return @file_put_contents(predizione_stato_file(), $json, LOCK_EX) !== false;
}

/* ---- Uso del mese (per la pagina "I miei limiti" con le barre) --------- */
/* I limiti si ripristinano nel GIORNO dell'abbonamento (es. attivato il 23 ->
   si azzerano ogni 23). Il giorno sta in $stato['giorno_rinnovo'] (1..28);
   se manca, si usa il 1° del mese (vecchio comportamento). */
function predizione_periodo_uso(int $giorno): array {
    $giorno = max(1, min(28, $giorno));
    $y = (int)date('Y'); $m = (int)date('n'); $d = (int)date('j');
    if ($d >= $giorno) { $sm = $m; $sy = $y; } else { $sm = $m - 1; $sy = $y; if ($sm < 1) { $sm = 12; $sy--; } }
    $inizio = mktime(0, 0, 0, $sm, $giorno, $sy);
    $fine   = mktime(0, 0, 0, $sm + 1, $giorno, $sy);   // mktime normalizza il mese 13
    return ['chiave' => date('Y-m-d', $inizio), 'inizio' => $inizio, 'fine' => $fine];
}
function predizione_uso_mese(array &$stato): array {
    $g = (int)($stato['giorno_rinnovo'] ?? 1);
    $per = predizione_periodo_uso($g);
    $oggi = date('Y-m-d');
    if (!isset($stato['uso']) || !is_array($stato['uso']) || (($stato['uso']['mese'] ?? '') !== $per['chiave'])) {
        // nuovo periodo mensile: azzera tutto (compreso il contatore del giorno)
        $stato['uso'] = ['mese' => $per['chiave'], 'mail' => 0, 'foto_ia' => 0, 'sessioni' => 0,
                         'reset_ts' => $per['fine'], 'giorno' => $oggi, 'mail_oggi' => 0, 'sessioni_oggi' => 0];
    } else {
        $stato['uso']['reset_ts'] = $per['fine'];   // prossima ricarica (per mostrarla nel pannello)
    }
    // contatori GIORNALIERI (mail e sessioni): si azzerano al cambio di data
    if (($stato['uso']['giorno'] ?? '') !== $oggi) {
        $stato['uso']['giorno'] = $oggi;
        $stato['uso']['mail_oggi'] = 0;
        $stato['uso']['sessioni_oggi'] = 0;
    }
    return $stato['uso'];
}
function predizione_uso_incr(array &$stato, string $tipo, int $n = 1): void {
    predizione_uso_mese($stato);
    if (array_key_exists($tipo, $stato['uso'])) {
        $stato['uso'][$tipo] = (int)$stato['uso'][$tipo] + $n;
    }
    // mail e sessioni contano anche nel totale del GIORNO (contatori giornalieri)
    if ($tipo === 'mail') {
        $stato['uso']['mail_oggi'] = (int)($stato['uso']['mail_oggi'] ?? 0) + $n;
    }
    if ($tipo === 'sessioni') {
        $stato['uso']['sessioni_oggi'] = (int)($stato['uso']['sessioni_oggi'] ?? 0) + $n;
    }
    // registra il movimento nello storico (consumo = segno meno)
    predizione_movimento($stato, $tipo, -abs($n), 'uso');
}

/* ---- Storico movimenti (per "Mio account": estratto conto per voce) ----- */
/* Ogni riga: {ts, tipo (mail|foto_ia|sessioni), delta (+/-), nota}.
   delta negativo = consumo; positivo = crediti aggiunti o rinnovo piano. */
function predizione_movimento(array &$stato, string $tipo, int $delta, string $nota = ''): void {
    if ($delta === 0) return;
    if (!isset($stato['movimenti']) || !is_array($stato['movimenti'])) $stato['movimenti'] = [];
    $stato['movimenti'][] = ['ts' => time(), 'tipo' => $tipo, 'delta' => $delta, 'nota' => $nota];
    if (count($stato['movimenti']) > 400) {
        $stato['movimenti'] = array_slice($stato['movimenti'], -400);
    }
}

/* ---- Archivio OPERAZIONI admin (attiva / sospendi / elimina / crea) ----- */
/* Registro globale in _dati/operazioni.jsonl: serve all'admin per ricordare
   cosa ha fatto e quando (le righe non si cancellano quando elimini un utente). */
function predizione_operazioni_file(): string { return DATA_DIR . '/operazioni.jsonl'; }
function predizione_log_operazione(string $azione, string $utente, string $dettaglio = ''): void {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    $riga = json_encode(['ts' => time(), 'azione' => $azione, 'utente' => $utente, 'dettaglio' => $dettaglio],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(predizione_operazioni_file(), $riga . "\n", FILE_APPEND | LOCK_EX);
}
function predizione_operazioni_leggi(int $max = 300): array {
    $f = predizione_operazioni_file();
    if (!is_file($f)) return [];
    $righe = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];
    foreach ($righe as $r) { $d = json_decode($r, true); if (is_array($d)) $out[] = $d; }
    $out = array_reverse($out);                 // dal piu' recente
    return array_slice($out, 0, $max);
}

/* Azzera la "Foto base" dello strumento IA (file + stato), cosi' la sezione IA
   riparte pulita a ogni Avvia/Finisci (non resta una foto base "appesa"). */
function predizione_pulisci_foto_base(array &$stato): void {
    $base = predizione_data_dir() . '/foto_base';
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $e) {
        if (is_file($base . '.' . $e)) @unlink($base . '.' . $e);
    }
    $stato['foto_base'] = null;
    $stato['foto_quad'] = null;
    $stato['foto_gomma'] = null;
}

/* ---- PASSWORD D'ACCESSO con ETICHETTA di provenienza (marketing) -------- */
/* Sul sito, dopo aver scelto il gioco, si inserisce una password. Oltre alla
   base "aaa", l'autore puo' crearne altre, ognuna con un'ETICHETTA (es. gruppo1):
   chi si iscrive con quella password si porta l'etichetta nell'anagrafica.
   Archivio in _dati/accessi.json: { "QQQ": {"etichetta":"gruppo1","creato":...}, ... } */
const PRED_ACCESSO_BASE = 'aaa';   // password base, sempre valida (provenienza "diretto")
function predizione_accessi_file(): string { return DATA_DIR . '/accessi.json'; }
function predizione_accessi_leggi(): array {
    $f = predizione_accessi_file();
    if (!is_file($f)) return [];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function predizione_accessi_scrivi(array $a): bool {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    return @file_put_contents(predizione_accessi_file(), json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
/* Verifica una password d'accesso e ritorna l'ETICHETTA (provenienza).
   Ritorna null se la password non e' valida. "aaa" -> "diretto". */
function predizione_accesso_etichetta(?string $password): ?string {
    $p = trim((string)$password);
    if ($p === '') return null;
    if (hash_equals(PRED_ACCESSO_BASE, $p)) return 'diretto';
    $a = predizione_accessi_leggi();
    foreach ($a as $pw => $info) {
        if (hash_equals((string)$pw, $p)) return (string)($info['etichetta'] ?? $pw);
    }
    return null;
}

/* LETTERA di provenienza di una password d'accesso (finisce nel numero ordine).
   base "aaa" (diretto) -> 'a'. Le altre hanno la loro 'lettera' salvata. */
function predizione_accesso_lettera(?string $password): string {
    $p = trim((string)$password);
    if ($p === '' || hash_equals(PRED_ACCESSO_BASE, $p)) return 'a';
    foreach (predizione_accessi_leggi() as $pw => $info) {
        if (hash_equals((string)$pw, $p)) {
            $l = preg_replace('/[^a-z]/', '', strtolower((string)($info['lettera'] ?? '')));
            return $l !== '' ? substr($l, 0, 1) : 'a';
        }
    }
    return 'a';
}

/* Prima LETTERA libera per una nuova provenienza (a = base/diretto, poi b, c, ...). */
function predizione_accesso_lettera_libera(): string {
    $usate = ['a' => true];
    foreach (predizione_accessi_leggi() as $info) {
        $l = substr(preg_replace('/[^a-z]/', '', strtolower((string)($info['lettera'] ?? ''))), 0, 1);
        if ($l !== '') $usate[$l] = true;
    }
    foreach (range('a', 'z') as $c) { if (empty($usate[$c])) return $c; }
    return 'z';
}

/* NUMERO ORDINE mascherato: "p" + numero a 3 cifre scritto AL CONTRARIO, con la
   lettera di provenienza PRIMA dell'ultima cifra. Es. ordine 14, diretto(a) -> p41a0. */
function predizione_codice_ordine(int $n, string $lettera): string {
    $l = preg_replace('/[^a-z]/', '', strtolower($lettera));
    $l = $l !== '' ? substr($l, 0, 1) : 'a';
    $num = str_pad((string)max(0, $n), 3, '0', STR_PAD_LEFT);   // "014"
    $rev = strrev($num);                                         // "410"
    return 'p' . substr($rev, 0, -1) . $l . substr($rev, -1);    // p41a0
}

/* Riconosce le mail AUTOMATICHE (no-reply, newsletter, notifiche, bounce):
   NON sono spettatori, quindi non vanno contate ne' ricevono risposta. */
function predizione_mitt_automatico(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') return false;
    $at = strpos($email, '@');
    $local = $at !== false ? substr($email, 0, $at) : $email;
    $dom   = $at !== false ? substr($email, $at + 1) : '';
    if (preg_match('/(^|[._+-])(no-?reply|no_reply|do-?not-?reply|donotreply|noreply|mailer-daemon|postmaster|bounce|bounces|notification|notifications|notify|newsletter|news|updates?|mailer|automated|auto-confirm)([._+-]|$)/', $local)) {
        return true;
    }
    foreach (['mailgun.com','mailgun.org','sendgrid.net','mailchimp.com','mcsv.net','sparkpostmail.com','amazonses.com','customer.io','sendinblue.com','brevo.com'] as $d) {
        if ($dom === $d || substr($dom, -(strlen($d) + 1)) === '.' . $d) return true;
    }
    return false;
}

function predizione_sessione_corrente(array $stato): ?array {
    $id = (int)($stato['sessione_corrente'] ?? 0);
    if ($id <= 0) return null;
    $s = $stato['sessioni'][(string)$id] ?? null;
    return is_array($s) ? $s : null;
}

/* Confronto password a tempo costante (compatibilita'; non piu' usato per l'accesso). */
function predizione_password_ok(?string $inserita): bool {
    if (!is_string($inserita) || $inserita === '') return false;
    return hash_equals(PREDIZIONE_PASSWORD, $inserita);
}

/* ---- APERTURE VERE (tolte le "aperture di servizio") --------------------
   Gmail e alcuni client SCARICANO la foto da soli appena la mail arriva, e la
   ri-scaricano piu' volte per una sola apertura (per via degli header anti-cache).
   Per contare le aperture VERE togliamo:
   - gli scaricamenti nei primi PRED_APERTURA_GRACE secondi dall'ARRIVO della mail
     (il caricamento automatico del client);
   - i ri-scaricamenti ravvicinati della stessa persona (debounce PRED_APERTURA_GRACE s).
   Ritorna: [ id => ['n'=>N, 'times'=>[...], 'labels'=>['A'=>bool,'B'=>bool], 'ua'=>str, 'first'=>str] ].
   Gli id SENZA alcuna apertura vera NON compaiono. */
const PRED_APERTURA_GRACE = 15;   // secondi

/* Orario (timestamp) di ARRIVO della mail, per ciascun id, da mail_sess_N.jsonl. */
function predizione_mail_ricevute_ts(int $sessione): array {
    $out = [];
    $f = predizione_data_dir() . '/mail_sess_' . $sessione . '.jsonl';
    if (!is_file($f)) return $out;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $r = json_decode($ln, true); if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? ''); if ($id === '') continue;
        $ts = strtotime((string)($r['ora'] ?? '')); if ($ts === false) continue;
        if (!isset($out[$id]) || $ts < $out[$id]) $out[$id] = $ts;   // il piu' antico
    }
    return $out;
}

function predizione_aperture_vere(int $sessione): array {
    $logf = predizione_log_file();
    if ($sessione <= 0 || !is_file($logf)) return [];
    $ric = predizione_mail_ricevute_ts($sessione);
    $grezze = [];
    foreach (file($logf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $c = str_getcsv($line);
        if ((string)($c[1] ?? '') !== (string)$sessione) continue;
        $lbl = strtoupper(substr((string)($c[3] ?? ''), 0, 1));
        if ($lbl !== 'A' && $lbl !== 'B') continue;   // neutro/test non contano
        $id  = trim((string)($c[2] ?? ''));
        $ts  = strtotime((string)($c[0] ?? '')); if ($ts === false) continue;
        $key = $id !== '' ? $id : ('_r' . count($grezze));
        $grezze[$key][] = ['ts' => $ts, 'lbl' => $lbl, 'ua' => (string)($c[5] ?? '')];
    }
    $out = [];
    foreach ($grezze as $id => $rows) {
        usort($rows, function ($a, $b) { return $a['ts'] - $b['ts']; });
        $ricTs = $ric[$id] ?? 0;
        $vere = []; $last = 0;
        foreach ($rows as $r) {
            if ($ricTs > 0 && $r['ts'] <= $ricTs + PRED_APERTURA_GRACE) continue;  // 1) primi 15s = servizio
            if ($last  > 0 && $r['ts'] <= $last  + PRED_APERTURA_GRACE) continue;  // 2) debounce 15s
            $vere[] = $r; $last = $r['ts'];
        }
        if (!$vere) continue;
        $labels = ['A' => false, 'B' => false]; $times = [];
        foreach ($vere as $v) { $labels[$v['lbl']] = true; $times[] = date('Y-m-d H:i:s', $v['ts']); }
        $out[$id] = ['n' => count($vere), 'times' => $times, 'labels' => $labels,
                     'ua' => $vere[count($vere) - 1]['ua'], 'first' => $times[0]];
    }
    return $out;
}

/* Conta le aperture registrate nel log dell'utente corrente (righe del CSV). */
function predizione_conta_aperture(): int {
    $file = predizione_log_file();
    if (!is_file($file)) return 0;
    $n = 0;
    $fh = @fopen($file, 'r');
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
