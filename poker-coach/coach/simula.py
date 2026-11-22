"""Simulatore del FILE DEL GIOCO: scrive due mani riga per riga, come farebbe un gioco vero.

    py -m coach.simula partita_prova.jsonl            (una riga ogni 1,5 secondi)
    py -m coach.simula partita_prova.jsonl --pausa 0  (tutto subito)

Serve a provare il coach senza gioco e come ESEMPIO del formato (vedi FORMATO-FILE.txt).
"""
import json
import time

NOMI = {1: "Hero", 2: "Marco", 3: "Luca", 5: "Anna"}


def _stato(mano, bottone, tocca, board, piatto, stack, puntate, carte, fuori=(), bb=50):
    posti = {}
    for k in sorted(NOMI):
        posti[str(k)] = {"nome": NOMI[k], "stack": stack[k], "puntata": puntate.get(k, 0),
                         "carte": carte if k == 1 else "", "in_mano": k not in fuori}
    return {"tipo": "stato", "gioco": "Simulatore", "mano": mano, "bb": bb, "ante": 0, "bottone": bottone,
            "hero": 1, "tocca_a": tocca, "board": board, "piatto": piatto, "posti": posti}


def _az(mano, posto, strada, azione, importo=0):
    return {"tipo": "azione", "mano": mano, "posto": posto, "strada": strada, "azione": azione, "importo": importo}


def righe():
    S = {1: 1500, 2: 2200, 3: 980, 5: 3100}
    # ---------------- mano 1: A♠K♦ da CO, flop 7 7 2, turn: Luca all-in, io lascio
    r = [_az(1, 3, "preflop", "post", 25), _az(1, 5, "preflop", "post", 50),
         _stato(1, 2, 1, "", 75, {**S, 3: 955, 5: 3050}, {3: 25, 5: 50}, "As Kd"),
         _az(1, 1, "preflop", "raise", 125),
         _stato(1, 2, 2, "", 200, {**S, 1: 1375, 3: 955, 5: 3050}, {1: 125, 3: 25, 5: 50}, "As Kd"),
         _az(1, 2, "preflop", "fold"),
         _stato(1, 2, 3, "", 200, {**S, 1: 1375, 3: 955, 5: 3050}, {1: 125, 3: 25, 5: 50}, "As Kd", (2,)),
         _az(1, 3, "preflop", "call", 100), _az(1, 5, "preflop", "fold"),
         _stato(1, 2, 3, "7h 7c 2s", 300, {**S, 1: 1375, 3: 855, 5: 3050}, {}, "As Kd", (2, 5)),
         _az(1, 3, "flop", "check"),
         _stato(1, 2, 1, "7h 7c 2s", 300, {**S, 1: 1375, 3: 855, 5: 3050}, {}, "As Kd", (2, 5)),
         _az(1, 1, "flop", "bet", 100),
         _stato(1, 2, 3, "7h 7c 2s", 400, {**S, 1: 1275, 3: 855, 5: 3050}, {1: 100}, "As Kd", (2, 5)),
         _az(1, 3, "flop", "call", 100),
         _stato(1, 2, 3, "7h 7c 2s Td", 500, {**S, 1: 1275, 3: 755, 5: 3050}, {}, "As Kd", (2, 5)),
         _az(1, 3, "turn", "allin", 755),
         _stato(1, 2, 1, "7h 7c 2s Td", 1255, {**S, 1: 1275, 3: 0, 5: 3050}, {3: 755}, "As Kd", (2, 5)),
         _az(1, 1, "turn", "fold"),
         {"tipo": "fine_mano", "mano": 1, "risultati": {"1": -225, "3": 275, "5": -50}, "mostrate": {}}]
    # ---------------- mano 2: J♥J♣ in big blind, Marco rilancia, io rilancio, Marco lascia
    S2 = {1: 1275, 2: 2200, 3: 1255, 5: 3050}
    r += [_az(2, 5, "preflop", "post", 25), _az(2, 1, "preflop", "post", 50),
          _stato(2, 3, 2, "", 75, {**S2, 5: 3025, 1: 1225}, {5: 25, 1: 50}, "Jh Jc"),
          _az(2, 2, "preflop", "raise", 150), _az(2, 3, "preflop", "fold"), _az(2, 5, "preflop", "fold"),
          _stato(2, 3, 1, "", 225, {**S2, 2: 2050, 5: 3025, 1: 1225}, {2: 150, 5: 25, 1: 50}, "Jh Jc", (3, 5)),
          _az(2, 1, "preflop", "raise", 400),
          _stato(2, 3, 2, "", 625, {**S2, 2: 2050, 5: 3025, 1: 825}, {2: 150, 5: 25, 1: 450}, "Jh Jc", (3, 5)),
          _az(2, 2, "preflop", "fold"),
          {"tipo": "fine_mano", "mano": 2, "risultati": {"1": 175, "2": -150, "5": -25}, "mostrate": {}}]
    return r


def main(argv=None):
    import argparse
    ap = argparse.ArgumentParser(prog="coach.simula")
    ap.add_argument("file")
    ap.add_argument("--pausa", type=float, default=1.5)
    a = ap.parse_args(argv)
    with open(a.file, "a", encoding="utf-8") as f:
        for d in righe():
            f.write(json.dumps(d, ensure_ascii=False) + "\n")
            f.flush()
            print(d["tipo"], d.get("azione", ""), d.get("posto", ""), d.get("board", ""))
            time.sleep(a.pausa)


if __name__ == "__main__":
    main()
