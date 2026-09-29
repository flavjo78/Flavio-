# Dashboard Presenze – documentazione tecnica (v1.04)

Documento per chi deve estendere il sistema (es. una app mobile per le ferie).
Descrive architettura, file condivisi, formati dei dati e le regole di calcolo
già implementate in `dashboard/APP.py`.

---

## 1. Architettura attuale

```
 Telefoni/tablet con app Android di timbratura
        │  (HTTP POST)
        ▼
 NAS "ServerNas" – web server con PHP
   \\ServerNas\web\ziwood\salva_presenze.php   ← riceve le timbrature
   \\ServerNas\web\ziwood\presenze\*.csv        ← UN file CSV per ogni timbratura
   \\ServerNas\web\ziwood\avvio\                ← cartella della dashboard
        APP.py, avvia_dashboard.bat, prepara_avvio.py
        orari_lavoro.json, alias_nomi.json, impostazioni.json,
        correzioni_timbrature.json, password_orari.txt, config_cartella_log.txt
        │
        ▼
 Dashboard Streamlit (Python) eseguita su OGNI PC dell'ufficio
 (avvia_dashboard.bat → python -m streamlit run APP.py → http://localhost:8501)
```

Punti chiave:

* **Non c'è un database.** Tutto è in file sul NAS (CSV + JSON). I file JSON della
  cartella `avvio` sono condivisi: tutti i PC vedono gli stessi orari, correzioni,
  festivi, impostazioni.
* La dashboard **non modifica mai** i CSV delle timbrature. Le correzioni manuali
  (timbrature aggiunte/eliminate, malattia, ferie) stanno in
  `correzioni_timbrature.json` e vengono applicate "sopra" ai dati grezzi.
* Il NAS ha già un **web server con PHP** (lo usa l'app Android per inviare le
  timbrature a `salva_presenze.php`): è il posto naturale dove ospitare anche la
  nuova app ferie.

---

## 2. File e formati

### 2.1 Timbrature grezze – `presenze/*.csv`

Un file per ogni timbratura (nome file tipo `2026-09-23_14.30.00.csv`), separatore `;`:

```
sep=;
Data;Orario;Nome;Evento
23-09-2026;07:28:14;Mario Rossi ;ENTRA
```

* `Data` = GG-MM-AAAA, `Orario` = HH:MM:SS, `Evento` = `ENTRA` | `ESCE`
  (l'app a volte sbaglia il tipo: la dashboard lo corregge in base all'orario).
* Il `Nome` può avere spazi finali e refusi → vedi `alias_nomi.json`.

### 2.2 Orari dei dipendenti – `orari_lavoro.json`

È anche **l'anagrafica ufficiale**: solo i nomi presenti qui vengono conteggiati.

```json
"Mario Rossi": {
  "ingresso": "07:30", "uscita": "16:30",
  "pausa_inizio": "13:00", "pausa_fine": "13:30",
  "pausa_extra_sec": 1800,
  "per_day": {"5": "OFF", "6": "OFF"}
}
```

* `per_day`: chiave = giorno della settimana (0=lunedì … 6=domenica).
  `"OFF"` = giorno di riposo; un orario `"HH:MM"` = ingresso diverso quel giorno.
* Regola **giorno lavorativo per orario** (`giorno_lavorativo_per`):
  se `per_day[wd] == "OFF"` → no; se `per_day[wd]` è un orario → sì;
  altrimenti sì da lunedì a venerdì, no sabato e domenica.
* **Ore previste al giorno** (`ore_previste_secondi`):
  (uscita − ingresso) − (pausa_fine − pausa_inizio) − pausa_extra_sec.
  Esempio 07:30–16:30, pausa 13:00–13:30, extra 30 min → 8 ore.

### 2.3 Correzioni e giustificativi – `correzioni_timbrature.json`

```json
{
  "aggiunte":   [{"data": "2026-09-24", "nome": "Mario Rossi", "orario": "16:30:00",
                  "evento": "ESCE", "nota": "Uscita", "quando": "25/09/2026 07:35"}],
  "eliminate":  [{"data": "2026-09-23", "nome": "Laura Bianchi", "orario": "13:01:00",
                  "evento": "ESCE", "quando": "..."}],
  "giustificativi": {"2026-09-21|Mario Rossi": "F", "2026-09-18|Laura Bianchi": "M"}
}
```

* `giustificativi`: chiave `"AAAA-MM-GG|Nome"`, valore `"F"` = **ferie**,
  `"M"` = **malattia**. **È qui che oggi vengono registrate le ferie.**
* Oggi le ferie si inseriscono dalla dashboard (scheda ✏️ Correzioni o Modalità
  correzione), anche per un periodo: si segnano **solo i giorni lavorativi** della
  persona (vedi 2.2 + festivi 2.4).
* Scrittura: il file viene riscritto per intero in modo atomico
  (`file.tmp` + `os.replace`).

### 2.4 Impostazioni e festivi – `impostazioni.json`

Chiavi rilevanti per le ferie:

```json
"festivi_nazionali": true,
"festivi_extra": {"2026-12-24": "Chiusura aziendale"},
"festivi_esclusi": []
```

**Festivi di un anno** = feste nazionali italiane (se `festivi_nazionali`) meno
`festivi_esclusi` più `festivi_extra`. Feste nazionali:
1/1, 6/1, Pasqua, Lunedì dell'Angelo (Pasqua+1), 25/4, 1/5, 2/6, 15/8, 1/11, 8/12,
25/12, 26/12 e, **dal 2026, il 4 ottobre** (San Francesco).
Pasqua: algoritmo gregoriano anonimo (funzione `_pasqua` in APP.py).

**Giorno lavorativo effettivo** (`lavorativo_il`) =
giorno lavorativo per orario **e** non festivo.

### 2.5 Altri file

* `alias_nomi.json`: `{"nome scritto male": "nome corretto" | "🚫 Ignora (scarta queste timbrature)"}`.
* `password_orari.txt`: password (in chiaro) dell'area amministratore della dashboard.
* `config_cartella_log.txt`: percorso della cartella `presenze` (es. `\\ServerNas\web\ziwood\presenze`).

---

## 3. Regole di calcolo presenze (riassunto)

* Timbrature del giorno ordinate e abbinate ai 4 momenti previsti
  (ingresso → inizio pausa → fine pausa → uscita) con programmazione dinamica che
  rispetta l'ordine (`_abbina_timbrature_a_momenti`).
* Doppia timbratura entro 60 s = scartata.
* Simbolo del giorno (`codice_presenza`): `M`/`F` se c'è un giustificativo,
  `P` presente, `½` se ore lavorate < 60% delle previste, `A` assente,
  `-` riposo/festivo/futuro.
* **Ferie e malattia non contano come assenze.**

---

## 4. Contratto di integrazione proposto per l'app Ferie

Obiettivo: l'app mobile è **separata** dalla dashboard ma usa gli stessi file sul NAS.

| Chi | Legge | Scrive |
|---|---|---|
| App ferie (PHP sul NAS) | `orari_lavoro.json`, `impostazioni.json`, `correzioni_timbrature.json`, `ferie_saldi.json` | **solo** `richieste_ferie/*.json` (un file per richiesta) |
| Dashboard (APP.py) | tutto | `ferie_saldi.json`, esito delle richieste, e quando approva → `giustificativi` = `"F"` in `correzioni_timbrature.json` |

Perché "un file per richiesta": come per le timbrature (un CSV per timbratura),
evita che due scritture contemporanee (app + dashboard, o due telefoni) si
sovrascrivano a vicenda. **L'app non deve mai scrivere `correzioni_timbrature.json`.**

### 4.1 Nuovo file `richieste_ferie/<id>.json` (proposta)

```json
{
  "id": "20261001-093012-mario-rossi-4f3a",
  "nome": "Mario Rossi",
  "tipo": "ferie",
  "dal": "2026-10-12", "al": "2026-10-16",
  "mezza_giornata": null,
  "giorni_lavorativi": 5,
  "nota": "Viaggio",
  "stato": "in_attesa",
  "creata_il": "2026-10-01T09:30:12",
  "deciso_il": null, "deciso_da": null, "motivo_rifiuto": null
}
```

`stato`: `in_attesa` → `approvata` | `rifiutata` | `annullata` (annullabile dal
dipendente solo finché è in attesa).

### 4.2 Nuovo file `ferie_saldi.json` (proposta, gestito dalla dashboard)

```json
{
  "Mario Rossi": {"anno": 2026, "giorni_spettanti": 26, "residuo_anno_precedente": 3,
                  "pin_hash": "<hash del PIN personale>"}
}
```

Calcolo mostrato al dipendente:

* **Godute** = giorni con giustificativo `F` nell'anno, con data ≤ oggi.
* **Programmate** = giorni con `F` nell'anno, con data > oggi (richieste già approvate:
  la dashboard, approvando, scrive subito `F` su tutti i giorni lavorativi del periodo).
* **In attesa** = giorni lavorativi delle richieste con stato `in_attesa`.
* **Disponibili** = giorni_spettanti + residuo_anno_precedente − godute − programmate.
* **Disponibili se approvate tutte** = disponibili − in attesa.

Mezza giornata di ferie: **realizzata** (v1.04) come giustificativo `"F½"` in
`correzioni_timbrature.json` (vale 0,5 giorni nel saldo). Si chiede solo per un giorno singolo
(`mezza_giornata`: `"mattina"` | `"pomeriggio"`, `giorni_lavorativi: 0.5`). Nella dashboard `F½` compare
come "Ferie ½ giornata" e nei riepiloghi conta 0,5.

Nota: i valori reali (26 giorni, maturazione mensile, ore vs giorni, permessi ROL)
dipendono dal contratto collettivo applicato: vanno confermati con l'azienda/consulente.


---

## 5. Come è stata realizzata l'app ferie (v1.04)

Vedi `GUIDA_INSTALLAZIONE.md` per l'installazione. Riassunto tecnico:

* **App dipendenti** (`ferie/`): PWA (`index.html`, `manifest.json`, `sw.js`) + API PHP (`api.php` → `azioni.php` →
  `lib.php`, `mail.php`), nessuna libreria esterna, nessun database. Cartella dati configurabile in `ferie/config.php`.
* **Regole dei giorni** riscritte in PHP (`lib.php`) identiche a quelle di APP.py (`_pasqua`, `festivi_anno`,
  `giorno_lavorativo_per`, `lavorativo_il`). `tests/test_dashboard_ferie.py` confronta le due implementazioni su >700 periodi.
* **Accesso**: nome + PIN (`pbkdf2$100000$sale$hash`, sha256, compatibile PHP/Python) oppure carta NFC
  (link `?carta=Nome[&c=codice]`, sessione breve). Token firmato HMAC (segreto in `richieste_ferie/_sistema/`),
  invalidato se cambiano PIN o codice carta. Blocco temporaneo dopo 5 PIN sbagliati. Il nome dell'utente viene
  SEMPRE dal token, mai dalla richiesta: ogni dipendente vede solo i propri dati.
* **`ferie_saldi.json`** (gestito dalla dashboard): oltre a `anno`, `giorni_spettanti`, `residuo_anno_precedente`,
  `pin_hash` ha `email` (facoltativa) e `carta_codice` (facoltativo). Il saldo è calcolato solo se `anno` coincide con l'anno corrente.
* **`ferie_config.json`** (gestito dalla dashboard, letto anche dall'app): impostazioni SMTP, destinatari degli avvisi
  all'ufficio, `url_app` per i link delle carte.
* **Regole di richiesta** (app): dal ≥ oggi, stesso anno solare, almeno un giorno lavorativo, nessuna sovrapposizione con
  richieste in attesa né con giorni già segnati F/F½/M, non oltre i giorni disponibili (`CONSENTI_OLTRE_SALDO` per permetterlo).
* **Approvazione** (dashboard, `approva_richiesta_ferie`): rilegge la richiesta, scrive `F`/`F½` sui giorni lavorativi
  (non sovrascrive `M`), poi segna `approvata`. Rifiuto con motivo obbligatorio.
* **Punto debole noto**: annullamento (app) e approvazione (dashboard) sullo stesso istante non sono coordinati da un
  blocco condiviso; la finestra è di millisecondi e la dashboard rilegge lo stato prima di scrivere.
