package app.ricorrenze;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.NotificationManager;
import android.app.TimePickerDialog;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.util.TreeSet;

public class SettingsActivity extends Activity {
    static final int REQ_ESPORTA = 41, REQ_IMPORTA = 42;
    static final int[] ANTICIPI = {1, 2, 3, 5, 7, 14, 30};

    private Store s;
    private TextView oraBtn;
    private FlowLayout flAvvisi;
    private TextView chipStesso;
    private LinearLayout boxStato;
    private EditText testo, prefisso;
    private final TreeSet<Integer> avvisi = new TreeSet<>();

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        Ui.init(this);
        s = Store.get(this);
        for (int a : s.avvisiDefault) avvisi.add(a);
        costruisci();
    }

    @Override
    protected void onResume() {
        super.onResume();
        aggiornaStato();
    }

    @Override
    protected void onPause() {
        super.onPause();
        String t = testo.getText().toString().trim();
        String p = prefisso.getText().toString().replaceAll("[^0-9]", "");
        boolean cambia = !t.equals(s.testoAuguri) || !p.equals(s.prefisso);
        if (!t.isEmpty()) s.testoAuguri = t;
        if (!p.isEmpty()) s.prefisso = p;
        if (cambia) s.salva(this);
    }

    private void costruisci() {
        LinearLayout root = Ui.col(this);
        root.setBackgroundColor(Ui.BG);
        LinearLayout header = Ui.row(this);
        header.setBackgroundColor(Ui.HEADER);
        header.setPadding(Ui.dp(6), Ui.dp(8), Ui.dp(10), Ui.dp(8));
        ImageView indietro = Ui.iconButton(this, R.drawable.ic_left, Ui.ON_HEADER);
        indietro.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { finish(); } });
        header.addView(indietro);
        TextView tit = Ui.text(this, "Impostazioni", 20, Ui.ON_HEADER, true);
        tit.setPadding(Ui.dp(8), 0, 0, 0);
        header.addView(tit, Ui.lpW(1));
        root.addView(header, Ui.lp(Ui.MATCH, Ui.WRAP));

        ScrollView sc = new ScrollView(this);
        LinearLayout f = Ui.col(this);
        f.setPadding(Ui.dp(16), Ui.dp(6), Ui.dp(16), Ui.dp(30));
        sc.addView(f);
        root.addView(sc, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));

        // --- Notifiche predefinite
        LinearLayout c1 = scheda(f, "Notifiche");
        c1.addView(Ui.text(this, "Orario delle notifiche", 15, Ui.TEXT, true));
        c1.addView(Ui.text(this, "Vale per tutte le ricorrenze che non hanno un orario proprio.", 12.5f, Ui.TEXT2, false));
        oraBtn = Ui.smallButton(this, R.drawable.ic_clock, Fmt.ora(s.oraDefault), Ui.PRIMARY);
        oraBtn.setTextSize(17);
        oraBtn.setMinHeight(Ui.dp(46));
        oraBtn.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                new TimePickerDialog(SettingsActivity.this, (tp, h, m) -> {
                    s.oraDefault = h * 60 + m;
                    oraBtn.setText(Fmt.ora(s.oraDefault));
                    s.salva(SettingsActivity.this);
                    aggiornaStato();
                }, s.oraDefault / 60, s.oraDefault % 60, true).show();
            }
        });
        LinearLayout ro = Ui.row(this);
        ro.addView(oraBtn);
        c1.addView(ro, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));

        c1.addView(Ui.divider(this), Ui.lpM(Ui.MATCH, Math.max(1, Ui.dp(1)), 0, 14, 0, 14));
        c1.addView(Ui.text(this, "Promemoria per le nuove ricorrenze", 15, Ui.TEXT, true));
        c1.addView(Ui.text(this, "Puoi sempre cambiarli per ogni singola ricorrenza.", 12.5f, Ui.TEXT2, false));
        chipStesso = Ui.chip(this, "🔔  Il giorno stesso", avvisi.contains(0));
        chipStesso.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (avvisi.contains(0)) avvisi.remove(0); else avvisi.add(0);
                Ui.setChip(chipStesso, avvisi.contains(0));
                salvaAvvisi();
            }
        });
        LinearLayout r0 = Ui.row(this);
        r0.addView(chipStesso);
        c1.addView(r0, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 10, 0, 0));
        c1.addView(Ui.text(this, "e anche prima:", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 12, 0, 8));
        flAvvisi = new FlowLayout(this);
        c1.addView(flAvvisi);
        aggiornaAvvisi();
        TextView applica = Ui.button(this, "Applica a tutte le ricorrenze esistenti", false);
        applica.setTextSize(14);
        applica.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                new AlertDialog.Builder(SettingsActivity.this)
                        .setTitle("Applicare a tutte?")
                        .setMessage("Tutte le " + s.eventi.size() + " ricorrenze avranno: " + Fmt.avvisi(s.avvisiDefault)
                                + ", alle " + Fmt.ora(s.oraDefault) + ". Le impostazioni fatte a mano su singole ricorrenze verranno sostituite.")
                        .setPositiveButton("Applica", (d, w) -> {
                            for (Evento e : s.eventi) { e.avvisi = s.copiaAvvisiDefault(); e.ora = -1; }
                            s.salva(SettingsActivity.this);
                            Toast.makeText(SettingsActivity.this, "Fatto", Toast.LENGTH_SHORT).show();
                            aggiornaStato();
                        })
                        .setNegativeButton("Annulla", null)
                        .show();
            }
        });
        c1.addView(applica, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 16, 0, 0));

        // --- Stato notifiche
        LinearLayout c2 = scheda(f, "Controllo notifiche");
        boxStato = Ui.col(this);
        c2.addView(boxStato);
        TextView prova = Ui.button(this, "🔔  Manda una notifica di prova", true);
        prova.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                Scheduler.prova(SettingsActivity.this);
                Toast.makeText(SettingsActivity.this, "Inviata: guarda in alto nelle notifiche", Toast.LENGTH_SHORT).show();
            }
        });
        c2.addView(prova, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

        // --- Auguri
        LinearLayout c3 = scheda(f, "Messaggio di auguri");
        c3.addView(Ui.text(this, "Testo già pronto quando premi WhatsApp o SMS. {nome} diventa il nome della persona.", 12.5f, Ui.TEXT2, false));
        testo = Ui.edit(this, "Tanti auguri {nome}! 🎉");
        testo.setSingleLine(false);
        testo.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES);
        testo.setText(s.testoAuguri);
        c3.addView(testo, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));
        c3.addView(Ui.text(this, "Prefisso internazionale (per WhatsApp)", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 14, 0, 6));
        LinearLayout rp = Ui.row(this);
        rp.addView(Ui.text(this, "+", 18, Ui.TEXT, true), Ui.lpM(Ui.WRAP, Ui.WRAP, 2, 0, 6, 0));
        prefisso = Ui.edit(this, "39");
        prefisso.setInputType(InputType.TYPE_CLASS_NUMBER);
        prefisso.setText(s.prefisso);
        rp.addView(prefisso, Ui.lp(Ui.dp(110), Ui.WRAP));
        rp.addView(Ui.text(this, "  (Italia = 39). Usato se il numero non inizia con +", 12.5f, Ui.TEXT2, false), Ui.lpW(1));
        c3.addView(rp, Ui.lp(Ui.MATCH, Ui.WRAP));

        // --- Aggiungi velocemente
        LinearLayout c4 = scheda(f, "Aggiungi velocemente");
        TextView fe = Ui.button(this, "🎉  Festività (Natale, Pasqua, Festa della mamma…)", false);
        fe.setTextSize(14);
        fe.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { Festivita.scegli(SettingsActivity.this, null); } });
        c4.addView(fe, Ui.lp(Ui.MATCH, Ui.WRAP));
        TextView ru = Ui.button(this, "📇  Importa compleanni dalla rubrica", false);
        ru.setTextSize(14);
        ru.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { Rubrica.importa(SettingsActivity.this, null); } });
        c4.addView(ru, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));

        // --- Backup
        LinearLayout c5 = scheda(f, "Copia di sicurezza");
        c5.addView(Ui.text(this, "Salva tutte le ricorrenze in un file (ad esempio su Google Drive o inviandolo a te stesso). Ti servirà se cambi telefono.", 12.5f, Ui.TEXT2, false));
        LinearLayout rb = Ui.row(this);
        TextView esp = Ui.button(this, "Esporta", true);
        esp.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { esporta(); } });
        TextView imp = Ui.button(this, "Importa", false);
        imp.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { importa(); } });
        rb.addView(esp, new LinearLayout.LayoutParams(0, Ui.WRAP, 1f));
        LinearLayout.LayoutParams pi = new LinearLayout.LayoutParams(0, Ui.WRAP, 1f);
        pi.setMargins(Ui.dp(10), 0, 0, 0);
        rb.addView(imp, pi);
        c5.addView(rb, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

        TextView info = Ui.text(this, "Ricorrenze · versione " + versione() + "\nI dati restano solo su questo telefono.", 12.5f, Ui.TEXT2, false);
        info.setGravity(Gravity.CENTER);
        info.setPadding(0, Ui.dp(22), 0, 0);
        f.addView(info, Ui.lp(Ui.MATCH, Ui.WRAP));

        setContentView(root);
        Ui.insets(root, header, root);
    }

    private String versione() {
        try { return getPackageManager().getPackageInfo(getPackageName(), 0).versionName; }
        catch (Exception e) { return "1.0"; }
    }

    private LinearLayout scheda(LinearLayout parent, String titolo) {
        parent.addView(Ui.sezione(this, titolo));
        LinearLayout c = Ui.card(this);
        parent.addView(c, Ui.lp(Ui.MATCH, Ui.WRAP));
        return c;
    }

    private void aggiornaAvvisi() {
        flAvvisi.removeAllViews();
        TreeSet<Integer> valori = new TreeSet<>();
        for (int a : ANTICIPI) valori.add(a);
        for (int a : avvisi) if (a > 0) valori.add(a);
        for (final int a : valori) {
            final TextView c = Ui.chip(this, a == 1 ? "1 giorno" : a + " giorni", avvisi.contains(a));
            c.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    if (avvisi.contains(a)) avvisi.remove(a); else avvisi.add(a);
                    Ui.setChip(c, avvisi.contains(a));
                    salvaAvvisi();
                }
            });
            flAvvisi.addView(c);
        }
    }

    private void salvaAvvisi() {
        int[] v = new int[avvisi.size()];
        int i = 0;
        for (int a : avvisi) v[i++] = a;
        s.avvisiDefault = v;
        s.salva(this);
    }

    // ------------------------------------------------------------------ stato permessi

    private void aggiornaStato() {
        if (boxStato == null) return;
        boxStato.removeAllViews();
        NotificationManager nm = getSystemService(NotificationManager.class);
        boolean notif = nm.areNotificationsEnabled();
        rigaStato(notif, notif ? "Notifiche consentite" : "Notifiche bloccate",
                notif ? null : "Attiva", new Runnable() { @Override public void run() { MainActivity.attivaNotifiche(SettingsActivity.this); } });

        boolean esatte = Scheduler.sveglieEsatte(this);
        rigaStato(esatte, esatte ? "Avvisi all'orario esatto" : "Avvisi non precisi (permesso «Sveglie e promemoria» spento)",
                esatte ? null : "Consenti", new Runnable() {
                    @Override public void run() {
                        if (Build.VERSION.SDK_INT >= 31)
                            Azioni.avvia(SettingsActivity.this, new Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, Uri.parse("package:" + getPackageName())));
                    }
                });

        PowerManager pm = getSystemService(PowerManager.class);
        boolean batt = pm.isIgnoringBatteryOptimizations(getPackageName());
        rigaStato(batt, batt ? "Nessuna limitazione della batteria" : "Risparmio batteria attivo: su alcuni telefoni può ritardare gli avvisi",
                batt ? null : "Togli", new Runnable() {
                    @Override public void run() {
                        Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName()));
                        try { startActivity(i); }
                        catch (Exception ex) { Azioni.avvia(SettingsActivity.this, new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)); }
                    }
                });

        Scheduler.Promemoria p = Scheduler.prossimo(s, System.currentTimeMillis());
        String prox;
        if (p == null) prox = "Nessun promemoria in programma.";
        else {
            LocalDateTime dt = LocalDateTime.ofInstant(Instant.ofEpochMilli(p.quando), ZoneId.systemDefault());
            LocalDate d = dt.toLocalDate();
            String giorno = d.equals(LocalDate.now()) ? "oggi" : d.equals(LocalDate.now().plusDays(1)) ? "domani" : Fmt.dataLunga(d);
            prox = "Prossima notifica: " + giorno + " alle " + Fmt.ora(dt.getHour() * 60 + dt.getMinute())
                    + "\n" + p.ev.emoji() + " " + p.ev.titolo() + " (" + Fmt.anticipo(p.anticipo) + ")";
        }
        TextView t = Ui.text(this, prox, 13.5f, Ui.TEXT, false);
        t.setBackground(Ui.round(Ui.SURFACE2, 12));
        t.setPadding(Ui.dp(12), Ui.dp(10), Ui.dp(12), Ui.dp(10));
        boxStato.addView(t, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));
    }

    private void rigaStato(boolean ok, String testo, String azione, final Runnable r) {
        LinearLayout row = Ui.row(this);
        row.setPadding(0, Ui.dp(6), 0, Ui.dp(6));
        TextView ic = Ui.text(this, ok ? "✅" : "⚠️", 16, Ui.TEXT, false);
        row.addView(ic, Ui.lpM(Ui.WRAP, Ui.WRAP, 0, 0, 10, 0));
        row.addView(Ui.text(this, testo, 14, ok ? Ui.TEXT : Ui.WARN, !ok), Ui.lpW(1));
        if (azione != null) {
            TextView b = Ui.button(this, azione, false);
            b.setTextSize(14);
            b.setMinHeight(Ui.dp(40));
            b.setPadding(Ui.dp(14), Ui.dp(8), Ui.dp(14), Ui.dp(8));
            b.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { r.run(); } });
            row.addView(b, Ui.lpM(Ui.WRAP, Ui.WRAP, 8, 0, 0, 0));
        }
        boxStato.addView(row, Ui.lp(Ui.MATCH, Ui.WRAP));
    }

    // ------------------------------------------------------------------ backup

    private void esporta() {
        Intent i = new Intent(Intent.ACTION_CREATE_DOCUMENT);
        i.addCategory(Intent.CATEGORY_OPENABLE);
        i.setType("application/json");
        i.putExtra(Intent.EXTRA_TITLE, "ricorrenze-" + LocalDate.now() + ".json");
        try { startActivityForResult(i, REQ_ESPORTA); }
        catch (Exception ex) { Toast.makeText(this, "Impossibile aprire la scelta del file", Toast.LENGTH_SHORT).show(); }
    }

    private void importa() {
        Intent i = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        i.addCategory(Intent.CATEGORY_OPENABLE);
        i.setType("*/*");
        try { startActivityForResult(i, REQ_IMPORTA); }
        catch (Exception ex) { Toast.makeText(this, "Impossibile aprire la scelta del file", Toast.LENGTH_SHORT).show(); }
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        super.onActivityResult(req, res, data);
        if (res != RESULT_OK || data == null || data.getData() == null) return;
        Uri u = data.getData();
        if (req == REQ_ESPORTA) {
            try {
                OutputStream out = getContentResolver().openOutputStream(u, "wt");
                if (out == null) throw new Exception();
                out.write(s.toJson().toString(1).getBytes(StandardCharsets.UTF_8));
                out.close();
                Toast.makeText(this, "Copia salvata: " + s.eventi.size() + " ricorrenze", Toast.LENGTH_LONG).show();
            } catch (Exception ex) {
                Toast.makeText(this, "Salvataggio non riuscito", Toast.LENGTH_LONG).show();
            }
        } else if (req == REQ_IMPORTA) {
            final JSONObject o;
            try {
                InputStream in = getContentResolver().openInputStream(u);
                if (in == null) throw new Exception();
                String txt = Store.leggi(in);
                in.close();
                o = new JSONObject(txt);
                if (o.optJSONArray("eventi") == null) throw new Exception();
            } catch (Exception ex) {
                Toast.makeText(this, "Questo file non è una copia di Ricorrenze", Toast.LENGTH_LONG).show();
                return;
            }
            int n = o.optJSONArray("eventi").length();
            new AlertDialog.Builder(this)
                    .setTitle("Importare " + n + " ricorrenze?")
                    .setMessage("«Unisci» le aggiunge a quelle che hai già. «Sostituisci» cancella quelle attuali e mette solo quelle del file.")
                    .setPositiveButton("Unisci", (d, w) -> carica(o, true))
                    .setNeutralButton("Sostituisci", (d, w) -> carica(o, false))
                    .setNegativeButton("Annulla", null)
                    .show();
        }
    }

    private void carica(JSONObject o, boolean unisci) {
        try {
            s.fromJson(o, unisci);
            s.salva(this);
            Toast.makeText(this, "Importazione completata: ora hai " + s.eventi.size() + " ricorrenze", Toast.LENGTH_LONG).show();
            // ricarica i campi
            avvisi.clear();
            for (int a : s.avvisiDefault) avvisi.add(a);
            Ui.setChip(chipStesso, avvisi.contains(0));
            aggiornaAvvisi();
            oraBtn.setText(Fmt.ora(s.oraDefault));
            testo.setText(s.testoAuguri);
            prefisso.setText(s.prefisso);
            aggiornaStato();
        } catch (Exception ex) {
            Toast.makeText(this, "Importazione non riuscita", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        super.onRequestPermissionsResult(req, perms, res);
        if (req == Rubrica.REQ) Rubrica.dopoPermesso(this, res, null);
        aggiornaStato();
    }
}
