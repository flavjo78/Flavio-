package app.ricorrenze;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.DialogInterface;
import android.content.Intent;
import android.view.Gravity;
import android.view.View;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Switch;
import android.widget.TextView;
import android.widget.Toast;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;

/** Finestre condivise: crea/modifica gruppo e scheda di una ricorrenza. */
public final class Dialoghi {
    private Dialoghi() {}

    public interface Fatto { void fatto(String id); }

    // ------------------------------------------------------------------ gruppo

    public static void gruppo(final Activity a, final Gruppo g0, final Fatto dopo) {
        final Store s = Store.get(a);
        final boolean nuovo = g0 == null;
        final int[] colore = {nuovo ? Ui.COLORI[(s.gruppi.size() * 3) % Ui.COLORI.length] : g0.colore};

        LinearLayout v = Ui.col(a);
        v.setPadding(Ui.dp(22), Ui.dp(8), Ui.dp(22), Ui.dp(4));
        final EditText nome = Ui.edit(a, "Nome (es. Calcetto, Condominio…)");
        if (!nuovo) nome.setText(g0.nome);
        v.addView(nome, Ui.lp(Ui.MATCH, Ui.WRAP));

        v.addView(Ui.text(a, "Colore", 14, Ui.TEXT2, true), Ui.lpM(Ui.WRAP, Ui.WRAP, 2, 16, 0, 8));
        final FlowLayout fl = new FlowLayout(a);
        final View[] pall = new View[Ui.COLORI.length];
        for (int i = 0; i < Ui.COLORI.length; i++) {
            final int col = Ui.COLORI[i];
            FrameLayout f = new FrameLayout(a);
            f.setLayoutParams(new android.view.ViewGroup.LayoutParams(Ui.dp(40), Ui.dp(40)));
            f.setBackground(Ui.circle(col));
            f.setClickable(true);
            pall[i] = f;
            f.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View x) {
                    colore[0] = col;
                    aggiornaPallini(a, pall, colore[0]);
                }
            });
            fl.addView(f);
        }
        aggiornaPallini(a, pall, colore[0]);
        v.addView(fl, Ui.lp(Ui.MATCH, Ui.WRAP));

        final Switch notif = new Switch(a);
        notif.setText("Notifiche per questo gruppo");
        notif.setTextSize(15);
        notif.setTextColor(Ui.TEXT);
        notif.setChecked(nuovo || g0.notifiche);
        v.addView(notif, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 18, 0, 0));
        TextView nota = Ui.text(a, "Se le spegni, le ricorrenze del gruppo restano nell'elenco ma non suonano.", 12, Ui.TEXT2, false);
        v.addView(nota, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 2, 0, 0));

        AlertDialog.Builder b = new AlertDialog.Builder(a)
                .setTitle(nuovo ? "Nuovo gruppo" : "Modifica gruppo")
                .setView(v)
                .setPositiveButton("Salva", null)
                .setNegativeButton("Annulla", null);
        if (!nuovo) b.setNeutralButton("Elimina", null);
        final AlertDialog d = b.create();
        d.setOnShowListener(new DialogInterface.OnShowListener() {
            @Override public void onShow(DialogInterface di) {
                d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(new View.OnClickListener() {
                    @Override public void onClick(View x) {
                        String n = nome.getText().toString().trim();
                        if (n.isEmpty()) { nome.setError("Scrivi un nome"); return; }
                        Gruppo stesso = s.gruppoPerNome(n);
                        if (stesso != null && (nuovo || stesso != g0)) { nome.setError("Esiste già un gruppo con questo nome"); return; }
                        Gruppo g = nuovo ? new Gruppo(Store.nuovoId(), n, colore[0], notif.isChecked()) : g0;
                        g.nome = n; g.colore = colore[0]; g.notifiche = notif.isChecked();
                        if (nuovo) s.gruppi.add(g);
                        s.salva(a);
                        d.dismiss();
                        if (dopo != null) dopo.fatto(g.id);
                    }
                });
                if (!nuovo) {
                    TextView el = (TextView) d.getButton(AlertDialog.BUTTON_NEUTRAL);
                    el.setTextColor(Ui.DANGER);
                    el.setOnClickListener(new View.OnClickListener() {
                        @Override public void onClick(View x) {
                            final int n = s.conta(g0.id);
                            new AlertDialog.Builder(a)
                                    .setTitle("Eliminare «" + g0.nome + "»?")
                                    .setMessage(n == 0 ? "Il gruppo è vuoto." :
                                            (n == 1 ? "La ricorrenza del gruppo resterà, senza gruppo." :
                                                    "Le " + n + " ricorrenze del gruppo resteranno, senza gruppo."))
                                    .setPositiveButton("Elimina", new DialogInterface.OnClickListener() {
                                        @Override public void onClick(DialogInterface q, int w) {
                                            for (Evento e : s.eventi) if (g0.id.equals(e.gruppo)) e.gruppo = "";
                                            s.gruppi.remove(g0);
                                            s.salva(a);
                                            d.dismiss();
                                            if (dopo != null) dopo.fatto(null);
                                        }
                                    })
                                    .setNegativeButton("Annulla", null)
                                    .show();
                        }
                    });
                }
            }
        });
        d.show();
    }

    private static void aggiornaPallini(Activity a, View[] pall, int sel) {
        for (int i = 0; i < pall.length; i++) {
            android.graphics.drawable.GradientDrawable g = Ui.circle(Ui.COLORI[i]);
            if (Ui.COLORI[i] == sel) g.setStroke(Ui.dp(4), Ui.dark ? 0xFFFFFFFF : 0xFF1C1B22);
            pall[i].setBackground(g);
            FrameLayout f = (FrameLayout) pall[i];
            f.removeAllViews();
            if (Ui.COLORI[i] == sel) {
                android.widget.ImageView ic = Ui.icon(a, R.drawable.ic_check, 0xFFFFFFFF, 22);
                FrameLayout.LayoutParams p = new FrameLayout.LayoutParams(Ui.dp(22), Ui.dp(22), Gravity.CENTER);
                f.addView(ic, p);
            }
        }
    }

    // ------------------------------------------------------------------ scheda

    public static void dettaglio(final Activity a, final Evento e, final Runnable cambiato) {
        final Store s = Store.get(a);
        final LocalDate oggi = LocalDate.now();
        int col = s.colore(e);
        Data prima = e.prossimaData(oggi);

        ScrollView sc = new ScrollView(a);
        LinearLayout v = Ui.col(a);
        v.setPadding(Ui.dp(24), Ui.dp(22), Ui.dp(24), Ui.dp(8));
        sc.addView(v);

        LinearLayout top = Ui.row(a);
        top.addView(Ui.bolla(a, prima != null ? prima.emoji() : e.emoji(), col, 60));
        LinearLayout tt = Ui.col(a);
        tt.setPadding(Ui.dp(14), 0, 0, 0);
        tt.addView(Ui.text(a, e.nome, 21, Ui.TEXT, true));
        Gruppo g = s.gruppo(e.gruppo);
        String sotto = g != null ? g.nome : "Senza gruppo";
        if (e.date.size() > 1) sotto += " · " + e.date.size() + " date";
        tt.addView(Ui.text(a, sotto, 14, Ui.TEXT2, false));
        top.addView(tt, Ui.lpW(1));
        v.addView(top);

        ArrayList<Data> ordinate = new ArrayList<>(e.date);
        Collections.sort(ordinate, new Comparator<Data>() {
            @Override public int compare(Data x, Data y) { return Long.compare(x.giorniA(oggi), y.giorniA(oggi)); }
        });
        boolean attive = s.notificheAttive(e);
        for (Data d : ordinate) v.addView(boxData(a, s, d, col, oggi, attive), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));

        final AlertDialog[] rif = new AlertDialog[1];
        if (!e.festa()) {
            TextView agg = Ui.text(a, "＋  Aggiungi un'altra data (onomastico, anniversario…)", 14, Ui.PRIMARY, true);
            agg.setPadding(Ui.dp(12), Ui.dp(12), Ui.dp(12), Ui.dp(12));
            agg.setBackground(Ui.ripple(Ui.roundStroke(0x00000000, Ui.alpha(Ui.PRIMARY, 0.45f), 1.2f, 14), 14));
            agg.setClickable(true);
            agg.setOnClickListener(new View.OnClickListener() {
                @Override public void onClick(View x) {
                    if (rif[0] != null) rif[0].dismiss();
                    a.startActivity(new Intent(a, EditActivity.class).putExtra("id", e.id).putExtra("nuovaData", true));
                }
            });
            v.addView(agg, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 10, 0, 0));
        }

        final String tel = e.telefono == null ? "" : e.telefono.trim();
        if (!tel.isEmpty()) {
            info(a, v, R.drawable.ic_phone, tel);
            LinearLayout az = Ui.row(a);
            final String msg = Azioni.messaggio(s, e, prima);
            TextView b1 = Ui.tileButton(a, R.drawable.ic_phone, "Chiama", Ui.PRIMARY);
            TextView b2 = Ui.tileButton(a, R.drawable.ic_chat, "WhatsApp", Ui.dark ? 0xFF4ADE80 : 0xFF1FA855);
            TextView b3 = Ui.tileButton(a, R.drawable.ic_sms, "SMS", Ui.ACCENT);
            b1.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View x) { Azioni.avvia(a, Azioni.chiama(tel)); } });
            b2.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View x) { Azioni.avvia(a, Azioni.whatsapp(s, tel, msg)); } });
            b3.setOnClickListener(new View.OnClickListener() { @Override public void onClick(View x) { Azioni.avvia(a, Azioni.sms(tel, msg)); } });
            LinearLayout.LayoutParams p1 = new LinearLayout.LayoutParams(0, Ui.WRAP, 1f);
            p1.setMargins(0, 0, Ui.dp(5), 0);
            LinearLayout.LayoutParams p2 = new LinearLayout.LayoutParams(0, Ui.WRAP, 1f);
            p2.setMargins(Ui.dp(3), 0, Ui.dp(3), 0);
            LinearLayout.LayoutParams p3 = new LinearLayout.LayoutParams(0, Ui.WRAP, 1f);
            p3.setMargins(Ui.dp(5), 0, 0, 0);
            az.addView(b1, p1);
            az.addView(b2, p2);
            az.addView(b3, p3);
            v.addView(az, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 10, 0, 4));
        }
        if (e.note != null && !e.note.trim().isEmpty()) {
            TextView n = Ui.text(a, "📝 " + e.note.trim(), 15, Ui.TEXT, false);
            n.setBackground(Ui.round(Ui.SURFACE2, 12));
            n.setPadding(Ui.dp(14), Ui.dp(10), Ui.dp(14), Ui.dp(10));
            v.addView(n, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));
        }

        final AlertDialog d = new AlertDialog.Builder(a)
                .setView(sc)
                .setPositiveButton("Modifica", null)
                .setNegativeButton("Chiudi", null)
                .setNeutralButton("Elimina", null)
                .create();
        rif[0] = d;
        d.setOnShowListener(new DialogInterface.OnShowListener() {
            @Override public void onShow(DialogInterface di) {
                d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(new View.OnClickListener() {
                    @Override public void onClick(View x) {
                        d.dismiss();
                        a.startActivity(new Intent(a, EditActivity.class).putExtra("id", e.id));
                    }
                });
                TextView el = (TextView) d.getButton(AlertDialog.BUTTON_NEUTRAL);
                el.setTextColor(Ui.DANGER);
                el.setOnClickListener(new View.OnClickListener() {
                    @Override public void onClick(View x) {
                        conferma(a, e, new Runnable() {
                            @Override public void run() {
                                d.dismiss();
                                if (cambiato != null) cambiato.run();
                            }
                        });
                    }
                });
            }
        });
        d.show();
    }

    /** Riquadro di una data nella scheda. */
    private static View boxData(Activity a, Store s, Data d, int col, LocalDate oggi, boolean attive) {
        LinearLayout box = Ui.col(a);
        box.setBackground(Ui.round(Ui.alpha(col, Ui.dark ? 0.18f : 0.10f), 16));
        box.setPadding(Ui.dp(14), Ui.dp(12), Ui.dp(14), Ui.dp(12));
        LocalDate occ = d.prossima(oggi);
        long gg = d.giorniA(oggi);
        LinearLayout r = Ui.row(a);
        TextView t = Ui.text(a, d.emoji() + "  " + d.nomeCompleto(), 16, Ui.TEXT, true);
        r.addView(t, Ui.lpW(1));
        TextView q = Ui.text(a, gg == 0 ? "OGGI" : Fmt.quando(gg).toLowerCase(), 12.5f, gg == 0 ? 0xFFFFFFFF : Ui.PRIMARY, true);
        q.setBackground(Ui.round(gg == 0 ? Ui.ACCENT : Ui.alpha(Ui.PRIMARY, Ui.dark ? 0.22f : 0.12f), 10));
        q.setPadding(Ui.dp(9), Ui.dp(4), Ui.dp(9), Ui.dp(4));
        r.addView(q);
        box.addView(r);
        String riga = Fmt.cap(Fmt.dataAnno(occ));
        String eta = d.eta(occ);
        if (!eta.isEmpty()) riga += " · " + eta;
        box.addView(Ui.text(a, riga, 14, Ui.TEXT, false), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 4, 0, 0));
        if (d.mobile() || (d.anno > 0 && d.usaAnno())) {
            String info = Fmt.cap(d.descrizioneData());
            String ora = d.etaOggi(oggi);
            if (!ora.isEmpty() && gg != 0) info += " · " + ora;
            box.addView(Ui.text(a, info, 13, Ui.TEXT2, false));
        }
        String prom = attive ? "🔔 " + Fmt.cap(Fmt.avvisi(d.avvisi)) + " · ore " + Fmt.ora(s.oraDi(d))
                : "🔕 Notifiche spente per questo gruppo";
        box.addView(Ui.text(a, prom, 13, Ui.TEXT2, false), Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 4, 0, 0));
        return box;
    }

    public static void conferma(final Activity a, final Evento e, final Runnable dopo) {
        new AlertDialog.Builder(a)
                .setTitle("Eliminare «" + e.nome + "»?")
                .setMessage(e.date.size() > 1 ? "La scheda con tutte le sue " + e.date.size() + " date e i promemoria verrà cancellata."
                        : "La scheda e i suoi promemoria verranno cancellati.")
                .setPositiveButton("Elimina", new DialogInterface.OnClickListener() {
                    @Override public void onClick(DialogInterface q, int w) {
                        Store s = Store.get(a);
                        s.eventi.remove(e);
                        s.salva(a);
                        Toast.makeText(a, "Eliminata", Toast.LENGTH_SHORT).show();
                        if (dopo != null) dopo.run();
                    }
                })
                .setNegativeButton("Annulla", null)
                .show();
    }

    private static void info(Activity a, LinearLayout v, int icon, String testo) {
        LinearLayout r = Ui.row(a);
        r.addView(Ui.icon(a, icon, Ui.TEXT2, 20));
        TextView t = Ui.text(a, testo, 15, Ui.TEXT, false);
        t.setPadding(Ui.dp(12), 0, 0, 0);
        r.addView(t, Ui.lpW(1));
        v.addView(r, Ui.lpM(Ui.MATCH, Ui.WRAP, 0, 12, 0, 0));
    }
}
