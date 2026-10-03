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

/* Conta le aperture di UNA sessione divise per foto vista:
   'a' = quante hanno visto la Foto A ("prima"), 'b' = la Rivelazione ("dopo").
   Legge l'etichetta scritta da image.php (A / B / A(test) / B(test) / neutro).
   Definita qui (non in config.php) cosi' non serve ricaricare config.php sul server. */
function predizione_conta_aperture_ab(int $sessione): array {
    if ($sessione <= 0 || !is_file(LOG_FILE)) return ['a' => 0, 'b' => 0];
    $fh = @fopen(LOG_FILE, 'r');
    if (!$fh) return ['a' => 0, 'b' => 0];
    $sid = (string)$sessione;
    // Conta i DESTINATARI distinti (per codice "id"), non i singoli scaricamenti:
    // i programmi di posta (es. Gmail) scaricano la stessa foto piu' volte per
    // una sola apertura, quindi contiamo una volta sola per id. Se l'id manca
    // (es. apertura di prova dal browser), quella riga conta comunque una volta.
    $va = []; $vb = []; $vt = []; $n = 0;
    while (($line = fgets($fh)) !== false) {
        if (trim($line) === '') continue;
        $n++;
        $c = str_getcsv($line);
        if (($c[1] ?? '') !== $sid) continue;   // solo la sessione corrente
        $lbl = (string)($c[3] ?? '');
        if ($lbl === '') continue;
        $id  = trim((string)($c[2] ?? ''));
        $key = ($id !== '') ? $id : ('_r' . $n);
        $first = strtoupper($lbl[0]);
        if     ($first === 'A') { $va[$key] = true; $vt[$key] = true; }
        elseif ($first === 'B') { $vb[$key] = true; $vt[$key] = true; }
    }
    fclose($fh);
    // 'tot' = destinatari distinti di QUESTA sessione (si azzera a ogni nuova sessione)
    return ['a' => count($va), 'b' => count($vb), 'tot' => count($vt)];
}

/* Normalizza il modo di invio: 'casella' | 'brevo' | 'ses'
   (il vecchio valore 'smtp' vale come 'casella'; default 'casella'). */
function predizione_invio_modo($v): string {
    $v = strtolower((string)$v);
    if ($v === 'ses')   return 'ses';
    // 'grande' (pannello) e 'brevo' (vecchio) indicano lo stesso canale dedicato
    if ($v === 'grande' || $v === 'brevo') return 'brevo';
    return 'casella'; // 'casella', 'smtp' (vecchio) o vuoto
}

/* Congela la rivelazione pronta nel file "after" della sessione $nid
   (sostituendo l'eventuale precedente) e la consuma. Cosi' l'ultima
   rivelazione caricata prima di "Cambia"/"Finisci" e' quella che resta. */
function predizione_congela_rivelazione(array &$stato, int $nid, string $riv): void {
    $extR  = strtolower(pathinfo($riv, PATHINFO_EXTENSION)) ?: 'jpg';
    $after = DATA_DIR . '/sess_' . $nid . '_after.' . $extR;
    $prev  = $stato['sessioni'][(string)$nid]['after'] ?? null;
    if (!empty($prev) && is_file($prev) && $prev !== $after) @unlink($prev);
    @rename($riv, $after);
    $stato['sessioni'][(string)$nid]['after'] = $after;
    $stato['rivelazione_pronta'] = null;
}

/* Vista pubblica e sicura (senza percorsi dei file). */
function predizione_stato_pubblico(array $s): array {
    $sess = predizione_sessione_corrente($s);
    $ab   = predizione_conta_aperture_ab((int)($s['sessione_corrente'] ?? 0));
    return [
        'sessione'       => (int)($s['sessione_corrente'] ?? 0),
        'n_sessioni'     => count($s['sessioni'] ?? []),
        'fase'           => $sess['fase'] ?? 'spento',
        'orario_scambio' => ($sess && !empty($sess['orario_scambio'])) ? (int)$sess['orario_scambio'] : null,
        'avvio_ts'       => ($sess && !empty($sess['avvio_ts'])) ? (int)$sess['avvio_ts'] : null,
        'forza'          => $s['forza'],
        'autorisponditore'   => !empty($s['autorisponditore']),
        'invio_modo'     => predizione_invio_modo($s['invio_modo'] ?? null),
        'mail_oggetto'   => (string)($s['mail_oggetto'] ?? ''),
        'mail_testo'     => (string)($s['mail_testo'] ?? ''),
        'ha_foto_a'          => (!empty($s['foto_a']) && is_file($s['foto_a'])) || is_file(__DIR__ . '/neutro.png'),
        'ha_rivelazione'     => !empty($s['rivelazione_pronta']) && is_file($s['rivelazione_pronta']),
        'ha_rivelazione_sess'=> ($sess && !empty($sess['after']) && is_file($sess['after'])),
        'aperture'       => $ab['tot'],   // solo sessione corrente (azzerate a ogni "Avvia")
        'aperture_a'     => $ab['a'],
        'aperture_b'     => $ab['b'],
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
        // La rivelazione (Foto B) NON serve piu' per avviare: la puoi caricare
        // anche a gioco avviato, fino a quando premi "Cambia". Serve pero'
        // prima di "Cambia" (la controlliamo li').

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

        // congela la Foto A DI QUESTA sessione (file con nome dedicato)
        $extA = strtolower(pathinfo($foto_a, PATHINFO_EXTENSION)) ?: 'jpg';
        $before = DATA_DIR . '/sess_' . $nid . '_before.' . $extA;
        @copy($foto_a, $before);           // la neutra viene copiata (resta anche come default)

        $stato['sessioni'][(string)$nid] = [
            'fase'           => 'avviato',
            'before'         => $before,
            'after'          => null,       // la rivelazione si congela a "Cambia" (o se e' gia' pronta, qui sotto)
            'orario_scambio' => $ts ?: null,
            'avvio_ts'       => time(),
        ];
        $stato['sessione_corrente']  = $nid;
        $stato['ultimo_id']          = $nid;
        $stato['forza']              = null;
        // se la rivelazione e' GIA' pronta, congelala subito (comodo per chi la carica prima)
        if (!empty($riv) && is_file($riv)) {
            predizione_congela_rivelazione($stato, $nid, $riv);
        }
        // l'autorisponditore si ACCENDE da solo all'avvio (non c'e' piu' un tasto nel pannello)
        $stato['autorisponditore'] = true;
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
        // congela l'ULTIMA rivelazione caricata (se c'e' una pronta nuova, vince lei)
        $riv = $stato['rivelazione_pronta'] ?? null;
        if (!empty($riv) && is_file($riv)) {
            predizione_congela_rivelazione($stato, $id, $riv);
        }
        // deve esserci una rivelazione (ora o congelata prima): altrimenti non si rivela
        $aft = $stato['sessioni'][(string)$id]['after'] ?? null;
        if (empty($aft) || !is_file($aft)) {
            predizione_json(['ok' => false, 'errore' => 'Carica la rivelazione prima di rivelare (Cambia)'], 400);
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
        // se si chiude senza aver mai premuto "Cambia" ma c'e' una rivelazione
        // pronta, congelala (cosi' resta per sempre a chi apre dopo)
        $aft = $stato['sessioni'][(string)$id]['after'] ?? null;
        $riv = $stato['rivelazione_pronta'] ?? null;
        if ((empty($aft) || !is_file($aft)) && !empty($riv) && is_file($riv)) {
            predizione_congela_rivelazione($stato, $id, $riv);
        }
        $stato['sessioni'][(string)$id]['fase'] = 'terminato';
        $stato['forza'] = null;
        // l'autorisponditore si SPEGNE da solo alla fine (la Lambda smette di leggere la posta)
        $stato['autorisponditore'] = false;
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

    case 'invio':
        // modo di invio: 'casella' (SMTP Tophost) | 'brevo' (SMTP Brevo) | 'ses' (Amazon)
        $stato['invio_modo'] = predizione_invio_modo(p('valore'));
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'messaggio':
        // oggetto e testo personalizzati della mail automatica (usati dalla Lambda)
        $stato['mail_oggetto'] = substr((string)(p('oggetto') ?? ''), 0, 200);
        $stato['mail_testo']   = substr((string)(p('testo') ?? ''), 0, 2000);
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    default:
        predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
}
