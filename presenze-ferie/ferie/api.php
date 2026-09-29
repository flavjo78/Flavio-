<?php
// ============================================================================
// APP FERIE - punto d'ingresso dell'API (riceve JSON via POST, risponde JSON)
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/azioni.php';

function rispondi($dati, $codice = 200)
{
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rispondi(['ok' => false, 'errore' => 'Usa il metodo POST.'], 405);
}
$corpo = file_get_contents('php://input');
if (strlen($corpo) > 20000) rispondi(['ok' => false, 'errore' => 'Richiesta troppo grande.'], 413);
$in = json_decode($corpo, true);
if (!is_array($in)) rispondi(['ok' => false, 'errore' => 'Richiesta non valida.'], 400);

try {
    rispondi(gestisci_azione($in));
} catch (ErroreFerie $e) {
    rispondi(['ok' => false, 'errore' => $e->getMessage()], 200);
} catch (Throwable $e) {
    error_log('ferie/api.php: ' . $e->getMessage());
    rispondi(['ok' => false, 'errore' => 'Errore del server. Riprova tra poco.'], 500);
}
