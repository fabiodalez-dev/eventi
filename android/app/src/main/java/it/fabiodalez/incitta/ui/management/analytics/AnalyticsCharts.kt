package it.fabiodalez.incitta.ui.management.analytics

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.AnalyticsCard
import it.fabiodalez.incitta.data.management.AnalyticsValue
import it.fabiodalez.incitta.data.management.ChartRow
import it.fabiodalez.incitta.data.management.formatCount
import it.fabiodalez.incitta.ui.Muted
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * I grafici delle statistiche, disegnati con `Canvas` come le linee SVG del
 * pannello: nessuna libreria in più. Ogni grafico ha anche i numeri in
 * testo, per chi usa un lettore di schermo e per chi vuole leggerli: le
 * barre scrivono il numero accanto al nome, le linee hanno la tabella
 * «giorno per giorno» sotto, come il pannello.
 */

// --------------------------------------------------------------- scale

/** Il tetto dell'asse: almeno 10, arrotondato alla decina sopra il massimo (la regola del pannello). */
internal fun chartCeiling(maximum: Long): Long = maxOf(10L, ((maximum + 9) / 10) * 10)

/**
 * I punti di una linea in un'area `width` × `height`: il primo giorno a
 * sinistra, l'ultimo a destra, zero in basso e il tetto in alto. Un solo
 * punto sta a sinistra.
 */
internal fun chartPoints(values: List<Long>, ceiling: Long, width: Float, height: Float): List<Pair<Float, Float>> {
    if (values.isEmpty()) return emptyList()
    val last = maxOf(1, values.size - 1)
    val top = maxOf(1L, ceiling).toFloat()
    return values.mapIndexed { index, value ->
        (width * index / last) to (height - height * value.coerceIn(0L, ceiling).toFloat() / top)
    }
}

/** La larghezza di una barra rispetto alla più lunga del grafico, fra 0 e 1. */
internal fun barFraction(value: Long, maximum: Long): Float =
    if (maximum <= 0L) 0f else (value.coerceAtLeast(0L).toFloat() / maximum.toFloat()).coerceIn(0f, 1f)

private val dayFormat = DateTimeFormatter.ofPattern("d MMM", Locale.ITALIAN)
private val longDayFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ITALIAN)
private val monthFormat = DateTimeFormatter.ofPattern("MMM yyyy", Locale.ITALIAN)

/** «12 set» per un giorno (`2026-09-12`), «set 2026» per un mese (`2026-09`); il testo com'è se non si legge. */
internal fun axisDate(value: String): String = runCatching {
    if (value.length == 7) YearMonth.parse(value).format(monthFormat) else LocalDate.parse(value).format(dayFormat)
}.getOrDefault(value)

/** «sab 12 set 2026»: un giorno scritto per intero, per le tabelle e i filtri. */
internal fun longDate(value: String?): String? = value?.let { runCatching { LocalDate.parse(it).format(longDayFormat) }.getOrDefault(it) }

// --------------------------------------------------------------- pezzi

/** Un titolo di sezione, letto come intestazione dal lettore di schermo. */
@Composable
internal fun AnalyticsSectionTitle(text: String, hint: String? = null) {
    Text(text, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
    hint?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodyMedium) }
}

/**
 * Una sezione che si apre e si chiude dal titolo, come le sezioni
 * richiudibili del pannello. Lo stato sopravvive alla rotazione.
 */
@Composable
internal fun CollapsibleSection(key: String, title: String, hint: String? = null, initiallyOpen: Boolean = true, content: @Composable ColumnScope.() -> Unit) {
    var open by rememberSaveable(key) { mutableStateOf(initiallyOpen) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(
            Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.Button) { open = !open }.semantics { heading() },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
            Icon(if (open) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, contentDescription = null)
        }
        hint?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodyMedium) }
        if (open) content()
    }
    HorizontalDivider()
}

/** I riquadri «In breve»: numero grande, nome e la frase del pannello. */
@Composable
internal fun StatCards(cards: List<AnalyticsCard>) {
    val label = stringResource(R.string.analytics_in_short_label)
    FlowRow(
        Modifier.fillMaxWidth().semantics { contentDescription = label },
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        cards.forEach { card ->
            val spoken = "${card.label}: ${formatCount(card.value)}. ${card.hint}"
            Column(
                Modifier.widthIn(min = 150.dp, max = 320.dp).weight(1f)
                    .border(1.dp, MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(12.dp))
                    .padding(12.dp)
                    .clearAndSetSemantics { contentDescription = spoken },
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(card.label, style = MaterialTheme.typography.labelLarge, color = Muted)
                Text(formatCount(card.value), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.SemiBold)
                if (card.hint.isNotBlank()) Text(card.hint, style = MaterialTheme.typography.bodySmall, color = Muted)
            }
        }
    }
}

/** «Cosa contano questi numeri»: lo stesso riquadro informativo del pannello, sempre sopra i numeri. */
@Composable
internal fun ConsentCard() {
    Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = RoundedCornerShape(12.dp), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.Info, contentDescription = null)
                Text(stringResource(R.string.analytics_consent_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            }
            Text("• " + stringResource(R.string.analytics_consent_links), style = MaterialTheme.typography.bodyMedium)
            Text("• " + stringResource(R.string.analytics_consent_views), style = MaterialTheme.typography.bodyMedium)
        }
    }
}

/** Un elenco «nome: numero», per le misure del profilo e il dettaglio delle azioni. */
@Composable
internal fun ValueList(values: List<AnalyticsValue>) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        values.forEach { value ->
            Row(Modifier.fillMaxWidth().semantics(mergeDescendants = true) {}, horizontalArrangement = Arrangement.SpaceBetween) {
                Text(value.label, modifier = Modifier.weight(1f))
                Text(formatCount(value.value), fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

// --------------------------------------------------------------- linee

/** Una linea del grafico: il nome per la legenda, i numeri e il colore. */
internal data class LineSeries(val label: String, val values: List<Long>, val total: Long? = null)

/**
 * Il grafico a linee del pannello: una o due linee, tre righe della griglia
 * (tetto, metà, zero), le date del primo giorno, di quello di mezzo e
 * dell'ultimo. Sotto, a richiesta, la tabella con tutti i numeri.
 */
@Composable
internal fun LineChart(
    key: String,
    title: String,
    dates: List<String>,
    series: List<LineSeries>,
    dateHeader: String = stringResource(R.string.analytics_day),
) {
    val colors = listOf(MaterialTheme.colorScheme.primary, MaterialTheme.colorScheme.tertiary)
    val grid = MaterialTheme.colorScheme.outlineVariant
    val maximum = series.flatMap { it.values }.maxOrNull() ?: 0L
    val ceiling = chartCeiling(maximum)
    val description = if (dates.isEmpty() || maximum == 0L) stringResource(R.string.analytics_chart_description_empty, title)
    else stringResource(R.string.analytics_chart_description, title, axisDate(dates.first()), axisDate(dates.last()), formatCount(maximum))
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            series.forEachIndexed { index, line ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(Modifier.size(width = 18.dp, height = 4.dp).background(colors[index % colors.size]))
                    Text(line.label + (line.total?.let { " " + formatCount(it) } ?: ""), style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
        Row(Modifier.fillMaxWidth().height(180.dp)) {
            Column(Modifier.fillMaxHeight().padding(end = 6.dp), verticalArrangement = Arrangement.SpaceBetween) {
                listOf(ceiling, ceiling / 2, 0L).forEach { Text(formatCount(it), style = MaterialTheme.typography.labelSmall, color = Muted) }
            }
            Canvas(Modifier.weight(1f).fillMaxHeight().semantics { contentDescription = description }) {
                val dash = PathEffect.dashPathEffect(floatArrayOf(6f, 6f))
                listOf(0f, size.height / 2, size.height).forEach { y -> drawLine(grid, Offset(0f, y), Offset(size.width, y), strokeWidth = 1f, pathEffect = dash) }
                series.forEachIndexed { index, line ->
                    val points = chartPoints(line.values, ceiling, size.width, size.height)
                    if (points.isEmpty()) return@forEachIndexed
                    val path = Path().apply {
                        moveTo(points.first().first, points.first().second)
                        points.drop(1).forEach { (x, y) -> lineTo(x, y) }
                    }
                    val color = colors[index % colors.size]
                    drawPath(path, color, style = Stroke(width = 3.dp.toPx()))
                    if (points.size <= 31) points.forEach { (x, y) -> drawCircle(color, radius = 3.dp.toPx(), center = Offset(x, y)) }
                }
            }
        }
        if (dates.isNotEmpty()) {
            Row(Modifier.fillMaxWidth().clearAndSetSemantics {}, horizontalArrangement = Arrangement.SpaceBetween) {
                listOf(dates.first(), dates[dates.size / 2], dates.last()).distinct().forEach { Text(axisDate(it), style = MaterialTheme.typography.labelSmall, color = Muted) }
            }
        }
        NumbersToggle(key, listOf(dateHeader) + series.map { it.label }, dates.mapIndexed { index, date -> listOf(axisDate(date)) + series.map { formatCount(it.values.getOrElse(index) { 0L }) } })
    }
}

/** «Vedi i numeri giorno per giorno»: la tabella sotto un grafico, chiusa finché non serve. */
@Composable
internal fun NumbersToggle(key: String, headers: List<String>, rows: List<List<String>>) {
    var open by rememberSaveable("numbers-$key") { mutableStateOf(false) }
    TextButton(onClick = { open = !open }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(if (open) R.string.analytics_hide_numbers else R.string.analytics_show_numbers))
    }
    if (open) NumbersTable(headers, rows)
}

/** Una tabella semplice: la prima colonna a sinistra, i numeri a destra; ogni riga si legge intera. */
@Composable
internal fun NumbersTable(headers: List<String>, rows: List<List<String>>) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(Modifier.fillMaxWidth().clearAndSetSemantics {}) {
            headers.forEachIndexed { index, header ->
                Text(header, style = MaterialTheme.typography.labelMedium, color = Muted, modifier = Modifier.weight(if (index == 0) 1.4f else 1f))
            }
        }
        HorizontalDivider()
        rows.forEach { row ->
            val spoken = row.mapIndexed { index, cell -> if (index == 0) cell else "${headers.getOrElse(index) { "" }}: $cell" }.joinToString(", ")
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp).clearAndSetSemantics { contentDescription = spoken }) {
                row.forEachIndexed { index, cell -> Text(cell, modifier = Modifier.weight(if (index == 0) 1.4f else 1f), fontWeight = if (index == 0) FontWeight.Normal else FontWeight.SemiBold) }
            }
        }
    }
}

// --------------------------------------------------------------- barre

/**
 * Le barre del pannello (`x-analytics-bars`): nome, numero, l'eventuale
 * secondo numero e una barra lunga quanto il numero rispetto al più alto.
 * Il nome si tocca se porta alla pagina del soggetto.
 */
@Composable
internal fun BarList(rows: List<ChartRow>, secondaryLabel: String? = null, onOpen: ((ChartRow) -> Unit)? = null) {
    if (rows.isEmpty()) {
        Text(stringResource(R.string.analytics_empty_chart), color = Muted)
        return
    }
    val maximum = rows.maxOf { it.value }
    val track = MaterialTheme.colorScheme.surfaceVariant
    val fill = MaterialTheme.colorScheme.primary
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        rows.forEach { row ->
            val secondary = row.secondary?.let { value -> secondaryLabel?.let { stringResource(R.string.analytics_secondary, it, formatCount(value)) } }
            val spoken = listOfNotNull(stringResource(R.string.analytics_row_description, row.name, formatCount(row.value)), secondary).joinToString(". ")
            val open = onOpen?.takeIf { row.id != null }
            Column(
                Modifier.fillMaxWidth()
                    .then(if (open != null) Modifier.heightIn(min = 48.dp).clickable(role = Role.Button) { open(row) } else Modifier)
                    .clearAndSetSemantics { contentDescription = spoken },
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(row.name, modifier = Modifier.weight(1f), color = if (open != null) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                    Text(formatCount(row.value), fontWeight = FontWeight.SemiBold)
                }
                secondary?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                Box(Modifier.fillMaxWidth().height(8.dp).background(track, RoundedCornerShape(4.dp))) {
                    Box(Modifier.fillMaxWidth(barFraction(row.value, maximum)).fillMaxHeight().background(fill, RoundedCornerShape(4.dp)))
                }
            }
        }
    }
}

/** Una riga «nome: numero» con la barra, per i valori che non portano a nessuna pagina. */
internal fun valuesAsRows(values: List<AnalyticsValue>): List<ChartRow> = values.map { ChartRow(name = it.label, value = it.value) }
