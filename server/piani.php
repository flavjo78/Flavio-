<?php
/* =========================================================================
   PREDIZIONE — PIANI, PREZZI, LIMITI, CREDITI e ATTIVAZIONE
   -------------------------------------------------------------------------
   Incluso da ordine.php e admin (stato.php). NON tocca il gioco: aggiunge
   solo la parte "commerciale" (quale piano ha un utente, cosa include, i
   crediti extra, e la funzione UNICA che attiva un ordine pagato).

   Decisioni (vedi ROADMAP §8):
   - Accesso all'app: 25 € una volta, valido 4 ANNI (Foyer incluso).
   - Livelli: Foyer (incluso) · Quinte · Sipario · Palco.
   - Annuale = prezzo pieno; mese singolo piu' caro (deterrente).
   - Le foto IA sono l'unico costo vivo vero: incluse nei piani a quote mensili.
   ========================================================================= */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pdf.php';   // generatore PDF per la ricevuta

const PRED_ACCESSO_PREZZO = 25;     // euro, una tantum
const PRED_ACCESSO_ANNI   = 4;      // durata accesso

/* ---- Definizione dei piani (prezzi e limiti inclusi) ------------------- */
function predizione_piani(): array {
    return [
        'Foyer' => [
            'prezzo_anno' => 0,   'prezzo_mese' => 0,
            'mail_mese' => 85,    'foto_ia_mese' => 0,   'sessioni_mese' => 12,
            'assistente' => false,'report' => false,     'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => false],
        ],
        'Quinte' => [
            'prezzo_anno' => 66,  'prezzo_mese' => 15,
            'mail_mese' => 500,   'foto_ia_mese' => 15,  'sessioni_mese' => 35,
            'assistente' => true, 'report' => true,      'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => true],
        ],
        'Sipario' => [
            'prezzo_anno' => 96,  'prezzo_mese' => 30,
            'mail_mese' => 1500,  'foto_ia_mese' => 30,  'sessioni_mese' => 70,
            'assistente' => true, 'report' => true,      'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => true],
        ],
        'Palco' => [
            'prezzo_anno' => 228, 'prezzo_mese' => 58,
            'mail_mese' => 3500,  'foto_ia_mese' => 100, 'sessioni_mese' => 105,
            'assistente' => true, 'report' => true,      'grandi_invii' => true,
            'flags' => ['mail' => true, 'foto' => true],
        ],
    ];
}

/* Nome piano valido? (ritorna il nome "pulito" o null) */
function predizione_piano_valido($piano): ?string {
    $piani = predizione_piani();
    $piano = ucfirst(strtolower(trim((string)$piano)));
    return isset($piani[$piano]) ? $piano : null;
}

/* Prezzo di un piano per durata ('anno' | 'mese'). L'accesso NON e' incluso qui. */
function predizione_prezzo_piano(string $piano, string $durata): int {
    $piani = predizione_piani();
    if (!isset($piani[$piano])) return 0;
    return (int)($durata === 'mese' ? $piani[$piano]['prezzo_mese'] : $piani[$piano]['prezzo_anno']);
}

/* ---- Extra a la carte (estensioni della licenza) ---------------------- */
function predizione_extra(): array {
    return [
        'mail_200'   => ['prezzo' => 1,   'tipo' => 'mail',     'qta' => 200,  'label' => '+200 mail'],
        'foto_10'    => ['prezzo' => 2.5, 'tipo' => 'foto_ia',  'qta' => 10,   'label' => '10 foto con IA'],
        'sess_5'     => ['prezzo' => 2,   'tipo' => 'sessioni', 'qta' => 5,    'label' => '5 sessioni'],
        'mail_5000'  => ['prezzo' => 7,   'tipo' => 'mail',     'qta' => 5000, 'label' => '5.000 mail (Palco)'],
    ];
}

/* ---- Applica un piano a un record utente (crea/estende abbonamento) ---- */
/* $durata: 'anno' (default) | 'mese'. $quante: quante unita' di durata (es. 3 mesi). */
function predizione_applica_piano(array &$rec, string $piano, string $durata = 'anno', int $quante = 1): void {
    $piani = predizione_piani();
    if (!isset($piani[$piano])) return;
    $quante = max(1, $quante);

    $rec['piano'] = $piano;
    $rec['flags'] = $piani[$piano]['flags'];

    // scadenza abbonamento: estende a partire da oggi o dalla scadenza se ancora valida
    $base = max(time(), (int)($rec['piano_scadenza'] ?? 0));
    if ($piano === 'Foyer') {
        $rec['piano_scadenza'] = 0; // Foyer non scade (vale quanto l'accesso)
    } else {
        $sec = ($durata === 'mese') ? (2629800 * $quante) : (31557600 * $quante); // ~mese / ~anno
        $rec['piano_scadenza'] = $base + $sec;
    }

    // accesso 4 anni: si imposta solo se non c'e' gia'
    if (empty($rec['accesso_scadenza'])) {
        $rec['accesso_scadenza'] = time() + PRED_ACCESSO_ANNI * 31557600;
    }
}

/* ---- Crediti extra (con scadenza facoltativa) ------------------------- */
/* $tipo: 'mail' | 'foto_ia' | 'sessioni'. $scad: timestamp o 0 = senza scadenza. */
function predizione_aggiungi_crediti(array &$rec, string $tipo, int $qta, int $scad = 0): void {
    if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni'], true) || $qta <= 0) return;
    if (!isset($rec['crediti']) || !is_array($rec['crediti'])) $rec['crediti'] = [];
    $rec['crediti'][] = ['tipo' => $tipo, 'qta' => $qta, 'scad' => $scad, 'aggiunto' => time()];
}

/* Somma i crediti NON scaduti di un tipo. */
function predizione_crediti_disponibili(array $rec, string $tipo): int {
    $tot = 0; $now = time();
    foreach (($rec['crediti'] ?? []) as $c) {
        if (($c['tipo'] ?? '') !== $tipo) continue;
        if (!empty($c['scad']) && $now > (int)$c['scad']) continue;
        $tot += (int)($c['qta'] ?? 0);
    }
    return $tot;
}

/* Riepilogo limiti+crediti per l'app (Mio account / I miei limiti). */
function predizione_limiti_utente(array $rec): array {
    $piani = predizione_piani();
    $piano = $rec['piano'] ?? 'Foyer';
    $p = $piani[$piano] ?? $piani['Foyer'];
    return [
        'piano'            => $piano,
        'piano_scadenza'   => (int)($rec['piano_scadenza'] ?? 0),
        'accesso_scadenza' => (int)($rec['accesso_scadenza'] ?? 0),
        'mail_mese'        => (int)$p['mail_mese'],
        'foto_ia_mese'     => (int)$p['foto_ia_mese'],
        'sessioni_mese'    => (int)$p['sessioni_mese'],
        'assistente'       => (bool)$p['assistente'],
        'report'           => (bool)$p['report'],
        'grandi_invii'     => (bool)$p['grandi_invii'],
        'crediti' => [
            'mail'     => predizione_crediti_disponibili($rec, 'mail'),
            'foto_ia'  => predizione_crediti_disponibili($rec, 'foto_ia'),
            'sessioni' => predizione_crediti_disponibili($rec, 'sessioni'),
        ],
    ];
}

/* ---- Prossimo numero utente libero (001..999, salta 000 admin) -------- */
function predizione_prossimo_numero(): string {
    $utenti = predizione_utenti_leggi();
    $max = 0;
    foreach (array_keys($utenti) as $k) {
        $n = (int)$k;
        if ($n > $max && $n < 1000) $max = $n;
    }
    return str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/* ---- Password casuale robusta (per il primo accesso) ------------------ */
/* Almeno 10 caratteri, 1 maiuscola, 1 minuscola, 1 cifra, 1 simbolo. */
function predizione_password_casuale(int $len = 12): string {
    $U = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $L = 'abcdefghijkmnpqrstuvwxyz';
    $D = '23456789'; $S = '!@#%&*?-';
    $all = $U . $L . $D . $S;
    $out = $U[random_int(0, strlen($U)-1)] . $L[random_int(0, strlen($L)-1)]
         . $D[random_int(0, strlen($D)-1)] . $S[random_int(0, strlen($S)-1)];
    for ($i = strlen($out); $i < max(10, $len); $i++) {
        $out .= $all[random_int(0, strlen($all)-1)];
    }
    return str_shuffle($out);
}

/* ---- Registro VENDITE (CSV) ------------------------------------------- */
function predizione_vendite_file(): string { return DATA_DIR . '/vendite.csv'; }
function predizione_vendite_aggiungi(array $r): void {
    $file = predizione_vendite_file();
    $nuovo = !is_file($file);
    $fh = @fopen($file, 'a');
    if (!$fh) return;
    if ($nuovo) {
        fputcsv($fh, ['data', 'ordine', 'utente', 'nome', 'mail', 'piano', 'durata', 'importo', 'canale']);
    }
    fputcsv($fh, [
        date('Y-m-d H:i:s'), $r['ordine'] ?? '', $r['utente'] ?? '', $r['nome'] ?? '',
        $r['mail'] ?? '', $r['piano'] ?? '', $r['durata'] ?? '', $r['importo'] ?? '', $r['canale'] ?? '',
    ]);
    fclose($fh);
}

/* ---- Ricevuta (testo; il PDF con FPDF si aggiunge in seguito) ---------- */
function predizione_ricevute_dir(): string {
    $d = DATA_DIR . '/ricevute';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function predizione_ricevuta_numero(): string {
    // progressivo per anno: RIC-ANNO-NNNN
    $anno = date('Y');
    $cnt_file = predizione_ricevute_dir() . "/_contatore_$anno.txt";
    $n = (int)@file_get_contents($cnt_file) + 1;
    @file_put_contents($cnt_file, (string)$n, LOCK_EX);
    return 'RIC-' . $anno . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
function predizione_ricevuta_scrivi(array $o, string $utente, string $numero): string {
    $imp   = (float)($o['importo'] ?? 0);
    $piano = $o['piano'] ?? 'Foyer';
    $dur   = $o['durata'] ?? 'anno';
    $prezzoPiano = (float)predizione_prezzo_piano($piano, $dur);

    // dati autore (solo-server, non nel repo): _dati/autore.json
    $autore = json_decode((string)@file_get_contents(DATA_DIR . '/autore.json'), true);
    $autore = is_array($autore) ? $autore : [];

    $pdf = new PredPDF();
    $y = 70;
    $pdf->text(60, $y, 22, 'abraka', true); $y += 10;
    $pdf->text(60, $y, 10, 'Licenza d\'uso dell\'opera PREDIZIONE'); $y += 34;

    $pdf->text(60, $y, 15, 'Ricevuta ' . $numero, true);
    $pdf->text(430, $y, 11, 'Data: ' . date('d/m/Y')); $y += 26;

    if (!empty($autore['nome']))  { $pdf->text(60, $y, 10, 'Emessa da: ' . $autore['nome']); $y += 14; }
    if (!empty($autore['citta'])) { $pdf->text(60, $y, 10, $autore['citta']); $y += 14; }
    if (!empty($autore['cf']))    { $pdf->text(60, $y, 10, 'C.F. ' . $autore['cf']); $y += 14; }
    $y += 8;

    $pdf->text(60, $y, 11, 'Cliente: ' . trim(($o['nome'] ?? '') . ' ' . ($o['cognome'] ?? '')), true); $y += 15;
    $pdf->text(60, $y, 10, 'Email: ' . ($o['mail'] ?? '')); $y += 14;
    $pdf->text(60, $y, 10, 'Utente: ' . $utente . '   Ordine: ' . ($o['id'] ?? '')); $y += 24;

    // righe
    $pdf->text(60, $y, 10, 'Descrizione', true); $pdf->text(470, $y, 10, 'Importo', true); $y += 6;
    $pdf->text(60, $y, 10, '______________________________________________________________'); $y += 18;
    $pdf->text(60, $y, 11, 'Accesso all\'app PREDIZIONE (4 anni)');
    $pdf->text(470, $y, 11, number_format((float)PRED_ACCESSO_PREZZO, 2, ',', '.') . ' EUR'); $y += 16;
    if ($prezzoPiano > 0) {
        $pdf->text(60, $y, 11, 'Estensione ' . $piano . ' (' . ($dur === 'mese' ? '1 mese' : '1 anno') . ')');
        $pdf->text(470, $y, 11, number_format($prezzoPiano, 2, ',', '.') . ' EUR'); $y += 16;
    }
    $pdf->text(60, $y, 10, '______________________________________________________________'); $y += 20;
    $pdf->text(60, $y, 13, 'TOTALE', true);
    $pdf->text(470, $y, 13, number_format($imp, 2, ',', '.') . ' EUR', true); $y += 26;

    if ($imp > 77.47) { $pdf->text(60, $y, 9, 'Marca da bollo 2,00 EUR assolta sull\'originale.'); $y += 16; }
    $pdf->text(60, $y, 9, 'Ricevuta per diritti d\'autore (art. 2 n.8 e 64-bis L. 633/1941). Non soggetta a IVA.'); $y += 30;
    $pdf->text(60, $y, 10, 'abraka.it  -  supporto@abraka.it');

    $path = predizione_ricevute_dir() . "/$numero.pdf";
    $pdf->save($path);
    return $path;
}

/* =========================================================================
   attiva_ordine() — LA FUNZIONE UNICA
   Da un ordine PAGATO: crea l'utente, applica il piano, scrive ricevuta e
   vendita, prepara la mail di benvenuto. Ritorna [utente, password, ricevuta].
   Idempotente: se l'ordine ha gia' un utente, non ne crea un altro.
   ========================================================================= */
function predizione_attiva_ordine(array &$ordine): array {
    $utenti = predizione_utenti_leggi();

    // gia' attivato?
    $u = (string)($ordine['utente'] ?? '');
    if ($u !== '' && isset($utenti[$u])) {
        return ['utente' => $u, 'password' => null, 'ricevuta' => $ordine['ricevuta'] ?? null, 'nuovo' => false];
    }

    $u    = predizione_prossimo_numero();
    $pass = predizione_password_casuale();
    $piano = predizione_piano_valido($ordine['piano'] ?? 'Foyer') ?? 'Foyer';

    $rec = [
        'pass'   => $pass,
        'admin'  => false,
        'attivo' => true,
        'flags'  => ['mail' => true, 'foto' => false],
        'mail'   => new stdClass(),
        'nome'   => trim(($ordine['nome'] ?? '') . ' ' . ($ordine['cognome'] ?? '')),
        'email_comunicazione' => $ordine['mail'] ?? '',
        'telefono' => $ordine['tel'] ?? '',
        'creato' => date('c'),
    ];
    predizione_applica_piano($rec, $piano, $ordine['durata'] ?? 'anno', (int)($ordine['quante'] ?? 1));

    $utenti[$u] = $rec;
    predizione_utenti_scrivi($utenti);

    // ricevuta + vendita
    $ricNum  = predizione_ricevuta_numero();
    $ricFile = predizione_ricevuta_scrivi($ordine, $u, $ricNum);
    predizione_vendite_aggiungi([
        'ordine' => $ordine['id'] ?? '', 'utente' => $u, 'nome' => $rec['nome'],
        'mail' => $ordine['mail'] ?? '', 'piano' => $piano, 'durata' => $ordine['durata'] ?? 'anno',
        'importo' => $ordine['importo'] ?? '', 'canale' => $ordine['canale'] ?? '',
    ]);

    $ordine['utente']       = $u;
    $ordine['ricevuta']     = $ricNum;
    $ordine['ricevuta_file']= $ricFile;
    $ordine['stato']        = 'attivato';
    $ordine['attivato']     = date('c');

    return ['utente' => $u, 'password' => $pass, 'ricevuta' => $ricNum, 'ricevuta_file' => $ricFile, 'nuovo' => true];
}
