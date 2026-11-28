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

/** Una data da ricordare dentro una scheda: compleanno, onomastico, anniversario, festività o altro. */
public class Data {
    public static final String[] TIPI = {"compleanno", "onomastico", "anniversario", "festa", "altro"};
    public static final String[] TIPI_NOME = {"Compleanno", "Onomastico", "Anniversario", "Festività", "Altro evento"};
    public static final String[] TIPI_EMOJI = {"🎂", "🌼", "💍", "🎉", "⭐"};

    public String id = Store.nuovoId();
    public String tipo = "compleanno";
    /** Descrizione libera: "matrimonio" per un anniversario, "Laurea" per un altro evento… */
    public String etichetta = "";
    public int giorno = 1;
    public int mese = 1;          // 1..12
    public int anno = 0;          // 0 = non indicato
    /** Data mobile: "" = fissa; "pasqua:N" = Pasqua + N giorni; "nth:N:G:M" = N-esimo giorno G (1=lun..7=dom) del mese M. */
    public String regola = "";
    public int[] avvisi = {0};    // giorni di anticipo (0 = il giorno stesso)
    public int ora = -1;          // minuti dalla mezzanotte, -1 = orario predefinito

    public static int tipoIdx(String t) {
        for (int i = 0; i < TIPI.length; i++) if (TIPI[i].equals(t)) return i;
        return TIPI.length - 1;
    }

    public String emoji() { return TIPI_EMOJI[tipoIdx(tipo)]; }
    public String tipoNome() { return TIPI_NOME[tipoIdx(tipo)]; }

    public String et() { return etichetta == null ? "" : etichetta.trim(); }

    /** "Compleanno", "Anniversario (matrimonio)", "Laurea"… */
    public String nomeCompleto() {
        String e = et();
        if (e.isEmpty()) return tipoNome();
        if ("altro".equals(tipo) || "festa".equals(tipo)) return Fmt.cap(e);
        return tipoNome() + " (" + e + ")";
    }

    public boolean mobile() { return regola != null && regola.length() > 0; }

    /** L'anno ha senso solo per compleanni (età) e anniversari (quanti anni). */
    public boolean usaAnno() { return "compleanno".equals(tipo) || "anniversario".equals(tipo); }

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
        if (anno <= 0 || mobile() || !usaAnno()) return -1;
        int n = occ.getYear() - anno;
        return n > 0 ? n : -1;
    }

    /** "compie 41 anni", "41° anniversario", "41 anni"… (vuoto se l'anno non c'è). */
    public String eta(LocalDate occ) {
        int n = anni(occ);
        if (n <= 0) return "";
        if ("compleanno".equals(tipo)) return n == 1 ? "compie 1 anno" : "compie " + n + " anni";
        if ("anniversario".equals(tipo)) return n + "° anniversario";
        return n == 1 ? "1 anno" : n + " anni";
    }

    /** Età attuale (per la scheda): "ha 40 anni". */
    public String etaOggi(LocalDate oggi) {
        if (anno <= 0 || mobile() || !"compleanno".equals(tipo)) return "";
        LocalDate questAnno = dataNellAnno(oggi.getYear());
        int n = oggi.getYear() - anno - (oggi.isBefore(questAnno) ? 1 : 0);
        if (n < 0) return "";
        return n == 1 ? "ha 1 anno" : "ha " + n + " anni";
    }

    public String descrizioneRegola() {
        if (!mobile()) return "";
        try {
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
        } catch (Exception ignored) { }
        return "data mobile";
    }

    /** "12 dicembre 1985", "19 marzo", "data mobile: Pasqua" */
    public String descrizioneData() {
        if (mobile()) return descrizioneRegola();
        return giorno + " " + Fmt.mese(mese) + (anno > 0 && usaAnno() ? " " + anno : "");
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

    /** Stessa ricorrenza (stesso tipo e stesso giorno), per evitare doppioni quando si uniscono schede. */
    public boolean stessaDi(Data o) {
        if (!tipo.equals(o.tipo)) return false;
        if (mobile() || o.mobile()) return regola.equals(o.regola);
        return giorno == o.giorno && mese == o.mese;
    }

    public Data copia() {
        Data d = new Data();
        d.id = id; d.tipo = tipo; d.etichetta = etichetta; d.giorno = giorno; d.mese = mese; d.anno = anno;
        d.regola = regola; d.avvisi = Arrays.copyOf(avvisi, avvisi.length); d.ora = ora;
        return d;
    }

    public JSONObject toJson() throws JSONException {
        JSONObject o = new JSONObject();
        o.put("id", id);
        o.put("tipo", tipo);
        if (!et().isEmpty()) o.put("etichetta", et());
        o.put("giorno", giorno);
        o.put("mese", mese);
        o.put("anno", anno);
        if (mobile()) o.put("regola", regola);
        JSONArray a = new JSONArray();
        for (int x : avvisi) a.put(x);
        o.put("avvisi", a);
        o.put("ora", ora);
        return o;
    }

    public static Data fromJson(JSONObject o) {
        Data d = new Data();
        d.id = o.optString("id", d.id);
        d.tipo = o.optString("tipo", "compleanno");
        d.etichetta = o.optString("etichetta", "");
        d.giorno = o.optInt("giorno", 1);
        d.mese = o.optInt("mese", 1);
        d.anno = o.optInt("anno", 0);
        d.regola = o.optString("regola", "");
        JSONArray a = o.optJSONArray("avvisi");
        if (a != null) {
            d.avvisi = new int[a.length()];
            for (int i = 0; i < a.length(); i++) d.avvisi[i] = a.optInt(i, 0);
        }
        d.ora = o.optInt("ora", -1);
        return d;
    }
}
