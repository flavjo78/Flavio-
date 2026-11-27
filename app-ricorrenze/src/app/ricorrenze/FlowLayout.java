package app.ricorrenze;

import android.content.Context;
import android.view.View;
import android.view.ViewGroup;

/** Dispone i figli in riga e va a capo quando finisce lo spazio (per i "chip"). */
public class FlowLayout extends ViewGroup {
    private final int hs, vs;

    public FlowLayout(Context c) {
        super(c);
        hs = Ui.dp(8);
        vs = Ui.dp(8);
    }

    @Override
    protected void onMeasure(int wSpec, int hSpec) {
        int maxW = MeasureSpec.getMode(wSpec) == MeasureSpec.UNSPECIFIED ? Integer.MAX_VALUE
                : MeasureSpec.getSize(wSpec) - getPaddingLeft() - getPaddingRight();
        int x = 0, y = 0, lineH = 0, usedW = 0;
        for (int i = 0; i < getChildCount(); i++) {
            View ch = getChildAt(i);
            if (ch.getVisibility() == GONE) continue;
            ch.measure(MeasureSpec.makeMeasureSpec(maxW, MeasureSpec.AT_MOST),
                    MeasureSpec.makeMeasureSpec(0, MeasureSpec.UNSPECIFIED));
            int w = ch.getMeasuredWidth(), h = ch.getMeasuredHeight();
            if (x > 0 && x + w > maxW) { x = 0; y += lineH + vs; lineH = 0; }
            x += w + hs;
            usedW = Math.max(usedW, x - hs);
            lineH = Math.max(lineH, h);
        }
        int totalH = y + lineH + getPaddingTop() + getPaddingBottom();
        int totalW = MeasureSpec.getMode(wSpec) == MeasureSpec.EXACTLY ? MeasureSpec.getSize(wSpec)
                : usedW + getPaddingLeft() + getPaddingRight();
        if (MeasureSpec.getMode(wSpec) == MeasureSpec.AT_MOST) totalW = Math.min(totalW, MeasureSpec.getSize(wSpec));
        setMeasuredDimension(totalW, resolveSize(totalH, hSpec));
    }

    @Override
    protected void onLayout(boolean changed, int l, int t, int r, int b) {
        int maxW = r - l - getPaddingLeft() - getPaddingRight();
        int x = 0, y = 0, lineH = 0;
        for (int i = 0; i < getChildCount(); i++) {
            View ch = getChildAt(i);
            if (ch.getVisibility() == GONE) continue;
            int w = ch.getMeasuredWidth(), h = ch.getMeasuredHeight();
            if (x > 0 && x + w > maxW) { x = 0; y += lineH + vs; lineH = 0; }
            ch.layout(getPaddingLeft() + x, getPaddingTop() + y, getPaddingLeft() + x + w, getPaddingTop() + y + h);
            x += w + hs;
            lineH = Math.max(lineH, h);
        }
    }
}
