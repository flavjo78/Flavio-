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

/* Chiude la sessione di PROVA della DIAGNOSTICA, SENZA toccare le foto vere
   dell'utente (foto_a, rivelazione_pronta, foto base): spegne il robottino e
   RIMUOVE la sessione di prova, così non resta nei report e non consuma nulla.
   (Diverso da predizione_chiudi_sessione, che invece congela/cancella le foto.) */
function predizione_diag_chiudi(array &$stato): void {
    $id = (int)($stato['diag']['sess'] ?? 0);
    if ($id > 0 && isset($stato['sessioni'][(string)$id])) {
        $b = $stato['sessioni'][(string)$id]['before'] ?? null;
        if (!empty($b) && is_file($b) && strpos($b, '_before.') !== false) @unlink($b);
        unset($stato['sessioni'][(string)$id]);
    }
    $stato['autorisponditore'] = false;
    $stato['forza'] = null;
    if ((int)($stato['sessione_corrente'] ?? 0) === $id) $stato['sessione_corrente'] = 0;
    unset($stato['diag']);
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
    $id = (int)($stato['sessione_corrente'] ?? 0);
    // una sessione di PROVA (diagnostica) si chiude senza toccare le foto vere
    if ($id > 0 && !empty($stato['sessioni'][(string)$id]['prova'])) {
        predizione_diag_chiudi($stato);
        return true;
    }
    predizione_chiudi_sessione($stato, $id, 'auto_fine');
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
        'mail_cfg'       => predizione_mail_cfg_pubblica(predizione_utente()),  // casella salvata (SENZA password in chiaro)
        'ha_zona'        => (!empty($s['foto_quad']) && is_array($s['foto_quad']) && count($s['foto_quad']) === 4),
        'ha_foto_base'   => (!empty($s['foto_base']) && is_file($s['foto_base'])),
        'foto_quad'      => (!empty($s['foto_quad'])  && is_array($s['foto_quad']))  ? $s['foto_quad']  : null,
        'foto_gomma'     => (!empty($s['foto_gomma']) && is_array($s['foto_gomma'])) ? $s['foto_gomma'] : null,
        'mail_ricevute'  => (int)($s['mail_ricevute'] ?? 0),
        'mail_inviate'   => (int)($s['mail_inviate'] ?? 0),
        'assistente_attivo' => !empty($s['assistente_token']),  // c'e' un link assistente attivo?
        'account'        => predizione_account_pubblico(predizione_utente()),  // piano e limiti (Mio account)
        'uso'            => predizione_uso_mese_pub($s),  // consumi del mese (+ mail_resta, può essere negativo)
        'palco_abilitato'  => !empty(predizione_limiti_utente(predizione_utente_record(predizione_utente()) ?: [])['grandi_invii']),
        'rispondi_manuale' => !empty($s['rispondi_manuale']),   // "tasto Rispondi" attivo?
        'rispondi_ok'      => !empty($s['rispondi_ok']),        // via libera gia' dato in questa sessione?
        'sicurezza'        => predizione_sicurezza($s),         // tappe anti-dimenticanza (pop-up Regia)
        'accesso'          => predizione_accesso_info(predizione_utente_record(predizione_utente()) ?: []),  // scadenza accesso 4 anni (banner/blocco)
        'calligrafia'      => predizione_calligrafia_pubblica($s),   // Foto magic: calligrafia corrente + disponibili
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

/* Consumi del mese per l'app, con l'aggiunta di 'mail_resta' (mail che restano:
   può essere NEGATIVO se durante uno show si è andati oltre il limite) e
   'debito_mail'. Così la Regia può mostrare il numero negativo. */
function predizione_uso_mese_pub(array &$s): array {
    $uso = predizione_uso_mese($s);
    $rec = predizione_utente_record(predizione_utente());
    if ($rec && function_exists('predizione_rimaste')) {
        $uso['mail_resta']  = predizione_rimaste($s, $rec, 'mail');
        $uso['debito_mail'] = (int)($s['debito']['mail'] ?? 0);
    }
    return $uso;
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
    return ['user' => (string)($m['user'] ?? ''), 'pass' => (string)($m['pass'] ?? ''),
            'from' => (string)($m['from'] ?? ''), 'login' => (string)($m['login'] ?? ''),
            'tipo' => predizione_casella_tipo($m)];
}
/* Versione PUBBLICA della config casella: come predizione_mail_cfg ma SENZA la
   password in chiaro (che non deve mai uscire nella risposta pubblica di 'stato',
   leggibile da chiunque). Al posto della password mette solo 'pass_impostata'
   (true/false), così l'app sa se c'è già una password senza vederla. */
function predizione_mail_cfg_pubblica(string $u): array {
    $m = predizione_mail_cfg($u);
    $m['pass_impostata'] = ((string)($m['pass'] ?? '') !== '');
    $m['pass'] = '';
    return $m;
}

/* Che tipo di casella usa l'utente: 'gmail' | 'altro' | '' (nessuna casella).
   Usa il 'tipo' salvato; se manca (utenti vecchi) lo deduce dall'indirizzo. */
function predizione_casella_tipo(array $m): string {
    $t = strtolower((string)($m['tipo'] ?? ''));
    if ($t === 'gmail' || $t === 'altro') return $t;
    $user = (string)($m['user'] ?? '');
    if ($user === '') return '';
    $d = strtolower(substr(strrchr($user, '@') ?: '', 1));
    return ($d === 'gmail.com' || $d === 'googlemail.com') ? 'gmail' : 'altro';
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
                    'casella_tipo'   => predizione_casella_tipo(isset($rec['mail']) ? (array)$rec['mail'] : []), // gmail|altro|''
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
                'accesso_info' => predizione_accesso_info(is_array($rec) ? $rec : []),  // scaduto/giorni/promemoria
                'piano_scad'   => (int)($rec['piano_scadenza'] ?? 0),
                'fonte'    => (string)($rec['fonte'] ?? ''),                 // provenienza (etichetta)
                'report_extra' => array_key_exists('report_extra', $rec) ? (bool)$rec['report_extra'] : null,
                'report'   => (bool)$lim['report'],
                'limiti'   => $lim,
                'sessioni' => $sessOut,
                'ha_giocato' => !empty($sessOut),   // se ha sessioni NON si puo' rinominare (link gia' inviati)
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
            // 'auto' e 'invio' arrivano dal pannello; se non arrivano (vecchio pannello)
            // li allineo a 'mail' per non disattivarli di nascosto.
            $fmail = (p('mail') === '1');
            $utenti[$num]['flags'] = [
                'mail'  => $fmail,
                'foto'  => (p('foto') === '1'),
                'auto'  => (p('auto')  !== null) ? (p('auto')  === '1') : $fmail,
                'invio' => (p('invio') !== null) ? (p('invio') === '1') : $fmail,
            ];
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
            // la base "aaa" (diretto) sempre in cima, lettera 'a' (puo' essere disattivata)
            $out[] = ['password' => PRED_ACCESSO_BASE, 'etichetta' => 'diretto', 'lettera' => 'a', 'creato' => 0,
                      'base' => true, 'off' => predizione_accesso_base_off()];
            foreach ($acc as $pw => $info) {
                $out[] = ['password' => (string)$pw, 'etichetta' => (string)($info['etichetta'] ?? ''),
                          'lettera' => (string)($info['lettera'] ?? ''), 'creato' => (int)($info['creato'] ?? 0)];
            }
            predizione_json(['ok' => true, 'accessi' => $out, 'base' => PRED_ACCESSO_BASE,
                             'base_off' => predizione_accesso_base_off()]);

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
            if ($pw === PRED_ACCESSO_BASE) {   // la base non si cancella: si DISATTIVA
                predizione_accesso_base_set(true);
                predizione_json(['ok' => true, 'base_off' => true]);
            }
            $acc = predizione_accessi_leggi();
            unset($acc[$pw]);
            predizione_accessi_scrivi($acc);
            predizione_json(['ok' => true]);

        case 'admin_accesso_base':   // attiva/disattiva la base "aaa"
            $att = (p('attiva') === '1' || p('attiva') === 'true' || p('attiva') === 1 || p('attiva') === true);
            predizione_accesso_base_set(!$att);   // attiva=1 -> non spenta
            predizione_json(['ok' => true, 'base_off' => predizione_accesso_base_off()]);

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

        case 'admin_rinomina':
            // Cambia l'USERNAME di accesso di un utente. L'username e' anche il
            // nome della cartella dati (_dati/u<nome>) ed e' dentro i link
            // image.php?u=<nome> gia' inviati: per questo si puo' rinominare SOLO
            // se l'utente NON ha ancora fatto sessioni (altrimenti si romperebbero
            // le foto gia' spedite). 000 e 001 non si rinominano mai.
            $old = predizione_pulisci_utente(p('numero'));
            $new = predizione_pulisci_utente(p('nuovo'));
            if (!isset($utenti[$old])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            if ($old === '000' || $old === '001') predizione_json(['ok' => false, 'errore' => 'Questo utente non si puo\' rinominare'], 400);
            if ($new === '' || $new === '000' || $new === '001') predizione_json(['ok' => false, 'errore' => 'Nome nuovo non valido'], 400);
            if ($new === $old) predizione_json(['ok' => false, 'errore' => 'E\' gia\' questo il nome'], 400);
            if (isset($utenti[$new])) predizione_json(['ok' => false, 'errore' => 'Esiste gia\' un utente con questo nome'], 400);
            // ha gia' giocato? (sessioni fatte) -> blocco
            $prevU = predizione_utente();
            predizione_set_utente($old);
            $stOld = predizione_leggi_stato();
            $haGiocato = !empty($stOld['sessioni']) || (int)($stOld['sessione_corrente'] ?? 0) > 0;
            predizione_set_utente($prevU);
            if ($haGiocato) {
                predizione_json(['ok' => false, 'errore' => 'Questo utente ha gia\' fatto spettacoli: rinominarlo romperebbe le foto gia\' inviate. Cambio non permesso.'], 400);
            }
            // sposta la cartella dati (se c'e') e la scheda sotto il nuovo nome
            $oldDir = DATA_DIR . '/u' . $old;
            $newDir = DATA_DIR . '/u' . $new;
            if (is_dir($oldDir) && !is_dir($newDir)) { @rename($oldDir, $newDir); }
            $rec = $utenti[$old];
            unset($utenti[$old]);
            $utenti[$new] = $rec;
            predizione_utenti_scrivi($utenti);
            predizione_log_operazione('rinomina', $new, 'era ' . $old);
            predizione_json(['ok' => true, 'nuovo' => $new]);

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

        case 'admin_prompt_ia':   // CALIGRAFIA FOTO MAGIC: legge l'elenco prompt + quello attivo + il predefinito
            predizione_json(['ok' => true,
                'prompt'  => predizione_prompts_ia_leggi(),
                'default' => predizione_prompt_ia_default(),
            ]);

        case 'admin_prompt_ia_salva':   // salva l'elenco prompt + quale è attivo
            $d = json_decode((string)(p('dati') ?? ''), true);
            if (!is_array($d) || empty($d['lista']) || !is_array($d['lista'])) {
                predizione_json(['ok' => false, 'errore' => 'Dati non validi'], 400);
            }
            $lista = [];
            foreach ($d['lista'] as $pr) {
                if (!is_array($pr)) continue;
                $pid   = preg_replace('/[^a-z0-9._-]/', '', strtolower((string)($pr['id'] ?? '')));
                $pnome = trim((string)($pr['nome'] ?? ''));
                $ptest = trim((string)($pr['testo'] ?? ''));
                if ($pid === '' || $ptest === '') continue;   // scarta righe vuote
                if ($pnome === '') $pnome = $pid;
                $lista[] = ['id' => substr($pid, 0, 40), 'nome' => substr($pnome, 0, 80), 'testo' => substr($ptest, 0, 6000),
                            'disponibile' => !empty($pr['disponibile'])];
            }
            if (!$lista) predizione_json(['ok' => false, 'errore' => 'Serve almeno un prompt valido'], 400);
            $attivo = preg_replace('/[^a-z0-9._-]/', '', strtolower((string)($d['attivo'] ?? '')));
            $ids = array_column($lista, 'id');
            if (!in_array($attivo, $ids, true)) $attivo = $lista[0]['id'];
            predizione_prompts_ia_scrivi(['attivo' => $attivo, 'lista' => $lista]);
            predizione_json(['ok' => true, 'prompt' => predizione_prompts_ia_leggi()]);

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
                            'prezzo' => (float)$e['prezzo'], 'tipo' => (string)$e['tipo'], 'qta' => (int)$e['qta'],
                            'ricorrenza' => (string)($e['ricorrenza'] ?? 'una_tantum')];
            }
            predizione_json(['ok' => true, 'listino' => [
                'piani' => $piani, 'extra' => $extra,
                'accesso' => predizione_accesso_prezzo(),
                'accesso_pieno' => predizione_accesso_pieno(),
                'accesso_anni' => predizione_accesso_anni(),
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
            $accessoN = max(0, (int)($in['accesso'] ?? predizione_accesso_prezzo()));
            $accessoP = max(0, (int)($in['accesso_pieno'] ?? 0)); if ($accessoP < $accessoN) $accessoP = $accessoN;
            $accessoA = max(1, (int)($in['accesso_anni'] ?? predizione_accesso_anni()));
            $nuovo = ['piani' => [], 'extra' => [], 'accesso' => $accessoN, 'accesso_pieno' => $accessoP,
                      'accesso_anni' => $accessoA, 'versioni' => $versioni, 'storico' => $storico];
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
                if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni', 'assistente', 'report'], true)) $tipo = 'mail';
                $dataE = (string)($e['data'] ?? '');
                if ($dataE !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataE)) $dataE = '';
                $nuovo['extra'][$code] = ['prezzo' => max(0, (float)($e['prezzo'] ?? 0)), 'tipo' => $tipo,
                                          'qta' => max(1, (int)($e['qta'] ?? 1)), 'label' => substr((string)($e['label'] ?? $code), 0, 60),
                                          'mesi' => max(0, (int)($e['mesi'] ?? 1)), 'data' => $dataE];
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

        case 'admin_cronologia':
            // Dati per il GRAFICO della scheda utente: acquisti (data+importo) +
            // serie dei consumi/crediti (mail/foto/sessioni) dallo storico movimenti,
            // piu' quanto RESTA adesso di ciascun tipo (per ricostruire la linea).
            $num = predizione_pulisci_utente(p('numero'));
            if ($num === '') predizione_json(['ok' => false, 'errore' => 'utente mancante'], 400);
            $utenti = predizione_utenti_leggi();
            $rec = (isset($utenti[$num]) && is_array($utenti[$num])) ? $utenti[$num] : [];
            // --- acquisti (ts in millisecondi + importo + etichetta) ---
            $acq = [];
            $file = predizione_vendite_file();
            if (is_file($file) && ($fh = @fopen($file, 'r'))) {
                fgetcsv($fh);  // intestazione
                while (($r = fgetcsv($fh)) !== false) {
                    if (($r[2] ?? '') !== $num) continue;   // colonna utente
                    $ts = @strtotime((string)($r[0] ?? '')); if (!$ts) $ts = 0;
                    $lab = trim((string)($r[5] ?? '') . ((($r[6] ?? '') !== '') ? ' (' . $r[6] . ')' : ''));
                    $acq[] = ['ts' => $ts * 1000, 'v' => (float)($r[7] ?? 0), 'lab' => ($lab !== '' ? $lab : 'acquisto')];
                }
                fclose($fh);
            }
            // --- stato dell'utente: movimenti + uso del mese ---
            $prevU = predizione_utente();
            predizione_set_utente($num);
            $st = predizione_leggi_stato();
            predizione_uso_mese($st);
            $uso = (isset($st['uso']) && is_array($st['uso'])) ? $st['uso'] : [];
            $mov = (isset($st['movimenti']) && is_array($st['movimenti'])) ? $st['movimenti'] : [];
            predizione_set_utente($prevU);
            $lim = predizione_limiti_utente($rec);
            // --- serie per tipo: quanto RESTA ora + lista movimenti (ts+delta) ---
            $serie = [];
            foreach ([['mail', 'mail_mese'], ['foto_ia', 'foto_ia_mese'], ['sessioni', 'sessioni_mese']] as $pr) {
                $tipo = $pr[0]; $capKey = $pr[1];
                $cap = (int)($lim[$capKey] ?? 0); $usato = (int)($uso[$tipo] ?? 0);
                $resta = max(0, $cap - $usato) + predizione_crediti_disponibili($rec, $tipo);
                $m = [];
                foreach ($mov as $mm) {
                    if (($mm['tipo'] ?? '') !== $tipo) continue;
                    $m[] = ['ts' => ((int)($mm['ts'] ?? 0)) * 1000, 'delta' => (int)($mm['delta'] ?? 0)];
                }
                $serie[$tipo] = ['resta' => $resta, 'mov' => $m];
            }
            predizione_json(['ok' => true, 'acquisti' => $acq, 'serie' => $serie, 'ora' => time() * 1000]);

        case 'admin_caselle':
            // Elenco delle caselle configurate, per l'autorisponditore MULTI-CASELLA.
            // Esclude 000 (admin) e 001 (usa la casella "di casa" via variabili d'ambiente
            // nella Lambda), gli utenti non attivi e quelli senza casella impostata.
            // Restituisce credenziali IMAP/SMTP + stato del gioco di ciascun utente, cosi'
            // la Lambda non deve fare altre chiamate. SOLO amministratore (gia' verificato).
            // PROMEMORIA SCADENZA ACCESSO (automatico): il robottino chiama questa
            // azione ~ogni minuto, cosi' il promemoria "fra un mese scade l'accesso"
            // parte da solo anche ad app chiusa. Inviato UNA sola volta per utente.
            $cambiati = false;
            foreach ($utenti as $num => $rec) {
                if ($num === '000' || $num === '001') continue;
                if (!is_array($rec)) continue;
                if (predizione_accesso_promemoria_forse($rec)) { $utenti[$num] = $rec; $cambiati = true; }
            }
            if ($cambiati) predizione_utenti_scrivi($utenti);

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

        case 'admin_limiti':
            // Modifica i LIMITI di un utente SENZA cambiare piano: salva i numeri
            // in rec['piano_dati'] (lo snapshot che predizione_limiti_utente preferisce).
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $rec = $utenti[$num];
            // base: lo snapshot attuale, altrimenti i dati del piano corrente
            if (isset($rec['piano_dati']) && is_array($rec['piano_dati'])) {
                $base = $rec['piano_dati'];
            } else {
                $piani = predizione_piani();
                $pn = (string)($rec['piano'] ?? 'Foyer');
                $base = $piani[$pn] ?? reset($piani);
            }
            $gi = function ($k, $def) { $v = p($k); return ($v === null || $v === '') ? $def : (int)$v; };
            $base['mail_mese']       = max(0, $gi('mail_mese',       (int)($base['mail_mese'] ?? 0)));
            $base['mail_giorno']     = max(0, $gi('mail_giorno',     (int)($base['mail_giorno'] ?? 0)));
            $base['foto_ia_mese']    = max(0, $gi('foto_ia_mese',    (int)($base['foto_ia_mese'] ?? 0)));
            $base['sessioni_mese']   = max(0, $gi('sessioni_mese',   (int)($base['sessioni_mese'] ?? 0)));
            $base['sessioni_giorno'] = max(0, $gi('sessioni_giorno', (int)($base['sessioni_giorno'] ?? 0)));
            $base['assistente']      = $gi('assistente',             (int)($base['assistente'] ?? 0)); // -1 illimitato, 0 no, N quota
            if (p('report')       !== null) $base['report']       = (p('report') === '1');
            if (p('grandi_invii') !== null) $base['grandi_invii'] = (p('grandi_invii') === '1');
            $utenti[$num]['piano_dati'] = predizione_piano_normalizza($base);
            predizione_utenti_scrivi($utenti);
            predizione_json(['ok' => true, 'limiti' => predizione_limiti_utente($utenti[$num])]);

        case 'admin_accesso_scad':
            // Modifica la SCADENZA dell'accesso all'app (4 anni di default).
            // 'data' = 'YYYY-MM-DD' (fine giornata); 'anni' = rinnova di N anni da oggi;
            // vuoto/0 = "non scade". Azzera anche il flag del promemoria gia' inviato.
            $num = predizione_pulisci_utente(p('numero'));
            if (!isset($utenti[$num])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $data = trim((string)(p('data') ?? ''));
            $anni = p('anni');
            if ($anni !== null && $anni !== '') {
                $utenti[$num]['accesso_scadenza'] = time() + max(0, (int)$anni) * 31557600;
            } elseif ($data === '' || $data === '0') {
                $utenti[$num]['accesso_scadenza'] = 0;   // non scade
            } else {
                $ts = @strtotime($data . ' 23:59:59');
                if (!$ts) predizione_json(['ok' => false, 'errore' => 'Data non valida (usa AAAA-MM-GG)'], 400);
                $utenti[$num]['accesso_scadenza'] = (int)$ts;
            }
            unset($utenti[$num]['accesso_promemoria_inviato']);   // potra' ri-avvisare per la nuova scadenza
            predizione_utenti_scrivi($utenti);
            predizione_log_operazione('accesso_scad', $num, 'scadenza accesso aggiornata');
            predizione_json(['ok' => true, 'accesso_info' => predizione_accesso_info($utenti[$num])]);

        case 'admin_prova_invio':
            // TEST del robottino: riempie una casella di tante mail. Usa le credenziali
            // SMTP di un utente GIA' configurato (mitt = numero utente) -> niente password
            // da ridigitare. Spedisce a 'dest' (indirizzo della casella del gioco da riempire).
            require_once __DIR__ . '/smtp.php';
            $mnum = predizione_pulisci_utente(p('mitt'));          // utente mittente
            $dest = substr(trim((string)(p('dest') ?? '')), 0, 160);
            $n    = (int)(p('quante') ?? 20);
            if ($n < 1) $n = 1; if ($n > 150) $n = 150;
            if (!isset($utenti[$mnum])) predizione_json(['ok' => false, 'errore' => 'Utente mittente assente'], 404);
            $mc = predizione_mail_cfg($mnum);
            if ($mc['user'] === '' || $mc['pass'] === '') {
                predizione_json(['ok' => false, 'errore' => 'Il mittente non ha una casella configurata (email + password per app)'], 400);
            }
            if ($dest === '') predizione_json(['ok' => false, 'errore' => 'Scrivi la casella del gioco (destinatario)'], 400);
            if (strcasecmp($dest, $mc['user']) === 0) {
                predizione_json(['ok' => false, 'errore' => 'Mittente e destinatario non possono essere la stessa casella (il robottino risponderebbe a sé stesso)'], 400);
            }
            [$host, $port] = pred_smtp_host($mc['user']);
            $esito = pred_smtp_invia_blocco($host, $port, $mc['user'], $mc['pass'],
                       $mc['user'], ($mc['from'] !== '' ? $mc['from'] : 'Prova Predizione'), $dest, $n);
            predizione_json(['ok' => true] + $esito);

        case 'admin_prova_casella':
            // TEST COMPLETO della casella di un utente: LETTURA (IMAP, quella che usa il
            // robottino) + INVIO (SMTP) con prova a prova@abraka.it. Dice OK o l'errore.
            require_once __DIR__ . '/smtp.php';
            $cnum = predizione_pulisci_utente(p('numero') ?: p('mitt'));
            if (!isset($utenti[$cnum])) predizione_json(['ok' => false, 'errore' => 'Utente assente'], 404);
            $mc = predizione_mail_cfg($cnum);
            $dest = substr(trim((string)(p('dest') ?? '')), 0, 160) ?: 'prova@abraka.it';
            predizione_json(pred_testa_casella($mc, $dest));
    }
}

/* Costruisce la configurazione casella (Gmail) dai parametri ricevuti. */
function predizione_mail_da_richiesta(): array {
    $email   = substr((string)(p('email') ?? ''), 0, 160);
    $apppass = substr((string)(p('app_password') ?? ''), 0, 200);
    $from    = substr((string)(p('from') ?? ''), 0, 160);
    // 'login' = nome con cui si entra nella casella, SE diverso dall'indirizzo.
    // Serve per le caselle (es. Tophost) dove il login e' il nome della MAILBOX
    // (es. "abraka.it") e non l'indirizzo completo ("io@abraka.it"). Per Gmail
    // si lascia vuoto (login = indirizzo). Se vuoto, si usa l'indirizzo.
    $login   = substr((string)(p('login') ?? ''), 0, 160);
    // 'tipo' = che genere di casella: 'gmail' oppure 'altro' (casella di dominio,
    // es. Tophost). Serve solo per mostrare le scritte giuste nell'app e per far
    // vedere nel pannello 000 che tipo di casella usa ogni utente. Se non arriva,
    // lo deduciamo dall'indirizzo (dominio gmail.com -> gmail, altrimenti altro).
    $tipo    = strtolower(substr((string)(p('tipo') ?? ''), 0, 10));
    if ($tipo !== 'gmail' && $tipo !== 'altro') {
        $dom  = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $tipo = ($dom === 'gmail.com' || $dom === 'googlemail.com') ? 'gmail' : 'altro';
    }
    // Password per le app di Google: Google la MOSTRA a gruppi di 4 con gli SPAZI
    // (es. "abcd efgh ijkl mnop"), ma la password vera è di 16 lettere SENZA spazi.
    // Se l'utente la incolla con gli spazi, il login fallisce "senza motivo": li
    // togliamo noi (solo per le caselle Gmail, dove non ci sono mai spazi validi).
    if ($tipo === 'gmail' && $apppass !== '') $apppass = preg_replace('/\s+/', '', $apppass);
    return [
        'imap_host' => 'imap.gmail.com', 'imap_port' => 993,
        'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587,
        'user' => $email, 'pass' => $apppass,
        'login' => $login, 'tipo' => $tipo,
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

/* ---- ACCESSO SCADUTO: blocca le funzioni operative (gioco + invii) ------
   Dopo i 4 anni (o la data impostata) l'app si mette in pausa: non si avvia
   un gioco, non si invia, non si accende il robottino, finché non si rinnova.
   Restano possibili la lettura, il Mio account e la configurazione casella. */
$recAttuale = predizione_utente_record($utente);
if ($recAttuale && predizione_accesso_scaduto($recAttuale)) {
    $AZIONI_BLOCCATE_SCAD = ['avvia', 'cambia', 'rispondi', 'autorisponditore', 'invio', 'proroga', 'sicurezza'];
    if (in_array($azione, $AZIONI_BLOCCATE_SCAD, true)) {
        predizione_json(['ok' => false, 'scaduto' => true,
            'errore' => 'Accesso scaduto. Per continuare a usare l\'app devi rinnovare l\'accesso: scrivici a supporto@abraka.it.'], 403);
    }
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

        // LIMITE SESSIONI: se non ne restano (piano del mese finito E niente crediti)
        // la nuova sessione NON parte. Prima si avviava comunque, oltre il limite.
        $recSess = predizione_utente_record(predizione_utente());
        if ($recSess) {
            predizione_uso_mese($stato);
            // SESSIONI: un nuovo show non parte se non ne restano (non vanno in negativo).
            if (predizione_rimaste($stato, $recSess, 'sessioni') < 1) {
                predizione_json(['ok' => false, 'limite' => 'sessioni',
                    'errore' => 'Hai finito le sessioni di questo mese. Acquista altre sessioni oppure attendi il rinnovo del piano.'], 403);
            }
            // MAIL: durante uno show le mail non si bloccano mai (vanno in negativo),
            // ma un NUOVO show non parte se le mail sono a zero o in negativo. Il
            // negativo si ripiana al prossimo rinnovo (le mail nuove lo colmano prima).
            if (predizione_rimaste($stato, $recSess, 'mail') <= 0) {
                predizione_json(['ok' => false, 'limite' => 'mail',
                    'errore' => 'Mail esaurite (sei in negativo): un nuovo show non può partire. Attendi il rinnovo del piano oppure aggiungi mail. (Lo show già avviato finisce sempre.)'], 403);
            }
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
        predizione_consuma($stato, 'sessioni', 1);   // consumo: prima il piano del mese, poi i crediti
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
        $acceso = ($valore === 'ON' || $valore === '1' || $valore === 'TRUE');
        // la funzione "autorisponditore" dev'essere inclusa per questo utente
        if ($acceso && empty(predizione_flags($utente)['auto'])) {
            predizione_json(['ok' => false, 'errore' => 'Autorisponditore non incluso in questo piano'], 403);
        }
        $stato['autorisponditore'] = $acceso;
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

    case 'calligrafia':
        // scelta della CALLIGRAFIA della Foto magic PER QUESTO utente.
        // Ammessa solo tra quelle rese "disponibili" dall'admin (pannello 000).
        $cid = preg_replace('/[^a-z0-9._-]/', '', strtolower((string)(p('calligrafia') ?? p('id') ?? '')));
        $disp = array_column(predizione_prompts_ia_disponibili(), 'id');
        if ($cid === '' || !in_array($cid, $disp, true)) {
            predizione_json(['ok' => false, 'errore' => 'Calligrafia non disponibile'], 400);
        }
        $stato['calligrafia_id'] = $cid;
        predizione_scrivi_stato($stato);
        predizione_json(['ok' => true, 'stato' => predizione_stato_pubblico($stato)]);

    case 'prova_casella':
        // TEST della PROPRIA casella: LETTURA (IMAP, come il robottino) + INVIO (SMTP)
        // con una prova a prova@abraka.it. Dice OK o l'errore preciso, così si capisce
        // se il robottino potrà funzionare (es. su Gmail serve l'IMAP attivo).
        require_once __DIR__ . '/smtp.php';
        $mcp = predizione_mail_cfg(predizione_utente());
        $destp = substr(trim((string)(p('dest') ?? '')), 0, 160) ?: 'prova@abraka.it';
        predizione_json(pred_testa_casella($mcp, $destp));

    case 'mail_svela':
        // Mostra all'utente (GIÀ autenticato con la sua password) la "password per
        // le app" a 16 lettere della PROPRIA casella, per controllarla a occhio
        // (es. una lettera sbagliata, o è la password di un altro indirizzo). NON
        // esce mai nello stato pubblico: serve il login, come tutte le azioni qui.
        predizione_json(['ok' => true, 'mail' => predizione_mail_cfg(predizione_utente())]);

    case 'diag_start':
        // DIAGNOSTICA AUTOMATICA — passo 1: avvia un gioco di PROVA (non consuma
        // limiti, non entra nei report) e manda una mail "spettatore" dalla casella
        // prova@ alla casella-gioco. Il robottino vero (AWS) dovrà rispondere; il
        // passo 2 (diag_check) controlla che la risposta sia arrivata su prova@.
        require_once __DIR__ . '/smtp.php';
        @set_time_limit(60);
        $mcD = predizione_mail_cfg($utente);
        if ((string)($mcD['user'] ?? '') === '' || (string)($mcD['pass'] ?? '') === '') {
            predizione_json(['ok' => false, 'errore' => 'Casella non configurata: salva prima indirizzo e password.'], 400);
        }
        // c'è un gioco VERO in corso? (non una prova) -> non si può fare la diagnostica
        $scAtt = predizione_sessione_corrente($stato);
        if ($scAtt && in_array(($scAtt['fase'] ?? ''), ['avviato', 'cambiato'], true) && empty($scAtt['prova'])) {
            predizione_json(['ok' => false, 'attivo' => true,
                'errore' => 'C\'è un gioco in corso. Termina prima il gioco, poi rifai la diagnostica.']);
        }
        // ripulisci una diagnostica eventualmente rimasta appesa
        if (!empty($stato['diag'])) { predizione_diag_chiudi($stato); }
        $pvD = predizione_prova_casella();
        if (!$pvD) {
            predizione_json(['ok' => false, 'errore' => 'Spettatore di prova non configurato sul server (_dati/prova_casella.json).']);
        }
        // UID già presenti in prova@ provenienti DA questa casella (per non confondere
        // con risposte vecchie): la "linea di partenza" del controllo.
        $uidsPrima = pred_prova_uids_da($pvD, (string)$mcD['user']);
        $uidBase   = (is_array($uidsPrima) && $uidsPrima) ? max($uidsPrima) : 0;
        // crea la sessione di PROVA (Foto A = quella dell'utente o la neutra predefinita)
        $foto_aD = (!empty($stato['foto_a']) && is_file($stato['foto_a'])) ? $stato['foto_a'] : __DIR__ . '/neutro.png';
        $nidD    = (int)($stato['ultimo_id'] ?? 0) + 1;
        $extD    = strtolower(pathinfo($foto_aD, PATHINFO_EXTENSION)) ?: 'png';
        $beforeD = predizione_data_dir() . '/sess_' . $nidD . '_before.' . $extD;
        @copy($foto_aD, $beforeD);
        $stato['sessioni'][(string)$nidD] = [
            'fase' => 'avviato', 'before' => $beforeD, 'after' => null, 'orario_scambio' => null,
            // avvio un po' INDIETRO: così la mail dello spettatore (inviata ora) non
            // viene scartata dal robottino come "arrivata prima dell'avvio" per via
            // di piccole differenze di orologio tra i server.
            'avvio_ts' => time() - 300, 'scadenza_ts' => time() + 900, 'prova' => true,
        ];
        // memorizzo i contatori per rimetterli a posto a fine prova
        $stato['diag'] = ['sess' => $nidD, 'uid_base' => $uidBase, 'start_ts' => time(),
                          'mr' => (int)($stato['mail_ricevute'] ?? 0), 'mi' => (int)($stato['mail_inviate'] ?? 0)];
        $stato['sessione_corrente'] = $nidD;
        $stato['ultimo_id']         = $nidD;
        $stato['forza']             = null;
        $stato['autorisponditore']  = true;    // il robottino deve rispondere
        $stato['rispondi_ok']       = true;    // niente "attesa Rispondi mail" durante la prova
        $stato['mail_ricevute']     = 0;
        $stato['mail_inviate']      = 0;
        predizione_scrivi_stato($stato);
        // manda la mail "spettatore" DALLA casella prova@ ALLA casella-gioco
        $tokD  = substr(bin2hex(random_bytes(3)), 0, 6);
        $subD  = 'Spettatore di prova PREDIZIONE ' . $tokD;
        $bodyD = '<p>Verifica automatica: il robottino dovrebbe rispondere a questa mail con la foto.</p>';
        $sndD  = pred_prova_manda($pvD, (string)$mcD['user'], $subD, $bodyD);
        predizione_json(['ok' => true, 'inviata' => (bool)$sndD['ok'], 'errore_invio' => $sndD['ok'] ? '' : (string)$sndD['err']]);

    case 'diag_check':
        // DIAGNOSTICA — passo 2: la risposta del robottino è arrivata su prova@?
        require_once __DIR__ . '/smtp.php';
        @set_time_limit(40);
        if (empty($stato['diag'])) predizione_json(['ok' => true, 'trovato' => false, 'nessuna' => true]);
        $mcC = predizione_mail_cfg($utente);
        $pvC = predizione_prova_casella();
        if (!$pvC) predizione_json(['ok' => true, 'trovato' => false]);
        $uidsOra = pred_prova_uids_da($pvC, (string)$mcC['user']);
        $base    = (int)($stato['diag']['uid_base'] ?? 0);
        $trovato = false;
        if (is_array($uidsOra)) { foreach ($uidsOra as $u) { if ((int)$u > $base) { $trovato = true; break; } } }
        predizione_json(['ok' => true, 'trovato' => $trovato]);

    case 'diag_end':
        // DIAGNOSTICA — passo 3: chiude la prova (senza toccare le foto vere),
        // rimette i contatori e, se è andata male, manda l'ALLERTA all'autore.
        $esitoD = strtolower((string)(p('esito') ?? ''));   // 'ok' | 'fail'
        $mcE    = predizione_mail_cfg($utente);
        $mrE    = (int)($stato['diag']['mr'] ?? 0);
        $miE    = (int)($stato['diag']['mi'] ?? 0);
        predizione_diag_chiudi($stato);
        $stato['mail_ricevute'] = $mrE;
        $stato['mail_inviate']  = $miE;
        predizione_scrivi_stato($stato);
        if ($esitoD === 'ok') {
            predizione_json(['ok' => true, 'esito' => 'ok',
                'messaggio' => 'Diagnostica superata: il robottino legge la casella e risponde. ✓']);
        }
        // FALLITA -> allerta a PRED_DIAG_ALERT (da supporto@)
        $alertTo = defined('PRED_DIAG_ALERT') ? PRED_DIAG_ALERT : 'appisolata@gmail.com';
        @predizione_mail_semplice($alertTo, 'ALLERTA — diagnostica NON superata (' . $utente . ')',
            "La diagnostica dell'utente \"$utente\" (casella " . (string)($mcE['user'] ?? '?') . ") NON e' stata superata.\n"
            . "Il robottino non ha risposto alla mail di prova entro il tempo previsto.\n\n"
            . "Cose da controllare: IMAP attivo nella casella, password per le app giusta di QUELL'indirizzo,\n"
            . "eventuale blocco di Google (accesso sospetto da approvare).\n\n"
            . date('d/m/Y H:i'));
        predizione_json(['ok' => true, 'esito' => 'fail',
            'messaggio' => 'Diagnostica NON superata. Contatta supporto@abraka.it.']);

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
        $nuovaMail = predizione_mail_da_richiesta();
        // se la password non e' stata reinserita (campo lasciato vuoto, perche' non la
        // mostriamo piu' in chiaro), TIENI quella gia' salvata invece di cancellarla.
        if ($nuovaMail['pass'] === '' && !empty($utenti[$utente]['mail']['pass'])) {
            $nuovaMail['pass'] = (string)$utenti[$utente]['mail']['pass'];
        }
        $utenti[$utente]['mail'] = $nuovaMail;
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
        // in modalità DIAGNOSTICA (sessione di prova) le mail NON consumano il limite
        $scMR = predizione_sessione_corrente($stato);
        $provaMR = ($scMR && !empty($scMR['prova']));
        if ($inv > 0 && !$provaMR) predizione_consuma($stato, 'mail', $inv);   // consumo: prima il piano del mese, poi i crediti
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
        $inviataFlag = !empty(p('inviata'));   // il robottino ha ANCHE risposto adesso
        $file  = predizione_data_dir() . '/mail_sess_' . $sid . '.jsonl';
        // doppione? se questa mail (stessa chiave) è già nel registro, non rifaccio nulla.
        // UNICA eccezione: se adesso c'è stata la RISPOSTA (inviata) e prima non c'era
        // l'ora d'invio, aggiorno quella riga aggiungendo 'inviata' (per il report).
        if ($msgid !== '' && is_file($file)) {
            $righe = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($righe as $ri => $riga) {
                $r = json_decode($riga, true);
                if (is_array($r) && isset($r['msgid']) && $r['msgid'] === $msgid) {
                    if ($inviataFlag && empty($r['inviata'])) {
                        $r['inviata'] = date('Y-m-d H:i:s');
                        if (($rb = p('robot')) !== null && $rb !== '') $r['robot'] = (int)$rb;
                        if (($pp = p('parti')) !== null && $pp !== '') $r['parti'] = (int)$pp;
                        $righe[$ri] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        @file_put_contents($file, implode("\n", $righe) . "\n", LOCK_EX);
                    }
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
        if ($inviataFlag) {
            $rec['inviata'] = date('Y-m-d H:i:s');   // ora della risposta
            if (($rb = p('robot')) !== null && $rb !== '') $rec['robot'] = (int)$rb;   // quale robottino (R1, R2, ...)
            if (($pp = p('parti')) !== null && $pp !== '') $rec['parti'] = (int)$pp;   // quanti robottini in quel giro
        }
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
