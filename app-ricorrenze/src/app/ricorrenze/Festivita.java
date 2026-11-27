package app.ricorrenze;

import android.app.Activity;
import android.app.AlertDialog;
import android.widget.Toast;

import java.time.LocalDate;
import java.util.ArrayList;

/** Festività italiane pronte da aggiungere (anche quelle a data mobile, es. Pasqua). */
public final class Festivita {
    private Festivita() {}

    static final class F {
        final String nome, regola;
        final int g, m;
        final boolean consigliata;
        F(String nome, int g, int m, String regola, boolean consigliata) {
            this.nome = nome; this.g = g; this.m = m; this.regola = regola; this.consigliata = consigliata;
        }
    }

    static final F[] ELENCO = {
            new F("Capodanno", 1, 1, "", true),
            new F("Epifania", 6, 1, "", false),
            new F("San Valentino", 14, 2, "", true),
            new F("Carnevale (martedì grasso)", 0, 0, "pasqua:-47", false),
            new F("Festa della donna", 8, 3, "", true),
            new F("Festa del papà", 19, 3, "", true),
            new F("Pasqua", 0, 0, "pasqua:0", true),
            new F("Pasquetta", 0, 0, "pasqua:1", false),
            new F("Festa della Liberazione", 25, 4, "", false),
            new F("Festa dei lavoratori", 1, 5, "", false),
            new F("Festa della mamma", 0, 5, "nth:2:7:5", true),
            new F("Festa della Repubblica", 2, 6, "", false),
            new F("Ferragosto", 15, 8, "", false),
            new F("Festa dei nonni", 2, 10, "", true),
            new F("San Francesco, patrono d'Italia", 4, 10, "", false),
            new F("Halloween", 31, 10, "", false),
            new F("Ognissanti", 1, 11, "", false),
            new F("Immacolata", 8, 12, "", false),
            new F("Vigilia di Natale", 24, 12, "", false),
            new F("Natale", 25, 12, "", true),
            new F("Santo Stefano", 26, 12, "", false),
            new F("San Silvestro", 31, 12, "", false),
    };

    static Evento evento(F f) {
        Evento e = new Evento();
        e.nome = f.nome;
        e.tipo = "festa";
        e.regola = f.regola;
        if (f.regola.isEmpty()) { e.giorno = f.g; e.mese = f.m; }
        else {
            LocalDate d = e.dataNellAnno(LocalDate.now().getYear());
            e.giorno = d.getDayOfMonth(); e.mese = d.getMonthValue();
        }
        return e;
    }

    public static void scegli(final Activity a, final Runnable dopo) {
        final Store s = Store.get(a);
        final ArrayList<F> disponibili = new ArrayList<>();
        for (F f : ELENCO) {
            boolean presente = false;
            for (Evento e : s.eventi) if (e.nome.equalsIgnoreCase(f.nome)) { presente = true; break; }
            if (!presente) disponibili.add(f);
        }
        if (disponibili.isEmpty()) {
            Toast.makeText(a, "Hai già aggiunto tutte le festività", Toast.LENGTH_LONG).show();
            return;
        }
        String[] et = new String[disponibili.size()];
        final boolean[] sel = new boolean[disponibili.size()];
        for (int i = 0; i < disponibili.size(); i++) {
            F f = disponibili.get(i);
            Evento e = evento(f);
            LocalDate d = e.prossima(LocalDate.now());
            et[i] = f.nome + " — " + Fmt.data(d) + (f.regola.isEmpty() ? "" : " " + d.getYear() + " (cambia ogni anno)");
            sel[i] = f.consigliata;
        }
        new AlertDialog.Builder(a)
                .setTitle("Festività da aggiungere")
                .setMultiChoiceItems(et, sel, (d, i, v) -> sel[i] = v)
                .setPositiveButton("Aggiungi", (d, w) -> {
                    Gruppo g = s.gruppo("feste");
                    if (g == null) g = s.gruppoPerNome("Festività");
                    if (g == null) {
                        g = new Gruppo("feste", "Festività", 0xFFF1B80F, true);
                        s.gruppi.add(g);
                    }
                    int n = 0;
                    for (int i = 0; i < disponibili.size(); i++) {
                        if (!sel[i]) continue;
                        Evento e = evento(disponibili.get(i));
                        e.gruppo = g.id;
                        e.avvisi = s.copiaAvvisiDefault();
                        s.eventi.add(e);
                        n++;
                    }
                    s.salva(a);
                    Toast.makeText(a, n == 1 ? "Aggiunta 1 festività" : "Aggiunte " + n + " festività", Toast.LENGTH_LONG).show();
                    if (dopo != null) dopo.run();
                })
                .setNegativeButton("Annulla", null)
                .show();
    }
}
