package app.ricorrenze;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Riavvio del telefono, aggiornamento app, cambio ora/fuso: riprogramma le sveglie. */
public class SistemaReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) {
        String a = i == null ? null : i.getAction();
        if (a == null) return;
        switch (a) {
            case Intent.ACTION_BOOT_COMPLETED:
            case Intent.ACTION_MY_PACKAGE_REPLACED:
            case Intent.ACTION_TIME_CHANGED:
            case Intent.ACTION_TIMEZONE_CHANGED:
            case Intent.ACTION_DATE_CHANGED:
            case "android.app.action.SCHEDULE_EXACT_ALARM_PERMISSION_STATE_CHANGED":
                Scheduler.controlla(c);
                break;
            default:
                break;
        }
    }
}
