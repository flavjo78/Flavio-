<?php
// 1. Cartella di destinazione
$target_dir = "presenze/";

// 2. Crea la cartella se non esiste
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// 3. Riceve i dati dall'app
$data = file_get_contents('php://input');

if ($data) {
    // 4. GENERAZIONE DEL NOME FILE DINAMICO
    // date("Y-m-d_H.i.s") genera una stringa come "2023-10-27_14.30.05"
    $nome_file = date("Y-m-d_H.i.s") . ".csv";
    $file_path = $target_dir . $nome_file;
    
    // 5. Scrittura del file
    if (file_put_contents($file_path, $data)) {
        // Rispondiamo OK e includiamo il nome del file creato
        echo "OK - File salvato: " . $nome_file;
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo "Errore: Impossibile scrivere il file.";
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    echo "Errore: Nessun dato ricevuto.";
}
?>