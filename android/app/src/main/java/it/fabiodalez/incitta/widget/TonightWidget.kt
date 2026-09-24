package it.fabiodalez.incitta.widget

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.PowerManager
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.GlanceTheme
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.appwidget.updateAll
import androidx.glance.appwidget.action.actionStartActivity
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.width
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.AppRepository
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

/*
 * Il widget «stasera in città»: poche date, l'ora, il posto, e un tocco che
 * apre la data nell'app.
 *
 * Non ha bisogno di un accesso: il catalogo è pubblico e la chiamata parte
 * senza token quando la sessione non c'è, esattamente come fa l'app da
 * anonima. Un widget che chiedesse di accedere per mostrare cosa c'è in
 * città stasera non avrebbe alcun senso sulla schermata iniziale.
 */

/*
 * La tavolozza dell'app. Passa dalle risorse e non da costanti Kotlin perché
 * Glance disegna con RemoteViews: chiaro e scuro li risolve il sistema al
 * momento del disegno, quindi la coppia sta in `values`/`values-night`.
 */
private val PaperOnInk = ColorProvider(R.color.widget_text)
private val MutedInk = ColorProvider(R.color.widget_muted)
private val AcidInk = ColorProvider(R.color.widget_accent)
private val Surface = ColorProvider(R.color.widget_surface)

class TonightWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = TonightWidget()

    /* Il primo widget accende la pianificazione, l'ultimo la spegne: nessun
       giro di rete per una bacheca che non sta più sulla schermata di nessuno. */
    override fun onEnabled(context: Context) {
        super.onEnabled(context)
        TonightWidgetRefreshWorker.schedule(context.applicationContext)
    }

    override fun onDisabled(context: Context) {
        super.onDisabled(context)
        TonightWidgetRefreshWorker.cancel(context.applicationContext)
    }
}

internal class TonightWidget : GlanceAppWidget() {
    /*
     * Qui NON si va in rete.
     *
     * `provideGlance` deve arrivare a `provideContent` — che non torna mai —
     * il prima possibile: è una sessione con un tempo suo, e una chiamata di
     * rete infilata prima viene interrotta se il server tarda. Succedeva:
     * verificato su emulatore il 24/09/2026, il widget appena posato restava
     * su «Cerco le date di stasera…» all'infinito, riavvio compreso, senza
     * scrivere niente e senza lasciare una riga nei registri.
     *
     * Quindi si disegna quello che c'è in cache, subito, e il giro di rete lo
     * fa `TonightWidgetRefreshWorker`, che quando ha finito ridisegna.
     */
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val application = context.applicationContext
        val snapshot = TonightWidgetStore.cached(application)
        val entries = tonightWidgetVisible(snapshot, System.currentTimeMillis())
        TonightWidgetRefreshWorker.enqueueIfStale(application, snapshot)
        provideContent {
            GlanceTheme {
                TonightWidgetBody(context, tonightWidgetState(snapshot, entries), entries)
            }
        }
    }
}

@Composable
private fun TonightWidgetBody(context: Context, state: TonightWidgetState, entries: List<TonightWidgetEntry>) {
    Column(
        modifier = GlanceModifier
            .fillMaxSize()
            .background(Surface)
            .cornerRadius(16.dp)
            .padding(14.dp)
            .clickable(actionStartActivity(appIntent(context, "https://eventi.fabiodalez.it/eventi/oggi"))),
    ) {
        Text(
            context.getString(R.string.widget_tonight_title),
            style = TextStyle(color = AcidInk, fontSize = 12.sp, fontWeight = FontWeight.Bold),
        )
        Spacer(GlanceModifier.height(8.dp))
        if (state == TonightWidgetState.Entries) {
            entries.forEach { entry -> TonightWidgetRow(context, entry) }
        } else {
            /*
             * Uno spazio vuoto sembra un widget rotto, e «cerco» che non
             * finisce mai è la stessa cosa detta peggio. Tre frasi, perché
             * «non ho ancora chiesto», «ho chiesto e non ci sono riuscito» e
             * «ho chiesto e stasera non c'è niente» sono tre cose diverse, e
             * solo la seconda dice a chi guarda che può riprovare più tardi.
             */
            Text(
                context.getString(
                    when (state) {
                        TonightWidgetState.Empty -> R.string.widget_tonight_empty
                        TonightWidgetState.Unreachable -> R.string.widget_tonight_unreachable
                        else -> R.string.widget_tonight_loading
                    },
                ),
                style = TextStyle(color = MutedInk, fontSize = 13.sp),
            )
        }
    }
}

@Composable
private fun TonightWidgetRow(context: Context, entry: TonightWidgetEntry) {
    Row(
        modifier = GlanceModifier
            .fillMaxWidth()
            .padding(vertical = 5.dp)
            .clickable(actionStartActivity(appIntent(context, entry.url))),
        verticalAlignment = Alignment.Top,
    ) {
        Text(
            entry.time.ifBlank { context.getString(R.string.widget_tonight_all_day) },
            style = TextStyle(color = AcidInk, fontSize = 13.sp, fontWeight = FontWeight.Bold),
        )
        Spacer(GlanceModifier.width(10.dp))
        Column(modifier = GlanceModifier.defaultWeight()) {
            Text(
                entry.title,
                maxLines = 2,
                style = TextStyle(color = PaperOnInk, fontSize = 14.sp, fontWeight = FontWeight.Medium),
            )
            if (entry.place.isNotBlank()) {
                Text(entry.place, maxLines = 1, style = TextStyle(color = MutedInk, fontSize = 12.sp))
            }
        }
    }
}

/**
 * L'intento che apre l'app su quella data.
 *
 * È esplicito sulla `MainActivity` — un widget non deve finire nel browser né
 * in un selettore di applicazioni — e riusa il percorso dei link condivisi,
 * che `MainActivity.handleIntent` sa già leggere. Nessuna rotta nuova.
 */
private fun appIntent(context: Context, url: String): Intent =
    Intent(Intent.ACTION_VIEW, Uri.parse(url))
        .setClass(context.applicationContext, MainActivity::class.java)
        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

/**
 * La copia locale di quel che il widget mostra.
 *
 * Sta in un file di preferenze suo, non in `LocalStore`: qui dentro c'è solo
 * catalogo pubblico, e `LocalStore.clearSession()` azzera le sue cache a ogni
 * uscita: un widget che si svuota quando qualcuno esce dall'account sarebbe
 * un difetto difficile da spiegare.
 */
internal object TonightWidgetStore {
    private const val PREFS = "incitta_widget"
    private const val KEY = "tonight"
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
    private val refreshing = Mutex()

    /** Quello che c'è già, senza toccare la rete: è ciò che il widget disegna. */
    suspend fun cached(context: Context): TonightWidgetSnapshot = withContext(Dispatchers.IO) { read(context) }

    fun shouldRefresh(context: Context, snapshot: TonightWidgetSnapshot): Boolean =
        tonightWidgetShouldRefresh(snapshot, System.currentTimeMillis(), powerSaveMode(context))

    /**
     * Il giro di rete. Lo chiama solo il lavoro pianificato, mai il disegno.
     *
     * Torna `true` se c'è qualcosa di nuovo da mostrare. Anche quando fallisce
     * scrive `lastAttemptAt`: è ciò che permette al widget di dire «non ci
     * sono riuscito» invece di restare su «cerco» per sempre.
     */
    suspend fun refresh(context: Context): Boolean = withContext(Dispatchers.IO) {
        val powerSave = powerSaveMode(context)
        if (!tonightWidgetShouldRefresh(read(context), System.currentTimeMillis(), powerSave)) return@withContext false
        refreshing.withLock {
            // Più istanze del widget si aggiornano insieme: la seconda trova già fatto.
            val current = read(context)
            if (!tonightWidgetShouldRefresh(current, System.currentTimeMillis(), powerSave)) return@withLock false
            val items = try {
                AppRepository(context).tonightOccurrences(TONIGHT_WIDGET_LIMIT)
            } catch (error: CancellationException) {
                throw error
            } catch (_: Exception) {
                // Rete assente o server giù: si tiene quel che c'è, si segna il tentativo e si riprova al giro dopo.
                write(context, current.copy(lastAttemptAt = System.currentTimeMillis()))
                return@withLock false
            }
            val now = System.currentTimeMillis()
            write(context, TonightWidgetSnapshot(tonightWidgetEntries(items), now, loaded = true, lastAttemptAt = now))
            true
        }
    }

    private fun read(context: Context): TonightWidgetSnapshot =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)
            ?.let { runCatching { json.decodeFromString<TonightWidgetSnapshot>(it) }.getOrNull() }
            ?: TonightWidgetSnapshot()

    private fun write(context: Context, snapshot: TonightWidgetSnapshot) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY, json.encodeToString(snapshot))
            .apply()
    }

    private fun powerSaveMode(context: Context): Boolean =
        runCatching { context.getSystemService(PowerManager::class.java)?.isPowerSaveMode == true }.getOrDefault(false)
}

/**
 * Il giro di rete del widget, fuori dal disegno.
 *
 * Sta in un lavoro pianificato e non dentro `provideGlance` per una ragione
 * verificata e non teorica: la sessione che disegna il widget ha un tempo suo
 * e viene interrotta se il server tarda, e l'interruzione non lascia traccia
 * né a schermo né nei registri. Qui invece il tentativo può fallire, essere
 * ritentato dal sistema, e comunque lasciare scritto che è avvenuto.
 *
 * Due giri al giorno come dichiara il widget, più uno appena viene posato:
 * chi lo aggiunge alla schermata vuole vederlo pieno adesso, non stasera.
 */
internal class TonightWidgetRefreshWorker(
    context: Context,
    parameters: WorkerParameters,
) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result {
        try {
            TonightWidgetStore.refresh(applicationContext)
        } catch (error: CancellationException) {
            throw error
        } catch (_: Exception) {
            return Result.retry()
        }
        /* Si ridisegna anche quando non è cambiato niente da mostrare: un
           tentativo fallito cambia comunque la frase, da «cerco» a «non ci
           sono riuscito», ed è tutto il punto di segnarlo. */
        TonightWidget().updateAll(applicationContext)
        return Result.success()
    }

    companion object {
        private const val PERIODIC = "tonight-widget-periodic"
        private const val ONCE = "tonight-widget-once"

        /** Il giro subito, quando serve: widget appena posato, o copia troppo vecchia. */
        fun enqueueIfStale(context: Context, snapshot: TonightWidgetSnapshot) {
            if (!TonightWidgetStore.shouldRefresh(context, snapshot)) return
            WorkManager.getInstance(context).enqueueUniqueWork(
                ONCE,
                ExistingWorkPolicy.KEEP,
                OneTimeWorkRequestBuilder<TonightWidgetRefreshWorker>()
                    .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                    .build(),
            )
        }

        fun schedule(context: Context) {
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                PERIODIC,
                ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<TonightWidgetRefreshWorker>(12, TimeUnit.HOURS)
                    .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                    .build(),
            )
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(PERIODIC)
        }
    }
}
