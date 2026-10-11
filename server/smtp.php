<?php
/* =========================================================================
   PREDIZIONE — invio SMTP minimo (STARTTLS + AUTH LOGIN)
   -------------------------------------------------------------------------
   Serve per l'INVIO MANUALE a pochi indirizzi, direttamente dalla casella
   dell'utente (es. Gmail con password per app). Nessuna libreria esterna.
   Ritorna true/false; in caso di errore riempie $err con il motivo.
   ========================================================================= */

function pred_smtp_send(string $host, int $port, string $user, string $pass,
                        string $from, string $fromName, string $to,
                        string $subject, string $htmlBody, ?string &$err = null): bool {
    $err = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("tcp://$host:$port", $eno, $estr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connessione a $host:$port fallita ($estr)"; return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;   // ultima riga della risposta
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    $read();                       // saluto del server
    $cmd("EHLO abraka.it");
    $r = $cmd("STARTTLS");
    if (($r[0] ?? '') !== '2') { $err = "STARTTLS rifiutato: " . trim($r); fclose($fp); return false; }
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
        $err = "attivazione TLS fallita"; fclose($fp); return false;
    }
    $cmd("EHLO abraka.it");
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
    $headers = "From: $fromHdr\r\n"
             . "To: <$to>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    // protezione: righe che iniziano con "." vanno raddoppiate
    $body = preg_replace('/^\./m', '..', $htmlBody);
    fwrite($fp, $headers . $body . "\r\n.\r\n");
    $r = $read();
    if (($r[0] ?? '') !== '2') { $err = "invio non accettato: " . trim($r); fclose($fp); return false; }

    $cmd("QUIT");
    fclose($fp);
    return true;
}

/* Deduce host/porta SMTP dall'indirizzo della casella. */
function pred_smtp_host(string $email): array {
    $dom = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($dom === 'gmail.com' || $dom === 'googlemail.com') return ['smtp.gmail.com', 587];
    // caselle del dominio abraka/Tophost
    return ['mail.tophost.it', 587];
}
