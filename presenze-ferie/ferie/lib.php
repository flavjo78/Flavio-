<?php
// ============================================================================
// APP FERIE - funzioni condivise (calcolo giorni, saldo, richieste, accesso)
//
// Le regole su giorni lavorativi e festivi sono le STESSE di dashboard/APP.py
// (giorno_lavorativo_per, festivi_anno, lavorativo_il): app e dashboard devono
// contare i giorni allo stesso modo. Se cambi una regola, cambiala in entrambi.
//
// Contratto con la dashboard: questa app SCRIVE SOLO i file
// richieste_ferie/*.json (e i contatori di sicurezza in richieste_ferie/_sistema/).
// Non scrive MAI correzioni_timbrature.json ne' ferie_saldi.json.
// ============================================================================

require_once __DIR__ . '/config.php';

class ErroreFerie extends Exception {}

// Alcuni PHP dei NAS non hanno l'estensione mbstring: versioni minime di riserva.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null)
    {
        preg_match_all('/./us', (string)$s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

// ---------------------------------------------------------------- file JSON

function leggi_json($percorso, $default = [])
{
    if (!is_file($percorso)) return $default;
    $testo = @file_get_contents($percorso);
    if ($testo === false) return $default;
    // Toglie l'eventuale BOM di Windows.
    $testo = preg_replace('/^\xEF\xBB\xBF/', '', $testo);
    $dati = json_decode($testo, true);
    return is_array($dati) ? $dati : $default;
}

function scrivi_json_atomico($percorso, $dati)
{
    $cartella = dirname($percorso);
    if (!is_dir($cartella) && !@mkdir($cartella, 0775, true) && !is_dir($cartella)) {
        throw new ErroreFerie('Impossibile creare la cartella dei dati sul NAS.');
    }
    $tmp = $percorso . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new ErroreFerie('Impossibile scrivere sul NAS (controlla i permessi della cartella richieste_ferie).');
    }
    if (!@rename($tmp, $percorso)) {
        @unlink($tmp);
        throw new ErroreFerie('Impossibile salvare il file sul NAS.');
    }
}

// ------------------------------------------------------------------- date

function oggi_iso()
{
    return isset($GLOBALS['OGGI_TEST']) ? $GLOBALS['OGGI_TEST'] : date('Y-m-d');
}

function data_valida($iso)
{
    if (!is_string($iso) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Giorno della settimana come in Python: 0=lunedi ... 6=domenica. */
function giorno_settimana($iso)
{
    return (int)date('N', strtotime($iso . ' 12:00:00')) - 1;
}

function aggiungi_giorni($iso, $n)
{
    return date('Y-m-d', strtotime($iso . ' 12:00:00 ' . ($n >= 0 ? '+' : '') . $n . ' day'));
}

// ---------------------------------------------------------------- festivi

/** Stessa formula di _pasqua() in APP.py (calendario gregoriano). */
function pasqua($anno)
{
    $a = $anno % 19;
    $b = intdiv($anno, 100);
    $c = $anno % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mese = intdiv($h + $l - 7 * $m + 114, 31);
    $giorno = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
}

function festivi_nazionali($anno)
{
    $fissi = [
        '01-01' => 'Capodanno', '01-06' => 'Epifania', '04-25' => 'Festa della Liberazione',
        '05-01' => 'Festa dei Lavoratori', '06-02' => 'Festa della Repubblica', '08-15' => 'Ferragosto',
        '11-01' => 'Ognissanti', '12-08' => 'Immacolata', '12-25' => 'Natale', '12-26' => 'Santo Stefano',
    ];
    if ($anno >= 2026) $fissi['10-04'] = 'San Francesco (festa nazionale)';
    $r = [];
    foreach ($fissi as $md => $nome) $r[sprintf('%04d-', $anno) . $md] = $nome;
    $p = pasqua($anno);
    $r[$p] = 'Pasqua';
    $r[aggiungi_giorni($p, 1)] = "Lunedì dell'Angelo (Pasquetta)";
    return $r;
}

/** Festivi validi di un anno: [data_iso => nome]. Come festivi_anno() in APP.py. */
function festivi_anno($anno)
{
    static $cache = [];
    $impronta = $anno . '|' . (isset($GLOBALS['IMP_TEST']) ? md5(json_encode($GLOBALS['IMP_TEST'])) : DATI_DIR);
    if (isset($cache[$impronta])) return $cache[$impronta];

    $imp = impostazioni();
    $r = [];
    $nazionali = array_key_exists('festivi_nazionali', $imp) ? (bool)$imp['festivi_nazionali'] : true;
    if ($nazionali) {
        $esclusi = is_array($imp['festivi_esclusi'] ?? null) ? $imp['festivi_esclusi'] : [];
        foreach (festivi_nazionali($anno) as $d => $n) {
            if (!in_array($d, $esclusi, true)) $r[$d] = $n;
        }
    }
    $extra = is_array($imp['festivi_extra'] ?? null) ? $imp['festivi_extra'] : [];
    foreach ($extra as $iso => $nome) {
        if (data_valida($iso) && (int)substr($iso, 0, 4) === $anno) $r[$iso] = $nome ?: 'Festivo';
    }
    return $cache[$impronta] = $r;
}

function impostazioni()
{
    if (isset($GLOBALS['IMP_TEST'])) return $GLOBALS['IMP_TEST'];
    return leggi_json(DATI_DIR . '/impostazioni.json', []);
}

// ------------------------------------------------- orari e giorni lavorativi

function orari_lavoro()
{
    if (isset($GLOBALS['ORARI_TEST'])) return $GLOBALS['ORARI_TEST'];
    return leggi_json(DATI_DIR . '/orari_lavoro.json', []);
}

/** Come giorno_lavorativo_per() in APP.py ($wd: 0=lunedi ... 6=domenica). */
function giorno_lavorativo_per($orari, $nome, $wd)
{
    if (isset($orari[$nome]) && is_array($orari[$nome])) {
        $per_day = $orari[$nome]['per_day'] ?? [];
        if (is_array($per_day) && array_key_exists((string)$wd, $per_day)) {
            $v = $per_day[(string)$wd];
            if ($v === 'OFF') return false;
            if (is_string($v) && $v !== '') return true; // orario diverso quel giorno
        }
    }
    return $wd < 5;
}

/** Come lavorativo_il() in APP.py: giorno lavorativo per orario E non festivo. */
function lavorativo_il($orari, $nome, $iso)
{
    if (!giorno_lavorativo_per($orari, $nome, giorno_settimana($iso))) return false;
    $festivi = festivi_anno((int)substr($iso, 0, 4));
    return !isset($festivi[$iso]);
}

function giorni_lavorativi_periodo($orari, $nome, $dal, $al)
{
    $r = [];
    for ($d = $dal; $d <= $al; $d = aggiungi_giorni($d, 1)) {
        if (lavorativo_il($orari, $nome, $d)) $r[] = $d;
    }
    return $r;
}

// ----------------------------------------------------------------- nomi

/** Nomi dei dipendenti (l'anagrafica ufficiale e' orari_lavoro.json). */
function elenco_dipendenti()
{
    $nomi = array_keys(orari_lavoro());
    sort($nomi, SORT_LOCALE_STRING);
    return $nomi;
}

/** Trova il nome ufficiale da un testo (anche scritto male, tramite alias_nomi.json). */
function risolvi_nome($testo)
{
    $testo = trim(preg_replace('/\s+/u', ' ', (string)$testo));
    if ($testo === '') return null;
    $orari = orari_lavoro();
    $minuscolo = mb_strtolower($testo, 'UTF-8');
    foreach (array_keys($orari) as $n) {
        if (mb_strtolower(trim($n), 'UTF-8') === $minuscolo) return $n;
    }
    $alias = isset($GLOBALS['ALIAS_TEST']) ? $GLOBALS['ALIAS_TEST'] : leggi_json(DATI_DIR . '/alias_nomi.json', []);
    foreach ($alias as $sbagliato => $giusto) {
        if (mb_strtolower(trim($sbagliato), 'UTF-8') === $minuscolo && isset($orari[$giusto])) return $giusto;
    }
    return null;
}

// ----------------------------------------------------------------- saldi

function saldi_ferie()
{
    if (isset($GLOBALS['SALDI_TEST'])) return $GLOBALS['SALDI_TEST'];
    return leggi_json(DATI_DIR . '/ferie_saldi.json', []);
}

function giustificativi()
{
    if (isset($GLOBALS['CORREZIONI_TEST'])) $c = $GLOBALS['CORREZIONI_TEST'];
    else $c = leggi_json(DATI_DIR . '/correzioni_timbrature.json', []);
    return (isset($c['giustificativi']) && is_array($c['giustificativi'])) ? $c['giustificativi'] : [];
}

/** Giustificativi di una persona: [data_iso => 'F'|'F½'|'M']. */
function giustificativi_di($nome)
{
    $r = [];
    foreach (giustificativi() as $chiave => $codice) {
        $parti = explode('|', $chiave, 2);
        if (count($parti) === 2 && $parti[1] === $nome && data_valida($parti[0])) $r[$parti[0]] = $codice;
    }
    return $r;
}

function valore_ferie($codice)
{
    if ($codice === 'F') return 1.0;
    if ($codice === "F½") return 0.5;
    return 0.0;
}

function leggi_richieste()
{
    $r = [];
    if (isset($GLOBALS['RICHIESTE_TEST'])) return $GLOBALS['RICHIESTE_TEST'];
    foreach (glob(RICHIESTE_DIR . '/*.json') ?: [] as $f) {
        $d = leggi_json($f, null);
        if (is_array($d) && isset($d['id'], $d['nome'])) $r[] = $d;
    }
    return $r;
}

function richieste_di($nome)
{
    $r = array_values(array_filter(leggi_richieste(), function ($x) use ($nome) { return $x['nome'] === $nome; }));
    usort($r, function ($a, $b) { return strcmp($b['creata_il'] ?? '', $a['creata_il'] ?? ''); });
    return $r;
}

/** Giorni (con valore 1 o 0.5) coperti da una richiesta, ricalcolati con le regole attuali. */
function giorni_richiesta($orari, $rich)
{
    $giorni = giorni_lavorativi_periodo($orari, $rich['nome'], $rich['dal'], $rich['al']);
    $peso = !empty($rich['mezza_giornata']) ? 0.5 : 1.0;
    $r = [];
    foreach ($giorni as $g) $r[$g] = $peso;
    return $r;
}

/**
 * Saldo ferie dell'anno (come da documentazione, sezione 4.2):
 *  godute = F (1) e F1/2 (0,5) con data <= oggi; programmate = con data > oggi;
 *  in attesa = giorni delle richieste in attesa; disponibili = spettanti + residuo - godute - programmate.
 */
function calcola_saldo($nome, $anno)
{
    $saldi = saldi_ferie();
    $voce = $saldi[$nome] ?? null;
    $configurato = is_array($voce) && (int)($voce['anno'] ?? 0) === $anno && isset($voce['giorni_spettanti']);
    $spettanti = $configurato ? (float)$voce['giorni_spettanti'] : 0.0;
    $residuo = $configurato ? (float)($voce['residuo_anno_precedente'] ?? 0) : 0.0;
    $oggi = oggi_iso();

    $godute = 0.0;
    $programmate = 0.0;
    foreach (giustificativi_di($nome) as $d => $codice) {
        if ((int)substr($d, 0, 4) !== $anno) continue;
        $v = valore_ferie($codice);
        if ($d <= $oggi) $godute += $v; else $programmate += $v;
    }
    $attesa = 0.0;
    $orari = orari_lavoro();
    foreach (richieste_di($nome) as $rich) {
        if (($rich['stato'] ?? '') !== 'in_attesa') continue;
        foreach (giorni_richiesta($orari, $rich) as $d => $v) {
            if ((int)substr($d, 0, 4) === $anno) $attesa += $v;
        }
    }
    $disponibili = $spettanti + $residuo - $godute - $programmate;
    return [
        'anno' => $anno,
        'configurato' => $configurato,
        'spettanti' => $spettanti,
        'residuo' => $residuo,
        'godute' => $godute,
        'programmate' => $programmate,
        'in_attesa' => $attesa,
        'disponibili' => $disponibili,
        'disponibili_se_approvate' => $disponibili - $attesa,
    ];
}

// -------------------------------------------------------------- calendario

function calendario_mese($nome, $anno, $mese)
{
    $orari = orari_lavoro();
    $oggi = oggi_iso();
    $giust = giustificativi_di($nome);
    $attesa = [];
    foreach (richieste_di($nome) as $rich) {
        if (($rich['stato'] ?? '') !== 'in_attesa') continue;
        foreach (giorni_richiesta($orari, $rich) as $d => $v) $attesa[$d] = $v;
    }
    $festivi = festivi_anno($anno);
    $n = (int)date('t', strtotime(sprintf('%04d-%02d-01 12:00:00', $anno, $mese)));
    $giorni = [];
    for ($g = 1; $g <= $n; $g++) {
        $iso = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
        $tipo = 'lavoro';
        $dettaglio = '';
        if (isset($giust[$iso])) {
            $c = $giust[$iso];
            if ($c === 'M') $tipo = 'malattia';
            else $tipo = ($iso <= $oggi ? 'ferie_godute' : 'ferie_programmate') . ($c === "F½" ? '_mezza' : '');
        } elseif (isset($attesa[$iso])) {
            $tipo = $attesa[$iso] < 1 ? 'attesa_mezza' : 'attesa';
        } elseif (isset($festivi[$iso])) {
            $tipo = 'festivo';
            $dettaglio = $festivi[$iso];
        } elseif (!giorno_lavorativo_per($orari, $nome, giorno_settimana($iso))) {
            $tipo = 'riposo';
        }
        $giorni[] = ['data' => $iso, 'giorno' => $g, 'wd' => giorno_settimana($iso), 'tipo' => $tipo, 'dettaglio' => $dettaglio];
    }
    return ['anno' => $anno, 'mese' => $mese, 'oggi' => $oggi, 'giorni' => $giorni];
}

// -------------------------------------------------------- nuova richiesta

/**
 * Controlla una richiesta e calcola i giorni che consumerebbe.
 * Ritorna ['giorni' => [date...], 'peso' => 1|0.5, 'totale' => n] oppure lancia ErroreFerie.
 */
function valuta_richiesta($nome, $dal, $al, $mezza, $controlla_saldo = true)
{
    if (!data_valida($dal) || !data_valida($al)) throw new ErroreFerie('Date non valide.');
    if ($al < $dal) throw new ErroreFerie('La data di fine è prima di quella di inizio.');
    if ($dal < oggi_iso()) throw new ErroreFerie('Non si possono chiedere ferie per giorni già passati: rivolgiti all\'ufficio.');
    if (substr($dal, 0, 4) !== substr($al, 0, 4)) throw new ErroreFerie('Il periodo attraversa due anni: fai due richieste separate (una fino al 31 dicembre e una dal 1 gennaio).');
    if ($mezza !== null && $mezza !== '' && $mezza !== 'mattina' && $mezza !== 'pomeriggio') throw new ErroreFerie('Mezza giornata non valida.');
    $mezza = ($mezza === '' ? null : $mezza);
    if ($mezza !== null && $dal !== $al) throw new ErroreFerie('La mezza giornata si può chiedere solo per un giorno singolo.');

    $orari = orari_lavoro();
    $giorni = giorni_lavorativi_periodo($orari, $nome, $dal, $al);
    if (!$giorni) throw new ErroreFerie('In questo periodo non ci sono giorni lavorativi (solo festivi o giorni di riposo).');

    $giust = giustificativi_di($nome);
    foreach ($giorni as $g) {
        if (isset($giust[$g])) throw new ErroreFerie('Il ' . data_it($g) . ' risulta già segnato come ' . ($giust[$g] === 'M' ? 'malattia' : 'ferie') . '.');
    }
    foreach (richieste_di($nome) as $rich) {
        if (($rich['stato'] ?? '') !== 'in_attesa') continue;
        $altri = giorni_richiesta($orari, $rich);
        foreach ($giorni as $g) {
            if (isset($altri[$g])) throw new ErroreFerie('Il ' . data_it($g) . ' è già in una tua richiesta in attesa.');
        }
    }

    $peso = $mezza !== null ? 0.5 : 1.0;
    $totale = count($giorni) * $peso;
    $anno = (int)substr($dal, 0, 4);
    $saldo = calcola_saldo($nome, $anno);
    if ($controlla_saldo && !CONSENTI_OLTRE_SALDO) {
        if (!$saldo['configurato']) {
            throw new ErroreFerie('I tuoi giorni di ferie del ' . $anno . ' non sono ancora stati impostati dall\'ufficio.');
        }
        if ($totale > $saldo['disponibili_se_approvate'] + 1e-9) {
            throw new ErroreFerie('Non hai abbastanza giorni: ne servono ' . num_it($totale) . ' e te ne restano ' . num_it(max(0, $saldo['disponibili_se_approvate'])) . ' (contando le richieste già in attesa).');
        }
    }
    return ['giorni' => $giorni, 'peso' => $peso, 'totale' => $totale, 'mezza' => $mezza, 'saldo' => $saldo];
}

/**
 * Piu' periodi in una volta (i giorni scelti sul calendario, raggruppati in blocchi).
 * $periodi = [['dal' => , 'al' => , 'mezza' => ], ...]. Controlla tutto PRIMA di salvare qualcosa.
 * Ritorna ['periodi' => [...valutazioni], 'totale' => n, 'giorni' => [date...], 'saldo' => ...].
 */
function valuta_periodi($nome, array $periodi)
{
    if (!$periodi) throw new ErroreFerie('Scegli almeno un giorno sul calendario.');
    if (count($periodi) > 20) throw new ErroreFerie('Troppi periodi in una volta: fai due richieste.');
    $valutati = [];
    $tutti = [];
    $totale = 0.0;
    $per_anno = [];
    foreach ($periodi as $p) {
        $v = valuta_richiesta($nome, (string)($p['dal'] ?? ''), (string)($p['al'] ?? ''), $p['mezza'] ?? null, false);
        foreach ($v['giorni'] as $g) {
            if (isset($tutti[$g])) throw new ErroreFerie('Il ' . data_it($g) . ' è scelto due volte.');
            $tutti[$g] = true;
        }
        $anno = (int)substr($p['dal'], 0, 4);
        $per_anno[$anno] = ($per_anno[$anno] ?? 0) + $v['totale'];
        $totale += $v['totale'];
        $valutati[] = $v + ['dal' => $p['dal'], 'al' => $p['al']];
    }
    if (!CONSENTI_OLTRE_SALDO) {
        foreach ($per_anno as $anno => $n) {
            $saldo = calcola_saldo($nome, $anno);
            if (!$saldo['configurato']) throw new ErroreFerie('I tuoi giorni di ferie del ' . $anno . " non sono ancora stati impostati dall'ufficio.");
            if ($n > $saldo['disponibili_se_approvate'] + 1e-9) {
                throw new ErroreFerie('Non hai abbastanza giorni: ne servono ' . num_it($n) . ' e te ne restano ' . num_it(max(0, $saldo['disponibili_se_approvate'])) . ' (contando le richieste già in attesa).');
            }
        }
    }
    $saldo0 = calcola_saldo($nome, (int)substr($periodi[0]['dal'], 0, 4));
    return ['periodi' => $valutati, 'totale' => $totale, 'giorni' => array_keys($tutti), 'saldo' => $saldo0];
}

function crea_richieste($nome, array $periodi, $nota)
{
    valuta_periodi($nome, $periodi);   // se qualcosa non va, non salva nulla
    $create = [];
    foreach ($periodi as $p) $create[] = crea_richiesta($nome, $p['dal'], $p['al'], $p['mezza'] ?? null, $nota);
    return $create;
}

function data_it($iso)
{
    return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
}

function num_it($n)
{
    return rtrim(rtrim(number_format((float)$n, 1, ',', ''), '0'), ',');
}

function slug($testo)
{
    $testo = strtr($testo, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'À' => 'a', 'È' => 'e', 'É' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u']);
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $testo));
    return trim($t, '-') ?: 'x';
}

function crea_richiesta($nome, $dal, $al, $mezza, $nota)
{
    $v = valuta_richiesta($nome, $dal, $al, $mezza);
    $nota = trim(mb_substr((string)$nota, 0, 300, 'UTF-8'));
    $ora = isset($GLOBALS['ORA_TEST']) ? $GLOBALS['ORA_TEST'] : date('Ymd-His');
    for ($i = 0; $i < 5; $i++) {
        $id = $ora . '-' . slug($nome) . '-' . bin2hex(random_bytes(2));
        $rich = [
            'id' => $id,
            'nome' => $nome,
            'tipo' => 'ferie',
            'dal' => $dal,
            'al' => $al,
            'mezza_giornata' => $v['mezza'],
            'giorni_lavorativi' => $v['totale'],
            'nota' => $nota,
            'stato' => 'in_attesa',
            'creata_il' => date('Y-m-d\TH:i:s'),
            'deciso_il' => null,
            'deciso_da' => null,
            'motivo_rifiuto' => null,
        ];
        if (!is_dir(RICHIESTE_DIR) && !@mkdir(RICHIESTE_DIR, 0775, true) && !is_dir(RICHIESTE_DIR)) {
            throw new ErroreFerie('Impossibile creare la cartella richieste_ferie sul NAS.');
        }
        $percorso = RICHIESTE_DIR . '/' . $id . '.json';
        // Modalita' "x": non sovrascrive mai un file gia' esistente.
        $f = @fopen($percorso, 'x');
        if ($f === false) continue;
        fwrite($f, json_encode($rich, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($f);
        return $rich;
    }
    throw new ErroreFerie('Impossibile salvare la richiesta sul NAS.');
}

function annulla_richiesta($nome, $id)
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$id)) throw new ErroreFerie('Richiesta non trovata.');
    $percorso = RICHIESTE_DIR . '/' . $id . '.json';
    $rich = leggi_json($percorso, null);
    if (!is_array($rich) || ($rich['nome'] ?? null) !== $nome) throw new ErroreFerie('Richiesta non trovata.');
    if (($rich['stato'] ?? '') !== 'in_attesa') throw new ErroreFerie('Questa richiesta non è più in attesa: non si può annullare.');
    $rich['stato'] = 'annullata';
    $rich['deciso_il'] = date('Y-m-d\TH:i:s');
    $rich['deciso_da'] = $nome;
    scrivi_json_atomico($percorso, $rich);
    return $rich;
}

// ------------------------------------------------------------- PIN e accesso

/** PIN salvato come "pbkdf2$iterazioni$sale_hex$hash_hex" (compatibile con Python hashlib). */
function verifica_pin($pin, $pin_hash)
{
    $p = explode('$', (string)$pin_hash);
    if (count($p) !== 4 || $p[0] !== 'pbkdf2' || !ctype_digit($p[1]) || (int)$p[1] < 1000) return false;
    $sale = @hex2bin($p[2]);
    if ($sale === false || $sale === '') return false;
    $calc = hash_pbkdf2('sha256', (string)$pin, $sale, (int)$p[1], 0, false);
    return hash_equals(strtolower($p[3]), $calc);
}

function cartella_sistema()
{
    $d = RICHIESTE_DIR . '/_sistema';
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
        throw new ErroreFerie('Impossibile creare la cartella di sistema sul NAS.');
    }
    return $d;
}

function segreto_app()
{
    $f = cartella_sistema() . '/segreto.txt';
    $s = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        if (@file_put_contents($f, $s) === false) throw new ErroreFerie('Impossibile scrivere sul NAS.');
    }
    return $s;
}

function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/')); }

function impronta_accesso($nome)
{
    $voce = saldi_ferie()[$nome] ?? [];
    return substr(hash('sha256', ($voce['pin_hash'] ?? '') . '|' . ($voce['carta_codice'] ?? '')), 0, 10);
}

function crea_token($nome, $secondi = null)
{
    if ($secondi === null) $secondi = DURATA_SESSIONE_ORE * 3600;
    $payload = b64u(json_encode(['n' => $nome, 'e' => time() + $secondi, 'f' => impronta_accesso($nome)], JSON_UNESCAPED_UNICODE));
    return $payload . '.' . b64u(hash_hmac('sha256', $payload, segreto_app(), true));
}

/** Ritorna il nome del dipendente del token, oppure lancia ErroreFerie. */
function nome_da_token($token)
{
    $sessione_scaduta = new ErroreFerie('Sessione scaduta: entra di nuovo.');
    $p = explode('.', (string)$token);
    if (count($p) !== 2) throw $sessione_scaduta;
    if (!hash_equals(b64u(hash_hmac('sha256', $p[0], segreto_app(), true)), $p[1])) throw $sessione_scaduta;
    $d = json_decode((string)b64u_dec($p[0]), true);
    if (!is_array($d) || ($d['e'] ?? 0) < time() || !isset(orari_lavoro()[$d['n'] ?? ''])) throw $sessione_scaduta;
    if (($d['f'] ?? '') !== impronta_accesso($d['n'])) throw $sessione_scaduta;
    return $d['n'];
}

function file_tentativi($nome)
{
    return cartella_sistema() . '/tentativi_' . md5($nome) . '.json';
}

function controlla_blocco($nome)
{
    $t = leggi_json(file_tentativi($nome), []);
    if (($t['fino_a'] ?? 0) > time()) {
        $min = (int)ceil(($t['fino_a'] - time()) / 60);
        throw new ErroreFerie('Troppi tentativi sbagliati. Riprova tra ' . $min . ' minuti.');
    }
}

function registra_tentativo($nome, $riuscito)
{
    $f = file_tentativi($nome);
    if ($riuscito) { @unlink($f); return; }
    $t = leggi_json($f, []);
    $n = (($t['fino_a'] ?? 0) > 0 && $t['fino_a'] <= time() ? 0 : (int)($t['n'] ?? 0)) + 1;
    $dati = ['n' => $n, 'fino_a' => 0];
    if ($n >= MAX_TENTATIVI) $dati = ['n' => 0, 'fino_a' => time() + MINUTI_BLOCCO * 60];
    scrivi_json_atomico($f, $dati);
}

function login_pin($nome_scritto, $pin)
{
    $nome = risolvi_nome($nome_scritto);
    if ($nome === null) throw new ErroreFerie('Nome o PIN non corretti.');
    controlla_blocco($nome);
    $voce = saldi_ferie()[$nome] ?? [];
    if (empty($voce['pin_hash'])) throw new ErroreFerie('Il tuo PIN non è ancora stato creato: chiedilo all\'ufficio.');
    if (!verifica_pin($pin, $voce['pin_hash'])) {
        registra_tentativo($nome, false);
        throw new ErroreFerie('Nome o PIN non corretti.');
    }
    registra_tentativo($nome, true);
    return $nome;
}

/** Accesso con la carta NFC: il link contiene il nome letto dalla carta (ed eventualmente un codice). */
function login_carta($nome_scritto, $codice)
{
    $nome = risolvi_nome($nome_scritto);
    if ($nome === null) throw new ErroreFerie('Carta non riconosciuta.');
    controlla_blocco($nome);
    $voce = saldi_ferie()[$nome] ?? [];
    $atteso = (string)($voce['carta_codice'] ?? '');
    if ($atteso !== '') {
        if (!hash_equals($atteso, (string)$codice)) {
            registra_tentativo($nome, false);
            throw new ErroreFerie('Carta non riconosciuta.');
        }
    } elseif (!NFC_ACCETTA_SOLO_NOME) {
        throw new ErroreFerie('Questa carta non è abilitata: entra con nome e PIN.');
    }
    registra_tentativo($nome, true);
    return $nome;
}
