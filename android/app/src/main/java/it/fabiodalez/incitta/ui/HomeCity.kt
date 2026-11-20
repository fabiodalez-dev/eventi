package it.fabiodalez.incitta.ui

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowForward
import androidx.compose.material.icons.outlined.Map
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.net.toUri
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.EventFilter
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Occurrence
import kotlinx.coroutines.CancellationException
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.util.Locale

/*
 * La home come sul sito dal telefono (`resources/views/home.blade.php`):
 * il titolo con i quattro ritagli contati, i tre comandi, il calendario del
 * mese, «Inizia tra poco» con «A seguire», «Organizzi eventi?» e la fascia
 * dei numeri. I dati arrivano da `GET /home` e `GET /calendar`, gli stessi
 * che usa il sito.
 */

@Serializable
internal data class HomeStats(
    val upcoming: Int = 0,
    val week: Int = 0,
    val venues: Int = 0,
    val categories: Int = 0,
    /* Null da un server che non li manda ancora: il ritaglio resta. */
    val today: Int? = null,
    val tomorrow: Int? = null,
    val weekend: Int? = null,
    val free: Int? = null,
)

@Serializable
internal data class HomeOverview(
    val sections: Map<String, List<Occurrence>> = emptyMap(),
    @SerialName("next_mode") val nextMode: String = "city",
    val stats: HomeStats = HomeStats(),
)

@Serializable
internal data class CalendarDayCount(
    @SerialName("business_date") val date: String,
    val count: Int = 0,
)

@Serializable
internal data class CalendarMeta(val interests: Boolean = false)

@Serializable
internal data class CalendarEnvelope(val data: List<CalendarDayCount> = emptyList(), val meta: CalendarMeta = CalendarMeta())

/** Le date del mese per giorno; `interests` dice se seguono gli interessi della persona. */
internal data class MonthCounts(val days: Map<LocalDate, Int>, val interests: Boolean)

private val Rome: ZoneId = ZoneId.of("Europe/Rome")
private const val WEB = "https://eventi.fabiodalez.it"

internal fun romeToday(): LocalDate = LocalDate.now(Rome)

/** Le celle di un mese a partire dal lunedì: null per i giorni prima del primo. */
internal fun monthCells(month: YearMonth): List<LocalDate?> {
    val lead = month.atDay(1).dayOfWeek.value - 1
    return List(lead) { null } + (1..month.lengthOfMonth()).map(month::atDay)
}

/** Carica la panoramica della home; null finché non arriva o se non arriva. */
@Composable
internal fun rememberHomeOverview(token: String?, revision: Int): HomeOverview? {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var overview by remember(token) { mutableStateOf<HomeOverview?>(null) }
    LaunchedEffect(token, revision) {
        try { overview = api.get<ApiEnvelope<HomeOverview>>("home", token).data }
        catch (error: Exception) { if (error is CancellationException) throw error }
    }
    return overview
}

/** I conteggi del mese per giorno, da `GET /calendar?month=`. */
@Composable
internal fun rememberMonthCounts(month: YearMonth, token: String?, personal: Boolean = false): MonthCounts? {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var counts by remember(month, token, personal) { mutableStateOf<MonthCounts?>(null) }
    LaunchedEffect(month, token, personal) {
        try {
            val body = api.get<CalendarEnvelope>("calendar?month=$month" + if (personal) "&personal=1" else "", token)
            counts = MonthCounts(body.data.mapNotNull { day -> runCatching { LocalDate.parse(day.date) to day.count }.getOrNull() }.toMap(), body.meta.interests)
        } catch (error: Exception) { if (error is CancellationException) throw error; counts = MonthCounts(emptyMap(), false) }
    }
    return counts
}

@Composable
internal fun HomeHero(stats: HomeStats?, city: String, onFilter: (EventFilter) -> Unit, onEvents: () -> Unit, onMap: () -> Unit, onTonight: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 20.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        val today = romeToday()
        val line = listOfNotNull(
            today.format(DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.ITALIAN)),
            stats?.upcoming?.takeIf { it > 0 }?.let { if (it == 1) "1 evento in programma" else "$it eventi in programma" },
        ).joinToString(" · ")
        Text(line, color = Muted, style = MaterialTheme.typography.labelMedium)
        Text(
            "A ${city.uppercase()}\nSUCCEDE TUTTO.\nTU SCEGLI COSA.",
            style = MaterialTheme.typography.displayMedium,
            modifier = Modifier.semantics { heading() },
        )
        Text("Concerti, serate, mostre, teatro, mercati, sport. Tutto quello che apre le porte in città, in un posto solo.", color = Muted, style = MaterialTheme.typography.bodyLarge)
        val cells = listOf(
            Triple("Oggi", stats?.today, EventFilter.TODAY),
            Triple("Domani", stats?.tomorrow, EventFilter.TOMORROW),
            Triple("Weekend", stats?.weekend, EventFilter.WEEKEND),
            Triple("Gratis", stats?.free, EventFilter.FREE),
        ).filter { (_, count, _) -> count != 0 }
        /* La parola in grande, senza il numero, come sul sito: si sceglie il
           giorno, non quante date ci sono. Un ritaglio vuoto non compare. */
        cells.chunked(2).forEach { row ->
            Row(Modifier.fillMaxWidth()) {
                row.forEach { (label, _, filter) ->
                    Row(
                        Modifier.weight(1f).border(1.dp, Rule).clickable { onFilter(filter) }.padding(horizontal = 16.dp, vertical = 18.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(label, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f), maxLines = 1)
                        Icon(Icons.AutoMirrored.Outlined.ArrowForward, contentDescription = null, tint = Acid)
                    }
                }
                if (row.size == 1) Spacer(Modifier.weight(1f))
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Button(onClick = onEvents, modifier = Modifier.weight(1f).heightIn(min = 52.dp)) {
                Text(stats?.upcoming?.let { if (it == 1) "Esplora l'evento" else "Esplora $it eventi" } ?: "Esplora gli eventi", maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            OutlinedButton(onClick = onMap, modifier = Modifier.weight(1f).heightIn(min = 52.dp)) {
                Icon(Icons.Outlined.Map, contentDescription = null)
                Spacer(Modifier.width(6.dp))
                Text("Apri la mappa", maxLines = 1)
            }
        }
        OutlinedButton(onClick = onTonight, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
            Text("Trova la tua serata →", color = Acid)
        }
    }
}

/**
 * Il calendario del mese: un giorno con date è un pulsante col numero degli
 * eventi; i giorni passati e quelli vuoti restano spenti, come sul sito.
 */
@Composable
internal fun MonthCalendarGrid(month: YearMonth, counts: Map<LocalDate, Int>?, selected: LocalDate?, onDay: (LocalDate) -> Unit, modifier: Modifier = Modifier) {
    val today = romeToday()
    Column(modifier, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(Modifier.fillMaxWidth()) {
            listOf("LUN", "MAR", "MER", "GIO", "VEN", "SAB", "DOM").forEach {
                Text(it, Modifier.weight(1f), color = Muted, style = MaterialTheme.typography.labelSmall, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            }
        }
        monthCells(month).chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                week.forEach { day ->
                    Box(Modifier.weight(1f).aspectRatio(0.9f)) {
                        if (day != null) DayCell(day, counts?.get(day) ?: 0, counts == null, day == today, day == selected, day.isBefore(today), onDay)
                    }
                }
                repeat(7 - week.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun DayCell(day: LocalDate, count: Int, loading: Boolean, today: Boolean, selected: Boolean, past: Boolean, onDay: (LocalDate) -> Unit) {
    val active = count > 0 && !past
    val shape = RoundedCornerShape(10.dp)
    val fill = when {
        selected || today -> Acid
        else -> Color.Transparent
    }
    val ink = when {
        selected || today -> Ink
        active -> Paper
        else -> Muted
    }
    val label = day.format(DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.ITALIAN)) + if (loading) "" else ": " + (if (count == 1) "1 evento" else "$count eventi")
    Column(
        Modifier.fillMaxWidth().aspectRatio(0.9f).clip(shape).background(fill)
            .then(if (active && !(selected || today)) Modifier.border(1.dp, Rule, shape) else Modifier)
            .then(if (active || today) Modifier.clickable { onDay(day) } else Modifier)
            .semantics { contentDescription = label },
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(day.dayOfMonth.toString(), color = ink, fontWeight = if (active) FontWeight.Bold else FontWeight.Normal, fontSize = 16.sp)
        if (count > 0 && !past) {
            Text(
                count.toString(),
                color = Acid,
                fontSize = 10.sp,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.background(if (selected || today) Color.White else Acid.copy(alpha = 0.14f), RoundedCornerShape(3.dp)).padding(horizontal = 4.dp),
            )
        }
    }
}

internal fun monthTitle(month: YearMonth): String =
    month.month.getDisplayName(TextStyle.FULL_STANDALONE, Locale.ITALIAN) + " " + month.year

/** Il calendario della città sotto l'apertura della home. */
@Composable
internal fun HomeCalendarSection(token: String?, onDay: (LocalDate) -> Unit, onOpenCalendar: () -> Unit) {
    val month = remember { YearMonth.from(romeToday()) }
    /* Come sul sito: chi è collegato vede il calendario dei suoi interessi. */
    val monthData = rememberMonthCounts(month, token, personal = token != null)
    val counts = monthData?.days
    val forYou = monthData?.interests == true
    Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 22.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(if (forYou) "IL TUO CALENDARIO" else "IL CALENDARIO DELLA CITTÀ", color = Acid, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold)
        Text(monthTitle(month), style = MaterialTheme.typography.headlineMedium, modifier = Modifier.semantics { heading() })
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            val total = counts?.values?.sum()
            Text(total?.let { if (it == 1) "1 evento ${if (forYou) "per te " else ""}questo mese" else "$it eventi ${if (forYou) "per te " else ""}questo mese" } ?: "", color = Muted, modifier = Modifier.weight(1f))
            TextButton(onClick = onOpenCalendar) {
                Text("Apri il calendario", color = Acid)
                Icon(Icons.AutoMirrored.Outlined.ArrowForward, contentDescription = null, tint = Acid, modifier = Modifier.padding(start = 4.dp))
            }
        }
        MonthCalendarGrid(month, counts, null, onDay)
    }
}

/** «Inizia tra poco»: la prossima data in evidenza, poi «A seguire». */
@Composable
internal fun StartingSoonSection(next: List<Occurrence>, personal: Boolean, savedIds: Set<Long>, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    val first = next.firstOrNull() ?: return
    Column(Modifier.fillMaxWidth().padding(vertical = 18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(if (personal) "IL PROSSIMO PER TE" else "IL PROSSIMO IN CITTÀ", color = Acid, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(horizontal = 18.dp))
        Text("Inizia tra poco", style = MaterialTheme.typography.headlineMedium, modifier = Modifier.padding(horizontal = 18.dp).semantics { heading() })
        EventRow(first, first.occurrenceId in savedIds, onOpen, onSave, layout = "list")
        if (next.size > 1) {
            Text("A seguire", style = MaterialTheme.typography.titleSmall, modifier = Modifier.padding(horizontal = 18.dp))
            next.drop(1).forEach { EventRow(it, it.occurrenceId in savedIds, onOpen, onSave, layout = "list") }
        }
    }
}

/** «Organizzi eventi?»: le tre porte d'ingresso per chi organizza, sul sito. */
@Composable
internal fun OrganizeCta() {
    val context = LocalContext.current
    fun open(path: String) { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, "$WEB/$path".toUri())) } }
    Column(
        Modifier.fillMaxWidth().padding(18.dp).background(CardSurface, CardShape).border(1.dp, Rule, CardShape).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Text("PARTECIPA AL PROGETTO", color = Acid, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold)
        Text("Organizzi eventi?", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
        Text("Organizzi qualcosa in città? Proponi la data e finisce in questo elenco. Gestisci un locale? Prenditi la tua pagina e pubblica da solo, senza passare da nessuno.", color = Muted)
        Button(onClick = { open("proponi-evento") }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Proponi un evento") }
        OutlinedButton(onClick = { open("registra-il-tuo-locale") }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Registra il tuo locale") }
        OutlinedButton(onClick = { open("registra-la-tua-organizzazione") }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Registra la tua organizzazione") }
    }
}

/** La fascia dei numeri in fondo alla home. */
@Composable
internal fun HomeStatsStrip(stats: HomeStats) {
    val cells = listOf(
        stats.week to "Eventi nei prossimi 7 giorni",
        stats.venues to "Locali sulla mappa",
        stats.categories to "Categorie con date in programma",
    )
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        cells.forEach { (value, label) ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(value.toString(), fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.width(56.dp))
                Text(label, color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}
