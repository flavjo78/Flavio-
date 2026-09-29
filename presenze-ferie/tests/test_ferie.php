<?php
// Test automatici dell'app ferie (calcolo giorni, saldo, richieste, accesso).
// Uso:  php tests/test_ferie.php
// Non toccano dati reali: lavorano in una cartella temporanea.

$tmp = sys_get_temp_dir() . '/ferie_test_' . bin2hex(random_bytes(3));
mkdir($tmp);
define('DATI_DIR', $tmp);
define('RICHIESTE_DIR', $tmp . '/richieste_ferie');
require __DIR__ . '/../ferie/azioni.php';

$ok = 0; $ko = 0;
function verifica($descr, $atteso, $reale)
{
    global $ok, $ko;
    if ($atteso === $reale || (is_float($reale) && is_numeric($atteso) && abs($atteso - $reale) < 1e-9)) { $ok++; return; }
    $ko++;
    echo "FALLITO: $descr\n   atteso: " . json_encode($atteso, JSON_UNESCAPED_UNICODE) . "\n   ottenuto: " . json_encode($reale, JSON_UNESCAPED_UNICODE) . "\n";
}
function errore_atteso($descr, $parte, callable $f)
{
    global $ok, $ko;
    try { $f(); $ko++; echo "FALLITO: $descr (nessun errore)\n"; }
    catch (ErroreFerie $e) {
        if (stripos($e->getMessage(), $parte) !== false) $ok++;
        else { $ko++; echo "FALLITO: $descr\n   messaggio: " . $e->getMessage() . "\n"; }
    }
}
function pin_hash($pin) {
    $sale = random_bytes(16);
    return 'pbkdf2$1000$' . bin2hex($sale) . '$' . hash_pbkdf2('sha256', $pin, $sale, 1000, 0, false);
}

// ---------------------------------------------------------------- dati di prova
$GLOBALS['ORARI_TEST'] = [
    'Mario Rossi'   => ['per_day' => ['5' => 'OFF', '6' => 'OFF']],
    'Laura Bianchi' => ['per_day' => ['4' => 'OFF', '5' => 'OFF', '6' => 'OFF']],
    'Sabato Sì'     => ['per_day' => ['5' => '08:00', '6' => 'OFF']],
    'Senza Orari'   => ['per_day' => []],
];
$GLOBALS['IMP_TEST'] = ['festivi_nazionali' => true, 'festivi_extra' => ['2026-12-24' => 'Chiusura aziendale'], 'festivi_esclusi' => []];
$GLOBALS['ALIAS_TEST'] = ['Mario Rosi' => 'Mario Rossi'];
$GLOBALS['OGGI_TEST'] = '2026-09-25';
$GLOBALS['CORREZIONI_TEST'] = ['giustificativi' => [
    '2026-09-21|Mario Rossi' => 'F', '2026-09-22|Mario Rossi' => 'F',
    '2026-10-05|Mario Rossi' => 'F', '2026-11-02|Mario Rossi' => "F½",
    '2026-09-18|Laura Bianchi' => 'M', '2026-10-27|Laura Bianchi' => 'M',
]];
$GLOBALS['SALDI_TEST'] = [
    'Mario Rossi' => ['anno' => 2026, 'giorni_spettanti' => 26, 'residuo_anno_precedente' => 3, 'pin_hash' => pin_hash('4321')],
    'Laura Bianchi' => ['anno' => 2026, 'giorni_spettanti' => 20, 'residuo_anno_precedente' => 0, 'pin_hash' => pin_hash('1111'), 'carta_codice' => 'abc123'],
    'Sabato Sì' => ['anno' => 2025, 'giorni_spettanti' => 20],
];

// ---------------------------------------------------------------- Pasqua e festivi
verifica('Pasqua 2024', '2024-03-31', pasqua(2024));
verifica('Pasqua 2025', '2025-04-20', pasqua(2025));
verifica('Pasqua 2026', '2026-04-05', pasqua(2026));
verifica('Pasqua 2027', '2027-03-28', pasqua(2027));
$f26 = festivi_anno(2026);
verifica('Pasquetta 2026 e festivo', true, isset($f26['2026-04-06']));
verifica('4 ottobre festivo dal 2026', true, isset($f26['2026-10-04']));
verifica('4 ottobre NON festivo nel 2025', false, isset(festivi_anno(2025)['2025-10-04']));
verifica('festivo extra 24/12', 'Chiusura aziendale', $f26['2026-12-24']);
verifica('festivi 2026 = 13 nazionali + 1 extra', 14, count($f26));

// ---------------------------------------------------------------- giorni lavorativi
$orari = orari_lavoro();
verifica('Mario 12-16 ott = 5 giorni', 5, count(giorni_lavorativi_periodo($orari, 'Mario Rossi', '2026-10-12', '2026-10-16')));
verifica('Laura (venerdi di riposo) 12-16 ott = 4', 4, count(giorni_lavorativi_periodo($orari, 'Laura Bianchi', '2026-10-12', '2026-10-16')));
verifica('Pasquetta: Mario 3-7 apr = ven + mar', ['2026-04-03', '2026-04-07'], giorni_lavorativi_periodo($orari, 'Mario Rossi', '2026-04-03', '2026-04-07'));
verifica('Pasquetta: Laura 3-7 apr = solo mar', ['2026-04-07'], giorni_lavorativi_periodo($orari, 'Laura Bianchi', '2026-04-03', '2026-04-07'));
verifica('chiusura aziendale 24/12 + Natale', ['2026-12-21', '2026-12-22', '2026-12-23'], giorni_lavorativi_periodo($orari, 'Mario Rossi', '2026-12-21', '2026-12-25'));
verifica('sabato con orario diverso e lavorativo', true, lavorativo_il($orari, 'Sabato Sì', '2026-09-26'));
verifica('domenica OFF non lavorativa', false, lavorativo_il($orari, 'Sabato Sì', '2026-09-27'));
verifica('senza per_day: sabato non lavorativo', false, lavorativo_il($orari, 'Senza Orari', '2026-09-26'));
verifica('nome sconosciuto: lun-ven', true, lavorativo_il($orari, 'Nessuno', '2026-09-28'));
$GLOBALS['IMP_TEST']['festivi_esclusi'] = ['2026-04-06'];
verifica('Pasquetta esclusa: lavorativa', 3, count(giorni_lavorativi_periodo($orari, 'Mario Rossi', '2026-04-03', '2026-04-07')));
$GLOBALS['IMP_TEST']['festivi_esclusi'] = [];
$GLOBALS['IMP_TEST']['festivi_nazionali'] = false;
verifica('festivi nazionali spenti: 25/12 lavorativo', true, lavorativo_il($orari, 'Mario Rossi', '2026-12-25'));
$GLOBALS['IMP_TEST']['festivi_nazionali'] = true;

// ---------------------------------------------------------------- nomi
verifica('nome con maiuscole diverse', 'Mario Rossi', risolvi_nome('  mario   ROSSI '));
verifica('nome via alias', 'Mario Rossi', risolvi_nome('Mario Rosi'));
verifica('nome sconosciuto', null, risolvi_nome('Pinco Pallino'));

// ---------------------------------------------------------------- saldo
$GLOBALS['RICHIESTE_TEST'] = [
    ['id' => 'r1', 'nome' => 'Mario Rossi', 'dal' => '2026-10-12', 'al' => '2026-10-16', 'mezza_giornata' => null, 'giorni_lavorativi' => 5, 'stato' => 'in_attesa', 'creata_il' => '2026-09-24T10:00:00'],
    ['id' => 'r2', 'nome' => 'Mario Rossi', 'dal' => '2026-08-03', 'al' => '2026-08-07', 'giorni_lavorativi' => 5, 'stato' => 'rifiutata', 'creata_il' => '2026-07-01T10:00:00'],
];
$s = calcola_saldo('Mario Rossi', 2026);
verifica('godute', 2.0, $s['godute']);
verifica('programmate (1 + mezza)', 1.5, $s['programmate']);
verifica('in attesa (solo richieste in attesa)', 5.0, $s['in_attesa']);
verifica('disponibili', 25.5, $s['disponibili']);
verifica('disponibili se approvate', 20.5, $s['disponibili_se_approvate']);
verifica('saldo non configurato (anno diverso)', false, calcola_saldo('Sabato Sì', 2026)['configurato']);

// ---------------------------------------------------------------- valutazione richieste
$v = valuta_richiesta('Mario Rossi', '2026-10-19', '2026-10-23', null);
verifica('richiesta 19-23 ott = 5 giorni', 5.0, (float)$v['totale']);
$v = valuta_richiesta('Mario Rossi', '2026-10-19', '2026-10-19', 'mattina');
verifica('mezza giornata = 0,5', 0.5, (float)$v['totale']);
errore_atteso('date invertite', 'prima', function () { valuta_richiesta('Mario Rossi', '2026-10-20', '2026-10-19', null); });
errore_atteso('giorni passati', 'passati', function () { valuta_richiesta('Mario Rossi', '2026-09-24', '2026-09-28', null); });
errore_atteso('due anni', 'due anni', function () { valuta_richiesta('Mario Rossi', '2026-12-28', '2027-01-04', null); });
errore_atteso('mezza su piu giorni', 'giorno singolo', function () { valuta_richiesta('Mario Rossi', '2026-10-19', '2026-10-20', 'mattina'); });
errore_atteso('solo weekend', 'giorni lavorativi', function () { valuta_richiesta('Mario Rossi', '2026-10-24', '2026-10-25', null); });
errore_atteso('solo festivo', 'giorni lavorativi', function () { valuta_richiesta('Mario Rossi', '2026-12-25', '2026-12-25', null); });
errore_atteso('sovrapposta a richiesta in attesa', 'in attesa', function () { valuta_richiesta('Mario Rossi', '2026-10-14', '2026-10-14', null); });
errore_atteso('sovrapposta a ferie gia approvate', 'ferie', function () { valuta_richiesta('Mario Rossi', '2026-10-05', '2026-10-06', null); });
errore_atteso('giorno di malattia', 'malattia', function () { valuta_richiesta('Laura Bianchi', '2026-10-27', '2026-10-27', null); });
errore_atteso('oltre il saldo', 'abbastanza', function () { valuta_richiesta('Mario Rossi', '2026-11-03', '2026-12-31', null); });
errore_atteso('saldo non impostato', 'non sono ancora stati impostati', function () { valuta_richiesta('Sabato Sì', '2026-10-19', '2026-10-19', null); });
errore_atteso('data non valida', 'non valide', function () { valuta_richiesta('Mario Rossi', '2026-02-30', '2026-03-02', null); });

// ---------------------------------------------------------------- calendario
$cal = calendario_mese('Mario Rossi', 2026, 10);
$tipi = array_column($cal['giorni'], 'tipo', 'data');
verifica('cal: ferie programmata', 'ferie_programmate', $tipi['2026-10-05']);
verifica('cal: in attesa', 'attesa', $tipi['2026-10-14']);
verifica('cal: festivo 4 ottobre', 'festivo', $tipi['2026-10-04']);
verifica('cal: riposo sabato', 'riposo', $tipi['2026-10-10']);
verifica('cal: giorno normale', 'lavoro', $tipi['2026-10-20']);
$cal = calendario_mese('Mario Rossi', 2026, 9);
$tipi = array_column($cal['giorni'], 'tipo', 'data');
verifica('cal: ferie godute', 'ferie_godute', $tipi['2026-09-21']);
verifica('cal: malattia di Laura', 'malattia', array_column(calendario_mese('Laura Bianchi', 2026, 9)['giorni'], 'tipo', 'data')['2026-09-18']);
verifica('cal: mezza giornata programmata', 'ferie_programmate_mezza', array_column(calendario_mese('Mario Rossi', 2026, 11)['giorni'], 'tipo', 'data')['2026-11-02']);

// ---------------------------------------------------------------- accesso
verifica('login con PIN giusto', 'Mario Rossi', login_pin('mario rossi', '4321'));
errore_atteso('PIN sbagliato', 'non corretti', function () { login_pin('Mario Rossi', '0000'); });
errore_atteso('nome inesistente', 'non corretti', function () { login_pin('Nessuno', '4321'); });
for ($i = 0; $i < MAX_TENTATIVI - 1; $i++) { try { login_pin('Mario Rossi', '9999'); } catch (ErroreFerie $e) {} }
errore_atteso('blocco dopo troppi tentativi', 'Troppi tentativi', function () { login_pin('Mario Rossi', '4321'); });
errore_atteso('blocco vale anche col PIN giusto', 'Riprova tra', function () { login_pin('Mario Rossi', '4321'); });
verifica('login di un altro non bloccato', 'Laura Bianchi', login_pin('Laura Bianchi', '1111'));

$t = crea_token('Laura Bianchi');
verifica('token valido', 'Laura Bianchi', nome_da_token($t));
errore_atteso('token manomesso', 'Sessione scaduta', function () use ($t) {
    list($p, $sig) = explode('.', $t);
    $d = json_decode(b64u_dec($p), true); $d['n'] = 'Mario Rossi';
    nome_da_token(b64u(json_encode($d)) . '.' . $sig);
});
errore_atteso('token vuoto', 'Sessione scaduta', function () { nome_da_token(''); });
$GLOBALS['SALDI_TEST']['Laura Bianchi']['pin_hash'] = pin_hash('2222');
errore_atteso('token invalidato dal cambio PIN', 'Sessione scaduta', function () use ($t) { nome_da_token($t); });

verifica('carta con codice giusto', 'Laura Bianchi', login_carta('Laura Bianchi', 'abc123'));
errore_atteso('carta con codice sbagliato', 'non riconosciuta', function () { login_carta('Laura Bianchi', 'zzz'); });
errore_atteso('carta senza codice quando serve', 'non riconosciuta', function () { login_carta('Laura Bianchi', ''); });
$GLOBALS['SALDI_TEST']['Mario Rossi']['pin_hash'] = pin_hash('4321');
@unlink(cartella_sistema() . '/tentativi_' . md5('Mario Rossi') . '.json');
verifica('carta col solo nome (default)', 'Mario Rossi', login_carta('Mario Rossi', ''));

// ---------------------------------------------------------------- richieste su file (test end-to-end dell'API)
unset($GLOBALS['RICHIESTE_TEST']);
$r = gestisci_azione(['azione' => 'login', 'nome' => 'Mario Rossi', 'pin' => '4321']);
verifica('API login ok', true, $r['ok']);
$tok = $r['token'];
$r = gestisci_azione(['azione' => 'calcola', 'token' => $tok, 'dal' => '2026-10-19', 'al' => '2026-10-23']);
verifica('API calcola', 5, (int)$r['giorni']);
$r = gestisci_azione(['azione' => 'richiedi', 'token' => $tok, 'dal' => '2026-10-19', 'al' => '2026-10-23', 'nota' => 'Viaggio <b>']);
$id = $r['richiesta']['id'];
verifica('API richiedi: file creato', true, is_file(RICHIESTE_DIR . "/$id.json"));
verifica('API richiedi: in attesa nel saldo', 5.0, (float)$r['saldo']['in_attesa']);
$file = json_decode(file_get_contents(RICHIESTE_DIR . "/$id.json"), true);
verifica('file: stato', 'in_attesa', $file['stato']);
verifica('file: nome dal token', 'Mario Rossi', $file['nome']);
verifica('file: giorni', 5.0, (float)$file['giorni_lavorativi']);
errore_atteso('seconda richiesta sovrapposta', 'in attesa', function () use ($tok) {
    gestisci_azione(['azione' => 'richiedi', 'token' => $tok, 'dal' => '2026-10-21', 'al' => '2026-10-21']);
});
// un altro dipendente non puo' annullare la richiesta di Mario
$tok_l = gestisci_azione(['azione' => 'login_carta', 'nome' => 'Laura Bianchi', 'codice' => 'abc123'])['token'] ?? null;
$GLOBALS['SALDI_TEST']['Laura Bianchi']['pin_hash'] = pin_hash('2222');
$tok_l = crea_token('Laura Bianchi');
errore_atteso('annullare richiesta altrui', 'non trovata', function () use ($tok_l, $id) { gestisci_azione(['azione' => 'annulla', 'token' => $tok_l, 'id' => $id]); });
errore_atteso('id con percorso', 'non trovata', function () use ($tok, $id) { gestisci_azione(['azione' => 'annulla', 'token' => $tok, 'id' => '../orari_lavoro']); });
$r = gestisci_azione(['azione' => 'annulla', 'token' => $tok, 'id' => $id]);
verifica('annullata: saldo torna libero', 0.0, (float)$r['saldo']['in_attesa']);
verifica('annullata: stato nel file', 'annullata', json_decode(file_get_contents(RICHIESTE_DIR . "/$id.json"), true)['stato']);
errore_atteso('annullare due volte', 'non è più in attesa', function () use ($tok, $id) { gestisci_azione(['azione' => 'annulla', 'token' => $tok, 'id' => $id]); });
errore_atteso('senza token', 'Sessione scaduta', function () { gestisci_azione(['azione' => 'home']); });
errore_atteso('azione sconosciuta', 'sconosciuta', function () use ($tok) { gestisci_azione(['azione' => 'boh', 'token' => $tok]); });
// una nota con caratteri non validi non deve produrre un file vuoto
$r = gestisci_azione(['azione' => 'richiedi', 'token' => $tok, 'dal' => '2026-10-26', 'al' => '2026-10-26', 'nota' => "Ciao \xC3\x28 ok àè"]);
$file = json_decode(file_get_contents(RICHIESTE_DIR . '/' . $r['richiesta']['id'] . '.json'), true);
verifica('nota con byte non validi: file leggibile', 'in_attesa', $file['stato']);
verifica('id senza accenti: nome con apostrofo/accento', true, (bool)preg_match('/^[A-Za-z0-9._-]+$/', slug("Nicolò D'Àngelo") . '-x'));
// piu' periodi insieme (giorni scelti sul calendario)
$P = [['dal' => '2026-11-09', 'al' => '2026-11-10', 'mezza' => null], ['dal' => '2026-11-12', 'al' => '2026-11-12', 'mezza' => 'mattina']];
$r = gestisci_azione(['azione' => 'calcola_multi', 'token' => $tok, 'periodi' => $P]);
verifica('multi: totale 2,5', 2.5, (float)$r['giorni']);
verifica('multi: 2 blocchi', 2, $r['blocchi']);
errore_atteso('multi: vuoto', 'almeno un giorno', function () use ($tok) { gestisci_azione(['azione' => 'calcola_multi', 'token' => $tok, 'periodi' => []]); });
errore_atteso('multi: giorno doppio', 'due volte', function () use ($tok) {
    gestisci_azione(['azione' => 'calcola_multi', 'token' => $tok, 'periodi' => [['dal' => '2026-11-09', 'al' => '2026-11-10'], ['dal' => '2026-11-10', 'al' => '2026-11-10']]]);
});
errore_atteso('multi: oltre il saldo (cumulativo)', 'abbastanza', function () use ($tok) {
    gestisci_azione(['azione' => 'calcola_multi', 'token' => $tok, 'periodi' => [['dal' => '2026-11-03', 'al' => '2026-11-27'], ['dal' => '2026-12-01', 'al' => '2026-12-22']]]);
});
$prima = count(glob(RICHIESTE_DIR . '/*.json'));
errore_atteso('multi: un periodo non valido = non salva nulla', 'passati', function () use ($tok) {
    gestisci_azione(['azione' => 'richiedi_multi', 'token' => $tok, 'periodi' => [['dal' => '2026-11-09', 'al' => '2026-11-09'], ['dal' => '2026-09-01', 'al' => '2026-09-01']]]);
});
verifica('multi: nulla salvato in caso di errore', $prima, count(glob(RICHIESTE_DIR . '/*.json')));
$attesa_prima = (float)gestisci_azione(['azione' => 'home', 'token' => $tok])['saldo']['in_attesa'];
$r = gestisci_azione(['azione' => 'richiedi_multi', 'token' => $tok, 'periodi' => $P, 'nota' => 'Ponte']);
verifica('multi: create 2 richieste', 2, $r['create']);
verifica('multi: in attesa +2,5', $attesa_prima + 2.5, (float)$r['saldo']['in_attesa']);
verifica('multi: file creati', $prima + 2, count(glob(RICHIESTE_DIR . '/*.json')));
foreach ($r['richieste'] as $x) { if ($x['stato'] === 'in_attesa') gestisci_azione(['azione' => 'annulla', 'token' => $tok, 'id' => $x['id']]); }
// l'app non deve mai scrivere i file della dashboard
verifica('non scrive correzioni_timbrature.json', false, file_exists(DATI_DIR . '/correzioni_timbrature.json'));
verifica('non scrive ferie_saldi.json', false, file_exists(DATI_DIR . '/ferie_saldi.json'));

// sessione della carta: breve, e un token scaduto non vale
errore_atteso('token scaduto', 'Sessione scaduta', function () { nome_da_token(crea_token('Mario Rossi', -5)); });
$rc = gestisci_azione(['azione' => 'login_carta', 'nome' => 'Mario Rossi', 'codice' => '']);
verifica('carta: sessione breve', true, $rc['sessione_breve']);
verifica('carta: token valido', 'Mario Rossi', nome_da_token($rc['token']));
$rp = gestisci_azione(['azione' => 'login', 'nome' => 'Mario Rossi', 'pin' => '4321']);
verifica('PIN: sessione normale', false, isset($rp['sessione_breve']));

// pulizia
foreach (glob(RICHIESTE_DIR . '/{,_sistema/}*', GLOB_BRACE) as $f) { if (is_file($f)) unlink($f); }
@rmdir(RICHIESTE_DIR . '/_sistema'); @rmdir(RICHIESTE_DIR); @rmdir($tmp);
echo "\nTest riusciti: $ok, falliti: $ko\n";
exit($ko ? 1 : 0);
