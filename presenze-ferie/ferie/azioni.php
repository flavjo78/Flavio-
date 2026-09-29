<?php
// ============================================================================
// APP FERIE - azioni dell'API (separate da api.php cosi' si possono provare da riga di comando)
// ============================================================================

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mail.php';

function vista_richiesta($r)
{
    return [
        'id' => $r['id'], 'dal' => $r['dal'], 'al' => $r['al'],
        'mezza_giornata' => $r['mezza_giornata'] ?? null,
        'giorni' => $r['giorni_lavorativi'], 'nota' => $r['nota'] ?? '',
        'stato' => $r['stato'], 'creata_il' => $r['creata_il'] ?? null,
        'deciso_il' => $r['deciso_il'] ?? null, 'motivo_rifiuto' => $r['motivo_rifiuto'] ?? null,
    ];
}

function periodi_da_input(array $in)
{
    $p = $in['periodi'] ?? null;
    if (!is_array($p)) throw new ErroreFerie('Scegli almeno un giorno sul calendario.');
    $r = [];
    foreach ($p as $x) {
        if (!is_array($x)) continue;
        $r[] = ['dal' => (string)($x['dal'] ?? ''), 'al' => (string)($x['al'] ?? ''), 'mezza' => $x['mezza'] ?? null];
    }
    return $r;
}

function dati_home($nome)
{
    $anno = (int)substr(oggi_iso(), 0, 4);
    $richieste = array_map('vista_richiesta', array_slice(richieste_di($nome), 0, 40));
    return ['nome' => $nome, 'oggi' => oggi_iso(), 'saldo' => calcola_saldo($nome, $anno), 'richieste' => $richieste];
}

/** Esegue un'azione dell'app. $in = dati ricevuti. Ritorna l'array della risposta (lancia ErroreFerie per gli errori). */
function gestisci_azione(array $in)
{
    $azione = (string)($in['azione'] ?? '');

    // ---- azioni senza accesso
    if ($azione === 'elenco') {
        return ['ok' => true, 'nomi' => elenco_dipendenti()];
    }
    if ($azione === 'login') {
        $nome = login_pin($in['nome'] ?? '', (string)($in['pin'] ?? ''));
        return ['ok' => true, 'token' => crea_token($nome)] + dati_home($nome);
    }
    if ($azione === 'login_carta') {
        $nome = login_carta($in['nome'] ?? '', $in['codice'] ?? '');
        // sessione breve: la carta si puo' riavvicinare in ogni momento
        return ['ok' => true, 'sessione_breve' => true, 'token' => crea_token($nome, DURATA_SESSIONE_CARTA_MIN * 60)] + dati_home($nome);
    }

    // ---- azioni che richiedono l'accesso: il nome viene SEMPRE dal token, mai dalla richiesta
    $nome = nome_da_token($in['token'] ?? '');

    switch ($azione) {
        case 'home':
            return ['ok' => true] + dati_home($nome);

        case 'calendario':
            $anno = (int)($in['anno'] ?? 0);
            $mese = (int)($in['mese'] ?? 0);
            if ($anno < 2000 || $anno > 2100 || $mese < 1 || $mese > 12) throw new ErroreFerie('Mese non valido.');
            return ['ok' => true] + calendario_mese($nome, $anno, $mese);

        case 'calcola':
            $v = valuta_richiesta($nome, (string)($in['dal'] ?? ''), (string)($in['al'] ?? ''), $in['mezza'] ?? null);
            return ['ok' => true, 'giorni' => $v['totale'], 'date' => $v['giorni'],
                    'disponibili_dopo' => $v['saldo']['disponibili_se_approvate'] - $v['totale']];

        case 'calcola_multi':
            $v = valuta_periodi($nome, periodi_da_input($in));
            return ['ok' => true, 'giorni' => $v['totale'], 'date' => $v['giorni'], 'blocchi' => count($v['periodi']),
                    'disponibili_dopo' => $v['saldo']['disponibili_se_approvate'] - $v['totale']];

        case 'richiedi_multi':
            $create = crea_richieste($nome, periodi_da_input($in), $in['nota'] ?? '');
            notifica_admin_multi($create);
            return ['ok' => true, 'create' => count($create)] + dati_home($nome);

        case 'richiedi':
            $r = crea_richiesta($nome, (string)($in['dal'] ?? ''), (string)($in['al'] ?? ''), $in['mezza'] ?? null, $in['nota'] ?? '');
            list($inviata, $motivo) = notifica_admin($r, 'nuova');
            return ['ok' => true, 'richiesta' => vista_richiesta($r)] + dati_home($nome);

        case 'annulla':
            $r = annulla_richiesta($nome, (string)($in['id'] ?? ''));
            notifica_admin($r, 'annullata');
            return ['ok' => true] + dati_home($nome);
    }
    throw new ErroreFerie('Azione sconosciuta.');
}
