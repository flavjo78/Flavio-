package app.ricorrenze;

import android.content.Context;
import android.util.AtomicFile;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Random;

/** Tutti i dati dell'app (ricorrenze, gruppi, impostazioni), salvati in un file JSON. */
public final class Store {
    private static Store inst;
    private static final Random RND = new Random();

    public final ArrayList<Evento> eventi = new ArrayList<>();
    public final ArrayList<Gruppo> gruppi = new ArrayList<>();

    // Impostazioni
    public int oraDefault = 9 * 60;
    public int[] avvisiDefault = {0, 1};
    public String prefisso = "39";
    public String testoAuguri = "Tanti auguri {nome}! 🎉🎂";

    private AtomicFile file;

    public static synchronized Store get(Context c) {
        if (inst == null) {
            inst = new Store();
            inst.file = new AtomicFile(new File(c.getApplicationContext().getFilesDir(), "dati.json"));
            inst.carica();
        }
        return inst;
    }

    public static String nuovoId() {
        return Long.toString(System.currentTimeMillis(), 36) + Integer.toString(1296 + RND.nextInt(45000), 36);
    }

    private void predefiniti() {
        gruppi.clear();
        gruppi.add(new Gruppo("famiglia", "Famiglia", 0xFFE84393, true));
        gruppi.add(new Gruppo("amici", "Amici", 0xFF3498DB, true));
        gruppi.add(new Gruppo("lavoro", "Lavoro", 0xFFE67E22, true));
        gruppi.add(new Gruppo("palestra", "Palestra", 0xFF2ECC71, true));
        gruppi.add(new Gruppo("altro", "Altro", 0xFF7F8C8D, true));
    }

    private void carica() {
        try {
            if (!file.getBaseFile().exists()) { predefiniti(); return; }
            byte[] b = file.readFully();
            fromJson(new JSONObject(new String(b, StandardCharsets.UTF_8)), false);
        } catch (Exception e) {
            if (gruppi.isEmpty()) predefiniti();
        }
    }

    public JSONObject toJson() {
        JSONObject o = new JSONObject();
        try {
            o.put("app", "ricorrenze");
            o.put("versione", 1);
            JSONArray g = new JSONArray();
            for (Gruppo x : gruppi) g.put(x.toJson());
            o.put("gruppi", g);
            JSONArray ev = new JSONArray();
            for (Evento x : eventi) ev.put(x.toJson());
            o.put("eventi", ev);
            JSONObject imp = new JSONObject();
            imp.put("oraDefault", oraDefault);
            JSONArray av = new JSONArray();
            for (int x : avvisiDefault) av.put(x);
            imp.put("avvisiDefault", av);
            imp.put("prefisso", prefisso);
            imp.put("testoAuguri", testoAuguri);
            o.put("impostazioni", imp);
        } catch (JSONException ignored) { }
        return o;
    }

    /** Legge i dati. Con unisci=true aggiunge/aggiorna senza cancellare quello che c'è. */
    public void fromJson(JSONObject o, boolean unisci) throws JSONException {
        JSONArray g = o.optJSONArray("gruppi");
        JSONArray ev = o.optJSONArray("eventi");
        if (g == null && ev == null) throw new JSONException("File non valido");
        if (!unisci) { gruppi.clear(); eventi.clear(); }
        if (g != null) for (int i = 0; i < g.length(); i++) {
            Gruppo x = Gruppo.fromJson(g.getJSONObject(i));
            Gruppo esistente = gruppo(x.id);
            if (esistente == null) esistente = gruppoPerNome(x.nome);
            if (esistente != null) { esistente.nome = x.nome; esistente.colore = x.colore; esistente.notifiche = x.notifiche; }
            else gruppi.add(x);
        }
        if (ev != null) for (int i = 0; i < ev.length(); i++) {
            Evento x = Evento.fromJson(ev.getJSONObject(i));
            Evento esistente = evento(x.id);
            if (esistente != null) eventi.set(eventi.indexOf(esistente), x);
            else eventi.add(x);
        }
        JSONObject imp = o.optJSONObject("impostazioni");
        if (imp != null && !unisci) {
            oraDefault = imp.optInt("oraDefault", oraDefault);
            JSONArray av = imp.optJSONArray("avvisiDefault");
            if (av != null) {
                avvisiDefault = new int[av.length()];
                for (int i = 0; i < av.length(); i++) avvisiDefault[i] = av.optInt(i, 0);
            }
            prefisso = imp.optString("prefisso", prefisso);
            testoAuguri = imp.optString("testoAuguri", testoAuguri);
        }
        if (gruppi.isEmpty() && eventi.isEmpty()) predefiniti();
    }

    /** Salva su disco e aggiorna le sveglie delle notifiche. */
    public void salva(Context c) {
        FileOutputStream out = null;
        try {
            out = file.startWrite();
            out.write(toJson().toString(1).getBytes(StandardCharsets.UTF_8));
            file.finishWrite(out);
        } catch (Exception e) {
            if (out != null) file.failWrite(out);
        }
        Scheduler.riprogramma(c);
    }

    public Gruppo gruppo(String id) {
        if (id == null || id.isEmpty()) return null;
        for (Gruppo g : gruppi) if (g.id.equals(id)) return g;
        return null;
    }

    public Gruppo gruppoPerNome(String nome) {
        for (Gruppo g : gruppi) if (g.nome.equalsIgnoreCase(nome.trim())) return g;
        return null;
    }

    public Evento evento(String id) {
        if (id == null) return null;
        for (Evento e : eventi) if (e.id.equals(id)) return e;
        return null;
    }

    public int conta(String gruppoId) {
        int n = 0;
        for (Evento e : eventi) if (gruppoId.equals(e.gruppo == null ? "" : e.gruppo)) n++;
        return n;
    }

    public int colore(Evento e) {
        Gruppo g = gruppo(e.gruppo);
        return g != null ? g.colore : 0xFF9A93B5;
    }

    public boolean notificheAttive(Evento e) {
        Gruppo g = gruppo(e.gruppo);
        return g == null || g.notifiche;
    }

    public int oraDi(Evento e) { return e.ora >= 0 ? e.ora : oraDefault; }

    public int[] copiaAvvisiDefault() { return Arrays.copyOf(avvisiDefault, avvisiDefault.length); }

    public static String leggi(java.io.InputStream in) throws IOException {
        java.io.ByteArrayOutputStream bo = new java.io.ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) > 0) bo.write(buf, 0, n);
        return new String(bo.toByteArray(), StandardCharsets.UTF_8);
    }
}
