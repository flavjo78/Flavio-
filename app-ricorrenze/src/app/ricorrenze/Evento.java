package app.ricorrenze;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.time.LocalDate;
import java.util.ArrayList;

/**
 * Una scheda: una persona (o una festa/evento) con nome, telefono, gruppo e note scritti una volta sola,
 * e dentro tutte le sue date da ricordare (compleanno, onomastico, anniversario…).
 */
public class Evento {
    public String id = Store.nuovoId();
    public String nome = "";
    public String telefono = "";
    public String gruppo = "";    // id del gruppo, "" = nessuno
    public String note = "";
    public final ArrayList<Data> date = new ArrayList<>();

    /** La data che arriva per prima (oggi compreso). */
    public Data prossimaData(LocalDate oggi) {
        Data best = null;
        long bg = Long.MAX_VALUE;
        for (Data d : date) {
            long g = d.giorniA(oggi);
            if (g < bg) { bg = g; best = d; }
        }
        return best;
    }

    public long giorniA(LocalDate oggi) {
        Data d = prossimaData(oggi);
        return d == null ? Long.MAX_VALUE : d.giorniA(oggi);
    }

    public String emoji() {
        return date.isEmpty() ? "⭐" : date.get(0).emoji();
    }

    /** È una festività "da sola" (es. Natale), non una persona. */
    public boolean festa() {
        return date.size() == 1 && "festa".equals(date.get(0).tipo);
    }

    public Data data(String id) {
        for (Data d : date) if (d.id.equals(id)) return d;
        return null;
    }

    /** Titolo "parlante" di una data: "Compleanno di Mario", "Onomastico di Mario", "Natale", "Laurea · Giulia"… */
    public String titolo(Data d) {
        String et = d.et();
        switch (d.tipo) {
            case "compleanno": return "Compleanno di " + nome;
            case "onomastico": return "Onomastico di " + nome;
            case "anniversario": return "Anniversario di " + nome + (et.isEmpty() ? "" : " (" + et + ")");
            case "festa": return et.isEmpty() || et.equalsIgnoreCase(nome) ? nome : Fmt.cap(et) + " · " + nome;
            default: return et.isEmpty() || et.equalsIgnoreCase(nome) ? nome : Fmt.cap(et) + " · " + nome;
        }
    }

    /** Aggiunge le date di un'altra scheda (senza doppioni) e completa i campi vuoti. */
    public int unisci(Evento o) {
        int n = 0;
        for (Data d : o.date) {
            boolean c = false;
            for (Data x : date) if (x.stessaDi(d)) { c = true; break; }
            if (!c) { date.add(d); n++; }
        }
        if (telefono.trim().isEmpty()) telefono = o.telefono;
        if (gruppo == null || gruppo.isEmpty()) gruppo = o.gruppo;
        if (o.note != null && !o.note.trim().isEmpty() && !note.contains(o.note.trim())) {
            note = note.trim().isEmpty() ? o.note.trim() : note.trim() + "\n" + o.note.trim();
        }
        return n;
    }

    public Evento copia() {
        Evento e = new Evento();
        e.id = id; e.nome = nome; e.telefono = telefono; e.gruppo = gruppo; e.note = note;
        for (Data d : date) e.date.add(d.copia());
        return e;
    }

    public JSONObject toJson() throws JSONException {
        JSONObject o = new JSONObject();
        o.put("id", id);
        o.put("nome", nome);
        o.put("telefono", telefono);
        o.put("gruppo", gruppo);
        o.put("note", note);
        JSONArray a = new JSONArray();
        for (Data d : date) a.put(d.toJson());
        o.put("date", a);
        return o;
    }

    /** Legge una scheda; accetta anche il formato della versione 1.0 (una sola data per voce). */
    public static Evento fromJson(JSONObject o) {
        Evento e = new Evento();
        e.id = o.optString("id", e.id);
        e.nome = o.optString("nome", "");
        e.telefono = o.optString("telefono", "");
        e.gruppo = o.optString("gruppo", "");
        e.note = o.optString("note", "");
        JSONArray a = o.optJSONArray("date");
        if (a != null) {
            for (int i = 0; i < a.length(); i++) {
                JSONObject x = a.optJSONObject(i);
                if (x != null) e.date.add(Data.fromJson(x));
            }
        } else {
            Data d = Data.fromJson(o); // formato 1.0: i campi della data stavano nella voce
            d.id = e.id + "d";
            e.date.add(d);
        }
        if (e.date.isEmpty()) e.date.add(new Data());
        return e;
    }
}
