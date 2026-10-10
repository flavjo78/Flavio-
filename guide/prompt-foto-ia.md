# PREDIZIONE — Ricettario dei prompt per le foto IA (Gemini / Nano Banana)

> Raccolta dei "prompt" (le richieste) che funzionano meglio per generare la
> rivelazione (Foto B) con Nano Banana 2.1 di Google AI Studio.
> Serve da memoria: qui si incollano le formule provate e il loro esito.
> NON ancora collegato all'app — materiale di prova.

Ultimo aggiornamento: 10 ottobre 2026.

---

## Impostazione di prova (AI Studio)
- Modello: **Nano Banana 2.1** (`gemini-nano-banana-2.1`).
- Progetto: **Predizione**; chiave: `predizione token` (prepagato 5 €, ricarica automatica OFF).
- Risoluzione: 1K · Output: Images & text · Thinking level: Medium.
- Costo osservato: foglio allegato ~259 token in input (≈ 0,00006 $); foto finita ~0,034 $.

---

## Prompt 1 — Scrivere a mano su un foglio bianco già fotografato (EDIT) ⭐ RICETTA VINCENTE
**Uso:** si allega la foto reale del foglio bianco tenuto in mano e si fa aggiungere la scritta.
**Stile ottenuto:** corsivo naturale a penna bic blu.
**Esito prova 10/10/2026 (Nano Banana 2.1):** OTTIMO. Scritta leggibilissima, corsivo
naturale con lievi imperfezioni credibili, posizionata nello spazio vuoto senza coprire
mani/viso, resto della foto perfettamente invariato. Promosso: formula da riusare nell'app.

```
Modifica l'immagine mantenendo tutto il resto completamente invariato. Scrivi sul
foglio bianco la frase 'asso di cuori' a penna bic blu, posizionando il testo negli
spazi vuoti senza sovrapporlo alle dita o alle mani. La calligrafia è un corsivo
naturale, spontaneo e informale, con le lettere arrotondate e collegate tra loro in
modo fluido. La scrittura è leggermente inclinata, con una pressione della penna
naturale e lievi imperfezioni tipiche di una scrittura a mano libera e veloce, senza
l'uso di righelli
```

- La frase tra apici (`'asso di cuori'`) è la parte da cambiare per ogni rivelazione.
- Esito: _(da compilare dopo la prova — leggibilità, naturalezza, difetti)_
```
```

---

## Prompt 2 — Versione avanzata (regole di naturalezza dettagliate) ⭐ MIGLIORE
**Uso:** come il Prompt 1 ma con regole minuziose sulla grafia → scrittura ancora più
naturale e integrata con la foto.
**Esito prova 10/10/2026 (Nano Banana 2.1):** grafia molto naturale e credibile.
**ATTENZIONE (da correggere nell'app):** usando `"[INSERISCI QUI LA TUA FRASE]"` con le
PARENTESI QUADRE, il modello ha disegnato anche le parentesi `[ ]` sul foglio.
→ Nell'app inserire la frase SENZA parentesi quadre (solo virgolette normali) e aggiungere
la riga di sicurezza: "Scrivi esclusivamente le parole della frase, senza virgolette,
parentesi o altri simboli."

```
Modifica l'immagine allegata mantenendo tutto il resto (posa, mano, dita, sfondo)
completamente invariato, senza aggiungere alcun oggetto come penne o altro. Scrivi sul
foglio bianco al centro, in un'unica riga orizzontale, la frase "LA_TUA_FRASE" (che deve
essere scritta una sola volta, senza alcuna ripetizione, lasciando del tutto visibili e
scoperte le dita che tengono la carta). Scrivi esclusivamente le parole della frase, senza
virgolette, parentesi o altri simboli.
La grafia deve essere in un corsivo quotidiano, veloce, informale e assolutamente
imperfetto (non scolastico o calligrafico), come un appunto frettoloso preso al volo.
Ecco le regole di scrittura per la naturalezza di ogni parola della frase:
1. La prima parola: scritta interamente in minuscolo (o con un'iniziale spontanea), in modo
casuale. Le lettere si collegano tra loro in modo rapido. Eventuali lettere doppie
consecutive o vicine (se presenti) devono essere visibilmente imperfette, asimmetriche e
disallineate l'una dall'altra, concludendo in modo frettoloso.
2. Le parole intermedie (se presenti): devono essere staccate dalle precedenti e scritte in
modo piccolo e rapido. Ciascuna parola si collega al proprio interno in modo sbrigativo, con
legature naturali ma imperfette tra le lettere. Le lettere con tratti ascendenti o
discendenti (come d, t, l, p, q, g, f) devono avere altezze irregolari e spontanee.
3. L'ultima parola: staccata dalle precedenti e interamente in minuscolo. Le lettere devono
susseguirsi in un corsivo rapido e un po' piatto, che tende a stringersi, rimpicciolirsi o
"scivolare" leggermente verso la fine della riga. L'ultima lettera (o eventuale puntino/segno
finale) deve essere un tocco rapido, naturale e leggermente decentrato.
Caratteristiche della penna e dell'ambiente:
- L'inchiostro della penna: comune penna a sfera blu (stile Bic) con inchiostro non uniforme.
Il tratto deve mostrare le naturali imperfezioni di una biro: non continuo o blu acceso
digitale, ma con tratti leggermente più sbiaditi, micro-interruzioni o piccoli accumuli di
inchiostro (grumi) tipici della scrittura a mano su carta.
- Naturalezza: nessun allineamento perfetto o uso di righe invisibili. La scritta deve
pendere o curvarsi leggermente in modo naturale.
- Integrazione: la scritta deve integrarsi con la stessa grana, luce, ombra e leggera
sfocatura (micro-mosso) dell'immagine originale, come se fosse parte originaria della foto.
```

- Sostituire `LA_TUA_FRASE` con la rivelazione (es. `asso di cuori`), SENZA parentesi quadre.

---

## Note per quando collegheremo l'IA all'app
- La parola/frase della rivelazione sarà il pezzo variabile inserito dal pannello.
- Valutare se partire sempre da una **foto base del foglio** (edit) oppure far
  disegnare tutto da zero (mano + foglio + scritta).
