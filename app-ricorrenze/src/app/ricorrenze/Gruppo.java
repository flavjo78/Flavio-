package app.ricorrenze;

import org.json.JSONException;
import org.json.JSONObject;

/** Gruppo di appartenenza (Famiglia, Lavoro, Palestra... personalizzabili). */
public class Gruppo {
    public String id;
    public String nome;
    public int colore;
    public boolean notifiche = true;

    public Gruppo(String id, String nome, int colore, boolean notifiche) {
        this.id = id; this.nome = nome; this.colore = colore; this.notifiche = notifiche;
    }

    public JSONObject toJson() throws JSONException {
        JSONObject o = new JSONObject();
        o.put("id", id);
        o.put("nome", nome);
        o.put("colore", String.format("#%08X", colore));
        o.put("notifiche", notifiche);
        return o;
    }

    public static Gruppo fromJson(JSONObject o) {
        int col = 0xFF7F8C8D;
        try {
            String h = o.optString("colore", "#FF7F8C8D").replace("#", "");
            col = (int) Long.parseLong(h, 16);
            if (h.length() <= 6) col |= 0xFF000000;
        } catch (Exception ignored) { }
        return new Gruppo(o.optString("id", Store.nuovoId()), o.optString("nome", "Gruppo"), col, o.optBoolean("notifiche", true));
    }
}
