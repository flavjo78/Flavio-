<?php
// ============================================================================
// APP FERIE - invio email di notifica via SMTP (nessuna libreria esterna)
//
// Le impostazioni stanno in avvio/ferie_config.json (si modificano dalla scheda
// "Ferie" della dashboard, cosi' le usano sia l'app sia la dashboard):
// {"attiva": true, "smtp_host": "...", "smtp_porta": 465,
//  "smtp_sicurezza": "ssl" | "tls" | "nessuna", "smtp_utente": "...",
//  "smtp_password": "...", "mittente": "ferie@azienda.it",
//  "mittente_nome": "Ferie Azienda", "destinatari_admin": ["ufficio@azienda.it"],
//  "verifica_certificato": true, "url_app": "..."}
//
// Un errore nell'invio NON deve mai bloccare una richiesta: le funzioni
// ritornano [ok, messaggio] e non lanciano eccezioni.
// ============================================================================

require_once __DIR__ . '/lib.php';

function impostazioni_mail()
{
    if (isset($GLOBALS['MAIL_TEST'])) return $GLOBALS['MAIL_TEST'];
    return leggi_json(DATI_DIR . '/ferie_config.json', []);
}

function _smtp_leggi($fp)
{
    $risposta = '';
    while (($riga = fgets($fp, 1024)) !== false) {
        $risposta .= $riga;
        if (strlen($riga) < 4 || $riga[3] === ' ') break;
    }
    return $risposta;
}

function _smtp_cmd($fp, $comando, $attesi)
{
    if ($comando !== null) fwrite($fp, $comando . "\r\n");
    $r = _smtp_leggi($fp);
    if (!in_array((int)substr($r, 0, 3), $attesi, true)) {
        throw new Exception('Risposta SMTP inattesa: ' . trim($r));
    }
    return $r;
}

function _codifica_intestazione($testo)
{
    return '=?UTF-8?B?' . base64_encode($testo) . '?=';
}

/** Invia una mail di testo. Ritorna [true, ''] oppure [false, 'motivo']. */
function invia_mail(array $destinatari, $oggetto, $testo)
{
    $c = impostazioni_mail();
    if (empty($c['attiva'])) return [false, 'Notifiche email non attive.'];
    $destinatari = array_values(array_filter(array_map('trim', $destinatari), function ($d) {
        return filter_var($d, FILTER_VALIDATE_EMAIL);
    }));
    if (!$destinatari) return [false, 'Nessun destinatario valido.'];
    if (empty($c['smtp_host']) || empty($c['mittente'])) return [false, 'Server o mittente non impostati.'];

    $sic = $c['smtp_sicurezza'] ?? 'ssl';
    $porta = (int)($c['smtp_porta'] ?? ($sic === 'ssl' ? 465 : 587));
    $verifica = !array_key_exists('verifica_certificato', $c) || !empty($c['verifica_certificato']);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verifica, 'verify_peer_name' => $verifica, 'allow_self_signed' => !$verifica]]);
    $fp = null;
    try {
        $indirizzo = ($sic === 'ssl' ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $porta;
        $fp = @stream_socket_client($indirizzo, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new Exception("Connessione al server di posta non riuscita ($errstr).");
        stream_set_timeout($fp, 10);
        _smtp_cmd($fp, null, [220]);
        $ehlo = 'ferie.local';
        _smtp_cmd($fp, "EHLO $ehlo", [250]);
        if ($sic === 'tls') {
            _smtp_cmd($fp, 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('Connessione protetta (TLS) non riuscita.');
            _smtp_cmd($fp, "EHLO $ehlo", [250]);
        }
        if (!empty($c['smtp_utente'])) {
            _smtp_cmd($fp, 'AUTH LOGIN', [334]);
            _smtp_cmd($fp, base64_encode($c['smtp_utente']), [334]);
            _smtp_cmd($fp, base64_encode((string)($c['smtp_password'] ?? '')), [235]);
        }
        _smtp_cmd($fp, 'MAIL FROM:<' . $c['mittente'] . '>', [250]);
        foreach ($destinatari as $d) _smtp_cmd($fp, 'RCPT TO:<' . $d . '>', [250, 251]);
        _smtp_cmd($fp, 'DATA', [354]);

        $nome_mitt = trim((string)($c['mittente_nome'] ?? ''));
        $da = ($nome_mitt !== '' ? _codifica_intestazione($nome_mitt) . ' ' : '') . '<' . $c['mittente'] . '>';
        $intest = [
            'From: ' . $da,
            'To: ' . implode(', ', $destinatari),
            'Subject: ' . _codifica_intestazione($oggetto),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@ferie.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $corpo = chunk_split(base64_encode($testo), 76, "\r\n");
        // Il corpo e' in base64: non puo' contenere righe con un solo punto.
        fwrite($fp, implode("\r\n", $intest) . "\r\n\r\n" . $corpo . "\r\n.\r\n");
        _smtp_cmd($fp, null, [250]);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [true, ''];
    } catch (Throwable $e) {
        if (is_resource($fp)) @fclose($fp);
        return [false, $e->getMessage()];
    }
}

function _testo_periodo($r)
{
    $t = data_it($r['dal']);
    if ($r['al'] !== $r['dal']) $t .= ' - ' . data_it($r['al']);
    if (!empty($r['mezza_giornata'])) $t .= ' (mezza giornata, ' . $r['mezza_giornata'] . ')';
    return $t . ' = ' . num_it($r['giorni_lavorativi']) . ' giorni lavorativi';
}

/** Avvisa l'ufficio di una nuova richiesta o di un annullamento. */
function notifica_admin($rich, $evento)
{
    $c = impostazioni_mail();
    $dest = is_array($c['destinatari_admin'] ?? null) ? $c['destinatari_admin'] : [];
    if ($evento === 'nuova') {
        $ogg = 'Nuova richiesta ferie: ' . $rich['nome'];
        $txt = $rich['nome'] . " ha chiesto ferie.\n\nPeriodo: " . _testo_periodo($rich)
            . ($rich['nota'] !== '' ? "\nNota: " . $rich['nota'] : '')
            . "\n\nApri la scheda \"Ferie\" della dashboard per approvare o rifiutare.\n";
    } else {
        $ogg = 'Richiesta ferie annullata: ' . $rich['nome'];
        $txt = $rich['nome'] . " ha annullato la richiesta di ferie.\n\nPeriodo: " . _testo_periodo($rich) . "\n";
    }
    return invia_mail($dest, $ogg, $txt);
}
