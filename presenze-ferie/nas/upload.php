<?php
// Abilitiamo la visualizzazione degli errori per capire cosa non va
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$target_dir = "uploads/";

// Crea la cartella se non esiste
if (!file_exists($target_dir)) {
    // Se mkdir fallisce, il debug ci dirà perché (solitamente permessi)
    if (!mkdir($target_dir, 0777, true)) {
        die("Impossibile creare la cartella uploads. Controlla i permessi del NAS.");
    }
}

// Verifica se il file è presente nella richiesta
if (isset($_FILES["file"])) {
    $target_file = $target_dir . basename($_FILES["file"]["name"]);
    
    // Tenta di spostare il file caricato
    if (move_uploaded_file($_FILES["file"]["tmp_name"], $target_file)) {
        header("HTTP/1.1 200 OK");
        echo "File caricato con successo: " . $_FILES["file"]["name"];
    } else {
        // Se arriviamo qui, move_uploaded_file ha fallito
        header("HTTP/1.1 500 Internal Server Error");
        echo "Errore nel salvataggio del file. Errore PHP: " . error_get_last()['message'];
    }
} else {
    // Se apri il file dal browser senza inviare nulla, DEVI vedere questo messaggio
    header("HTTP/1.1 200 OK"); 
    echo "Il server è pronto! In attesa di file dall'app Ziwood...";
}
?>