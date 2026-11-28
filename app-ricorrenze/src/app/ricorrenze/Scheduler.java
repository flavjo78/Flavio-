package app.ricorrenze;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.drawable.Icon;
import android.os.Build;

import java.time.LocalDate;
import java.time.ZoneId;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.List;

/**
 * Calcola quando suonano i promemoria e programma UNA sveglia di sistema per il prossimo.
 * Quando la sveglia scatta mostra le notifiche dovute (anche quelle perse, es. telefono spento,
 * fino a 36 ore prima) e programma la successiva.
 */
public final class Scheduler {
    private Scheduler() {}

    public static final String CANALE = "promemoria";
    static final long FINESTRA_MAX = 36L * 3600 * 1000;
    static final int COLORE = 0xFF6C4CE0;

    public static class Promemoria {
        public Evento ev;
        public Data d;            // la data della scheda (compleanno, onomastico…)
        public int anticipo;      // giorni prima
        public LocalDate data;    // data della ricorrenza
        public long quando;       // istante della notifica
    }

    static SharedPreferences prefs(Context c) {
        return c.getSharedPreferences("sveglie", Context.MODE_PRIVATE);
    }

    public static void canale(Context c) {
        NotificationManager nm = c.getSystemService(NotificationManager.class);
        if (nm.getNotificationChannel(CANALE) == null) {
            NotificationChannel ch = new NotificationChannel(CANALE, "Promemoria ricorrenze",
                    NotificationManager.IMPORTANCE_HIGH);
            ch.setDescription("Avvisi di compleanni, anniversari e festività");
            ch.enableVibration(true);
            ch.enableLights(true);
            ch.setLightColor(COLORE);
            nm.createNotificationChannel(ch);
        }
    }

    static long istante(Store s, Data dd, int anticipo, LocalDate data) {
        int ora = s.oraDi(dd);
        return data.minusDays(anticipo).atTime(ora / 60, ora % 60)
                .atZone(ZoneId.systemDefault()).toInstant().toEpochMilli();
    }

    /** Tutti i promemoria con istante in (da, a]. */
    public static List<Promemoria> traIstanti(Store s, long da, long a) {
        ArrayList<Promemoria> out = new ArrayList<>();
        int y = LocalDate.now().getYear();
        for (Evento e : s.eventi) {
            if (!s.notificheAttive(e)) continue;
            for (Data dd : e.date) for (int anticipo : dd.avvisi) {
                for (int yy = y - 1; yy <= y + 2; yy++) {
                    LocalDate d = dd.dataNellAnno(yy);
                    long t = istante(s, dd, anticipo, d);
                    if (t > da && t <= a) {
                        Promemoria p = new Promemoria();
                        p.ev = e; p.d = dd; p.anticipo = anticipo; p.data = d; p.quando = t;
                        out.add(p);
                    }
                }
            }
        }
        Collections.sort(out, new Comparator<Promemoria>() {
            @Override public int compare(Promemoria x, Promemoria z) { return Long.compare(x.quando, z.quando); }
        });
        return out;
    }

    /** Il primo promemoria dopo l'istante indicato. */
    public static Promemoria prossimo(Store s, long dopo) {
        Promemoria best = null;
        int y = LocalDate.now().getYear();
        for (Evento e : s.eventi) {
            if (!s.notificheAttive(e)) continue;
            for (Data dd : e.date) for (int anticipo : dd.avvisi) {
                for (int yy = y - 1; yy <= y + 2; yy++) {
                    LocalDate d = dd.dataNellAnno(yy);
                    long t = istante(s, dd, anticipo, d);
                    if (t > dopo && (best == null || t < best.quando)) {
                        best = new Promemoria();
                        best.ev = e; best.d = dd; best.anticipo = anticipo; best.data = d; best.quando = t;
                    }
                }
            }
        }
        return best;
    }

    /** Chiamato dalla sveglia, all'accensione del telefono e all'apertura dell'app. */
    public static void controlla(Context c) {
        Store s = Store.get(c);
        SharedPreferences p = prefs(c);
        long now = System.currentTimeMillis();
        long ultimo = p.getLong("ultimo", -1);
        if (ultimo < 0 || ultimo > now + 5 * 60_000L) ultimo = now; // primo avvio o orologio spostato indietro
        long da = Math.max(ultimo, now - FINESTRA_MAX);
        long a = now + 60_000L;
        if (a > da) {
            for (Promemoria pr : traIstanti(s, da, a)) notifica(c, s, pr);
        }
        long nuovoUltimo = Math.max(a, ultimo);
        p.edit().putLong("ultimo", nuovoUltimo).apply();
        programma(c, s, nuovoUltimo);
    }

    /** Dopo una modifica ai dati: riprogramma senza mostrare promemoria "arretrati". */
    public static void riprogramma(Context c) {
        Store s = Store.get(c);
        SharedPreferences p = prefs(c);
        long now = System.currentTimeMillis();
        long ultimo = p.getLong("ultimo", -1);
        if (ultimo < now || ultimo > now + 5 * 60_000L) {
            ultimo = now;
            p.edit().putLong("ultimo", ultimo).apply();
        }
        programma(c, s, ultimo);
    }

    static PendingIntent piSveglia(Context c) {
        Intent i = new Intent(c, AllarmeReceiver.class).setAction("app.ricorrenze.SVEGLIA");
        return PendingIntent.getBroadcast(c, 1, i, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    static void programma(Context c, Store s, long dopo) {
        AlarmManager am = c.getSystemService(AlarmManager.class);
        PendingIntent pi = piSveglia(c);
        Promemoria pr = prossimo(s, dopo);
        if (pr == null) { am.cancel(pi); return; }
        long t = pr.quando;
        try {
            if (Build.VERSION.SDK_INT >= 31 && !am.canScheduleExactAlarms()) {
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, t, pi);
            } else {
                am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, t, pi);
            }
        } catch (SecurityException ex) {
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, t, pi);
        }
    }

    public static boolean sveglieEsatte(Context c) {
        if (Build.VERSION.SDK_INT < 31) return true;
        return c.getSystemService(AlarmManager.class).canScheduleExactAlarms();
    }

    // ------------------------------------------------------------------ notifiche

    public static String titolo(Promemoria pr) {
        Evento e = pr.ev;
        String quando = Fmt.quando(pr.anticipo);
        String t = e.titolo(pr.d);
        if ("compleanno".equals(pr.d.tipo) || "onomastico".equals(pr.d.tipo) || "anniversario".equals(pr.d.tipo)) t = Fmt.low(t);
        return pr.d.emoji() + " " + quando + ": " + t;
    }

    public static String testo(Store s, Promemoria pr) {
        Evento e = pr.ev;
        StringBuilder b = new StringBuilder();
        String eta = pr.d.eta(pr.data);
        if (!eta.isEmpty()) b.append(Fmt.cap(eta)).append(" · ");
        b.append(Fmt.cap(Fmt.dataLunga(pr.data)));
        Gruppo g = s.gruppo(e.gruppo);
        if (g != null) b.append(" · ").append(g.nome);
        return b.toString();
    }

    static void notifica(Context c, Store s, Promemoria pr) {
        canale(c);
        Evento e = pr.ev;
        int id = (e.id + "|" + pr.d.id + "|" + pr.anticipo + "|" + pr.data.getYear()).hashCode();
        String testo = testo(s, pr);
        String lungo = testo;
        if (e.note != null && !e.note.trim().isEmpty()) lungo += "\n📝 " + e.note.trim();

        Intent apri = new Intent(c, MainActivity.class)
                .putExtra("evento", e.id)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent piApri = PendingIntent.getActivity(c, id, apri,
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);

        Notification.Builder nb = new Notification.Builder(c, CANALE)
                .setSmallIcon(R.drawable.ic_notif)
                .setColor(COLORE)
                .setContentTitle(titolo(pr))
                .setContentText(testo)
                .setStyle(new Notification.BigTextStyle().bigText(lungo))
                .setAutoCancel(true)
                .setContentIntent(piApri)
                .setCategory(Notification.CATEGORY_REMINDER)
                .setWhen(pr.quando)
                .setShowWhen(true);

        String tel = e.telefono == null ? "" : e.telefono.trim();
        if (!tel.isEmpty()) {
            String msg = Azioni.messaggio(s, e, pr.d);
            nb.addAction(azione(c, R.drawable.ic_phone, "Chiama", Azioni.chiama(tel), id * 4 + 1));
            nb.addAction(azione(c, R.drawable.ic_chat, "WhatsApp", Azioni.whatsapp(s, tel, msg), id * 4 + 2));
            nb.addAction(azione(c, R.drawable.ic_sms, "SMS", Azioni.sms(tel, msg), id * 4 + 3));
        }
        NotificationManager nm = c.getSystemService(NotificationManager.class);
        try { nm.notify(id, nb.build()); } catch (SecurityException ignored) { }
    }

    static Notification.Action azione(Context c, int icon, String label, Intent i, int req) {
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        PendingIntent pi = PendingIntent.getActivity(c, req, i,
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        return new Notification.Action.Builder(Icon.createWithResource(c, icon), label, pi).build();
    }

    /** Notifica di prova immediata. */
    public static void prova(Context c) {
        Store s = Store.get(c);
        Promemoria pr = new Promemoria();
        Evento e = null;
        LocalDate oggi = LocalDate.now();
        for (Evento x : s.eventi) if (!x.date.isEmpty() && (e == null || x.giorniA(oggi) < e.giorniA(oggi))) e = x;
        if (e == null) {
            e = new Evento();
            e.id = "prova";
            e.nome = "Mario Rossi";
            Data d = new Data();
            d.giorno = oggi.getDayOfMonth();
            d.mese = oggi.getMonthValue();
            d.anno = oggi.getYear() - 40;
            e.date.add(d);
        }
        pr.ev = e;
        pr.d = e.prossimaData(oggi);
        pr.data = pr.d.prossima(oggi);
        pr.anticipo = (int) pr.d.giorniA(oggi);
        pr.quando = System.currentTimeMillis();
        notifica(c, s, pr);
    }
}
