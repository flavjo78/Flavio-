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
require_once __DIR__ . '/piani.php';   // piani/limiti per il "Mio account"

header('Cache-Control: no-store');

function p(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}

/* Conta le aperture VERE di UNA sessione divise per foto vista:
   'a' = quante persone hanno visto la Foto A, 'b' = la Rivelazione (B),
   'tot' = destinatari distinti che hanno aperto DAVVERO.
   Usa predizione_aperture_vere() (config.php) che toglie le "aperture di
   servizio": il pre-scarico automatico nei primi 15s e i ri-scaricamenti
   ravvicinati della stessa persona. Cosi' il contatore mostra le aperture reali. */
function predizione_conta_aperture_ab(int $sessione): array {
    $vere = predizione_aperture_vere($sessione);
    $a = 0; $b = 0;
    foreach ($vere as $op) {
        if (!empty($op['labels']['A'])) $a++;
        if (!empty($op['labels']['B'])) $b++;
    }
    return ['a' => $a, 'b' => $b, 'tot' => count($vere)];
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

/* ---- LUCCHETTO DEL ROBOTTINO (un solo turno alla volta) -----------------
   Serve a chi NON può impostare "Concorrenza riservata = 1" su AWS: fa in
   modo che UNA sola esecuzione della Lambda lavori per volta, così non
   partono mai due risposte alla stessa mail (niente doppioni).

   Come funziona: un unico file DATA_DIR/robot.lock con dentro {token, ts}.
   - 'prendi'   : lo prende SOLO se è libero, scaduto (vecchio) o già suo.
   - 'rinnova'  : aggiorna l'ora SOLO se il lucchetto è ancora suo.
   - 'rilascia' : lo cancella SOLO se è suo.
   Il token è un numero a caso che il robottino genera a ogni risveglio, così
   un turno riconosce "il suo" lucchetto. Il TTL (durata) fa sì che, se una
   esecuzione muore senza rilasciare, il lucchetto si liberi da solo dopo
   qualche minuto (nessun blocco permanente).
   L'operazione è ATOMICA: usa flock(LOCK_EX) attorno a leggi-decidi-scrivi,
   così due robottini non possono prenderlo insieme. */
function predizione_robot_lock(string $op, string $token, int $ttl): array {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    $file = DATA_DIR . '/robot.lock';
    $fh = @fopen($file, 'c+');   // crea se manca, lettura+scrittura, NON tronca
    if (!$fh) return ['ok' => false, 'errore' => 'lock non apribile'];
    $esito = ['ok' => true, 'preso' => false, 'scade_tra' => 0];
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $cur = json_decode((string)$raw, true);
        $now = time();
        $occupato = is_array($cur) && !empty($cur['token'])
                    && (($now - (int)($cur['ts'] ?? 0)) < $ttl);
        $mio = $occupato && ((string)$cur['token'] === $token);

        if ($op === 'rilascia') {
            // libera solo se è mio (o se è già scaduto/vuoto, non importa)
            if (!$occupato || $mio) {
                ftruncate($fh, 0); rewind($fh); fwrite($fh, '');
            }
            $esito['preso'] = false;
        } elseif ($op === 'rinnova') {
            if ($mio) {
                $dati = json_encode(['token' => $token, 'ts' => $now]);
                ftruncate($fh, 0); rewind($fh); fwrite($fh, $dati);
                $esito['preso'] = true;
            } else {
                // lucchetto perso (scaduto o preso da un altro): NON riprenderlo di nascosto
                $esito['preso'] = false;
                if ($occupato) $esito['scade_tra'] = $ttl - ($now - (int)$cur['ts']);
            }
        } else { // 'prendi' (default)
            if (!$occupato || $mio) {
                $dati = json_encode(['token' => $token, 'ts' => $now]);
                ftruncate($fh, 0); rewind($fh); fwrite($fh, $dati);
                $esito['preso'] = true;
            } else {
                $esito['preso'] = false;
                $esito['scade_tra'] = $ttl - ($now - (int)$cur['ts']);
            }
        }
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $esito;
}

/* Congela la rivelazione pronta nel file "after" della sessione $nid
   (sostituendo l'eventuale precedente) e la consuma. Cosi' l'ultima
   rivelazione caricata prima di "Cambia"/"Finisci" e' quella che resta. */
function predizione_congela_rivelazione(array &$stato, int $nid, string $riv): void {
    $extR  = strtolower(pathinfo($riv, PATHINFO_EXTENSION)) ?: 'jpg';
    $after = predizione_data_dir() . '/sess_' . $nid . '_after.' . $extR;
    $prev  = $stato['sessioni'][(string)$nid]['after'] ?? null;
    if (!empty($prev) && is_file($prev) && $prev !== $after) @unlink($prev);
    @rename($riv, $after);
    $stato['sessioni'][(string)$nid]['after'] = $after;
    $stato['rivelazione_pronta'] = null;
}

/* Aggiunge una voce al "diario" della sessione: COSA e' stato premuto e QUANDO.
   Serve all'intestazione del report (la "storia" della sessione: Avvia / Rispondi
   mail / Rivela / Finisci), così il prestigiatore vede la sequenza con gli orari. */
function predizione_diario(array &$stato, int $sid, string $azione): void {
    if ($sid <= 0) return;
    $k = (string)$sid;
    if (!isset($stato['sessioni'][$k]) || !is_array($stato['sessioni'][$k])) return;
    if (!isset($stato['sessioni'][$k]['diario']) || !is_array($stato['sessioni'][$k]['diario'])) {
        $stato['sessioni'][$k]['diario'] = [];
    }
    $stato['sessioni'][$k]['diario'][] = ['a' => $azione, 'ts' => time()];
}

/* Chiude una sessione (come il tasto "Finisci"): congela la rivelazione pronta,
   fase=terminato, spegne l'autorisponditore, revoca l'assistente, pulisce le foto.
   Usata sia dall'azione 'finisci' sia dallo spegnimento automatico di sicurezza. */
function predizione_chiudi_sessione(array &$stato, int $id, string $motivo = 'finisci'): void {
    if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) return;
    // se si chiude senza aver mai premuto "Cambia" ma c'e' una rivelazione pronta, congelala
    $aft = $stato['sessioni'][(string)$id]['after'] ?? null;
    $riv = $stato['rivelazione_pronta'] ?? null;
    if ((empty($aft) || !is_file($aft)) && !empty($riv) && is_file($riv)) {
        predizione_congela_rivelazione($stato, $id, $riv);
    }
    $stato['sessioni'][(string)$id]['fase'] = 'terminato';
    $stato['sessioni'][(string)$id]['fine_ts'] = time();
    predizione_diario($stato, $id, $motivo);
    $stato['forza'] = null;
    $stato['autorisponditore'] = false;      // la Lambda smette di leggere/rispondere
    $stato['assistente_token'] = null;        // il link assistente scade con la fine
    predizione_pulisci_foto_base($stato);
    if (!empty($stato['rivelazione_pronta']) && is_file($stato['rivelazione_pronta'])) @unlink($stato['rivelazione_pronta']);
    $stato['rivelazione_pronta'] = null;
    if (empty($stato['blocca_foto_a'])) {
        if (!empty($stato['foto_a']) && is_file($stato['foto_a'])) @unlink($stato['foto_a']);
        $stato['foto_a'] = null;
    }
}

/* Stato della SICUREZZA della sessione in corso: da quanto è attiva e a che
   "tappa" siamo (ok → avviso → rallentato → fermo). Il cronometro parte
   dall'ULTIMA conferma: 'proroga_ts' (impostato all'Avvia e aggiornato dal tasto
   "Sì, continua"); ripiega su 'avvio_ts'. Ogni "Avvia" fa una sessione nuova con
   orario nuovo, quindi un gioco nuovo non viene mai scambiato per uno vecchio.
   Ritorna anche i secondi mancanti alle prossime tappe (per il pop-up in Regia). */
function predizione_sicurezza(array $s): array {
    $id = (int)($s['sessione_corrente'] ?? 0);
    $sess = ($id > 0 && isset($s['sessioni'][(string)$id])) ? $s['sessioni'][(string)$id] : null;
    $vuoto = ['fase' => 'ok', 'attiva' => false, 'disattivata' => false, 'ferma_tra' => 0,
              'avviso_tra' => 0, 'rallenta_tra' => 0];
    if (!$sess) return $vuoto;
    $faseGioco = $sess['fase'] ?? '';
    if ($faseGioco !== 'avviato' && $faseGioco !== 'cambiato') return $vuoto;
    $avv = (int)($sess['avvio_ts'] ?? time());
    $off = !empty($sess['sicurezza_off']);
    // scadenza = quando il gioco si chiude da solo (impostata all'Avvia, spostata da "Sì, continua")
    $scad = (int)($sess['scadenza_ts'] ?? ($avv + PRED_SIC_DURATA_ORE * 3600));
    $now  = time();
    if ($off) {
        // avvisi disattivati: niente pop-up/rallenta, SOLO il tetto assoluto dall'Avvia
        $hard = $avv + PRED_SIC_HARD_ORE * 3600;
        return ['fase' => ($now >= $hard ? 'fermo' : 'ok'), 'attiva' => true, 'disattivata' => true,
                'ferma_tra' => max(0, $hard - $now), 'avviso_tra' => 0, 'rallenta_tra' => 0];
    }
    $manca    = $scad - $now;                                   // secondi alla chiusura
    $sogliaAv = PRED_SIC_AVVISO_ANTICIPO_ORE   * 3600;          // avviso quando manca <= 3h
    $sogliaRa = PRED_SIC_RALLENTA_ANTICIPO_ORE * 3600;          // rallenta quando manca <= 1h
    if     ($manca <= 0)        $fase = 'fermo';
    elseif ($manca <= $sogliaRa) $fase = 'rallentato';
    elseif ($manca <= $sogliaAv) $fase = 'avviso';
    else                         $fase = 'ok';
    return ['fase' => $fase, 'attiva' => true, 'disattivata' => false,
            'ferma_tra' => max(0, $manca),
            'avviso_tra' => max(0, $manca - $sogliaAv),
            'rallenta_tra' => max(0, $manca - $sogliaRa)];
}

/* SPEGNIMENTO AUTOMATICO: se la sicurezza è arrivata alla tappa 'fermo', chiude
   la sessione da sola. Scatta a ogni lettura (incluso ogni giro del robottino),
   quindi funziona anche con l'app del performer chiusa. Ritorna true se ha chiuso. */
function predizione_autochiusura(array &$stato): bool {
    $sic = predizione_sicurezza($stato);
    if (($sic['fase'] ?? 'ok') !== 'fermo') return false;
    predizione_chiudi_sessione($stato, (int)($stato['sessione_corrente'] ?? 0), 'auto_fine');
    return true;
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
        'blocca_foto_a'  => !empty($s['blocca_foto_a']),   // spunta "tieni la Foto A dopo Finisci"
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
        'utente'         => predizione_utente(),
        'flags'          => predizione_flags(predizione_utente()),  // il pannello sa cosa mostrare
        'mail_impostata' => predizione_mail_impostata(predizione_utente()),
        'mail_cfg'       => predizione_mail_cfg(predizione_utente()),  // casella salvata (per riempire le Impostazioni)
        'ha_zona'        => (!empty($s['foto_quad']) && is_array($s['foto_quad']) && count($s['foto_quad']) === 4),
        'ha_foto_base'   => (!empty($s['foto_base']) && is_file($s['foto_base'])),
        'foto_quad'      => (!empty($s['foto_quad'])  && is_array($s['foto_quad']))  ? $s['foto_quad']  : null,
        'foto_gomma'     => (!empty($s['foto_gomma']) && is_array($s['foto_gomma'])) ? $s['foto_gomma'] : null,
        'mail_ricevute'  => (int)($s['mail_ricevute'] ?? 0),
        'mail_inviate'   => (int)($s['mail_inviate'] ?? 0),
        'assistente_attivo' => !empty($s['assistente_token']),  // c'e' un link assistente attivo?
        'account'        => predizione_account_pubblico(predizione_utente()),  // piano e limiti (Mio account)
        'uso'            => predizione_uso_mese($s),  // consumi del mese (pagina Limiti con le barre)
        'palco_abilitato'  => !empty(predizione_limiti_utente(predizione_utente_record(predizione_utente()) ?: [])['grandi_invii']),
        'rispondi_manuale' => !empty($s['rispondi_manuale']),   // "tasto Rispondi" attivo?
        'rispondi_ok'      => !empty($s['rispondi_ok']),        // via libera gia' dato in questa sessione?
        'sicurezza'        => predizione_sicurezza($s),         // tappe anti-dimenticanza (pop-up Regia)
    ];
}

/* Dati "commerciali" dell'utente per il Mio account (piano, scadenze, crediti). */
function predizione_account_pubblico(string $u): ?array {
    if (!function_exists('predizione_limiti_utente')) return null;
    $rec = predizione_utente_record($u);
    if (!$rec) return null;
    $lim = predizione_limiti_utente($rec);
    $lim['nome']  = (string)($rec['nome'] ?? '');
    $lim['email'] = (string)($rec['email_comunicazione'] ?? '');
    $lim['tel']   = (string)($rec['telefono'] ?? '');
    return $lim;
}

/* true se l'utente ha gia' configurato la sua casella (campi minimi presenti) */
function predizione_mail_impostata(string $u): bool {
    $rec = predizione_utente_record($u);
    $m = ($rec && isset($rec['mail'])) ? (array)$rec['mail'] : [];
    return !empty($m['user']) && !empty($m['pass']);
}

/* Configurazione casella dell'utente (email, password per app 16 cifre, mittente),
   per RIMOSTRARLA precompilata nella Regia e nel pannello 000 (dati propri/dell'admin). */
function predizione_mail_cfg(string $u): array {
    $rec = predizione_utente_record($u);
    $m = ($rec && isset($rec['mail'])) ? (array)$rec['mail'] : [];
    return ['user' => (string)($m['user'] ?? ''), 'pass' => (string)($m['pass'] ?? ''), 'from' => (string)($m['from'] ?? '')];
}

/* ---- AZIONI AMMINISTRATORE (comandate dal pannello Admin, utente 000) --- */
function predizione_admin(string $azione): void {
    $utenti = predizione_utenti_leggi();
    switch ($azione) {

        case 'admin_utenti':   // elenco utenti + stato in diretta
            $out = [];
            foreach ($utenti as $num => $rec) {
                $prevU = predizione_utente();
                predizione_set_utente((string)$num);
                $st   = predizione_leggi_stato();
                $sess = predizione_sessione_corrente($st);
                $ab   = predizione_conta_aperture_ab((int)($st['sessione_corrente'] ?? 0));
                predizione_set_utente($prevU);
                $out[] = [
                    'numero'         => (string)$num,
                    'admin'          => !empty($rec['admin']),
                    'attivo'         => !empty($rec['attivo']),
                    'flags'          => predizione_flags((string)$num),
                    'mail_impostata' => predizione_mail_impostata((string)$num),
                    'pass'           => (string)($rec['pass'] ?? ''),
                    'fase'           => $sess['fase'] ?? 'spento',
                    'sessione'       => (int)($st['sessione_corrente'] ?? 0),
                    'aperture'       => $ab['tot'],
                    'piano'          => (string)($rec['piano'] ?? 'Foyer'),
                    'piano_versione' => isset($rec['piano_versione']) ? (int)$rec['piano_versione'] : null,
                    'accesso_scad'   => (int)($rec['accesso_scadenza'] ?? 0),
                    'piano_scad'     => (int)($rec['piano_scadenza'] ?? 0),
                    'crediti'        => predizione_limiti_utente(is_array($rec) ? $rec : [])['crediti'],
                    'nome'           => (string)($rec['nome'] ?? ''),
                    'email'          => (string)($rec['email_comunicazione'] ?? ''),
                    'tel'            => (string)($rec['telefono'] ?? ''),
                    'fonte'          => (string)($rec['fonte'] ?? ''),   // provenienza (etichetta)
                ];
            }
            predizione_json(['ok' => true, 'utenti' => $out]);

        case 'admin_scheda':   // dettaglio di UN utente: anagrafica + cronologia sessioni
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $rec = $utenti[$num];
            $prevU = predizione_utente();
            predizione_set_utente($num);
            $st = predizione_leggi_stato();
            $sessOut = [];
            foreach (($st['sessioni'] ?? []) as $sid => $s) {
                if (!is_array($s)) continue;
                $ab = predizione_conta_aperture_ab((int)$sid);
                $mfile = predizione_data_dir() . '/mail_sess_' . (int)$sid . '.jsonl';
                $nmitt = 0;
                if (is_file($mfile)) { $fh = @fopen($mfile, 'r'); if ($fh) { while (fgets($fh) !== false) $nmitt++; fclose($fh); } }
                $sessOut[] = [
                    'id'        => (int)$sid,
                    'fase'      => (string)($s['fase'] ?? 'spento'),
                    'avvio_ts'  => (int)($s['avvio_ts'] ?? 0),
                    'mittenti'  => $nmitt,
                    'aperture'  => (int)($ab['tot'] ?? 0),
                    'aperture_b'=> (int)($ab['b'] ?? 0),
                ];
            }
            predizione_set_utente($prevU);
            // ordina le sessioni dalla più recente
            usort($sessOut, fn($a, $b) => $b['id'] - $a['id']);
            $lim = predizione_limiti_utente(is_array($rec) ? $rec : []);
            predizione_json(['ok' => true, 'scheda' => [
                'numero'   => $num,
                'nome'     => (string)($rec['nome'] ?? ''),
                'email'    => (string)($rec['email_comunicazione'] ?? ''),
                'tel'      => (string)($rec['telefono'] ?? ''),
                'pass'     => (string)($rec['pass'] ?? ''),
                'attivo'   => !empty($rec['attivo']),
                'flags'    => predizione_flags($num),
                'piano'    => (string)($rec['piano'] ?? 'Foyer'),
                'piano_versione' => isset($rec['piano_versione']) ? (int)$rec['piano_versione'] : null,
                'accesso_scad' => (int)($rec['accesso_scadenza'] ?? 0),
                'piano_scad'   => (int)($rec['piano_scadenza'] ?? 0),
                'fonte'    => (string)($rec['fonte'] ?? ''),                 // provenienza (etichetta)
                'report_extra' => array_key_exists('report_extra', $rec) ? (bool)$rec['report_extra'] : null,
                'report'   => (bool)$lim['report'],
                'limiti'   => $lim,
                'sessioni' => $sessOut,
                'mail'     => predizione_mail_cfg($num),
            ]]);

        case 'admin_piano':   // upgrade/cambio piano (per X mesi o X anni)
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $piano = predizione_piano_valido((string)(p('piano') ?? ''));
            if (!$piano) predizione_json(['ok' => false, 'errore' => 'Piano non valido'], 400);
            $durata = (p('durata') === 'mese') ? 'mese' : 'anno';
            $quante = max(1, (int)(p('quante') ?? 1));
            predizione_applica_piano($utenti[$num], $piano, $durata, $quante);
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true]);

        case 'admin_crediti':   // aggiunge crediti (mail / foto_ia / sessioni), con scadenza facoltativa
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $tipo = (string)(p('tipo') ?? '');
            $qta  = (int)(p('qta') ?? 0);
            $scad = 0; $sc = (string)(p('scad') ?? '');
            if ($sc !== '') { $ts = strtotime($sc); if ($ts) $scad = $ts; }
            if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni', 'assistente'], true) || $qta <= 0) {
                predizione_json(['ok' => false, 'errore' => 'Tipo o quantità crediti non validi'], 400);
            }
            predizione_aggiungi_crediti($utenti[$num], $tipo, $qta, $scad);
            predizione_utenti_scrivi($utenti);
            // registra l'accredito nello storico dell'utente (per "Mio account")
            $prevU = predizione_utente();
            predizione_set_utente($num);
            $stMov = predizione_leggi_stato();
            predizione_movimento($stMov, $tipo, +abs($qta), 'crediti');
            predizione_scrivi_stato($stMov);
            predizione_set_utente($prevU);
            predizione_json(['ok' => true]);

        case 'admin_crea':
            $num = predizione_pulisci_utente(p('numero'));
            if ($num === '000' || isset($utenti[$num])) {
                predizione_json(['ok' => false, 'errore' => 'Numero già esistente o non valido'], 400);
            }
            $pw = substr((string)(p('pw') ?? ''), 0, 100);   // password del NUOVO utente (campo a parte)
            $utenti[$num] = [
                'pass'   => ($pw !== '' ? $pw : PRED_DEFAULT_PASS),
                'admin'  => false, 'attivo' => true,
                'flags'  => ['mail' => (p('mail') === '1'), 'foto' => (p('foto') === '1')],
                'mail'   => new stdClass(),
            ];
            predizione_utenti_scrivi($utenti);
            predizione_log_operazione('crea', $num, 'utente creato a mano');
            predizione_json(['ok' => true]);

        case 'admin_flags':
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $utenti[$num]['flags'] = ['mail' => (p('mail') === '1'), 'foto' => (p('foto') === '1')];
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true]);

        case 'admin_report':
            // attiva/disattiva la funzione REPORT per questo utente (vince sul piano)
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $v = strtoupper((string)p('valore'));
            $utenti[$num]['report_extra'] = ($v === '1' || $v === 'ON' || $v === 'TRUE');
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true]);

        case 'admin_accessi':   // elenco password d'accesso (provenienza) + LETTERA per il numero ordine
            $acc = predizione_accessi_leggi();
            $out = [];
            // la base "aaa" (diretto) sempre in cima, lettera 'a'
            $out[] = ['password' => PRED_ACCESSO_BASE, 'etichetta' => 'diretto', 'lettera' => 'a', 'creato' => 0, 'base' => true];
            foreach ($acc as $pw => $info) {
                $out[] = ['password' => (string)$pw, 'etichetta' => (string)($info['etichetta'] ?? ''),
                          'lettera' => (string)($info['lettera'] ?? ''), 'creato' => (int)($info['creato'] ?? 0)];
            }
            predizione_json(['ok' => true, 'accessi' => $out, 'base' => PRED_ACCESSO_BASE]);

        case 'admin_accesso_crea':
            // NB: il campo si chiama 'pw_accesso' (non 'password', che è la password admin)
            $pw  = substr(trim((string)(p('pw_accesso') ?? '')), 0, 60);
            $et  = substr(trim((string)(p('etichetta') ?? '')), 0, 60);
            if ($pw === '' || $et === '') predizione_json(['ok' => false, 'errore' => 'Password ed etichetta obbligatorie'], 400);
            if ($pw === PRED_ACCESSO_BASE) predizione_json(['ok' => false, 'errore' => 'Questa password è già la base'], 400);
            $acc = predizione_accessi_leggi();
            // lettera: se l'admin ne propone una valida e libera la usa, altrimenti la prima libera
            $lett = substr(preg_replace('/[^a-z]/', '', strtolower((string)(p('lettera') ?? ''))), 0, 1);
            $gia  = ['a' => true];
            foreach ($acc as $i) { $x = substr((string)($i['lettera'] ?? ''), 0, 1); if ($x !== '') $gia[$x] = true; }
            if ($lett === '' || !empty($gia[$lett])) { $lett = predizione_accesso_lettera_libera(); }
            $acc[$pw] = ['etichetta' => $et, 'lettera' => $lett, 'creato' => time()];
            predizione_accessi_scrivi($acc);
            predizione_json(['ok' => true, 'lettera' => $lett]);

        case 'admin_accesso_elimina':
            $pw  = (string)(p('pw_accesso') ?? '');
            $acc = predizione_accessi_leggi();
            unset($acc[$pw]);
            predizione_accessi_scrivi($acc);
            predizione_json(['ok' => true]);

        case 'admin_password':
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $np = substr((string)(p('pw') ?? ''), 0, 100);   // nuova password (campo a parte, non 'password')
            if ($np === '') predizione_json(['ok' => false, 'errore' => 'Password vuota'], 400);
            $utenti[$num]['pass'] = $np;
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true]);

        case 'admin_attiva':
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $v = strtoupper((string)p('valore'));
            $attivo = ($v === '1' || $v === 'ON' || $v === 'TRUE');
            $utenti[$num]['attivo'] = $attivo;
            predizione_utenti_scrivi($utenti);
            predizione_log_operazione($attivo ? 'attiva' : 'sospendi', $num, (string)($utenti[$num]['nome'] ?? ''));
            predizione_json(['ok' => true]);

        case 'admin_mail':
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $utenti[$num]['mail'] = predizione_mail_da_richiesta();
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true]);

        case 'admin_elimina':
            $num = predizione_pulisci_utente(p('numero'));
            if ($num === '000') predizione_json(['ok' => false, 'errore' => 'L\'amministratore non si elimina'], 400);
            $nomeEl = (string)($utenti[$num]['nome'] ?? '');
            unset($utenti[$num]);
            predizione_utenti_scrivi($utenti);
            predizione_log_operazione('elimina', $num, $nomeEl);
            predizione_json(['ok' => true]);

        case 'admin_operazioni':
            // archivio delle operazioni admin (attiva/sospendi/elimina/crea), con data
            predizione_json(['ok' => true, 'operazioni' => predizione_operazioni_leggi(300)]);

        case 'admin_listino':
            // legge il LISTINO effettivo (piani + extra + prezzo accesso) per l'editor.
            $piani = [];
            foreach (predizione_piani() as $nome => $p) {
                $piani[] = array_merge(['nome' => (string)$nome, 'versione' => predizione_piano_versione((string)$nome)],
                                       predizione_piano_normalizza($p));
            }
            $extra = [];
            foreach (predizione_extra() as $code => $e) {
                $extra[] = ['code' => (string)$code, 'label' => (string)$e['label'],
                            'prezzo' => (float)$e['prezzo'], 'tipo' => (string)$e['tipo'], 'qta' => (int)$e['qta']];
            }
            predizione_json(['ok' => true, 'listino' => [
                'piani' => $piani, 'extra' => $extra,
                'accesso' => predizione_accesso_prezzo(),
                'nomi_base' => predizione_piani_nomi_base(),
            ]]);

        case 'admin_piani_storico':
            // storico delle VERSIONI vecchie dei piani (per il pannello 000)
            $st = predizione_piani_storico();
            usort($st, fn($a, $b) => (int)($b['archiviato'] ?? 0) - (int)($a['archiviato'] ?? 0));
            // versioni correnti
            $corr = [];
            foreach (predizione_piani_nomi_base() as $nm) { $corr[$nm] = predizione_piano_versione($nm); }
            predizione_json(['ok' => true, 'storico' => $st, 'versioni' => $corr]);

        case 'admin_listino_salva':
            // salva il listino modificato dall'editor. Riceve 'dati' = JSON
            // {piani:{Nome:{...}}, extra:{code:{...}}, accesso:N}. I 4 nomi base
            // restano; si puo' aggiungere UN piano extra. Chi e' gia' abbonato
            // tiene il piano vecchio (snapshot) fino al rinnovo.
            $raw = (string)(p('dati') ?? '');
            $in  = json_decode($raw, true);
            if (!is_array($in)) predizione_json(['ok' => false, 'errore' => 'Dati listino non validi'], 400);
            // parto dal listino ATTUALE, cosi' conservo versioni e storico
            $old      = predizione_listino_leggi();
            $versioni = is_array($old['versioni'] ?? null) ? $old['versioni'] : [];
            $storico  = is_array($old['storico'] ?? null)  ? $old['storico']  : [];
            $correnti = predizione_piani();   // definizioni attualmente in vigore (per confronto)
            $nuovo = ['piani' => [], 'extra' => [], 'accesso' => max(0, (int)($in['accesso'] ?? predizione_accesso_prezzo())),
                      'versioni' => $versioni, 'storico' => $storico];
            // piani
            $pin = is_array($in['piani'] ?? null) ? $in['piani'] : [];
            $def = predizione_piani_default();
            // sempre i 4 ufficiali
            foreach (predizione_piani_nomi_base() as $nome) {
                $src   = is_array($pin[$nome] ?? null) ? $pin[$nome] : [];
                $nuovoP = predizione_piano_normalizza($src, $def[$nome] ?? []);
                $vecchioP = predizione_piano_normalizza($correnti[$nome] ?? ($def[$nome] ?? []));
                $verCorr = (int)($nuovo['versioni'][$nome] ?? 1); if ($verCorr < 1) $verCorr = 1;
                // se e' CAMBIATO qualcosa: archivia la versione vecchia e alza la versione
                if (json_encode($nuovoP) !== json_encode($vecchioP)) {
                    $nuovo['storico'][] = ['nome' => $nome, 'versione' => $verCorr,
                        'etichetta' => $nome . ' ' . str_pad((string)$verCorr, 2, '0', STR_PAD_LEFT),
                        'dati' => $vecchioP, 'archiviato' => time()];
                    $verCorr++;
                }
                $nuovo['versioni'][$nome] = $verCorr;
                $nuovo['piani'][$nome] = $nuovoP;
            }
            // extra
            $ein = is_array($in['extra'] ?? null) ? $in['extra'] : [];
            foreach ($ein as $code => $e) {
                $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$code));
                if ($code === '' || !is_array($e)) continue;
                $tipo = (string)($e['tipo'] ?? 'mail');
                if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni'], true)) $tipo = 'mail';
                $nuovo['extra'][$code] = ['prezzo' => max(0, (float)($e['prezzo'] ?? 0)), 'tipo' => $tipo,
                                          'qta' => max(1, (int)($e['qta'] ?? 1)), 'label' => substr((string)($e['label'] ?? $code), 0, 60)];
            }
            if (!predizione_listino_scrivi($nuovo)) predizione_json(['ok' => false, 'errore' => 'Salvataggio non riuscito (permessi _dati?)'], 500);
            predizione_log_operazione('listino', '000', 'listino aggiornato');
            predizione_json(['ok' => true]);

        case 'admin_acquisti':
            // cronologia acquisti di UN utente (dal registro vendite.csv globale)
            $num = predizione_pulisci_utente(p('numero'));
            $file = predizione_vendite_file();
            $out = [];
            if (is_file($file)) {
                $fh = @fopen($file, 'r');
                if ($fh) {
                    $head = fgetcsv($fh);  // intestazione
                    while (($r = fgetcsv($fh)) !== false) {
                        // colonne: data,ordine,utente,nome,mail,piano,durata,importo,canale
                        if (($r[2] ?? '') !== $num) continue;
                        $out[] = ['data' => $r[0] ?? '', 'ordine' => $r[1] ?? '', 'piano' => $r[5] ?? '',
                                  'durata' => $r[6] ?? '', 'importo' => $r[7] ?? '', 'canale' => $r[8] ?? ''];
                    }
                    fclose($fh);
                }
            }
            $out = array_reverse($out);  // dal piu' recente
            predizione_json(['ok' => true, 'acquisti' => $out]);

        case 'admin_caselle':
            // Elenco delle caselle configurate, per l'autorisponditore MULTI-CASELLA.
            // Esclude 000 (admin) e 001 (usa la casella "di casa" via variabili d'ambiente
            // nella Lambda), gli utenti non attivi e quelli senza casella impostata.
            // Restituisce credenziali IMAP/SMTP + stato del gioco di ciascun utente, cosi'
            // la Lambda non deve fare altre chiamate. SOLO amministratore (gia' verificato).
            $out = [];
            foreach ($utenti as $num => $rec) {
                if ($num === '000' || $num === '001') continue;
                if (empty($rec['attivo'])) continue;
                $m = (isset($rec['mail']) && is_array($rec['mail'])) ? $rec['mail'] : [];
                if (empty($m['user']) || empty($m['pass'])) continue;   // nessuna casella configurata
                $prevU = predizione_utente();
                predizione_set_utente((string)$num);
                $st   = predizione_leggi_stato();
                if (predizione_autochiusura($st)) { predizione_scrivi_stato($st); }  // spegnimento 12h anche per i clienti
                $sess = predizione_sessione_corrente($st);
                predizione_set_utente($prevU);
                // OTTIMIZZAZIONE (ROADMAP §2): elenca al robottino SOLO i giochi ATTIVI.
                // L'autorisponditore e' ON solo tra "Avvia" e "Finisci": i giochi spenti
                // non vengono nemmeno inviati, cosi' la Lambda non ci perde tempo (ne' si
                // connette alla loro casella). Il robottino ha comunque il suo controllo.
                if (empty($st['autorisponditore'])) continue;
                $out[] = [
                    'utente'           => (string)$num,
                    'pass_pannello'    => (string)($rec['pass'] ?? ''),
                    'imap_host'        => (string)($m['imap_host'] ?? 'imap.gmail.com'),
                    'imap_port'        => (int)($m['imap_port'] ?? 993),
                    'imap_user'        => (string)$m['user'],
                    'imap_pass'        => (string)$m['pass'],
                    'smtp_host'        => (string)($m['smtp_host'] ?? 'smtp.gmail.com'),
                    'smtp_port'        => (int)($m['smtp_port'] ?? 587),
                    'from'             => (string)($m['from'] ?? $m['user']),
                    'sessione'         => (int)($st['sessione_corrente'] ?? 0),
                    'fase'             => $sess['fase'] ?? 'spento',
                    'avvio_ts'         => ($sess && !empty($sess['avvio_ts'])) ? (int)$sess['avvio_ts'] : 0,
                    'autorisponditore' => !empty($st['autorisponditore']),
                    'rispondi_manuale' => !empty($st['rispondi_manuale']),
                    'rispondi_ok'      => !empty($st['rispondi_ok']),
                    'sicurezza_fase'   => predizione_sicurezza($st)['fase'],   // ok|avviso|rallentato (per i giri veloci)
                    'mail_oggetto'     => (string)($st['mail_oggetto'] ?? ''),
                    'mail_testo'       => (string)($st['mail_testo'] ?? ''),
                ];
            }
            predizione_json(['ok' => true, 'caselle' => $out]);

        case 'admin_lock':
            // LUCCHETTO: un solo turno del robottino alla volta (vedi
            // predizione_robot_lock). Solo amministratore (già verificato).
            //   lock_op = prendi | rinnova | rilascia   (default: prendi)
            //   token   = numero a caso del turno
            //   ttl     = durata in secondi (default 150)
            $op  = (string)(p('lock_op') ?? 'prendi');
            if (!in_array($op, ['prendi', 'rinnova', 'rilascia'], true)) $op = 'prendi';
            $tok = substr((string)(p('token') ?? ''), 0, 64);
            $ttl = (int)(p('ttl') ?? 150);
            if ($ttl < 10)  $ttl = 10;
            if ($ttl > 600) $ttl = 600;
            if ($tok === '') predizione_json(['ok' => false, 'errore' => 'token mancante'], 400);
            predizione_json(predizione_robot_lock($op, $tok, $ttl));
    }
}

/* Costruisce la configurazione casella (Gmail) dai parametri ricevuti. */
function predizione_mail_da_richiesta(): array {
    $email   = substr((string)(p('email') ?? ''), 0, 160);
    $apppass = substr((string)(p('app_password') ?? ''), 0, 200);
    $from    = substr((string)(p('from') ?? ''), 0, 160);
    return [
        'imap_host' => 'imap.gmail.com', 'imap_port' => 993,
        'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587,
        'user' => $email, 'pass' => $apppass,
        'from' => ($from !== '' ? $from : $email),
    ];
}

$azione   = p('azione') ?? 'stato';
// contesto utente: accetta sia 'utente' (POST dei comandi) sia 'u' (GET della lettura
// stato, come lo manda il pannello). Senza 'u' la lettura tornava sempre a 001.
$utente   = predizione_set_utente(p('utente') ?? ($_GET['u'] ?? ''));
$password = p('password');

/* ---- LOGIN: verifica accesso e ritorna le funzioni abilitate ----------- */
if ($azione === 'login') {
    if (!predizione_auth($utente, $password)) {
        predizione_json(['ok' => false, 'errore' => 'Numero o password non validi'], 401);
    }
    $rec = predizione_utente_record($utente);
    predizione_json([
        'ok' => true, 'utente' => $utente,
        'admin' => !empty($rec['admin']),
        'flags' => predizione_flags($utente),
    ]);
}

/* ---- AZIONI AMMINISTRATORE (utente 000) -------------------------------- */
if (strpos($azione, 'admin_') === 0) {
    if (!predizione_is_admin($utente, $password)) {
        predizione_json(['ok' => false, 'errore' => 'Solo amministratore'], 403);
    }
    predizione_admin($azione);   // gestisce la richiesta ed esce
    predizione_json(['ok' => false, 'errore' => 'Azione admin sconosciuta'], 400);
}

$stato = predizione_leggi_stato();

/* Spegnimento automatico di sicurezza: se il gioco è avviato da troppe ore
   (dimenticanza) lo chiude da solo. Scatta a ogni lettura — incluso ogni giro
   del robottino — così funziona anche se l'app del performer è chiusa. */
if (predizione_autochiusura($stato)) { predizione_scrivi_stato($stato); }

/* ---- lettura stato (senza password) ------------------------------------ */
if ($azione === 'stato') {
    $pub = predizione_stato_pubblico($stato);
    // se arriva un lasciapassare assistente, diciamo se e' ANCORA valido
    // (serve al link assistente per accorgersi di revoca o fine sessione)
    $tokLett = p('assistente');
    if (is_string($tokLett) && $tokLett !== '') {
        $pub['assistente_valido'] = predizione_assistente_valido($stato, $tokLett);
    }
    predizione_json(['ok' => true, 'stato' => $pub]);
}

/* ---- da qui serve l'accesso: password dell'utente OPPURE, per i soli
       comandi del gioco, un valido lasciapassare "Assistente di scena" ---- */
$AZIONI_ASSISTENTE = ['avvia', 'cambia', 'finisci', 'rispondi', 'proroga', 'sicurezza'];
$assistTok = p('assistente');
$accessoOk = predizione_auth($utente, $password)
    || (in_array($azione, $AZIONI_ASSISTENTE, true) && predizione_assistente_valido($stato, $assistTok));
if (!$accessoOk) {
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
        $before = predizione_data_dir() . '/sess_' . $nid . '_before.' . $extA;
        @copy($foto_a, $before);           // la neutra viene copiata (resta anche come default)

        $stato['sessioni'][(string)$nid] = [
            'fase'           => 'avviato',
            'before'         => $before,
            'after'          => null,       // la rivelazione si congela a "Cambia" (o se e' gia' pronta, qui sotto)
            'orario_scambio' => $ts ?: null,
            'avvio_ts'       => time(),
            'scadenza_ts'    => time() + PRED_SIC_DURATA_ORE * 3600,   // chiusura automatica (la sposta "Sì, continua")
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
        // se e' attivo il "tasto Rispondi" (invio manuale), le risposte restano IN ATTESA
        // finche' il performer non preme "Rispondi mail": azzero il via-libera a ogni avvio.
        $stato['rispondi_ok'] = false;
        // azzera i conteggi mail della nuova sessione
        $stato['mail_ricevute'] = 0;
        $stato['mail_inviate']  = 0;
        predizione_pulisci_foto_base($stato);   // la sezione IA riparte pulita
        predizione_uso_incr($stato, 'sessioni', 1);   // consumo mensile: una sessione in piu'
        predizione_diario($stato, $nid, 'avvia');     // storia della sessione (per il report)
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
        $stato['sessioni'][(string)$id]['cambio_ts'] = time();   // orario del cambio foto (per il report)
        predizione_diario($stato, $id, 'cambia');                // storia della sessione
        $stato['forza'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'finisci':
        // FINE: smette di rispondere e chiude la sessione (la B resta congelata)
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) {
            predizione_json(['ok' => false, 'errore' => 'Nessuna sessione da terminare'], 400);
        }
        predizione_chiudi_sessione($stato, $id, 'finisci');
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
        // modo di invio: 'casella' (SMTP Tophost) | 'brevo'/'grande' (Palco) | 'ses' (Amazon)
        $modoRichiesto = predizione_invio_modo(p('valore'));
        // "Palco" (canale grande) e' permesso solo a chi ha l'estensione nel piano
        if ($modoRichiesto !== 'casella') {
            $limU = predizione_limiti_utente(predizione_utente_record($utente) ?: []);
            if (empty($limU['grandi_invii'])) {
                predizione_json(['ok' => false, 'errore' => 'Palco non attivo per il tuo piano'], 403);
            }
        }
        $stato['invio_modo'] = $modoRichiesto;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'blocca_foto':
        // spunta "blocca la Foto A": se ON, dopo "Finisci" la Foto A resta (per il prossimo show).
        $valore = strtoupper((string)p('valore'));
        $stato['blocca_foto_a'] = ($valore === 'ON' || $valore === '1' || $valore === 'TRUE');
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'rispondi_manuale':
        // interruttore "Aggiungi il tasto Invia": se ON, dopo "Avvia" le risposte
        // restano in attesa finche' non si preme "Rispondi mail". Solo il performer.
        $valore = strtoupper((string)p('valore'));
        $stato['rispondi_manuale'] = ($valore === 'ON' || $valore === '1' || $valore === 'TRUE');
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'rispondi':
        // VIA LIBERA alle risposte: da ora il robottino risponde alle mail raccolte
        // (serve solo quando "rispondi_manuale" e' attivo). Password o assistente.
        $stato['rispondi_ok'] = true;
        predizione_diario($stato, (int)($stato['sessione_corrente'] ?? 0), 'rispondi');  // storia della sessione
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'proroga':
        // "Sì, continua": rimanda lo spegnimento di sicurezza (riparte da adesso).
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) {
            predizione_json(['ok' => false, 'errore' => 'Nessuna sessione attiva'], 400);
        }
        $base = max((int)($stato['sessioni'][(string)$id]['scadenza_ts'] ?? 0), time());
        $stato['sessioni'][(string)$id]['scadenza_ts'] = $base + PRED_SIC_PROROGA_ORE * 3600;  // +3 ore
        $stato['sessioni'][(string)$id]['sicurezza_off'] = false;   // confermare riattiva gli avvisi
        predizione_diario($stato, $id, 'proroga');
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'sicurezza':
        // interruttore degli avvisi di sicurezza PER QUESTO SHOW (ON=attivi, OFF=disattivati).
        $id = (int)($stato['sessione_corrente'] ?? 0);
        if ($id <= 0 || !isset($stato['sessioni'][(string)$id])) {
            predizione_json(['ok' => false, 'errore' => 'Nessuna sessione attiva'], 400);
        }
        $valore = strtoupper((string)(p('valore') ?? ''));
        $off = ($valore === 'OFF' || $valore === '0' || $valore === 'FALSE');
        $stato['sessioni'][(string)$id]['sicurezza_off'] = $off;
        if (!$off) $stato['sessioni'][(string)$id]['scadenza_ts'] = time() + PRED_SIC_DURATA_ORE * 3600;  // riattivando, riparte il conto
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'movimenti':
        // storico dei consumi/accrediti (per "Mio account"): elenco {ts,tipo,delta,nota}
        $tipoF = strtolower((string)(p('tipo') ?? ''));
        $mv = is_array($stato['movimenti'] ?? null) ? $stato['movimenti'] : [];
        if ($tipoF !== '' && in_array($tipoF, ['mail','foto_ia','sessioni','assistente'], true)) {
            $mv = array_values(array_filter($mv, function ($r) use ($tipoF) { return ($r['tipo'] ?? '') === $tipoF; }));
        }
        // dal piu' recente al piu' vecchio, massimo 200 righe
        $mv = array_slice(array_reverse($mv), 0, 200);
        predizione_json(['ok' => true, 'movimenti' => $mv]);

    case 'messaggio':
        // oggetto e testo personalizzati della mail automatica (usati dalla Lambda)
        $stato['mail_oggetto'] = substr((string)(p('oggetto') ?? ''), 0, 200);
        $stato['mail_testo']   = substr((string)(p('testo') ?? ''), 0, 2000);
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'assistente_crea':
        // il performer crea un link "Assistente di scena" (lasciapassare temporaneo).
        // Vale finche' non si preme "Finisci" o non si revoca. Solo il performer (password).
        $tok = bin2hex(random_bytes(16));
        $stato['assistente_token'] = $tok;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'token' => $tok, 'utente' => $utente,
                         'stato' => predizione_stato_pubblico($stato)]);

    case 'assistente_revoca':
        // il performer annulla il link assistente (smette subito di funzionare).
        $stato['assistente_token'] = null;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'mail_config':
        // l'utente imposta la PROPRIA casella (serve il flag 'mail' abilitato)
        $flags = predizione_flags($utente);
        if (empty($flags['mail'])) {
            predizione_json(['ok' => false, 'errore' => 'Funzione mail non abilitata'], 403);
        }
        $utenti = predizione_utenti_leggi();
        $utenti[$utente]['mail'] = predizione_mail_da_richiesta();
        predizione_utenti_scrivi($utenti);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'email_comunicazione':
        // l'utente aggiorna la PROPRIA mail di comunicazione (ricevute/avvisi da noi).
        // Serve alla guida del primo accesso ("mi confermi che e' questa?").
        $nuova = strtolower(trim((string)p('email')));
        if ($nuova === '' || !filter_var($nuova, FILTER_VALIDATE_EMAIL)) {
            predizione_json(['ok' => false, 'errore' => 'Scrivi una mail valida'], 400);
        }
        $utenti = predizione_utenti_leggi();
        if (!isset($utenti[$utente])) predizione_json(['ok' => false, 'errore' => 'Utente non trovato'], 404);
        $utenti[$utente]['email_comunicazione'] = substr($nuova, 0, 160);
        predizione_utenti_scrivi($utenti);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'cambia_password':
        // l'utente cambia la PROPRIA password di ACCESSO al pannello.
        // La password attuale e' gia' verificata dal controllo d'accesso qui sopra.
        // Regole (come nella mail di benvenuto): >= 10 caratteri, 1 maiuscola, 1 simbolo.
        $np = (string)(p('nuova') ?? '');
        if (strlen($np) < 10 || !preg_match('/[A-Z]/', $np) || !preg_match('/[^A-Za-z0-9]/', $np)) {
            predizione_json(['ok' => false, 'errore' => 'La password deve avere almeno 10 caratteri, 1 maiuscola e 1 simbolo'], 400);
        }
        $utenti = predizione_utenti_leggi();
        if (!isset($utenti[$utente])) predizione_json(['ok' => false, 'errore' => 'Utente non trovato'], 404);
        $utenti[$utente]['pass'] = substr($np, 0, 100);
        predizione_utenti_scrivi($utenti);
        predizione_json(['ok' => true]);

    case 'foto_zona':
        // salva i 4 ANGOLI del foglio (quad) e la GOMMA (poligono, es. la mano)
        if (empty(predizione_flags($utente)['foto'])) {
            predizione_json(['ok' => false, 'errore' => 'Strumento foto non abilitato'], 403);
        }
        $pulisci = function($arr, $min) {
            if (!is_array($arr)) return null;
            $out = [];
            foreach ($arr as $pt) {
                if (is_array($pt) && isset($pt[0], $pt[1])) {
                    $out[] = [ max(0.0, min(1.0, (float)$pt[0])), max(0.0, min(1.0, (float)$pt[1])) ];
                }
            }
            return count($out) >= $min ? $out : null;
        };
        $quad = $pulisci(json_decode((string)p('quad'), true), 4);
        if (is_array($quad)) $quad = array_slice($quad, 0, 4);
        $stato['foto_quad']  = $quad;                                   // null = nessun angolo (ripiego piatto)
        $stato['foto_gomma'] = $pulisci(json_decode((string)p('gomma'), true), 3); // null = nessuna gomma
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'mail_report':
        // l'autorisponditore comunica quante mail ha RISPOSTO (incrementi).
        // NB: le RICEVUTE ora le conta 'mail_log' (una per mail, senza doppioni),
        // così il conteggio funziona anche in modalità "Rispondi a mano" (quando
        // le mail arrivano ma la risposta è ancora in attesa). Qui aggiorniamo
        // solo le INVIATE e il consumo mensile delle mail.
        $inv = max(0, (int)p('inviate'));
        $stato['mail_inviate']  = (int)($stato['mail_inviate']  ?? 0) + $inv;
        if ($inv > 0) predizione_uso_incr($stato, 'mail', $inv);   // consumo mensile mail
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true]);

    case 'mail_log':
        // l'autorisponditore registra UNA mail in arrivo (per il report di sessione
        // e per il conteggio "Mail ricevute"). È idempotente: ogni mail porta una
        // chiave stabile 'msgid'; se è già registrata non la conta una seconda volta
        // (così la modalità "Rispondi a mano", che rilegge le stesse mail ogni minuto
        // senza segnarle lette, non gonfia il contatore).
        $sid = ctype_digit((string)p('sessione')) ? (int)p('sessione') : (int)($stato['sessione_corrente'] ?? 0);
        if ($sid <= 0) predizione_json(['ok' => false, 'errore' => 'Nessuna sessione'], 400);
        predizione_prepara_cartella();
        $msgid = substr((string)(p('msgid') ?? ''), 0, 200);
        $file  = predizione_data_dir() . '/mail_sess_' . $sid . '.jsonl';
        // doppione? se questa mail (stessa chiave) è già nel registro, non rifaccio nulla
        if ($msgid !== '' && is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $riga) {
                $r = json_decode($riga, true);
                if (is_array($r) && isset($r['msgid']) && $r['msgid'] === $msgid) {
                    predizione_json(['ok' => true, 'gia' => true]);
                }
            }
        }
        $rec = [
            'ora'      => date('Y-m-d H:i:s'),
            'mittente' => substr((string)(p('mittente') ?? ''), 0, 160),
            'oggetto'  => substr((string)(p('oggetto')  ?? ''), 0, 300),
            'testo'    => substr((string)(p('testo')    ?? ''), 0, 4000),
            'id'       => substr((string)(p('id')       ?? ''), 0, 60),
            'msgid'    => $msgid,
        ];
        @file_put_contents($file, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        // ogni mail NUOVA conta come "ricevuta" (il contatore del pannello)
        $stato['mail_ricevute'] = (int)($stato['mail_ricevute'] ?? 0) + 1;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true]);

    case 'report_cancella':
        // cancella i dati personali dei mittenti (tutti i file mail_sess_*.jsonl dell'utente)
        $dir = predizione_data_dir();
        foreach (glob($dir . '/mail_sess_*.jsonl') ?: [] as $f) { @unlink($f); }
        predizione_json(['ok' => true]);

    default:
        predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
}
