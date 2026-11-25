package it.fabiodalez.incitta.ui.management.events

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.EventsTab
import it.fabiodalez.incitta.data.management.ManagedEventCard
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * L'elenco eventi (W-EV-01…03): le quattro schede del pannello, la ricerca
 * per titolo, il filtro di stato, una card per evento con locandina, titolo,
 * prossima o ultima data, stato e provenienza. L'organizzazione vede anche il
 * locale, perché lavora in posti diversi.
 */
@Composable
internal fun EventsListScreen(
    viewModel: EventsListViewModel,
    context: ManagementGraphContext,
    onOpen: (Long) -> Unit,
    onCreate: () -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = state.subject
    ManagementScaffold(
        title = stringResource(R.string.management_events_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.busy && state.events.isNotEmpty(),
    ) {
        if (subject?.permissions?.eventsCreate == true) {
            Button(onClick = onCreate, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.Outlined.Add, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.management_events_new))
            }
        }
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            EventsTab.entries.forEach { tab ->
                FilterChip(
                    selected = state.tab == tab,
                    onClick = { viewModel.showTab(tab) },
                    label = { Text(stringResource(tabLabel(tab))) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        OutlinedTextField(
            value = state.query,
            onValueChange = viewModel::typeQuery,
            label = { Text(stringResource(R.string.management_events_search)) },
            singleLine = true,
            trailingIcon = {
                IconButton(onClick = viewModel::search, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.Search, contentDescription = stringResource(R.string.management_events_search_action))
                }
            },
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { viewModel.search() }),
            modifier = Modifier.fillMaxWidth(),
        )
        StatusFilter(state.status, viewModel::filterStatus)

        when {
            !state.loaded && state.busy -> {
                val loading = stringResource(R.string.management_loading)
                LinearProgressIndicator(Modifier.fillMaxWidth().semantics { contentDescription = loading })
            }
            state.error != null && state.events.isEmpty() -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(state.error.orEmpty(), color = MaterialTheme.colorScheme.error)
                OutlinedButton(onClick = viewModel::refresh, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
            }
            state.loaded && state.events.isEmpty() -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                val filtered = state.query.isNotBlank() || state.status != null || state.tab != EventsTab.All
                Text(stringResource(if (filtered) R.string.management_events_empty_filtered else R.string.management_events_empty), style = MaterialTheme.typography.titleMedium)
                if (!filtered) {
                    Text(stringResource(R.string.management_events_empty_hint), color = Muted)
                    if (subject?.permissions?.eventsCreate == true) {
                        OutlinedButton(onClick = onCreate, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_events_new)) }
                    }
                }
            }
            else -> {
                state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                state.events.forEach { event ->
                    EventCardRow(event, subject?.timezone ?: ZoneId.of("Europe/Rome"), showVenue = subject?.kind == SubjectKind.Organizer, onClick = { onOpen(event.id) })
                    HorizontalDivider()
                }
                if (state.nextPage != null) {
                    OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.management_events_more))
                    }
                }
            }
        }
    }
}

@Composable
private fun StatusFilter(selected: String?, onSelect: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        OutlinedButton(onClick = { open = true }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_events_status_filter) + ": " + (selected?.let { stringResource(statusLabel(it)) } ?: stringResource(R.string.management_events_status_any)))
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(text = { Text(stringResource(R.string.management_events_status_any)) }, onClick = { open = false; onSelect(null) }, modifier = Modifier.heightIn(min = 48.dp))
            EVENT_STATUSES.forEach { status ->
                DropdownMenuItem(text = { Text(stringResource(statusLabel(status))) }, onClick = { open = false; onSelect(status) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
}

/** Una card: locandina a sinistra, titolo, data, distintivi. */
@Composable
private fun EventCardRow(event: ManagedEventCard, zone: ZoneId, showVenue: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 72.dp).clickable(role = Role.Button, onClick = onClick).padding(vertical = 8.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        val posterLabel = stringResource(R.string.management_events_poster, event.title)
        PosterImage(event.poster?.thumb ?: event.poster?.card, Modifier.size(width = 64.dp, height = 80.dp).semantics { contentDescription = posterLabel })
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(event.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text(listOfNotNull(whenLabel(event, zone), if (showVenue) event.venue?.name else null).joinToString(" · "), color = Muted)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                StatusBadge(event.status.label.ifBlank { stringResource(statusLabel(event.status.value)) })
                if (event.past) StatusBadge(stringResource(R.string.management_events_past_badge))
                if (event.source.imported) StatusBadge(event.source.label)
            }
        }
    }
}

@Composable
internal fun StatusBadge(text: String) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.colorScheme.secondaryContainer) {
        Text(text, style = MaterialTheme.typography.labelMedium, modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp))
    }
}

@Composable
private fun whenLabel(event: ManagedEventCard, zone: ZoneId): String = when {
    event.nextStartsAt != null -> formatEventDate(event.nextStartsAt, zone)
    event.lastStartsAt != null -> stringResource(R.string.management_events_was, formatEventDate(event.lastStartsAt, zone))
    else -> stringResource(R.string.management_events_no_dates)
}

private val eventDateFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)

/** «sab 22 nov 2026, 21:00» nell'ora della città. */
internal fun formatEventDate(iso: String, zone: ZoneId): String =
    runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(eventDateFormat) }.getOrDefault(iso)

internal val EVENT_STATUSES = listOf("draft", "pending", "published", "rejected", "cancelled", "archived")

internal fun statusLabel(status: String): Int = when (status) {
    "draft" -> R.string.management_events_status_draft
    "pending" -> R.string.management_events_status_pending
    "published" -> R.string.management_events_status_published
    "rejected" -> R.string.management_events_status_rejected
    "cancelled" -> R.string.management_events_status_cancelled
    else -> R.string.management_events_status_archived
}

private fun tabLabel(tab: EventsTab): Int = when (tab) {
    EventsTab.All -> R.string.management_events_tab_all
    EventsTab.Upcoming -> R.string.management_events_tab_upcoming
    EventsTab.Past -> R.string.management_events_tab_past
    EventsTab.Drafts -> R.string.management_events_tab_drafts
}
