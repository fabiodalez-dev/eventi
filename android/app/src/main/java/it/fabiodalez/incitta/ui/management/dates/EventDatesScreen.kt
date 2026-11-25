package it.fabiodalez.incitta.ui.management.dates

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.DatesPeriod
import it.fabiodalez.incitta.data.management.EventRecurrenceState
import it.fabiodalez.incitta.data.management.ManagedEventOccurrence
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.social.SocialPreviewDialog
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.pickerDate
import it.fabiodalez.incitta.ui.management.common.pickerMillis
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.events.ChoiceField
import it.fabiodalez.incitta.ui.management.events.MultiChips
import it.fabiodalez.incitta.ui.management.events.StatusBadge
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.util.Locale

/* Le conferme dell'elenco: una alla volta, sulla data toccata. */
private sealed interface DateAction {
    data class Cancel(val date: ManagedEventOccurrence) : DateAction
    data class SoldOut(val date: ManagedEventOccurrence) : DateAction
    data class Delete(val date: ManagedEventOccurrence) : DateAction
    data object Repeat : DateAction
}

/**
 * Le date di un evento (W-DT-01…07, 09, 10, 12; W-EV-17), dalla scheda
 * dell'evento. Il locale vede la sezione «Date» del suo pannello: inizio,
 * stato, posti, artisti, visite, clic e salvataggi; annulla (anche «questa e
 * le successive»), tutto esaurito, elimina, «Ripeti». L'organizzazione vede
 * «Date e luoghi», con il locale di ogni data e il modulo con lo stato
 * completo. Per tutti: biglietteria della data (a chi la gestisce) e
 * locandina in PDF per gli eventi pubblicati o archiviati.
 */
@Composable
internal fun EventDatesScreen(
    viewModel: EventDatesViewModel,
    context: ManagementGraphContext,
    onAdd: () -> Unit,
    onEdit: (Long) -> Unit,
    onTickets: (Long) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = state.subject
    val meta = state.meta
    val zone = datesZone(meta, subject)
    val venue = subject?.kind != SubjectKind.Organizer
    var pending by remember { mutableStateOf<DateAction?>(null) }
    val androidContext = LocalContext.current
    val chooser = stringResource(R.string.management_export_chooser)
    val noApp = stringResource(R.string.management_export_no_app)
    var fileError by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { viewModel.onShown() }
    LaunchedEffect(state.poster) {
        state.poster?.let { file ->
            viewModel.consumePoster()
            fileError = if (SharedFiles.open(androidContext, file.file, file.mime, chooser)) null else noApp
        }
    }

    ManagementScaffold(
        title = stringResource(if (venue) R.string.management_dates_title else R.string.management_dates_title_organizer),
        padding = context.padding,
        onBack = context::back,
        subtitle = meta?.event?.let { listOf(it.title, it.status.label).filter(String::isNotBlank).joinToString(" · ") },
        busy = state.busy,
    ) {
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        fileError?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        val can = meta?.abilities
        if (can?.create == true) Button(enabled = !state.busy, onClick = onAdd, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_dates_add))
        }
        if (venue && can?.recurrence == true) RecurrenceBox(meta.recurrence, needsDates = can.recurrenceNeedsDates, busy = state.busy) { pending = DateAction.Repeat }

        PeriodChips(state.period, viewModel::showPeriod)
        LoadState(state.dates, onRetry = viewModel::retry, emptyText = stringResource(
            if (state.period == DatesPeriod.All) R.string.management_dates_empty else R.string.management_dates_empty_period,
        )) { dates ->
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                dates.forEach { date ->
                    DateCard(date, zone, venue, state.busy,
                        onEdit = { onEdit(date.id) },
                        onCancel = { pending = DateAction.Cancel(date) },
                        onSoldOut = { pending = DateAction.SoldOut(date) },
                        onDelete = { pending = DateAction.Delete(date) },
                        onTickets = date.ticketingDateId?.let { id -> { onTickets(id) } },
                        onPoster = { fileError = null; viewModel.downloadPoster(date, androidContext.cacheDir) },
                        onSocialPreview = { fileError = null; viewModel.downloadSocialPreview(date, androidContext.cacheDir) })
                }
                if (state.nextPage != null) OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_dates_more))
                }
            }
        }
    }

    state.socialPreview?.let { preview ->
        SocialPreviewDialog(preview.file, meta?.event?.title.orEmpty(), onDismiss = viewModel::consumeSocialPreview)
    }

    when (val action = pending) {
        is DateAction.Cancel -> CancelDateDialog(action.date, zone, onDismiss = { pending = null }) { series -> pending = null; viewModel.cancel(action.date, series) }
        is DateAction.SoldOut -> ConfirmDialog(
            title = stringResource(if (action.date.soldOut) R.string.management_dates_available else R.string.management_dates_sold_out),
            text = stringResource(if (action.date.soldOut) R.string.management_dates_available_confirm else R.string.management_dates_sold_out_confirm, dayLabel(action.date.startsAt, zone)),
            confirmLabel = stringResource(if (action.date.soldOut) R.string.management_dates_available else R.string.management_dates_sold_out),
            onConfirm = { pending = null; viewModel.toggleSoldOut(action.date) }, onDismiss = { pending = null })
        is DateAction.Delete -> ConfirmDialog(
            title = stringResource(R.string.management_dates_delete),
            text = stringResource(R.string.management_dates_delete_confirm, dayLabel(action.date.startsAt, zone)),
            confirmLabel = stringResource(R.string.management_dates_delete),
            onConfirm = { pending = null; viewModel.delete(action.date) }, onDismiss = { pending = null }, destructive = true)
        DateAction.Repeat -> RecurrenceDialog(meta?.recurrence, zone, onDismiss = { pending = null }) { frequency, weekdays, until ->
            pending = null
            viewModel.repeat(frequency, weekdays, until)
        }
        null -> Unit
    }
}

@Composable
private fun PeriodChips(selected: DatesPeriod, onSelect: (DatesPeriod) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf(DatesPeriod.All to R.string.management_dates_period_all, DatesPeriod.Upcoming to R.string.management_dates_period_upcoming, DatesPeriod.Past to R.string.management_dates_period_past)
            .forEach { (period, label) ->
                FilterChip(selected = period == selected, onClick = { onSelect(period) }, label = { Text(stringResource(label)) }, modifier = Modifier.heightIn(min = 48.dp))
            }
    }
}

/** La ripetizione detta a parole, con «Ripeti»; senza date il pulsante resta spento, come nel pannello. */
@Composable
private fun RecurrenceBox(recurrence: EventRecurrenceState?, needsDates: Boolean, busy: Boolean, onRepeat: () -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(stringResource(R.string.management_dates_repeat), style = MaterialTheme.typography.titleMedium)
            Text(recurrence?.description?.takeIf { it.isNotBlank() }?.let { stringResource(R.string.management_dates_repeat_current, it) }
                ?: stringResource(R.string.management_dates_repeat_none), color = Muted)
            recurrence?.until?.let { until -> Text(stringResource(R.string.management_dates_repeat_until_value, isoDay(until)), color = Muted) }
            OutlinedButton(enabled = !busy && !needsDates, onClick = onRepeat, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_dates_repeat))
            }
            if (needsDates) Text(stringResource(R.string.management_dates_repeat_needs_dates), color = Muted)
        }
    }
}

@Composable
private fun DateCard(
    date: ManagedEventOccurrence,
    zone: ZoneId,
    venue: Boolean,
    busy: Boolean,
    onEdit: () -> Unit,
    onCancel: () -> Unit,
    onSoldOut: () -> Unit,
    onDelete: () -> Unit,
    onTickets: (() -> Unit)?,
    onPoster: () -> Unit,
    onSocialPreview: () -> Unit = {},
) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(dateRange(date, zone), style = MaterialTheme.typography.titleMedium)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                StatusBadge(date.status.label)
                if (date.past) StatusBadge(stringResource(R.string.management_dates_past_badge))
                if (date.series) StatusBadge(stringResource(R.string.management_dates_series_badge))
                date.highlight?.takeIf { it.isNotBlank() }?.let { StatusBadge(it) }
            }
            val place = if (venue) date.place else date.venue?.name ?: stringResource(R.string.management_dates_main_venue)
            place?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
            if (venue) Text(
                date.capacityLeft?.let { left -> date.capacity?.let { stringResource(R.string.management_dates_capacity, left, it) } ?: stringResource(R.string.management_dates_capacity_left, left) }
                    ?: stringResource(R.string.management_dates_capacity_none),
                color = Muted,
            )
            if (date.lineup.isNotEmpty()) Text(stringResource(R.string.management_dates_lineup, date.lineup.joinToString(", ") { it.name }), color = Muted)
            date.statusNote?.takeIf { it.isNotBlank() }?.let { Text(RichText.plain(RichText.toMarkup(it)), color = Muted) }
            date.stats?.let { stats -> Text(stringResource(R.string.management_dates_stats, stats.views, stats.clicks, stats.saves), color = Muted) }

            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (date.abilities.update) OutlinedButton(enabled = !busy, onClick = onEdit, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_dates_edit)) }
                if (date.abilities.soldOut) OutlinedButton(enabled = !busy, onClick = onSoldOut, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (date.soldOut) R.string.management_dates_available else R.string.management_dates_sold_out))
                }
                if (date.abilities.cancel) OutlinedButton(enabled = !busy, onClick = onCancel, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_dates_cancel), color = MaterialTheme.colorScheme.error)
                }
                onTickets?.let { open -> OutlinedButton(onClick = open, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_dates_tickets)) } }
                if (date.socialPreview) OutlinedButton(enabled = !busy, onClick = onSocialPreview, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_date_preview)) }
                if (date.posterPdf) OutlinedButton(enabled = !busy, onClick = onPoster, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_dates_poster)) }
                if (date.abilities.delete) OutlinedButton(enabled = !busy, onClick = onDelete, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_dates_delete), color = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
}

/**
 * «Annulla data»: la scelta «solo questa» / «questa e le successive»
 * compare solo quando la data fa parte di una serie, come nel pannello.
 */
@Composable
private fun CancelDateDialog(date: ManagedEventOccurrence, zone: ZoneId, onDismiss: () -> Unit, onConfirm: (Boolean) -> Unit) {
    var series by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.management_dates_cancel_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(stringResource(R.string.management_dates_cancel_confirm, dayLabel(date.startsAt, zone)))
                if (date.series) {
                    ScopeOption(stringResource(R.string.management_dates_scope_single), stringResource(R.string.management_dates_scope_single_help), !series) { series = false }
                    ScopeOption(stringResource(R.string.management_dates_scope_series), stringResource(R.string.management_dates_scope_series_help), series) { series = true }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onConfirm(series) }) { Text(stringResource(R.string.management_dates_cancel), color = MaterialTheme.colorScheme.error) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_dates_keep)) } },
    )
}

@Composable
private fun ScopeOption(title: String, help: String, selected: Boolean, onSelect: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = selected, onClick = onSelect, role = Role.RadioButton),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = null)
        Column(Modifier.padding(start = 8.dp)) {
            Text(title)
            Text(help, color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

/** «Ripeti»: frequenza, giorni della settimana (solo settimanale e quindicinale), «fino al». */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun RecurrenceDialog(current: EventRecurrenceState?, zone: ZoneId, onDismiss: () -> Unit, onConfirm: (String, Set<String>, LocalDate?) -> Unit) {
    var frequency by remember { mutableStateOf(current?.frequency ?: "weekly") }
    var weekdays by remember { mutableStateOf(current?.weekdays?.toSet() ?: emptySet()) }
    var until by remember { mutableStateOf(current?.until?.let { runCatching { LocalDate.parse(it) }.getOrNull() }) }
    var pick by remember { mutableStateOf(false) }
    val frequencies = listOf(
        "daily" to stringResource(R.string.management_dates_frequency_daily),
        "weekly" to stringResource(R.string.management_dates_frequency_weekly),
        "biweekly" to stringResource(R.string.management_dates_frequency_biweekly),
        "monthly" to stringResource(R.string.management_dates_frequency_monthly),
    )
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.management_dates_repeat_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(stringResource(R.string.management_dates_repeat_hint), color = Muted)
                ChoiceField(stringResource(R.string.management_dates_frequency), frequencies, frequency, { frequency = it ?: "weekly" })
                if (frequency == "weekly" || frequency == "biweekly") {
                    MultiChips(stringResource(R.string.management_dates_weekdays), EventDatesViewModel.WEEKDAYS.map { it to weekdayLabel(it) }, weekdays, { weekdays = it },
                        help = stringResource(R.string.management_dates_weekdays_hint))
                }
                Text(stringResource(R.string.management_dates_until), style = MaterialTheme.typography.titleSmall)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { pick = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(until?.format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ITALIAN)) ?: stringResource(R.string.management_dates_until_pick))
                    }
                    if (until != null) TextButton(onClick = { until = null }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_dates_until_clear)) }
                }
                Text(stringResource(R.string.management_dates_until_hint), color = Muted)
            }
        },
        confirmButton = { TextButton(onClick = { onConfirm(frequency, if (frequency == "weekly" || frequency == "biweekly") weekdays else emptySet(), until) }) { Text(stringResource(R.string.management_dates_repeat_submit)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_cancel)) } },
    )
    if (pick) {
        val picker = rememberDatePickerState(initialSelectedDateMillis = pickerMillis(until ?: LocalDate.now(zone)))
        DatePickerDialog(
            onDismissRequest = { pick = false },
            confirmButton = { TextButton(onClick = { pick = false; picker.selectedDateMillis?.let { until = pickerDate(it) } }) { Text(stringResource(R.string.management_datetime_done)) } },
            dismissButton = { TextButton(onClick = { pick = false }) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = picker) }
    }
}

private val dayFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ITALIAN)
private val timeFormat = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

/** «gio 11 set 2026, 21:00 – 23:30» nella città dell'evento. */
internal fun dateRange(date: ManagedEventOccurrence, zone: ZoneId): String {
    val start = runCatching { OffsetDateTime.parse(date.startsAt).atZoneSameInstant(zone) }.getOrNull() ?: return date.startsAt
    val end = date.endsAt?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(zone) }.getOrNull() }
    val day = start.format(dayFormat)
    if (date.isAllDay) return day
    return "$day, ${start.format(timeFormat)}" + (end?.let { if (it.toLocalDate() == start.toLocalDate()) " – ${it.format(timeFormat)}" else " – ${it.format(dayFormat)}, ${it.format(timeFormat)}" } ?: "")
}

/** Il giorno di una data, per le conferme che dicono quale data stanno per toccare. */
internal fun dayLabel(iso: String, zone: ZoneId): String =
    runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(dayFormat) }.getOrDefault(iso)

private fun isoDay(value: String): String = runCatching { LocalDate.parse(value).format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ITALIAN)) }.getOrDefault(value)

/** Il nome del giorno in italiano per i valori `mon`…`sun` del server. */
internal fun weekdayLabel(value: String): String {
    val index = EventDatesViewModel.WEEKDAYS.indexOf(value)
    if (index < 0) return value
    return DayOfWeek.of(index + 1).getDisplayName(TextStyle.FULL, Locale.ITALIAN).replaceFirstChar { it.titlecase(Locale.ITALIAN) }
}
