package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.History
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialEventDetail
import it.fabiodalez.incitta.data.management.ManagedEventDate
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.ZoneId

/**
 * La scheda di un evento in sola lettura (R-EV-07): locandina, stato, data
 * che conta, informazioni, descrizione, date nel fuso della città e il
 * riquadro «Redazione», con le decisioni permesse (R-EV-08…14, 17), le
 * pagine collegate (statistiche, grafiche social, sponsorizzazione: sul sito
 * finché l'app non le ha; la scheda pubblica), l'anteprima e la cronologia.
 */
@Composable
internal fun EventRecordScreen(
    viewModel: EventRecordViewModel,
    padding: PaddingValues,
    startWithMerge: Boolean,
    onBack: () -> Unit,
    onOpenEvent: (Long) -> Unit,
    onPreview: (Long) -> Unit,
    onActivity: (Long) -> Unit,
    onOpenWeb: (String) -> Unit,
    /* «Modifica» e «Gestisci le date» nell'app (R3); `null`: sul sito, come prima. */
    onEdit: ((Long) -> Unit)? = null,
    onDates: ((Long) -> Unit)? = null,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val action by viewModel.action.collectAsStateWithLifecycle()
    val copy by viewModel.openCopy.collectAsStateWithLifecycle()
    val trashed by viewModel.trashed.collectAsStateWithLifecycle()
    val candidates by viewModel.candidates.collectAsStateWithLifecycle()
    var pending by remember { mutableStateOf<String?>(null) }
    var merging by remember { mutableStateOf(startWithMerge) }
    LaunchedEffect(copy) { copy?.let { viewModel.consumeCopy(); onOpenEvent(it) } }
    LaunchedEffect(trashed) { if (trashed) onBack() }
    LaunchedEffect(action.busy) { if (!action.busy) { pending = null } }

    val detail = (state as? Loadable.Ready)?.value
    ManagementScaffold(title = detail?.title ?: stringResource(R.string.editorial_events_title), padding = padding, onBack = onBack, busy = action.busy) {
        LoadState(state, onRetry = viewModel::load) { event ->
            EventRecordContent(
                event = event,
                busy = action.busy,
                message = action.error ?: action.message,
                isError = action.error != null,
                onDismissMessage = viewModel::consumeMessage,
                onAction = { key -> if (key == EventActionKeys.MERGE) merging = true else pending = key },
                onPreview = { onPreview(event.id) },
                onActivity = { onActivity(event.id) },
                onOpenWeb = onOpenWeb,
                onEdit = onEdit?.let { edit -> { edit(event.id) } },
                onDates = onDates?.let { dates -> { dates(event.id) } },
            )
        }
    }
    if (detail != null) {
        pending?.let { key ->
            EventDecisionDialog(
                key = key, featured = detail.isFeatured, approvesSchedule = detail.approvesSchedule(),
                zone = detail.city.zone, cityName = detail.city.name, impact = detail.cancelImpact, busy = action.busy,
                onDecision = viewModel::decide, onDismiss = { pending = null },
            )
        }
        if (merging && detail.can[EventActionKeys.MERGE] == true) {
            MergeEventSheet(
                candidates = candidates,
                busy = action.busy,
                onSearch = viewModel::searchCandidates,
                onMerge = { absorbed -> merging = false; viewModel.decide(EventDecision.Merge(absorbed)) },
                onDismiss = { merging = false },
            )
        }
    }
}

/** Il contenuto della scheda, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun EventRecordContent(
    event: EditorialEventDetail,
    busy: Boolean,
    message: String?,
    isError: Boolean,
    onDismissMessage: () -> Unit,
    onAction: (String) -> Unit,
    onPreview: () -> Unit,
    onActivity: () -> Unit,
    onOpenWeb: (String) -> Unit,
    onEdit: (() -> Unit)? = null,
    onDates: (() -> Unit)? = null,
) {
    val zone = event.city.zone
    val can = event.can
    val uriHandler = LocalUriHandler.current
    val approves = event.approvesSchedule()
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        message?.let {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(it, color = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
                TextButton(onClick = onDismissMessage, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_close)) }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            PosterImage(event.poster?.card ?: event.poster?.thumb, Modifier.width(96.dp).height(128.dp).clip(RoundedCornerShape(10.dp)))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    AssistChip(onClick = {}, label = { Text(event.status.label) })
                    if (event.past) AssistChip(onClick = {}, label = { Text(stringResource(R.string.editorial_events_past)) })
                    if (event.source.imported) AssistChip(onClick = {}, label = { Text(event.source.label) })
                    if (event.trashed) AssistChip(onClick = {}, label = { Text(stringResource(R.string.editorial_events_trashed)) })
                }
                val upcoming = event.dates.upcoming.firstOrNull()
                val last = event.dates.past.firstOrNull()
                Text(stringResource(if (upcoming != null) R.string.editorial_event_next_date else R.string.editorial_event_last_date), color = Muted)
                Text(editorialMoment((upcoming ?: last)?.startsAt, zone) ?: stringResource(R.string.editorial_events_no_dates), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                ((upcoming ?: last)?.place ?: event.venue?.name)?.let { Text(it, color = Muted) }
            }
        }

        // Le decisioni: la principale in vista, le altre nel menu (solo quelle permesse).
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (can[EventActionKeys.PUBLISH] == true) {
                Button(onClick = { onAction(EventActionKeys.PUBLISH) }, enabled = !busy, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text(eventActionLabel(EventActionKeys.PUBLISH, event.isFeatured, approves))
                }
            } else {
                Spacer(Modifier.weight(1f))
            }
            val more = eventRowActions { it }.filter { it.key != EventActionKeys.OPEN && it.key != EventActionKeys.PUBLISH }
                .map { it.copy(label = eventActionLabel(it.key, event.isFeatured, approves)) }
            ActionMenu(actions = more, abilities = can, onAction = { onAction(it.key) }, subject = event.title)
        }

        Section(stringResource(R.string.editorial_event_pages)) {
            if (can["preview"] == true) LinkButton(stringResource(R.string.editorial_events_action_preview), Icons.Outlined.Visibility, onPreview)
            if (can["activity"] == true) LinkButton(stringResource(R.string.editorial_events_action_activity), Icons.Outlined.History, onActivity)
            event.links.site?.let { url -> LinkButton(stringResource(R.string.editorial_events_action_site), Icons.AutoMirrored.Outlined.OpenInNew) { runCatching { uriHandler.openUri(url) } } }
            event.links.statistics?.let { path -> LinkButton(stringResource(R.string.editorial_events_action_statistics), Icons.AutoMirrored.Outlined.OpenInNew) { onOpenWeb(path) } }
            event.links.social?.let { path -> LinkButton(stringResource(R.string.editorial_events_action_social), Icons.AutoMirrored.Outlined.OpenInNew) { onOpenWeb(path) } }
            event.links.sponsor?.let { path -> LinkButton(stringResource(R.string.editorial_events_action_sponsor), Icons.AutoMirrored.Outlined.OpenInNew) { onOpenWeb(path) } }
            if (can["edit"] == true && onEdit != null) {
                LinkButton(stringResource(R.string.editorial_ee_edit), Icons.Outlined.Edit, onEdit)
                onDates?.let { LinkButton(stringResource(R.string.editorial_ee_open_dates), Icons.Outlined.CalendarMonth, it) }
            } else if (can["edit"] == true) {
                LinkButton(stringResource(R.string.editorial_event_edit_web), Icons.AutoMirrored.Outlined.OpenInNew) { onOpenWeb("admin/events/${event.id}/edit") }
                Text(stringResource(R.string.editorial_event_edit_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }

        Section(stringResource(R.string.editorial_event_info)) {
            InfoLine(stringResource(R.string.editorial_event_category), event.category?.name)
            InfoLine(stringResource(R.string.editorial_event_venue), event.venue?.name)
            InfoLine(stringResource(R.string.editorial_event_city), event.city.name)
            InfoLine(stringResource(R.string.editorial_event_organizer), event.organizer)
            InfoLine(stringResource(R.string.editorial_event_price), priceLine(event))
            InfoLine(stringResource(R.string.editorial_event_tags), event.tags.joinToString(", ").ifBlank { null })
        }

        Section(stringResource(R.string.editorial_event_description)) {
            Text(event.descriptionText?.takeIf { it.isNotBlank() } ?: stringResource(R.string.editorial_event_no_description))
        }

        Section(stringResource(R.string.editorial_event_dates)) {
            if (event.dates.upcoming.isEmpty() && event.dates.past.isEmpty()) Text(stringResource(R.string.editorial_event_no_date_rows), color = Muted)
            if (event.dates.upcoming.isNotEmpty() && event.dates.past.isNotEmpty()) Text(stringResource(R.string.editorial_event_upcoming), fontWeight = FontWeight.SemiBold)
            event.dates.upcoming.forEach { DateCard(it, zone) }
            if (event.dates.upcoming.isNotEmpty() && event.dates.past.isNotEmpty()) Text(stringResource(R.string.editorial_event_past_dates), fontWeight = FontWeight.SemiBold)
            event.dates.past.forEach { DateCard(it, zone) }
        }

        Section(stringResource(R.string.editorial_event_editorial)) {
            val info = event.editorial
            InfoLine(stringResource(R.string.editorial_event_source), info.source)
            InfoLine(stringResource(R.string.editorial_event_verification), info.verification)
            InfoLine(stringResource(R.string.editorial_event_created_by), info.createdBy)
            InfoLine(stringResource(R.string.editorial_event_created_at), editorialMoment(info.createdAt, zone))
            InfoLine(stringResource(R.string.editorial_event_updated_at), editorialMoment(info.updatedAt, zone))
            InfoLine(stringResource(R.string.editorial_event_published_at), editorialMoment(info.publishedAt, zone) ?: stringResource(R.string.editorial_event_never))
            InfoLine(stringResource(R.string.editorial_event_scheduled_at), editorialMoment(info.scheduledPublishAt, zone))
            if (event.isFeatured) InfoLine(stringResource(R.string.editorial_event_featured_until), editorialMoment(event.featuredUntil, zone) ?: stringResource(R.string.editorial_event_featured_open))
            InfoLine(stringResource(R.string.editorial_event_score), info.editorialScore.toString())
            InfoLine(stringResource(R.string.editorial_event_rejection), info.rejectionReason)
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun priceLine(event: EditorialEventDetail): String? {
    fun money(value: Double) = String.format(java.util.Locale.ITALIAN, "%.2f", value)
    val range = when {
        event.priceMin != null && event.priceMax != null && event.priceMin != event.priceMax -> stringResource(R.string.editorial_event_price_range, money(event.priceMin), money(event.priceMax))
        event.priceMin != null -> stringResource(R.string.editorial_event_price_from, money(event.priceMin))
        else -> null
    }
    return listOfNotNull(event.priceType.label.takeIf { it.isNotBlank() }, range).joinToString(" · ").ifBlank { null }
}

@Composable
private fun Section(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        content()
    }
}

@Composable
private fun InfoLine(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Column {
        Text(label, color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(value, style = MaterialTheme.typography.bodyLarge)
    }
}

@Composable
private fun LinkButton(label: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Icon(icon, contentDescription = null)
        Text(label, Modifier.padding(start = 8.dp).weight(1f))
    }
}

@Composable
private fun DateCard(date: ManagedEventDate, zone: ZoneId) {
    Surface(shape = RoundedCornerShape(10.dp), tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(editorialMoment(date.startsAt, zone) ?: date.startsAt, Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                Text(date.status.label, color = if (date.cancelled) MaterialTheme.colorScheme.error else Muted)
            }
            editorialMoment(date.endsAt, zone)?.let { Text(stringResource(R.string.editorial_event_date_ends, it), color = Muted) }
            date.place?.let { Text(it, color = Muted) }
            if (date.lineup.isNotEmpty()) Text(stringResource(R.string.editorial_event_date_lineup, date.lineup.joinToString(", ")), color = Muted)
            if (date.capacity != null && date.capacityLeft != null) Text(stringResource(R.string.editorial_event_date_capacity, date.capacityLeft, date.capacity), color = Muted)
            date.statusNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
            date.highlight?.takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        }
    }
}
