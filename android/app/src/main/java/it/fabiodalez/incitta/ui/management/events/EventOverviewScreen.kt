package it.fabiodalez.incitta.ui.management.events

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.ManagedEventDate
import it.fabiodalez.incitta.data.management.ManagedEventDetail
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.prepareImage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.imageErrorText
import it.fabiodalez.incitta.ui.management.common.rememberImagePicker
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/* Le conferme della scheda: una alla volta. */
private enum class PendingAction { Publish, Submit, Duplicate, Delete, Schedule, RemovePoster }

/**
 * La scheda in lettura (W-EV-04…06, W-EV-22, W-NT-02): locandina, stato e
 * motivo del rifiuto, riquadro «Pubblicazione», informazioni, descrizione,
 * serate; le azioni secondo `abilities` (pubblica o invia alla redazione,
 * modifica, duplica, programma, anteprima, elimina) e i collegamenti
 * (biglietteria della prossima data, riquadro «Ticketing inCittà» del
 * locale, scheda pubblica).
 */
@Composable
internal fun EventOverviewScreen(
    viewModel: EventOverviewViewModel,
    context: ManagementGraphContext,
    onEdit: (Long) -> Unit,
    onPreview: (Long) -> Unit,
    onTickets: (Long) -> Unit,
    onOpenCopy: (Long) -> Unit,
    onEventTicketing: (venueId: Long, eventId: Long) -> Unit,
    onDates: (Long) -> Unit = {},
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    // Tornando dalle date (P2) la scheda rilegge le serate in breve.
    var shown by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (shown) viewModel.load() else shown = true }
    val event = state.event.valueOrNull
    val zone = event?.city?.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: state.subject?.timezone ?: ZoneId.of("Europe/Rome")
    var pending by remember { mutableStateOf<PendingAction?>(null) }
    val uriHandler = LocalUriHandler.current
    val androidContext = LocalContext.current
    val scope = rememberCoroutineScope()
    val pickPoster = rememberImagePicker { uri ->
        scope.launch {
            try { viewModel.uploadPoster(withContext(Dispatchers.IO) { prepareImage(androidContext, uri) }) }
            catch (e: ImageUploadException) { viewModel.posterError(UiText.Res(imageErrorText(e.reason))) }
        }
    }

    LaunchedEffect(state.duplicatedId, state.deleted) {
        state.duplicatedId?.let { viewModel.consumeNavigation(); onOpenCopy(it) }
        if (state.deleted) { viewModel.consumeNavigation(); context.back() }
    }

    ManagementScaffold(
        title = event?.title ?: stringResource(R.string.management_events_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = event?.let { listOfNotNull(it.status.label, it.venue?.name).joinToString(" · ") },
        busy = state.busy,
    ) {
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        LoadState(state.event, onRetry = viewModel::load) { detail ->
            Overview(detail, zone, state.busy,
                onAction = { pending = it },
                onEdit = { onEdit(detail.id) },
                onPreview = { onPreview(detail.id) },
                onTickets = onTickets,
                onEventTicketing = state.subject?.takeIf { showsEventTicketing(it, detail) }?.let { subject -> { onEventTicketing(subject.id, detail.id) } },
                onDates = { onDates(detail.id) },
                onSite = { detail.publicUrl?.let(uriHandler::openUri) },
                onPickPoster = pickPoster,
                onCancelSchedule = viewModel::cancelSchedule)
        }
    }

    when (pending) {
        PendingAction.Publish -> ConfirmDialog(
            title = stringResource(if (event?.abilities?.publishDirectly == true) R.string.management_events_publish else R.string.management_events_publish_to_review),
            text = stringResource(if (event?.abilities?.publishDirectly == true) R.string.management_events_publish_confirm else R.string.management_events_publish_review_confirm),
            confirmLabel = stringResource(if (event?.abilities?.publishDirectly == true) R.string.management_events_publish else R.string.management_events_publish_to_review),
            onConfirm = { pending = null; viewModel.publish() }, onDismiss = { pending = null })
        PendingAction.Submit -> ConfirmDialog(stringResource(R.string.management_events_submit), stringResource(R.string.management_events_submit_confirm),
            stringResource(R.string.management_events_submit), onConfirm = { pending = null; viewModel.submit() }, onDismiss = { pending = null })
        PendingAction.Duplicate -> ConfirmDialog(stringResource(R.string.management_events_duplicate), stringResource(R.string.management_events_duplicate_confirm),
            stringResource(R.string.management_events_duplicate), onConfirm = { pending = null; viewModel.duplicate() }, onDismiss = { pending = null })
        PendingAction.Delete -> ConfirmDialog(stringResource(R.string.management_events_delete_title), stringResource(R.string.management_events_delete_confirm),
            stringResource(R.string.management_events_delete), onConfirm = { pending = null; viewModel.delete() }, onDismiss = { pending = null }, destructive = true)
        PendingAction.RemovePoster -> ConfirmDialog(stringResource(R.string.management_events_poster_remove), stringResource(R.string.management_events_poster_hint),
            stringResource(R.string.management_events_poster_remove), onConfirm = { pending = null; viewModel.removePoster() }, onDismiss = { pending = null }, destructive = true)
        PendingAction.Schedule -> ScheduleDialog(zone, event?.city?.name, onDismiss = { pending = null }, onConfirm = { pending = null; viewModel.schedule(it) })
        null -> Unit
    }
}


@Composable
private fun Overview(
    detail: ManagedEventDetail,
    zone: ZoneId,
    busy: Boolean,
    onAction: (PendingAction) -> Unit,
    onEdit: () -> Unit,
    onPreview: () -> Unit,
    onTickets: (Long) -> Unit,
    onEventTicketing: (() -> Unit)?,
    onSite: () -> Unit,
    onPickPoster: () -> Unit,
    onCancelSchedule: () -> Unit,
    onDates: () -> Unit,
) {
    val can = detail.abilities
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val posterLabel = stringResource(R.string.management_events_poster, detail.title)
        PosterImage(detail.poster?.card ?: detail.poster?.full, Modifier.fillMaxWidth().height(220.dp).semantics { contentDescription = posterLabel }, contain = true)
        if (can.update) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(enabled = !busy, onClick = onPickPoster, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (detail.poster == null) R.string.management_events_poster_choose else R.string.management_events_poster_change))
                }
                if (detail.poster != null) OutlinedButton(enabled = !busy, onClick = { onAction(PendingAction.RemovePoster) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_events_poster_remove))
                }
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            StatusBadge(detail.status.label)
            if (detail.past) StatusBadge(stringResource(R.string.management_events_past_badge))
            if (detail.source.imported) StatusBadge(detail.source.label)
        }
        detail.rejectionReason?.takeIf { it.isNotBlank() }?.let {
            Text(stringResource(R.string.management_events_rejected_reason), style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.error)
            Text(it)
        }

        // Le azioni principali, come nella testata del pannello.
        if (can.update) Button(enabled = !busy, onClick = onEdit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_edit)) }
        if (can.publish) {
            Button(enabled = !busy && !can.publishNeedsDates, onClick = { onAction(PendingAction.Publish) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(if (can.publishDirectly) R.string.management_events_publish else R.string.management_events_publish_to_review))
            }
            if (can.publishNeedsDates) Text(stringResource(R.string.management_events_publish_needs_dates), color = Muted)
        }
        if (can.submit) Button(enabled = !busy, onClick = { onAction(PendingAction.Submit) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_events_submit))
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (can.duplicate) OutlinedButton(enabled = !busy, onClick = { onAction(PendingAction.Duplicate) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_duplicate)) }
            if (can.preview) OutlinedButton(onClick = onPreview, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_preview)) }
            detail.links.ticketsOccurrenceId?.let { id -> OutlinedButton(onClick = { onTickets(id) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_tickets)) } }
            onEventTicketing?.let { open -> OutlinedButton(onClick = open, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_event_ticketing_title)) } }
            if (detail.publicUrl != null) OutlinedButton(onClick = onSite, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_site)) }
            if (can.schedule) OutlinedButton(enabled = !busy, onClick = { onAction(PendingAction.Schedule) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_schedule)) }
            if (can.cancelSchedule) OutlinedButton(enabled = !busy, onClick = onCancelSchedule, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_schedule_cancel)) }
            if (can.delete) OutlinedButton(enabled = !busy, onClick = { onAction(PendingAction.Delete) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_events_delete), color = MaterialTheme.colorScheme.error)
            }
        }

        if (can.schedule || can.cancelSchedule || detail.scheduledPublishAt != null) {
            SectionTitle(stringResource(R.string.management_events_publication))
            Text(detail.status.label, style = MaterialTheme.typography.titleMedium)
            if (detail.scheduledPublishAt != null) {
                Text(stringResource(R.string.management_events_scheduled_for, formatEventDate(detail.scheduledPublishAt, zone)))
                if (detail.status.value == "pending") Text(stringResource(R.string.management_events_scheduled_waiting), color = Muted)
            } else {
                Text(stringResource(R.string.management_events_not_scheduled), color = Muted)
            }
            Text(stringResource(R.string.management_events_verification_note), color = Muted)
        }

        SectionTitle(stringResource(R.string.management_events_info))
        InfoLine(stringResource(R.string.management_events_category), detail.category?.name)
        InfoLine(stringResource(R.string.management_events_venue), detail.venue?.name)
        InfoLine(stringResource(R.string.management_events_organizer), detail.organizer?.name ?: detail.organizerName ?: stringResource(R.string.management_events_no_organizer))
        InfoLine(stringResource(R.string.management_events_price), priceText(detail))
        InfoLine(stringResource(R.string.management_events_tags), detail.tags.joinToString(", ") { it.name }.ifBlank { null })

        SectionTitle(stringResource(R.string.management_events_description))
        val text = detail.descriptionText?.takeIf { it.isNotBlank() } ?: RichText.plain(RichText.toMarkup(detail.description)).takeIf { it.isNotBlank() }
        Text(text ?: stringResource(R.string.management_events_no_description), color = if (text == null) Muted else MaterialTheme.colorScheme.onSurface)

        SectionTitle(stringResource(R.string.management_events_dates))
        // Le date dell'evento (P2): elenco, aggiungi, modifica, annulla, «Ripeti».
        OutlinedButton(onClick = onDates, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.management_dates_open_manage)) }
        if (detail.dates.upcoming.isEmpty() && detail.dates.past.isEmpty()) Text(stringResource(R.string.management_events_no_dates_long), color = Muted)
        if (detail.dates.upcoming.isNotEmpty()) {
            Text(stringResource(R.string.management_events_upcoming_dates), style = MaterialTheme.typography.titleSmall)
            detail.dates.upcoming.forEach { DateRow(it, zone) }
        }
        if (detail.dates.past.isNotEmpty()) {
            Text(stringResource(R.string.management_events_past_dates), style = MaterialTheme.typography.titleSmall)
            detail.dates.past.forEach { DateRow(it, zone) }
        }
    }
}

/**
 * Il riquadro «Ticketing inCittà» dell'evento, come nella modifica evento
 * del pannello del locale: solo per un locale, solo a chi può modificare
 * l'evento (`EventTicketingRequest` chiede `EventPolicy::update`) e gestisce
 * la biglietteria (`permissions.ticketing_manage`: il collaboratore no,
 * decisione 6), e solo per i prezzi che il pannello accompagna con il
 * riquadro (ingresso libero, biglietto, tessera).
 */
internal fun showsEventTicketing(subject: ManagementSubject, detail: ManagedEventDetail): Boolean =
    subject.kind == SubjectKind.Venue && subject.permissions.ticketingManage && detail.abilities.update &&
        detail.priceType.value in TICKETING_PRICE_TYPES

private val TICKETING_PRICE_TYPES = setOf("free", "ticket", "membership")

@Composable
private fun priceText(detail: ManagedEventDetail): String {
    val min = detail.priceMin
    val max = detail.priceMax
    val amount = when {
        min != null && max != null && max != min -> stringResource(R.string.management_events_price_from_to, money(min), money(max))
        min != null -> stringResource(R.string.management_events_price_from, money(min))
        else -> null
    }
    return listOfNotNull(detail.priceType.label.ifBlank { null }, amount).joinToString(" · ")
}

private fun money(value: Double): String = String.format(Locale.ITALIAN, "%.2f", value)

@Composable
private fun DateRow(date: ManagedEventDate, zone: ZoneId) {
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        val end = date.endsAt?.let { iso -> runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(timeFormat) }.getOrNull() }
        Text(formatEventDate(date.startsAt, zone) + (end?.let { " – $it" } ?: ""), style = MaterialTheme.typography.titleSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            StatusBadge(date.status.label)
            date.highlight?.takeIf { it.isNotBlank() }?.let { StatusBadge(it) }
        }
        date.place?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
        date.doorsAt?.let { iso -> runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(timeFormat) }.getOrNull() }?.let {
            Text(stringResource(R.string.management_events_doors, it), color = Muted)
        }
        if (date.lineup.isNotEmpty()) Text(stringResource(R.string.management_events_lineup, date.lineup.joinToString(", ")), color = Muted)
        date.capacity?.let { total ->
            Text(date.capacityLeft?.let { stringResource(R.string.management_events_capacity, it, total) } ?: stringResource(R.string.management_events_capacity_total, total), color = Muted)
        }
        date.statusNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
    }
    HorizontalDivider()
}

private val timeFormat = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

@Composable
internal fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 8.dp))
}

@Composable
private fun InfoLine(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Column {
        Text(label, style = MaterialTheme.typography.labelLarge, color = Muted)
        Text(value)
    }
}

/** «Programma pubblicazione»: data e ora nella città dell'evento, nel futuro. */
@Composable
private fun ScheduleDialog(zone: ZoneId, cityName: String?, onDismiss: () -> Unit, onConfirm: (LocalDateTime) -> Unit) {
    var at by remember { mutableStateOf<LocalDateTime?>(null) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.management_events_schedule)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.management_events_schedule_hint), color = Muted)
                CityDateTimeField(stringResource(R.string.management_events_schedule_at), at, zone, cityName, onChange = { at = it })
            }
        },
        confirmButton = {
            TextButton(enabled = at != null, onClick = { at?.let(onConfirm) }) { Text(stringResource(R.string.management_events_schedule_save)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_cancel)) } },
    )
}
