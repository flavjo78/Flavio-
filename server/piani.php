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

const PRED_ACCESSO_PREZZO = 25;     // euro, una tantum (DEFAULT; l'editor puo' cambiarlo)
const PRED_ACCESSO_ANNI   = 4;      // durata accesso

/* ---- Definizione PREDEFINITA dei piani (prezzi e limiti inclusi) -------
   NB: questi sono i valori "di fabbrica". L'autore puo' modificarli dal
   pannello 000 (editor del listino): le modifiche vengono salvate in
   _dati/listino.json e lette da predizione_piani() qui sotto. */
function predizione_piani_default(): array {
    return [
        'Foyer' => [
            'prezzo_anno' => 0,   'prezzo_mese' => 0,
            'mail_mese' => 85,    'mail_giorno' => 0,    'foto_ia_mese' => 0,
            'sessioni_mese' => 12,'sessioni_giorno' => 0,
            'assistente' => 0,    'report' => false,     'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => false],
        ],
        'Quinte' => [
            'prezzo_anno' => 66,  'prezzo_mese' => 15,
            'mail_mese' => 500,   'mail_giorno' => 100,  'foto_ia_mese' => 15,
            'sessioni_mese' => 5, 'sessioni_giorno' => 1,
            'assistente' => -1,   'report' => true,      'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => true],
        ],
        'Sipario' => [
            'prezzo_anno' => 96,  'prezzo_mese' => 30,
            'mail_mese' => 1500,  'mail_giorno' => 150,  'foto_ia_mese' => 30,
            'sessioni_mese' => 10,'sessioni_giorno' => 2,
            'assistente' => -1,   'report' => true,      'grandi_invii' => false,
            'flags' => ['mail' => true, 'foto' => true],
        ],
        'Palco' => [
            'prezzo_anno' => 228, 'prezzo_mese' => 58,
            'mail_mese' => 3500,  'mail_giorno' => 0,    'foto_ia_mese' => 100,
            'sessioni_mese' => 15,'sessioni_giorno' => 3,
            'assistente' => -1,   'report' => true,      'grandi_invii' => true,
            'flags' => ['mail' => true, 'foto' => true],
        ],
    ];
}

/* I 4 nomi "ufficiali" dei livelli (l'editor li tiene fissi; se ne puo'
   aggiungere UN altro personalizzato, ma questi quattro restano). */
function predizione_piani_nomi_base(): array { return ['Foyer', 'Quinte', 'Sipario', 'Palco']; }

/* Normalizza un piano: forza i tipi giusti e riempie i campi mancanti. */
function predizione_piano_normalizza(array $p, array $base = []): array {
    $g = function ($k, $def) use ($p, $base) { return array_key_exists($k, $p) ? $p[$k] : ($base[$k] ?? $def); };
    $flags = $g('flags', ['mail' => true, 'foto' => true]);
    if (!is_array($flags)) $flags = ['mail' => true, 'foto' => true];
    // Assistente di scena: NUMERO. 0 = non disponibile, -1 = illimitato, N = quota.
    // (compatibilita': un vecchio valore true/false diventa illimitato/non disponibile)
    $ass = $g('assistente', 0);
    if (is_bool($ass)) $ass = $ass ? -1 : 0;
    $ass = (int)$ass; if ($ass < -1) $ass = -1;
    return [
        'prezzo_anno'    => max(0, (int)$g('prezzo_anno', 0)),
        'prezzo_mese'    => max(0, (int)$g('prezzo_mese', 0)),
        'mail_mese'      => max(0, (int)$g('mail_mese', 0)),
        'mail_giorno'    => max(0, (int)$g('mail_giorno', 0)),     // 0 = nessun limite giornaliero
        'foto_ia_mese'   => max(0, (int)$g('foto_ia_mese', 0)),
        'sessioni_mese'  => max(0, (int)$g('sessioni_mese', 0)),
        'sessioni_giorno'=> max(0, (int)$g('sessioni_giorno', 0)), // 0 = nessun limite giornaliero
        'assistente'     => $ass,                                  // int: 0 no, -1 illimitato, N quota
        'report'         => (bool)$g('report', false),
        'grandi_invii'   => (bool)$g('grandi_invii', false),
        'flags'          => ['mail' => !empty($flags['mail']), 'foto' => !empty($flags['foto'])],
    ];
}

/* ---- LISTINO su file (modificabile dall'editor del pannello 000) ------- */
function predizione_listino_file(): string { return DATA_DIR . '/listino.json'; }
function predizione_listino_leggi(): array {
    $d = json_decode((string)@file_get_contents(predizione_listino_file()), true);
    return is_array($d) ? $d : [];
}
function predizione_listino_scrivi(array $data): bool {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    return @file_put_contents(predizione_listino_file(),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/* Piani EFFETTIVI: i valori di fabbrica, con sopra le modifiche salvate. */
function predizione_piani(): array {
    $def = predizione_piani_default();
    $lis = predizione_listino_leggi();
    if (empty($lis['piani']) || !is_array($lis['piani'])) return $def;
    $out = [];
    // prima i 4 ufficiali nell'ordine giusto, poi eventuali piani extra
    foreach (predizione_piani_nomi_base() as $nome) {
        $src = $lis['piani'][$nome] ?? null;
        $out[$nome] = is_array($src) ? predizione_piano_normalizza($src, $def[$nome] ?? []) : ($def[$nome] ?? predizione_piano_normalizza([]));
    }
    foreach ($lis['piani'] as $nome => $src) {
        $nome = (string)$nome;
        if (isset($out[$nome]) || !is_array($src)) continue;
        if (!in_array($nome, predizione_piani_nomi_base(), true)) $out[$nome] = predizione_piano_normalizza($src);
    }
    return $out;
}

/* Prezzo accesso EFFETTIVO (editor puo' cambiarlo; altrimenti la costante). */
function predizione_accesso_prezzo(): int {
    $lis = predizione_listino_leggi();
    return isset($lis['accesso']) ? max(0, (int)$lis['accesso']) : (int)PRED_ACCESSO_PREZZO;
}

/* ---- VERSIONI dei piani (storico) -------------------------------------
   Ogni volta che l'autore cambia un piano, la versione AUMENTA e la vecchia
   definizione finisce nello STORICO (es. "Sipario 01"): non si vede sul sito,
   ma nel pannello si', cosi' si capisce a cosa rinnovano i gia' abbonati. */
function predizione_piano_versione(string $nome): int {
    $lis = predizione_listino_leggi();
    $v = (int)($lis['versioni'][$nome] ?? 1);
    return $v < 1 ? 1 : $v;
}
/* Etichetta di versione: "Sipario 01", "Sipario 02"... (2 cifre). */
function predizione_piano_etichetta(string $nome, ?int $ver = null): string {
    $ver = $ver ?? predizione_piano_versione($nome);
    return $nome . ' ' . str_pad((string)max(1, $ver), 2, '0', STR_PAD_LEFT);
}
function predizione_piani_storico(): array {
    $lis = predizione_listino_leggi();
    $s = $lis['storico'] ?? [];
    return is_array($s) ? $s : [];
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

/* ---- Extra a la carte (estensioni della licenza) — VALORI DI FABBRICA -- */
function predizione_extra_default(): array {
    return [
        'mail_200'   => ['prezzo' => 1,   'tipo' => 'mail',     'qta' => 200,  'label' => '+200 mail'],
        'foto_10'    => ['prezzo' => 2.5, 'tipo' => 'foto_ia',  'qta' => 10,   'label' => '10 foto con IA'],
        'sess_5'     => ['prezzo' => 2,   'tipo' => 'sessioni', 'qta' => 5,    'label' => '5 sessioni'],
        'mail_5000'  => ['prezzo' => 7,   'tipo' => 'mail',     'qta' => 5000, 'label' => '5.000 mail (Palco)'],
    ];
}
/* Extra EFFETTIVI: fabbrica + modifiche salvate (prezzo/quantita'/etichetta). */
function predizione_extra(): array {
    $def = predizione_extra_default();
    $lis = predizione_listino_leggi();
    if (empty($lis['extra']) || !is_array($lis['extra'])) return $def;
    $out = $def;
    foreach ($lis['extra'] as $code => $e) {
        $code = (string)$code;
        if (!is_array($e)) continue;
        $tipo = (string)($e['tipo'] ?? ($def[$code]['tipo'] ?? 'mail'));
        if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni'], true)) $tipo = 'mail';
        $out[$code] = [
            'prezzo' => max(0, (float)($e['prezzo'] ?? ($def[$code]['prezzo'] ?? 0))),
            'tipo'   => $tipo,
            'qta'    => max(1, (int)($e['qta'] ?? ($def[$code]['qta'] ?? 1))),
            'label'  => (string)($e['label'] ?? ($def[$code]['label'] ?? $code)),
        ];
    }
    return $out;
}

/* ---- Applica un piano a un record utente (crea/estende abbonamento) ---- */
/* $durata: 'anno' (default) | 'mese'. $quante: quante unita' di durata (es. 3 mesi). */
function predizione_applica_piano(array &$rec, string $piano, string $durata = 'anno', int $quante = 1): void {
    $piani = predizione_piani();
    if (!isset($piani[$piano])) return;
    $quante = max(1, $quante);

    $rec['piano'] = $piano;
    $rec['flags'] = $piani[$piano]['flags'];
    // SNAPSHOT del piano com'e' OGGI: congela limiti e funzioni al momento
    // dell'acquisto. Se poi l'autore modifica il listino, questo utente tiene
    // il piano vecchio FINO al rinnovo (quando questa funzione gira di nuovo).
    $rec['piano_dati'] = predizione_piano_normalizza($piani[$piano]);
    // VERSIONE del piano sottoscritta (per lo storico/pannello): es. Sipario v2.
    $rec['piano_versione'] = predizione_piano_versione($piano);

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
    if (!in_array($tipo, ['mail', 'foto_ia', 'sessioni', 'assistente'], true) || $qta <= 0) return;
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
    // Se l'utente ha uno SNAPSHOT (piano congelato all'acquisto), si usa quello:
    // cosi' una modifica del listino non tocca chi e' gia' abbonato, fino al rinnovo.
    // Altrimenti (utenti vecchi senza snapshot) si legge il piano corrente.
    $p = (isset($rec['piano_dati']) && is_array($rec['piano_dati']))
         ? predizione_piano_normalizza($rec['piano_dati'])
         : ($piani[$piano] ?? ($piani['Foyer'] ?? predizione_piano_normalizza([])));
    return [
        'piano'            => $piano,
        'piano_scadenza'   => (int)($rec['piano_scadenza'] ?? 0),
        'accesso_scadenza' => (int)($rec['accesso_scadenza'] ?? 0),
        'mail_mese'        => (int)$p['mail_mese'],
        'mail_giorno'      => (int)($p['mail_giorno'] ?? 0),
        'foto_ia_mese'     => (int)$p['foto_ia_mese'],
        'sessioni_mese'    => (int)$p['sessioni_mese'],
        'sessioni_giorno'  => (int)($p['sessioni_giorno'] ?? 0),
        'assistente'       => ((int)$p['assistente'] !== 0),      // disponibile? (compat booleana)
        'assistente_num'   => (int)$p['assistente'],              // 0 no · -1 illimitato · N quota
        // Report: incluso nel piano OPPURE attivato a parte dall'admin (report_extra).
        // Se report_extra e' impostato (true/false), VINCE sul piano (si puo' anche togliere).
        'report'           => array_key_exists('report_extra', $rec) ? (bool)$rec['report_extra'] : (bool)$p['report'],
        'grandi_invii'     => (bool)$p['grandi_invii'],
        'crediti' => [
            'mail'      => predizione_crediti_disponibili($rec, 'mail'),
            'foto_ia'   => predizione_crediti_disponibili($rec, 'foto_ia'),
            'sessioni'  => predizione_crediti_disponibili($rec, 'sessioni'),
            'assistente'=> predizione_crediti_disponibili($rec, 'assistente'),
        ],
    ];
}

/* ---- Nome utente dal NOME della mail (prima della @) ------------------- */
/* es. "Mario.Rossi@gmail.com" -> "mario.rossi". Solo caratteri sicuri. */
function predizione_utente_da_email(string $email): string {
    $local = strtolower(trim($email));
    $at = strpos($local, '@');
    if ($at !== false) $local = substr($local, 0, $at);
    $local = preg_replace('/[^a-z0-9._-]/', '', $local);
    $local = preg_replace('/\.{2,}/', '.', $local);
    $local = trim($local, '._-');
    return substr($local, 0, 28);
}

/* Nome utente LIBERO a partire dalla mail. Se è già preso (o è un numero
   riservato), mette un progressivo DAVANTI: mario -> 1mario -> 2mario ...
   Se dalla mail non si ricava nulla, ripiega sul vecchio numero progressivo. */
function predizione_utente_libero_da_email(array $utenti, string $email): string {
    $base = predizione_utente_da_email($email);
    if ($base === '') return predizione_prossimo_numero();
    $riservati = function ($u) use ($utenti) { return isset($utenti[$u]) || $u === '000' || $u === '001'; };
    if (!$riservati($base)) return $base;
    $n = 1;
    do { $cand = $n . $base; $n++; } while ($riservati($cand));
    return $cand;
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
    $pdf->text(470, $y, 11, number_format((float)predizione_accesso_prezzo(), 2, ',', '.') . ' EUR'); $y += 16;
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
/* Applica gli EXTRA di un ordine (pacchetti mail/foto/sessioni) a un utente. */
function predizione_applica_extra_ordine(array &$rec, array $extra): void {
    $cat = predizione_extra();
    foreach ($extra as $code => $qta) {
        $qta = max(0, (int)$qta);
        if ($qta <= 0 || !isset($cat[$code])) continue;
        $e = $cat[$code];
        predizione_aggiungi_crediti($rec, $e['tipo'], (int)$e['qta'] * $qta, 0);
    }
}

/* Imposta il GIORNO di ricarica (giorno dell'abbonamento) nello stato dell'utente. */
function predizione_imposta_giorno_rinnovo(string $utente, int $giorno): void {
    $prev = predizione_utente();
    predizione_set_utente($utente);
    $st = predizione_leggi_stato();
    $st['giorno_rinnovo'] = max(1, min(28, $giorno));
    predizione_scrivi_stato($st);
    predizione_set_utente($prev);
}

function predizione_attiva_ordine(array &$ordine): array {
    $utenti = predizione_utenti_leggi();
    $piano  = predizione_piano_valido($ordine['piano'] ?? 'Foyer') ?? 'Foyer';
    $extra  = (isset($ordine['extra']) && is_array($ordine['extra'])) ? $ordine['extra'] : [];
    $fonte  = (string)($ordine['fonte'] ?? '');

    // gia' attivato? (idempotente)
    $u = (string)($ordine['utente'] ?? '');
    if ($u !== '' && isset($utenti[$u]) && !empty($ordine['attivato'])) {
        return ['utente' => $u, 'password' => null, 'ricevuta' => $ordine['ricevuta'] ?? null, 'nuovo' => false];
    }

    // --- CLIENTE GIA' ESISTENTE (upgrade/extra che si aggiungono al suo account) ---
    $esistente = (string)($ordine['utente_esistente'] ?? '');
    if ($esistente !== '' && isset($utenti[$esistente])) {
        if (($ordine['piano'] ?? '') !== '') predizione_applica_piano($utenti[$esistente], $piano, $ordine['durata'] ?? 'anno', (int)($ordine['quante'] ?? 1));
        predizione_applica_extra_ordine($utenti[$esistente], $extra);
        predizione_utenti_scrivi($utenti);
        $ricNum  = predizione_ricevuta_numero();
        $ricFile = predizione_ricevuta_scrivi($ordine, $esistente, $ricNum);
        predizione_vendite_aggiungi(['ordine' => $ordine['id'] ?? '', 'utente' => $esistente, 'nome' => (string)($utenti[$esistente]['nome'] ?? ''),
            'mail' => $ordine['mail'] ?? '', 'piano' => $piano, 'durata' => $ordine['durata'] ?? 'anno',
            'importo' => $ordine['importo'] ?? '', 'canale' => $ordine['canale'] ?? '']);
        $ordine['utente'] = $esistente; $ordine['ricevuta'] = $ricNum; $ordine['ricevuta_file'] = $ricFile;
        $ordine['stato'] = 'attivato'; $ordine['attivato'] = date('c');
        return ['utente' => $esistente, 'password' => null, 'ricevuta' => $ricNum, 'ricevuta_file' => $ricFile, 'nuovo' => false, 'upgrade' => true];
    }

    // --- NUOVO CLIENTE ---
    $u    = predizione_utente_libero_da_email($utenti, (string)($ordine['mail'] ?? ''));
    $pass = predizione_password_casuale();

    $rec = [
        'pass'   => $pass,
        'admin'  => false,
        'attivo' => true,
        'flags'  => ['mail' => true, 'foto' => false],
        'mail'   => new stdClass(),
        'nome'   => trim(($ordine['nome'] ?? '') . ' ' . ($ordine['cognome'] ?? '')),
        'email_comunicazione' => $ordine['mail'] ?? '',
        'telefono' => $ordine['tel'] ?? '',
        'fonte'  => $fonte,                 // provenienza (etichetta della password usata)
        'creato' => date('c'),
    ];
    predizione_applica_piano($rec, $piano, $ordine['durata'] ?? 'anno', (int)($ordine['quante'] ?? 1));
    predizione_applica_extra_ordine($rec, $extra);

    $utenti[$u] = $rec;
    predizione_utenti_scrivi($utenti);
    predizione_imposta_giorno_rinnovo($u, (int)date('j'));   // ricarica nel giorno di oggi (attivazione)

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
