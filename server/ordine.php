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
                'id' => $id, 'stato' => $o['stato'] ?? 'attesa',
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
        // mail di benvenuto col (solo se nuovo: altrimenti la password non si rimanda)
        if ($res['nuovo'] && !empty($ordini[$id]['mail'])) {
            $corpo = "Benvenuto in PREDIZIONE!\n\n"
                   . "Il tuo accesso e' attivo.\n"
                   . "Numero utente: " . $res['utente'] . "\n"
                   . "Password: " . $res['password'] . "\n\n"
                   . "Entra dall'app e, al primo accesso, puoi cambiare la password\n"
                   . "(almeno 10 caratteri, 1 maiuscola e 1 simbolo).\n\n"
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
    if (!isset($ordini[$id])) predizione_json(['ok' => false, 'errore' => 'non trovato'], 404);
    predizione_json(['ok' => true, 'id' => $id, 'stato' => $ordini[$id]['stato'] ?? 'attesa',
                     'importo' => $ordini[$id]['importo'] ?? 0]);
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

    // validazioni (il sito le fa gia', ma ricontrolliamo qui)
    $err = [];
    if ($nome === '' || $cognome === '') $err[] = 'Nome e cognome';
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $err[] = 'Email non valida';
    if (preg_replace('/\D/', '', $tel) === '') $err[] = 'Telefono';
    if ($prov === '' || $citta === '' || $via === '') $err[] = 'Residenza';
    if ($licenza !== '1' && strtolower($licenza) !== 'true' && $licenza !== 'on') $err[] = 'Accettazione licenza';
    if ($err) predizione_json(['ok' => false, 'errore' => 'Dati mancanti: ' . implode(', ', $err)], 400);

    $importo = PRED_ACCESSO_PREZZO + predizione_prezzo_piano($piano, $durata);

    $ordini = ord_leggi();
    $id = ord_prossimo_id($ordini);
    $ordini[$id] = [
        'id' => $id, 'stato' => 'attesa', 'data' => date('c'),
        'nome' => $nome, 'cognome' => $cognome, 'mail' => $mail, 'tel' => $tel,
        'naz' => $naz, 'prov' => $prov, 'citta' => $citta, 'via' => $via, 'lingua' => $lingua,
        'gioco' => $gioco, 'piano' => $piano, 'durata' => $durata, 'quante' => 1,
        'canale' => $canale, 'importo' => $importo,
        'licenza_accettata' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'utente' => '', 'ricevuta' => '',
    ];
    ord_scrivi($ordini);

    // istruzioni di pagamento per canale. La CAUSALE è breve e chiara: codice + nome.
    $pg = ord_pagamenti();
    $causale = "$id $nome $cognome";
    if ($canale === 'bonifico') {
        $istr = "Bonifico di $importo € a:\n"
              . "Intestatario: {$pg['intestatario']}\n"
              . "IBAN: {$pg['iban']}\n\n"
              . "CAUSALE (scrivila ESATTA, tutta su una riga):\n"
              . "    $causale";
    } elseif ($canale === 'paypal') {
        $istr = "PayPal: paga $importo € qui: {$pg['paypal']}\n\nNella NOTA del pagamento scrivi:  $id";
    } else {
        $istr = "Satispay: riceverai la richiesta di $importo € al tuo numero.\nCausale/nota:  $id";
    }

    // mail al cliente (come pagare) e a supporto (nuovo ordine)
    $corpoCliente = "Grazie! Ecco come completare l'ordine $id.\n\n$istr\n\n"
                  . "Il gioco viene ATTIVATO entro 72 ore dalla conferma del pagamento.\n"
                  . "La password ti arrivera' da supporto@abraka.it (controlla anche lo spam).\n\n"
                  . "abraka — supporto@abraka.it";
    ord_manda_mail($mail, "Ordine $id — come pagare", $corpoCliente);
    ord_manda_mail('supporto@abraka.it', "Nuovo ordine $id", "Ordine $id\n$nome $cognome\n$mail\n$gioco · $piano ($durata)\nImporto $importo €\nCanale: $canale");

    predizione_json(['ok' => true, 'ordine' => $id, 'importo' => $importo,
                     'canale' => $canale, 'causale' => $causale, 'istruzioni' => $istr]);
}

predizione_json(['ok' => false, 'errore' => 'Azione sconosciuta'], 400);
