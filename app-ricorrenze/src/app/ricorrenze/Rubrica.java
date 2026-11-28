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

    /** Una data trovata in rubrica. */
    static class DataT {
        String tipo;
        int y, m, g;
        String testo() {
            return ("anniversario".equals(tipo) ? "💍 " : "🎂 ") + g + " " + Fmt.mese(m) + (y > 0 ? " " + y : "");
        }
    }

    /** Un contatto con le sue date (compleanno, anniversario). */
    static class Trovato implements Comparable<Trovato> {
        String nome, tel = "";
        final ArrayList<DataT> date = new ArrayList<>();
        Evento esistente;                       // scheda che ha già lo stesso nome
        final ArrayList<DataT> nuove = new ArrayList<>();
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
            t.esistente = s.perNome(t.nome, null);
            for (DataT dt : t.date) {
                boolean presente = false;
                if (t.esistente != null) for (Data x : t.esistente.date) {
                    if (x.tipo.equals(dt.tipo) && x.giorno == dt.g && x.mese == dt.m) { presente = true; break; }
                }
                if (!presente) t.nuove.add(dt);
            }
            if (!t.nuove.isEmpty()) nuovi.add(t);
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
            StringBuilder b = new StringBuilder(t.nome).append(" — ");
            for (int k = 0; k < t.nuove.size(); k++) b.append(k > 0 ? ", " : "").append(t.nuove.get(k).testo());
            if (t.esistente != null) b.append("  (si aggiunge alla sua scheda)");
            etichette[i] = b.toString();
            scelti[i] = true;
        }
        new AlertDialog.Builder(a)
                .setTitle("Trovati " + nuovi.size() + " — scegli quali aggiungere")
                .setMultiChoiceItems(etichette, scelti, (d, i, v) -> scelti[i] = v)
                .setPositiveButton("Avanti", (d, w) -> {
                    final ArrayList<Trovato> sel = new ArrayList<>();
                    boolean servonoGruppi = false;
                    for (int i = 0; i < nuovi.size(); i++) if (scelti[i]) {
                        sel.add(nuovi.get(i));
                        if (nuovi.get(i).esistente == null) servonoGruppi = true;
                    }
                    if (sel.isEmpty()) return;
                    if (servonoGruppi) scegliGruppo(a, sel, dopo);
                    else aggiungi(a, sel, "", dopo);
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
                .setTitle("In quale gruppo metto le nuove schede?")
                .setSingleChoiceItems(voci, 0, (d, i) -> scelta[0] = i)
                .setPositiveButton("Importa", (d, w) -> aggiungi(a, sel, scelta[0] == 0 ? "" : s.gruppi.get(scelta[0] - 1).id, dopo))
                .setNegativeButton("Annulla", null)
                .show();
    }

    private static void aggiungi(Activity a, ArrayList<Trovato> sel, String gid, Runnable dopo) {
        Store s = Store.get(a);
        int date = 0;
        for (Trovato t : sel) {
            Evento e = t.esistente;
            if (e == null) {
                e = new Evento();
                e.nome = t.nome;
                e.gruppo = gid;
                s.eventi.add(e);
            }
            if (e.telefono == null || e.telefono.trim().isEmpty()) e.telefono = t.tel;
            for (DataT dt : t.nuove) {
                Data d = new Data();
                d.tipo = dt.tipo;
                d.giorno = dt.g;
                d.mese = dt.m;
                d.anno = dt.y;
                d.avvisi = s.copiaAvvisiDefault();
                e.date.add(d);
                date++;
            }
        }
        s.salva(a);
        Toast.makeText(a, date == 1 ? "Aggiunta 1 data" : "Aggiunte " + date + " date", Toast.LENGTH_LONG).show();
        if (dopo != null) dopo.run();
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
        java.util.LinkedHashMap<Long, Trovato> perContatto = new java.util.LinkedHashMap<>();
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
                    if (!visti.add(id + "|" + t)) continue;
                    Trovato x = perContatto.get(id);
                    if (x == null) {
                        x = new Trovato();
                        x.nome = nome.trim().replaceAll("\\s+", " ");
                        String n = tel.get(id);
                        if (n != null) x.tel = n;
                        perContatto.put(id, x);
                    }
                    DataT dt = new DataT();
                    dt.tipo = t; dt.y = d[0]; dt.m = d[1]; dt.g = d[2];
                    x.date.add(dt);
                }
            } finally { c.close(); }
        }
        ArrayList<Trovato> out = new ArrayList<>(perContatto.values());
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
