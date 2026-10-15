<?php
/* =========================================================================
   PREDIZIONE — PROVA: manda tante mail insieme nella casella del gioco
   -------------------------------------------------------------------------
   Serve SOLO per collaudare il robottino (lo "split" fino a 10 robottini su
   una casella piena). Apri questa pagina nel browser:
       https://www.abraka.it/predizione/prova-invio.php
   Compila il modulo e premi Invia. Riempie di mail NON LETTE la casella del
   gioco; poi avvia il gioco e guarda il robottino svuotarla.

   NB: usa lo STESSO motore d'invio del robottino (smtp.php). Protetto dalla
   password amministratore. CANCELLA questo file quando hai finito la prova.
   ========================================================================= */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smtp.php';

@set_time_limit(0);               // invii lunghi: non interrompere a metà
header('Cache-Control: no-store');

function pv($k, $d = '') { $v = $_POST[$k] ?? $d; return is_string($v) ? trim($v) : $v; }

$fatto    = false;
$risultati = [];
$inviate  = 0;
$errori   = 0;
$msgTop   = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pw   = pv('password');
    $dest = pv('dest');                                  // casella del GIOCO (dove legge il robottino)
    $mitt = pv('mitt_user');                             // casella di PROVA (da cui spedisco)
    $mpas = pv('mitt_pass');                             // password per app della casella di prova
    $from = pv('mitt_from') ?: $mitt;                    // indirizzo mostrato come mittente
    $n    = (int) pv('quante', '20');

    if ($pw !== PRED_ADMIN_PASS) {
        $msgTop = 'Password amministratore errata.';
    } elseif ($dest === '' || $mitt === '' || $mpas === '') {
        $msgTop = 'Compila: casella del gioco, casella di prova e password per app.';
    } else {
        if ($n < 1)   $n = 1;
        if ($n > 150) $n = 150;                           // tetto di sicurezza
        [$host, $port] = pred_smtp_host($mitt);           // ricava host/porta dalla casella di prova
        $stamp = date('His');                             // per distinguere questa infornata
        for ($i = 1; $i <= $n; $i++) {
            $sub  = "Prova PREDIZIONE #$i-$stamp";
            $body = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">'
                  . "<p>Mail di prova numero <b>$i</b> (infornata $stamp).</p>"
                  . '<p>Serve a collaudare il robottino: non e\' una mail vera.</p></div>';
            $err  = null;
            $ok   = pred_smtp_send($host, $port, $mitt, $mpas, $from, 'Prova Predizione',
                                   $dest, $sub, $body, $err);
            if ($ok) { $inviate++; }
            else     { $errori++; if (count($risultati) < 6) $risultati[] = "#$i: $err"; }
        }
        $fatto = true;
        $msgTop = "Inviate: $inviate — Errori: $errori";
    }
}

/* valori riproposti nel form (tranne le password) */
$vDest = htmlspecialchars(pv('dest'), ENT_QUOTES, 'UTF-8');
$vMitt = htmlspecialchars(pv('mitt_user'), ENT_QUOTES, 'UTF-8');
$vFrom = htmlspecialchars(pv('mitt_from'), ENT_QUOTES, 'UTF-8');
$vN    = htmlspecialchars((string)(pv('quante') ?: '20'), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="it"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Prova invio — PREDIZIONE</title>
<style>
  body{margin:0;background:#0a0908;color:#ece0c9;font-family:Arial,Helvetica,sans-serif;line-height:1.5}
  .wrap{max-width:560px;margin:0 auto;padding:26px 18px}
  h1{font-size:20px;margin:0 0 6px}
  p.sub{color:#9c9179;margin:0 0 20px}
  label{display:block;margin:14px 0 4px;font-size:14px;color:#cdbf9f}
  input{width:100%;box-sizing:border-box;padding:11px 12px;border-radius:9px;border:1px solid #3a342b;
        background:#15120d;color:#f1e7cf;font-size:15px}
  .hint{font-size:12px;color:#8a8069;margin:3px 0 0}
  button{margin-top:22px;width:100%;padding:14px;border:0;border-radius:11px;background:#c9a24a;color:#1a1408;
         font-size:16px;font-weight:bold;cursor:pointer}
  .msg{padding:12px 14px;border-radius:9px;margin:0 0 16px;font-weight:bold}
  .ok{background:#17311a;color:#bfe6c2;border:1px solid #2f5d35}
  .ko{background:#361717;color:#eccaca;border:1px solid #5d2f2f}
  .box{background:#15120d;border:1px solid #3a342b;border-radius:10px;padding:12px 14px;margin-top:14px;font-size:13px;color:#cdbf9f}
  code{color:#e6c97a}
</style></head><body><div class="wrap">
  <h1>Prova invio — riempi la casella</h1>
  <p class="sub">Manda tante mail insieme nella casella del gioco, per collaudare il robottino.</p>

  <?php if ($msgTop !== ''): ?>
    <div class="msg <?= ($fatto && $errori === 0) ? 'ok' : 'ko' ?>"><?= htmlspecialchars($msgTop, ENT_QUOTES, 'UTF-8') ?></div>
    <?php if (!empty($risultati)): ?>
      <div class="box"><b>Primi errori:</b><br><?= implode('<br>', array_map(fn($r)=>htmlspecialchars($r, ENT_QUOTES,'UTF-8'), $risultati)) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <label>Casella del GIOCO (dove legge il robottino)</label>
    <input name="dest" value="<?= $vDest ?>" placeholder="es. io@abraka.it oppure la Gmail del cliente">
    <div class="hint">È l'indirizzo che il robottino controlla. Le mail arrivano qui, NON lette.</div>

    <label>Casella di PROVA da cui spedire</label>
    <input name="mitt_user" value="<?= $vMitt ?>" placeholder="es. unatuagmail@gmail.com">
    <div class="hint">Una tua casella diversa da quella del gioco: ci arriveranno le risposte del robottino.</div>

    <label>Password per app della casella di prova</label>
    <input name="mitt_pass" type="password" placeholder="16 caratteri (Gmail) o password SMTP">
    <div class="hint">Per Gmail serve la "password per app". Non viene salvata: si usa solo ora.</div>

    <label>Mittente mostrato (facoltativo)</label>
    <input name="mitt_from" value="<?= $vFrom ?>" placeholder="lascia vuoto per usare la casella di prova">

    <label>Quante mail (max 150)</label>
    <input name="quante" type="number" min="1" max="150" value="<?= $vN ?>">
    <div class="hint">Consiglio: la prima volta prova con <b>20</b>; se va, rilancia con <b>100</b>.</div>

    <label>Password amministratore</label>
    <input name="password" type="password" placeholder="la password del 000">

    <button type="submit">Invia le mail di prova</button>
  </form>

  <div class="box">
    <b>Come leggere la prova</b><br>
    1) Invia (es. 100 mail) → finiscono NON lette nella casella del gioco.<br>
    2) Nel pannello premi <b>Avvia</b> il gioco su quella casella.<br>
    3) Il robottino le svuota; le <b>risposte</b> arrivano alla casella di prova.<br>
    4) Conta le risposte: devono essere <b>tante quante le mail inviate</b> (nessuna persa) e <b>una sola per mail</b> (nessun doppione).<br>
    <br>Quando hai finito, <b>cancella questo file</b> da Tophost.
  </div>
</div></body></html>
