<?php
/**
 * ZiWood — invio del modulo contatti.
 * Riceve i campi via POST, fa i controlli minimi e spedisce una mail a DESTINATARIO.
 * Risponde in JSON ({"ok":true|false,"messaggio":"…"}) al JavaScript della pagina contatti.
 */

const DESTINATARIO = 'info@ziwood.it';
const MITTENTE     = 'sito@ziwood.it';   // indirizzo sul dominio: serve perché la mail non finisca in spam

header('Content-Type: application/json; charset=utf-8');

function rispondi(bool $ok, string $messaggio): void {
    echo json_encode(['ok' => $ok, 'messaggio' => $messaggio], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rispondi(false, 'Richiesta non valida.');
}

// campo nascosto "sito": i bot lo riempiono, le persone no
if (!empty($_POST['sito'])) {
    rispondi(true, 'Grazie, il messaggio è stato inviato.');
}

$pulisci = fn($k) => trim(str_replace(["\r", "\n"], ' ', (string)($_POST[$k] ?? '')));

$nome      = $pulisci('nome');
$email     = $pulisci('email');
$telefono  = $pulisci('telefono');
$tipo      = $pulisci('tipo');
$messaggio = trim((string)($_POST['messaggio'] ?? ''));
$privacy   = !empty($_POST['privacy']);

if ($nome === '' || $messaggio === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    rispondi(false, 'Controlla nome, email e messaggio: sono obbligatori.');
}
if (!$privacy) {
    rispondi(false, 'Per inviare la richiesta devi accettare l\'informativa privacy.');
}
if (mb_strlen($messaggio) > 5000) {
    rispondi(false, 'Il messaggio è troppo lungo.');
}

$oggetto = '=?UTF-8?B?' . base64_encode("Richiesta dal sito — $nome" . ($tipo ? " ($tipo)" : '')) . '?=';
$corpo = "Nuova richiesta dal modulo contatti di ziwood.it\n\n"
       . "Nome: $nome\n"
       . "Email: $email\n"
       . "Telefono: " . ($telefono ?: '-') . "\n"
       . "Tipo di progetto: " . ($tipo ?: '-') . "\n\n"
       . "Messaggio:\n$messaggio\n\n"
       . "---\nInviato il " . date('d/m/Y H:i') . " da IP " . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";

$intestazioni = "From: ZiWood sito <" . MITTENTE . ">\r\n"
              . "Reply-To: $nome <$email>\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n";

if (@mail(DESTINATARIO, $oggetto, $corpo, $intestazioni, '-f' . MITTENTE)) {
    rispondi(true, 'Grazie! Abbiamo ricevuto la richiesta: ti rispondiamo al più presto.');
}
rispondi(false, 'Non siamo riusciti a inviare il messaggio. Scrivici a info@ziwood.it o chiamaci allo 080 480 0481.');
