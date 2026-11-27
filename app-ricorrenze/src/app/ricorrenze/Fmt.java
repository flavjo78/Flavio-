package app.ricorrenze;

import java.time.LocalDate;
import java.util.Arrays;

/** Testi in italiano per date, orari e promemoria. */
public final class Fmt {
    private Fmt() {}

    public static final String[] MESI = {"gennaio", "febbraio", "marzo", "aprile", "maggio", "giugno",
            "luglio", "agosto", "settembre", "ottobre", "novembre", "dicembre"};
    public static final String[] GIORNI = {"lunedì", "martedì", "mercoledì", "giovedì", "venerdì", "sabato", "domenica"};
    public static final String[] GIORNI_BREVI = {"L", "M", "M", "G", "V", "S", "D"};

    public static String cap(String s) {
        if (s == null || s.isEmpty()) return "";
        return Character.toUpperCase(s.charAt(0)) + s.substring(1);
    }

    public static String low(String s) {
        if (s == null || s.isEmpty()) return "";
        return Character.toLowerCase(s.charAt(0)) + s.substring(1);
    }

    public static String mese(int m) { return MESI[Math.max(1, Math.min(12, m)) - 1]; }

    /** "12 dicembre" */
    public static String data(LocalDate d) { return d.getDayOfMonth() + " " + MESI[d.getMonthValue() - 1]; }

    /** "sabato 12 dicembre" */
    public static String dataLunga(LocalDate d) { return GIORNI[d.getDayOfWeek().getValue() - 1] + " " + data(d); }

    /** "sabato 12 dicembre 2026" */
    public static String dataAnno(LocalDate d) { return dataLunga(d) + " " + d.getYear(); }

    public static String ora(int minuti) {
        return String.format("%02d:%02d", minuti / 60, minuti % 60);
    }

    /** "Oggi", "Domani", "Tra 3 giorni" */
    public static String quando(long giorni) {
        if (giorni == 0) return "Oggi";
        if (giorni == 1) return "Domani";
        return "Tra " + giorni + " giorni";
    }

    public static String anticipo(int g) {
        if (g == 0) return "il giorno stesso";
        if (g == 1) return "1 giorno prima";
        return g + " giorni prima";
    }

    /** "il giorno stesso e 3 giorni prima" */
    public static String avvisi(int[] a) {
        if (a == null || a.length == 0) return "nessun promemoria";
        int[] s = Arrays.copyOf(a, a.length);
        Arrays.sort(s);
        StringBuilder b = new StringBuilder();
        for (int i = 0; i < s.length; i++) {
            if (i > 0) b.append(i == s.length - 1 ? " e " : ", ");
            b.append(anticipo(s[i]));
        }
        return b.toString();
    }

    public static String primoNome(String nome) {
        if (nome == null) return "";
        String n = nome.trim();
        int sp = n.indexOf(' ');
        return sp > 0 ? n.substring(0, sp) : n;
    }
}
