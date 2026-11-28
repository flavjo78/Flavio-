package app.ricorrenze;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.TimePickerDialog;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.ContactsContract.CommonDataKinds.Phone;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import java.time.LocalDate;
import java.time.YearMonth;
import java.util.ArrayList;
import java.util.TreeSet;

/**
 * Aggiungi o modifica una scheda: nome, telefono, gruppo e note una volta sola,
 * e dentro tutte le date da ricordare (compleanno, onomastico, anniversario, altri eventi),
 * ognuna con i suoi promemoria.
 */
public class EditActivity extends Activity {
    static final int REQ_CONTATTO = 31;
    static final int[] ANTICIPI = {1, 2, 3, 5, 7, 14, 30};

    private Store s;
    private Evento orig, e;
    private boolean nuovo, pronto, modificato;

    private EditText nome, tel, note;
    private LinearLayout boxDate;
    private FlowLayout flGruppi;
    private ScrollView sc;
    private final ArrayList<Riga> righe = new ArrayList<>();

    /** Le viste di una data nel modulo. */
    private class Riga {
        Data d;
        LinearLayout card;
        TextView titolo, anteprima, promemoria, etichettaAnno;
        EditText anno, etichetta;
        Spinner spGiorno, spMese;
        ImageView togli;
    }

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        Ui.init(this);
        s = Store.get(this);
        Intent in = getIntent();
        orig = s.evento(in.getStringExtra("id"));
        nuovo = orig == null;
        if (nuovo) {
            e = new Evento();
            String g = in.getStringExtra("gruppo");
            e.gruppo = g != null && s.gruppo(g) != null ? g : "";
            Data d = nuovaData("compleanno");
            d.giorno = in.getIntExtra("giorno", d.giorno);
            d.mese = in.getIntExtra("mese", d.mese);
            e.date.add(d);
        } else {
            e = orig.copia();
        }
        costruisci();
        getWindow().getDecorView().post(new Runnable() { @Override public void run() { pronto = true; } });
        if (!nuovo && in.getBooleanExtra("nuovaData", false)) {
            getWindow().getDecorView().post(new Runnable() { @Override public void run() { scegliTipoNuovaData(); } });
        }
    }

    private Data nuovaData(String tipo) {
        Data d = new Data();
        LocalDate oggi = LocalDate.now();
        d.tipo = tipo;
        d.giorno = oggi.getDayOfMonth();
        d.mese = oggi.getMonthValue();
        d.avvisi = s.copiaAvvisiDefault();
        return d;
    }

    private void segna() { if (pronto) modificato = true; }

    // ------------------------------------------------------------------ interfaccia

    private void costruisci() {
        LinearLayout root = Ui.col(this);
        root.setBackgroundColor(Ui.BG);

        LinearLayout header = Ui.row(this);
        header.setBackgroundColor(Ui.HEADER);
        header.setPadding(Ui.dp(6), Ui.dp(8), Ui.dp(10), Ui.dp(8));
        ImageView chiudi = Ui.iconButton(this, R.drawable.ic_close, Ui.ON_HEADER);
        chiudi.setContentDescription("Chiudi");
        chiudi.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { onBackPressed(); } });
        header.addView(chiudi);
        TextView tit = Ui.text(this, nuovo ? "Aggiungi" : "Modifica", 20, Ui.ON_HEADER, true);
        tit.setPadding(Ui.dp(8), 0, 0, 0);
        header.addView(tit, Ui.lpW(1));
        TextView salvaTop = Ui.text(this, "Salva", 16, Ui.ON_HEADER, true);
        salvaTop.setPadding(Ui.dp(16), Ui.dp(10), Ui.dp(16), Ui.dp(10));
        salvaTop.setBackground(Ui.ripple(Ui.round(Ui.alpha(0xFFFFFFFF, 0.16f), 12), 12));
        salvaTop.setClickable(true);
        salvaTop.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { salva(); } });
        header.addView(salvaTop);
        root.addView(header, Ui.lp(Ui.MATCH, Ui.WRAP));

        sc = new ScrollView(this);
        LinearLayout f = Ui.col(this);
        f.setPadding(Ui.dp(16), Ui.dp(6), Ui.dp(16), Ui.dp(28));
        sc.addView(f);
        root.addView(sc, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));

        // Nome
        LinearLayout cNome = scheda(f, "Nome");
        nome = Ui.edit(this, "Es. Mario Rossi, oppure Natale");
        nome.setText(e.nome);
        nome.addTextChangedListener(osserva());
        cNome.addView(nome, Ui.lp(Ui.MATCH, Ui.WRAP));
        cNome.addView(Ui.text(this, "Una persona o un evento. Il nome si scrive una volta sola: sotto aggiungi tutte le sue date.", 12.5f, Ui.TEXT2, false),
                Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 8, 0, 0));

        // Date
        f.addView(Ui.sezione(this, "Date da ricordare"));
        boxDate = Ui.col(this);
        f.addView(boxDate, Ui.lp(Ui.MATCH, Ui.WRAP));
        for (Data d : e.date) aggiungiRiga(d);
        TextView altra = Ui.button(this, "＋  Aggiungi un'altra data (onomastico, anniversario…)", false);
        altra.setTextSize(14);
        altra.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { scegliTipoNuovaData(); } });
        f.addView(altra, Ui.lp(Ui.MATCH, Ui.WRAP));

        // Gruppo
        LinearLayout cGr = scheda(f, "Gruppo");
        flGruppi = new FlowLayout(this);
        cGr.addView(flGruppi);
        aggiornaGruppi();

        // Contatto
        LinearLayout cTel = scheda(f, "Contatto (facoltativo)");
        LinearLayout rt = Ui.row(this);
        tel = Ui.edit(this, "Numero di telefono");
        tel.setInputType(InputType.TYPE_CLASS_PHONE);
        tel.setText(e.telefono);
        tel.addTextChangedListener(osserva());
        rt.addView(tel, Ui.lpW(1));
        TextView rub = Ui.smallButton(this, R.drawable.ic_contact, "Rubrica", Ui.PRIMARY);
        rub.setMinHeight(Ui.dp(48));
        rub.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { scegliContatto(); } });
        rt.addView(rub, Ui.lpM(Ui.WRAP, Ui.WRAP, 8, 0, 0, 0));
        cTel.addView(rt, Ui.lp(Ui.MATCH, Ui.WRAP));
        cTel.addView(Ui.text(this, "Con il numero puoi chiamare o mandare gli auguri (WhatsApp o SMS) direttamente dalla notifica.", 12.5f, Ui.TEXT2, false),
                Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 8, 0, 0));

        // Note
        LinearLayout cNo = scheda(f, "Note (facoltative)");
        note = Ui.edit(this, "Es. idee regalo, taglia, cosa gli piace…");
        note.setSingleLine(false);
        note.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES);
        note.setMinLines(2);
        note.setGravity(Gravity.TOP | Gravity.START);
        note.setText(e.note);
        note.addTextChangedListener(osserva());
        cNo.addView(note, Ui.lp(Ui.MATCH, Ui.WRAP));

        TextView salva = Ui.button(this, "Salva", true);
        salva.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { salva(); } });
        f.addView(salva, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 20, 0, 0));
        if (!nuovo) {
            TextView el = Ui.text(this, "Elimina questa scheda", 15, Ui.DANGER, true);
            el.setGravity(Gravity.CENTER);
            el.setPadding(0, Ui.dp(14), 0, Ui.dp(14));
            Ui.touch(el, 12);
            el.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    Dialoghi.conferma(EditActivity.this, orig, new Runnable() { @Override public void run() { finish(); } });
                }
            });
            f.addView(el, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));
        }

        setContentView(root);
        Ui.insets(root, header, root);
        aggiornaTutte();
    }

    private LinearLayout scheda(LinearLayout parent, String titolo) {
        parent.addView(Ui.sezione(this, titolo));
        LinearLayout c = Ui.card(this);
        parent.addView(c, Ui.lp(Ui.MATCH, Ui.WRAP));
        return c;
    }

    private TextWatcher osserva() {
        return new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void onTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void afterTextChanged(Editable x) { segna(); }
        };
    }

    // ------------------------------------------------------------------ una data

    private Riga aggiungiRiga(final Data d) {
        final Riga r = new Riga();
        r.d = d;
        LinearLayout c = Ui.card(this);
        r.card = c;

        LinearLayout h = Ui.row(this);
        r.titolo = Ui.text(this, "", 16.5f, Ui.PRIMARY, true);
        r.titolo.setPadding(Ui.dp(12), Ui.dp(8), Ui.dp(12), Ui.dp(8));
        r.titolo.setBackground(Ui.ripple(Ui.round(Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.18f : 0.09f), 12), 12));
        r.titolo.setClickable(true);
        r.titolo.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { scegliTipo(r); } });
        h.addView(r.titolo, Ui.lp(Ui.WRAP, Ui.WRAP));
        h.addView(new View(this), Ui.lpW(1));
        r.togli = Ui.iconButton(this, R.drawable.ic_close, Ui.TEXT2);
        r.togli.setContentDescription("Togli questa data");
        r.togli.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { togli(r); } });
        h.addView(r.togli);
        c.addView(h, Ui.lp(Ui.MATCH, Ui.WRAP));

        r.etichetta = Ui.edit(this, "");
        r.etichetta.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_CAP_SENTENCES);
        r.etichetta.setText(d.etichetta);
        r.etichetta.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence x, int a, int b, int cc) { }
            @Override public void onTextChanged(CharSequence x, int a, int b, int cc) { }
            @Override public void afterTextChanged(Editable x) { d.etichetta = x.toString(); segna(); }
        });
        c.addView(r.etichetta, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

        if (d.mobile()) {
            TextView dm = Ui.text(this, "📅  " + Fmt.cap(d.descrizioneRegola()) + "\nLa data viene calcolata da sola ogni anno.", 15, Ui.TEXT, false);
            c.addView(dm, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));
        } else {
            LinearLayout rd = Ui.row(this);
            r.spGiorno = new Spinner(this, Spinner.MODE_DROPDOWN);
            r.spMese = new Spinner(this, Spinner.MODE_DROPDOWN);
            String[] mesi = new String[12];
            for (int i = 0; i < 12; i++) mesi[i] = Fmt.cap(Fmt.MESI[i]);
            ArrayAdapter<String> am = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, mesi);
            am.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
            r.spMese.setAdapter(am);
            r.spMese.setSelection(d.mese - 1);
            aggiornaGiorni(r);
            rd.addView(r.spGiorno, new LinearLayout.LayoutParams(0, Ui.dp(52), 0.36f));
            rd.addView(r.spMese, new LinearLayout.LayoutParams(0, Ui.dp(52), 0.64f));
            c.addView(rd, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 0));
            r.spMese.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
                @Override public void onItemSelected(AdapterView<?> p, View v, int pos, long id) {
                    if (d.mese != pos + 1) { d.mese = pos + 1; aggiornaGiorni(r); segna(); }
                    aggiornaAnteprima(r);
                }
                @Override public void onNothingSelected(AdapterView<?> p) { }
            });
            r.spGiorno.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
                @Override public void onItemSelected(AdapterView<?> p, View v, int pos, long id) {
                    if (d.giorno != pos + 1) { d.giorno = pos + 1; segna(); }
                    aggiornaAnteprima(r);
                }
                @Override public void onNothingSelected(AdapterView<?> p) { }
            });
        }

        r.etichettaAnno = Ui.text(this, "", 13, Ui.TEXT2, true);
        c.addView(r.etichettaAnno, Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 12, 0, 6));
        r.anno = Ui.edit(this, "Es. 1985 — lascia vuoto se non lo sai");
        r.anno.setInputType(InputType.TYPE_CLASS_NUMBER);
        if (d.anno > 0) r.anno.setText(String.valueOf(d.anno));
        r.anno.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence x, int a, int b, int cc) { }
            @Override public void onTextChanged(CharSequence x, int a, int b, int cc) { }
            @Override public void afterTextChanged(Editable x) {
                String v = x.toString().trim();
                d.anno = v.length() < 4 ? 0 : annoValido(v);
                aggiornaAnteprima(r);
                segna();
            }
        });
        c.addView(r.anno, Ui.lp(Ui.MATCH, Ui.WRAP));
        if (d.mobile()) { r.anno.setVisibility(View.GONE); r.etichettaAnno.setVisibility(View.GONE); }

        r.anteprima = Ui.text(this, "", 14.5f, Ui.PRIMARY, true);
        r.anteprima.setBackground(Ui.round(Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.16f : 0.08f), 12));
        r.anteprima.setPadding(Ui.dp(12), Ui.dp(10), Ui.dp(12), Ui.dp(10));
        c.addView(r.anteprima, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

        LinearLayout pr = Ui.row(this);
        pr.setBackground(Ui.roundStroke(Ui.SURFACE2, Ui.DIVIDER, 1, 12));
        pr.setPadding(Ui.dp(12), Ui.dp(10), Ui.dp(12), Ui.dp(10));
        pr.addView(Ui.icon(this, R.drawable.ic_bell, Ui.PRIMARY, 20));
        r.promemoria = Ui.text(this, "", 14, Ui.TEXT, false);
        r.promemoria.setPadding(Ui.dp(10), 0, Ui.dp(8), 0);
        pr.addView(r.promemoria, Ui.lpW(1));
        pr.addView(Ui.text(this, "Cambia", 14, Ui.PRIMARY, true));
        Ui.touch(pr, 12);
        pr.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { modificaPromemoria(r); } });
        c.addView(pr, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 10, 0, 0));

        boxDate.addView(c, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 12));
        righe.add(r);
        aggiornaRiga(r);
        return r;
    }

    private void aggiornaTutte() {
        for (Riga r : righe) aggiornaRiga(r);
        if (!righe.isEmpty()) {
            String t = righe.get(0).d.tipo;
            if ("festa".equals(t)) nome.setHint("Es. Natale, Festa della mamma…");
            else if ("altro".equals(t)) nome.setHint("Es. Bollo auto, oppure il nome di una persona");
            else if ("anniversario".equals(t)) nome.setHint("Es. Luca e Anna");
            else nome.setHint("Es. Mario Rossi");
        }
    }

    private void aggiornaRiga(Riga r) {
        Data d = r.d;
        r.titolo.setText(d.emoji() + "  " + d.tipoNome() + "  ▾");
        r.togli.setVisibility(righe.size() > 1 ? View.VISIBLE : View.INVISIBLE);
        if ("anniversario".equals(d.tipo)) {
            r.etichetta.setVisibility(View.VISIBLE);
            r.etichetta.setHint("Di cosa? Es. matrimonio (facoltativo)");
        } else if ("altro".equals(d.tipo)) {
            r.etichetta.setVisibility(View.VISIBLE);
            r.etichetta.setHint("Che evento? Es. Laurea, Primo appuntamento");
        } else if ("festa".equals(d.tipo) && righe.size() > 1) {
            r.etichetta.setVisibility(View.VISIBLE);
            r.etichetta.setHint("Quale festa? (facoltativo)");
        } else {
            r.etichetta.setVisibility(View.GONE);
        }
        if ("compleanno".equals(d.tipo)) r.etichettaAnno.setText("Anno di nascita (facoltativo, serve per l'età)");
        else if ("anniversario".equals(d.tipo)) r.etichettaAnno.setText("Anno (facoltativo, per contare gli anni)");
        else r.etichettaAnno.setText("Anno (facoltativo)");
        aggiornaAnteprima(r);
        aggiornaPromemoria(r);
    }

    private void aggiornaGiorni(Riga r) {
        Data d = r.d;
        int max = d.mese == 2 ? 29 : YearMonth.of(2024, d.mese).lengthOfMonth();
        if (d.giorno > max) d.giorno = max;
        String[] g = new String[max];
        for (int i = 0; i < max; i++) g[i] = String.valueOf(i + 1);
        ArrayAdapter<String> ad = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, g);
        ad.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        r.spGiorno.setAdapter(ad);
        r.spGiorno.setSelection(d.giorno - 1);
    }

    private int annoValido(String t) {
        t = t.trim();
        if (t.isEmpty()) return 0;
        try {
            int y = Integer.parseInt(t);
            if (y >= 1850 && y <= LocalDate.now().getYear()) return y;
        } catch (NumberFormatException ignored) { }
        return -1;
    }

    private void aggiornaAnteprima(Riga r) {
        if (r.anteprima == null) return;
        LocalDate oggi = LocalDate.now();
        Data t = r.d.copia();
        if (t.anno < 0) t.anno = 0;
        LocalDate occ = t.prossima(oggi);
        long g = t.giorniA(oggi);
        String testo = "👉  " + (g == 0 ? "È oggi! " : Fmt.quando(g) + " · ") + Fmt.dataAnno(occ);
        String eta = t.eta(occ);
        if (!eta.isEmpty()) testo += "\n      " + Fmt.cap(eta);
        if (r.d.anno < 0) testo += "\n⚠️  Anno non valido (es. 1985)";
        r.anteprima.setText(testo);
    }

    private void aggiornaPromemoria(Riga r) {
        r.promemoria.setText(Fmt.cap(Fmt.avvisi(r.d.avvisi)) + " · ore " + Fmt.ora(s.oraDi(r.d)));
    }

    private void scegliTipo(final Riga r) {
        String[] voci = new String[Data.TIPI.length];
        for (int i = 0; i < voci.length; i++) voci[i] = Data.TIPI_EMOJI[i] + "   " + Data.TIPI_NOME[i];
        new AlertDialog.Builder(this)
                .setTitle("Che tipo di data?")
                .setItems(voci, (dd, i) -> {
                    r.d.tipo = Data.TIPI[i];
                    aggiornaTutte();
                    segna();
                })
                .show();
    }

    private void scegliTipoNuovaData() {
        final String[] tipi = {"onomastico", "compleanno", "anniversario", "altro", "festa"};
        String[] voci = new String[tipi.length];
        for (int i = 0; i < tipi.length; i++) {
            int k = Data.tipoIdx(tipi[i]);
            voci[i] = Data.TIPI_EMOJI[k] + "   " + Data.TIPI_NOME[k];
        }
        new AlertDialog.Builder(this)
                .setTitle("Che data vuoi aggiungere?")
                .setItems(voci, (dd, i) -> {
                    Data d = nuovaData(tipi[i]);
                    e.date.add(d);
                    final Riga r = aggiungiRiga(d);
                    aggiornaTutte();
                    segna();
                    sc.post(new Runnable() {
                        @Override public void run() { sc.smoothScrollTo(0, boxDate.getTop() + r.card.getTop() - Ui.dp(12)); }
                    });
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    private void togli(final Riga r) {
        if (righe.size() <= 1) return;
        new AlertDialog.Builder(this)
                .setTitle("Togliere questa data?")
                .setMessage(r.d.emoji() + " " + r.d.nomeCompleto() + " — " + r.d.descrizioneData())
                .setPositiveButton("Togli", (dd, w) -> {
                    e.date.remove(r.d);
                    boxDate.removeView(r.card);
                    righe.remove(r);
                    aggiornaTutte();
                    segna();
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    // ------------------------------------------------------------------ promemoria di una data

    private void modificaPromemoria(final Riga r) {
        final TreeSet<Integer> av = new TreeSet<>();
        for (int x : r.d.avvisi) av.add(x);
        final int[] ora = {r.d.ora};

        LinearLayout v = Ui.col(this);
        v.setPadding(Ui.dp(22), Ui.dp(10), Ui.dp(22), 0);
        final TextView stesso = Ui.chip(this, "🔔  Il giorno stesso", av.contains(0));
        stesso.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View x) {
                if (av.contains(0)) av.remove(0); else av.add(0);
                Ui.setChip(stesso, av.contains(0));
            }
        });
        LinearLayout r0 = Ui.row(this);
        r0.addView(stesso);
        v.addView(r0);
        v.addView(Ui.text(this, "Avvisami anche prima:", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 14, 0, 8));
        final FlowLayout fl = new FlowLayout(this);
        v.addView(fl);
        riempiAnticipi(fl, av);

        v.addView(Ui.text(this, "Orario della notifica:", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 16, 0, 8));
        LinearLayout ro = Ui.row(this);
        final TextView oraBtn = Ui.smallButton(this, R.drawable.ic_clock, "", Ui.PRIMARY);
        oraBtn.setTextSize(17);
        oraBtn.setMinHeight(Ui.dp(46));
        final TextView reset = Ui.text(this, "", 13, Ui.TEXT2, false);
        reset.setPadding(Ui.dp(12), Ui.dp(8), Ui.dp(4), Ui.dp(8));
        final Runnable aggiornaOra = new Runnable() {
            @Override public void run() {
                oraBtn.setText(Fmt.ora(ora[0] >= 0 ? ora[0] : s.oraDefault));
                if (ora[0] < 0) {
                    reset.setText("orario predefinito\n(lo cambi nelle Impostazioni)");
                    reset.setTextColor(Ui.TEXT2);
                    reset.setClickable(false);
                } else {
                    reset.setText("↺  usa il predefinito (" + Fmt.ora(s.oraDefault) + ")");
                    reset.setTextColor(Ui.PRIMARY);
                    reset.setClickable(true);
                }
            }
        };
        oraBtn.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View x) {
                int o = ora[0] >= 0 ? ora[0] : s.oraDefault;
                new TimePickerDialog(EditActivity.this, (tp, hh, mm) -> {
                    ora[0] = hh * 60 + mm;
                    aggiornaOra.run();
                }, o / 60, o % 60, true).show();
            }
        });
        reset.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View x) { ora[0] = -1; aggiornaOra.run(); }
        });
        ro.addView(oraBtn);
        ro.addView(reset, Ui.lpW(1));
        v.addView(ro, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 8));
        aggiornaOra.run();

        ScrollView sv = new ScrollView(this);
        sv.addView(v);
        new AlertDialog.Builder(this)
                .setTitle("Promemoria — " + r.d.nomeCompleto())
                .setView(sv)
                .setPositiveButton("OK", (dd, w) -> {
                    int[] arr = new int[av.size()];
                    int i = 0;
                    for (int x : av) arr[i++] = x;
                    r.d.avvisi = arr;
                    r.d.ora = ora[0];
                    aggiornaPromemoria(r);
                    segna();
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    private void riempiAnticipi(final FlowLayout fl, final TreeSet<Integer> av) {
        fl.removeAllViews();
        TreeSet<Integer> valori = new TreeSet<>();
        for (int a : ANTICIPI) valori.add(a);
        for (int a : av) if (a > 0) valori.add(a);
        for (final int a : valori) {
            final TextView c = Ui.chip(this, a == 1 ? "1 giorno" : a + " giorni", av.contains(a));
            c.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    if (av.contains(a)) av.remove(a); else av.add(a);
                    Ui.setChip(c, av.contains(a));
                }
            });
            fl.addView(c);
        }
        TextView altro = Ui.chip(this, "＋  Altro", false);
        altro.setTextColor(Ui.PRIMARY);
        altro.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                final EditText n = Ui.edit(EditActivity.this, "Numero di giorni (1–365)");
                n.setInputType(InputType.TYPE_CLASS_NUMBER);
                LinearLayout box = Ui.col(EditActivity.this);
                box.setPadding(Ui.dp(22), Ui.dp(8), Ui.dp(22), 0);
                box.addView(n, Ui.lp(Ui.MATCH, Ui.WRAP));
                new AlertDialog.Builder(EditActivity.this)
                        .setTitle("Quanti giorni prima?")
                        .setView(box)
                        .setPositiveButton("Aggiungi", (d, w) -> {
                            try {
                                int g = Integer.parseInt(n.getText().toString().trim());
                                if (g >= 1 && g <= 365) { av.add(g); riempiAnticipi(fl, av); }
                                else Toast.makeText(EditActivity.this, "Scegli un numero da 1 a 365", Toast.LENGTH_SHORT).show();
                            } catch (NumberFormatException ex) {
                                Toast.makeText(EditActivity.this, "Scrivi un numero", Toast.LENGTH_SHORT).show();
                            }
                        })
                        .setNegativeButton("Annulla", null)
                        .show();
            }
        });
        fl.addView(altro);
    }

    // ------------------------------------------------------------------ gruppi

    private void aggiornaGruppi() {
        flGruppi.removeAllViews();
        aggiungiChipGruppo("Nessuno", "", 0);
        for (Gruppo g : s.gruppi) aggiungiChipGruppo(g.nome, g.id, g.colore);
        TextView nuovoG = Ui.chip(this, "＋  Nuovo gruppo", false);
        nuovoG.setTextColor(Ui.PRIMARY);
        nuovoG.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                Dialoghi.gruppo(EditActivity.this, null, new Dialoghi.Fatto() {
                    @Override public void fatto(String id) {
                        if (id != null) e.gruppo = id;
                        aggiornaGruppi();
                        segna();
                    }
                });
            }
        });
        flGruppi.addView(nuovoG);
    }

    private void aggiungiChipGruppo(String nome, final String id, int colore) {
        boolean sel = id.equals(e.gruppo == null ? "" : e.gruppo);
        TextView c = Ui.chip(this, nome, sel);
        if (colore != 0) {
            android.graphics.drawable.GradientDrawable dot = Ui.circle(sel ? Ui.ON_PRIMARY : colore);
            dot.setBounds(0, 0, Ui.dp(10), Ui.dp(10));
            c.setCompoundDrawablesRelative(dot, null, null, null);
            c.setCompoundDrawablePadding(Ui.dp(8));
        }
        c.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                e.gruppo = id;
                aggiornaGruppi();
                segna();
            }
        });
        flGruppi.addView(c);
    }

    // ------------------------------------------------------------------ rubrica

    private void scegliContatto() {
        try {
            startActivityForResult(new Intent(Intent.ACTION_PICK, Phone.CONTENT_URI), REQ_CONTATTO);
        } catch (ActivityNotFoundException ex) {
            Toast.makeText(this, "Rubrica non disponibile", Toast.LENGTH_SHORT).show();
        }
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        super.onActivityResult(req, res, data);
        if (req != REQ_CONTATTO || res != RESULT_OK || data == null || data.getData() == null) return;
        Uri u = data.getData();
        Cursor c = null;
        try {
            c = getContentResolver().query(u, new String[]{Phone.NUMBER, Phone.DISPLAY_NAME}, null, null, null);
            if (c != null && c.moveToFirst()) {
                String num = c.getString(0);
                String nm = c.getString(1);
                if (num != null) tel.setText(num);
                if (nm != null && nome.getText().toString().trim().isEmpty()) nome.setText(nm);
            }
        } catch (Exception ex) {
            Toast.makeText(this, "Non riesco a leggere il contatto", Toast.LENGTH_SHORT).show();
        } finally {
            if (c != null) c.close();
        }
    }

    // ------------------------------------------------------------------ salvataggio

    private void salva() {
        String n = nome.getText().toString().trim().replaceAll("\\s+", " ");
        if (n.isEmpty()) {
            nome.setError("Scrivi un nome");
            nome.requestFocus();
            return;
        }
        for (Riga r : righe) {
            if (r.anno.getVisibility() == View.VISIBLE) {
                int y = annoValido(r.anno.getText().toString());
                if (y < 0) {
                    r.anno.setError("Anno non valido");
                    r.anno.requestFocus();
                    return;
                }
                r.d.anno = y;
            }
            r.d.etichetta = r.etichetta.getVisibility() == View.VISIBLE ? r.etichetta.getText().toString().trim() : "";
        }
        e.nome = n;
        e.telefono = tel.getText().toString().trim();
        e.note = note.getText().toString().trim();

        final Evento altro = s.perNome(n, orig);
        if (altro != null) {
            new AlertDialog.Builder(this)
                    .setTitle("«" + altro.nome + "» esiste già")
                    .setMessage("Vuoi aggiungere queste date alla scheda che c'è già? Così il nome non è doppio e trovi tutto insieme.")
                    .setPositiveButton("Sì, uniscile", (d, w) -> {
                        altro.unisci(e);
                        if (!nuovo) s.eventi.remove(orig);
                        s.salva(this);
                        Toast.makeText(this, "Date aggiunte alla scheda di " + altro.nome + " ✓", Toast.LENGTH_LONG).show();
                        finish();
                    })
                    .setNegativeButton("No, tienile separate", (d, w) -> conferma())
                    .setNeutralButton("Annulla", null)
                    .show();
            return;
        }
        conferma();
    }

    private void conferma() {
        if (nuovo) s.eventi.add(e);
        else {
            int i = s.eventi.indexOf(orig);
            if (i >= 0) s.eventi.set(i, e); else s.eventi.add(e);
        }
        s.salva(this);
        String msg;
        if (e.date.size() > 1) msg = "Salvata ✓  " + e.date.size() + " date per " + e.nome;
        else {
            Data d = e.date.get(0);
            msg = d.avvisi.length == 0 ? "Salvata (senza promemoria)"
                    : "Salvata ✓  Ti avviserò " + Fmt.avvisi(d.avvisi) + ", alle " + Fmt.ora(s.oraDi(d));
        }
        Toast.makeText(this, msg, Toast.LENGTH_LONG).show();
        finish();
    }

    @Override
    public void onBackPressed() {
        if (!modificato) { super.onBackPressed(); return; }
        new AlertDialog.Builder(this)
                .setTitle("Uscire senza salvare?")
                .setMessage("Le modifiche andranno perse.")
                .setPositiveButton("Esci", (d, w) -> finish())
                .setNegativeButton("Continua", null)
                .setNeutralButton("Salva", (d, w) -> salva())
                .show();
    }
}
