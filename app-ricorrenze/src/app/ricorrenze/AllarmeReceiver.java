package app.ricorrenze;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Scatta all'orario del promemoria. */
public class AllarmeReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) {
        Scheduler.controlla(c);
    }
}
