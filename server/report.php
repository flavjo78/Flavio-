<?php
/* =========================================================================
   PREDIZIONE — Report di sessione (un unico file: mittenti + aperture)
   -------------------------------------------------------------------------
   Riceve (POST) utente + password + s (sessione). Produce una PAGINA HTML
   con: chi ha scritto (indirizzo, oggetto, testo, ora) e chi ha aperto
   (prima apertura, foto vista, dispositivo), uniti per destinatario.
   ========================================================================= */

require __DIR__ . '/config.php';

function p(string $k): ?string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    return is_string($v) ? trim($v) : null;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$utente   = predizione_set_utente($_POST['utente'] ?? $_GET['u'] ?? '');
$password = $_POST['password'] ?? $_GET['password'] ?? null;

/* Lasciapassare AMMINISTRATORE: 000 + password admin può aprire il report di
   qualsiasi utente (per la scheda nel pannello 000), senza la password dell'utente. */
$adminU  = (string)($_POST['au'] ?? $_GET['au'] ?? '');
$adminPw = $_POST['ap'] ?? $_GET['ap'] ?? null;
$okAdmin = ($adminU !== '' && predizione_is_admin(predizione_pulisci_utente($adminU), is_string($adminPw) ? $adminPw : null));

if (!$okAdmin && !predizione_auth($utente, is_string($password) ? $password : null)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:30px">Accesso negato.</body>';
    exit;
}

$stato = predizione_leggi_stato();

/* query di accesso da riusare nei link (cronologia -> singolo report) */
if ($okAdmin) {
    $authQ = 'au=' . rawurlencode($adminU) . '&ap=' . rawurlencode((string)$adminPw) . '&u=' . rawurlencode($utente);
} else {
    $authQ = 'u=' . rawurlencode($utente) . '&password=' . rawurlencode((string)$password);
}
/* URL assoluto a questo stesso script: i link devono funzionare anche quando la
   pagina e' aperta da un blob (il pannello la mostra cosi'), non solo da GET. */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$selfHost = $_SERVER['HTTP_HOST'] ?? 'www.abraka.it';
$selfPath = $_SERVER['SCRIPT_NAME'] ?? '/predizione/report.php';
$base = $scheme . '://' . $selfHost . $selfPath;

/* conta gli spettatori VERI (mittenti non automatici, unici) di una sessione */
function contaUtentiSessione(int $sid): int {
    $f = predizione_data_dir() . '/mail_sess_' . $sid . '.jsonl';
    if (!is_file($f)) return 0;
    $visti = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $m = strtolower(trim((string)($r['mittente'] ?? '')));
        if ($m === '' || predizione_mitt_automatico($m)) continue;
        $visti[$m] = true;
    }
    return count($visti);
}

/* --- CRONOLOGIA: elenco di tutte le sessioni con link al loro report ------ */
if (p('lista') !== null) {
    $sess = is_array($stato['sessioni'] ?? null) ? $stato['sessioni'] : [];
    krsort($sess, SORT_NUMERIC);   // dalla piu' recente
    $faseLbl = ['spento'=>'spento','avviato'=>'in corso','cambiato'=>'rivelato','terminato'=>'terminata'];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?><!doctype html><html lang="it"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cronologia report — Predizione</title>
    <style>
      :root{--gold:#b8863b;--ink:#20242e;--dim:#6b7280;--line:#e5e7eb;--soft:#faf8f4}
      *{box-sizing:border-box} body{margin:0;background:#fff;color:var(--ink);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.5}
      .wrap{max-width:720px;margin:0 auto;padding:26px 18px 60px}
      h1{font-family:Georgia,serif;font-weight:600;font-size:24px;margin:0 0 2px}
      .sub{color:var(--dim);margin:0 0 18px}
      a.row{display:flex;justify-content:space-between;align-items:center;gap:12px;text-decoration:none;color:inherit;background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:10px}
      a.row:active{transform:scale(.99)}
      .n{font-weight:700;font-size:16px}
      .meta{color:var(--dim);font-size:13px}
      .go{color:var(--gold);font-weight:700}
      .empty{color:var(--dim);font-style:italic;padding:14px 0}
    </style></head><body><div class="wrap">
      <h1>Cronologia report</h1>
      <div class="sub">Utente <?= h($utente) ?> · tocca una sessione per aprirne il report</div>
      <div class="btns" style="margin:0 0 14px"><button onclick="if(history.length>1){history.back();}else{window.close();}" style="font:inherit;cursor:pointer;border:1px solid var(--line);background:#fff;border-radius:8px;padding:9px 14px">← Indietro</button></div>
      <?php if (!$sess): ?><div class="empty">Nessuna sessione ancora registrata.</div>
      <?php else: foreach ($sess as $sn => $sv):
          $ts = !empty($sv['avvio_ts']) ? date('d/m/Y H:i', (int)$sv['avvio_ts']) : '—';
          $fl = $faseLbl[$sv['fase'] ?? ''] ?? ($sv['fase'] ?? '');
          $nUt = contaUtentiSessione((int)$sn); ?>
        <a class="row" href="<?= h($base) ?>?<?= $authQ ?>&s=<?= (int)$sn ?>">
          <span><span class="n">Sessione #<?= (int)$sn ?></span><br><span class="meta"><?= $nUt ?> utent<?= $nUt === 1 ? 'e' : 'i' ?> · <?= h($ts) ?> · <?= h($fl) ?></span></span>
          <span class="go">Apri ›</span>
        </a>
      <?php endforeach; endif; ?>
    </div></body></html><?php
    exit;
}

$sid = ctype_digit((string)p('s')) ? (int)p('s') : (int)($stato['sessione_corrente'] ?? 0);

/* --- mittenti (dal log delle mail in arrivo) ----------------------------- */
$mitt = [];   // id => {mittente, oggetto, testo, ora}
$mfile = predizione_data_dir() . '/mail_sess_' . $sid . '.jsonl';
if (is_file($mfile)) {
    foreach (file($mfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        // salta le mail AUTOMATICHE (no-reply, newsletter, notifiche): non sono spettatori
        if (predizione_mitt_automatico((string)($r['mittente'] ?? ''))) continue;
        $id = (string)($r['id'] ?? '');
        $key = $id !== '' ? $id : ('m' . count($mitt));
        $mitt[$key] = $r;
    }
}

/* --- aperture VERE (filtro "aperture di servizio": vedi config.php) ------- */
/* Scarta il pre-scarico automatico (primi 15s dall'arrivo) e i ri-scaricamenti
   ravvicinati della stessa persona: restano solo le aperture reali. */
$ap = [];   // id => {first, labels[], ua, n, times}
foreach (predizione_aperture_vere($sid) as $id => $v) {
    $ap[$id] = ['first' => $v['first'], 'labels' => $v['labels'], 'ua' => $v['ua'], 'n' => $v['n'], 'times' => $v['times']];
}

/* --- dispositivo leggibile dall'user-agent ------------------------------- */
function dispositivo($ua) {
    $ua = (string)$ua;
    if (stripos($ua, 'GoogleImageProxy') !== false) return 'Gmail (anteprima)';
    if (stripos($ua, 'iPhone') !== false) return 'iPhone';
    if (stripos($ua, 'iPad') !== false) return 'iPad';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) return 'Mac';
    return $ua !== '' ? substr($ua, 0, 24) : '—';
}
function fotoVista($labels) {
    $b = !empty($labels['B']); $a = !empty($labels['A']);
    if ($b && $a) return 'A poi B';
    if ($b) return 'Rivelazione (B)';
    if ($a) return 'Foto A';
    return '—';
}

/* --- DIARIO della sessione: la "storia" (cosa è stato premuto e quando) --- */
$sInfo = is_array($stato['sessioni'][(string)$sid] ?? null) ? $stato['sessioni'][(string)$sid] : [];
$diarioLbl = [
    'avvia'    => 'Avviato il gioco',
    'rispondi' => 'Risposta mail (via libera)',
    'cambia'   => 'Rivelato (cambio foto)',
    'finisci'  => 'Chiuso il gioco',
];
$diario = (isset($sInfo['diario']) && is_array($sInfo['diario'])) ? $sInfo['diario'] : [];
if (!$diario) {   // sessioni vecchie senza diario: ricostruisci dagli orari salvati
    if (!empty($sInfo['avvio_ts']))  $diario[] = ['a' => 'avvia',   'ts' => (int)$sInfo['avvio_ts']];
    if (!empty($sInfo['cambio_ts'])) $diario[] = ['a' => 'cambia',  'ts' => (int)$sInfo['cambio_ts']];
    if (!empty($sInfo['fine_ts']))   $diario[] = ['a' => 'finisci', 'ts' => (int)$sInfo['fine_ts']];
}

/* --- unione per destinatario (id) ---------------------------------------- */
$ids = array_values(array_unique(array_merge(array_keys($mitt), array_keys($ap))));

/* --- statistiche ROBOTTINI + tempo di lavoro (dalle mail di questa sessione) --- */
$robVisti = [];            // quali robottini (R1, R2, ...) hanno risposto
$maxInsieme = 0;           // massimo robottini insieme nello stesso giro
$invTs = [];               // tutti gli orari d'invio (per il tempo di lavoro)
foreach ($mitt as $m) {
    if (isset($m['robot']) && (int)$m['robot'] > 0) $robVisti[(int)$m['robot']] = true;
    if (isset($m['parti']) && (int)$m['parti'] > $maxInsieme) $maxInsieme = (int)$m['parti'];
    if (!empty($m['inviata'])) { $t = strtotime((string)$m['inviata']); if ($t) $invTs[] = $t; }
}
$nRobottini = count($robVisti);
$primoInvio = $invTs ? min($invTs) : null;
$ultimoInvio = $invTs ? max($invTs) : null;
$durataLavoro = ($primoInvio && $ultimoInvio) ? ($ultimoInvio - $primoInvio) : null;
function pred_durata_umana(?int $sec): string {
    if ($sec === null) return '—';
    if ($sec < 60) return $sec . ' secondi';
    $m = intdiv($sec, 60); $s = $sec % 60;
    return $m . ' min ' . ($s ? ($s . ' s') : '') ;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report sessione <?= (int)$sid ?> — Predizione</title>
<style>
  :root{--gold:#b8863b;--ink:#20242e;--dim:#6b7280;--line:#e5e7eb;--soft:#faf8f4}
  *{box-sizing:border-box} body{margin:0;background:#fff;color:var(--ink);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.5}
  .wrap{max-width:900px;margin:0 auto;padding:26px 18px 60px}
  h1{font-family:Georgia,serif;font-weight:600;font-size:26px;margin:0}
  .sub{color:var(--dim);margin:2px 0 18px}
  .cards{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 20px}
  .card{flex:1;min-width:120px;background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:12px 14px;text-align:center}
  .card b{display:block;font-size:24px;color:var(--gold)}
  .card span{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em}
  h2{font-size:15px;letter-spacing:.04em;text-transform:uppercase;color:var(--dim);margin:26px 0 8px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{text-align:left;padding:8px 8px;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:var(--soft);font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--dim)}
  td.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
  .msg{border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fff}
  .msg .from{font-weight:600}
  .msg .subj{color:var(--dim);margin:2px 0 8px}
  .msg .body{white-space:pre-wrap;background:var(--soft);border-radius:8px;padding:10px 12px}
  .foot{color:var(--dim);font-size:12px;margin-top:30px;border-top:1px solid var(--line);padding-top:12px}
  .btns{margin:14px 0 6px}
  .btns button{font:inherit;cursor:pointer;border:1px solid var(--line);background:#fff;border-radius:8px;padding:9px 14px;margin-right:8px}
  @media print{.btns{display:none}}
  .empty{color:var(--dim);font-style:italic;padding:10px 0}
  .times{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 2px}
  .times .t{flex:1;min-width:140px;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:9px 12px}
  .times .t span{display:block;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--dim)}
  .times .t b{font-size:14px;font-family:ui-monospace,Menlo,Consolas,monospace}
  /* diario della sessione (la "storia": cosa è stato premuto e quando) */
  .diario{background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:0 0 18px}
  .diario .dt{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--dim);margin:0 0 8px}
  .diario .de{display:flex;align-items:baseline;gap:10px;padding:5px 0;border-top:1px dashed var(--line)}
  .diario .de:first-of-type{border-top:0}
  .diario .de b{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:var(--gold);white-space:nowrap}
  .diario .de span{font-size:14px}
</style>
</head><body><div class="wrap">
  <h1>Report sessione #<?= (int)$sid ?></h1>
  <div class="sub">Utente <?= h($utente) ?> · generato il <?= h(date('d/m/Y H:i')) ?></div>

  <?php if ($diario): ?>
  <div class="diario">
    <div class="dt">Storia della sessione</div>
    <?php foreach ($diario as $d):
        $lbl = $diarioLbl[$d['a'] ?? ''] ?? ($d['a'] ?? '');
        $ora = !empty($d['ts']) ? date('d/m/Y H:i:s', (int)$d['ts']) : '—'; ?>
    <div class="de"><b><?= h($ora) ?></b><span><?= h($lbl) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="cards">
    <div class="card"><b><?= count($mitt) ?></b><span>Mittenti</span></div>
    <div class="card"><b><?= count($ap) ?></b><span>Destinatari che hanno aperto</span></div>
    <div class="card"><b><?= array_sum(array_map(function($x){return $x['n'];}, $ap)) ?></b><span>Aperture totali</span></div>
  </div>

  <?php if ($nRobottini || $maxInsieme || $durataLavoro !== null): ?>
  <div class="cards">
    <div class="card"><b><?= $nRobottini ?: '—' ?></b><span>Robottini che hanno risposto</span></div>
    <div class="card"><b><?= $maxInsieme ?: '—' ?></b><span>Massimo insieme (stesso momento)</span></div>
    <div class="card"><b><?= pred_durata_umana($durataLavoro) ?></b><span>Tempo di lavoro (1º → ultimo invio)</span></div>
  </div>
  <?php endif; ?>

  <div class="btns">
    <button onclick="if(history.length>1){history.back();}else{window.close();}">← Indietro</button>
    <button onclick="window.print()">Stampa / Salva PDF</button>
    <button onclick="location.href='<?= h($base) ?>?<?= $authQ ?>&lista=1'">Cronologia report</button>
  </div>

  <h2>Riepilogo per destinatario</h2>
  <?php if (!$ids): ?><div class="empty">Nessun dato per questa sessione.</div><?php else: ?>
  <table>
    <thead><tr><th>Mittente</th><th>Ricevuta</th><th>Inviata</th><th>Robottino</th><th>Aperture (tutti gli orari)</th><th>Foto vista</th><th>Dispositivo</th><th>N.</th></tr></thead>
    <tbody>
    <?php foreach ($ids as $id):
        $m = $mitt[$id] ?? null; $a = $ap[$id] ?? null;
        $orari = ($a && !empty($a['times'])) ? $a['times'] : []; ?>
      <tr>
        <td><?= h($m['mittente'] ?? '—') ?></td>
        <td class="mono"><?= h($m['ora'] ?? '—') ?></td>
        <td class="mono"><?= h($m['inviata'] ?? '—') ?></td>
        <td><?= isset($m['robot']) ? ('R' . (int)$m['robot']) : '—' ?></td>
        <td class="mono"><?php if ($orari): foreach ($orari as $i => $t): ?><?= ($i === 0 ? '' : '<br>') . h($t) ?><?php endforeach; else: ?>—<?php endif; ?></td>
        <td><?= $a ? h(fotoVista($a['labels'])) : '—' ?></td>
        <td><?= $a ? h(dispositivo($a['ua'])) : '—' ?></td>
        <td><?= $a ? (int)$a['n'] : 0 ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2>Messaggi ricevuti (oggetto e testo)</h2>
  <?php if (!$mitt): ?>
    <div class="empty">Nessun messaggio registrato. (Si registrano quando l'autorisponditore è aggiornato.)</div>
  <?php else: foreach ($mitt as $m): ?>
    <div class="msg">
      <div class="from"><?= h($m['mittente'] ?? '—') ?> <span style="color:var(--dim);font-weight:400">· <?= h($m['ora'] ?? '') ?></span></div>
      <div class="subj">Oggetto: <?= h($m['oggetto'] ?? '') ?: '(nessuno)' ?></div>
      <div class="body"><?= h($m['testo'] ?? '') ?: '(vuoto)' ?></div>
    </div>
  <?php endforeach; endif; ?>

  <div class="foot">Predizione · report per uso da palco. Contiene dati personali di spettatori consenzienti: conservalo con cura e cancellalo quando non serve.</div>
</div></body></html>
