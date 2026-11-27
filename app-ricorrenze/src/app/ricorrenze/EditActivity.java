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

/** Aggiungi o modifica una ricorrenza. */
public class EditActivity extends Activity {
    static final int REQ_CONTATTO = 31;
    static final int[] ANTICIPI = {1, 2, 3, 5, 7, 14, 30};

    private Store s;
    private Evento orig, e;
    private boolean nuovo, pronto, modificato;

    private final TextView[] chipTipo = new TextView[Evento.TIPI.length];
    private EditText nome, anno, tel, note;
    private Spinner spGiorno, spMese;
    private TextView anteprima, etichettaAnno, oraBtn, oraReset, chipStesso;
    private FlowLayout flGruppi, flAvvisi;
    private final TreeSet<Integer> avvisi = new TreeSet<>();

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
            LocalDate oggi = LocalDate.now();
            e.giorno = in.getIntExtra("giorno", oggi.getDayOfMonth());
            e.mese = in.getIntExtra("mese", oggi.getMonthValue());
            String g = in.getStringExtra("gruppo");
            e.gruppo = g != null && s.gruppo(g) != null ? g : "";
            e.avvisi = s.copiaAvvisiDefault();
        } else {
            e = orig.copia();
        }
        for (int a : e.avvisi) avvisi.add(a);
        costruisci();
        getWindow().getDecorView().post(new Runnable() { @Override public void run() { pronto = true; } });
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
        TextView tit = Ui.text(this, nuovo ? "Nuova ricorrenza" : "Modifica", 20, Ui.ON_HEADER, true);
        tit.setPadding(Ui.dp(8), 0, 0, 0);
        header.addView(tit, Ui.lpW(1));
        TextView salvaTop = Ui.text(this, "Salva", 16, Ui.ON_HEADER, true);
        salvaTop.setPadding(Ui.dp(16), Ui.dp(10), Ui.dp(16), Ui.dp(10));
        salvaTop.setBackground(Ui.ripple(Ui.round(Ui.alpha(0xFFFFFFFF, 0.16f), 12), 12));
        salvaTop.setClickable(true);
        salvaTop.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { salva(); } });
        header.addView(salvaTop);
        root.addView(header, Ui.lp(Ui.MATCH, Ui.WRAP));

        ScrollView sc = new ScrollView(this);
        LinearLayout f = Ui.col(this);
        f.setPadding(Ui.dp(16), Ui.dp(6), Ui.dp(16), Ui.dp(28));
        sc.addView(f);
        root.addView(sc, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));

        // Tipo
        LinearLayout cTipo = scheda(f, "Che cosa ricordare");
        FlowLayout flTipo = new FlowLayout(this);
        for (int i = 0; i < Evento.TIPI.length; i++) {
            final int k = i;
            TextView c = Ui.chip(this, Evento.TIPI_EMOJI[i] + "  " + Evento.TIPI_NOME[i], Evento.TIPI[i].equals(e.tipo));
            c.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    e.tipo = Evento.TIPI[k];
                    for (int j = 0; j < chipTipo.length; j++) Ui.setChip(chipTipo[j], j == k);
                    aggiornaTesti();
                    segna();
                }
            });
            chipTipo[i] = c;
            flTipo.addView(c);
        }
        cTipo.addView(flTipo);

        // Nome
        LinearLayout cNome = scheda(f, "Nome");
        nome = Ui.edit(this, "");
        nome.setText(e.nome);
        nome.addTextChangedListener(osserva());
        cNome.addView(nome, Ui.lp(Ui.MATCH, Ui.WRAP));

        // Data
        LinearLayout cData = scheda(f, "Data");
        if (e.mobile()) {
            TextView dm = Ui.text(this, "📅  " + Fmt.cap(e.descrizioneRegola()) + "\nLa data viene calcolata da sola ogni anno.", 15, Ui.TEXT, false);
            cData.addView(dm);
        } else {
            LinearLayout r = Ui.row(this);
            spGiorno = new Spinner(this, Spinner.MODE_DROPDOWN);
            spMese = new Spinner(this, Spinner.MODE_DROPDOWN);
            String[] mesi = new String[12];
            for (int i = 0; i < 12; i++) mesi[i] = Fmt.cap(Fmt.MESI[i]);
            ArrayAdapter<String> am = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, mesi);
            am.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
            spMese.setAdapter(am);
            spMese.setSelection(e.mese - 1);
            aggiornaGiorni();
            r.addView(spGiorno, new LinearLayout.LayoutParams(0, Ui.dp(52), 0.36f));
            r.addView(spMese, new LinearLayout.LayoutParams(0, Ui.dp(52), 0.64f));
            cData.addView(r, Ui.lp(Ui.MATCH, Ui.WRAP));
            spMese.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
                @Override public void onItemSelected(AdapterView<?> p, View v, int pos, long id) {
                    if (e.mese != pos + 1) { e.mese = pos + 1; aggiornaGiorni(); segna(); }
                    aggiornaAnteprima();
                }
                @Override public void onNothingSelected(AdapterView<?> p) { }
            });
            spGiorno.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
                @Override public void onItemSelected(AdapterView<?> p, View v, int pos, long id) {
                    if (e.giorno != pos + 1) { e.giorno = pos + 1; segna(); }
                    aggiornaAnteprima();
                }
                @Override public void onNothingSelected(AdapterView<?> p) { }
            });
        }
        etichettaAnno = Ui.text(this, "", 13, Ui.TEXT2, true);
        cData.addView(etichettaAnno, Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 14, 0, 6));
        anno = Ui.edit(this, "Es. 1985 — lascia vuoto se non lo sai");
        anno.setInputType(InputType.TYPE_CLASS_NUMBER);
        if (e.anno > 0) anno.setText(String.valueOf(e.anno));
        anno.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void onTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void afterTextChanged(Editable x) {
                String v = x.toString().trim();
                e.anno = v.length() < 4 ? 0 : annoValido(v);
                aggiornaAnteprima();
                segna();
            }
        });
        if (e.mobile()) { anno.setVisibility(View.GONE); etichettaAnno.setVisibility(View.GONE); }
        cData.addView(anno, Ui.lp(Ui.MATCH, Ui.WRAP));
        anteprima = Ui.text(this, "", 14.5f, Ui.PRIMARY, true);
        anteprima.setBackground(Ui.round(Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.16f : 0.08f), 12));
        anteprima.setPadding(Ui.dp(12), Ui.dp(10), Ui.dp(12), Ui.dp(10));
        cData.addView(anteprima, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

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

        // Promemoria
        LinearLayout cPr = scheda(f, "Promemoria");
        chipStesso = Ui.chip(this, "🔔  Il giorno stesso", avvisi.contains(0));
        chipStesso.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (avvisi.contains(0)) avvisi.remove(0); else avvisi.add(0);
                Ui.setChip(chipStesso, avvisi.contains(0));
                segna();
            }
        });
        LinearLayout r0 = Ui.row(this);
        r0.addView(chipStesso);
        cPr.addView(r0);
        cPr.addView(Ui.text(this, "Avvisami anche prima:", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 14, 0, 8));
        flAvvisi = new FlowLayout(this);
        cPr.addView(flAvvisi);
        aggiornaAvvisi();

        cPr.addView(Ui.text(this, "Orario della notifica:", 13, Ui.TEXT2, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 2, 16, 0, 8));
        LinearLayout ro = Ui.row(this);
        oraBtn = Ui.smallButton(this, R.drawable.ic_clock, "", Ui.PRIMARY);
        oraBtn.setTextSize(17);
        oraBtn.setMinHeight(Ui.dp(46));
        oraBtn.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { scegliOra(); } });
        ro.addView(oraBtn);
        oraReset = Ui.text(this, "", 13, Ui.TEXT2, false);
        oraReset.setPadding(Ui.dp(12), Ui.dp(8), Ui.dp(4), Ui.dp(8));
        oraReset.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (e.ora >= 0) { e.ora = -1; aggiornaOra(); segna(); }
            }
        });
        ro.addView(oraReset, Ui.lpW(1));
        cPr.addView(ro, Ui.lp(Ui.MATCH, Ui.WRAP));
        aggiornaOra();

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
            TextView el = Ui.text(this, "Elimina questa ricorrenza", 15, Ui.DANGER, true);
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
        aggiornaTesti();
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

    private void aggiornaTesti() {
        String t = e.tipo;
        if ("compleanno".equals(t) || "onomastico".equals(t)) nome.setHint("Es. Mario Rossi");
        else if ("anniversario".equals(t)) nome.setHint("Es. Matrimonio Luca e Anna");
        else if ("festa".equals(t)) nome.setHint("Es. Natale, Festa della mamma…");
        else nome.setHint("Es. Scadenza bollo auto");
        if ("compleanno".equals(t)) etichettaAnno.setText("Anno di nascita (facoltativo, serve per l'età)");
        else if ("anniversario".equals(t)) etichettaAnno.setText("Anno (facoltativo, per contare gli anni)");
        else etichettaAnno.setText("Anno (facoltativo)");
        aggiornaAnteprima();
    }

    private void aggiornaGiorni() {
        int max = e.mese == 2 ? 29 : YearMonth.of(2024, e.mese).lengthOfMonth();
        if (e.giorno > max) e.giorno = max;
        String[] g = new String[max];
        for (int i = 0; i < max; i++) g[i] = String.valueOf(i + 1);
        ArrayAdapter<String> ad = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, g);
        ad.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        spGiorno.setAdapter(ad);
        spGiorno.setSelection(e.giorno - 1);
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

    private void aggiornaAnteprima() {
        if (anteprima == null) return;
        LocalDate oggi = LocalDate.now();
        Evento t = e.copia();
        if (t.anno < 0) t.anno = 0;
        LocalDate occ = t.prossima(oggi);
        long g = t.giorniA(oggi);
        String testo = "👉  " + (g == 0 ? "È oggi! " : Fmt.quando(g) + " · ") + Fmt.dataAnno(occ);
        String eta = t.eta(occ);
        if (!eta.isEmpty()) testo += "\n      " + Fmt.cap(eta);
        if (e.anno < 0) testo += "\n⚠️  Anno non valido (es. 1985)";
        anteprima.setText(testo);
    }

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
                    segna();
                }
            });
            flAvvisi.addView(c);
        }
        TextView altro = Ui.chip(this, "＋  Altro", false);
        altro.setTextColor(Ui.PRIMARY);
        altro.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { altroAnticipo(); } });
        flAvvisi.addView(altro);
    }

    private void altroAnticipo() {
        final EditText n = Ui.edit(this, "Numero di giorni (1–365)");
        n.setInputType(InputType.TYPE_CLASS_NUMBER);
        LinearLayout box = Ui.col(this);
        box.setPadding(Ui.dp(22), Ui.dp(8), Ui.dp(22), 0);
        box.addView(n, Ui.lp(Ui.MATCH, Ui.WRAP));
        new AlertDialog.Builder(this)
                .setTitle("Quanti giorni prima?")
                .setView(box)
                .setPositiveButton("Aggiungi", (d, w) -> {
                    try {
                        int g = Integer.parseInt(n.getText().toString().trim());
                        if (g >= 1 && g <= 365) { avvisi.add(g); aggiornaAvvisi(); segna(); }
                        else Toast.makeText(this, "Scegli un numero da 1 a 365", Toast.LENGTH_SHORT).show();
                    } catch (NumberFormatException ex) {
                        Toast.makeText(this, "Scrivi un numero", Toast.LENGTH_SHORT).show();
                    }
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    private void aggiornaOra() {
        int o = s.oraDi(e);
        oraBtn.setText(Fmt.ora(o));
        if (e.ora < 0) {
            oraReset.setText("orario predefinito\n(lo cambi nelle Impostazioni)");
            oraReset.setTextColor(Ui.TEXT2);
            oraReset.setClickable(false);
        } else {
            oraReset.setText("↺  usa il predefinito (" + Fmt.ora(s.oraDefault) + ")");
            oraReset.setTextColor(Ui.PRIMARY);
            oraReset.setClickable(true);
        }
    }

    private void scegliOra() {
        int o = s.oraDi(e);
        new TimePickerDialog(this, (v, h, m) -> {
            e.ora = h * 60 + m;
            aggiornaOra();
            segna();
        }, o / 60, o % 60, true).show();
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
        String n = nome.getText().toString().trim();
        if (n.isEmpty()) {
            nome.setError("Scrivi un nome");
            nome.requestFocus();
            return;
        }
        int y = annoValido(anno.getText().toString());
        if (y < 0) {
            anno.setError("Anno non valido");
            anno.requestFocus();
            return;
        }
        e.nome = n;
        e.anno = y;
        e.telefono = tel.getText().toString().trim();
        e.note = note.getText().toString().trim();
        ArrayList<Integer> l = new ArrayList<>(avvisi);
        e.avvisi = new int[l.size()];
        for (int i = 0; i < l.size(); i++) e.avvisi[i] = l.get(i);

        if (nuovo) s.eventi.add(e);
        else {
            int i = s.eventi.indexOf(orig);
            if (i >= 0) s.eventi.set(i, e); else s.eventi.add(e);
        }
        s.salva(this);
        String msg = e.avvisi.length == 0 ? "Salvata (senza promemoria)"
                : "Salvata ✓  Ti avviserò " + Fmt.avvisi(e.avvisi) + ", alle " + Fmt.ora(s.oraDi(e));
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
