<?php
// Usato da test_dashboard_ferie.py: esegue le funzioni PHP dell'app sugli stessi dati
// della dashboard e stampa i risultati in JSON, per confrontarli.
// Uso: php parita_php.php file_input.json
$in = json_decode(file_get_contents($argv[1]), true);
define('DATI_DIR', sys_get_temp_dir() . '/parita_php_dati');
define('RICHIESTE_DIR', DATI_DIR . '/richieste_ferie');
require __DIR__ . '/../ferie/azioni.php';

$GLOBALS['ORARI_TEST'] = $in['orari'];
$GLOBALS['IMP_TEST'] = $in['imp'];
$GLOBALS['OGGI_TEST'] = $in['oggi'];
$GLOBALS['CORREZIONI_TEST'] = ['giustificativi' => $in['giustificativi'] ?? []];
$GLOBALS['SALDI_TEST'] = $in['saldi'] ?? [];
$GLOBALS['RICHIESTE_TEST'] = $in['richieste'] ?? [];

$out = ['giorni' => [], 'saldi' => [], 'pin' => []];
foreach ($in['casi'] as $c) {
    $out['giorni'][] = giorni_lavorativi_periodo($GLOBALS['ORARI_TEST'], $c[0], $c[1], $c[2]);
}
foreach ($in['saldi_casi'] as $c) {
    $s = calcola_saldo($c[0], $c[1]);
    $out['saldi'][] = $s;
}
foreach ($in['pin_casi'] as $c) {
    $out['pin'][] = verifica_pin($c[0], $c[1]);
}
$out['pin_php'] = ['pin' => '7391', 'hash' => (function () {
    $sale = random_bytes(16);
    return 'pbkdf2$100000$' . bin2hex($sale) . '$' . hash_pbkdf2('sha256', '7391', $sale, 100000, 0, false);
})()];
echo json_encode($out);
