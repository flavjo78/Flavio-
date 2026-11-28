package app.ricorrenze;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.widget.Toast;

/** Chiamata, SMS e WhatsApp verso il contatto di una ricorrenza. */
public final class Azioni {
    private Azioni() {}

    public static String messaggio(Store s, Evento e, Data d) {
        String t = s.testoAuguri == null || s.testoAuguri.trim().isEmpty() ? "Tanti auguri {nome}! 🎉" : s.testoAuguri;
        boolean persona = d == null || !("festa".equals(d.tipo) || ("altro".equals(d.tipo) && d.et().isEmpty()));
        String nome = persona ? Fmt.primoNome(e.nome) : "";
        String r = t.replace("{nome}", nome);
        return r.replace(" !", "!").replace("  ", " ").trim();
    }

    /** Numero in formato internazionale senza "+" (per wa.me). */
    public static String internazionale(Store s, String tel) {
        String t = tel.replaceAll("[^0-9+]", "");
        if (t.startsWith("+")) return t.substring(1).replace("+", "");
        if (t.startsWith("00")) return t.substring(2);
        String pre = s.prefisso == null ? "39" : s.prefisso.replaceAll("[^0-9]", "");
        if (pre.isEmpty()) pre = "39";
        if (!"39".equals(pre) && t.startsWith("0")) t = t.substring(1);
        return pre + t;
    }

    public static Intent chiama(String tel) {
        return new Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(tel.trim())));
    }

    public static Intent sms(String tel, String msg) {
        Intent i = new Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:" + Uri.encode(tel.trim())));
        i.putExtra("sms_body", msg);
        return i;
    }

    public static Intent whatsapp(Store s, String tel, String msg) {
        return new Intent(Intent.ACTION_VIEW,
                Uri.parse("https://wa.me/" + internazionale(s, tel) + "?text=" + Uri.encode(msg)));
    }

    public static void avvia(Activity a, Intent i) {
        try {
            a.startActivity(i);
        } catch (ActivityNotFoundException ex) {
            Toast.makeText(a, "Nessuna app disponibile per questa azione", Toast.LENGTH_SHORT).show();
        }
    }
}
