<?php
// ============================================================================
// APP FERIE - impostazioni
// Da modificare solo se le cartelle sul NAS sono diverse da quelle previste.
// ============================================================================

// Cartella della dashboard ("avvio") dove stanno orari_lavoro.json,
// impostazioni.json, correzioni_timbrature.json, ferie_saldi.json ...
// Percorso relativo a QUESTA cartella (ferie/): ziwood/ferie -> ziwood/avvio
if (!defined('DATI_DIR')) define('DATI_DIR', __DIR__ . '/../avvio');

// Le richieste dei dipendenti: un file JSON per richiesta.
if (!defined('RICHIESTE_DIR')) define('RICHIESTE_DIR', DATI_DIR . '/richieste_ferie');

date_default_timezone_set('Europe/Rome');

// Dopo quanti PIN sbagliati consecutivi si blocca il nome, e per quanto tempo.
if (!defined('MAX_TENTATIVI')) define('MAX_TENTATIVI', 5);
if (!defined('MINUTI_BLOCCO')) define('MINUTI_BLOCCO', 10);

// Quanto resta valido l'accesso sul telefono (ore) prima di richiedere di nuovo il PIN.
if (!defined('DURATA_SESSIONE_ORE')) define('DURATA_SESSIONE_ORE', 12);

// Accesso con la carta NFC: sessione breve (minuti). Non resta salvata sul telefono.
if (!defined('DURATA_SESSIONE_CARTA_MIN')) define('DURATA_SESSIONE_CARTA_MIN', 15);

// Carta NFC: se true, chi non ha un "codice carta" personale (impostato in
// dashboard) puo' entrare con la sola lettura del NOME sulla carta.
// Comodo, ma chi conosce il nome di un collega potrebbe aprire il suo profilo.
// Mettere false per accettare la carta solo se il link contiene il codice.
if (!defined('NFC_ACCETTA_SOLO_NOME')) define('NFC_ACCETTA_SOLO_NOME', true);

// Se true il dipendente puo' chiedere piu' giorni di quelli disponibili.
if (!defined('CONSENTI_OLTRE_SALDO')) define('CONSENTI_OLTRE_SALDO', false);
