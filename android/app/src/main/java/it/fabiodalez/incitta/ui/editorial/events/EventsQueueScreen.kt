package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Download
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialChoice
import it.fabiodalez.incitta.data.editorial.EditorialEventFilters
import it.fabiodalez.incitta.data.editorial.EditorialEventRow
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import java.time.ZoneId

/**
 * L'elenco degli eventi della redazione (R-EV-01…04, 17): le schede del
 * pannello con i conteggi, i dodici filtri, la ricerca per titolo e locale,
 * le decisioni dal menu di ogni riga, l'eliminazione in gruppo (tenendo
 * premuto si scelgono le righe) e l'esportazione in Excel di quello che si
 * sta guardando.
 */
@Composable
internal fun EventsQueueScreen(
    viewModel: EventsQueueViewModel,
    padding: PaddingValues,
    showSubmissions: Boolean,
    onBack: () -> Unit,
    onOpen: (Long) -> Unit,
    onMerge: (Long) -> Unit,
    onSubmissions: () -> Unit,
    /* «Nuovo evento» (R3): `null` per chi non crea eventi. */
    onCreate: (() -> Unit)? = null,
) {
    val state by viewModel.rows.collectAsStateWithLifecycle()
    val filters by viewModel.filters.collectAsStateWithLifecycle()
    val action by viewModel.action.collectAsStateWithLifecycle()
    val selection by viewModel.selection.collectAsStateWithLifecycle()
    val exported by viewModel.exported.collectAsStateWithLifecycle()
    val copy by viewModel.openCopy.collectAsStateWithLifecycle()
    val context = LocalContext.current
    var pending by remember { mutableStateOf<Pair<EditorialEventRow, String>?>(null) }
    var confirmBulk by remember { mutableStateOf(false) }
    LaunchedEffect(copy) { copy?.let { viewModel.consumeCopy(); onOpen(it) } }
    LaunchedEffect(action.busy) { if (!action.busy) pending = null }

    val labels = EventFilterLabels.current()
    EventsQueueContent(
        state = state,
        filters = eventFilterDefinitions(filters, labels),
        padding = padding,
        selection = selection,
        busy = action.busy,
        message = action.error ?: action.message,
        isError = action.error != null,
        exportReady = exported != null,
        showSubmissions = showSubmissions,
        onBack = onBack,
        onSearch = viewModel.list::search,
        onLoadMore = viewModel.list::loadMore,
        onRetry = viewModel.list::retry,
        onTab = viewModel.list::selectTab,
        onFilters = viewModel.list::applyFilters,
        onOpen = { row -> if (selection.isNotEmpty()) viewModel.toggleSelection(row.id) else onOpen(row.id) },
        onSelect = { row -> if (row.can[EventActionKeys.DESTROY] == true) viewModel.toggleSelection(row.id) },
        onAction = { row, key ->
            when (key) {
                EventActionKeys.OPEN -> onOpen(row.id)
                EventActionKeys.MERGE -> onMerge(row.id)
                else -> pending = row to key
            }
        },
        onExport = { viewModel.export(context.cacheDir) },
        onShareExport = {
            exported?.let { SharedFiles.share(context, it.file, it.mime, context.getString(R.string.editorial_events_export_chooser)) }
            viewModel.consumeExport()
        },
        onOpenExport = {
            exported?.let { SharedFiles.open(context, it.file, it.mime, context.getString(R.string.editorial_events_export_chooser)) }
            viewModel.consumeExport()
        },
        onDeleteSelected = { confirmBulk = true },
        onClearSelection = viewModel::clearSelection,
        onDismissMessage = viewModel::consumeMessage,
        onSubmissions = onSubmissions,
        onCreate = onCreate,
    )
    pending?.let { (row, key) ->
        val zone = row.city?.zone ?: ZoneId.of("Europe/Rome")
        EventDecisionDialog(
            key = key, featured = row.isFeatured, approvesSchedule = false, zone = zone, cityName = row.city?.name,
            impact = null, busy = action.busy,
            onDecision = { viewModel.decide(row, it) }, onDismiss = { pending = null },
        )
    }
    if (confirmBulk) {
        val template = context.getString(R.string.editorial_events_bulk_done)
        ConfirmDialog(
            title = stringResource(R.string.editorial_events_bulk_title, selection.size),
            text = stringResource(R.string.editorial_events_bulk_text),
            confirmLabel = stringResource(R.string.editorial_events_action_destroy),
            onConfirm = { confirmBulk = false; viewModel.deleteSelected { deleted, skipped -> String.format(template, deleted, skipped) } },
            onDismiss = { confirmBulk = false },
            destructive = true,
        )
    }
}

/** Le etichette dei filtri, lette dalle risorse una volta per composizione. */
internal data class EventFilterLabels(
    val status: String, val city: String, val venue: String, val category: String, val source: String,
    val featured: String, val yes: String, val no: String, val flags: Map<String, String>,
    val trashed: String, val trashedWith: String, val trashedOnly: String,
) {
    companion object {
        @Composable
        fun current() = EventFilterLabels(
            status = stringResource(R.string.editorial_events_filter_status),
            city = stringResource(R.string.editorial_events_filter_city),
            venue = stringResource(R.string.editorial_events_filter_venue),
            category = stringResource(R.string.editorial_events_filter_category),
            source = stringResource(R.string.editorial_events_filter_source),
            featured = stringResource(R.string.editorial_events_filter_featured),
            yes = stringResource(R.string.editorial_events_filter_yes),
            no = stringResource(R.string.editorial_events_filter_no),
            flags = mapOf(
                "published_today" to stringResource(R.string.editorial_events_filter_published_today),
                "published_this_week" to stringResource(R.string.editorial_events_filter_published_this_week),
                "missing_poster" to stringResource(R.string.editorial_events_filter_missing_poster),
                "incomplete" to stringResource(R.string.editorial_events_filter_incomplete),
                "possible_duplicates" to stringResource(R.string.editorial_events_filter_possible_duplicates),
            ),
            trashed = stringResource(R.string.editorial_events_filter_trashed),
            trashedWith = stringResource(R.string.editorial_events_filter_trashed_with),
            trashedOnly = stringResource(R.string.editorial_events_filter_trashed_only),
        )
    }
}

/**
 * I dodici filtri della tabella del pannello nel foglio dei filtri, nello
 * stesso ordine: stato, città, locale (cercabile), categoria, provenienza,
 * in evidenza, i cinque «da guardare», il cestino. Un filtro senza scelte
 * (il server non le ha ancora mandate) non compare.
 */
internal fun eventFilterDefinitions(filters: EditorialEventFilters, labels: EventFilterLabels): List<FilterDefinition> = buildList {
    fun choices(list: List<EditorialChoice>) = list.map { it.toOption() }
    add(FilterDefinition(EventFilterKeys.STATUS, labels.status, choices(filters.statuses)))
    add(FilterDefinition(EventFilterKeys.CITY, labels.city, choices(filters.cities)))
    add(FilterDefinition(EventFilterKeys.VENUE, labels.venue, choices(filters.venues), searchable = true))
    add(FilterDefinition(EventFilterKeys.CATEGORY, labels.category, choices(filters.categories)))
    add(FilterDefinition(EventFilterKeys.SOURCE, labels.source, choices(filters.sources)))
    add(FilterDefinition(EventFilterKeys.FEATURED, labels.featured, listOf(OptionChoice("1", labels.yes), OptionChoice("0", labels.no))))
    EventFilterKeys.FLAGS.forEach { flag -> add(FilterDefinition(flag, labels.flags[flag] ?: flag, listOf(OptionChoice("1", labels.yes)))) }
    add(FilterDefinition(EventFilterKeys.TRASHED, labels.trashed, listOf(OptionChoice("with", labels.trashedWith), OptionChoice("only", labels.trashedOnly))))
}.filter { it.options.isNotEmpty() }

/** Lo schermo dell'elenco senza ViewModel: lo provano i test strumentati con uno stato preparato. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
internal fun EventsQueueContent(
    state: PagedState<EditorialEventRow>,
    filters: List<FilterDefinition>,
    padding: PaddingValues,
    selection: Set<Long>,
    busy: Boolean,
    message: String?,
    isError: Boolean,
    exportReady: Boolean,
    showSubmissions: Boolean,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onTab: (String?) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onOpen: (EditorialEventRow) -> Unit,
    onSelect: (EditorialEventRow) -> Unit,
    onAction: (EditorialEventRow, String) -> Unit,
    onExport: () -> Unit,
    onShareExport: () -> Unit,
    onOpenExport: () -> Unit,
    onDeleteSelected: () -> Unit,
    onClearSelection: () -> Unit,
    onDismissMessage: () -> Unit,
    onSubmissions: () -> Unit,
    onCreate: (() -> Unit)? = null,
) {
    val tabs = listOf(
        ListTab(null, stringResource(R.string.editorial_events_tab_all)),
        ListTab("pending", stringResource(R.string.editorial_events_tab_pending)),
        ListTab("published", stringResource(R.string.editorial_events_tab_published)),
        ListTab("draft", stringResource(R.string.editorial_events_tab_draft)),
        ListTab("cancelled", stringResource(R.string.editorial_events_tab_cancelled)),
    )
    PagedListScreen(
        title = stringResource(R.string.editorial_events_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        tabs = tabs,
        onTab = onTab,
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_events_search),
        emptyText = stringResource(R.string.editorial_events_empty),
        header = {
            onCreate?.let { create ->
                Button(onClick = create, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_new_event)) }
            }
            if (showSubmissions) {
                OutlinedButton(onClick = onSubmissions, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.Outlined.Inbox, contentDescription = null)
                    Text(stringResource(R.string.editorial_events_submissions), Modifier.padding(start = 8.dp).weight(1f))
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                OutlinedButton(onClick = onExport, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Icon(Icons.Outlined.Download, contentDescription = null)
                    Text(stringResource(R.string.editorial_events_export), Modifier.padding(start = 6.dp))
                }
                if (exportReady) {
                    TextButton(onClick = onOpenExport, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_export_open)) }
                    TextButton(onClick = onShareExport, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_export_share)) }
                }
            }
            if (selection.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.editorial_events_selected, selection.size), Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    TextButton(onClick = onClearSelection, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_clear_selection)) }
                    Button(onClick = onDeleteSelected, enabled = !busy, colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error), modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_events_delete_selected))
                    }
                }
            }
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            message?.let {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(it, color = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
                    TextButton(onClick = onDismissMessage, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_close)) }
                }
            }
        },
    ) { row ->
        EventRowCard(
            row = row,
            selected = row.id in selection,
            onOpen = { onOpen(row) },
            onSelect = { onSelect(row) },
            onAction = { key -> onAction(row, key) },
        )
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun EventRowCard(row: EditorialEventRow, selected: Boolean, onOpen: () -> Unit, onSelect: () -> Unit, onAction: (String) -> Unit) {
    val zone = row.city?.zone ?: ZoneId.of("Europe/Rome")
    val openLabel = stringResource(R.string.editorial_events_open, row.title)
    val actions = eventRowActions { key -> key }.map { it.copy(label = eventRowLabel(it.key, row)) }
    Surface(
        color = if (selected) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surface,
        shape = RoundedCornerShape(12.dp),
        modifier = Modifier.fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .combinedClickable(onClick = onOpen, onLongClick = onSelect, onClickLabel = openLabel)
            .semantics { this.selected = selected },
    ) {
        Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
            Box {
                PosterImage(row.poster?.thumb ?: row.poster?.card, Modifier.width(56.dp).height(75.dp).clip(RoundedCornerShape(8.dp)))
                if (selected) Icon(Icons.Outlined.CheckCircle, contentDescription = stringResource(R.string.editorial_events_select, row.title), tint = MaterialTheme.colorScheme.primary)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(row.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                if (row.source.imported) Text(row.source.label, color = Muted, style = MaterialTheme.typography.bodySmall)
                Text(whenAndWhere(row, zone), color = Muted, style = MaterialTheme.typography.bodyMedium)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    StatusPill(row.status.label)
                    if (row.past) StatusPill(stringResource(R.string.editorial_events_past))
                    if (row.isFeatured) StatusPill(stringResource(R.string.editorial_events_featured))
                    if (row.trashed) StatusPill(stringResource(R.string.editorial_events_trashed))
                }
                Text(
                    stringResource(R.string.editorial_events_dates_count, row.occurrencesCount) + " · " +
                        (editorialMoment(row.publishedAt, zone)?.let { stringResource(R.string.editorial_events_published_on, it) } ?: stringResource(R.string.editorial_events_never_published)),
                    color = Muted, style = MaterialTheme.typography.bodySmall,
                )
            }
            ActionMenu(actions = actions, abilities = row.can, onAction = { onAction(it.key) }, subject = row.title)
        }
    }
}

@Composable
private fun eventRowLabel(key: String, row: EditorialEventRow): String = eventActionLabel(key, row.isFeatured)

@Composable
private fun StatusPill(text: String) {
    Surface(shape = RoundedCornerShape(50), color = MaterialTheme.colorScheme.surfaceVariant) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 4.dp), style = MaterialTheme.typography.labelMedium)
    }
}

/** «sab 22 nov 2026, 21:00 · Circolo Aurora», oppure «Ultima data: …» per chi ha solo date passate. */
@Composable
private fun whenAndWhere(row: EditorialEventRow, zone: ZoneId): String {
    val place = row.venue?.name ?: row.place ?: stringResource(R.string.editorial_events_no_place)
    val next = editorialMoment(row.nextStartsAt, zone)
    val last = editorialMoment(row.lastStartsAt, zone)
    val time = when {
        next != null -> next
        last != null -> stringResource(R.string.editorial_events_was, last)
        else -> stringResource(R.string.editorial_events_no_dates)
    }
    return stringResource(R.string.editorial_events_next, time, place)
}
