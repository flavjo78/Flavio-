<?php
/* =========================================================================
   PREDIZIONE — ORDINI (flusso d'acquisto)
   -------------------------------------------------------------------------
   Riceve l'ordine dal sito, lo salva "in attesa", manda le mail "come pagare"
   (al cliente) e "nuovo ordine" (a supporto). Quando l'ordine risulta PAGATO
   (clic dell'admin), chiama predizione_attiva_ordine() e manda la mail di
   benvenuto con la password.

   Azioni:
     crea         (POST, pubblico)  -> crea l'ordine, ritorna id+importo+istruzioni
     stato        (GET,  pubblico)  -> stato di un ordine (id)
     admin_ordini (POST, admin)     -> elenco ordini
     admin_attiva (POST, admin)     -> segna pagato e ATTIVA (crea utente)
     admin_annulla(POST, admin)     -> annulla un ordine in attesa

   Dati: _dati/ordini.json . Coordinate di pagamento: _dati/pagamenti.json
   (non versionato; se manca si usano segnaposto).
   ========================================================================= */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/piani.php';

/* Indirizzo dell'autore a cui notificare ogni acquisto a buon fine.
   Cambialo qui se vuoi riceverlo su un'altra casella. */
const PRED_NOTIFY_EMAIL = 'appisolata@gmail.com';

/* ---------- utilita' ---------------------------------------------------- */
function ord_file(): string { return DATA_DIR . '/ordini.json'; }
function ord_leggi(): array {
    predizione_prepara_cartella();
    $d = json_decode((string)@file_get_contents(ord_file()), true);
    return is_array($d) ? $d : [];
}
function ord_scrivi(array $o): bool {
    predizione_prepara_cartella();
    return @file_put_contents(ord_file(), json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}
function ord_prossimo_id(array $ordini): string {
    $max = 0;
    foreach (array_keys($ordini) as $k) {
        if (preg_match('/PRD-(\d+)/', $k, $m)) { $n = (int)$m[1]; if ($n > $max) $max = $n; }
    }
    return 'PRD-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}
function ord_p(string $k): string {
    $v = $_POST[$k] ?? $_GET[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

/* Coordinate di pagamento (dal server, non dal repo pubblico). */
function ord_pagamenti(): array {
    $d = json_decode((string)@file_get_contents(DATA_DIR . '/pagamenti.json'), true);
    return is_array($d) ? $d : [
        'intestatario' => 'ABRAKA di (nome)',
        'iban'         => 'IT00 X000 0000 0000 0000 0000 000',
        'paypal'       => 'https://paypal.me/abraka',
        'satispay'     => '(numero Satispay)',
    ];
}

/* Mailer semplice: prova mail() di PHP. Non blocca mai l'ordine. */
function ord_manda_mail(string $to, string $oggetto, string $corpo, string $da = 'supporto@abraka.it'): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers = "From: abraka <$da>\r\nReply-To: $da\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($oggetto) . '?=', $corpo, $headers);
}

/* Mailer con allegato PDF (per la ricevuta). */
function ord_manda_mail_pdf(string $to, string $oggetto, string $corpo, ?string $pdf, string $da = 'supporto@abraka.it'): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!$pdf || !is_file($pdf)) return ord_manda_mail($to, $oggetto, $corpo, $da);
    $bound = 'abk' . bin2hex(random_bytes(8));
    $alleg = chunk_split(base64_encode((string)file_get_contents($pdf)));
    $nome  = basename($pdf);
    $headers = "From: abraka <$da>\r\nReply-To: $da\r\nMIME-Version: 1.0\r\n"
             . "Content-Type: multipart/mixed; boundary=\"$bound\"\r\n";
    $msg  = "--$bound\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$corpo\r\n"
          . "--$bound\r\nContent-Type: application/pdf; name=\"$nome\"\r\n"
          . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nome\"\r\n\r\n$alleg\r\n"
          . "--$bound--";
    return @mail($to, '=?UTF-8?B?' . base64_encode($oggetto) . '?=', $msg, $headers);
}

/* ---------- dispatch ---------------------------------------------------- */
$azione = ord_p('azione') ?: 'crea';

/* ===== AREA ADMIN (richiede 000 + password) ============================= */
if (strpos($azione, 'admin_') === 0) {
    $au = predizione_pulisci_utente(ord_p('u') ?: '000');
    if (!predizione_is_admin($au, ord_p('pass'))) {
        predizione_json(['ok' => false, 'errore' => 'Accesso negato'], 403);
    }
    $ordini = ord_leggi();

    if ($azione === 'admin_ordini') {
        // lista compatta, piu' recenti prima
        $out = [];
        foreach ($ordini as $id => $o) {
            $out[] = [
                'id' => $id, 'codice' => (string)($o['codice'] ?? $id), 'stato' => $o['stato'] ?? 'attesa',
                'fonte' => (string)($o['fonte'] ?? ''),
                'nome' => trim(($o['nome'] ?? '') . ' ' . ($o['cognome'] ?? '')),
                'mail' => $o['mail'] ?? '', 'gioco' => $o['gioco'] ?? '',
                'piano' => $o['piano'] ?? '', 'durata' => $o['durata'] ?? '',
                'importo' => $o['importo'] ?? 0, 'canale' => $o['canale'] ?? '',
                'data' => $o['data'] ?? '', 'utente' => $o['utente'] ?? '',
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['data'], $a['data']));
        predizione_json(['ok' => true, 'ordini' => $out]);
    }

    if ($azione === 'admin_attiva') {
        $id = ord_p('id');
        if (!isset($ordini[$id])) predizione_json(['ok' => false, 'errore' => 'Ordine non trovato'], 404);
        $res = predizione_attiva_ordine($ordini[$id]);
        ord_scrivi($ordini);
        // archivio operazioni: ordine attivato
        if (function_exists('predizione_log_operazione')) {
            predizione_log_operazione('attiva', (string)$res['utente'],
                'ordine ' . $id . ' · ' . trim(($ordini[$id]['nome'] ?? '') . ' ' . ($ordini[$id]['cognome'] ?? '')));
        }
        // mail di benvenuto col (solo se nuovo: altrimenti la password non si rimanda)
        if ($res['nuovo'] && !empty($ordini[$id]['mail'])) {
            $corpo = "Benvenuto in PREDIZIONE!\n\n"
                   . "Il tuo accesso e' attivo.\n"
                   . "Nome utente: " . $res['utente'] . "\n"
                   . "Password: " . $res['password'] . "\n\n"
                   . "Entra dall'app con questi dati e, al primo accesso, puoi cambiare la\n"
                   . "password (almeno 10 caratteri, 1 maiuscola e 1 simbolo).\n"
                   . "App: https://www.abraka.it/predizione/regia-predizione.html\n\n"
                   . "==============================================\n"
                   . "AL PRIMO ACCESSO L'APP TI FA DA GUIDA\n"
                   . "==============================================\n"
                   . "La prima volta che entri (o finche' non\n"
                   . "hai collegato la mail del gioco) l'app si trasforma in una GUIDA passo-passo\n"
                   . "che ti accompagna, dal telefono o dal computer, fino alla fine.\n\n"
                   . "- Con una mail GMAIL: e' tutto gia' COLLAUDATO e si fa in pochi minuti, in\n"
                   . "  pochi passaggi guidati (auto-impostazione). E' la via piu' semplice e veloce.\n"
                   . "- Con un ALTRO tipo di mail (Outlook, Libero, aziendale, un tuo dominio...):\n"
                   . "  ci pensiamo NOI, ma serve la nostra assistenza e i tempi sono un po' piu'\n"
                   . "  lunghi. In quel caso scrivici a supporto@abraka.it e ti seguiamo passo passo.\n\n"
                   . "Piu' sotto trovi comunque TUTTI i passaggi scritti, se preferisci leggerli.\n\n"
                   . "==============================================\n"
                   . "LE DUE MAIL (leggi: e' importante)\n"
                   . "==============================================\n"
                   . "1) MAIL DI COMUNICAZIONE = la mail con cui ti sei registrato ora.\n"
                   . "   E' QUESTA la mail dove ti scriviamo noi: ricevute, avvisi, assistenza.\n"
                   . "2) MAIL DEL GIOCO = la mail da cui partono le foto agli spettatori.\n"
                   . "   Puo' essere la STESSA della comunicazione, ma e' PREFERIBILE usarne\n"
                   . "   una DIVERSA, dedicata solo allo spettacolo (resta tutto piu' ordinato).\n\n"
                   . "Per usare al PIENO la funzione \"Palco\" (grandi invii, centinaia di mail)\n"
                   . "serve una mail di un DOMINIO tuo (non una Gmail): scrivici a\n"
                   . "supporto@abraka.it e ti guidiamo (assistenza dedicata, tempi un po' piu' lunghi).\n\n"
                   . "==============================================\n"
                   . "COME PREPARARE LA MAIL DEL GIOCO (passo-passo, con Gmail)\n"
                   . "==============================================\n"
                   . "PARTE 1 - CREARE LA MAIL GMAIL DEL GIOCO\n"
                   . "1) Dal telefono o dal computer vai su:  https://accounts.google.com/signup\n"
                   . "2) Scrivi un nome (puo' essere il tuo nome d'arte) e scegli un indirizzo,\n"
                   . "   es. ilmiomagoshow@gmail.com . Scegli una password e segnatela.\n"
                   . "3) Segui i passaggi fino alla fine: la casella Gmail e' pronta.\n\n"
                   . "PARTE 2 - ATTIVARE LA \"VERIFICA IN DUE PASSAGGI\"\n"
                   . "4) Entrato nella nuova Gmail, vai su:  https://myaccount.google.com/security\n"
                   . "5) Cerca \"Verifica in due passaggi\" e ATTIVALA (ti chiede il numero di\n"
                   . "   telefono e un codice che ricevi via SMS). Serve per il passo dopo.\n\n"
                   . "PARTE 3 - CREARE LA \"PASSWORD PER LE APP\" (16 lettere)\n"
                   . "6) Sempre nella pagina Sicurezza, apri \"Password per le app\"\n"
                   . "   (oppure vai su:  https://myaccount.google.com/apppasswords ).\n"
                   . "7) Dai un nome qualsiasi (es. PREDIZIONE) e premi Crea.\n"
                   . "8) Google ti mostra un codice di 16 LETTERE (es. abcd efgh ijkl mnop):\n"
                   . "   e' una password speciale. COPIALA (si usa una volta sola, nel passo 10).\n\n"
                   . "PARTE 4 - COLLEGARE LA MAIL ALL'APP PREDIZIONE\n"
                   . "9) Apri l'app PREDIZIONE -> ingranaggio Impostazioni -> \"La tua casella\".\n"
                   . "10) Scrivi l'indirizzo Gmail nuovo e INCOLLA le 16 lettere nel campo\n"
                   . "    password. (Facoltativo: un nome mittente.) Premi \"Salva casella\".\n\n"
                   . "Fatto! Da ora, a gioco avviato, il tasto \"Invia a mano\" spedisce le mail\n"
                   . "con la foto dalla tua Gmail. (Per gli eventi GRANDI c'e' il livello \"Palco\":\n"
                   . "non devi configurare nulla, scrivici a supporto@abraka.it.)\n\n"
                   . "Se qualcosa non ti torna, rispondi a questa mail: ti aiutiamo noi.\n"
                   . "==============================================\n\n"
                   . "In allegato la ricevuta " . $res['ricevuta'] . ".\n\n"
                   . "Un saluto,\nabraka — supporto@abraka.it";
            ord_manda_mail_pdf($ordini[$id]['mail'], 'Benvenuto — i tuoi accessi', $corpo, $res['ricevuta_file'] ?? null);
        }
        // notifica all'autore ogni acquisto a buon fine
        if ($res['nuovo']) {
            $o = $ordini[$id];
            $dett = "Nuovo acquisto ATTIVATO.\n\n"
                  . "Ordine: " . ($o['id'] ?? '') . "\n"
                  . "Cliente: " . ($o['nome'] ?? '') . ' ' . ($o['cognome'] ?? '') . "\n"
                  . "Email: " . ($o['mail'] ?? '') . "\n"
                  . "Telefono: " . ($o['tel'] ?? '') . "\n"
                  . "Residenza: " . ($o['via'] ?? '') . ", " . ($o['citta'] ?? '') . " (" . ($o['prov'] ?? '') . ")\n"
                  . "Gioco: " . ($o['gioco'] ?? '') . "\n"
                  . "Piano: " . ($o['piano'] ?? '') . " (" . ($o['durata'] ?? '') . ")\n"
                  . "Importo: " . ($o['importo'] ?? '') . " EUR  ·  Canale: " . ($o['canale'] ?? '') . "\n"
                  . "Utente creato: " . $res['utente'] . "\n"
                  . "Ricevuta: " . $res['ricevuta'] . "\n"
                  . "Data: " . date('d/m/Y H:i') . "\n";
            ord_manda_mail(PRED_NOTIFY_EMAIL, 'Acquisto effettuato — ' . ($o['id'] ?? ''), $dett);
        }
        predizione_json(['ok' => true, 'utente' => $res['utente'], 'password' => $res['password'],
                         'ricevuta' => $res['ricevuta'], 'nuovo' => $res['nuovo']]);
    }

    if ($azione === 'admin_annulla') {
        $id = ord_p('id');
        if (isset($ordini[$id]) && ($ordini[$id]['stato'] ?? 'attesa') !== 'attivato') {
            $ordini[$id]['stato'] = 'annullato';
            ord_scrivi($ordini);
        }
        predizione_json(['ok' => true]);
    }

    predizione_json(['ok' => false, 'errore' => 'Azione admin sconosciuta'], 400);
}

/* ===== CONFERMA EMAIL (pubblico): invia e verifica un codice ============ */
function ord_codici_dir(): string { $d = DATA_DIR . '/codici'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }

if ($azione === 'invia_codice') {
    $mail = ord_p('mail');
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) predizione_json(['ok' => false, 'errore' => 'Email non valida'], 400);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $f = ord_codici_dir() . '/' . sha1(strtolower($mail)) . '.json';
    @file_put_contents($f, json_encode(['code' => $code, 'ts' => time()]), LOCK_EX);
    ord_manda_mail($mail, 'Il tuo codice abraka', "Il tuo codice di conferma e':\n\n    $code\n\nScade tra 20 minuti. Se non hai richiesto nulla, ignora questa mail.\n\nabraka");
    predizione_json(['ok' => true]);
}

if ($azione === 'verifica_codice') {
    $mail = ord_p('mail');
    $code = preg_replace('/\D/', '', ord_p('code'));
    $f = ord_codici_dir() . '/' . sha1(strtolower($mail)) . '.json';
    $d = json_decode((string)@file_get_contents($f), true);
    if (is_array($d) && $code !== '' && (time() - (int)($d['ts'] ?? 0) <= 1200) && hash_equals((string)($d['code'] ?? ''), $code)) {
        @unlink($f);
        predizione_json(['ok' => true]);
    }
    predizione_json(['ok' => false, 'errore' => 'Codice errato o scaduto']);
}

/* ===== RICHIESTA ACCESSO (pubblico): il form del sito manda la richiesta.
   La mail parte da accesso@abraka.it e arriva SIA all'autore (appisolata)
   SIA a chi ha scritto (conferma). Niente piu' apertura del programma di posta. */
if ($azione === 'richiesta') {
    $mail  = strtolower(ord_p('mail'));
    $chi   = substr(ord_p('chi'), 0, 400);
    $gioco = substr(ord_p('gioco'), 0, 120);
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        predizione_json(['ok' => false, 'errore' => 'Scrivi una mail valida'], 400);
    }
    $da = 'accesso@abraka.it';
    // 1) all'autore: la richiesta
    $corpoAutore = "Nuova RICHIESTA DI ACCESSO dal sito.\n\n"
                 . "Email di chi scrive: $mail\n"
                 . "Gioco: " . ($gioco !== '' ? $gioco : '(non indicato)') . "\n"
                 . "Chi e': " . ($chi !== '' ? $chi : '(non indicato)') . "\n\n"
                 . "Rispondi direttamente a $mail.";
    ord_manda_mail(PRED_NOTIFY_EMAIL, 'Richiesta accesso — ' . ($gioco !== '' ? $gioco : 'PREDIZIONE'), $corpoAutore, $da);
    // 2) a chi ha scritto: la conferma
    $corpoCliente = "Ciao,\n\nabbiamo ricevuto la tua richiesta di accesso"
                  . ($gioco !== '' ? " per \"$gioco\"" : '') . ".\n"
                  . "Ti rispondiamo al piu' presto da questo indirizzo.\n\n"
                  . "Un saluto,\nabraka";
    ord_manda_mail($mail, 'Abbiamo ricevuto la tua richiesta — abraka', $corpoCliente, $da);
    predizione_json(['ok' => true]);
}

/* ===== LISTINO (pubblico): piani + extra con prezzi, per la pagina carrello */
if ($azione === 'listino') {
    $piani = [];
    foreach (predizione_piani() as $nome => $p) {
        // una tantum di questo piano: codici stabili pt_<piano>_<indice> (per il carrello)
        $ut = [];
        foreach (($p['una_tantum'] ?? []) as $ix => $u) {
            $ut[] = ['code' => 'pt_' . strtolower($nome) . '_' . $ix, 'label' => (string)$u['label'],
                     'tipo' => (string)$u['tipo'], 'qta' => (int)$u['qta'], 'prezzo' => (float)$u['prezzo']];
        }
        $om = [];
        foreach (($p['omaggio'] ?? []) as $o) {
            $om[] = ['label' => (string)$o['label'], 'tipo' => (string)$o['tipo'], 'qta' => (int)$o['qta']];
        }
        $piani[] = ['nome' => $nome, 'prezzo_anno' => (int)$p['prezzo_anno'], 'prezzo_mese' => (int)$p['prezzo_mese'],
                    'prezzo_anno_pieno' => (int)($p['prezzo_anno_pieno'] ?? $p['prezzo_anno']),
                    'prezzo_mese_pieno' => (int)($p['prezzo_mese_pieno'] ?? $p['prezzo_mese']),
                    'mail_mese' => (int)$p['mail_mese'], 'mail_giorno' => (int)($p['mail_giorno'] ?? 0),
                    'foto_ia_mese' => (int)$p['foto_ia_mese'], 'sessioni_mese' => (int)$p['sessioni_mese'],
                    'sessioni_giorno' => (int)($p['sessioni_giorno'] ?? 0),
                    'assistente' => (bool)$p['assistente'], 'grandi_invii' => (bool)$p['grandi_invii'], 'report' => (bool)$p['report'],
                    'una_tantum' => $ut, 'omaggio' => $om];
    }
    $extra = [];
    foreach (predizione_extra() as $code => $e) {
        $extra[] = ['code' => $code, 'label' => (string)$e['label'], 'prezzo' => (float)$e['prezzo'],
                    'tipo' => (string)($e['tipo'] ?? 'mail'),
                    'mesi' => (int)($e['mesi'] ?? 1), 'data' => (string)($e['data'] ?? '')];
    }
    predizione_json(['ok' => true, 'accesso' => predizione_accesso_prezzo(), 'accesso_pieno' => predizione_accesso_pieno(),
                     'accesso_anni' => predizione_accesso_anni(),
                     'piani' => $piani, 'extra' => $extra]);
}

/* ===== PASSWORD D'ACCESSO del gioco (pubblico): valida e ritorna l'etichetta */
if ($azione === 'verifica_accesso') {
    $et = predizione_accesso_etichetta(ord_p('password'));
    if ($et === null) predizione_json(['ok' => false, 'errore' => 'Password del gioco non valida']);
    predizione_json(['ok' => true, 'etichetta' => $et]);
}

/* ===== COORDINATE DI PAGAMENTO (pubblico: servono al sito per il bonifico) */
if ($azione === 'pagamenti') {
    $pg = ord_pagamenti();
    predizione_json(['ok' => true,
        'intestatario' => (string)($pg['intestatario'] ?? ''),
        'iban'         => (string)($pg['iban'] ?? ''),
        'paypal'       => (string)($pg['paypal'] ?? ''),
        'satispay'     => (string)($pg['satispay'] ?? '')]);
}

/* ===== STATO di un ordine (pubblico, serve al sito) ===================== */
if ($azione === 'stato') {
    $ordini = ord_leggi();
    $id = ord_p('id');
    if (!isset($ordini[$id])) {
        // puo' arrivare il CODICE mascherato (es. p41a0): cerchiamo l'ordine con quel codice
        foreach ($ordini as $k => $o) { if ((string)($o['codice'] ?? '') === $id) { $id = $k; break; } }
    }
    if (!isset($ordini[$id])) predizione_json(['ok' => false, 'errore' => 'non trovato'], 404);
    predizione_json(['ok' => true, 'id' => $id, 'codice' => (string)($ordini[$id]['codice'] ?? $id),
                     'stato' => $ordini[$id]['stato'] ?? 'attesa', 'importo' => $ordini[$id]['importo'] ?? 0]);
}

/* ===== CREA ordine (pubblico, dal sito) ================================= */
if ($azione === 'crea') {
    $nome    = ord_p('nome');    $cognome = ord_p('cognome');
    $mail    = ord_p('mail');    $tel     = ord_p('tel');
    $naz     = ord_p('naz') ?: 'Italia'; $prov = ord_p('prov'); $citta = ord_p('citta'); $via = ord_p('via');
    $lingua  = ord_p('lingua') ?: 'it';
    $gioco   = ord_p('gioco') ?: 'Predizione';
    $piano   = predizione_piano_valido(ord_p('piano') ?: 'Foyer') ?? 'Foyer';
    $durata  = (ord_p('durata') === 'mese') ? 'mese' : 'anno';
    $canale  = in_array(ord_p('canale'), ['bonifico', 'satispay', 'paypal'], true) ? ord_p('canale') : 'bonifico';
    $licenza = ord_p('licenza');

    // provenienza (etichetta della password d'accesso usata sul sito) + lettera per il numero ordine
    $fonte   = predizione_accesso_etichetta(ord_p('accesso_pw')) ?? '';
    $lettera = predizione_accesso_lettera(ord_p('accesso_pw'));

    // EXTRA (carrello): mappa {codice: quantita'} in JSON
    $extra = []; $extraRaw = ord_p('extra');
    if ($extraRaw !== '') { $tmp = json_decode($extraRaw, true); if (is_array($tmp)) foreach ($tmp as $k => $v) { $q = max(0, (int)$v); if ($q > 0) $extra[(string)$k] = $q; } }
    $cat = predizione_extra_catalogo($piano); $extraCost = 0.0;
    foreach ($extra as $code => $q) { if (isset($cat[$code])) $extraCost += (float)$cat[$code]['prezzo'] * $q; }

    // CLIENTE GIA' ATTIVO (upgrade/extra dal pannello): niente accesso 25 €
    $esistente = preg_replace('/[^a-z0-9._-]/', '', strtolower((string)ord_p('utente_esistente')));
    $utentiAll = predizione_utenti_leggi();
    $esistenteOk = ($esistente !== '' && $esistente !== '000' && isset($utentiAll[$esistente]));
    if ($esistenteOk) {   // usa i dati del cliente gia' in archivio se mancano
        if ($nome === '')    { $nome = (string)($utentiAll[$esistente]['nome'] ?? ''); }
        if ($mail === '')    { $mail = (string)($utentiAll[$esistente]['email_comunicazione'] ?? ''); }
    }

    // validazioni (il sito le fa gia', ma ricontrolliamo qui)
    $err = [];
    if (!$esistenteOk) {
        if ($nome === '' || $cognome === '') $err[] = 'Nome e cognome';
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $err[] = 'Email non valida';
        if (preg_replace('/\D/', '', $tel) === '') $err[] = 'Telefono';
        if ($prov === '' || $citta === '' || $via === '') $err[] = 'Residenza';
        if ($licenza !== '1' && strtolower($licenza) !== 'true' && $licenza !== 'on') $err[] = 'Accettazione licenza';
    }
    if ($err) predizione_json(['ok' => false, 'errore' => 'Dati mancanti: ' . implode(', ', $err)], 400);

    $vuolePiano = (ord_p('piano') !== '');
    if ($esistenteOk) {
        $importo = ($vuolePiano ? predizione_prezzo_piano($piano, $durata) : 0) + $extraCost;
    } else {
        $importo = predizione_accesso_prezzo() + predizione_prezzo_piano($piano, $durata) + $extraCost;
    }

    $ordini = ord_leggi();
    $id = ord_prossimo_id($ordini);
    // numero ordine MASCHERATO mostrato al cliente e all'autore (es. p41a0).
    // La chiave interna resta PRD-NNNN; "codice" e' quello che si vede e si usa come causale.
    $codice = predizione_codice_ordine((int)preg_replace('/\D/', '', $id), $lettera);
    $ordini[$id] = [
        'id' => $id, 'codice' => $codice, 'stato' => 'attesa', 'data' => date('c'),
        'nome' => $nome, 'cognome' => $cognome, 'mail' => $mail, 'tel' => $tel,
        'naz' => $naz, 'prov' => $prov, 'citta' => $citta, 'via' => $via, 'lingua' => $lingua,
        'gioco' => $gioco, 'piano' => $piano, 'durata' => $durata, 'quante' => 1,
        'canale' => $canale, 'importo' => $importo,
        'extra' => $extra, 'fonte' => $fonte, 'utente_esistente' => ($esistenteOk ? $esistente : ''),
        'licenza_accettata' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'utente' => '', 'ricevuta' => '',
    ];
    ord_scrivi($ordini);

    // istruzioni di pagamento per canale. La CAUSALE è breve e chiara: codice + nome.
    $pg = ord_pagamenti();
    $causale = "$codice $nome $cognome";
    if ($canale === 'bonifico') {
        $istr = "Bonifico di $importo € a:\n"
              . "Intestatario: {$pg['intestatario']}\n"
              . "IBAN: {$pg['iban']}\n\n"
              . "CAUSALE (scrivila ESATTA, tutta su una riga):\n"
              . "    $causale";
    } elseif ($canale === 'paypal') {
        $istr = "PayPal: paga $importo € qui: {$pg['paypal']}\n\nNella NOTA del pagamento scrivi:  $codice";
    } else {
        $istr = "Satispay: riceverai la richiesta di $importo € al tuo numero.\nCausale/nota:  $codice";
    }

    // mail al cliente (come pagare) e a supporto (nuovo ordine) — si usa il CODICE mascherato
    $corpoCliente = "Grazie! Ecco come completare l'ordine $codice.\n\n$istr\n\n"
                  . "Il gioco viene ATTIVATO entro 72 ore dalla conferma del pagamento.\n"
                  . "La password ti arrivera' da supporto@abraka.it (controlla anche lo spam).\n\n"
                  . "abraka — supporto@abraka.it";
    ord_manda_mail($mail, "Ordine $codice — come pagare", $corpoCliente);
    // AVVISO all'autore: 1) appena arriva l'ordine (QUI), 2) quando viene processato (in admin_attiva)
    $avviso = "Ordine $codice\n$nome $cognome\n$mail\n$gioco · $piano ($durata)\nImporto $importo €\nCanale: $canale";
    ord_manda_mail('supporto@abraka.it', "Nuovo ordine $codice", $avviso);
    ord_manda_mail(PRED_NOTIFY_EMAIL, "Nuovo ordine ricevuto — $codice", "È arrivato un nuovo ordine (in attesa di pagamento):\n\n$avviso");

    predizione_json(['ok' => true, 'ordine' => $codice, 'id' => $id, 'importo' => $importo,
                     'canale' => $canale, 'causale' => $causale, 'istruzioni' => $istr]);
}

predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
