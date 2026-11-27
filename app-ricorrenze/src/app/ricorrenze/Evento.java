package app.ricorrenze;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.time.DayOfWeek;
import java.time.LocalDate;
import java.time.Year;
import java.time.YearMonth;
import java.time.temporal.ChronoUnit;
import java.time.temporal.TemporalAdjusters;
import java.util.Arrays;

/** Una ricorrenza: compleanno, anniversario, onomastico, festività o altro. */
public class Evento {
    public static final String[] TIPI = {"compleanno", "anniversario", "onomastico", "festa", "altro"};
    public static final String[] TIPI_NOME = {"Compleanno", "Anniversario", "Onomastico", "Festività", "Altro"};
    public static final String[] TIPI_EMOJI = {"🎂", "💍", "🌼", "🎉", "⭐"};

    public String id = Store.nuovoId();
    public String nome = "";
    public String tipo = "compleanno";
    public int giorno = 1;
    public int mese = 1;          // 1..12
    public int anno = 0;          // 0 = non indicato
    /** Data mobile: "" = fissa; "pasqua:N" = Pasqua + N giorni; "nth:N:G:M" = N-esimo giorno G (1=lun..7=dom) del mese M. */
    public String regola = "";
    public String telefono = "";
    public String gruppo = "";    // id del gruppo, "" = nessuno
    public int[] avvisi = {0};    // giorni di anticipo (0 = il giorno stesso)
    public int ora = -1;          // minuti dalla mezzanotte, -1 = orario predefinito
    public String note = "";

    public static int tipoIdx(String t) {
        for (int i = 0; i < TIPI.length; i++) if (TIPI[i].equals(t)) return i;
        return TIPI.length - 1;
    }

    public String emoji() { return TIPI_EMOJI[tipoIdx(tipo)]; }
    public String tipoNome() { return TIPI_NOME[tipoIdx(tipo)]; }

    /** Titolo "parlante": "Compleanno di Mario", "Natale"... */
    public String titolo() {
        if ("compleanno".equals(tipo)) return "Compleanno di " + nome;
        if ("onomastico".equals(tipo)) return "Onomastico di " + nome;
        return nome;
    }

    public boolean mobile() { return regola != null && regola.length() > 0; }

    /** Data della ricorrenza in un certo anno (il 29 febbraio diventa 28 negli anni non bisestili). */
    public LocalDate dataNellAnno(int y) {
        if (mobile()) {
            try {
                String[] p = regola.split(":");
                if ("pasqua".equals(p[0])) {
                    return pasqua(y).plusDays(p.length > 1 ? Integer.parseInt(p[1]) : 0);
                }
                if ("nth".equals(p[0])) {
                    int n = Integer.parseInt(p[1]), g = Integer.parseInt(p[2]), m = Integer.parseInt(p[3]);
                    return LocalDate.of(y, m, 1).with(TemporalAdjusters.dayOfWeekInMonth(n, DayOfWeek.of(g)));
                }
            } catch (Exception ignored) { }
        }
        int m = Math.max(1, Math.min(12, mese));
        int d = giorno;
        if (m == 2 && d == 29 && !Year.isLeap(y)) d = 28;
        int max = YearMonth.of(y, m).lengthOfMonth();
        if (d > max) d = max;
        if (d < 1) d = 1;
        return LocalDate.of(y, m, d);
    }

    /** Prossima volta (oggi compreso). */
    public LocalDate prossima(LocalDate oggi) {
        LocalDate d = dataNellAnno(oggi.getYear());
        if (d.isBefore(oggi)) d = dataNellAnno(oggi.getYear() + 1);
        return d;
    }

    public long giorniA(LocalDate oggi) {
        return ChronoUnit.DAYS.between(oggi, prossima(oggi));
    }

    /** Anni compiuti in quella data (o -1 se l'anno non è indicato). */
    public int anni(LocalDate occ) {
        if (anno <= 0 || mobile()) return -1;
        int n = occ.getYear() - anno;
        return n > 0 ? n : -1;
    }

    /** "compie 41 anni", "41° anniversario", "41 anni"... (vuoto se l'anno non c'è). */
    public String eta(LocalDate occ) {
        int n = anni(occ);
        if (n <= 0) return "";
        if ("compleanno".equals(tipo)) return n == 1 ? "compie 1 anno" : "compie " + n + " anni";
        if ("anniversario".equals(tipo)) return n + "° anniversario";
        return n == 1 ? "1 anno" : n + " anni";
    }

    /** Età attuale (per il dettaglio): "ha 40 anni". */
    public String etaOggi(LocalDate oggi) {
        if (anno <= 0 || mobile()) return "";
        LocalDate questAnno = dataNellAnno(oggi.getYear());
        int n = oggi.getYear() - anno - (oggi.isBefore(questAnno) ? 1 : 0);
        if (n < 0) return "";
        if ("compleanno".equals(tipo)) return n == 1 ? "ha 1 anno" : "ha " + n + " anni";
        return "";
    }

    public String descrizioneRegola() {
        if (!mobile()) return "";
        String[] p = regola.split(":");
        if ("pasqua".equals(p[0])) {
            int off = p.length > 1 ? Integer.parseInt(p[1]) : 0;
            if (off == 0) return "data mobile: Pasqua";
            if (off > 0) return "data mobile: " + off + (off == 1 ? " giorno" : " giorni") + " dopo Pasqua";
            return "data mobile: " + (-off) + " giorni prima di Pasqua";
        }
        if ("nth".equals(p[0])) {
            int n = Integer.parseInt(p[1]), g = Integer.parseInt(p[2]), m = Integer.parseInt(p[3]);
            String[] ord = {"", "prima", "seconda", "terza", "quarta", "quinta"};
            String gn = Fmt.GIORNI[g - 1];
            String art = (g == 7) ? "la " : "il ";
            String ordine = (g == 7) ? ord[Math.min(5, n)] : ord[Math.min(5, n)].replaceAll("a$", "o");
            return "data mobile: " + art + ordine + " " + gn + " di " + Fmt.MESI[m - 1];
        }
        return "data mobile";
    }

    /** Domenica di Pasqua (calendario gregoriano). */
    public static LocalDate pasqua(int y) {
        int a = y % 19, b = y / 100, c = y % 100, d = b / 4, e = b % 4;
        int f = (b + 8) / 25, g = (b - f + 1) / 3;
        int h = (19 * a + b - d - g + 15) % 30;
        int i = c / 4, k = c % 4;
        int l = (32 + 2 * e + 2 * i - h - k) % 7;
        int m = (a + 11 * h + 22 * l) / 451;
        int month = (h + l - 7 * m + 114) / 31;
        int day = ((h + l - 7 * m + 114) % 31) + 1;
        return LocalDate.of(y, month, day);
    }

    public boolean haAvviso(int giorni) {
        for (int a : avvisi) if (a == giorni) return true;
        return false;
    }

    public Evento copia() {
        Evento e = new Evento();
        e.id = id; e.nome = nome; e.tipo = tipo; e.giorno = giorno; e.mese = mese; e.anno = anno;
        e.regola = regola; e.telefono = telefono; e.gruppo = gruppo;
        e.avvisi = Arrays.copyOf(avvisi, avvisi.length); e.ora = ora; e.note = note;
        return e;
    }

    public JSONObject toJson() throws JSONException {
        JSONObject o = new JSONObject();
        o.put("id", id);
        o.put("nome", nome);
        o.put("tipo", tipo);
        o.put("giorno", giorno);
        o.put("mese", mese);
        o.put("anno", anno);
        if (mobile()) o.put("regola", regola);
        o.put("telefono", telefono);
        o.put("gruppo", gruppo);
        JSONArray a = new JSONArray();
        for (int x : avvisi) a.put(x);
        o.put("avvisi", a);
        o.put("ora", ora);
        o.put("note", note);
        return o;
    }

    public static Evento fromJson(JSONObject o) {
        Evento e = new Evento();
        e.id = o.optString("id", e.id);
        e.nome = o.optString("nome", "");
        e.tipo = o.optString("tipo", "compleanno");
        e.giorno = o.optInt("giorno", 1);
        e.mese = o.optInt("mese", 1);
        e.anno = o.optInt("anno", 0);
        e.regola = o.optString("regola", "");
        e.telefono = o.optString("telefono", "");
        e.gruppo = o.optString("gruppo", "");
        JSONArray a = o.optJSONArray("avvisi");
        if (a != null) {
            e.avvisi = new int[a.length()];
            for (int i = 0; i < a.length(); i++) e.avvisi[i] = a.optInt(i, 0);
        }
        e.ora = o.optInt("ora", -1);
        e.note = o.optString("note", "");
        return e;
    }
}
