# App Ferie — guida all'installazione (passo passo)

Cosa hai ricevuto:

| Cartella | A cosa serve | Dove va |
|---|---|---|
| `ferie/` | L'app per i dipendenti (pagina web + piccolo programma PHP) | sul NAS in `\\ServerNas\web\ziwood\ferie\` |
| `dashboard/APP.py` | La dashboard aggiornata alla **v1.04**, con la nuova scheda **🏖️ Ferie** | sul NAS in `\\ServerNas\web\ziwood\avvio\` (sostituisce il vecchio `APP.py`) |
| `nas/htaccess_avvio.txt` | Protezione della cartella dei dati | dentro `avvio` (vedi sezione 2) |
| `prova_pc/` | Prova completa sul tuo PC con dati finti (sezione 0) | resta qui |
| `tests/` | Prove automatiche (non servono per l'uso normale) | restano qui |

Come funziona, in breve: il dipendente chiede le ferie dal telefono → il NAS salva la richiesta in un piccolo file
(`avvio\richieste_ferie\`) → tu la vedi nella scheda **🏖️ Ferie** della dashboard e premi *Approva* o *Rifiuta*.
Se approvi, la dashboard segna le ferie sui giorni lavorativi (nella stessa scheda ✏️ Correzioni di sempre), quindi
tutti i tuoi conteggi restano coerenti. **L'app dei dipendenti non modifica mai le tue correzioni.**

---

## 0. Prima prova sul tuo PC (senza toccare il NAS)

Cartella `prova_pc/`: avvia tutto sul computer con **dati dimostrativi** (3 dipendenti finti, giorni e PIN già pronti).
Non legge e non scrive nulla sul NAS.

1. Serve **Python** (lo stesso della dashboard) e **PHP** (gratuito, si scarica una volta sola):
   - vai su <https://windows.php.net/download/>, scarica lo ZIP **"VS16 x64 Non Thread Safe"** e decomprimilo in
     `prova_pc\php\` (deve esistere `prova_pc\php\php.exe`). Se il file non c'è, `avvia_prova_pc.bat` te lo ricorda.
2. Fai doppio clic su **`prova_pc\avvia_prova_pc.bat`** (su Mac/Linux: `./avvia_prova_pc.sh`).
   Si aprono due finestre nere (lasciale aperte) e il browser.
3. **App ferie:** `http://localhost:8080/index.html` — dipendenti di prova: *Mario Rossi* PIN **1111**,
   *Laura Bianchi* PIN **2222**, *Giorgio Verdi* PIN **3333**.
4. **Dashboard:** `http://localhost:8501` → scheda **🏖️ Ferie**, password **0**.
5. **Da tablet o telefono** collegato allo stesso Wi-Fi del PC: `http://IP-DEL-PC:8080/index.html`
   (l'IP lo vedi con `ipconfig`, riga "Indirizzo IPv4"). Al primo avvio Windows chiede di autorizzare PHP nel firewall:
   consenti la **rete privata**.
6. Per ripartire da zero: `avvia_prova_pc.bat --azzera`.

> Il `.bat` per Windows non ho potuto provarlo su Windows (il resto sì, sulla versione Mac/Linux `.sh`, che fa le stesse cose).
> Se dà un errore, mandami il testo che vedi.

### Telefono in orizzontale e tablet
L'app si adatta da sola: **in orizzontale (telefono o tablet)** vedi tutto insieme — saldo e calendario a sinistra, le tue
richieste a destra; **in verticale** ha le tre pagine *Saldo / Calendario / Richieste* con i pulsanti in alto.
- **Android, app installata sulla Home:** si apre sempre in orizzontale (è impostato nell'app).
- **iPhone / iPad e browser normale:** Apple e i browser non permettono a un sito di bloccare la rotazione: basta girare il
  dispositivo. In verticale sul telefono compare un promemoria "ruota il telefono in orizzontale".
- Un **tablet** è la soluzione più comoda (schermo grande, disposizione sempre a due colonne).

## 1. Copia i file (5 minuti)

1. **Fai una copia di sicurezza** della cartella `avvio` (basta copiarla e chiamarla `avvio_backup`).
2. Copia la cartella **`ferie`** in `\\ServerNas\web\ziwood\` (accanto a `salva_presenze.php`).
3. Sostituisci `\\ServerNas\web\ziwood\avvio\APP.py` con quello nuovo di `dashboard\APP.py`.
   In alto nella dashboard deve comparire **v1.04**. Le tue impostazioni, gli orari e le correzioni restano come sono.
4. Riavvia la dashboard (`avvia_dashboard.bat`) su ogni PC dell'ufficio.

Se la cartella `avvio` non si trova in `..\avvio` rispetto a `ferie`, apri `ferie\config.php` e correggi la riga `DATI_DIR`.

## 2. Proteggi la cartella dei dati (IMPORTANTE, 2 minuti)

Il NAS pubblica sul web tutto ciò che sta dentro `web\`. Se non lo blocchi, **chiunque sia collegato al Wi-Fi può
leggere** `avvio\password_orari.txt` (la password è salvata in chiaro), i PIN (in forma cifrata) e le richieste di ferie.

1. Copia `nas\htaccess_avvio.txt` dentro `avvio` e rinominalo `.htaccess` (le istruzioni sono scritte nel file).
2. **Prova:** dal telefono apri `http://IP-DEL-NAS/ziwood/avvio/password_orari.txt`.
   Deve comparire **403 – Forbidden**. Se vedi la password, la protezione non funziona sul tuo NAS
   (succede se usa un web server diverso da Apache): dimmelo e troviamo un'alternativa
   (di solito basta spostare la cartella dati fuori da `web\` e cambiare `DATI_DIR`).

> ⚠️ **Nota su `upload.php`** (già presente sul tuo NAS): accetta qualsiasi file con qualsiasi nome. Se un estraneo
> caricasse un file `.php`, potrebbe farlo eseguire sul NAS. Finché il NAS è raggiungibile solo dal Wi-Fi
> aziendale il rischio è basso, ma se non ti serve più **eliminalo**; se ti serve, chiedimi di renderlo sicuro.

## 3. Prepara i dipendenti (dalla dashboard)

Apri la dashboard → scheda **🏖️ Ferie** (stessa password di *Orari Dipendenti*) → **👥 Saldi e PIN**. Per ogni dipendente:

1. **Anno, giorni spettanti, residuo dell'anno precedente** → *Salva*. (Ogni persona può avere giorni diversi.)
   *All'inizio di ogni anno* ricordati di cambiare l'anno e i giorni: finché non lo fai il dipendente vede
   "giorni non ancora impostati" e non può fare richieste.
2. **PIN**: scrivi tu un PIN di 4-8 cifre oppure premi *🎲 Genera un PIN casuale* e comunicalo alla persona.
   Il PIN non si può rileggere (è salvato cifrato): se lo dimentica se ne crea un altro.
3. *(Facoltativo)* l'**email** del dipendente, per avvisarlo quando approvi o rifiuti.

Nella scheda **📊 Riepilogo** vedi il saldo di tutti.

## 4. Provala dal telefono

1. Con il telefono collegato al **Wi-Fi aziendale**, apri `http://IP-DEL-NAS/ziwood/ferie/index.html`
   (l'indirizzo IP del NAS è lo stesso che usa già l'app di timbratura).
2. Scegli il nome, scrivi il PIN, entra. Premi **➕ Chiedi ferie** e **scegli i giorni direttamente sul calendario**:
   tocca un giorno, oppure **trascina il dito** per scegliere un periodo. I giorni scelti diventano **gialli** ("in attesa").
   Tocca di nuovo un giorno per toglierlo. Puoi cambiare mese con le frecce senza perdere la scelta.
   Sabati, domeniche, festivi e giorni già occupati non si possono scegliere; a destra vedi subito
   **quanti giorni** userai (festivi e giorni di riposo esclusi). Più periodi scelti insieme partono con una sola richiesta.
3. Nella dashboard, scheda **🏖️ Ferie → 📥 Richieste** compare la richiesta: *Approva* o *Rifiuta*
   (il rifiuto richiede un motivo, che il dipendente vede nell'app).
4. Torna sul telefono: lo stato è cambiato e il calendario mostra le ferie.

**Mezza giornata:** si può chiedere solo per **un giorno singolo** (mattina o pomeriggio); vale 0,5 giorni e
nella dashboard compare come **F½**.

### Metterla sulla schermata home
- **iPhone/iPad (Safari):** tasto *Condividi* → *Aggiungi alla schermata Home*.
- **Android (Chrome):** menu ⋮ → *Aggiungi a schermata Home* (o *Installa app*).

Nota onesta: l'indirizzo `http://` (senza il lucchetto) è normale dentro l'azienda, ma i browser
consentono l'installazione "completa" e l'uso senza rete solo con `https://`. Con `http://` l'app funziona
lo stesso (come collegamento sulla home, sempre con il Wi-Fi aziendale). Se in futuro vorrete usarla anche da casa
servirà `https://` e un accesso sicuro al NAS: è un passo a parte, da fare con calma.

## 5. Carta NFC

Il link della carta è: `http://IP-DEL-NAS/ziwood/ferie/index.html?carta=Mario%20Rossi`.
Nella dashboard (*Saldi e PIN → 💳 Carta NFC*) lo trovi già pronto per ogni dipendente (dopo aver scritto l'indirizzo
dell'app in *✉️ Notifiche email → Indirizzo dell'app ferie*).

- **Con la tua app Android del totem:** dopo aver letto il nome dalla carta, apri il link nel browser. Esempio (Kotlin):
  ```kotlin
  val url = "http://IP-DEL-NAS/ziwood/ferie/index.html?carta=" + Uri.encode(nome)
  startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
  ```
  Se vuoi la stessa carta anche per le ferie senza toccare l'app, puoi scrivere sulla carta **un secondo record**
  di tipo *URL* con il link (con l'app gratuita *NFC Tools*).
- **L'accesso da carta è una sessione breve:** non resta salvato sul telefono e si chiude da solo dopo 2 minuti
  senza toccare lo schermo (adatto anche a un totem condiviso). Basta riavvicinare la carta.
- **Sicurezza:** se la carta contiene solo il nome, chi conosce il nome di un collega può scrivere il link a mano.
  Per evitarlo, in dashboard premi *🔒 Crea nuovo codice per la carta* e scrivi sulla carta il link **con** il codice
  (`…?carta=Mario%20Rossi&c=CODICE`). In alternativa, in `ferie\config.php` metti `NFC_ACCETTA_SOLO_NOME` a `false`:
  le carte senza codice non entreranno più.
- Un nome scritto un po' diverso dall'anagrafica (maiuscole, spazi, refusi presenti in `alias_nomi.json`) viene riconosciuto lo stesso.

## 6. Notifiche email

Dashboard → **🏖️ Ferie → ✉️ Notifiche email**. Servono i dati SMTP di un account email dell'azienda (li trovi nel pannello del
tuo servizio di posta: server, porta, utente, password). Poi *✉️ Invia una mail di prova*.
- **All'ufficio** (indirizzi a scelta): quando arriva o viene annullata una richiesta. La invia l'app sul NAS.
- **Al dipendente** (se hai messo la sua email): quando approvi o rifiuti. La invia la dashboard.

Se la posta non funziona, **le richieste funzionano lo stesso**: la mail è solo un avviso in più.
La password della posta è salvata in `avvio\ferie_config.json` (per questo la cartella va protetta, sezione 2).

## 7. Cosa scrive chi (per non fare pasticci)

| File in `avvio\` | Lo scrive | Lo legge |
|---|---|---|
| `richieste_ferie\*.json` (uno per richiesta) | l'app (nuova richiesta o annullamento); la dashboard (esito) | entrambi |
| `richieste_ferie\_sistema\` (chiave di sicurezza, contatori PIN) | l'app | l'app |
| `ferie_saldi.json` (giorni, PIN cifrati, email, codici carta) | la dashboard | l'app |
| `ferie_config.json` (email) | la dashboard | l'app |
| `correzioni_timbrature.json` | **solo la dashboard** | l'app (per sapere le ferie già segnate) |

- **PIN sbagliato 5 volte** → quel nome è bloccato 10 minuti (`MAX_TENTATIVI` e `MINUTI_BLOCCO` in `config.php`).
- **Cambiare il PIN o il codice della carta** fa uscire il dipendente dai telefoni dove era già entrato.
- **Togliere ferie già approvate:** pagina ✏️ Correzioni (ogni giorno si può annullare), come sempre.
- Se il NAS mostra l'errore "Impossibile creare la cartella richieste_ferie": crea a mano `avvio\richieste_ferie` e dai
  permesso di **scrittura** all'utente del web server (su Synology: *http*).

## 8. Prove automatiche (facoltative)

```
php tests/test_ferie.php                 # calcolo giorni, saldo, PIN, richieste, calendario (88 prove)
python3 tests/test_dashboard_ferie.py    # dashboard + confronto con l'app: stessi giorni su 2 anni, PIN, email (20 prove)
```
Il secondo confronta l'app e la dashboard su oltre 700 periodi diversi (festivi, Pasqua e Pasquetta, 4 ottobre dal 2026,
chiusure aziendali, riposi diversi per persona) e verifica che contino **esattamente** gli stessi giorni.
Serve PHP ≥ 7.2 sul NAS (le prove sono state eseguite con PHP 8.4).

**Cosa non ho potuto provare:** l'invio email verso un server SMTP *reale* con cifratura SSL/TLS (provato solo
con un server finto senza cifratura) e il tuo NAS vero: se qualcosa non va alla prima prova, scrivimi il messaggio che vedi.
