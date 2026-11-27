package app.ricorrenze;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Typeface;
import android.view.GestureDetector;
import android.view.MotionEvent;
import android.view.View;

import java.time.LocalDate;
import java.time.YearMonth;
import java.util.ArrayList;
import java.util.HashMap;

/** Griglia del mese (settimana da lunedì) con i pallini colorati delle ricorrenze. */
public class MonthView extends View {
    public interface Ascoltatore {
        void giorno(LocalDate d);
        void cambiaMese(int delta);
    }

    private YearMonth ym = YearMonth.now();
    private LocalDate sel;
    private LocalDate oggi = LocalDate.now();
    private HashMap<Integer, ArrayList<Integer>> punti = new HashMap<>();
    private Ascoltatore asc;
    private final Paint pTesto = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final Paint pFondo = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final float cellH;
    private final GestureDetector gd;

    public MonthView(Context c) {
        super(c);
        cellH = Ui.dp(52);
        pTesto.setTextAlign(Paint.Align.CENTER);
        pTesto.setTextSize(Ui.sp(15));
        gd = new GestureDetector(c, new GestureDetector.SimpleOnGestureListener() {
            @Override public boolean onDown(MotionEvent e) { return true; }

            @Override public boolean onSingleTapUp(MotionEvent e) {
                tocca(e.getX(), e.getY());
                return true;
            }

            @Override public boolean onFling(MotionEvent a, MotionEvent b, float vx, float vy) {
                if (a == null || b == null) return false;
                float dx = b.getX() - a.getX(), dy = b.getY() - a.getY();
                if (Math.abs(dx) > Ui.dp(50) && Math.abs(dx) > Math.abs(dy) * 1.4f && asc != null) {
                    asc.cambiaMese(dx < 0 ? 1 : -1);
                    return true;
                }
                return false;
            }
        });
    }

    public void imposta(YearMonth ym, LocalDate sel, HashMap<Integer, ArrayList<Integer>> punti) {
        this.ym = ym;
        this.sel = sel;
        this.punti = punti;
        this.oggi = LocalDate.now();
        requestLayout();
        invalidate();
    }

    public void setAscoltatore(Ascoltatore a) { this.asc = a; }

    private int offset() { return ym.atDay(1).getDayOfWeek().getValue() - 1; }

    private int righe() { return (offset() + ym.lengthOfMonth() + 6) / 7; }

    @Override
    protected void onMeasure(int wSpec, int hSpec) {
        int w = MeasureSpec.getSize(wSpec);
        setMeasuredDimension(w, (int) (righe() * cellH));
    }

    @Override
    protected void onDraw(Canvas c) {
        float cw = getWidth() / 7f;
        float r = Math.min(cw, cellH) * 0.37f;
        int off = offset();
        int len = ym.lengthOfMonth();
        for (int d = 1; d <= len; d++) {
            int idx = off + d - 1;
            int colIdx = idx % 7, row = idx / 7;
            float cx = colIdx * cw + cw / 2f;
            float cy = row * cellH + cellH * 0.42f;
            LocalDate data = ym.atDay(d);
            boolean isSel = data.equals(sel), isOggi = data.equals(oggi);
            int colTesto = colIdx == 6 ? Ui.SUNDAY : Ui.TEXT;
            pTesto.setTypeface(Typeface.DEFAULT);
            if (isSel) {
                pFondo.setStyle(Paint.Style.FILL);
                pFondo.setColor(Ui.PRIMARY);
                c.drawCircle(cx, cy, r, pFondo);
                colTesto = Ui.ON_PRIMARY;
                pTesto.setTypeface(Typeface.DEFAULT_BOLD);
            } else if (isOggi) {
                pFondo.setStyle(Paint.Style.FILL);
                pFondo.setColor(Ui.alpha(Ui.ACCENT, Ui.dark ? 0.25f : 0.14f));
                c.drawCircle(cx, cy, r, pFondo);
                colTesto = Ui.ACCENT;
                pTesto.setTypeface(Typeface.DEFAULT_BOLD);
            }
            pTesto.setColor(colTesto);
            float base = cy - (pTesto.descent() + pTesto.ascent()) / 2f;
            c.drawText(String.valueOf(d), cx, base, pTesto);

            ArrayList<Integer> cols = punti.get(d);
            if (cols != null && !cols.isEmpty()) {
                int n = Math.min(3, cols.size());
                float pr = Ui.dp(3f), gap = Ui.dp(8);
                float startX = cx - (n - 1) * gap / 2f;
                float py = row * cellH + cellH * 0.86f;
                pFondo.setStyle(Paint.Style.FILL);
                for (int k = 0; k < n; k++) {
                    pFondo.setColor(cols.get(k));
                    c.drawCircle(startX + k * gap, py, pr, pFondo);
                }
                if (cols.size() > 3) {
                    pFondo.setColor(Ui.TEXT2);
                    c.drawCircle(startX + 3 * gap - Ui.dp(2), py, Ui.dp(1.6f), pFondo);
                }
            }
        }
    }

    private void tocca(float x, float y) {
        float cw = getWidth() / 7f;
        int colIdx = Math.max(0, Math.min(6, (int) (x / cw)));
        int row = (int) (y / cellH);
        int d = row * 7 + colIdx - offset() + 1;
        if (d >= 1 && d <= ym.lengthOfMonth()) {
            sel = ym.atDay(d);
            invalidate();
            if (asc != null) asc.giorno(sel);
        }
    }

    @Override
    public boolean onTouchEvent(MotionEvent e) {
        boolean r = gd.onTouchEvent(e);
        if (e.getAction() == MotionEvent.ACTION_UP) performClick();
        return r || super.onTouchEvent(e);
    }

    @Override
    public boolean performClick() { return super.performClick(); }
}
