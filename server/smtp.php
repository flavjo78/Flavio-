<?php
/* =========================================================================
   PREDIZIONE — invio SMTP minimo (STARTTLS 587, con ripiego SSL 465)
   -------------------------------------------------------------------------
   Per l'INVIO MANUALE a pochi indirizzi dalla casella dell'utente (es. Gmail
   con password per app). Nessuna libreria esterna. Ritorna true/false; in
   caso di errore riempie $err. Prova prima 587 (STARTTLS); se la connessione
   o lo STARTTLS falliscono, riprova su 465 (SSL diretto): alcuni hosting
   bloccano una porta ma non l'altra.
   ========================================================================= */

function pred_smtp_try(string $host, int $port, bool $sslDiretto,
                       string $user, string $pass, string $from, string $fromName,
                       string $to, string $subject, string $htmlBody, ?string &$err): bool {
    $err = '';
    $url = ($sslDiretto ? 'ssl://' : 'tcp://') . "$host:$port";
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($url, $eno, $estr, 18, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connessione a $host:$port fallita ($estr)"; return false; }
    stream_set_timeout($fp, 18);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    $read();
    $cmd("EHLO abraka.it");
    if (!$sslDiretto) {   // porta 587: serve STARTTLS
        $r = $cmd("STARTTLS");
        if (($r[0] ?? '') !== '2') { $err = "STARTTLS rifiutato: " . trim($r); fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            $err = "attivazione TLS fallita"; fclose($fp); return false;
        }
        $cmd("EHLO abraka.it");
    }
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if (($r[0] ?? '') !== '2') { $err = "login casella fallito (utente o password per app errati)"; fclose($fp); return false; }

    $r = $cmd("MAIL FROM:<$from>");
    if (($r[0] ?? '') !== '2') { $err = "MAIL FROM rifiutato: " . trim($r); fclose($fp); return false; }
    $r = $cmd("RCPT TO:<$to>");
    if (($r[0] ?? '') !== '2') { $err = "destinatario rifiutato: " . trim($r); fclose($fp); return false; }
    $r = $cmd("DATA");
    if (($r[0] ?? '') !== '3') { $err = "DATA rifiutato: " . trim($r); fclose($fp); return false; }

    $fromHdr = ($fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= ' : '') . "<$from>";
    $headers = "From: $fromHdr\r\nTo: <$to>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $body = preg_replace('/^\./m', '..', $htmlBody);
    fwrite($fp, $headers . $body . "\r\n.\r\n");
    $r = $read();
    if (($r[0] ?? '') !== '2') { $err = "invio non accettato: " . trim($r); fclose($fp); return false; }
    $cmd("QUIT");
    fclose($fp);
    return true;
}

function pred_smtp_send(string $host, int $port, string $user, string $pass,
                        string $from, string $fromName, string $to,
                        string $subject, string $htmlBody, ?string &$err = null): bool {
    // 1) STARTTLS sulla porta data (di solito 587)
    if (pred_smtp_try($host, $port, false, $user, $pass, $from, $fromName, $to, $subject, $htmlBody, $err)) return true;
    $err1 = $err;
    // 2) ripiego: SSL diretto sulla 465 (se l'hosting blocca la 587)
    if (pred_smtp_try($host, 465, true, $user, $pass, $from, $fromName, $to, $subject, $htmlBody, $err)) return true;
    $err = 'invio non riuscito. ' . $err1 . ' / (465) ' . $err
         . ' — se riprova sempre, l\'hosting potrebbe bloccare l\'invio: in tal caso usa il livello Palco.';
    return false;
}

/* Deduce host/porta SMTP dall'indirizzo della casella. */
function pred_smtp_host(string $email): array {
    $dom = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($dom === 'gmail.com' || $dom === 'googlemail.com') return ['smtp.gmail.com', 587];
    return ['mail.tophost.it', 587];
}

/* Deduce host/porta IMAP (lettura) dall'indirizzo della casella.
   Gmail -> imap.gmail.com; dominio (Tophost) -> pop.tophost.it (stesso host del
   robottino di casa). Porta 993 (SSL) in entrambi i casi. */
function pred_imap_host(string $email): array {
    $dom = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($dom === 'gmail.com' || $dom === 'googlemail.com') return ['imap.gmail.com', 993];
    return ['pop.tophost.it', 993];
}

/* TEST della LETTURA (IMAP): prova a entrare nella casella (quello che fa il
   robottino). Prova i vari login finché uno entra. Richiede l'estensione php-imap:
   se manca, ritorna 'skip'=>true (la lettura la potrà verificare solo il robottino).
   Ritorna ['ok'=>bool, 'login'=>string|null, 'err'=>string, 'skip'=>bool]. */
function pred_imap_test(string $host, int $port, array $logins, string $pass): array {
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'login' => null, 'skip' => true,
                'err' => 'IMAP non testabile da questo server (estensione php-imap non attiva): la lettura la verifica il robottino.'];
    }
    // timeout brevi: se un host non risponde, il tasto non resta appeso
    if (function_exists('imap_timeout')) { @imap_timeout(IMAP_OPENTIMEOUT, 12); @imap_timeout(IMAP_READTIMEOUT, 12); }
    $mbox = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}INBOX';
    $ultimo = '';
    foreach ($logins as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        $im = @imap_open($mbox, $u, $pass, 0, 1);
        if ($im !== false) { @imap_close($im); @imap_errors(); @imap_alerts(); return ['ok' => true, 'login' => $u, 'skip' => false, 'err' => '']; }
        $e = @imap_last_error(); if ($e) $ultimo = $e;
        @imap_errors(); @imap_alerts();   // svuota la coda errori per il tentativo dopo
    }
    return ['ok' => false, 'login' => null, 'skip' => false, 'err' => ($ultimo ?: 'login IMAP non riuscito')];
}

/* TEST COMPLETO della casella: LETTURA (IMAP) + INVIO (SMTP, manda una prova a
   $dest). $m = config casella {user, pass, login?, from?}. Ritorna un riepilogo
   leggibile con l'esito dei due controlli (per il tasto "Prova casella"). */
function pred_testa_casella(array $m, string $dest = 'prova@abraka.it'): array {
    $cUser = (string)($m['user'] ?? '');
    $cPass = (string)($m['pass'] ?? '');
    $cFrom = (string)($m['from'] ?? '');
    $cLogin= (string)($m['login'] ?? '');
    if ($cUser === '' || $cPass === '') {
        return ['ok' => false, 'errore' => 'Casella non configurata (manca indirizzo o password).'];
    }
    // ordine login: manuale (se c'è) -> indirizzo intero -> nome mailbox (parte dopo @)
    $logins = [];
    if ($cLogin !== '') $logins[] = $cLogin;
    $logins[] = $cUser;
    $posAt = strpos($cUser, '@'); if ($posAt !== false) { $d = substr($cUser, $posAt + 1); if ($d !== '') $logins[] = $d; }
    $logins = array_values(array_unique(array_filter($logins)));

    [$ih, $ip] = pred_imap_host($cUser);
    $imap = pred_imap_test($ih, $ip, $logins, $cPass);

    [$sh, $sp] = pred_smtp_host($cUser);
    $smtpLogin = null; $smtpErr = null;
    foreach ($logins as $u) {
        $e = null;
        $corpo = '<p>Prova automatica PREDIZIONE: la casella <b>' . htmlspecialchars($cUser, ENT_QUOTES, 'UTF-8') . '</b> funziona in INVIO.</p>';
        if (pred_smtp_send($sh, $sp, $u, $cPass, $cUser, $cFrom, $dest, 'Prova casella — PREDIZIONE', $corpo, $e)) { $smtpLogin = $u; break; }
        $smtpErr = $e;
    }
    return [
        'ok'   => true,
        'dest' => $dest,
        'imap' => ['ok' => (bool)$imap['ok'], 'login' => $imap['login'], 'err' => (string)$imap['err'],
                   'skip' => !empty($imap['skip']), 'host' => $ih . ':' . $ip],
        'smtp' => ['ok' => ($smtpLogin !== null), 'login' => $smtpLogin, 'err' => (string)($smtpErr ?? ''),
                   'host' => $sh . ':' . $sp],
    ];
}

/* INVIO IN BLOCCO (una sola connessione, tante mail): serve per il TEST del
   robottino (riempire una casella). Apre UNA connessione, fa login una volta e
   spedisce N messaggi di fila -> velocissimo, niente timeout. Prova 587
   (STARTTLS); se fallisce, 465 (SSL). Ritorna ['inviate'=>, 'errori'=>, 'note'=>[]]. */
function pred_smtp_invia_blocco(string $host, int $port, string $user, string $pass,
                                string $from, string $fromName, string $to, int $n): array {
    $inviate = 0; $errori = 0; $note = [];
    $stamp = date('His');
    foreach ([[(int)($port ?: 587), false], [465, true]] as [$pTry, $ssl]) {
        if ($port === 465) { $pTry = 465; $ssl = true; }
        $url = ($ssl ? 'ssl://' : 'tcp://') . "$host:$pTry";
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($url, $eno, $estr, 18, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { $note[] = "connessione $host:$pTry fallita ($estr)"; continue; }
        stream_set_timeout($fp, 20);
        $read = function () use ($fp) { $d=''; while (($l=fgets($fp,515))!==false){$d.=$l; if(strlen($l)>=4&&$l[3]===' ')break;} return $d; };
        $cmd  = function (string $c) use ($fp, $read) { fwrite($fp, $c."\r\n"); return $read(); };
        $read();
        $cmd("EHLO abraka.it");
        if (!$ssl) {
            $r = $cmd("STARTTLS");
            if (($r[0] ?? '') !== '2' || !@stream_socket_enable_crypto($fp, true,
                 STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                $note[] = "STARTTLS su $pTry fallito"; fclose($fp); continue;
            }
            $cmd("EHLO abraka.it");
        }
        $cmd("AUTH LOGIN"); $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (($r[0] ?? '') !== '2') { $note[] = "login casella fallito (utente o password per app errati)"; fclose($fp); break; }
        $fromHdr = ($fromName !== '' ? '=?UTF-8?B?'.base64_encode($fromName).'?= ' : '') . "<$from>";
        for ($i = 1; $i <= $n; $i++) {
            $r = $cmd("MAIL FROM:<$from>");
            if (($r[0] ?? '') !== '2') { $errori++; if(count($note)<6)$note[]="#$i MAIL FROM: ".trim($r); $cmd("RSET"); continue; }
            $r = $cmd("RCPT TO:<$to>");
            if (($r[0] ?? '') !== '2') { $errori++; if(count($note)<6)$note[]="#$i destinatario: ".trim($r); $cmd("RSET"); continue; }
            $r = $cmd("DATA");
            if (($r[0] ?? '') !== '3') { $errori++; if(count($note)<6)$note[]="#$i DATA: ".trim($r); $cmd("RSET"); continue; }
            $sub  = "Prova PREDIZIONE #$i-$stamp";
            $body = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">'
                  . "<p>Mail di prova numero <b>$i</b> (infornata $stamp).</p>"
                  . '<p>Serve a collaudare il robottino: non e\' una mail vera.</p></div>';
            $hdr  = "From: $fromHdr\r\nTo: <$to>\r\n"
                  . "Subject: =?UTF-8?B?".base64_encode($sub)."?=\r\n"
                  . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
            fwrite($fp, $hdr . preg_replace('/^\./m', '..', $body) . "\r\n.\r\n");
            $r = $read();
            if (($r[0] ?? '') === '2') { $inviate++; } else { $errori++; if(count($note)<6)$note[]="#$i invio: ".trim($r); }
        }
        $cmd("QUIT"); fclose($fp);
        break;
    }
    return ['inviate' => $inviate, 'errori' => $errori, 'note' => $note];
}
