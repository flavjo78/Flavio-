package app.ricorrenze;

import android.Manifest;
import android.app.Activity;
import android.app.NotificationManager;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import java.time.LocalDate;
import java.time.YearMonth;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;

public class MainActivity extends Activity {
    static final int REQ_NOTIF = 7;

    private Store s;
    private FrameLayout contenuto;
    private final View[] pagine = new View[3];
    private final LinearLayout[] tabs = new LinearLayout[3];
    private int tab = 0;
    private TextView sottotitolo;
    private View fab;

    // Prossimi
    private String filtro = null; // null = tutti, "" = senza gruppo, altrimenti id gruppo
    private String cerca = "";
    private LinearLayout lista, chips;
    private TextView banner;
    private EditText campoCerca;

    // Calendario
    private YearMonth mese = YearMonth.now();
    private LocalDate giornoSel = LocalDate.now();
    private MonthView mv;
    private TextView meseTitolo;
    private LinearLayout listaGiorno;

    // Gruppi
    private LinearLayout listaGruppi;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        Ui.init(this);
        s = Store.get(this);
        Scheduler.canale(this);
        costruisci();
        chiediPermessoNotifiche();
        Scheduler.controlla(this);
        apriDaIntent(getIntent());
    }

    @Override
    protected void onNewIntent(Intent i) {
        super.onNewIntent(i);
        setIntent(i);
        apriDaIntent(i);
    }

    @Override
    protected void onResume() {
        super.onResume();
        aggiorna();
    }

    @Override
    public void onConfigurationChanged(Configuration c) {
        super.onConfigurationChanged(c);
        if (mv != null) mv.requestLayout();
    }

    @Override
    public void onBackPressed() {
        if (tab != 0) { mostraTab(0); return; }
        if (filtro != null || !cerca.isEmpty()) {
            filtro = null;
            campoCerca.setText("");
            aggiorna();
            return;
        }
        super.onBackPressed();
    }

    private void apriDaIntent(Intent i) {
        if (i == null) return;
        String id = i.getStringExtra("evento");
        if (id == null) return;
        i.removeExtra("evento");
        final Evento e = s.evento(id);
        if (e != null) {
            mostraTab(0);
            Dialoghi.dettaglio(this, e, new Runnable() { @Override public void run() { aggiorna(); } });
        }
    }

    // ================================================================== struttura

    private void costruisci() {
        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Ui.BG);
        LinearLayout colonna = Ui.col(this);
        colonna.setFocusableInTouchMode(true);
        root.addView(colonna, new FrameLayout.LayoutParams(Ui.MATCH, Ui.MATCH));

        // Intestazione
        LinearLayout header = Ui.row(this);
        header.setBackgroundColor(Ui.HEADER);
        header.setPadding(Ui.dp(20), Ui.dp(14), Ui.dp(8), Ui.dp(14));
        LinearLayout tit = Ui.col(this);
        tit.addView(Ui.text(this, "Ricorrenze", 24, Ui.ON_HEADER, true));
        sottotitolo = Ui.text(this, "", 14, Ui.alpha(Ui.ON_HEADER, 0.8f), false);
        tit.addView(sottotitolo);
        header.addView(tit, Ui.lpW(1));
        ImageView imp = Ui.iconButton(this, R.drawable.ic_settings, Ui.ON_HEADER);
        imp.setContentDescription("Impostazioni");
        imp.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { startActivity(new Intent(MainActivity.this, SettingsActivity.class)); }
        });
        header.addView(imp);
        colonna.addView(header, Ui.lp(Ui.MATCH, Ui.WRAP));

        contenuto = new FrameLayout(this);
        colonna.addView(contenuto, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));

        // Barra in basso
        LinearLayout barra = Ui.row(this);
        barra.setBackgroundColor(Ui.SURFACE);
        barra.setElevation(Ui.dp(10));
        barra.setPadding(0, Ui.dp(6), 0, Ui.dp(6));
        String[] nomi = {"Prossimi", "Calendario", "Gruppi"};
        int[] icone = {R.drawable.ic_cake, R.drawable.ic_event, R.drawable.ic_people};
        for (int i = 0; i < 3; i++) {
            final int k = i;
            LinearLayout t = Ui.col(this);
            t.setGravity(Gravity.CENTER);
            t.setPadding(0, Ui.dp(4), 0, Ui.dp(4));
            FrameLayout pill = new FrameLayout(this);
            ImageView ic = Ui.icon(this, icone[i], Ui.TEXT2, 24);
            pill.addView(ic, new FrameLayout.LayoutParams(Ui.dp(24), Ui.dp(24), Gravity.CENTER));
            t.addView(pill, new LinearLayout.LayoutParams(Ui.dp(64), Ui.dp(32)));
            TextView lab = Ui.text(this, nomi[i], 12, Ui.TEXT2, true);
            lab.setPadding(0, Ui.dp(3), 0, 0);
            lab.setGravity(Gravity.CENTER);
            t.addView(lab);
            t.setClickable(true);
            t.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { mostraTab(k); } });
            tabs[i] = t;
            barra.addView(t, Ui.lpW(1));
        }
        colonna.addView(barra, Ui.lp(Ui.MATCH, Ui.WRAP));

        pagine[0] = paginaProssimi();
        pagine[1] = paginaCalendario();
        pagine[2] = paginaGruppi();
        for (View p : pagine) contenuto.addView(p, new FrameLayout.LayoutParams(Ui.MATCH, Ui.MATCH));

        // Pulsante "+"
        FrameLayout f = new FrameLayout(this);
        f.setBackground(Ui.ripple(Ui.round(Ui.PRIMARY, 20), 20));
        f.setElevation(Ui.dp(6));
        f.setClickable(true);
        f.setContentDescription("Aggiungi");
        f.addView(Ui.icon(this, R.drawable.ic_add, Ui.ON_PRIMARY, 28),
                new FrameLayout.LayoutParams(Ui.dp(28), Ui.dp(28), Gravity.CENTER));
        FrameLayout.LayoutParams fp = new FrameLayout.LayoutParams(Ui.dp(60), Ui.dp(60), Gravity.BOTTOM | Gravity.END);
        fp.setMargins(0, 0, Ui.dp(18), Ui.dp(18));
        f.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (tab == 2) {
                    Dialoghi.gruppo(MainActivity.this, null, new Dialoghi.Fatto() { @Override public void fatto(String id) { aggiorna(); } });
                } else {
                    Intent i = new Intent(MainActivity.this, EditActivity.class);
                    if (tab == 1 && giornoSel != null) {
                        i.putExtra("giorno", giornoSel.getDayOfMonth());
                        i.putExtra("mese", giornoSel.getMonthValue());
                    }
                    if (tab == 0 && filtro != null && !filtro.isEmpty()) i.putExtra("gruppo", filtro);
                    startActivity(i);
                }
            }
        });
        fab = f;
        contenuto.addView(f, fp);

        setContentView(root);
        Ui.insets(root, header, barra);
        mostraTab(0);
    }

    private void mostraTab(int k) {
        tab = k;
        for (int i = 0; i < 3; i++) {
            pagine[i].setVisibility(i == k ? View.VISIBLE : View.GONE);
            LinearLayout t = tabs[i];
            FrameLayout pill = (FrameLayout) t.getChildAt(0);
            ImageView ic = (ImageView) pill.getChildAt(0);
            TextView lab = (TextView) t.getChildAt(1);
            boolean on = i == k;
            pill.setBackground(on ? Ui.round(Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.25f : 0.14f), 16) : null);
            ic.getDrawable().setTint(on ? Ui.PRIMARY : Ui.TEXT2);
            lab.setTextColor(on ? Ui.PRIMARY : Ui.TEXT2);
        }
        if (k != 0 && campoCerca != null) {
            InputMethodManager imm = getSystemService(InputMethodManager.class);
            imm.hideSoftInputFromWindow(campoCerca.getWindowToken(), 0);
            campoCerca.clearFocus();
        }
        aggiorna();
    }

    private void aggiorna() {
        LocalDate oggi = LocalDate.now();
        String sot = Fmt.cap(Fmt.dataLunga(oggi));
        int nOggi = 0;
        for (Evento e : s.eventi) if (e.giorniA(oggi) == 0) nOggi++;
        if (nOggi == 1) sot += " · 1 ricorrenza oggi 🎉";
        else if (nOggi > 1) sot += " · " + nOggi + " ricorrenze oggi 🎉";
        sottotitolo.setText(sot);
        if (tab == 0) aggiornaProssimi();
        else if (tab == 1) aggiornaCalendario();
        else aggiornaGruppi();
    }

    // ================================================================== Prossimi

    private View paginaProssimi() {
        LinearLayout pag = Ui.col(this);

        banner = Ui.text(this, "", 14, Ui.TEXT, true);
        banner.setBackground(Ui.ripple(Ui.round(Ui.alpha(Ui.WARN, Ui.dark ? 0.22f : 0.16f), 14), 14));
        banner.setPadding(Ui.dp(14), Ui.dp(12), Ui.dp(14), Ui.dp(12));
        banner.setClickable(true);
        banner.setVisibility(View.GONE);
        pag.addView(banner, Ui.lpM(Ui.MATCH, Ui.WRAP, 16, 12, 16, 0));

        campoCerca = Ui.edit(this, "Cerca un nome…");
        campoCerca.setBackground(Ui.round(Ui.SURFACE, 14));
        campoCerca.setElevation(Ui.dark ? 0 : Ui.dp(1));
        campoCerca.setCompoundDrawablesRelative(Ui.tinted(this, R.drawable.ic_search, Ui.TEXT2, 20), null, null, null);
        campoCerca.setCompoundDrawablePadding(Ui.dp(10));
        campoCerca.setImeOptions(EditorInfo.IME_ACTION_SEARCH);
        campoCerca.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void onTextChanged(CharSequence x, int a, int b, int c) { }
            @Override public void afterTextChanged(Editable x) {
                cerca = x.toString().trim().toLowerCase();
                aggiornaLista();
            }
        });
        pag.addView(campoCerca, Ui.lpM(Ui.MATCH, Ui.WRAP, 16, 12, 16, 0));

        HorizontalScrollView hs = new HorizontalScrollView(this);
        hs.setHorizontalScrollBarEnabled(false);
        chips = Ui.row(this);
        chips.setPadding(Ui.dp(16), Ui.dp(10), Ui.dp(16), Ui.dp(4));
        hs.addView(chips);
        pag.addView(hs, Ui.lp(Ui.MATCH, Ui.WRAP));

        ScrollView sc = new ScrollView(this);
        sc.setClipToPadding(false);
        lista = Ui.col(this);
        lista.setPadding(Ui.dp(16), 0, Ui.dp(16), Ui.dp(100));
        sc.addView(lista);
        pag.addView(sc, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));
        return pag;
    }

    private void aggiornaProssimi() {
        aggiornaBanner();
        aggiornaChips();
        aggiornaLista();
    }

    private void aggiornaBanner() {
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (!nm.areNotificationsEnabled()) {
            banner.setText("🔕  Le notifiche sono spente: senza, l'app non può avvisarti. Tocca qui per attivarle.");
            banner.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { attivaNotifiche(MainActivity.this); } });
            banner.setVisibility(View.VISIBLE);
        } else if (!Scheduler.sveglieEsatte(this)) {
            banner.setText("⏰  Consenti «Sveglie e promemoria» per ricevere gli avvisi all'orario esatto. Tocca qui.");
            banner.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    if (Build.VERSION.SDK_INT >= 31) {
                        Azioni.avvia(MainActivity.this, new Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM,
                                Uri.parse("package:" + getPackageName())));
                    }
                }
            });
            banner.setVisibility(View.VISIBLE);
        } else {
            banner.setVisibility(View.GONE);
        }
    }

    static void attivaNotifiche(Activity a) {
        if (Build.VERSION.SDK_INT >= 33
                && a.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
                && a.shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)) {
            a.requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIF);
            return;
        }
        Intent i = new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(Settings.EXTRA_APP_PACKAGE, a.getPackageName());
        Azioni.avvia(a, i);
    }

    private void aggiornaChips() {
        chips.removeAllViews();
        if (filtro != null && !filtro.isEmpty() && s.gruppo(filtro) == null) filtro = null;
        aggiungiChip("Tutti", null, 0);
        for (Gruppo g : s.gruppi) aggiungiChip(g.nome, g.id, g.colore);
        if (s.conta("") > 0) aggiungiChip("Senza gruppo", "", 0xFF9A93B5);
    }

    private void aggiungiChip(String nome, final String id, int colore) {
        boolean sel = TextUtils.equals(filtro, id);
        TextView c = Ui.chip(this, nome, sel);
        if (colore != 0) {
            GradientDrawable dot = Ui.circle(sel ? Ui.ON_PRIMARY : colore);
            dot.setBounds(0, 0, Ui.dp(10), Ui.dp(10));
            c.setCompoundDrawablesRelative(dot, null, null, null);
            c.setCompoundDrawablePadding(Ui.dp(8));
        }
        c.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                filtro = id;
                aggiornaChips();
                aggiornaLista();
            }
        });
        chips.addView(c, Ui.lpM(Ui.WRAP, Ui.WRAP, 0, 0, 8, 0));
    }

    private boolean corrisponde(Evento e) {
        if (filtro != null && !filtro.equals(e.gruppo == null ? "" : e.gruppo)) return false;
        if (cerca.isEmpty()) return true;
        Gruppo g = s.gruppo(e.gruppo);
        String t = (e.nome + " " + e.note + " " + e.telefono + " " + e.tipoNome() + " " + (g != null ? g.nome : "")).toLowerCase();
        return t.contains(cerca);
    }

    private void aggiornaLista() {
        lista.removeAllViews();
        final LocalDate oggi = LocalDate.now();
        if (s.eventi.isEmpty()) {
            lista.addView(benvenuto());
            return;
        }
        ArrayList<Evento> l = new ArrayList<>();
        for (Evento e : s.eventi) if (corrisponde(e)) l.add(e);
        Collections.sort(l, new Comparator<Evento>() {
            @Override public int compare(Evento a, Evento b) {
                int c = Long.compare(a.giorniA(oggi), b.giorniA(oggi));
                return c != 0 ? c : a.nome.compareToIgnoreCase(b.nome);
            }
        });
        if (l.isEmpty()) {
            TextView t = Ui.text(this, "Nessun risultato.", 15, Ui.TEXT2, false);
            t.setGravity(Gravity.CENTER);
            t.setPadding(0, Ui.dp(40), 0, 0);
            lista.addView(t, Ui.lp(Ui.MATCH, Ui.WRAP));
            return;
        }
        String ultima = null;
        for (Evento e : l) {
            long g = e.giorniA(oggi);
            String sez = g == 0 ? "Oggi" : g == 1 ? "Domani" : g <= 7 ? "Entro una settimana"
                    : g <= 31 ? "Entro un mese" : "Più avanti";
            if (!sez.equals(ultima)) {
                lista.addView(Ui.sezione(this, sez));
                ultima = sez;
            }
            lista.addView(riga(e, oggi, true), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 10));
        }
    }

    private View benvenuto() {
        LinearLayout c = Ui.card(this);
        c.setGravity(Gravity.CENTER_HORIZONTAL);
        c.setPadding(Ui.dp(22), Ui.dp(28), Ui.dp(22), Ui.dp(22));
        TextView em = Ui.text(this, "🎈", 52, Ui.TEXT, false);
        em.setGravity(Gravity.CENTER);
        c.addView(em, Ui.lp(Ui.MATCH, Ui.WRAP));
        TextView t = Ui.text(this, "Ancora nessuna ricorrenza", 19, Ui.TEXT, true);
        t.setGravity(Gravity.CENTER);
        c.addView(t, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 8, 0, 4));
        TextView d = Ui.text(this, "Aggiungi compleanni, anniversari e feste: ti avviserò il giorno stesso o qualche giorno prima, all'orario che scegli tu.", 14, Ui.TEXT2, false);
        d.setGravity(Gravity.CENTER);
        c.addView(d, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 18));
        TextView b1 = Ui.button(this, "＋  Aggiungi una ricorrenza", true);
        b1.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { startActivity(new Intent(MainActivity.this, EditActivity.class)); } });
        c.addView(b1, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 8));
        TextView b2 = Ui.button(this, "📇  Importa compleanni dalla rubrica", false);
        b2.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { Rubrica.importa(MainActivity.this, rinfresca); }
        });
        c.addView(b2, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 8));
        TextView b3 = Ui.button(this, "🎉  Aggiungi le festività", false);
        b3.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { Festivita.scegli(MainActivity.this, rinfresca); }
        });
        c.addView(b3, Ui.lp(Ui.MATCH, Ui.WRAP));
        LinearLayout.LayoutParams p = Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 16, 0, 0);
        c.setLayoutParams(p);
        return c;
    }

    private final Runnable rinfresca = new Runnable() { @Override public void run() { aggiorna(); } };

    /** Riga di una ricorrenza (usata in Prossimi e Calendario). */
    private View riga(final Evento e, LocalDate oggi, boolean conBadge) {
        LinearLayout card = Ui.row(this);
        GradientDrawable bg = Ui.round(Ui.SURFACE, 16);
        if (Ui.dark) bg.setStroke(Ui.dp(1), Ui.DIVIDER);
        card.setBackground(bg);
        card.setElevation(Ui.dark ? 0 : Ui.dp(1));
        card.setPadding(Ui.dp(12), Ui.dp(12), Ui.dp(14), Ui.dp(12));
        int col = s.colore(e);
        card.addView(Ui.bolla(this, e.emoji(), col, 48));

        LocalDate occ = e.prossima(oggi);
        LinearLayout mid = Ui.col(this);
        mid.setPadding(Ui.dp(12), 0, Ui.dp(8), 0);
        TextView nome = Ui.text(this, e.nome, 16, Ui.TEXT, true);
        nome.setSingleLine(true);
        nome.setEllipsize(TextUtils.TruncateAt.END);
        mid.addView(nome);
        String l2 = Fmt.cap(Fmt.dataLunga(occ));
        String eta = e.eta(occ);
        if (!eta.isEmpty()) l2 += " · " + eta;
        TextView t2 = Ui.text(this, l2, 13.5f, Ui.TEXT2, false);
        mid.addView(t2);
        Gruppo g = s.gruppo(e.gruppo);
        String tipo = e.tipoNome();
        LinearLayout r3 = Ui.row(this);
        r3.setPadding(0, Ui.dp(4), 0, 0);
        TextView tp = Ui.text(this, tipo, 11.5f, Ui.TEXT2, true);
        tp.setBackground(Ui.round(Ui.SURFACE2, 8));
        tp.setPadding(Ui.dp(7), Ui.dp(2), Ui.dp(7), Ui.dp(2));
        r3.addView(tp);
        if (g != null) {
            TextView gt = Ui.text(this, g.nome + (g.notifiche ? "" : "  🔕"), 11.5f, Ui.dark ? Ui.TEXT : g.colore, true);
            gt.setBackground(Ui.round(Ui.alpha(g.colore, Ui.dark ? 0.30f : 0.13f), 8));
            gt.setPadding(Ui.dp(7), Ui.dp(2), Ui.dp(7), Ui.dp(2));
            r3.addView(gt, Ui.lpM(Ui.WRAP, Ui.WRAP, 6, 0, 0, 0));
        }
        if (e.telefono != null && !e.telefono.trim().isEmpty()) {
            ImageView ph = Ui.icon(this, R.drawable.ic_phone, Ui.TEXT2, 14);
            LinearLayout.LayoutParams pp = new LinearLayout.LayoutParams(Ui.dp(14), Ui.dp(14));
            pp.setMargins(Ui.dp(8), 0, 0, 0);
            r3.addView(ph, pp);
        }
        mid.addView(r3);
        card.addView(mid, Ui.lpW(1));

        if (conBadge) card.addView(badge(e.giorniA(oggi)));
        Ui.touch(card, 16);
        card.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) { Dialoghi.dettaglio(MainActivity.this, e, rinfresca); }
        });
        return card;
    }

    private View badge(long gg) {
        if (gg == 0 || gg == 1) {
            TextView t = Ui.text(this, gg == 0 ? "OGGI" : "domani", 12, gg == 0 ? 0xFFFFFFFF : Ui.PRIMARY, true);
            t.setBackground(Ui.round(gg == 0 ? Ui.ACCENT : Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.22f : 0.12f), 10));
            t.setPadding(Ui.dp(10), Ui.dp(5), Ui.dp(10), Ui.dp(5));
            return t;
        }
        LinearLayout b = Ui.col(this);
        b.setGravity(Gravity.CENTER);
        b.setMinimumWidth(Ui.dp(48));
        b.addView(Ui.text(this, String.valueOf(gg), 20, gg <= 7 ? Ui.PRIMARY : Ui.TEXT, true));
        b.addView(Ui.text(this, "giorni", 11, Ui.TEXT2, false));
        return b;
    }

    // ================================================================== Calendario

    private View paginaCalendario() {
        ScrollView sc = new ScrollView(this);
        sc.setClipToPadding(false);
        LinearLayout pag = Ui.col(this);
        pag.setPadding(Ui.dp(16), Ui.dp(14), Ui.dp(16), Ui.dp(100));
        sc.addView(pag);

        LinearLayout c = Ui.card(this);
        c.setPadding(Ui.dp(8), Ui.dp(8), Ui.dp(8), Ui.dp(10));
        LinearLayout nav = Ui.row(this);
        ImageView prev = Ui.iconButton(this, R.drawable.ic_left, Ui.TEXT);
        ImageView next = Ui.iconButton(this, R.drawable.ic_right, Ui.TEXT);
        meseTitolo = Ui.text(this, "", 18, Ui.TEXT, true);
        meseTitolo.setGravity(Gravity.CENTER);
        meseTitolo.setPadding(0, Ui.dp(8), 0, Ui.dp(8));
        meseTitolo.setClickable(true);
        meseTitolo.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                mese = YearMonth.now();
                giornoSel = LocalDate.now();
                aggiornaCalendario();
            }
        });
        prev.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { cambiaMese(-1); } });
        next.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View v) { cambiaMese(1); } });
        nav.addView(prev);
        nav.addView(meseTitolo, Ui.lpW(1));
        nav.addView(next);
        c.addView(nav);

        LinearLayout gs = Ui.row(this);
        gs.setPadding(0, Ui.dp(6), 0, Ui.dp(4));
        for (int i = 0; i < 7; i++) {
            TextView t = Ui.text(this, Fmt.GIORNI_BREVI[i], 12, i == 6 ? Ui.SUNDAY : Ui.TEXT2, true);
            t.setGravity(Gravity.CENTER);
            gs.addView(t, Ui.lpW(1));
        }
        c.addView(gs);

        mv = new MonthView(this);
        mv.setAscoltatore(new MonthView.Ascoltatore() {
            @Override public void giorno(LocalDate d) { giornoSel = d; aggiornaListaGiorno(); }
            @Override public void cambiaMese(int delta) { MainActivity.this.cambiaMese(delta); }
        });
        c.addView(mv, Ui.lp(Ui.MATCH, Ui.WRAP));
        pag.addView(c, Ui.lp(Ui.MATCH, Ui.WRAP));

        listaGiorno = Ui.col(this);
        pag.addView(listaGiorno, Ui.lp(Ui.MATCH, Ui.WRAP));
        return sc;
    }

    private void cambiaMese(int delta) {
        mese = mese.plusMonths(delta);
        YearMonth ora = YearMonth.now();
        giornoSel = mese.equals(ora) ? LocalDate.now() : mese.atDay(1);
        aggiornaCalendario();
    }

    private ArrayList<Evento> eventiDelMese() {
        ArrayList<Evento> l = new ArrayList<>();
        for (Evento e : s.eventi) {
            LocalDate d = e.dataNellAnno(mese.getYear());
            if (d.getMonthValue() == mese.getMonthValue()) l.add(e);
        }
        final int y = mese.getYear();
        Collections.sort(l, new Comparator<Evento>() {
            @Override public int compare(Evento a, Evento b) {
                int c = a.dataNellAnno(y).compareTo(b.dataNellAnno(y));
                return c != 0 ? c : a.nome.compareToIgnoreCase(b.nome);
            }
        });
        return l;
    }

    private void aggiornaCalendario() {
        meseTitolo.setText(Fmt.cap(Fmt.mese(mese.getMonthValue())) + " " + mese.getYear());
        HashMap<Integer, ArrayList<Integer>> punti = new HashMap<>();
        for (Evento e : eventiDelMese()) {
            int d = e.dataNellAnno(mese.getYear()).getDayOfMonth();
            ArrayList<Integer> l = punti.get(d);
            if (l == null) { l = new ArrayList<>(); punti.put(d, l); }
            l.add(s.colore(e));
        }
        mv.imposta(mese, giornoSel, punti);
        aggiornaListaGiorno();
    }

    private void aggiornaListaGiorno() {
        listaGiorno.removeAllViews();
        LocalDate oggi = LocalDate.now();
        ArrayList<Evento> mese_ = eventiDelMese();
        if (giornoSel != null && YearMonth.from(giornoSel).equals(mese)) {
            listaGiorno.addView(Ui.sezione(this, Fmt.dataLunga(giornoSel)));
            int n = 0;
            for (Evento e : mese_) {
                if (e.dataNellAnno(mese.getYear()).equals(giornoSel)) {
                    listaGiorno.addView(rigaCalendario(e, oggi), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 10));
                    n++;
                }
            }
            if (n == 0) {
                TextView t = Ui.text(this, "Nessuna ricorrenza in questo giorno.", 14, Ui.TEXT2, false);
                t.setPadding(Ui.dp(4), 0, 0, Ui.dp(6));
                listaGiorno.addView(t);
            }
            final LocalDate g = giornoSel;
            TextView add = Ui.button(this, "＋  Aggiungi il " + Fmt.data(g), false);
            add.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View v) {
                    startActivity(new Intent(MainActivity.this, EditActivity.class)
                            .putExtra("giorno", g.getDayOfMonth()).putExtra("mese", g.getMonthValue()));
                }
            });
            listaGiorno.addView(add, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 4, 0, 0));
        }
        listaGiorno.addView(Ui.sezione(this, "Tutto " + Fmt.mese(mese.getMonthValue()) + " (" + mese_.size() + ")"));
        if (mese_.isEmpty()) {
            TextView t = Ui.text(this, "Nessuna ricorrenza in questo mese.", 14, Ui.TEXT2, false);
            t.setPadding(Ui.dp(4), 0, 0, 0);
            listaGiorno.addView(t);
        } else {
            LinearLayout c = Ui.card(this);
            c.setPadding(Ui.dp(6), Ui.dp(6), Ui.dp(6), Ui.dp(6));
            for (final Evento e : mese_) {
                LocalDate d = e.dataNellAnno(mese.getYear());
                LinearLayout r = Ui.row(this);
                r.setPadding(Ui.dp(10), Ui.dp(10), Ui.dp(10), Ui.dp(10));
                TextView num = Ui.text(this, String.valueOf(d.getDayOfMonth()), 16, Ui.PRIMARY, true);
                num.setGravity(Gravity.CENTER);
                r.addView(num, Ui.lp(Ui.dp(34), Ui.WRAP));
                TextView em = Ui.text(this, e.emoji(), 16, Ui.TEXT, false);
                r.addView(em, Ui.lpM(Ui.WRAP, Ui.WRAP, 4, 0, 10, 0));
                String testo = e.nome;
                String eta = e.eta(d);
                TextView nm = Ui.text(this, testo, 15, Ui.TEXT, false);
                nm.setSingleLine(true);
                nm.setEllipsize(TextUtils.TruncateAt.END);
                r.addView(nm, Ui.lpW(1));
                if (!eta.isEmpty()) r.addView(Ui.text(this, eta.replace("compie ", ""), 13, Ui.TEXT2, false));
                View dot = new View(this);
                dot.setBackground(Ui.circle(s.colore(e)));
                LinearLayout.LayoutParams dp = new LinearLayout.LayoutParams(Ui.dp(10), Ui.dp(10));
                dp.setMargins(Ui.dp(10), 0, 0, 0);
                r.addView(dot, dp);
                Ui.touch(r, 12);
                r.setOnClickListener(new View.OnClickListener() {
                    @Override public void onClick(View v) { Dialoghi.dettaglio(MainActivity.this, e, rinfresca); }
                });
                c.addView(r);
            }
            listaGiorno.addView(c, Ui.lp(Ui.MATCH, Ui.WRAP));
        }
    }

    private View rigaCalendario(Evento e, LocalDate oggi) {
        return riga(e, oggi, true);
    }

    // ================================================================== Gruppi

    private View paginaGruppi() {
        ScrollView sc = new ScrollView(this);
        sc.setClipToPadding(false);
        listaGruppi = Ui.col(this);
        listaGruppi.setPadding(Ui.dp(16), Ui.dp(8), Ui.dp(16), Ui.dp(100));
        sc.addView(listaGruppi);
        return sc;
    }

    private void aggiornaGruppi() {
        listaGruppi.removeAllViews();
        TextView intro = Ui.text(this, "Tocca un gruppo per rinominarlo, cambiare colore o spegnere le sue notifiche. Con «Vedi» mostri solo le sue ricorrenze.", 14, Ui.TEXT2, false);
        intro.setPadding(Ui.dp(4), Ui.dp(10), Ui.dp(4), Ui.dp(6));
        listaGruppi.addView(intro);
        listaGruppi.addView(Ui.sezione(this, "I tuoi gruppi (" + s.gruppi.size() + ")"));
        for (final Gruppo g : s.gruppi) {
            listaGruppi.addView(rigaGruppo(g.nome, g.colore, s.conta(g.id), g.notifiche, g.id, g), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 10));
        }
        int senza = s.conta("");
        if (senza > 0) {
            listaGruppi.addView(rigaGruppo("Senza gruppo", 0xFF9A93B5, senza, true, "", null), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 0, 0, 10));
        }
        TextView nuovo = Ui.button(this, "＋  Nuovo gruppo", false);
        nuovo.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                Dialoghi.gruppo(MainActivity.this, null, new Dialoghi.Fatto() { @Override public void fatto(String id) { aggiorna(); } });
            }
        });
        listaGruppi.addView(nuovo, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 6, 0, 0));
    }

    private View rigaGruppo(String nome, int colore, int n, boolean notifiche, final String id, final Gruppo g) {
        LinearLayout card = Ui.row(this);
        GradientDrawable bg = Ui.round(Ui.SURFACE, 16);
        if (Ui.dark) bg.setStroke(Ui.dp(1), Ui.DIVIDER);
        card.setBackground(bg);
        card.setElevation(Ui.dark ? 0 : Ui.dp(1));
        card.setPadding(Ui.dp(14), Ui.dp(12), Ui.dp(10), Ui.dp(12));
        TextView iniz = Ui.text(this, nome.substring(0, 1).toUpperCase(), 18, 0xFFFFFFFF, true);
        iniz.setGravity(Gravity.CENTER);
        iniz.setBackground(Ui.circle(colore));
        card.addView(iniz, Ui.lp(Ui.dp(42), Ui.dp(42)));
        LinearLayout mid = Ui.col(this);
        mid.setPadding(Ui.dp(14), 0, Ui.dp(8), 0);
        mid.addView(Ui.text(this, nome, 16, Ui.TEXT, true));
        String sub = n == 0 ? "nessuna ricorrenza" : n == 1 ? "1 ricorrenza" : n + " ricorrenze";
        if (!notifiche) sub += " · 🔕 notifiche spente";
        mid.addView(Ui.text(this, sub, 13, Ui.TEXT2, false));
        card.addView(mid, Ui.lpW(1));
        TextView vedi = Ui.text(this, "Vedi", 14, Ui.PRIMARY, true);
        vedi.setPadding(Ui.dp(14), Ui.dp(8), Ui.dp(14), Ui.dp(8));
        vedi.setBackground(Ui.ripple(Ui.round(Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.2f : 0.1f), 12), 12));
        vedi.setClickable(true);
        vedi.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                filtro = id;
                mostraTab(0);
            }
        });
        card.addView(vedi);
        Ui.touch(card, 16);
        card.setOnClickListener(new View.OnClickListener() {
            @Override public void onClick(View v) {
                if (g == null) { filtro = id; mostraTab(0); return; }
                Dialoghi.gruppo(MainActivity.this, g, new Dialoghi.Fatto() { @Override public void fatto(String x) { aggiorna(); } });
            }
        });
        return card;
    }

    // ================================================================== permessi

    private void chiediPermessoNotifiche() {
        if (Build.VERSION.SDK_INT < 33) return;
        if (checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) return;
        SharedPreferences p = getSharedPreferences("app", MODE_PRIVATE);
        if (p.getBoolean("chiesto_notifiche", false)) return;
        p.edit().putBoolean("chiesto_notifiche", true).apply();
        requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_NOTIF);
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] res) {
        super.onRequestPermissionsResult(req, perms, res);
        if (req == Rubrica.REQ) Rubrica.dopoPermesso(this, res, rinfresca);
        aggiorna();
    }
}
