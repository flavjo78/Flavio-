package app.ricorrenze;

import static org.junit.Assert.*;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.Application;
import android.app.Dialog;
import android.app.Notification;
import android.app.NotificationManager;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ScrollView;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.RuntimeEnvironment;
import org.robolectric.Shadows;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;
import org.robolectric.shadows.ShadowDialog;
import org.robolectric.shadows.ShadowNotificationManager;
import org.robolectric.shadows.ShadowAlarmManager;

import java.io.File;
import java.io.FileOutputStream;
import java.lang.reflect.Method;
import java.time.LocalDate;
import java.time.ZoneId;
import java.util.Arrays;
import java.util.List;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35, qualifiers = "w400dp-h860dp-xxhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
public class ProvaTest {
    static final String DIR = System.getProperty("shots", "shots");

    static long ms(LocalDate d, int h, int m) {
        return d.atTime(h, m).atZone(ZoneId.systemDefault()).toInstant().toEpochMilli();
    }

    @Test
    public void logicaDate() {
        assertEquals(LocalDate.of(2026, 4, 5), Data.pasqua(2026));
        assertEquals(LocalDate.of(2025, 4, 20), Data.pasqua(2025));
        assertEquals(LocalDate.of(2027, 3, 28), Data.pasqua(2027));
        assertEquals(LocalDate.of(2024, 3, 31), Data.pasqua(2024));
        Data m = new Data(); m.regola = "nth:2:7:5";
        assertEquals(LocalDate.of(2026, 5, 10), m.dataNellAnno(2026));
        assertEquals(LocalDate.of(2027, 5, 9), m.dataNellAnno(2027));
        assertEquals("data mobile: la seconda domenica di maggio", m.descrizioneRegola());
        Data c = new Data(); c.regola = "pasqua:-47";
        assertEquals(LocalDate.of(2026, 2, 17), c.dataNellAnno(2026));
        Data f = new Data(); f.giorno = 29; f.mese = 2;
        assertEquals(LocalDate.of(2027, 2, 28), f.dataNellAnno(2027));
        assertEquals(LocalDate.of(2028, 2, 29), f.dataNellAnno(2028));
        Data b = new Data(); b.giorno = 12; b.mese = 12; b.anno = 1985; b.tipo = "compleanno";
        assertEquals("compie 41 anni", b.eta(LocalDate.of(2026, 12, 12)));
        assertEquals("ha 40 anni", b.etaOggi(LocalDate.of(2026, 11, 27)));
        assertEquals("ha 41 anni", b.etaOggi(LocalDate.of(2026, 12, 12)));
        assertEquals(LocalDate.of(2026, 12, 12), b.prossima(LocalDate.of(2026, 11, 27)));
        assertEquals(LocalDate.of(2027, 12, 12), b.prossima(LocalDate.of(2026, 12, 13)));
        assertEquals(0, b.giorniA(LocalDate.of(2026, 12, 12)));
        b.tipo = "anniversario";
        assertEquals("41° anniversario", b.eta(LocalDate.of(2026, 12, 12)));

        assertArrayEquals(new int[]{1980, 5, 21}, Rubrica.data("1980-05-21"));
        assertArrayEquals(new int[]{0, 5, 21}, Rubrica.data("--05-21"));
        assertArrayEquals(new int[]{1980, 5, 21}, Rubrica.data("21/05/1980"));
        assertArrayEquals(new int[]{1980, 5, 21}, Rubrica.data("19800521"));
        assertArrayEquals(new int[]{0, 5, 21}, Rubrica.data("1604-05-21"));
        assertArrayEquals(new int[]{1990, 1, 2}, Rubrica.data("1990-01-02T00:00:00.000Z"));
        assertNull(Rubrica.data("boh"));

        Store s = Store.get(RuntimeEnvironment.getApplication());
        assertEquals("393331234567", Azioni.internazionale(s, "333 123 4567"));
        assertEquals("393331234567", Azioni.internazionale(s, "+39 333-123-4567"));
        assertEquals("393331234567", Azioni.internazionale(s, "0039 333 1234567"));
        assertEquals("39061234567", Azioni.internazionale(s, "06 1234567"));
        assertEquals("il giorno stesso, 1 giorno prima e 7 giorni prima", Fmt.avvisi(new int[]{7, 0, 1}));
        Evento p = new Evento(); p.nome = "Mario Rossi";
        Data pd = new Data(); pd.tipo = "onomastico"; p.date.add(pd);
        assertEquals("Tanti auguri Mario! 🎉🎂", Azioni.messaggio(s, p, pd));
        assertEquals("Onomastico di Mario Rossi", p.titolo(pd));
        Data an = new Data(); an.tipo = "anniversario"; an.etichetta = "matrimonio";
        assertEquals("Anniversario (matrimonio)", an.nomeCompleto());
        Data al = new Data(); al.tipo = "altro"; al.etichetta = "laurea";
        assertEquals("Laurea", al.nomeCompleto());
        assertEquals("Laurea · Mario Rossi", p.titolo(al));
        p.nome = "Natale"; pd.tipo = "festa";
        assertEquals("Tanti auguri! 🎉🎂", Azioni.messaggio(s, p, pd));
        System.out.println("OK logica date");
    }

    @Test
    public void calcoloPromemoria() {
        Application app = RuntimeEnvironment.getApplication();
        Store s = Store.get(app);
        s.eventi.clear();
        LocalDate oggi = LocalDate.now();
        LocalDate d = oggi.plusDays(3);
        Evento e = new Evento();
        e.nome = "Test";
        Data dd = new Data(); dd.giorno = d.getDayOfMonth(); dd.mese = d.getMonthValue(); dd.avvisi = new int[]{0, 1, 3}; dd.ora = 9 * 60;
        e.date.add(dd);
        s.eventi.add(e);
        long t0 = ms(oggi, 8, 0);
        Scheduler.Promemoria p1 = Scheduler.prossimo(s, t0);
        assertEquals(ms(oggi, 9, 0), p1.quando);
        assertEquals(3, p1.anticipo);
        Scheduler.Promemoria p2 = Scheduler.prossimo(s, p1.quando);
        assertEquals(ms(oggi.plusDays(2), 9, 0), p2.quando);
        Scheduler.Promemoria p3 = Scheduler.prossimo(s, p2.quando);
        assertEquals(ms(d, 9, 0), p3.quando);
        assertEquals(0, p3.anticipo);
        Scheduler.Promemoria p4 = Scheduler.prossimo(s, p3.quando);
        assertEquals(d.plusYears(1).minusDays(3), LocalDate.ofEpochDay(p4.data.minusDays(3).toEpochDay()));
        List<Scheduler.Promemoria> l = Scheduler.traIstanti(s, t0, ms(d, 10, 0));
        assertEquals(3, l.size());
        // gruppo senza notifiche
        Gruppo g = new Gruppo("zz", "Zitto", 0xFF000000, false);
        s.gruppi.add(g);
        e.gruppo = "zz";
        assertNull(Scheduler.prossimo(s, t0));
        e.gruppo = "";
        // orario predefinito
        dd.ora = -1; s.oraDefault = 20 * 60 + 30;
        assertEquals(ms(oggi, 20, 30), Scheduler.prossimo(s, t0).quando);
        // a cavallo d'anno: ricorrenza il 2 gennaio, avviso 7 giorni prima
        Evento capo = new Evento(); capo.nome = "Gennaio";
        Data cd = new Data(); cd.giorno = 2; cd.mese = 1; cd.avvisi = new int[]{7}; cd.ora = 600; capo.date.add(cd);
        s.eventi.clear(); s.eventi.add(capo);
        LocalDate dic = LocalDate.of(oggi.getYear(), 12, 20);
        Scheduler.Promemoria pc = Scheduler.prossimo(s, ms(dic, 0, 0));
        assertEquals(ms(LocalDate.of(oggi.getYear(), 12, 26), 10, 0), pc.quando);
        assertEquals(LocalDate.of(oggi.getYear() + 1, 1, 2), pc.data);
        // due date nella stessa scheda: entrambe suonano, ognuna con i suoi promemoria
        Evento mario = new Evento(); mario.nome = "Mario";
        Data comp = new Data(); comp.giorno = oggi.plusDays(2).getDayOfMonth(); comp.mese = oggi.plusDays(2).getMonthValue(); comp.avvisi = new int[]{0, 1}; comp.ora = 480;
        Data ono = new Data(); ono.tipo = "onomastico"; ono.giorno = oggi.plusDays(5).getDayOfMonth(); ono.mese = oggi.plusDays(5).getMonthValue(); ono.avvisi = new int[]{0}; ono.ora = 600;
        mario.date.add(comp); mario.date.add(ono);
        s.eventi.clear(); s.eventi.add(mario);
        List<Scheduler.Promemoria> lm = Scheduler.traIstanti(s, ms(oggi, 0, 0), ms(oggi.plusDays(6), 0, 0));
        assertEquals(3, lm.size());
        assertEquals("🎂 Domani: compleanno di Mario", Scheduler.titolo(lm.get(0)));
        assertEquals("🌼 Oggi: onomastico di Mario", Scheduler.titolo(lm.get(2)));
        assertEquals(2, s.occorrenze(oggi).size());
        System.out.println("OK promemoria; currentTimeMillis=" + System.currentTimeMillis() + " LocalDate.now=" + LocalDate.now());
    }

    @Test
    public void sveglieENotifiche() {
        Application app = RuntimeEnvironment.getApplication();
        Store s = Store.get(app);
        s.eventi.clear();
        Evento e = new Evento();
        LocalDate oggi = LocalDate.now();
        e.nome = "Lucia Bianchi"; e.telefono = "333 1234567";
        Data ld = new Data(); ld.giorno = oggi.getDayOfMonth(); ld.mese = oggi.getMonthValue(); ld.anno = 1990; ld.avvisi = new int[]{0};
        e.date.add(ld);
        s.eventi.add(e);
        s.salva(app);
        ShadowAlarmManager sam = Shadows.shadowOf(app.getSystemService(android.app.AlarmManager.class));
        ShadowAlarmManager.ScheduledAlarm al = sam.peekNextScheduledAlarm();
        assertNotNull("sveglia programmata", al);
        System.out.println("Sveglia: " + new java.util.Date(al.triggerAtTime) + " tipo=" + al.type);
        Scheduler.prova(app);
        ShadowNotificationManager snm = Shadows.shadowOf(app.getSystemService(NotificationManager.class));
        assertEquals(1, snm.size());
        Notification n = snm.getAllNotifications().get(0);
        System.out.println("Notifica: " + n.extras.getCharSequence(Notification.EXTRA_TITLE) + " | "
                + n.extras.getCharSequence(Notification.EXTRA_TEXT) + " | azioni=" + n.actions.length);
        assertEquals(3, n.actions.length);
        // Simula lo scatto della sveglia
        new AllarmeReceiver().onReceive(app, new Intent("app.ricorrenze.SVEGLIA"));
        new SistemaReceiver().onReceive(app, new Intent(Intent.ACTION_BOOT_COMPLETED));
    }

    @Test
    public void migrazioneEDoppioni() throws Exception {
        Store s = Store.get(RuntimeEnvironment.getApplication());
        String v1 = "{\"app\":\"ricorrenze\",\"versione\":1,\"gruppi\":[{\"id\":\"famiglia\",\"nome\":\"Famiglia\",\"colore\":\"#FFE84393\",\"notifiche\":true}],"
                + "\"eventi\":[{\"id\":\"a1\",\"nome\":\"Mario Rossi\",\"tipo\":\"compleanno\",\"giorno\":12,\"mese\":12,\"anno\":1985,\"telefono\":\"333\",\"gruppo\":\"famiglia\",\"avvisi\":[0,3],\"ora\":480,\"note\":\"regalo\"},"
                + "{\"id\":\"a2\",\"nome\":\"mario  rossi\",\"tipo\":\"onomastico\",\"giorno\":19,\"mese\":1,\"anno\":0,\"telefono\":\"\",\"gruppo\":\"\",\"avvisi\":[0],\"ora\":-1,\"note\":\"\"},"
                + "{\"id\":\"a3\",\"nome\":\"Pasqua\",\"tipo\":\"festa\",\"giorno\":5,\"mese\":4,\"anno\":0,\"regola\":\"pasqua:0\",\"avvisi\":[0],\"ora\":-1}]}";
        s.fromJson(new org.json.JSONObject(v1), false);
        assertEquals(3, s.eventi.size());
        Evento m = s.evento("a1");
        assertEquals(1, m.date.size());
        Data d = m.date.get(0);
        assertEquals("compleanno", d.tipo);
        assertEquals(12, d.giorno); assertEquals(12, d.mese); assertEquals(1985, d.anno);
        assertEquals(480, d.ora);
        assertArrayEquals(new int[]{0, 3}, d.avvisi);
        assertEquals("pasqua:0", s.evento("a3").date.get(0).regola);
        assertEquals(1, s.contaDoppioni());
        assertEquals(1, s.unisciDoppioni());
        assertEquals(2, s.eventi.size());
        assertEquals(2, m.date.size());
        assertEquals("333", m.telefono);
        assertEquals("famiglia", m.gruppo);
        assertEquals("onomastico", m.date.get(1).tipo);
        // salvataggio nel formato nuovo e rilettura
        String j = s.toJson().toString();
        s.fromJson(new org.json.JSONObject(j), false);
        assertEquals(2, s.evento("a1").date.size());
        assertEquals(0, s.contaDoppioni());
        System.out.println("OK migrazione 1.0 -> 1.1 e unione doppioni");
    }

    // ------------------------------------------------------------------ schermate

    static void dati(Store s) {
        s.eventi.clear();
        LocalDate o = LocalDate.now();
        aggiungi(s, "Mamma", "compleanno", o, 1958, "famiglia", "333 1112222", new int[]{0, 3});
        aggiungi(s, "Marco Bianchi", "compleanno", o.plusDays(1), 1990, "palestra", "347 5556666", new int[]{0, 1});
        aggiungi(s, "Mamma", "onomastico", o.plusDays(16), 0, "famiglia", "", new int[]{0});
        aggiungi(s, "Mamma e papà", "anniversario", o.plusDays(4), 1984, "famiglia", "", new int[]{0, 7}).date.get(0).etichetta = "matrimonio";
        aggiungi(s, "Giulia Verdi", "altro", o.plusDays(40), 2019, "lavoro", "", new int[]{0}).date.get(0).etichetta = "Laurea";
        aggiungi(s, "Giulia Verdi", "compleanno", o.plusDays(9), 1987, "lavoro", "320 9998888", new int[]{0});
        aggiungi(s, "Natale", "festa", LocalDate.of(o.getYear(), 12, 25), 0, "", "", new int[]{0});
        aggiungi(s, "Luca", "onomastico", o.plusDays(20), 0, "amici", "", new int[]{0});
        aggiungi(s, "Nonno Pietro", "compleanno", o.plusDays(70), 1940, "famiglia", "", new int[]{0, 1});
        aggiungi(s, "Bollo auto", "altro", o.plusDays(120), 0, "altro", "", new int[]{7, 30});
        s.eventi.get(0).note = "Idea regalo: il libro di cucina che le piaceva";
    }

    static Evento aggiungi(Store s, String n, String t, LocalDate d, int y, String g, String tel, int[] av) {
        Evento e = s.perNome(n, null);
        if (e == null) {
            e = new Evento();
            e.nome = n; e.gruppo = g; e.telefono = tel;
            s.eventi.add(e);
        }
        Data x = new Data();
        x.tipo = t; x.giorno = d.getDayOfMonth(); x.mese = d.getMonthValue(); x.anno = y; x.avvisi = av;
        e.date.add(x);
        return e;
    }

    static void png(Bitmap b, String nome) throws Exception {
        new File(DIR).mkdirs();
        FileOutputStream out = new FileOutputStream(new File(DIR, nome + ".png"));
        b.compress(Bitmap.CompressFormat.PNG, 100, out);
        out.close();
    }

    static void shot(Activity a, String nome) throws Exception {
        org.robolectric.shadows.ShadowLooper.idleMainLooper();
        View v = a.getWindow().getDecorView();
        Bitmap b = Bitmap.createBitmap(v.getWidth(), v.getHeight(), Bitmap.Config.ARGB_8888);
        v.draw(new Canvas(b));
        png(b, nome);
    }

    /** Disegna tutto il contenuto di uno ScrollView, anche la parte nascosta. */
    static void shotLungo(Activity a, String nome) throws Exception {
        org.robolectric.shadows.ShadowLooper.idleMainLooper();
        ViewGroup root = (ViewGroup) a.findViewById(android.R.id.content);
        View contenuto = root.getChildAt(0);
        ScrollView sc = trova(contenuto);
        View figlio = sc.getChildAt(0);
        int w = sc.getWidth();
        figlio.measure(View.MeasureSpec.makeMeasureSpec(w, View.MeasureSpec.EXACTLY), View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        figlio.layout(0, 0, w, figlio.getMeasuredHeight());
        Bitmap b = Bitmap.createBitmap(w, figlio.getMeasuredHeight(), Bitmap.Config.ARGB_8888);
        b.eraseColor(Ui.BG);
        figlio.draw(new Canvas(b));
        png(b, nome);
    }

    static ScrollView trova(View v) {
        if (v instanceof ScrollView && v.getVisibility() == View.VISIBLE) return (ScrollView) v;
        if (v instanceof ViewGroup) {
            ViewGroup g = (ViewGroup) v;
            for (int i = 0; i < g.getChildCount(); i++) {
                if (g.getChildAt(i).getVisibility() != View.VISIBLE) continue;
                ScrollView r = trova(g.getChildAt(i));
                if (r != null) return r;
            }
        }
        return null;
    }

    static void shotDialog(String nome) throws Exception {
        org.robolectric.shadows.ShadowLooper.idleMainLooper();
        Dialog d = ShadowDialog.getLatestDialog();
        View v = d.getWindow().getDecorView();
        int w = v.getWidth() > 0 ? v.getWidth() : 1080;
        v.measure(View.MeasureSpec.makeMeasureSpec(Math.min(w, Ui.dp(360)), View.MeasureSpec.EXACTLY), View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED));
        v.layout(0, 0, v.getMeasuredWidth(), v.getMeasuredHeight());
        Bitmap b = Bitmap.createBitmap(v.getMeasuredWidth(), v.getMeasuredHeight(), Bitmap.Config.ARGB_8888);
        b.eraseColor(0xFF888888);
        v.draw(new Canvas(b));
        png(b, nome);
        d.dismiss();
    }

    static void tab(MainActivity a, int k) throws Exception {
        Method m = MainActivity.class.getDeclaredMethod("mostraTab", int.class);
        m.setAccessible(true);
        m.invoke(a, k);
    }

    @Test
    public void schermateChiare() throws Exception { schermate("chiaro"); }

    @Test
    public void icona() throws Exception {
        Application app = RuntimeEnvironment.getApplication();
        android.graphics.drawable.Drawable d = app.getDrawable(R.mipmap.ic_launcher);
        Bitmap b = Bitmap.createBitmap(432, 432, Bitmap.Config.ARGB_8888);
        d.setBounds(0, 0, 432, 432);
        d.draw(new Canvas(b));
        png(b, "icona");
        android.graphics.drawable.Drawable n = app.getDrawable(R.drawable.ic_notif);
        Bitmap b2 = Bitmap.createBitmap(96, 96, Bitmap.Config.ARGB_8888);
        b2.eraseColor(0xFF333333);
        n.setBounds(0, 0, 96, 96);
        n.draw(new Canvas(b2));
        png(b2, "notif");
    }

    @Test
    @Config(sdk = 26)
    public void schermateAndroid8() throws Exception { schermate("a8"); sveglieENotifiche(); calcoloPromemoria(); }

    @Test
    @Config(qualifiers = "+night")
    public void schermateScure() throws Exception { schermate("scuro"); }

    void schermate(String tema) throws Exception {
        Application app = RuntimeEnvironment.getApplication();
        Store s = Store.get(app);
        s.eventi.clear();
        // schermata vuota
        MainActivity vuota = Robolectric.buildActivity(MainActivity.class).setup().get();
        shot(vuota, tema + "-0-vuota");
        vuota.finish();

        dati(s);
        MainActivity a = Robolectric.buildActivity(MainActivity.class).setup().get();
        shot(a, tema + "-1-prossimi");
        shotLungo(a, tema + "-1b-prossimi-tutto");
        java.lang.reflect.Field ft = MainActivity.class.getDeclaredField("tipi");
        ft.setAccessible(true);
        @SuppressWarnings("unchecked") java.util.Set<String> tipi = (java.util.Set<String>) ft.get(a);
        Method agg = MainActivity.class.getDeclaredMethod("aggiorna");
        agg.setAccessible(true);
        tipi.add("onomastico");
        agg.invoke(a);
        shot(a, tema + "-1c-filtro-onomastici");
        tipi.add("compleanno");
        agg.invoke(a);
        shot(a, tema + "-1d-filtro-due");
        tipi.clear();
        agg.invoke(a);
        tab(a, 1);
        shot(a, tema + "-2-calendario");
        tab(a, 2);
        shot(a, tema + "-3-gruppi");
        tab(a, 0);

        Dialoghi.dettaglio(a, s.eventi.get(0), null);
        shotDialog(tema + "-4-dettaglio");
        Dialoghi.dettaglio(a, s.perNome("Giulia Verdi", null), null);
        shotDialog(tema + "-4b-dettaglio-giulia");
        Dialoghi.gruppo(a, s.gruppi.get(0), null);
        shotDialog(tema + "-5-gruppo");
        Festivita.scegli(a, null);
        shotDialog(tema + "-5b-festivita");

        Intent i = new Intent(app, EditActivity.class).putExtra("id", s.eventi.get(0).id);
        EditActivity ea = Robolectric.buildActivity(EditActivity.class, i).setup().get();
        shot(ea, tema + "-6-modifica");
        shotLungo(ea, tema + "-6b-modifica-tutto");
        Method mp = EditActivity.class.getDeclaredMethod("scegliTipoNuovaData");
        mp.setAccessible(true);
        mp.invoke(ea);
        shotDialog(tema + "-6c-nuova-data");

        EditActivity nu = Robolectric.buildActivity(EditActivity.class, new Intent(app, EditActivity.class)).setup().get();
        shotLungo(nu, tema + "-7-nuova-tutto");

        SettingsActivity st = Robolectric.buildActivity(SettingsActivity.class).setup().get();
        shot(st, tema + "-8-impostazioni");
        shotLungo(st, tema + "-8b-impostazioni-tutto");
        System.out.println("OK schermate " + tema);
    }
}
