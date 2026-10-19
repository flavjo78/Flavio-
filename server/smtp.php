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

/* ===== LETTURA via IMAP "a mano" (socket SSL), SENZA l'estensione php-imap =====
   Tophost spesso non ha php-imap: così possiamo verificare DAVVERO la lettura
   (quello che fa il robottino) aprendo noi una connessione ssl://host:993,
   facendo LOGIN e, se serve, cercando una mail nell'INBOX. */

/* manda un comando "aN COMANDO" e legge finché torna la riga taggata "aN ...".
   Ritorna ['stato'=>'OK'|'NO'|'BAD'|'', 'righe'=>[...]]. $seq è il contatore dei tag. */
function pred_imap_raw_cmd($fp, string $cmd, int &$seq): array {
    $seq++;
    $tag = 'a' . $seq;
    @fwrite($fp, $tag . ' ' . $cmd . "\r\n");
    $righe = []; $stato = '';
    $fine = time() + 15;
    while (time() < $fine) {
        $l = @fgets($fp, 8192);
        if ($l === false) break;
        $righe[] = rtrim($l, "\r\n");
        if (strncmp($l, $tag . ' ', strlen($tag) + 1) === 0) {
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)/i', $l, $mm)) $stato = strtoupper($mm[1]);
            break;
        }
        $m = @stream_get_meta_data($fp); if (!empty($m['timed_out'])) break;
    }
    return ['stato' => $stato, 'righe' => $righe];
}

/* estrae un motivo leggibile da una risposta NO/BAD del server IMAP */
function pred_imap_raw_motivo(array $righe): string {
    $ult = '';
    foreach ($righe as $r) { if (preg_match('/\b(?:NO|BAD)\b\s*(.*)$/i', $r, $m) && trim($m[1]) !== '') $ult = trim($m[1]); }
    return $ult !== '' ? $ult : 'login IMAP rifiutato';
}

/* apre la connessione IMAP e fa LOGIN. Ritorna il socket (da chiudere con LOGOUT)
   oppure null + $err. $seq azzerato e usato per i comandi successivi. */
function pred_imap_raw_apri(string $host, int $port, string $user, string $pass, ?string &$err, int &$seq) {
    $err = null; $seq = 0;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client("ssl://$host:$port", $eno, $estr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connessione a $host:$port fallita ($estr)"; return null; }
    stream_set_timeout($fp, 15);
    @fgets($fp, 4096);   // saluto "* OK ..."
    $u = str_replace(['\\', '"'], ['\\\\', '\\"'], $user);
    $p = str_replace(['\\', '"'], ['\\\\', '\\"'], $pass);
    $r = pred_imap_raw_cmd($fp, 'LOGIN "' . $u . '" "' . $p . '"', $seq);
    if ($r['stato'] !== 'OK') { $err = pred_imap_raw_motivo($r['righe']); @fclose($fp); return null; }
    return $fp;
}

/* conta quante mail dell'INBOX hanno $needle nell'oggetto (-1 = non ho potuto cercare) */
function pred_imap_raw_conta_oggetto($fp, string $needle, int &$seq): int {
    $nd = str_replace(['\\', '"'], ['\\\\', '\\"'], $needle);
    $r = pred_imap_raw_cmd($fp, 'UID SEARCH HEADER SUBJECT "' . $nd . '"', $seq);
    if ($r['stato'] !== 'OK') return -1;
    $n = 0;
    foreach ($r['righe'] as $x) {
        if (preg_match('/^\*\s+SEARCH\b(.*)$/i', $x, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $t) { if ($t !== '' && ctype_digit($t)) $n++; }
        }
    }
    return $n;
}

/* elenco degli UID delle mail in INBOX ricevute DA un certo mittente */
function pred_imap_raw_uids_from($fp, string $fromAddr, int &$seq): array {
    $nd = str_replace(['\\', '"'], ['\\\\', '\\"'], $fromAddr);
    $r = pred_imap_raw_cmd($fp, 'UID SEARCH FROM "' . $nd . '"', $seq);
    $out = [];
    if ($r['stato'] !== 'OK') return $out;
    foreach ($r['righe'] as $x) {
        if (preg_match('/^\*\s+SEARCH\b(.*)$/i', $x, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $t) { if ($t !== '' && ctype_digit($t)) $out[] = (int)$t; }
        }
    }
    return $out;
}

/* ===== Casella "SPETTATORE" di prova (per la DIAGNOSTICA AUTOMATICA) ==========
   Serve una casella neutra (NON una casella-gioco) che manda la mail di prova e
   poi rilegge la risposta del robottino. I login da provare (Tophost/Gmail). */
function pred_prova_logins(array $pv): array {
    $u = (string)($pv['user'] ?? '');
    $logins = [];
    if (!empty($pv['login'])) $logins[] = (string)$pv['login'];
    if ($u !== '') {
        $logins[] = $u;
        $at = strpos($u, '@');
        if ($at !== false) { $logins[] = substr($u, 0, $at); $d = substr($u, $at + 1); if ($d !== '') $logins[] = $d; }
    }
    return array_values(array_unique(array_filter($logins)));
}

/* Manda una mail DALLA casella spettatore. Ritorna ['ok'=>bool,'login'=>?,'err'=>string]. */
function pred_prova_manda(array $pv, string $to, string $subject, string $bodyHtml): array {
    [$h, $p] = pred_smtp_host((string)($pv['user'] ?? ''));
    $pass = (string)($pv['pass'] ?? '');
    $err = null;
    foreach (pred_prova_logins($pv) as $login) {
        $e = null;
        if (pred_smtp_send($h, $p, $login, $pass, (string)$pv['user'], 'Spettatore di prova', $to, $subject, $bodyHtml, $e)) {
            return ['ok' => true, 'login' => $login, 'err' => ''];
        }
        $err = $e;
    }
    return ['ok' => false, 'login' => null, 'err' => (string)$err];
}

/* Legge dalla casella spettatore gli UID delle mail ricevute DA $fromAddr (la
   casella-gioco). Ritorna l'array di uid, oppure null se non riesce a entrare. */
function pred_prova_uids_da(array $pv, string $fromAddr): ?array {
    [$h, $p] = pred_imap_host((string)($pv['user'] ?? ''));
    $pass = (string)($pv['pass'] ?? '');
    foreach (pred_prova_logins($pv) as $login) {
        $err = null; $seq = 0;
        $fp = pred_imap_raw_apri($h, $p, $login, $pass, $err, $seq);
        if (!$fp) continue;
        $sel = pred_imap_raw_cmd($fp, 'SELECT INBOX', $seq);
        if ($sel['stato'] !== 'OK') { pred_imap_raw_cmd($fp, 'LOGOUT', $seq); @fclose($fp); continue; }
        $uids = pred_imap_raw_uids_from($fp, $fromAddr, $seq);
        pred_imap_raw_cmd($fp, 'LOGOUT', $seq); @fclose($fp);
        return $uids;
    }
    return null;
}

/* TEST della LETTURA (IMAP): prova a entrare nella casella (quello che fa il
   robottino). Prova i vari login finché uno entra.
   Ritorna ['ok'=>bool, 'login'=>string|null, 'err'=>string, 'skip'=>false]. */
function pred_imap_test(string $host, int $port, array $logins, string $pass): array {
    $ultimo = '';
    foreach ($logins as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        $err = null; $seq = 0;
        $fp = pred_imap_raw_apri($host, $port, $u, $pass, $err, $seq);
        if ($fp) { pred_imap_raw_cmd($fp, 'LOGOUT', $seq); @fclose($fp); return ['ok' => true, 'login' => $u, 'skip' => false, 'err' => '']; }
        if ($err) $ultimo = $err;
    }
    return ['ok' => false, 'login' => null, 'skip' => false, 'err' => ($ultimo ?: 'login IMAP non riuscito')];
}

/* TEST SERIO E COMPLETO della casella — becca anche la "password di un ALTRO
   indirizzo". $m = config casella {user, pass, login?, from?}. Come funziona:
   1) MANDA una mail di verifica (con un codice unico) ALLA CASELLA STESSA;
   2) ENTRA nella casella (IMAP, login con l'indirizzo scritto) e CERCA quel codice.
   Tre esiti:
   - login IMAP fallito        -> la password NON è di questo indirizzo / IMAP spento;
   - login ok ma codice ASSENTE -> la casella invia/legge da un ALTRO account
     (la mail è finita in un'altra casella): password di un altro indirizzo;
   - login ok e codice PRESENTE -> tutto coerente (invia e legge dallo stesso indirizzo).
   Ritorna un riepilogo leggibile con 'problema' (true/false) e 'diagnosi' a parole. */
function pred_testa_casella(array $m, string $dest = ''): array {
    @set_time_limit(60);
    $cUser = trim((string)($m['user'] ?? ''));
    $cPass = (string)($m['pass'] ?? '');
    $cFrom = (string)($m['from'] ?? '');
    $cLogin= trim((string)($m['login'] ?? ''));
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
    [$sh, $sp] = pred_smtp_host($cUser);

    // codice unico di questa verifica (va nell'oggetto della mail di prova)
    $token   = 'VRF' . strtoupper(bin2hex(random_bytes(4)));
    $oggetto = 'PREDIZIONE verifica ' . $token;
    $corpo   = '<p>Verifica automatica PREDIZIONE (' . $token . '). Se la leggi, la casella <b>'
             . htmlspecialchars($cUser, ENT_QUOTES, 'UTF-8') . '</b> invia e legge correttamente.</p>';

    // 1) INVIO della mail di verifica ALLA CASELLA STESSA (prova i vari login)
    $smtpLogin = null; $smtpErr = null;
    foreach ($logins as $u) {
        $e = null;
        if (pred_smtp_send($sh, $sp, $u, $cPass, $cUser, $cFrom, $cUser, $oggetto, $corpo, $e)) { $smtpLogin = $u; break; }
        $smtpErr = $e;
    }

    // 2) LETTURA (IMAP) + VERIFICA IDENTITÀ: entra e cerca il codice nell'INBOX
    $imapOk = false; $imapLogin = null; $imapErr = ''; $ultimo = '';
    $identita = null; $identitaNota = '';   // true=trovato, false=assente, null=non verificabile
    foreach ($logins as $u) {
        $err = null; $seq = 0;
        $fp = pred_imap_raw_apri($ih, $ip, $u, $cPass, $err, $seq);
        if (!$fp) { if ($err) $ultimo = $err; continue; }
        $imapOk = true; $imapLogin = $u;
        if ($smtpLogin !== null) {
            $sel = pred_imap_raw_cmd($fp, 'SELECT INBOX', $seq);
            if ($sel['stato'] === 'OK') {
                $trov = -1;
                for ($t = 0; $t < 5; $t++) {   // la mail può metterci qualche secondo
                    $trov = pred_imap_raw_conta_oggetto($fp, $token, $seq);
                    if ($trov > 0) break;
                    if ($t < 4) sleep(3);
                }
                if ($trov > 0)       { $identita = true; }
                elseif ($trov === 0) { $identita = false; $identitaNota = 'la mail di verifica NON è arrivata in questa casella'; }
                else                 { $identita = null;  $identitaNota = 'non ho potuto leggere la posta in arrivo'; }
            } else {
                $identita = null; $identitaNota = 'non riesco ad aprire la posta in arrivo';
            }
        }
        pred_imap_raw_cmd($fp, 'LOGOUT', $seq); @fclose($fp);
        break;
    }
    if (!$imapOk) $imapErr = ($ultimo ?: 'login IMAP non riuscito');

    // VERDETTO + DIAGNOSI a parole semplici
    $problema = false; $diagnosi = '';
    if (!$imapOk) {
        $problema = true;
        $diagnosi = 'Il robottino NON riesce a LEGGERE la casella ' . $cUser . '. Quasi sempre vuol dire: '
                  . 'la "password per le app" NON è di questo indirizzo (o è sbagliata di una lettera), '
                  . 'oppure IMAP è spento in questa casella.';
    } elseif ($identita === false) {
        $problema = true;
        $diagnosi = 'ATTENZIONE: la casella è scritta come ' . $cUser . ' ma in realtà invia e legge da un ALTRO '
                  . 'account (la "password per le app" è di un altro indirizzo). Rigenera la password per le app '
                  . 'DENTRO ' . $cUser . ' e rimettila qui.';
    } elseif ($smtpLogin === null) {
        $problema = true;
        $diagnosi = 'Il robottino riesce a leggere ma NON a INVIARE da ' . $cUser . ': ' . ($smtpErr ?: 'invio rifiutato') . '.';
    } elseif ($identita === true) {
        $diagnosi = 'Tutto a posto: il robottino legge e invia dallo stesso indirizzo (' . $cUser . ').';
    } else {
        $diagnosi = 'Accesso e invio OK, ma non ho potuto confermare al 100% la lettura (' . $identitaNota . '). '
                  . 'Riprova fra un minuto.';
    }

    return [
        'ok'       => true,
        'problema' => $problema,
        'diagnosi' => $diagnosi,
        'indirizzo'=> $cUser,
        'dest'     => $cUser,
        'imap'     => ['ok' => $imapOk, 'login' => $imapLogin, 'err' => $imapErr, 'skip' => false, 'host' => $ih . ':' . $ip],
        'smtp'     => ['ok' => ($smtpLogin !== null), 'login' => $smtpLogin, 'err' => (string)($smtpErr ?? ''), 'host' => $sh . ':' . $sp],
        'identita' => ['ok' => $identita, 'nota' => $identitaNota],
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
