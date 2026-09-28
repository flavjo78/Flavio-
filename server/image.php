<?php
/* =========================================================================
   PREDIZIONE — immagine dinamica  (il cuore dell'effetto)
   -------------------------------------------------------------------------
   Quando lo spettatore APRE la mail, il suo programma scarica questo file.
   L'indirizzo nella mail contiene il numero di sessione, es:
        image.php?s=1&id=spettatore1
   Lo script guarda la SESSIONE di quella persona e restituisce la foto giusta:

     - sessione non trovata / gioco spento -> immagine NEUTRA (trasparente)
     - sessione IN CORSO                    -> Foto A (neutra di attesa)
     - sessione TERMINATA                   -> la RIVELAZIONE di QUELLA sessione

   Ogni sessione ha le sue foto salvate a parte: chi ha ricevuto la
   rivelazione di una sessione continua a vederla per sempre, anche quando
   ne avvii altre con foto diverse.
   ========================================================================= */

require __DIR__ . '/config.php';

/* --- parametri dalla mail ------------------------------------------------ */
$id = isset($_GET['id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', $_GET['id']) : '';
$id = substr($id, 0, 40);
$sReq = isset($_GET['s']) && ctype_digit((string)$_GET['s']) ? (int)$_GET['s'] : null;

$stato = predizione_leggi_stato();

/* Sessione di riferimento: quella nell'indirizzo, altrimenti la corrente. */
$sid = $sReq ?: (int)($stato['sessione_corrente'] ?? 0);
$sess = ($sid > 0 && isset($stato['sessioni'][(string)$sid]) && is_array($stato['sessioni'][(string)$sid]))
        ? $stato['sessioni'][(string)$sid] : null;

/* --- decide QUALE FILE mostrare (o null = neutro) ------------------------ */
function predizione_file(array $stato, ?array $sess): array {
    // ritorna [percorso_file|null, etichetta]
    // scorciatoia test: forza A/B sulla sessione di riferimento
    if ($sess) {
        if ($stato['forza'] === 'A') return [$sess['before'] ?? null, 'A(test)'];
        if ($stato['forza'] === 'B') return [$sess['after']  ?? null, 'B(test)'];

        $fase = $sess['fase'] ?? 'spento';
        if ($fase === 'terminato') {
            return [$sess['after'] ?? null, 'B'];
        }
        if ($fase === 'avviato') {
            // rete di sicurezza: se l'orario e' scattato -> rivelazione
            if (!empty($sess['orario_scambio']) && time() >= (int)$sess['orario_scambio']) {
                return [$sess['after'] ?? null, 'B'];
            }
            return [$sess['before'] ?? null, 'A'];
        }
    }
    return [null, 'neutro'];
}

[$foto, $etichetta] = predizione_file($stato, $sess);

/* --- log dell'apertura (la "cattura") ------------------------------------ */
if (ABILITA_LOG) {
    $ua = str_replace(["\r", "\n", '"'], '', ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $riga = date('Y-m-d H:i:s') . ',' . ($sid ?: '') . ',' . $id . ',' . $etichetta . ',' . $ip . ',"' . $ua . "\"\n";
    @file_put_contents(LOG_FILE, $riga, FILE_APPEND | LOCK_EX);
}

/* --- header ANTI-CACHE (fondamentali perche' lo scambio funzioni) -------- */
function predizione_header_anticache(string $mime): void {
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

/* --- immagine neutra: pixel trasparente (spento o foto mancante) --------- */
if (!$foto || !is_file($foto)) {
    predizione_header_anticache('image/png');
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    );
    exit;
}

/* --- invia la foto scelta ------------------------------------------------ */
$ext  = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
$mime = ($ext === 'png') ? 'image/png'
      : (($ext === 'gif') ? 'image/gif'
      : (($ext === 'webp') ? 'image/webp' : 'image/jpeg'));

predizione_header_anticache($mime);
header('Content-Length: ' . filesize($foto));
readfile($foto);
