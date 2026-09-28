<?php
/* =========================================================================
   PREDIZIONE — comando dello stato del gioco
   -------------------------------------------------------------------------
   Il pannello chiama questo file per:
     - leggere lo stato attuale        (azione = stato)
     - avviare il gioco                (azione = avvia)   -> mostra Foto A
     - finire il gioco                 (azione = finisci) -> mostra Foto B
     - azzerare / spegnere             (azione = azzera)  -> immagine neutra
     - forzare A/B per i test          (azione = forza, valore = A|B|OFF)

   Tutte le azioni (tranne la sola lettura) richiedono la password.
   Non c'e' niente da configurare qui: vedi config.php.
   ========================================================================= */

require __DIR__ . '/config.php';

header('Cache-Control: no-store');

/* Legge un parametro sia da POST sia da GET. */
function p(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}

$azione   = p('azione') ?? 'stato';
$password = p('password');

/* --- una vista "pubblica" e sicura dello stato (senza percorsi dei file) -- */
function predizione_stato_pubblico(array $s): array {
    return [
        'fase'           => $s['fase'],
        'orario_scambio' => $s['orario_scambio'] ? (int)$s['orario_scambio'] : null,
        'forza'          => $s['forza'],
        'avvio_ts'       => $s['avvio_ts'] ? (int)$s['avvio_ts'] : null,
        'ha_foto_a'      => !empty($s['foto_a_file']) && is_file($s['foto_a_file']),
        'ha_foto_b'      => !empty($s['foto_b_file']) && is_file($s['foto_b_file']),
        'aperture'       => predizione_conta_aperture(),
        'ora_server'     => time(),
    ];
}

$stato = predizione_leggi_stato();

/* --- sola lettura dello stato: serve al pannello per aggiornarsi ---------- */
if ($azione === 'stato') {
    predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);
}

/* --- da qui in poi serve la password ------------------------------------- */
if (!predizione_password_ok($password)) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}

switch ($azione) {
    case 'avvia':
        // orario di sicurezza opzionale: "HH:MM" (oggi) oppure timestamp
        $orario = p('orario_scambio');
        $ts = null;
        if ($orario !== null && $orario !== '') {
            if (ctype_digit($orario)) {
                $ts = (int)$orario;                       // gia' un timestamp
            } elseif (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $orario)) {
                $ts = strtotime(date('Y-m-d') . ' ' . $orario . ':00'); // oggi alle HH:MM
                // se l'ora e' gia' passata, intende domani
                if ($ts !== false && $ts < time()) { $ts = strtotime('+1 day', $ts); }
            }
        }
        $stato['fase']           = 'avviato';
        $stato['avvio_ts']       = time();
        $stato['orario_scambio'] = $ts ?: null;
        $stato['forza']          = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);
        // no break: exit dentro predizione_json

    case 'finisci':
        $stato['fase']  = 'terminato';
        $stato['forza'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'azzera':
        $stato['fase']           = 'spento';
        $stato['orario_scambio'] = null;
        $stato['forza']          = null;
        $stato['avvio_ts']       = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'forza':
        $valore = strtoupper((string)p('valore'));
        $stato['forza'] = ($valore === 'A' || $valore === 'B') ? $valore : null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    default:
        predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
}
