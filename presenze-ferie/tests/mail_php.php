<?php
// Usato da test_dashboard_ferie.py: prova l'invio SMTP dell'app PHP verso un finto server.
// Uso: php mail_php.php porta
define('DATI_DIR', sys_get_temp_dir() . '/mail_php_dati');
define('RICHIESTE_DIR', DATI_DIR . '/richieste_ferie');
require __DIR__ . '/../ferie/mail.php';
$GLOBALS['MAIL_TEST'] = ['attiva' => true, 'smtp_host' => '127.0.0.1', 'smtp_porta' => (int)$argv[1], 'smtp_sicurezza' => 'nessuna',
    'smtp_utente' => 'utente', 'smtp_password' => 'segreta', 'mittente' => 'ferie@azienda.test', 'mittente_nome' => 'Ferie àzienda',
    'destinatari_admin' => ['ufficio@azienda.test', 'non-valido']];
$r = ['id' => 'x', 'nome' => 'Mario Rossi', 'dal' => '2026-10-12', 'al' => '2026-10-16', 'mezza_giornata' => null,
      'giorni_lavorativi' => 5, 'nota' => "Viaggio\n.punto solo in riga", 'stato' => 'in_attesa'];
echo json_encode(notifica_admin($r, 'nuova'));
