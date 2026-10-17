# PREDIZIONE — Passaggio di consegne

> Documento di handover del progetto. Spiega **cosa fa** l'app, **com'è fatta**,
> le **decisioni prese** e **cosa manca ancora**. Leggilo prima di metterci mano.

Ultimo aggiornamento: 17 ottobre 2026 (AGG 59 — righe prezzi allineate; accessori con scadenza (30 gg default, modificabile); licenza senza banner "bozza"; prima: AGG 58).

> ### Aggiunte 17 ottobre 2026 (AGG 59 — allineamento prezzi + accessori a scadenza + licenza)
> - **Allineamento pagina prezzi (`prezzi.html`):** righe feature con **altezza fissa** (2 righe) e `.yr`
>   a 2 righe → le voci dei 4 piani sono sulla stessa linea anche quando il testo va a capo (su telefono
>   l'altezza fissa si disattiva, i piani si impilano).
> - **Accessori con SCADENZA.** Gli extra acquistati ora si aggiungono come **crediti** che **NON si
>   ricaricano** ogni mese e **scadono dopo N giorni** dall'accredito (default **30**, campo `giorni` per
>   extra, 0 = senza scadenza). `piani.php`: extra con `giorni`; `predizione_applica_extra_ordine` imposta
>   `scad = now + giorni*86400` (i crediti con `scad` sono già esclusi quando scaduti). Editor (`admin.html`):
>   campo **"valido gg"** per ogni estensione. Pagina prezzi + carrello: nota "valgono 30 giorni, non si
>   ricaricano" e, per gli extra con durata diversa, "· valido N gg". (Report resta un interruttore, senza scadenza.)
> - **Licenza (`guide/licenza-uso.html`):** tolto il banner rosso "Bozza di lavoro / da far verificare al
>   commercialista" in cima.
> - **Richiede:** `predizione/` → `piani.php`, `stato.php`, `ordine.php`, `admin.html`; radice → `prezzi.html`,
>   `acquista.html`; (facolt.) `guide/licenza-uso.html` nel sito. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 58 — ritocchi confronto prezzi)
> - **`prezzi.html`:** riga omaggio assente → ora è un **spaziatore invisibile** (niente più scritta
>   "nessun omaggio di benvenuto"), così "Invii grandi" resta allineato anche nel Foyer.
> - **Mail senza tetto giornaliero** → scritta **"(nessun limite giornaliero)"** (es. Palco "3500 mail al
>   mese (nessun limite giornaliero)"; vale anche per Foyer).
> - **Richiede:** radice del sito → `prezzi.html`. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 57 — confronto piani con righe fisse allineate)
> - **`prezzi.html` `feats()` riscritta:** ogni piano mostra SEMPRE le stesse 7 righe nello stesso ordine
>   (Sessioni · Mail · Foto magic al mese · 🎁 Omaggio · Assistente · Report · Invii grandi/Palco). Le voci
>   NON incluse compaiono comunque, **in grigio** ("non incluso" / "a pagamento") → colonne allineate e confrontabili.
>   Prima: Report/Assistente su una riga unica, omaggio solo dove c'era, Palco attaccato alla mail → righe sfalsate.
> - **Omaggi (editor):** già ora ogni piano ha il campo 🎁 omaggio per mail/foto/sessioni (stesse voci, quantità
>   0 dove non c'è) → invariato, è già uniforme.
> - **Richiede:** radice del sito → `prezzi.html`. (Nel bundle anche i file AGG 51→56.) Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 56 — disattivare la base d'accesso "aaa")
> - **La base "aaa" (provenienza "diretto") è disattivabile.** Nuovo marcatore `_dati/accesso_base_off`
>   (`config.php`: `predizione_accesso_base_off()` / `predizione_accesso_base_set()`). Se disattivata,
>   `predizione_accesso_etichetta('aaa')` → null → sul sito "aaa" NON è più una password valida.
> - **Pannello 000 → Password d'accesso (`admin.html` + `stato.php`):** la riga "aaa" ora ha **Elimina**
>   (disattiva) e, quando è spenta, mostra **"disattivata"** + **Ripristina**. `admin_accessi` espone `off`/
>   `base_off`; `admin_accesso_elimina` con pw=base la disattiva; nuova azione `admin_accesso_base` (attiva 1/0).
> - **Richiede:** `predizione/` → `config.php`, `stato.php`, `admin.html`. (Nel bundle anche i file AGG 51→55.) Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 55 — Salva + Vedi prezzi in cima all'editor Listino)
> - **Barra in alto (`admin.html`):** nella vista **Listino** ora accanto ad "Aggiorna" compaiono
>   **"Salva"** (oro, salva il listino = come il tasto in fondo) e **"Vedi prezzi ↗"** (apre la pagina
>   prezzi del sito in una nuova scheda, URL calcolato dalla radice). Si mostrano solo in Listino.
> - **Richiede:** `predizione/` → `admin.html`. (Nel bundle anche i file degli AGG 51→54.) Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 54 — significato corretto dei due numeri per servizio)
> - **Due significati diversi, etichette diverse per servizio (`admin.html`):**
>   - **Mail = LIMITI:** riquadro "limite mail" con **al mese** + **max / giorno** (es. 500 al mese, max 100
>     al giorno: il giorno è un TETTO).
>   - **Sessioni = SI SOMMANO:** riquadro "sessioni incluse (si sommano)" con **al giorno** `+` **al mese**
>     (es. 1 al giorno + 5 al mese: il giorno è una BASE, il mese un BONUS).
>   - **Foto magic:** "foto incluse" → **al mese**. Ogni riquadro ha una nota-esempio sotto.
> - **Stesse frasi sul sito** (`prezzi.html` e `acquista.html`): mail → "500 mail al mese (max 100 al giorno)";
>   sessioni → "1 al giorno + 5 al mese" (come nel primo html `prezzi-predizione.html`).
> - Nessun cambio ai dati: solo etichette/frasi più chiare. **Richiede:** `predizione/` → `admin.html`;
>   radice → `prezzi.html`, `acquista.html`. (Nel bundle anche piani.php/stato.php dell'AGG 53.) Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 53 — limiti chiari nell'editor + Report/Assistente nel confronto costi)
> - **Chiarezza "limiti vs omaggio" (`admin.html`):** nella riga di ogni servizio i campi **al giorno / al mese**
>   sono ora racchiusi in un riquadro **"limiti inclusi nel piano"** (tetto: "max / giorno", "inclusi / mese");
>   il campo **🎁 omaggio** (una tantum) resta a parte. Così non si confonde il limite con un'aggiunta.
> - **Report e Assistente nel listino/confronto costi:** `piani.php` extra di fabbrica ora includono
>   **"Assistente di scena" 2 €/mese** (tipo assistente) e **"Report sessione" 1 €/mese** (tipo `report`).
>   Nuovo tipo extra `report`: comprarlo ATTIVA la funzione (`report_extra=true`) invece di dare crediti
>   (`predizione_applica_extra_ordine`). Whitelist aggiornata in `piani.php` e `stato.php`; opzione "Report"
>   aggiunta al menù tipo dell'editor; icona report sul sito.
> - **Prezzo barrato (confermato):** se nel piano il prezzo "si paga" è PIÙ BASSO del "pieno", sul sito il
>   pieno appare **barrato** con lo sconto; se sono uguali, niente barrato. (Il "pieno" dev'essere il numero più alto.)
> - **Richiede:** in `predizione/` → `piani.php`, `stato.php`, `admin.html`; nella radice → `acquista.html`, `prezzi.html`. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 52 — editor a righe unificate (una tantum/giorno/mese) + default dall'html)
> - **Editor listino (`admin.html`): ogni servizio su UNA riga** con interruttore + icona + i campi quantità
>   **una tantum** (= omaggio di benvenuto) · **al giorno** · **al mese**. Mail e Sessioni hanno tutti e tre;
>   Foto magic ha "una tantum" + "al mese" (la foto non ha tetto giornaliero nel sistema). L'omaggio NON è più
>   una sezione separata: si imposta nel campo "una tantum" del servizio (al salvataggio si ricompone la lista
>   `omaggio`). Restano Assistente/Report/Palco con interruttore e le "Estensioni acquistabili di questo piano".
> - **Valori iniziali dall'html prezzi:** `piani.php` nuova costante `PRED_ACCESSO_PIENO=105` → l'accesso di
>   default mostra **105 → 25 (PROMO −76%)** come nella pagina `prezzi-predizione.html`. Gli altri numeri dei
>   piani erano già allineati all'html. (L'editor resta la fonte: si può cambiare tutto da lì.)
> - **Include AGG 51** (vetrina `prezzi.html` dinamica).
> - **Richiede:** in `predizione/` → `piani.php`, `admin.html`; nella radice del sito → `prezzi.html`. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 51 — vetrina `prezzi.html` dinamica dall'editor)
> - **`sito/prezzi.html` non è più scritta a mano:** ora fa `fetch` dell'endpoint `ordine.php?azione=listino`
>   e costruisce da sola accesso (con prezzo pieno barrato + promo %), i 4 piani (sessioni al giorno+mese,
>   mail al mese+giorno, foto, **omaggio 🎁**, assistente/report/Palco, prezzo pieno barrato) e le estensioni
>   (con "/ mese" per quelle ricorrenti). L'interruttore Annuale/Mensile funziona sui dati dinamici.
> - **Fonte della verità = l'editor del pannello 000:** cambi i piani lì e la pagina prezzi si aggiorna da sola.
>   Niente più disallineamento. (La pagina "scegli" `acquista.html` era già dinamica da AGG 48.)
> - Retro-compatibile: se un campo manca, usa valori di ripiego; se l'endpoint non risponde, messaggio gentile.
> - **Richiede:** caricare SOLO `prezzi.html` nella **radice del sito**. Niente AWS, niente altri file.

> ### Aggiunte 17 ottobre 2026 (AGG 50 — Omaggio di benvenuto per piano + coerenza listino)
> - **Omaggio di benvenuto (una volta sola) per piano.** Nel modello piano nuovo campo `omaggio`
>   (stessa forma di `una_tantum`: label/tipo/qta, SENZA prezzo = gratis). Foyer di fabbrica ha
>   **10 Foto magic in omaggio** (coerente con `guide/prezzi-predizione.html`): `flags.foto` resta attiva
>   ma il credito mensile ricorrente è 0. `piani.php`: `predizione_applica_omaggio($rec,$piano)` regala i
>   crediti UNA volta, chiamata SOLO per i **nuovi** utenti in `attiva_ordine` (non su rinnovi/upgrade).
> - **Editor (`admin.html`):** per ogni piano sezione **"Omaggio di benvenuto (una volta sola)"** (aggiungi/
>   togli voci, "gratis") oltre alle "Una tantum (acquistabili)". I campi **al mese** e **al giorno** di Mail e
>   Sessioni erano già presenti (sotto ogni funzione) — restano.
> - **`ordine.php` (listino):** espone `omaggio` per piano. **`acquista.html`:** mostra l'omaggio nel piano (🎁).
> - **Da fare (confermato con l'autore):** la pagina VETRINA `prezzi.html`/`prezzi-predizione.html` diventerà
>   **dinamica** leggendo dall'editor (endpoint listino), così "consiglio agli acquisti" = sempre allineato.
> - **Richiede:** in `predizione/` → `piani.php`, `ordine.php`, `admin.html`; nella radice → `acquista.html`. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 49 — link "Prezzi" → confronto piani, non la guida hosting)
> - **Bug:** il link "Prezzi e guida agli acquisti" (Regia → Limiti, e `acquista.html`) apriva
>   `guide/guida-acquisti.html` = la vecchia guida su **dominio/hosting/invio**, non il **confronto dei piani**.
> - **Fix:** ora punta a **`prezzi.html`** (radice del sito, già online = confronto dei 4 piani) ed etichetta
>   cambiata in "Prezzi e confronto dei piani". `regia-predizione.html` (SW **v22**) + `sito/acquista.html`.
> - Nota: così il link funziona subito (prezzi.html è già online); la cartella `guide/` dell'AGG 47 non serve
>   più per questo link (resta utile solo se vuoi tenere online la guida hosting a parte).
> - **Richiede:** in `predizione/` → `regia-predizione.html`, `service-worker.js`; nella radice → `acquista.html`.

> ### Aggiunte 17 ottobre 2026 (AGG 48 — Listino: prezzo pieno+sconto, interruttori, icone, una tantum per piano)
> - **Prezzo PIENO (barrato) facoltativo** per accesso e per ogni piano (anno+mese). Se uguale o minore
>   del prezzo pagato → nessuno sconto (niente barrato). `piani.php`: `prezzo_anno_pieno`/`prezzo_mese_pieno`
>   nel normalizza (default = prezzo pagato) + `predizione_accesso_pieno()`. Esposti in `ordine.php` (listino)
>   e `stato.php` (admin_listino). Sul sito `acquista.html` appare il prezzo pieno barrato sopra quello pagato.
> - **Interruttore incluso/escluso** per OGNI funzione di OGNI piano nell'editor (`admin.html`): mail, Foto magic,
>   sessioni, assistente, report, Palco — con ICONA a fianco. Spegnere = funzione esclusa (valore a 0/false).
>   Gli stati partono dallo stato attuale del piano → **nessun valore cambia** se non tocchi gli interruttori.
> - **Estensioni (extra) editabili** con scelta **una tantum / al mese** e tipo (mail/foto_ia/sessioni/assistente).
>   `piani.php` `predizione_extra`: campo `ricorrenza` ('una_tantum'|'mese'), tipo 'assistente' ammesso.
>   Pulsante "Aggiungi estensione" per crearne di nuove (codice generato dal nome).
> - **Una tantum PER PIANO**: ogni piano può avere voci una tantum sue (`una_tantum` nel piano). Nel carrello
>   (`acquista.html`) compaiono come aggiunte quando scegli quel piano (codici `pt_<piano>_<indice>`).
>   `predizione_extra_catalogo($piano)` unisce estensioni globali + una tantum del piano → usato sia per il
>   PREZZO sia per l'applicazione dei crediti (restano coerenti). `predizione_applica_extra_ordine` accetta il piano.
> - **Icone** su tutte le voci (editor pannello + pagina "scegli" del sito).
> - **NON cambiati** prezzi/limiti/modalità dei piani esistenti: tutte le aggiunte sono additive e retro-compatibili.
> - **Da fare (nota):** la pagina vetrina `prezzi.html` è ancora scritta a mano (numeri fissi) → va resa
>   dinamica come `acquista.html` in un secondo momento. Il "al mese" delle estensioni oggi aggiunge i crediti
>   una volta (non è ancora un addebito ricorrente automatico).
> - **Richiede:** caricare in `predizione/` → `piani.php`, `ordine.php`, `stato.php`, `admin.html`; nella
>   radice del sito → `acquista.html`. Niente AWS.

> ### Aggiunte 17 ottobre 2026 (AGG 47 — correzioni ricevuta/mail/icona + link guida + form accesso)
> - **Ricevuta PDF: codice ordine mascherato.** `piani.php` (ricevuta) ora stampa `$o['codice']`
>   (es. `p90a0`) invece del numero interno `PRD-0009`. Così l'allegato della mail di benvenuto
>   porta il codice "giusto" (quello che il cliente usa come causale). Nessun'altra logica cambiata.
> - **Mail di benvenuto:** tolta la frase iniziale "Non devi capire nulla da solo:" (`ordine.php`).
> - **Icona "Foto magic"** nella Regia cambiata (fotocamera + scintille, fusione delle vecchie
>   proposte 2+6). Solo `regia-predizione.html`; service-worker a **v21**.
> - **Cartella `guide/` MANCANTE sul server.** Il link "Prezzi e guida agli acquisti" dava 404
>   perché `www.abraka.it/guide/` non esisteva (il codice puntava giusto, mancava solo il file).
>   Va caricata la cartella `guide/` nella **radice del sito**. Nessuna modifica al codice.
> - **Form "Richiedi l'accesso" (sito `index.html`):** dopo l'invio compare per un attimo il
>   messaggio "Richiesta inviata" e si torna da soli alla schermata Password (via l'alert bloccante).
> - **Richiede:** caricare in `predizione/` → `piani.php`, `ordine.php`, `regia-predizione.html`,
>   `service-worker.js`; nella **radice del sito** → `index.html` + cartella `guide/`. Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 46 — "Caligrafia Foto magic": il prompt dell'IA è modificabile dal pannello 000)
> - **Nuova voce di menù "Caligrafia Foto magic" nel pannello 000 (`admin.html`).** Mostra l'elenco dei
>   prompt salvati con uno **attivo** (radio), permette di **modificarlo**, **crearne di nuovi** e
>   **sceglierne uno**. Nel testo la parola **{FRASE}** viene sostituita a runtime con la frase vera.
> - **Il prompt NON è più cablato.** `config.php`: `predizione_prompt_ia_default()` (la ricetta di prima,
>   con `{FRASE}` dove andava la frase) + `predizione_prompts_ia_leggi/scrivi` (archivio `_dati/prompts_ia.json`:
>   `{attivo, lista:[{id,nome,testo}]}`) + `predizione_prompt_ia_attivo()`. `genera_ia.php` usa il prompt
>   attivo e sostituisce `{FRASE}` (se manca, aggiunge la frase in coda). **Se il file non c'è, vale il
>   predefinito → comportamento invariato.**
> - **stato.php:** `admin_prompt_ia` (legge elenco+attivo+predefinito) e `admin_prompt_ia_salva` (salva).
> - **Richiede:** caricare su Tophost `config.php` + `stato.php` + `genera_ia.php` + `admin.html`.
>   (Nel pacchetto ci sono anche gli altri file dell'AGG 45: ricaricarli non fa danno.) Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 45 — "Autorisponditore" e "Invia mail" funzioni separate attivabili per utente)
> - **Due funzioni distinte al posto dell'unica "mail".** `flags` ora ha anche `auto` (Autorisponditore)
>   e `invio` (Invia mail a mano), oltre a `mail`/`foto`. `config.php` `predizione_flags`: se `auto`/`invio`
>   non sono impostati (utenti vecchi) valgono come `mail` → **nessuno perde funzioni**.
> - **Pannello 000 (`admin.html`):** nella scheda utente, sezione gestione "Modi di invio inclusi" con due
>   spunte **autorisponditore** e **invia mail** (salvate con `admin_flags`, che ora accetta `auto`/`invio`).
> - **Regia (`regia-predizione.html`):** la tile **Autorisponditore** (`#tileAuto`) si blocca se `!auto`;
>   la tile **Invia mail** (`#tileCasella`) si blocca se `!invio` (prima era legata a `mail`). FLAGS include auto/invio.
> - **Blindatura server:** l'azione `autorisponditore` (stato.php) rifiuta l'accensione se `!auto`; `invia.php`
>   rifiuta l'invio a mano se `!invio`.
> - **config.php va caricato** (aggiunge solo le due funzioni; le password nel file sono residui NON usati
>   — il login vero è in utenti.json — quindi ricaricarlo non cambia nessuna password viva).
> - **Richiede:** caricare su Tophost `config.php` + `stato.php` + `invia.php` + `regia-predizione.html` +
>   `admin.html` + `service-worker.js` (cache **v20**). Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 44 — SICUREZZA: password casella non più esposta + nuovo utente con mail di default)
> - **La password della casella NON esce più nello stato pubblico.** `stato.php`: nuovo
>   `predizione_mail_cfg_pubblica()` (svuota `pass`, aggiunge `pass_impostata` true/false) usato in
>   `predizione_stato_pubblico`. Prima `stato.php?azione=stato` (senza password) restituiva la password
>   della casella in chiaro → chiunque poteva leggerla. **Risolto.** (`admin_scheda`, dietro login admin,
>   continua a mostrarla all'amministratore.)
> - **Salvataggio casella senza riscrivere la password.** `mail_config`: se il campo password è vuoto,
>   TIENE quella già salvata (non la cancella). In `regia-predizione.html` il campo password non viene più
>   pre-riempito con la password vera: mostra "•••••• già impostata — lascia vuoto per tenerla"
>   (`g_passImpostata`); "Salva casella" accetta il campo vuoto se c'è già una password.
> - **Nuovo utente (pannello 000) con "funzione mail" ATTIVA di default** (`admin.html`): senza quel
>   flag l'utente non ha casella né guida di configurazione (era fonte di confusione). Aggiunta nota.
> - **Richiede:** caricare su Tophost `stato.php` + `regia-predizione.html` + `admin.html` +
>   `service-worker.js` (cache **v19**). Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 43 — cambio username nel pannello 000, suffisso nei nomi doppi, Gmail "@" automatico)
> - **Cambio USERNAME dal pannello 000 (`admin.html` + `stato.php`).** Nuova azione `admin_rinomina`:
>   rinomina l'username (chiave in `utenti.json`) E sposta la cartella dati `_dati/u<old>`→`_dati/u<new>`.
>   **Permesso SOLO se l'utente non ha ancora fatto sessioni** (`ha_giocato` in `admin_scheda`): altrimenti
>   si romperebbero i link `image.php?u=…` già inviati. `000`/`001` mai rinominabili. Nella scheda:
>   riga "Username di accesso" + campo "Cambia username" (o lucchetto con spiegazione se ha giocato);
>   dopo il cambio la scheda si riapre col nuovo nome.
> - **Nomi doppi: numero come SUFFISSO** (`piani.php` `predizione_utente_libero_da_email`): ora
>   `mario.rossi` → `mario.rossi1` → `mario.rossi2` (prima era prefisso `1mario.rossi`).
> - **Gmail "@gmail.com" automatico** nella guida (percorso Gmail): se scrivi solo il nome
>   (`ilmiomagoshow`), l'app aggiunge da sola `@gmail.com` al salvataggio. Nota nel campo.
>   **AGG 43.1:** il campo mostra `@gmail.com` come **suffisso fisso visibile e non modificabile**
>   (si scrive solo la parte prima della @); al salvataggio si compone `nome@gmail.com`. SW **v18**.
> - **Include anche AGG 42.1:** nella sezione "La tua casella" (Account) le etichette ora si
>   ADATTANO all'indirizzo (Gmail → "Indirizzo Gmail"/"Password per le app 16 lettere";
>   dominio → "Indirizzo email"/"Password della casella"), via `caselleLabels()`.
> - **Richiede:** caricare su Tophost `stato.php`, `invia.php`, `piani.php`, `regia-predizione.html`,
>   `admin.html`, `service-worker.js` (cache **v17**). Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 42 — guida primo accesso: casella di dominio, "Salta", tipo casella nel pannello 000)
> - **Guida del primo accesso (`regia-predizione.html`):**
>   - Tasto **"Salta per ora"** nella schermata iniziale (prima la guida restava obbligatoria finché
>     non impostavi la casella). `data-act="salta"` → `gChiudi(true)` (non si riapre; la si rifà da Impostazioni).
>   - Il percorso **"Un dominio mio"** ora **configura davvero la casella** (indirizzo + password della
>     casella + nome mittente) e la salva con `mail_config` **`tipo:'altro'`**. Prima mandava solo
>     all'assistenza. Il nome d'accesso lo trova il login automatico (AGG 41). Il percorso Gmail salva `tipo:'gmail'`.
> - **Tipo casella nel pannello 000 (`admin.html`):** nel sottotitolo di ogni utente ora compare
>   **📧 Gmail / 🏠 Altra / ✉️ —** (da `casella_tipo`). `stato.php`: nuovo helper `predizione_casella_tipo`
>   (usa il `tipo` salvato, altrimenti lo DEDUCE dal dominio dell'indirizzo), esposto in `admin_utenti`
>   (`casella_tipo`) e in `predizione_mail_cfg` (`tipo`).
> - **Richiede:** caricare su Tophost `stato.php`, `invia.php` (AGG 41), `regia-predizione.html`,
>   `admin.html`, `service-worker.js` (cache **v14**). Niente AWS.

> ### Aggiunte 15 ottobre 2026 (AGG 41 — "Invia mail" a mano: login automatico casella di dominio)
> - **Bug trovato:** il tasto "Invia mail" (`invia.php`) faceva login SMTP con l'indirizzo
>   completo (`io@abraka.it`), ma le caselle di **dominio** su Tophost entrano col **nome della
>   mailbox** = la parte dopo la @ (`abraka.it`) → "login casella fallito (465)". (Il robottino
>   invece funziona perché nelle env usa `IMAP_USER=abraka.it`.)
> - **Fix (solo Tophost, niente app, niente AWS):** `invia.php` ora prova i login **in ordine
>   automatico**: 1) un `login` impostato a mano (se c'è); 2) l'**indirizzo intero** (giusto per
>   Gmail); 3) la **parte dopo la @** (giusto per le caselle di dominio). Appena uno entra, lo
>   riusa per gli invii successivi (helper `iv_invia_con_login`). `stato.php`
>   (`predizione_mail_da_richiesta` + `predizione_mail_cfg`) accetta/espone un campo
>   facoltativo `login` (override manuale), per ora non mostrato nell'app.
> - **Da fare (facoltativo):** scelta "Gmail / Altra casella" nell'app con etichette giuste
>   (oggi dicono sempre "Gmail"): migliora solo le scritte, il login funziona già da solo.
> - **Richiede:** caricare SOLO `stato.php` + `invia.php` su Tophost.

> ### Aggiunte 15 ottobre 2026 (AGG 40 — report: colonna Robottino + robottini/tempo)
> - **Report:** nuova colonna **"Robottino"** (numero della fetta che ha risposto a quella mail:
>   `index.mjs` passa `robot=parte+1` e `parti=totParti` nel `mail_log`; `stato.php` li salva nella
>   riga). Nel riepilogo in alto tre nuove card: **Robottini che hanno risposto** (quanti numeri
>   diversi), **Massimo insieme** (max `parti` = picco contemporaneo), **Tempo di lavoro**
>   (dal 1º all'ultimo `inviata`). Tutto calcolato in `report.php` dai dati delle mail.
>   **Richiede re-deploy index.mjs + stato.php/report.php su Tophost.** (Vale per le sessioni NUOVE.)
> - **Robottini log (da AGG-39e):** `index.mjs` scrive in CloudWatch `GIRO robottini=N split={...}`
>   e `PICCO robottini per casella={...}` per contare i robottini anche dai log.

> ### Aggiunte 15 ottobre 2026 (AGG 39 — pannello 000, report "Inviata", Test interno, mobile)
> - **Report: colonna "Inviata il".** `mail_log` (stato.php) accetta `inviata` → registra l'ora
>   della risposta nella riga del destinatario (aggiorna la riga anche se la mail era già stata
>   registrata come "ricevuta", es. modo manuale). `index.mjs` passa `inviata:'1'` quando risponde.
>   `report.php` ha la nuova colonna tra "Ricevuta" e "Aperture". **Richiede re-deploy index.mjs.**
> - **"Test (invio prova)" dentro il pannello 000.** Nuova voce di menù + vista: riempie una casella
>   di N mail (max 150) per collaudare il robottino, con credenziali **prese in automatico** dall'utente
>   scelto (azione admin `admin_prova_invio`; invio in blocco `pred_smtp_invia_blocco` in smtp.php, UNA
>   connessione → niente 504). Mittente ≠ destinatario (anti-loop). Il vecchio `prova-invio.php` sciolto
>   è stato RIMOSSO (ora è tutto nel pannello admin). **Il client spezza l'invio in blocchi da 20**
>   (richieste brevi) così anche 100 mail NON vanno in timeout sul server di Tophost. **Multi-mittente:**
>   si possono spuntare PIÙ caselle mittenti e un "max per casella" (default 50): il totale si divide
>   fra le caselle (così nessuna Gmail viene frenata; Gmail si ferma a ~84/raffica).
> - **Scheda utente (pannello 000):** nel titolo ora **provenienza + piano** (non più la password);
>   la **password attuale** è accanto a "Cambia password d'accesso"; tasto **"Entra diretto ▶"** già
>   presente (apre la Regia dell'utente in nuova scheda via `?u=&k=`). **Limiti modificabili a mano**
>   (azione `admin_limiti`): aggiusti i numeri (mail/foto/sessioni/assistenti) e si salvano in
>   `rec['piano_dati']` SENZA cambiare piano (il piano resta, lo snapshot vince).
> - **Mobile (Regia + Assistente):** `env(safe-area-inset-*)` su header/impostazioni (il titolo
>   "Impostazioni" non finisce più sotto il notch iPhone) e **barre di scorrimento nascoste** (scroll
>   invariato, ma niente più barra verticale inutile). Service-worker **v13** (così i telefoni ricaricano).


> ### ⚠️ Correzioni recenti (11 ottobre 2026) — leggere PRIMA del resto
> Alcune parti più vecchie di questo documento citano ancora Brevo: sono **superate**.
> Lo stato VERO oggi è questo:
> - **Invio grandi volumi = Mailgun, NON Brevo.** Brevo è stato abbandonato (non funzionava).
>   Il canale "Palco" del pannello usa **Mailgun (SMTP)**. Nel codice della Lambda le variabili
>   del canale grande sono ora `MAILGUN_HOST/PORT/USER/PASS` (i vecchi `BREVO_*` restano accettati
>   solo per compatibilità). Guida aggiornata: `guide/palco-aws.html`.
> - **abraka.it NON invia più mail agli spettatori.** Il sito/dominio abraka serve solo come
>   **vetrina di vendita** e **pannello di controllo** dei clienti, e per le mail **commerciali**
>   ai clienti (benvenuto, ricevute, da `supporto@abraka.it`). Le mail agli spettatori le invia
>   **ciascun cliente**: dal **proprio dominio** via Mailgun (modo "Palco") o dalla **propria
>   casella** (modo "Casella", es. Gmail con password per app, eventi piccoli).
> - **Mailgun è multi-dominio con UN solo account (quello dell'autore, che paga lui).** L'autore
>   aggiunge in Mailgun un dominio per ogni cliente; il cliente deve solo **incollare 3-4 righe DNS**
>   nel proprio dominio (istruzioni pronte nella guida). Il cliente non crea account né paga Mailgun.
> - **"Palco" richiede un DOMINIO: NON funziona con Gmail.** Gmail vieta gli invii a raffica
>   (soglie un po' più alte ma non utilizzabili in pratica). Chi ha solo Gmail usa il modo "Casella".

> ### Aggiunte 15 ottobre 2026 (AGG 37 — split di UNA casella fra più robottini + turno 110s)
> - **Work-stealing DENTRO una casella.** Una casella piena viene affidata a più robottini insieme
>   (fino a `MAX_PARTI`=10, env). Ogni fetta prende solo gli uid con `uid % totParti === parte`
>   (disjoint → nessun doppione per costruzione). `processaCasella` legge `cfg.parte`/`cfg.totParti`
>   e filtra la search unseen. `unGiro(alloc)` espande ogni casella in `alloc.get(utente)` fette.
> - **Allocazione dinamica (ramp).** L'handler aggiorna `alloc` a ogni giro: casella che torna
>   `ancora` (piena) → robottini raddoppiati (1→2→4→8→10); casella svuotata → torna a 1. Il pool
>   `inParallelo(ROBOTTINI)` limita comunque il totale di fette concorrenti a 30.
> - **Turno a 110s** (`MAX_MS=110000`, era 55000). **RICHIEDE Concorrenza riservata = 1** su AWS
>   (il turno supera il minuto). Timeout AWS consigliato 3 min, memoria 512 MB.
> - Simulato (800 mail, 1 casella): 0 doppioni, 0 perse, sale a 10 robottini. **Rischio reale da
>   provare: più connessioni IMAP simultanee alla STESSA casella (fino a 10); se il provider le
>   rifiuta, abbassare `MAX_PARTI`. Fare una PROVA reale prima di uno show grande.** Solo `index.mjs`.
> - **Nota sul "turno successivo che aiuta":** incompatibile con Concorrenza=1 (un turno alla volta);
>   l'aiuto avviene DENTRO il turno (fino a 10 robottini per casella), non tra turni.

> ### Aggiunte 15 ottobre 2026 (AGG 38 — LUCCHETTO lato server: un turno alla volta SENZA concorrenza riservata)
> - **Problema risolto:** l'account AWS non può impostare "Concorrenza riservata = 1" (il
>   limite dell'account è 10 e AWS pretende che la concorrenza NON riservata resti ≥100 →
>   impossibile). Senza quel "1" due esecuzioni potevano sovrapporsi (turno >60s + sveglia al
>   minuto) → rischio doppioni. **Soluzione: un lucchetto su stato.php** (niente più bisogno di
>   concorrenza riservata).
> - **stato.php:** nuova azione admin `admin_lock` + helper `predizione_robot_lock($op,$token,$ttl)`.
>   Un unico file `_dati/robot.lock` = `{token, ts}`. Operazioni: `prendi` (solo se libero, scaduto
>   o già suo), `rinnova` (solo se è suo), `rilascia` (solo se è suo). **Atomico** con `flock(LOCK_EX)`
>   attorno a leggi-decidi-scrivi → due robottini non lo prendono mai insieme. **TTL default 150s**:
>   se un'esecuzione muore senza rilasciare, il lucchetto si libera da solo (nessun blocco eterno).
> - **index.mjs (handler):** a ogni risveglio genera un `LOCK_TOKEN` casuale, PRENDE il lucchetto
>   all'inizio (se occupato da un altro turno vivo → esce SUBITO, niente doppioni), lo RINNOVA a ogni
>   giro (se lo perde → si ferma), lo RILASCIA alla fine (blocco `finally`). Se `PANEL_ADMIN_PASS`
>   non è impostata, il lucchetto è disattivato (torna sempre "preso": comportamento vecchio).
>   **Non serve più "Concorrenza riservata = 1" su AWS.**
> - **Ronda portata a ~3s** (`PAUSA_MS=3000`): durante uno show attivo le mail nuove ricevono
>   risposta in ~3 secondi. `MAX_MS=110000` invariato.
> - **Comportamento a riposo:** se nessuno show è attivo il turno fa UN giro (~1-2s), vede che non
>   c'è nulla ed esce subito (gratis). Le mail ai giochi SPENTI non vanno risposte comunque. La
>   staffetta delle sveglie al minuto + lucchetto garantisce un solo turno alla volta; unico "buco"
>   ~10s tra fine turno (110s) e sveglia utile (120s). **Richiede: caricare stato.php su Tophost E
>   ri-deploy index.mjs su AWS.** Test: lucchetto verificato (mutua esclusione + TTL + atomicità flock).

> ### Aggiunte 14 ottobre 2026 (AGG 36 — ronda ~5s + RAFFICA + 30 robottini)
> - **Ronda ~5s** (`PAUSA_MS=5000`): durante uno show le mail nuove ricevono risposta in ~5s.
> - **RAFFICA (niente pausa quando c'è coda).** `processaCasella` ritorna `ancora` (true se ha
>   colpito `MAX_PER_GIRO`, cioè c'è altra posta). Nuovo loop `handler`: se `backlog` → `continue`
>   SUBITO (svuota a raffica, niente 5s); se svuotato ma show attivo → pausa 5s (ronda per mail
>   nuove); se niente attivo → esce (riposo). `MAX_MS=55000` (esecuzione ~1 min, la sveglia dopo
>   continua). **Richiede "Concorrenza riservata = 1" su AWS** (con la raffica un'esecuzione può
>   durare oltre il minuto: la concorrenza 1 impedisce sovrapposizioni → zero doppioni).
> - **ROBOTTINI default 30** (era 20). Resta `inParallelo` (pool) + cap per casella.
> - **NON ancora fatto:** spartire UNA singola casella grande tra più robottini (work-stealing dentro
>   la stessa casella). Con la raffica un robottino solo svuota 800 in ~10-15 min (limite: timeout
>   Lambda 2 min per esecuzione). Per scendere a ~2 min su un singolo show enorme serve lo split
>   multi-connessione IMAP (parte delicata, da fare con prova reale). Le caselle DIVERSE sono già
>   in parallelo (30 insieme). **Solo `index.mjs`; i file Tophost restano quelli dell'AGG 34.**

> ### Aggiunte 14 ottobre 2026 (AGG 35 — robottino PARALLELO + anti-ingorgo "a turni")
> - **Parallelo DENTRO la stessa Lambda (ROADMAP §2 fatto).** `unGiro()` ora costruisce la lista
>   dei lavori (casa 001 + caselle attive) e li serve con `inParallelo(lavori, ROBOTTINI, processaCasella)`:
>   un pool di worker con AL MASSIMO `ROBOTTINI` (default 20, env) caselle insieme. Essendo lavoro I/O
>   (IMAP/SMTP/HTTP async), 20 caselle avanzano davvero in parallelo → niente più coda sequenziale.
>   **Nessuna nuova infrastruttura AWS** (niente SQS/fan-out): tutto nell'unica Lambda già deployata.
> - **Anti-ingorgo "a turni".** In `processaCasella` tetto `MAX_PER_GIRO` (default 25, env): una casella
>   risponde al massimo a 25 mail per giro poi passa oltre (le altre partono al giro ~12s dopo, restano
>   non lette). Così una casella da 800 mail non blocca le altre. Contatore `fatti` (log o risposta),
>   `if (fatti >= MAX_PER_GIRO) break;`. Si combina con i giri veloci e con il parallelo.
> - **Regolabili da env:** `ROBOTTINI` (quante caselle insieme) e `MAX_PER_GIRO` (mail per casella a giro).
>   Consigliata memoria Lambda 512 MB per 20 in parallelo (opzionale; a 256 funziona). Costo sempre ~€0.
> - Helper `inParallelo(lista, limite, fn)` = pool di worker, preserva l'ordine, cattura gli errori per
>   casella. **Richiede re-deploy Lambda su AWS** (solo `index.mjs`, nessuna modifica PHP/Regia).

> ### Aggiunte 14 ottobre 2026 (AGG 34 — robottino veloce + sicurezza anti-dimenticanza)
> - **Robottino VELOCE (~12s).** La sveglia resta 1/minuto (minimo EventBridge) ma la Lambda
>   fa più giri ravvicinati dentro la stessa invocazione: `handler` cicla `unGiro()` con pausa
>   ~12s finché c'è almeno uno show con `giro_veloce` e finché resta tempo (MAX_MS 50s, niente
>   sovrapposizioni). A riposo/shows spenti esce subito (gratis). `processaCasella` ritorna
>   `giro_veloce` (attivo e non 'rallentato'/'fermo'). Giri sequenziali = nessun rischio doppione.
> - **Sicurezza anti-dimenticanza a tappe** (config.php: `PRED_SIC_DURATA_ORE`=12,
>   `_AVVISO_ANTICIPO`=3→9h, `_RALLENTA_ANTICIPO`=1→11h, `_PROROGA`=3, `_HARD`=24). Ogni sessione
>   ha `scadenza_ts` (avvia = now+12h). `predizione_sicurezza($s)` → fase ok|avviso|rallentato|fermo
>   + `ferma_tra`; esposta in stato pubblico e in `admin_caselle` (`sicurezza_fase`).
>   `predizione_autochiusura()` chiude la sessione a 'fermo' (riusa `predizione_chiudi_sessione`,
>   estratto da 'finisci'); gira a OGNI lettura di stato.php (anche ogni giro del robottino →
>   funziona ad app chiusa). Azioni nuove: `proroga` (scadenza += 3h, "Sì continua") e `sicurezza`
>   (ON/OFF avvisi per lo show; OFF lascia solo il tetto HARD 24h). Entrambe anche per l'assistente.
> - **Pop-up Regia "Sei ancora in gioco?"** (`#sicBox`, stile `.hist`): compare quando
>   `st.sicurezza.fase` è 'avviso' o 'rallentato', con countdown `ferma_tra`, tasti "Sì, continua
>   (+3 ore)" (azione `proroga`) e "Non avvisarmi per questo show" (azione `sicurezza` OFF).
>   `aggiornaSicurezza(st)` chiamata da `render`. Service-worker v12.
> - **Ogni "Avvia" azzera il cronometro** (nuova sessione = nuovo `scadenza_ts`): un gioco nuovo
>   non viene mai scambiato per uno vecchio.
> - **NOTA SCALA:** la Lambda è ancora SEQUENZIALE (un giro processa casa + le caselle attive una
>   dopo l'altra). Ottima per pochi show insieme; per decine di show grandi in contemporanea serve
>   il "parallelo sicuro" (ROADMAP §2), non ancora costruito. **Richiede re-deploy Lambda su AWS.**

> ### Aggiunte 14 ottobre 2026 (AGG 33)
> - **Robottino: solo i giochi ATTIVI (ROADMAP §2).** `admin_caselle` ora salta le caselle con
>   gioco spento (`if (empty($st['autorisponditore'])) continue;`): la Lambda riceve solo gli show
>   avviati e non perde tempo né si connette alle caselle spente. Il controllo lato Lambda
>   (`!stato.autorisponditore` → esce) resta come rete di sicurezza. 001 resta gestito a parte via env.
>   Solo `stato.php` (nessuna modifica alla Lambda per questa voce). ROADMAP §2 → fatto.

> ### Aggiunte 14 ottobre 2026 (AGG 32)
> - **Report: righe non più sfalsate.** La riga "mail ricevuta" e la riga "aperture" ora si
>   UNISCONO per destinatario. Causa del problema: l'`id` del destinatario cambiava tra la
>   registrazione della mail e la risposta (era basato su `Date.now()`). Ora la Lambda usa
>   `idStabile(mail, chiave)` (hash del Message-ID): lo STESSO id in `mail_log` e nell'immagine
>   della risposta → join corretto in `report.php`. **Richiede re-deploy della Lambda su AWS.**
>   (Vale per le sessioni NUOVE; i vecchi log con id casuali restano sfalsati.)
> - **Report: "Storia della sessione" (diario).** Nuovo riquadro in alto con la sequenza di cosa
>   ha premuto il prestigiatore/assistente e QUANDO: Avviato il gioco · Risposta mail · Rivelato
>   (cambio foto) · Chiuso il gioco. Stato: `stato['sessioni'][N]['diario']` = lista `{a,ts}`,
>   scritta da `predizione_diario()` nelle azioni avvia/rispondi/cambia/finisci. `report.php` lo
>   mostra (fallback: ricostruito da avvio_ts/cambio_ts/fine_ts per le sessioni vecchie).

> ### Aggiunte 14 ottobre 2026 (AGG 31)
> - **Occhio → ritorno diretto al gioco.** Premendo l'occhio (accanto a "Cambia") si apre
>   Impostazioni→Foto in "modalità occhio" (`eyeMode`): il tasto `#spBack` diventa
>   "‹ torna al gioco" e, come la ✕, chiude del tutto le impostazioni (`tornaAlGioco()`)
>   invece di tornare al menu. `openSettingsTo`/`openSet` azzerano `eyeMode` e l'etichetta.
> - **Contatore "Mail ricevute" anche in "Rispondi a mano".** Le RICEVUTE ora le conta
>   `mail_log` (una per mail, idempotente con la chiave `msgid` = Message-ID), non più
>   `mail_report` (che resta solo per le INVIATE + consumo mensile). La Lambda, in
>   modalità `rispondiManuale && !rispondiOk`, NON esce più prima: legge le mail per
>   CONTARLE (senza rispondere e senza segnarle `\Seen`) e le registra via `mail_log`
>   con `msgid`; così "ricevute" sale e "inviate" resta 0 finché non si preme "Rispondi
>   mail". **Richiede il re-deploy della Lambda su AWS** (il file `stato.php` da solo non
>   basta per la modalità manuale; in modalità automatica funziona comunque).

> ### Aggiunte 12 ottobre 2026 (AGG 12)
> - **Mail automatiche ignorate.** La Lambda e il report scartano i mittenti automatici
>   (`no-reply@`, `updates@`, newsletter, notifiche, domini ESP): non contano né ricevono
>   risposta (helper `predizione_mitt_automatico` in config.php e `mittenteAutomatico` nella Lambda).
> - **Palco bloccato per chi non ce l'ha.** Il tasto Palco è selezionabile solo se il piano ha
>   `grandi_invii` (`stato_pubblico.palco_abilitato`); l'azione `invio` lo rifiuta lato server.
>   In Impostazioni le voci non attive restano VISIBILI ma in grigio (classe `.locked`), non nascoste.
> - **Tasto "Rispondi mail" (invio manuale).** Interruttore `rispondi_manuale` (azione stato.php):
>   se ON, dopo "Avvia" la Lambda NON risponde finché non si preme "Rispondi mail" in Regia
>   (azione `rispondi` → `rispondi_ok`, azzerato a ogni avvio). Vale anche nell'assistente.
> - **Mio account: storico movimenti.** Ogni consumo/accredito va in `stato['movimenti']`
>   (`predizione_movimento`); toccando una voce in Regia si apre l'estratto conto (azione `movimenti`).
> - **Report:** mostra TUTTI gli orari di apertura (non solo il primo) e ha la **cronologia**
>   (report.php?lista=1) con l'elenco delle sessioni passate; i link sono assoluti (aperto da blob).
> - **Foto:** miniatura VUOTA = X rossa; la foto IA generata diventa subito Foto B anche nella sua
>   miniatura; "Carica" su Foto B offre "da file" o "con IA"; occhio accanto a Cambia → Impostazioni/Foto;
>   orologio conto alla rovescia 30s (antiorario) durante la generazione IA; pop-up orario in oro/scuro.

> ### Aggiunte 12 ottobre 2026 (AGG 14)
> - **Nome utente dal NOME della mail.** I nuovi utenti non hanno più un numero: l'username è la
>   parte della mail prima della @ (es. `mario.rossi@gmail.com` → `mario.rossi`). Se è già preso,
>   progressivo DAVANTI: `1mario.rossi`, `2mario.rossi`… Helper in piani.php
>   (`predizione_utente_da_email`, `predizione_utente_libero_da_email`), usato in `attiva_ordine`.
>   **`predizione_pulisci_utente` ora accetta `[a-z0-9._-]`** (prima solo cifre): gli username
>   stringa funzionano ovunque (cartelle `_dati/u<nome>`, `image.php?u=`, login pannello/assistente).
>   I vecchi id numerici (000 admin, 001 autore) restano validi. Il login del pannello e il parse
>   del link assistente accettano lettere.
> - **Avviso all'autore a OGNI ordine:** mail a `PRED_NOTIFY_EMAIL` sia alla **ricezione** dell'ordine
>   (azione `crea`) sia alla **lavorazione**/pagamento (azione `admin_attiva`). Prima solo al pagamento.

> ### Aggiunte 13 ottobre 2026 (AGG 21 — lotto Regia/listino/scheda)
> - **Imposta foto si svuota dopo "Finisci"**, salvo la spunta **"Blocca la Foto A"** (Regia, sezione foto).
>   Stato `blocca_foto_a` (default TRUE la prima volta); azione `blocca_foto` (ON/OFF); in `finisci` si
>   cancella sempre `rivelazione_pronta` e, se la spunta è OFF, anche `foto_a`. Esposto in stato pubblico.
> - **Tile grigie (bloccate) → Acquista con spiegazione.** In Regia un tap su una tile `.locked` apre la
>   sezione **Limiti** e mostra un box rosso (`#lockMsg`) con la spiegazione; il tasto Acquista è lì.
> - **Prezzi al mese + totale.** `acquista.html`: per l'annuale mostra l'equivalente **€/mese** grande e il
>   **totale annuo** sotto (96 → 8 €/mese). Link **"Prezzi e guida agli acquisti"** (`guide/guida-acquisti.html`)
>   in acquista e nella sezione Limiti della Regia (`#guidaLink`).
> - **"IA" → "Foto magic" ovunque** (Regia tile+sezione, listino editor/storico/scheda, acquista); icona
>   bacchetta magica. Chiavi tecniche `foto_ia` invariate.
> - **Pop-up Aiuto:** le funzioni non attive sono scritte in **rosso e più grandi** (`.help-lock .hl-t/.hl-s`).
> - **Scheda utente (000) a fisarmonica:** sezioni comprimibili (default tutte chiuse), aprendone una si
>   chiudono le altre; tasto **"Apri tutte"/"Chiudi tutte"** in alto (`.acc-h`/`.acc-b`).
> - **Assistente di scena "al mese"** nel listino (etichette editor/scheda) e **nuovo tipo di credito
>   `assistente`** (piani.php/stato.php/config). Gestione crediti nella scheda: **4 righe separate**
>   (mail / Foto magic / sessioni / assistenti) con quantità + scadenza, niente più menù a tendina.
> - **Orario di sicurezza:** selettore in **oro/nero** (due `<select>` Ore/Minuti + azzera) al posto del
>   picker blu di sistema (non stilabile). `swVal()` compone `HH:MM` per `avvia`.

> ### Aggiunte 13 ottobre 2026 (AGG 20 — funzione "Aiuto" nella Regia)
> - **Guida ai tasti (Regia).** Tasto **"Aiuto"** in Impostazioni sotto "Salva e torna" (bordo rosso).
>   Primo tocco → pop-up (`#helpIntro`, stile `.hist`) che elenca le funzioni **grigie bloccate dal piano**
>   (rileva la classe `.locked` su tileCasella/tileIa/tileReport/modoBig) con breve spiegazione. OK → **modalità
>   aiuto** ON. Un intercettatore click in **cattura** (`document.addEventListener('click',…,true)`): 1° tocco su un
>   tasto mostra una **nuvoletta** (`#helpTip`, bordo rosso, freccia) e blocca l'azione; 2° tocco sullo stesso
>   tasto la esegue. Badge **"✕ aiuto"** (`#helpBadge`) in alto a destra per spegnere. Mappa testi `HELP` per
>   tasti gioco (Avvia/Rispondi/Cambia/Finisci), tile (data-go) e tasti Impostazioni. Tutto client-side in
>   regia-predizione.html, nessun endpoint nuovo.

> ### Aggiunte 13 ottobre 2026 (AGG 19 — ritocchi)
> - **Assistente di scena = NUMERO** nel modello piano (0 = non disponibile, -1 = illimitato, N = quota).
>   `predizione_piano_normalizza` accetta int (o bool vecchio: true→-1, false→0); `predizione_limiti_utente`
>   espone sia `assistente` (bool compat, true se ≠0) sia `assistente_num` (il numero). Editor con campo
>   numero + spunta "illimitato".
> - **Icone pannello 000** rifatte in SVG lineari `currentColor` (stile Regia), al posto delle emoji.
> - **Regia:** i sottotitoli degli slot foto (`.slot-txt .s`) ora vanno a capo (niente più testo tagliato).

> ### Aggiunte 13 ottobre 2026 (AGG 18 — listino modificabile + versioni piani + pannello 000)
> - **Listino modificabile (editor nel pannello 000).** I piani NON sono più solo nel codice:
>   `predizione_piani()`/`predizione_extra()`/`predizione_accesso_prezzo()` leggono i valori di
>   fabbrica (`predizione_piani_default`) con sopra le modifiche salvate in `_dati/listino.json`.
>   Endpoint `admin_listino` (lettura) e `admin_listino_salva` (scrittura) in stato.php. L'editor
>   cambia **prezzi e numeri** dei 4 piani (nomi fissi) + prezzo accesso; il sito/carrello si
>   aggiornano da soli (ordine.php `listino` usa i valori effettivi).
> - **Nuovi campi piano:** `mail_giorno` e `sessioni_giorno` (limiti "al giorno"). I default
>   ora rispecchiano il listino del sito (Quinte 1 sess/giorno+5/mese, 500 mail/mese(100/giorno), ecc.).
>   `predizione_piano_normalizza()` forza i tipi.
> - **Versioni dei piani + storico.** Ogni modifica a un piano **archivia la versione vecchia**
>   (es. "Sipario 01") in `listino.json['storico']` e alza `listino.json['versioni'][nome]`.
>   Endpoint `admin_piani_storico`. In Regia/pannello la versione è solo informativa; sul sito NON si vede.
> - **Snapshot per-utente (chi è già abbonato tiene il piano vecchio).** `predizione_applica_piano`
>   congela `rec['piano_dati']` (limiti) + `rec['piano_versione']`; `predizione_limiti_utente` usa lo
>   snapshot se presente. Al **rinnovo/upgrade** (nuovo `applica_piano`) lo snapshot si aggiorna al piano
>   corrente. Utenti vecchi senza snapshot leggono il piano corrente (retro-compatibile).
> - **Pannello 000 riorganizzato (admin.html riscritto).** Menù laterale richiudibile (desktop-first,
>   responsive): Nuovo utente · Ordini (in attesa default + processati richiudibili + ricerca per
>   tipo/nome/id) · Utenti (filtri: fase/piano/provenienza + ordinamento; legenda spento vs terminato) ·
>   Listino · Storico piani · Password d'accesso · Operazioni. Scheda utente: anagrafica CON dentro la
>   casella Gmail e il cambio password; piano con **scadenza** e **3 righe crediti separate** (mail/foto/
>   sessioni); **cronologia acquisti** (endpoint `admin_acquisti` da vendite.csv). admin_utenti/admin_scheda
>   espongono `fonte` e `piano_versione`.
> - **B2 (al mese + giorno):** acquista.html mostra dettagli piano e **tabella di confronto**; `config.php`
>   `predizione_uso_mese/incr` tiene `uso['mail_oggi']` (contatore giornaliero); la Regia mostra
>   "Sessioni del mese" e un riepilogo "Al giorno".

> ### Aggiunte 12 ottobre 2026 (AGG 17 — lotto grande)
> - **Password d'accesso con provenienza:** l'admin crea password etichettate (`_dati/accessi.json`,
>   azioni `admin_accesso_*`, campo `pw_accesso` NON `password`); sul sito la verifica è `verifica_accesso`
>   in ordine.php; `crea` salva `fonte` (etichetta) nell'anagrafica. Base `aaa` = "diretto".
> - **Ricarica limiti nel giorno dell'abbonamento:** `stato['giorno_rinnovo']` (impostato all'attivazione),
>   `predizione_periodo_uso()`; `uso.reset_ts` mostrato nei Limiti.
> - **Carrello acquisto:** `sito/acquista.html` (nuovo) + endpoint `listino`; `crea` accetta `extra` (JSON) e
>   `utente_esistente` (upgrade/extra senza accesso 25€); `attiva_ordine` applica piano+extra. Il tasto
>   "Acquista crediti" della Regia apre `acquista.html?u=<utente>`.
> - **Report attivabile a parte:** `report_extra` nel record (admin_report); vince sul piano. Voce Report in
>   Regia grigia se non abilitata.
> - **Regia riorganizzata:** "La tua casella" (config) spostata dentro **Il mio account** (con i dati utente);
>   la tile ex-casella è ora **Invia mail** (invio a mano): premere Invia = come Avvia + interruttore
>   "continua a rispondere dopo l'invio". Tasto **Rispondi mail** sempre visibile con la logica oro.
>   Sottotitolo "Il tuo piano è …". Via la parola "robottino" dai testi visibili.
> - **Bug risolti:** testo slot a blocco (niente sovrapposizioni); anteprima a tutto schermo riusa
>   l'immagine della miniatura; Foto base IA azzerata a Avvia/Finisci. Invio SMTP con ripiego SSL 465.
> - **Admin:** scheda con provenienza + interruttore Report; sezione "Password d'accesso".

---

## 1. Cos'è

Uno **strumento da palco per un effetto di mentalismo**. Durante lo spettacolo gli
spettatori scrivono una mail al performer; il sistema risponde in automatico con una
mail che contiene una **foto**. Durante il gioco la foto è una **neutra di attesa (A)**;
quando il performer preme **"Cambia"**, la foto diventa la **rivelazione (B)**.

**Quattro fasi / tre tasti.** Spento (prima di "Avvia": non risponde) → **Avvia** (mostra A,
risponde) → **Cambia** (mostra B, risponde ancora) → **Finisci** (chiude: smette di rispondere,
la B resta congelata per sempre). Per il prossimo show si preme di nuovo "Avvia" (nuova sessione).
(In `stato.php` esiste ancora l'azione `azzera` per annullare una sessione avviata per sbaglio,
ma non c'è più un tasto nel pannello.)

L'illusione sta nel fatto che la foto sembra "dentro" una mail già ricevuta, mentre
in realtà viene ricostruita a ogni apertura da un server. Lo spettatore non immagina
che una foto nel corpo di una mail possa cambiare dopo l'invio.

**A sessioni.** Ogni show è una **sessione** con la sua rivelazione. Le foto cambiano
di sessione in sessione, ma ogni sessione conserva le sue: **chi ha ricevuto la
rivelazione di una sessione continua a vederla per sempre**, anche quando ne partono
altre con foto diverse. Per questo ogni mail porta il numero di sessione nell'indirizzo
dell'immagine (`image.php?s=NUMERO&id=…`).

**Natura del progetto:** è uno strumento di intrattenimento da palco, con un pubblico
consenziente. NON deve imitare marchi reali, NON raccoglie credenziali, NON inganna
terzi al di fuori del gioco. Vedi §6 (Vincoli etici) — sono vincoli di progetto, non
opzionali.

---

## 2. Come funziona (architettura)

Dominio e hosting: **abraka.it** su Tophost (piano Topweb, PHP). Il pannello e il
backend PHP sono costruiti e collaudati; restano da fare invio e autorisponditore.

```
   Spettatore                 Sistema del performer
  ┌──────────┐   mail in    ┌───────────────────────────┐
  │  scrive  │ ───────────► │  Casella (abraka.it)       │
  │  la mail │              │            │               │
  └──────────┘              │            ▼               │
       ▲                    │   Autorisponditore  [TODO] │
       │  risposta con      │            │               │
       │  <img> remota      │            ▼               │
       │                    │   Servizio invio (SES/Brevo)
       │  apre la mail      └───────────────────────────┘
       │  ─── GET image.php?id=… ───►  image.php  [PRONTO]
       │                                    ▲
       │  ◄─── Foto A / B / neutra ──────────┘
       │        (decisa dallo STATO del gioco)
                                            ▲
   Performer  ── Avvia/Finisci/Carica ──► stato.php + carica.php  [PRONTI]
   (pannello)                                (comandati dal pannello, con password)
```

Lo **scambio A→B è comandato dal performer** (tasto **"Cambia"**), con un **orario di
sicurezza** facoltativo che lo fa scattare da solo. "Finisci" chiude e ferma le risposte.
Le mail vengono elaborate solo mentre il gioco è attivo (fasi avviato/cambiato).

1. **Pannello di regia** (`pannello/regia-predizione.html`) — l'app del performer.
   *Costruito e collegato al backend; installabile sul telefono (PWA).*
2. **Backend dell'effetto** (`server/image.php` + `stato.php` + `carica.php` + `config.php`).
   *Scritto e collaudato in locale; da caricare su abraka.it.*
3. **Servizio d'invio** (Amazon SES o Brevo) — spedisce le ~500 risposte.
   *Da attivare/acquistare.*
4. **Autorisponditore** — collega "mail arrivata → risposta con foto".
   *Da costruire.*

---

## 3. I file

```
predizione/
├─ CLAUDE.md                       ← questo documento
├─ pannello/
│   ├─ regia-predizione.html       ← pannello del performer (collegato al backend, PWA)
│   ├─ manifest.json               ← installazione come app sul telefono
│   ├─ service-worker.js           ← installazione + apertura offline
│   └─ icons/                      ← icone dell'app (192, 512, apple-touch)
├─ server/
│   ├─ config.php                  ← impostazioni: PASSWORD, fuso orario, cartella dati
│   ├─ image.php                   ← immagine dinamica (il cuore dell'effetto)
│   ├─ stato.php                   ← comandi Avvia/Finisci/Azzera/Forza (protetti)
│   ├─ carica.php                  ← upload delle foto dal pannello (protetto)
│   ├─ neutro.png                  ← Foto A neutra PREDEFINITA (usata se non se ne carica una)
│   ├─ _dati/                      ← [creata sul server] foto, stato.json, aperture.csv
│   └─ ISTRUZIONI.txt              ← come caricare i file su abraka.it
└─ guide/
    ├─ guida-acquisti.html         ← cosa comprare (dominio, hosting, invio) a confronto
    ├─ licenza-uso.html            ← LICENZA D'USO (contratto con il prestigiatore), pagina web — BOZZA v1.0
    ├─ licenza-uso.txt             ← stessa licenza in testo semplice, da allegare alla mail di benvenuto
    └─ prezzi-predizione.html      ← listino (da riscrivere a "livelli di licenza", ROADMAP §8.4 b)
```

### `pannello/regia-predizione.html`
App mobile in verticale, singolo file HTML (CSS e JS inline). **Installabile** sul
telefono (PWA: manifest + service worker + icone). Due schermate: **Regia** (aperture
registrate, stato/fase del gioco, tasti **Avvia / Cambia / Finisci**) e **Impostazioni** (ingranaggio:
password, interruttore **Autorisponditore** ON/OFF, carico Foto A neutra / rivelazione,
orario di sicurezza, test Forza A/B/Auto).
Mostra il numero di **sessione** corrente; "Avvia" richiede Foto A + rivelazione caricate.

**Stato attuale:** **collegato al backend e collaudato** (test end-to-end contro i file
PHP, con Chromium). Chiama `stato.php` e `carica.php` sul server (via `../predizione/`),
si aggiorna ogni 4 secondi e mostra la fase reale. La password è salvata solo sul
dispositivo (localStorage). Il caricamento foto ora **persiste** (le salva il server).
Da collegare, in futuro, il contatore alle mail reali (oggi conta le aperture del log).

### `server/config.php`
Impostazioni condivise. **L'unica cosa da cambiare a mano è la PASSWORD**
(`PREDIZIONE_PASSWORD`), la stessa che si inserisce nel pannello. Contiene fuso orario,
percorso della cartella dati protetta `_dati/`, gli helper per leggere/scrivere lo stato
e il conteggio aperture. Anche `ABILITA_LOG` (cattura on/off) sta qui.

### `server/stato.php`
Riceve i comandi dal pannello (tutti tranne la sola lettura richiedono la password):
`avvia` (apre una NUOVA sessione: congela Foto A + rivelazione in file dedicati, fase=avviato,
orario di sicurezza opzionale), `cambia` (fase→cambiato: mostra B, gioco ancora attivo),
`finisci` (fase→terminato: chiude, la B resta congelata), `azzera` (annulla la sessione solo
se non ha ancora rivelato, fase=avviato), `forza` (A/B/OFF test), `autorisponditore` (interruttore
ON/OFF della risposta automatica, letto dalla Lambda a ogni giro), `invio` (modo di invio:
`smtp` casella per eventi piccoli / `ses` Amazon per eventi grandi), `messaggio` (oggetto+testo
personalizzati della mail), `stato` (lettura per il polling — espone anche `aperture_a`/`aperture_b`,
il conteggio per foto vista della sessione corrente).
Fasi: **spento → avviato → cambiato → terminato**. Lo stato vive in `_dati/stato.json`.

### `server/carica.php`
Riceve dal pannello (con password) e valida l'immagine (JPG/PNG/GIF/WEBP):
`slot=A` → **Foto A neutra** persistente (`_dati/foto_a.*`, riusata ogni sessione);
`slot=B` → **rivelazione** in attesa (`_dati/rivelazione_pronta.*`), consumata al prossimo `avvia`.
Se non si carica nessuna Foto A, `avvia` usa la **neutra predefinita `neutro.png`**
(nella cartella degli script): così basta caricare la rivelazione. Il performer può
sovrascrivere la neutra caricandone una propria (slot=A) quando vuole.

### `server/image.php`
Il motore dell'effetto. La mail chiede `image.php?s=NUMERO&id=…`; lo script guarda la
**sessione** di quella persona e restituisce:
- **sessione assente / gioco spento** → immagine **neutra** (pixel trasparente).
- **fase avviato** → **Foto A** (o rivelazione se è scattato l'orario di sicurezza).
- **fase cambiato o terminato** → la **rivelazione (B) di quella sessione** (congelata per sempre).
- **Forza A/B** (test): mostra A/B della sessione di riferimento, vince su tutto.
Ogni sessione ha i suoi file (`sess_N_before.*`, `sess_N_after.*`): mai sovrascritti,
così le rivelazioni vecchie restano congelate. Header **anti-cache** sempre. Log in
`_dati/aperture.csv` (data/ora, sessione, id, foto, IP, dispositivo; `ABILITA_LOG`).

Non c'è niente da configurare in `image.php`: tutte le impostazioni stanno in `config.php`.
Vedi `server/ISTRUZIONI.txt`.

---

## 4. Decisioni prese (e perché)

- **Immagine remota, non allegato.** La foto è un `<img src="…/image.php">`: viene
  scaricata a ogni apertura. È ciò che permette lo scambio A→B ed è tecnologia standard
  (stessa dei pixel di tracciamento). Non appesantisce la mail e non costa traffico SES.

- **Scambio comandato dal performer (non a orario fisso).** Lo decide il pulsante
  "Finisci gioco". Motivo: dal vivo il momento giusto lo conosce solo il performer.
  L'orario resta come **rete di sicurezza**: se non premi, lo scambio scatta lo stesso.

- **Le mail contano solo a gioco avviato.** A gioco spento image.php mostra il neutro e
  (in futuro) l'autorisponditore non deve rispondere: niente parte prima di "Avvia".

- **Foto per-sessione, congelate per sempre.** La Foto A neutra è persistente e riusata;
  la rivelazione è diversa a ogni sessione. Ogni "Avvia" crea una sessione numerata che
  salva le proprie foto in file dedicati (`sess_N_*`), mai sovrascritti. Così chi ha
  ricevuto una rivelazione continua a vederla anche dopo altre sessioni. Requisito del
  performer: la mail deve portare `?s=NUMERO` per legare il destinatario alla sua sessione.

- **Scambio deciso al momento dell'apertura**, non alla spedizione. Ogni spettatore
  resta con la versione vista alla *sua* prima apertura (per via della cache — vedi sotto).

- **Header anti-cache** in `image.php`. Senza, il client congelerebbe subito la prima
  foto e lo scambio non avverrebbe.

- **`?id=` per destinatario.** Doppio scopo: aggirare parte della cache condivisa e
  registrare chi apre/quando (funzione "cattura" del pannello).

- **Pannello protetto da password.** Il pannello può caricare foto e cambiare lo stato
  via internet, quindi va protetto: la password sta in `config.php` (server) e il
  pannello la invia a ogni comando. È il pannello del performer, non finge nessun
  servizio reale. La cartella dati `_dati/` è blindata con un `.htaccess`.

- **Stato su file, nessun database.** Lo stato del gioco è un piccolo `stato.json`, le
  foto e il log stanno in `_dati/`. Massima semplicità di deploy su hosting economici.

- **Pannello e backend separati ma stesso dominio.** Il pannello (in `/pannello/`) chiama
  gli script PHP (in `/predizione/`) sullo stesso dominio `abraka.it`: nessun problema di
  permessi cross-origin, e la foto e la mail restano coerenti sullo stesso dominio.

- **Invio via servizio dedicato (SES/Brevo), non dalla casella normale.** 500 mail in
  raffica da una Gmail/casella normale = blocco + spam. Un ESP con dominio autenticato
  (SPF/DKIM/DMARC) è l'unico modo affidabile. SES ≈ costo nullo ma più tecnico; Brevo
  più semplice ma il gratis è 300/giorno (per 500 serve lo Starter ~9 €/mese).

- **Hosting Tophost (piano Topweb) su dominio abraka.it.** Scelto per prezzo (~19 €/anno,
  dominio incluso) e per il PHP incluso, indispensabile all'effetto. Il piano "Topname"
  era escluso perché *senza* PHP. Aruba/Netsons erano alternative valide equivalenti.

- **Dominio onesto, mai look-alike di marchi.** Scelta tecnica *e* etica: un dominio che
  imita un marchio (es. "googie") viene messo in blacklist dai filtri e non è ammissibile.

---

## 5. Limiti noti

- **Cache delle immagini (Gmail e Apple Mail).** Sia Gmail sia Apple Mail **scaricano la
  foto una volta e ne tengono una copia**, poi non la riscaricano più. Conseguenza: su una
  mail **già vista** lo scambio A→B **non si vede** (resta la A congelata). Gmail passa da
  un suo proxy (`GoogleImageProxy`, nel log appare come "Gmail (anteprima)") che fotocopia
  l'immagine; Apple Mail con "Protezione privacy" la pre-scarica all'arrivo. Nessun trucco
  lo aggira al 100%. **Regola pratica che funziona:** lo spettatore deve aprire la mail
  **per la PRIMA volta DOPO** aver premuto "Cambia" (riveli, poi "adesso aprite" → vede la
  B). NON funziona il "guarda la foto che cambia dal vivo" mentre la mail è già aperta.
  (Verificato in prova il 04/10/2026.)

- **Il server non va mai spento.** La foto resta visibile agli spettatori solo finché
  `image.php` e le immagini restano online. Spegnere = immagini rotte per chi riscarica.

- **Usare sempre l'indirizzo con `www`.** Il sito canonico è `www.abraka.it`: chiamare
  `abraka.it` (senza www) provoca un redirect verso www e, in quel redirect, le richieste
  **POST perdono il corpo** (le GET no). L'autorisponditore su AWS deve avere quindi
  `STATO_URL` e `IMAGE_URL_BASE` con `www`, altrimenti i conteggi "Mail ricevute/inviate" e
  il log mittenti del report non vengono mai scritti (bug trovato e risolto il 04/10/2026).

- **Sandbox SES.** All'inizio SES limita a ~200 mail/giorno verso indirizzi verificati.
  Va richiesto il passaggio a "production" e l'aumento del limite **giorni prima** dello show.

---

## 6. Vincoli etici (non negoziabili)

Questo è uno strumento da palco per un pubblico consenziente. Restano validi:
- Dominio **proprio e onesto**, che non imita nessun marchio o organizzazione reale.
- Nessuna schermata che finge di essere un servizio reale, nessuna raccolta di credenziali.
- La "cattura" registra aperture per uso da palco, non è sorveglianza di terzi ignari.

Se un'evoluzione futura spingesse verso l'inganno di terzi fuori dal gioco (finte
identità, finte prove, imitazione di brand), va fermata: esce dall'ambito di questo progetto.

---

## 7. Cosa manca — prossimi passi

- [x] Acquistare **dominio + hosting** → fatto: abraka.it su Tophost (Topweb, PHP).
- [x] **Pannello installabile** sul telefono (PWA).
- [x] **Backend a tre fasi/sessioni** (image/stato/carica/config) + pannello collegato.
- [x] **Deploy dal vivo:** i 5 file (4 PHP + pannello) sono in `abraka.it/predizione/`,
      password impostata in `config.php`, effetto provato online (neutro → A → rivelazione).
- [x] **HTTPS** attivato su Tophost (propagazione ~3-4h; poi mettere "Redirect automatico: On").
- [x] **Amazon SES** (regione Europa/Irlanda): account creato, **dominio `abraka.it` VERIFICATO**
      (DKIM 3x CNAME + DMARC su DNS Tophost), Gmail `appisolata@gmail.com` verificata come test.
      Ancora in **sandbox** (200 mail/giorno, solo verso indirizzi verificati).
- [x] **Casella del gioco `io@abraka.it`**: invia e riceve. Nota: andava agganciata alla
      mailbox principale `abraka.it` (all'inizio puntava a una mailbox separata senza spazio).
- [x] **Autorisponditore — CODICE PRONTO** (`autorisponditore/index.mjs`): AWS Lambda (Node.js)
      che ogni minuto legge la casella `io@abraka.it` via **IMAP** (mail restano su Tophost,
      leggibili) e, a gioco avviato, risponde via **SES** con `image.php?s=SESSIONE&id=…`.
      Impostazioni via variabili d'ambiente. Pacchetto zip pronto da caricare.
      Ha un **interruttore ON/OFF** letto da `stato.php` (`autorisponditore`): a OFF la Lambda
      si sveglia ma esce subito senza leggere la posta né rispondere — comandato dal pannello
      (Impostazioni), così non lavora quando non c'è spettacolo senza toccare AWS.
      **Modo di invio** a 3 scelte dal pannello (`invio_modo` = `casella`|`brevo`|`ses`;
      il vecchio `smtp` vale come `casella`): **casella** = SMTP Tophost via nodemailer
      (eventi piccoli, gratis); **brevo** = SMTP Brevo (eventi grandi); **ses** = Amazon
      (richiede la produzione). TUTTI E TRE COLLAUDATI OK. **Oggetto e testo** della mail
      arrivano dal pannello (`mail_oggetto`/`mail_testo`); i valori env sono solo di riserva.
      Variabili: casella `SMTP_HOST`=`mail.tophost.it` `SMTP_PORT`=`587` (STARTTLS, utente/pass
      da IMAP_* se vuoti); Brevo `BREVO_HOST`=`smtp-relay.brevo.com` `BREVO_PORT`=`587`
      `BREVO_USER`=`<login @smtp-brevo.com>` `BREVO_PASS`=`<chiave SMTP>`.
      Anti-doppione: la mail viene marcata \Seen PRIMA di rispondere (una mail = una risposta).
- [ ] **Deploy dell'autorisponditore su AWS**: creare la Lambda, caricare lo zip, impostare
      le variabili (IMAP_USER/PASS ecc.), timeout 2 min, policy `AmazonSESFullAccess`, trigger
      EventBridge `rate(1 minute)`. **Guida completa: `guide/autorisponditore.md`.**
- [~] **Invio grandi volumi: MAILGUN** (sostituisce Brevo, che è stato abbandonato perché non
      funzionava). È il canale "Palco". UN solo account Mailgun dell'autore (paga lui), **multi-dominio**:
      si aggiunge un dominio per ogni cliente, il cliente incolla 3-4 righe DNS nel proprio dominio.
      SMTP `smtp.mailgun.org:587` (UE: `smtp.eu.mailgun.org`), login tipo `postmaster@<dominio-cliente>`,
      password SMTP in `MAILGUN_PASS`. **Palco richiede un dominio: NON va con Gmail** (chi ha solo
      Gmail usa il modo "Casella"). Guida illustrata con le istruzioni per il cliente: `guide/palco-aws.html`.
      _(Storico: Brevo era stato collaudato — DKIM brevo1/brevo2 + DMARC `rua` — ma è stato dismesso.)_
- [ ] **Uscire dalla sandbox SES** ("Richiedi accesso alla produzione"): Amazon ha rifiutato
      2 volte. Opzionale ora che Mailgun copre gli eventi grandi.
- [ ] Collegare il **contatore** del pannello alle mail reali (oggi mostra le aperture del log).
- [ ] Prova completa in piccolo (2–3 mail) prima dello show, su Gmail e su iPhone.

---

## 8. Modello di vendita: licenza d'uso come AUTORE (dal 10 ottobre 2026)

**Cosa si vende.** Non un servizio ma una **licenza d'uso dell'opera PREDIZIONE** concessa dall'autore
(diritto d'autore sul software, L. 633/1941 art. 2 n. 8 e 64-bis). Conseguenze che il codice deve rispettare:
- il cliente compra **livelli di licenza** (Accesso 5 anni + Foyer/Quinte/Sipario/Palco mensili o annuali)
  con limiti d'uso; gli extra sono **"estensioni della licenza"** (più invii, più sessioni, utenza Assistente,
  funzione Report). **Mai** chiamarli "assistenza" o "servizio", in nessuna pagina, mail o ricevuta;
- niente IVA e niente fattura: si emette una **ricevuta** numerata per anno (bollo 2 € se > 77,47 €);
- **inquadramento fiscale DA DECIDERE col commercialista (aggiornato 16 ott): l'autore HA GIÀ una
  partita IVA** (per un'altra attività). Due vie aperte: (A) **integrare** questa attività nella partita
  IVA esistente (eventuale codice ATECO software/diritto d'autore, trattamento IVA, coefficiente se
  forfettario) — spesso più semplice; (B) tenerla **fuori** come reddito diverso/occasionale (quadro RL,
  ricevuta). Piano C se nessuna conviene: **Paddle** (venditore ufficiale che fattura lui). Domande pronte:
  `guide/domande-commercialista.html`. (La vecchia nota "l'autore non ha partita IVA" è superata.)

**Il contratto.** `guide/licenza-uso.html` (+ `.txt`), bozza v1.0: 12 articoli, foro di Lecce, i vincoli
etici del §6 sono obblighi del cliente (art. 5), foto B conservate per sempre e foto A cancellabili dopo
60 gg (art. 7), privacy spettatori con il prestigiatore titolare (art. 6). Al checkout si accetta con una
sola spunta: *"Ho preso visione della Licenza d'uso di PREDIZIONE (versione 1.0), compresa l'attivazione
immediata di cui agli articoli 4 e 9, e la accetto."* Fornitori citati solo come "servizi gestiti da terzi".
La pagina ha `noindex` e va linkata solo dal flusso d'ordine. **Contatto nella licenza, nelle mail d'ordine e nelle
ricevute: `supporto@abraka.it`** (casella CREATA su Tophost — confermato 16 ott — separata da `io@abraka.it` che resta la casella del gioco; esiste anche `accesso@abraka.it` per le richieste d'accesso).

**Dati personali dell'autore (regola ferrea).** Il repo è PUBBLICO: nei file solo nome, città e mail.
Indirizzo completo e codice fiscale stanno sul server in `server/_dati/autore.json` (non versionato) e
compaiono solo nella conferma d'ordine e nella ricevuta PDF. Non scriverli mai in codice, guide o ROADMAP.

**Incasso.** Tre canali in fase 1: bonifico (0 €), Satispay con richiesta manuale dall'app (0 € sotto 10 €),
PayPal via link PayPal.Me (anche per l'estero). **Listino unico + sconto "pagamento diretto"** per
Satispay/bonifico; **mai** un sovrapprezzo per PayPal (vietato). Extra sotto 10 € solo Satispay/bonifico.
Fase 2: API Satispay, Stripe, webhook PayPal, tutti verso la stessa `attiva_ordine()`.

**Attivazione.** `ordine.php` crea `PRD-NNNN` (in attesa) → pagato (clic in admin o callback) →
`attiva_ordine()`: utente in `utenti.json` + piano/limiti + ricevuta PDF + mail di benvenuto + riga in
`_dati/vendite.csv`. **La Lambda non si tocca**: prende da sola gli utenti attivi via `admin_caselle`.
Dettagli, tabelle prezzi/sconti e ordine dei lavori: **ROADMAP §8** (8.1 regole, 8.2 iter, 8.3 chi fa cosa,
8.4 da costruire: b listino → c ordine.php → d admin/ricevuta → e piani e limiti → f fase 2).

### Credenziali/risorse in uso (per riprendere)
- AWS: account root con la Gmail; regione **eu-west-1 (Irlanda)** per SES.
- Tophost: pannello `cp.tophost.it`; casella gioco **io@abraka.it** su mailbox `abraka.it`.
- Pannello performer: `https://abraka.it/predizione/regia-predizione.html` (password in config.php).

### Snippet dell'immagine nella mail (per l'autorisponditore)
```html
<img src="https://abraka.it/predizione/image.php?s=NUMEROSESSIONE&id=SPETTATORE1"
     width="500" style="display:block;max-width:100%;border:0" alt="">
```
`s` = numero della sessione in corso (lo mostra il pannello); `id` diverso per ogni
destinatario. L'autorisponditore riempirà entrambi in automatico, e risponderà solo a
gioco avviato (fase ≠ spento).
