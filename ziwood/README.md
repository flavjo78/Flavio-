# Sito ZiWood — ziwood.it

Nuovo sito della Falegnameria ZiWood (Martina Franca). Sito **statico**: HTML, CSS e
JavaScript senza librerie esterne, più un solo script PHP per il modulo contatti.
Si carica via FTP sull'hosting Kaliweb così com'è.

## Cosa c'è

```
ziwood/
├─ index.html            home
├─ azienda.html          storia, fondatore, numeri, showroom, missione
├─ settori.html          4 settori (residenziale, retail, nautica, Ho.Re.Ca.) + servizi
├─ realizzazioni.html    galleria per progetto, con filtri e lightbox
├─ contatti.html         dati, modulo, mappa
├─ privacy.html          informativa
├─ invia.php             riceve il modulo e manda la mail a info@ziwood.it
├─ assets/style.css      stile (palette oliva/crema, font Cormorant Garamond + Montserrat)
├─ assets/main.js        menu, slideshow, apparizioni allo scroll, contatori, galleria, modulo
└─ img/                  foto (logo, foto dal company profile, lavori/ = gallerie del vecchio sito)
```

## Pubblicare su Kaliweb

1. Dal pannello Kaliweb recupera i dati FTP (host, utente, password) dello spazio di `ziwood.it`.
2. Con FileZilla (o simile) carica **tutto il contenuto** di questa cartella nella cartella
   pubblica del sito (di solito `public_html/`, `httpdocs/` o `www/`).
3. Apri `https://www.ziwood.it` e controlla le cinque pagine.
4. Prova il modulo in `contatti.html`: deve arrivare una mail a `info@ziwood.it`.
   - Se non arriva, in `invia.php` controlla `MITTENTE`: deve essere un indirizzo **sul dominio**
     (es. `sito@ziwood.it`, anche se la casella non esiste). Alcuni hosting rifiutano `mail()`
     con mittenti esterni.
   - Se Kaliweb non abilita la funzione `mail()`, va sostituita con l'invio SMTP della casella
     (stesso approccio dell'autorisponditore di Predizione): chiedi e lo adattiamo.
5. Attiva l'HTTPS dal pannello Kaliweb (Let's Encrypt) e il redirect automatico.

## Modificare i contenuti

- **Testi**: sono direttamente nei file `.html`, in italiano, sezione per sezione.
- **Foto**: in `img/`. Per aggiungere un progetto alla galleria, in `realizzazioni.html`
  copia un blocco `<div class="lavoro …">`, cambia l'immagine di copertina e l'elenco in
  `data-foto` (tutte le foto del progetto, che si sfogliano nel lightbox).
- **Colori e font**: le variabili in cima a `assets/style.css` (`--oliva`, `--crema`, …).
- **Contatti/indirizzo**: compaiono in `contatti.html`, nel footer di ogni pagina e nel menu.

## Da fare / da chiedere al cliente

- **Logo in alta risoluzione** (SVG o PNG grande): quello attuale è l'unico recuperabile
  dal company profile (382×128 px) e su schermi retina è leggermente morbido.
- Foto Ho.Re.Ca. e nautica di realizzazioni proprie: oggi la sala colazioni è a bassa
  risoluzione e la cabina yacht arriva dal company profile.
- Verificare ragione sociale e P. IVA nel footer e nella privacy (presi dal sito attuale).
- Eventuale video per l'hero (il riferimento usa un video a tutto schermo: la struttura è pronta,
  basta sostituire lo slideshow con un `<video autoplay muted loop playsinline>`).
