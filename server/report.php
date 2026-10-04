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

if (!predizione_auth($utente, is_string($password) ? $password : null)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:30px">Accesso negato.</body>';
    exit;
}

$stato = predizione_leggi_stato();
$sid = ctype_digit((string)p('s')) ? (int)p('s') : (int)($stato['sessione_corrente'] ?? 0);

/* --- mittenti (dal log delle mail in arrivo) ----------------------------- */
$mitt = [];   // id => {mittente, oggetto, testo, ora}
$mfile = predizione_data_dir() . '/mail_sess_' . $sid . '.jsonl';
if (is_file($mfile)) {
    foreach (file($mfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? '');
        $key = $id !== '' ? $id : ('m' . count($mitt));
        $mitt[$key] = $r;
    }
}

/* --- aperture (dal log image.php) ---------------------------------------- */
$ap = [];   // id => {first, labels[], ua, ip, n}
$lf = predizione_log_file();
if (is_file($lf)) {
    $fh = @fopen($lf, 'r');
    if ($fh) {
        while (($ln = fgets($fh)) !== false) {
            if (trim($ln) === '') continue;
            $c = str_getcsv($ln);
            if ((string)($c[1] ?? '') !== (string)$sid) continue;
            $id = (string)($c[2] ?? '');
            $lbl = strtoupper(substr((string)($c[3] ?? ''), 0, 1));
            $key = $id !== '' ? $id : ('a' . count($ap));
            if (!isset($ap[$key])) $ap[$key] = ['first' => $c[0] ?? '', 'labels' => [], 'ua' => $c[5] ?? '', 'ip' => $c[4] ?? '', 'n' => 0];
            $ap[$key]['n']++;
            if ($lbl === 'A' || $lbl === 'B') $ap[$key]['labels'][$lbl] = true;
            // prima apertura = la più antica (le righe sono in ordine cronologico, tengo la prima)
        }
        fclose($fh);
    }
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

/* --- unione per destinatario (id) ---------------------------------------- */
$ids = array_values(array_unique(array_merge(array_keys($mitt), array_keys($ap))));

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
</style>
</head><body><div class="wrap">
  <h1>Report sessione #<?= (int)$sid ?></h1>
  <div class="sub">Utente <?= h($utente) ?> · generato il <?= h(date('d/m/Y H:i')) ?></div>

  <div class="cards">
    <div class="card"><b><?= count($mitt) ?></b><span>Mittenti</span></div>
    <div class="card"><b><?= count($ap) ?></b><span>Destinatari che hanno aperto</span></div>
    <div class="card"><b><?= array_sum(array_map(function($x){return $x['n'];}, $ap)) ?></b><span>Aperture totali</span></div>
  </div>

  <div class="btns">
    <button onclick="window.print()">Stampa / Salva PDF</button>
  </div>

  <h2>Riepilogo per destinatario</h2>
  <?php if (!$ids): ?><div class="empty">Nessun dato per questa sessione.</div><?php else: ?>
  <table>
    <thead><tr><th>Mittente</th><th>Ricevuta</th><th>1ª apertura</th><th>Foto vista</th><th>Dispositivo</th><th>Aperture</th></tr></thead>
    <tbody>
    <?php foreach ($ids as $id):
        $m = $mitt[$id] ?? null; $a = $ap[$id] ?? null; ?>
      <tr>
        <td><?= h($m['mittente'] ?? '—') ?></td>
        <td class="mono"><?= h($m['ora'] ?? '—') ?></td>
        <td class="mono"><?= h($a['first'] ?? '—') ?></td>
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
