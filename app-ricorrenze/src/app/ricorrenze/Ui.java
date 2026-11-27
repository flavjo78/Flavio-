package app.ricorrenze;

import android.content.Context;
import android.content.res.ColorStateList;
import android.content.res.Configuration;
import android.graphics.Insets;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.RippleDrawable;
import android.os.Build;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowInsets;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

/** Colori, misure e piccoli "mattoncini" grafici usati da tutte le schermate. */
public final class Ui {
    private Ui() {}

    public static int BG, SURFACE, SURFACE2, TEXT, TEXT2, PRIMARY, ON_PRIMARY, HEADER, ON_HEADER,
            ACCENT, DIVIDER, DANGER, OK, WARN, SUNDAY;
    public static boolean dark;
    public static float density = 3f, scaled = 3f;

    /** Colori dei gruppi (scelta nel selettore). */
    public static final int[] COLORI = {
            0xFFE84393, 0xFFE74C3C, 0xFFE67E22, 0xFFF1B80F, 0xFF2ECC71,
            0xFF16A99A, 0xFF3498DB, 0xFF6C5CE7, 0xFF8D6E63, 0xFF7F8C8D
    };

    public static void init(Context c) {
        density = c.getResources().getDisplayMetrics().density;
        scaled = c.getResources().getDisplayMetrics().scaledDensity;
        dark = (c.getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK)
                == Configuration.UI_MODE_NIGHT_YES;
        if (dark) {
            BG = 0xFF121218; SURFACE = 0xFF1E1D27; SURFACE2 = 0xFF2A2936;
            TEXT = 0xFFEDEBF5; TEXT2 = 0xFFA9A6B8; DIVIDER = 0xFF34323F;
            PRIMARY = 0xFF9B87FF; ON_PRIMARY = 0xFF15112A;
            HEADER = 0xFF241F3D; ON_HEADER = 0xFFFFFFFF;
            ACCENT = 0xFFFF6B9A; DANGER = 0xFFFF6B6B; OK = 0xFF4ADE80; WARN = 0xFFFBBF24;
            SUNDAY = 0xFFFF8A8A;
        } else {
            BG = 0xFFF6F5FB; SURFACE = 0xFFFFFFFF; SURFACE2 = 0xFFEFEDF7;
            TEXT = 0xFF1C1B22; TEXT2 = 0xFF6B6878; DIVIDER = 0xFFE4E2EC;
            PRIMARY = 0xFF6C4CE0; ON_PRIMARY = 0xFFFFFFFF;
            HEADER = 0xFF6C4CE0; ON_HEADER = 0xFFFFFFFF;
            ACCENT = 0xFFF0457A; DANGER = 0xFFD93636; OK = 0xFF16A34A; WARN = 0xFFD97706;
            SUNDAY = 0xFFD93636;
        }
    }

    public static int dp(float v) { return Math.round(v * density); }
    public static float sp(float v) { return v * scaled; }

    public static int alpha(int color, float a) {
        int al = Math.round(255 * a);
        return (color & 0x00FFFFFF) | (al << 24);
    }

    public static GradientDrawable round(int color, float radiusDp) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(color);
        g.setCornerRadius(dp(radiusDp));
        return g;
    }

    public static GradientDrawable roundStroke(int fill, int stroke, float strokeDp, float radiusDp) {
        GradientDrawable g = round(fill, radiusDp);
        g.setStroke(Math.max(1, dp(strokeDp)), stroke);
        return g;
    }

    public static GradientDrawable circle(int color) {
        GradientDrawable g = new GradientDrawable();
        g.setShape(GradientDrawable.OVAL);
        g.setColor(color);
        return g;
    }

    /** Sfondo con effetto "onda" al tocco. */
    public static Drawable ripple(Drawable content, float radiusDp) {
        Drawable mask = round(0xFFFFFFFF, radiusDp);
        return new RippleDrawable(ColorStateList.valueOf(alpha(PRIMARY, 0.22f)), content, mask);
    }

    public static void touch(View v, float radiusDp) {
        v.setClickable(true);
        v.setFocusable(true);
        v.setForeground(new RippleDrawable(ColorStateList.valueOf(alpha(PRIMARY, 0.18f)), null,
                round(0xFFFFFFFF, radiusDp)));
    }

    public static TextView text(Context c, CharSequence s, float sizeSp, int color, boolean bold) {
        TextView t = new TextView(c);
        t.setText(s);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, sizeSp);
        t.setTextColor(color);
        if (bold) t.setTypeface(Typeface.create("sans-serif-medium", Typeface.NORMAL));
        return t;
    }

    public static LinearLayout col(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.VERTICAL);
        return l;
    }

    public static LinearLayout row(Context c) {
        LinearLayout l = new LinearLayout(c);
        l.setOrientation(LinearLayout.HORIZONTAL);
        l.setGravity(Gravity.CENTER_VERTICAL);
        return l;
    }

    public static LinearLayout.LayoutParams lp(int w, int h) {
        return new LinearLayout.LayoutParams(w, h);
    }

    public static LinearLayout.LayoutParams lpW(float weight) {
        return new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, weight);
    }

    public static LinearLayout.LayoutParams lpM(int w, int h, int l, int t, int r, int b) {
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(w, h);
        p.setMargins(dp(l), dp(t), dp(r), dp(b));
        return p;
    }

    public static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    public static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    public static ImageView icon(Context c, int res, int color, int sizeDp) {
        ImageView iv = new ImageView(c);
        Drawable d = c.getDrawable(res).mutate();
        d.setTint(color);
        iv.setImageDrawable(d);
        iv.setLayoutParams(new LinearLayout.LayoutParams(dp(sizeDp), dp(sizeDp)));
        return iv;
    }

    public static Drawable tinted(Context c, int res, int color, int sizeDp) {
        Drawable d = c.getDrawable(res).mutate();
        d.setTint(color);
        d.setBounds(0, 0, dp(sizeDp), dp(sizeDp));
        return d;
    }

    /** Tasto rotondo con icona (per l'intestazione). */
    public static ImageView iconButton(Context c, int res, int color) {
        ImageView iv = icon(c, res, color, 44);
        iv.setPadding(dp(10), dp(10), dp(10), dp(10));
        iv.setBackground(new RippleDrawable(ColorStateList.valueOf(alpha(color, 0.25f)), null, circle(0xFFFFFFFF)));
        iv.setClickable(true);
        return iv;
    }

    /** Scheda bianca arrotondata. */
    public static LinearLayout card(Context c) {
        LinearLayout l = col(c);
        GradientDrawable g = round(SURFACE, 18);
        if (dark) g.setStroke(dp(1), DIVIDER);
        l.setBackground(g);
        l.setElevation(dark ? 0 : dp(1.5f));
        l.setPadding(dp(16), dp(14), dp(16), dp(14));
        return l;
    }

    public static TextView sezione(Context c, String s) {
        TextView t = text(c, s, 13, PRIMARY, true);
        t.setAllCaps(true);
        t.setLetterSpacing(0.06f);
        t.setPadding(dp(4), dp(18), dp(4), dp(8));
        return t;
    }

    /** "Chip" selezionabile. */
    public static TextView chip(Context c, String label, boolean selected) {
        TextView t = text(c, label, 14, TEXT, false);
        t.setGravity(Gravity.CENTER);
        t.setPadding(dp(14), dp(8), dp(14), dp(8));
        t.setMinHeight(dp(38));
        t.setClickable(true);
        setChip(t, selected);
        return t;
    }

    public static void setChip(TextView t, boolean sel) {
        t.setSelected(sel);
        if (sel) {
            t.setBackground(ripple(round(PRIMARY, 20), 20));
            t.setTextColor(ON_PRIMARY);
        } else {
            t.setBackground(ripple(roundStroke(SURFACE, DIVIDER, 1.2f, 20), 20));
            t.setTextColor(TEXT);
        }
    }

    /** Pulsante: pieno (colore principale) o "tonale". */
    public static TextView button(Context c, String label, boolean filled) {
        TextView t = text(c, label, 15, filled ? ON_PRIMARY : PRIMARY, true);
        t.setGravity(Gravity.CENTER);
        t.setPadding(dp(18), dp(12), dp(18), dp(12));
        t.setMinHeight(dp(48));
        t.setBackground(ripple(round(filled ? PRIMARY : alpha(PRIMARY, dark ? 0.18f : 0.10f), 14), 14));
        t.setClickable(true);
        return t;
    }

    public static TextView smallButton(Context c, int iconRes, String label, int color) {
        TextView t = text(c, label, 14, color, true);
        t.setGravity(Gravity.CENTER);
        t.setPadding(dp(12), dp(9), dp(14), dp(9));
        t.setCompoundDrawablesRelative(tinted(c, iconRes, color, 18), null, null, null);
        t.setCompoundDrawablePadding(dp(6));
        t.setBackground(ripple(round(alpha(color, dark ? 0.20f : 0.12f), 12), 12));
        t.setClickable(true);
        return t;
    }

    /** Tasto quadrato: icona sopra, testo sotto (per Chiama / WhatsApp / SMS). */
    public static TextView tileButton(Context c, int iconRes, String label, int color) {
        TextView t = text(c, label, 13, color, true);
        t.setGravity(Gravity.CENTER);
        t.setSingleLine(true);
        t.setPadding(dp(4), dp(10), dp(4), dp(10));
        t.setCompoundDrawablesRelative(null, tinted(c, iconRes, color, 24), null, null);
        t.setCompoundDrawablePadding(dp(4));
        t.setBackground(ripple(round(alpha(color, dark ? 0.20f : 0.12f), 14), 14));
        t.setClickable(true);
        return t;
    }

    public static EditText edit(Context c, String hint) {
        EditText e = new EditText(c);
        e.setHint(hint);
        e.setTextSize(TypedValue.COMPLEX_UNIT_SP, 16);
        e.setTextColor(TEXT);
        e.setHintTextColor(alpha(TEXT2, 0.8f));
        e.setBackground(roundStroke(SURFACE2, DIVIDER, 1, 12));
        e.setPadding(dp(14), dp(12), dp(14), dp(12));
        e.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_CAP_WORDS);
        e.setSingleLine(true);
        return e;
    }

    public static View divider(Context c) {
        View v = new View(c);
        v.setBackgroundColor(DIVIDER);
        v.setLayoutParams(lpM(MATCH, Math.max(1, dp(1)), 0, 6, 0, 6));
        return v;
    }

    /** Pallino colorato con un'emoji dentro. */
    public static TextView bolla(Context c, String emoji, int color, int sizeDp) {
        TextView t = text(c, emoji, sizeDp * 0.45f, TEXT, false);
        t.setGravity(Gravity.CENTER);
        t.setBackground(circle(alpha(color, dark ? 0.28f : 0.18f)));
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(dp(sizeDp), dp(sizeDp));
        t.setLayoutParams(p);
        t.setIncludeFontPadding(false);
        return t;
    }

    /**
     * Su Android 15+ l'app disegna sotto le barre di sistema: qui si aggiunge lo spazio
     * giusto in alto (barra di stato) e in basso (barra di navigazione o tastiera).
     * Se bottom == root, il fondo viene gestito sul contenitore intero.
     */
    public static void insets(final View root, final View top, final View bottom) {
        if (Build.VERSION.SDK_INT < 35) return;
        final int tl = top.getPaddingLeft(), tt = top.getPaddingTop(), tr = top.getPaddingRight(), tb = top.getPaddingBottom();
        final int bl = bottom.getPaddingLeft(), bt = bottom.getPaddingTop(), br = bottom.getPaddingRight(), bb = bottom.getPaddingBottom();
        root.setOnApplyWindowInsetsListener(new View.OnApplyWindowInsetsListener() {
            @Override
            public WindowInsets onApplyWindowInsets(View v, WindowInsets in) {
                Insets s = in.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
                Insets ime = in.getInsets(WindowInsets.Type.ime());
                top.setPadding(tl, tt + s.top, tr, tb);
                if (bottom == root) {
                    root.setPadding(s.left, 0, s.right, Math.max(s.bottom, ime.bottom));
                } else if (ime.bottom > s.bottom) {
                    root.setPadding(s.left, 0, s.right, ime.bottom);
                    bottom.setPadding(bl, bt, br, bb);
                } else {
                    root.setPadding(s.left, 0, s.right, 0);
                    bottom.setPadding(bl, bt, br, bb + s.bottom);
                }
                return in;
            }
        });
        root.requestApplyInsets();
    }
}
