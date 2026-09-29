<?php
/* =========================================================================
   PREDIZIONE — comando dello stato del gioco (a sessioni)
   -------------------------------------------------------------------------
   Il pannello chiama questo file per:
     - stato    : leggere la situazione (nessuna password)
     - avvia    : apre una NUOVA sessione (Foto A + rivelazione pronta) -> A
     - finisci  : chiude la sessione corrente -> rivelazione (B/C/...)
     - azzera   : annulla la sessione corrente se non ancora terminata
     - forza    : test, mostra A/B della sessione corrente (valore A|B|OFF)
     - autorisponditore : interruttore ON/OFF della risposta automatica (valore ON|OFF)

   Tutte le azioni tranne "stato" richiedono la password. Vedi config.php.
   ========================================================================= */

require __DIR__ . '/config.php';

header('Cache-Control: no-store');

function p(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}

/* Vista pubblica e sicura (senza percorsi dei file). */
function predizione_stato_pubblico(array $s): array {
    $sess = predizione_sessione_corrente($s);
    return [
        'sessione'       => (int)($s['sessione_corrente'] ?? 0),
        'n_sessioni'     => count($s['sessioni'] ?? []),
        'fase'           => $sess['fase'] ?? 'spento',
        'orario_scambio' => ($sess && !empty($sess['orario_scambio'])) ? (int)$sess['orario_scambio'] : null,
        'avvio_ts'       => ($sess && !empty($sess['avvio_ts'])) ? (int)$sess['avvio_ts'] : null,
        'forza'          => $s['forza'],
        'autorisponditore'   => !empty($s['autorisponditore']),
        'ha_foto_a'          => (!empty($s['foto_a']) && is_file($s['foto_a'])) || is_file(__DIR__ . '/neutro.png'),
        'ha_rivelazione'     => !empty($s['rivelazione_pronta']) && is_file($s['rivelazione_pronta']),
        'aperture'       => predizione_conta_aperture(),
        'ora_server'     => time(),
    ];
}

$azione   = p('azione') ?? 'stato';
$password = p('password');
$stato    = predizione_leggi_stato();

if ($azione === 'stato') {
    predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);
}

if (!predizione_password_ok($password)) {
    predizione_json(['ok' => false, 'errore' => 'Password errata'], 401);
}

switch ($azione) {

    case 'avvia':
        // Foto A: quella caricata, altrimenti la neutra PREDEFINITA (neutro.png)
        $foto_a = (!empty($stato['foto_a']) && is_file($stato['foto_a']))
                  ? $stato['foto_a']
                  : __DIR__ . '/neutro.png';
        $riv    = $stato['rivelazione_pronta'] ?? null;
        if (!is_file($foto_a)) {
            predizione_json(['ok' => false, 'errore' => 'Manca la Foto A neutra (neutro.png)'], 400);
        }
        if (empty($riv) || !is_file($riv)) {
            predizione_json(['ok' => false, 'errore' => 'Carica prima la rivelazione di questa sessione'], 400);
        }

        // orario di sicurezza opzionale: "HH:MM" (oggi) oppure timestamp
        $orario = p('orario_scambio');
        $ts = null;
        if ($orario !== null && $orario !== '') {
            if (ctype_digit($orario)) {
                $ts = (int)$orario;
            } elseif (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $orario)) {
                $ts = strtotime(date('Y-m-d') . ' ' . $orario . ':00');
                if ($ts !== false && $ts < time()) { $ts = strtotime('+1 day', $ts); }
            }
        }

        // nuovo numero di sessione
        $nid = (int)($stato['ultimo_id'] ?? 0) + 1;

        // congela le foto DI QUESTA sessione (file con nome dedicato)
        $extA = strtolower(pathinfo($foto_a, PATHINFO_EXTENSION)) ?: 'jpg';
        $extR = strtolower(pathinfo($riv, PATHINFO_EXTENSION)) ?: 'jpg';
        $before = DATA_DIR . '/sess_' . $nid . '_before.' . $extA;
        $after  = DATA_DIR . '/sess_' . $nid . '_after.' . $extR;
        @copy($foto_a, $before);           // la neutra viene copiata (resta anche come default)
        @rename($riv, $after);             // la rivelazione viene consumata per questa sessione

        $stato['sessioni'][(string)$nid] = [
            'fase'           => 'avviato',
            'before'         => $before,
            'after'          => $after,
            'orario_scambio' => $ts ?: null,
            'avvio_ts'       => time(),
        ];
        $stato['sessione_corrente']  = $nid;
        $stato['ultimo_id']          = $nid;
        $stato['rivelazione_pronta'] = null;   // consumata
        $stato['forza']              = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'cambia':
        // la RIVELAZIONE: la foto passa a B, ma il gioco resta attivo (risponde ancora)
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) {
            predizione_json(['ok' => false, 'errore' => 'Nessuna sessione attiva'], 400);
        }
        if (($stato['sessioni'][(string)$id]['fase'] ?? '') !== 'avviato') {
            predizione_json(['ok' => false, 'errore' => 'Il cambio si fa solo a gioco in corso'], 400);
        }
        $stato['sessioni'][(string)$id]['fase'] = 'cambiato';
        $stato['forza'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'finisci':
        // FINE: smette di rispondere e chiude la sessione (la B resta congelata)
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) {
            predizione_json(['ok' => false, 'errore' => 'Nessuna sessione da terminare'], 400);
        }
        $stato['sessioni'][(string)$id]['fase'] = 'terminato';
        $stato['forza'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'azzera':
        // annulla la sessione SOLO se non ha ancora rivelato (fase = avviato):
        // una sessione gia' "cambiata" o "terminata" NON si cancella, perche' i suoi
        // destinatari devono continuare a vedere la loro foto per sempre.
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id > 0 && isset($stato['sessioni'][(string)$id])) {
            if (($stato['sessioni'][(string)$id]['fase'] ?? '') === 'avviato') {
                $s = $stato['sessioni'][(string)$id];
                if (!empty($s['before']) && is_file($s['before'])) @unlink($s['before']);
                if (!empty($s['after'])  && is_file($s['after']))  @unlink($s['after']);
                unset($stato['sessioni'][(string)$id]);
            }
        }
        $stato['sessione_corrente'] = 0;   // torna al neutro per gli indirizzi senza ?s=
        $stato['forza'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'forza':
        $valore = strtoupper((string)p('valore'));
        $stato['forza'] = ($valore === 'A' || $valore === 'B') ? $valore : null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'autorisponditore':
        // interruttore ON/OFF della risposta automatica (la Lambda lo legge a ogni giro):
        // OFF -> la Lambda si sveglia ma NON fa nulla; ON -> risponde a gioco attivo.
        $valore = strtoupper((string)p('valore'));
        $stato['autorisponditore'] = ($valore === 'ON' || $valore === '1' || $valore === 'TRUE');
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    default:
        predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
}
