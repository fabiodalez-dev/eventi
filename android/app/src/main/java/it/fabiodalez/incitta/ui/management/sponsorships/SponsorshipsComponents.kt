package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.SponsorshipEvent
import it.fabiodalez.incitta.data.management.SponsorshipProgress
import it.fabiodalez.incitta.ui.Muted
import java.text.NumberFormat
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Currency
import java.util.Locale

/*
 * I pezzi comuni delle schermate delle sponsorizzazioni: l'avanzamento con la
 * tacca dell'atteso, la riga di un evento, il grafico a linee, i formati di
 * date e numeri all'italiana, e la scelta di un evento in un foglio.
 */

private val italian = Locale.ITALIAN
private val dayFormat = DateTimeFormatter.ofPattern("EEE d MMM", italian)
private val dayYearFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", italian)
private val clockFormat = DateTimeFormatter.ofPattern("HH:mm", italian)
private val shortFormat = DateTimeFormatter.ofPattern("dd/MM/yyyy", italian)

/** «sab 22 nov, 21:00» nel fuso della città (con l'anno se non è quello in corso). */
internal fun sponsorshipMoment(iso: String?, zone: ZoneId): String? = iso?.let { value ->
    runCatching {
        val local = OffsetDateTime.parse(value).atZoneSameInstant(zone)
        val day = local.format(if (local.year == LocalDate.now(zone).year) dayFormat else dayYearFormat)
        "$day, ${local.format(clockFormat)}"
    }.getOrNull()
}

/** «sab 22 nov» per un istante, nel fuso della città. */
internal fun sponsorshipDay(iso: String?, zone: ZoneId): String? = iso?.let { value ->
    runCatching {
        val local = OffsetDateTime.parse(value).atZoneSameInstant(zone)
        local.format(if (local.year == LocalDate.now(zone).year) dayFormat else dayYearFormat)
    }.getOrNull()
}

/** «22/11/2026» per un istante, nel fuso della città. */
internal fun sponsorshipShortDate(iso: String?, zone: ZoneId): String? = iso?.let { value ->
    runCatching { OffsetDateTime.parse(value).atZoneSameInstant(zone).format(shortFormat) }.getOrNull()
}

/** «sab 22 nov» per una giornata (`2026-11-22`), senza fuso: è già il giorno della città. */
internal fun sponsorshipCalendarDay(date: String, zone: ZoneId): String = runCatching {
    val day = LocalDate.parse(date)
    day.format(if (day.year == LocalDate.now(zone).year) dayFormat else dayYearFormat)
}.getOrDefault(date)

/** Un numero intero all'italiana: 1.240. */
internal fun sponsorshipCount(value: Long): String = NumberFormat.getIntegerInstance(italian).format(value)

/** Un importo in centesimi all'italiana; `null` senza importo. */
internal fun sponsorshipMoney(cents: Long?, currency: String): String? = cents?.let {
    NumberFormat.getCurrencyInstance(italian).apply {
        this.currency = runCatching { Currency.getInstance(currency) }.getOrDefault(Currency.getInstance("EUR"))
    }.format(java.math.BigDecimal.valueOf(it, 2))
}

/** Il colore della barra e del distintivo, dai nomi dei colori del pannello. */
@Composable
internal fun sponsorshipColor(name: String?): Color = when (name) {
    "success" -> Color(0xFF2E9D5B)
    "warning" -> Color(0xFFD08A00)
    "danger" -> MaterialTheme.colorScheme.error
    "info" -> Color(0xFF2F7FC1)
    "gray" -> MaterialTheme.colorScheme.outline
    else -> MaterialTheme.colorScheme.primary
}

/** Un distintivo colorato con il testo dello stato. */
@Composable
internal fun SponsorshipBadge(text: String, color: String?) {
    if (text.isBlank()) return
    val tint = sponsorshipColor(color)
    Text(
        text,
        style = MaterialTheme.typography.labelLarge,
        color = tint,
        modifier = Modifier.clip(RoundedCornerShape(6.dp)).background(tint.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 4.dp),
    )
}

/**
 * L'avanzamento di un'unità: stato e giorni che restano sopra, la barra con
 * la tacca dell'atteso, la frase e le note sotto. Il colore suggerisce, la
 * frase lo dice a parole, come nel pannello.
 */
@Composable
internal fun SponsorshipProgressView(progress: SponsorshipProgress, forceBadge: Boolean = false) {
    val color = sponsorshipColor(progress.color)
    val percent = (progress.fraction * 100).toInt()
    val label = stringResource(R.string.sponsorships_progress_label, percent)
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        val showBadge = forceBadge || progress.showBadge
        if (showBadge || progress.daysLeftLabel != null) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                if (showBadge) SponsorshipBadge(progress.badge, progress.color) else Spacer(Modifier.width(1.dp))
                progress.daysLeftLabel?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
        }
        val track = MaterialTheme.colorScheme.surfaceVariant
        val marker = MaterialTheme.colorScheme.onSurface
        Canvas(Modifier.fillMaxWidth().height(10.dp).semantics { contentDescription = label }) {
            val radius = size.height / 2
            drawRoundRect(track, cornerRadius = androidx.compose.ui.geometry.CornerRadius(radius, radius))
            if (progress.fraction > 0f) {
                drawRoundRect(color, size = size.copy(width = size.width * progress.fraction), cornerRadius = androidx.compose.ui.geometry.CornerRadius(radius, radius))
            }
            progress.expectedFraction?.let { expected ->
                val x = size.width * expected
                drawLine(marker.copy(alpha = 0.8f), Offset(x, 0f), Offset(x, size.height), strokeWidth = 2.dp.toPx())
            }
        }
        if (progress.phrase.isNotBlank()) Text(progress.phrase, style = MaterialTheme.typography.titleSmall)
        progress.notes.forEach { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

/** La riga di un evento: locandina, titolo, data, locale (per l'organizzazione). */
@Composable
internal fun SponsorshipEventLine(event: SponsorshipEvent, zone: ZoneId, trailing: @Composable (() -> Unit)? = null, below: @Composable ColumnScope.() -> Unit = {}) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
        val poster = event.poster?.thumb ?: event.poster?.card
        if (!poster.isNullOrBlank()) {
            AsyncImage(poster, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.width(48.dp).height(64.dp).clip(RoundedCornerShape(6.dp)))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(event.title, style = MaterialTheme.typography.titleMedium, maxLines = 2)
            val meta = listOfNotNull(sponsorshipMoment(event.date, zone), event.venue?.takeIf { it.isNotBlank() }).joinToString(" · ")
            if (meta.isNotBlank()) Text(meta, color = Muted, style = MaterialTheme.typography.bodySmall)
            below()
        }
        trailing?.invoke()
    }
}

/**
 * Un grafico a linee per giorno: una linea piena e, se c'è, una tratteggiata
 * (le attese). Solo il disegno: i valori esatti stanno nell'elenco sotto,
 * come nel pannello.
 */
@Composable
internal fun SponsorshipLineChart(values: List<Long>, description: String, dashed: List<Long>? = null) {
    val color = MaterialTheme.colorScheme.primary
    val axis = MaterialTheme.colorScheme.outlineVariant
    Canvas(Modifier.fillMaxWidth().height(140.dp).semantics { contentDescription = description }) {
        val top = (listOf(values.maxOrNull() ?: 0L, dashed?.maxOrNull() ?: 0L).max()).coerceAtLeast(1L).toFloat()
        val bottom = size.height - 4.dp.toPx()
        drawLine(axis, Offset(0f, bottom), Offset(size.width, bottom), strokeWidth = 1.dp.toPx())
        fun line(points: List<Long>): Path = Path().apply {
            points.forEachIndexed { index, value ->
                val x = if (points.size <= 1) size.width / 2 else size.width * index / (points.size - 1)
                val y = bottom - (value / top) * (bottom - 8.dp.toPx())
                if (index == 0) moveTo(x, y) else lineTo(x, y)
            }
        }
        dashed?.takeIf { it.isNotEmpty() }?.let {
            drawPath(line(it), color.copy(alpha = 0.55f), style = Stroke(width = 2.dp.toPx(), pathEffect = PathEffect.dashPathEffect(floatArrayOf(14f, 10f)), cap = StrokeCap.Round, join = StrokeJoin.Round))
        }
        if (values.isNotEmpty()) drawPath(line(values), color, style = Stroke(width = 3.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
    }
}

/** Una misura grande con l'etichetta sopra e la spiegazione sotto. */
@Composable
internal fun SponsorshipMetric(label: String, value: String, hint: String? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = Muted)
        Text(value, style = MaterialTheme.typography.headlineSmall)
        hint?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

/** Un titolo di sezione con la riga che spiega. */
@Composable
internal fun SponsorshipSection(title: String, lead: String? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.padding(top = 8.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        lead?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
    }
}

/**
 * La scelta di un evento in un foglio: ricerca, locandina, titolo, data e
 * un'azione per riga. Usata per mettere in evidenza un evento con un periodo
 * attivo (W-SP-09).
 */
// da sostituire con common/EventPickerSheet di P5
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SponsorshipEventPickerSheet(
    title: String,
    lead: String?,
    query: String,
    events: List<SponsorshipEvent>,
    zone: ZoneId,
    busy: Boolean,
    error: String?,
    hasMore: Boolean,
    onSearch: (String) -> Unit,
    onMore: () -> Unit,
    onDismiss: () -> Unit,
    header: @Composable ColumnScope.() -> Unit = {},
    action: @Composable (SponsorshipEvent) -> Unit,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var text by rememberSaveable(query) { mutableStateOf(query) }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(title, style = MaterialTheme.typography.titleLarge)
            lead?.let { Text(it, color = Muted) }
            header()
            OutlinedTextField(text, { text = it.take(80) }, label = { Text(stringResource(R.string.sponsorships_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedButton(enabled = !busy, onClick = { onSearch(text) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_search_action)) }
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (!busy && events.isEmpty()) Text(stringResource(if (query.isBlank()) R.string.sponsorships_no_events else R.string.sponsorships_no_results), color = Muted)
            events.forEach { event ->
                SponsorshipEventLine(event, zone, trailing = { action(event) })
                HorizontalDivider()
            }
            if (hasMore) OutlinedButton(enabled = !busy, onClick = onMore, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_more_events)) }
            Spacer(Modifier.height(24.dp))
        }
    }
}
