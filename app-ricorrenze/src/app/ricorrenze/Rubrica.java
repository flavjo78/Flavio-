package app.ricorrenze;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ContentResolver;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.provider.ContactsContract;
import android.provider.ContactsContract.CommonDataKinds.Event;
import android.provider.ContactsContract.CommonDataKinds.Phone;
import android.widget.Toast;

import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Importa compleanni e anniversari salvati nei contatti del telefono. */
public final class Rubrica {
    private Rubrica() {}

    public static final int REQ = 21;

    static class Trovato implements Comparable<Trovato> {
        String nome, tel = "", tipo;
        int g, m, y;
        public int compareTo(Trovato o) { return nome.compareToIgnoreCase(o.nome); }
    }

    public static void importa(final Activity a, final Runnable dopo) {
        if (a.checkSelfPermission(Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
            new AlertDialog.Builder(a)
                    .setTitle("Importa dalla rubrica")
                    .setMessage("Leggo i compleanni e gli anniversari salvati nei tuoi contatti, con il loro numero di telefono. "
                            + "Potrai scegliere quali aggiungere. I dati restano solo su questo telefono.")
                    .setPositiveButton("Continua", (d, w) -> a.requestPermissions(new String[]{Manifest.permission.READ_CONTACTS}, REQ))
                    .setNegativeButton("Annulla", null)
                    .show();
            return;
        }
        mostra(a, dopo);
    }

    public static void dopoPermesso(Activity a, int[] res, Runnable dopo) {
        if (res.length > 0 && res[0] == PackageManager.PERMISSION_GRANTED) mostra(a, dopo);
        else Toast.makeText(a, "Senza permesso non posso leggere la rubrica. Puoi sempre aggiungere i compleanni a mano.", Toast.LENGTH_LONG).show();
    }

    static void mostra(final Activity a, final Runnable dopo) {
        final Store s = Store.get(a);
        ArrayList<Trovato> tutti;
        try {
            tutti = leggi(a);
        } catch (Exception ex) {
            Toast.makeText(a, "Non riesco a leggere la rubrica.", Toast.LENGTH_LONG).show();
            return;
        }
        final ArrayList<Trovato> nuovi = new ArrayList<>();
        for (Trovato t : tutti) {
            boolean presente = false;
            for (Evento e : s.eventi) {
                if (e.nome.equalsIgnoreCase(t.nome) && e.giorno == t.g && e.mese == t.m) { presente = true; break; }
            }
            if (!presente) nuovi.add(t);
        }
        if (nuovi.isEmpty()) {
            new AlertDialog.Builder(a)
                    .setTitle("Niente di nuovo")
                    .setMessage(tutti.isEmpty()
                            ? "Nei contatti non ho trovato compleanni salvati. Puoi aggiungerli a mano con il tasto +."
                            : "Tutti i compleanni della rubrica sono già nell'elenco.")
                    .setPositiveButton("OK", null)
                    .show();
            return;
        }
        String[] etichette = new String[nuovi.size()];
        final boolean[] scelti = new boolean[nuovi.size()];
        for (int i = 0; i < nuovi.size(); i++) {
            Trovato t = nuovi.get(i);
            String em = "anniversario".equals(t.tipo) ? "💍 " : "🎂 ";
            etichette[i] = em + t.nome + " — " + t.g + " " + Fmt.mese(t.m) + (t.y > 0 ? " " + t.y : "");
            scelti[i] = true;
        }
        new AlertDialog.Builder(a)
                .setTitle("Trovati " + nuovi.size() + " — scegli quali aggiungere")
                .setMultiChoiceItems(etichette, scelti, (d, i, v) -> scelti[i] = v)
                .setPositiveButton("Avanti", (d, w) -> {
                    final ArrayList<Trovato> sel = new ArrayList<>();
                    for (int i = 0; i < nuovi.size(); i++) if (scelti[i]) sel.add(nuovi.get(i));
                    if (sel.isEmpty()) return;
                    scegliGruppo(a, sel, dopo);
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    private static void scegliGruppo(final Activity a, final ArrayList<Trovato> sel, final Runnable dopo) {
        final Store s = Store.get(a);
        String[] voci = new String[s.gruppi.size() + 1];
        voci[0] = "Nessun gruppo (lo scelgo dopo)";
        for (int i = 0; i < s.gruppi.size(); i++) voci[i + 1] = s.gruppi.get(i).nome;
        final int[] scelta = {0};
        new AlertDialog.Builder(a)
                .setTitle("In quale gruppo li metto?")
                .setSingleChoiceItems(voci, 0, (d, i) -> scelta[0] = i)
                .setPositiveButton("Importa", (d, w) -> {
                    String gid = scelta[0] == 0 ? "" : s.gruppi.get(scelta[0] - 1).id;
                    for (Trovato t : sel) {
                        Evento e = new Evento();
                        e.nome = t.nome;
                        e.tipo = t.tipo;
                        e.giorno = t.g;
                        e.mese = t.m;
                        e.anno = t.y;
                        e.telefono = t.tel;
                        e.gruppo = gid;
                        e.avvisi = s.copiaAvvisiDefault();
                        s.eventi.add(e);
                    }
                    s.salva(a);
                    Toast.makeText(a, sel.size() == 1 ? "Aggiunta 1 ricorrenza" : "Aggiunte " + sel.size() + " ricorrenze", Toast.LENGTH_LONG).show();
                    if (dopo != null) dopo.run();
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    static ArrayList<Trovato> leggi(Activity a) {
        ContentResolver cr = a.getContentResolver();
        HashMap<Long, String> tel = new HashMap<>();
        HashSet<Long> cellulare = new HashSet<>();
        Cursor pc = cr.query(Phone.CONTENT_URI, new String[]{Phone.CONTACT_ID, Phone.NUMBER, Phone.TYPE, Phone.IS_SUPER_PRIMARY},
                null, null, null);
        if (pc != null) {
            try {
                while (pc.moveToNext()) {
                    long id = pc.getLong(0);
                    String num = pc.getString(1);
                    if (num == null) continue;
                    boolean mob = pc.getInt(2) == Phone.TYPE_MOBILE;
                    boolean prim = pc.getInt(3) != 0;
                    if (!tel.containsKey(id) || prim || (mob && !cellulare.contains(id))) {
                        tel.put(id, num);
                        if (mob) cellulare.add(id);
                    }
                }
            } finally { pc.close(); }
        }
        ArrayList<Trovato> out = new ArrayList<>();
        HashSet<String> visti = new HashSet<>();
        Cursor c = cr.query(ContactsContract.Data.CONTENT_URI,
                new String[]{ContactsContract.Data.CONTACT_ID, ContactsContract.Data.DISPLAY_NAME, Event.START_DATE, Event.TYPE},
                ContactsContract.Data.MIMETYPE + "=?", new String[]{Event.CONTENT_ITEM_TYPE}, null);
        if (c != null) {
            try {
                while (c.moveToNext()) {
                    long id = c.getLong(0);
                    String nome = c.getString(1);
                    int tipo = c.getInt(3);
                    if (nome == null || nome.trim().isEmpty()) continue;
                    String t;
                    if (tipo == Event.TYPE_BIRTHDAY) t = "compleanno";
                    else if (tipo == Event.TYPE_ANNIVERSARY) t = "anniversario";
                    else continue;
                    int[] d = data(c.getString(2));
                    if (d == null) continue;
                    String chiave = id + "|" + t;
                    if (!visti.add(chiave)) continue;
                    Trovato x = new Trovato();
                    x.nome = nome.trim();
                    x.tipo = t;
                    x.y = d[0]; x.m = d[1]; x.g = d[2];
                    String n = tel.get(id);
                    if (n != null) x.tel = n;
                    out.add(x);
                }
            } finally { c.close(); }
        }
        Collections.sort(out);
        return out;
    }

    private static final Pattern P_ISO = Pattern.compile("^(\\d{4})-?(\\d{1,2})-?(\\d{1,2})");
    private static final Pattern P_SENZA_ANNO = Pattern.compile("^--(\\d{1,2})-?(\\d{1,2})");
    private static final Pattern P_EU = Pattern.compile("^(\\d{1,2})[/.\\-](\\d{1,2})[/.\\-](\\d{4})");
    private static final Pattern P_EU_BREVE = Pattern.compile("^(\\d{1,2})[/.](\\d{1,2})$");

    /** Restituisce {anno, mese, giorno} (anno 0 se assente) o null. */
    static int[] data(String s) {
        if (s == null) return null;
        s = s.trim();
        int y, m, d;
        Matcher x = P_ISO.matcher(s);
        if (x.find()) { y = i(x.group(1)); m = i(x.group(2)); d = i(x.group(3)); }
        else if ((x = P_SENZA_ANNO.matcher(s)).find()) { y = 0; m = i(x.group(1)); d = i(x.group(2)); }
        else if ((x = P_EU.matcher(s)).find()) { d = i(x.group(1)); m = i(x.group(2)); y = i(x.group(3)); }
        else if ((x = P_EU_BREVE.matcher(s)).find()) { d = i(x.group(1)); m = i(x.group(2)); y = 0; }
        else return null;
        if (m < 1 || m > 12 || d < 1 || d > 31) return null;
        if (y < 1900 || y > java.time.LocalDate.now().getYear()) y = 0;
        return new int[]{y, m, d};
    }

    private static int i(String s) { return Integer.parseInt(s); }
}
