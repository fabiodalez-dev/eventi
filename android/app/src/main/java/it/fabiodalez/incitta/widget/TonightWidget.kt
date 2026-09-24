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
}

internal class TonightWidget : GlanceAppWidget() {
    /*
     * Il giro di rete sta PRIMA di `provideContent`, che non torna mai.
     * `provideGlance` gira sul thread principale, quindi ogni lettura pesante
     * passa esplicitamente da un altro thread: la rete lo fa già da sé dentro
     * `ApiClient`, le preferenze no.
     */
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val snapshot = TonightWidgetStore.load(context.applicationContext)
        val now = System.currentTimeMillis()
        val entries = tonightWidgetVisible(snapshot, now)
        provideContent {
            GlanceTheme {
                TonightWidgetBody(context, snapshot.loaded, entries)
            }
        }
    }
}

@Composable
private fun TonightWidgetBody(context: Context, loaded: Boolean, entries: List<TonightWidgetEntry>) {
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
        if (entries.isEmpty()) {
            /*
             * Uno spazio vuoto sembra un widget rotto. Due frasi diverse,
             * perché «non ho ancora chiesto» e «ho chiesto e non c'è niente»
             * sono due cose che chi guarda deve poter distinguere.
             */
            Text(
                context.getString(if (loaded) R.string.widget_tonight_empty else R.string.widget_tonight_loading),
                style = TextStyle(color = MutedInk, fontSize = 13.sp),
            )
        } else {
            entries.forEach { entry -> TonightWidgetRow(context, entry) }
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

    suspend fun load(context: Context): TonightWidgetSnapshot = withContext(Dispatchers.IO) {
        val cached = read(context)
        val powerSave = powerSaveMode(context)
        if (!tonightWidgetShouldRefresh(cached, System.currentTimeMillis(), powerSave)) return@withContext cached
        refreshing.withLock {
            // Più istanze del widget si aggiornano insieme: la seconda trova già fatto.
            val current = read(context)
            if (!tonightWidgetShouldRefresh(current, System.currentTimeMillis(), powerSave)) return@withLock current
            val items = try {
                AppRepository(context).tonightOccurrences(TONIGHT_WIDGET_LIMIT)
            } catch (error: CancellationException) {
                throw error
            } catch (_: Exception) {
                // Rete assente o server giù: si tiene quel che c'è e si riprova al giro dopo.
                return@withLock current
            }
            TonightWidgetSnapshot(tonightWidgetEntries(items), System.currentTimeMillis(), loaded = true)
                .also { write(context, it) }
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
