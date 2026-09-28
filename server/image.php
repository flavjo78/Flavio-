<?php
/* =========================================================================
   PREDIZIONE — immagine dinamica  (il cuore dell'effetto)
   -------------------------------------------------------------------------
   Quando lo spettatore APRE la mail, il suo programma di posta scarica
   questo file. Lo script guarda lo stato del gioco (deciso dal pannello)
   e restituisce la foto giusta in QUEL momento:

     - Gioco SPENTO      -> immagine neutra (niente A ne' B)
     - Gioco IN CORSO    -> Foto A   (a meno che sia scattato l'orario di sicurezza)
     - Gioco TERMINATO   -> Foto B   (hai premuto "Finisci" o e' scattato l'orario)

   Lo stato lo comanda il pannello tramite stato.php; le foto le carica
   il pannello tramite carica.php. Qui non c'e' niente da configurare:
   le impostazioni stanno in config.php.
   ========================================================================= */

require __DIR__ . '/config.php';

/* --- identificativo dello spettatore (solo per il log), ripulito --------- */
$id = isset($_GET['id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', $_GET['id']) : '';
$id = substr($id, 0, 40);

$stato = predizione_leggi_stato();

/* --- decide A, B, oppure neutro (null) ----------------------------------- */
function predizione_scelta(array $stato): ?string {
    // 1) scorciatoia manuale per i test: vince su tutto
    if ($stato['forza'] === 'A' || $stato['forza'] === 'B') {
        return $stato['forza'];
    }
    // 2) gioco non avviato: niente da mostrare
    if ($stato['fase'] === 'spento') {
        return null;
    }
    // 3) gioco terminato: Foto B
    if ($stato['fase'] === 'terminato') {
        return 'B';
    }
    // 4) gioco in corso: Foto A, ma se e' scattato l'orario di sicurezza -> B
    if ($stato['fase'] === 'avviato') {
        if (!empty($stato['orario_scambio']) && time() >= (int)$stato['orario_scambio']) {
            return 'B';
        }
        return 'A';
    }
    return null;
}

$scelta = predizione_scelta($stato);

/* --- log dell'apertura (la "cattura") ------------------------------------ */
if (ABILITA_LOG) {
    $ua   = str_replace(["\r", "\n", '"'], '', ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $mostrato = $scelta ?? 'neutro';
    $riga = date('Y-m-d H:i:s') . ',' . $id . ',' . $mostrato . ',' . $ip . ',"' . $ua . "\"\n";
    @file_put_contents(LOG_FILE, $riga, FILE_APPEND | LOCK_EX);
}

/* --- header ANTI-CACHE (fondamentali perche' lo scambio funzioni) -------- */
function predizione_header_anticache(string $mime): void {
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

/* --- trova il file foto da mostrare -------------------------------------- */
$foto = null;
if ($scelta === 'A') { $foto = $stato['foto_a_file'] ?? null; }
if ($scelta === 'B') { $foto = $stato['foto_b_file'] ?? null; }

/* --- immagine neutra: un pixel trasparente (gioco spento o foto mancante) - */
if ($scelta === null || !$foto || !is_file($foto)) {
    predizione_header_anticache('image/png');
    // PNG 1x1 completamente trasparente
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
