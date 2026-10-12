<?php
/* =========================================================================
   PREDIZIONE — Diagnostica (apri questa pagina nel browser per capire cosa
   non va). NON mostra password. Cancellala quando hai finito.
   Apri:  https://www.abraka.it/predizione/diagnostica.php
   ========================================================================= */
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo "PREDIZIONE — diagnostica\n";
echo "========================\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Cartella: " . __DIR__ . "\n\n";

echo "FILE PRESENTI:\n";
foreach (['config.php','piani.php','pdf.php','stato.php','carica.php','anteprima.php',
          'image.php','genera_ia.php','report.php','ordine.php','invia.php','smtp.php','neutro.png'] as $f) {
    $p = __DIR__ . '/' . $f;
    echo ' - ' . str_pad($f, 18) . (is_file($p) ? ('OK  ' . filesize($p) . ' byte') : 'MANCANTE') . "\n";
}

echo "\nCARICO I FILE PRINCIPALI (se qui compare un errore rosso, è quello il problema):\n";
require __DIR__ . '/config.php';
echo " - config.php: caricato\n";
require __DIR__ . '/piani.php';
echo " - piani.php: caricato\n";

echo "\nDATI:\n";
echo ' - cartella _dati scrivibile: ' . (is_dir(DATA_DIR) ? (is_writable(DATA_DIR) ? 'SI' : 'NO (permessi!)') : 'NON ESISTE') . "\n";
$u = predizione_utenti_leggi();
echo ' - utenti nel registro: ' . count($u) . '  (numeri: ' . implode(',', array_keys($u)) . ")\n";
echo ' - funzioni nuove presenti: '
   . 'movimento=' . (function_exists('predizione_movimento') ? 'si' : 'NO')
   . ', mitt_automatico=' . (function_exists('predizione_mitt_automatico') ? 'si' : 'NO')
   . ', limiti_utente=' . (function_exists('predizione_limiti_utente') ? 'si' : 'NO') . "\n";

echo "\nSE VEDI 'TUTTO OK' QUI SOTTO, il backend è a posto e il problema è altrove.\n";
echo "TUTTO OK\n";
