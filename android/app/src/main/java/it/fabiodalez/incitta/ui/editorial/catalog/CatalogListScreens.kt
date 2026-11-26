package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.data.editorial.CatalogSectionSummary
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve

/*
 * R11 — Catalogo: l'ingresso con le cinque voci e l'elenco di ognuna, come
 * le tabelle del gruppo «Contenuti» del pannello (colonne, filtri sì/no,
 * ordinamento, azioni di riga). Il tocco su una riga apre il modulo solo a
 * chi può modificare: il moderatore legge l'elenco, come nel pannello.
 */

/** Il titolo di ogni voce, come nel menu del pannello. */
internal fun catalogTitle(kind: CatalogKind): Int = when (kind) {
    CatalogKind.Categories -> R.string.editorial_catalog_categories
    CatalogKind.Tags -> R.string.editorial_catalog_tags
    CatalogKind.Features -> R.string.editorial_catalog_features
    CatalogKind.Cities -> R.string.editorial_catalog_cities
    CatalogKind.Pages -> R.string.editorial_catalog_pages
}

private fun catalogHelp(kind: CatalogKind): Int = when (kind) {
    CatalogKind.Categories -> R.string.editorial_catalog_categories_help
    CatalogKind.Tags -> R.string.editorial_catalog_tags_help
    CatalogKind.Features -> R.string.editorial_catalog_features_help
    CatalogKind.Cities -> R.string.editorial_catalog_cities_help
    CatalogKind.Pages -> R.string.editorial_catalog_pages_help
}

/** «Nuova categoria», «Nuovo tag»… */
internal fun catalogNewLabel(kind: CatalogKind): Int = when (kind) {
    CatalogKind.Categories -> R.string.editorial_catalog_new_category
    CatalogKind.Tags -> R.string.editorial_catalog_new_tag
    CatalogKind.Features -> R.string.editorial_catalog_new_feature
    CatalogKind.Cities -> R.string.editorial_catalog_new_city
    CatalogKind.Pages -> R.string.editorial_catalog_new_page
}

/** Le colonne ordinabili di ogni voce (`->sortable()` del pannello); la prima è l'ordine predefinito. */
internal fun catalogSorts(kind: CatalogKind): List<Pair<String?, Int>> = when (kind) {
    CatalogKind.Categories -> listOf(null to R.string.editorial_catalog_sort_order, "name" to R.string.editorial_catalog_sort_name)
    CatalogKind.Tags -> listOf(null to R.string.editorial_catalog_sort_name, "-events_count" to R.string.editorial_catalog_sort_events, "-usage_count" to R.string.editorial_catalog_sort_usage)
    CatalogKind.Features -> listOf(null to R.string.editorial_catalog_sort_group, "name" to R.string.editorial_catalog_sort_name)
    CatalogKind.Cities -> emptyList()
    CatalogKind.Pages -> listOf(null to R.string.editorial_catalog_sort_order, "title" to R.string.editorial_catalog_sort_title, "-updated_at" to R.string.editorial_catalog_sort_updated)
}

@Composable
internal fun CatalogHomeScreen(
    viewModel: CatalogHomeViewModel,
    padding: PaddingValues,
    onBack: () -> Unit,
    onOpen: (CatalogSectionSummary) -> Unit,
) {
    val sections by viewModel.sections.collectAsState()
    ManagementScaffold(
        title = stringResource(R.string.editorial_area_catalog),
        padding = padding,
        onBack = onBack,
        subtitle = stringResource(R.string.editorial_catalog_home_help),
    ) {
        LoadState(sections, onRetry = viewModel::load, emptyText = stringResource(R.string.editorial_catalog_home_empty)) { list ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                list.forEach { section -> CatalogSectionRow(section) { onOpen(section) } }
            }
        }
    }
}

@Composable
private fun CatalogSectionRow(section: CatalogSectionSummary, onClick: () -> Unit) {
    val kind = section.kind ?: return
    val readOnly = if (section.canCreate) "" else " · " + stringResource(R.string.editorial_catalog_read_only)
    ManagementRow(
        title = stringResource(R.string.editorial_catalog_section_count, stringResource(catalogTitle(kind)), section.count),
        description = stringResource(catalogHelp(kind)) + readOnly,
        onClick = onClick,
    )
}

@Composable
internal fun CatalogListScreen(
    viewModel: CatalogListViewModel,
    padding: PaddingValues,
    canCreate: Boolean,
    onBack: () -> Unit,
    onOpen: (CatalogRecord) -> Unit,
    onCreate: () -> Unit,
) {
    val kind = viewModel.kind
    val state by viewModel.rows.collectAsState()
    val actions by viewModel.actions.collectAsState()
    val yes = stringResource(R.string.editorial_catalog_yes)
    val no = stringResource(R.string.editorial_catalog_no)
    val filters = catalogFilters(kind).map { (key, label) ->
        FilterDefinition(key, stringResource(label), listOf(OptionChoice("1", yes), OptionChoice("0", no)))
    }
    val mergeLabel = stringResource(R.string.editorial_catalog_action_merge)
    val deleteLabel = stringResource(R.string.editorial_catalog_action_delete)
    val rowActions = buildList {
        if (kind == CatalogKind.Tags) add(RowAction("merge", mergeLabel))
        add(RowAction("destroy", deleteLabel, destructive = true))
    }
    val snackbar = remember { SnackbarHostState() }
    val message = actions.message?.resolve()
    LaunchedEffect(message) {
        if (message != null) { snackbar.showSnackbar(message); viewModel.clearMessage() }
    }

    Box(Modifier.fillMaxSize()) {
        PagedListScreen(
            title = stringResource(catalogTitle(kind)),
            padding = padding,
            state = state,
            onBack = onBack,
            onSearch = viewModel::search,
            onLoadMore = viewModel.list::loadMore,
            onRetry = viewModel.list::retry,
            rowKey = { it.id },
            filters = filters,
            onFilters = viewModel::applyFilters,
            header = {
                Text(stringResource(catalogHelp(kind)), color = Muted)
                if (canCreate) Button(onClick = onCreate, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(catalogNewLabel(kind))) }
                val sorts = catalogSorts(kind)
                if (sorts.size > 1) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.editorial_catalog_sort), color = Muted)
                    sorts.forEach { (key, label) ->
                        FilterChip(state.query.sort == key, { viewModel.sortBy(key) }, label = { Text(stringResource(label)) }, modifier = Modifier.heightIn(min = 48.dp))
                    }
                }
            },
        ) { record ->
            CatalogRow(kind, record, rowActions, onOpen = { onOpen(record) }, onAction = { action ->
                when (action.key) {
                    "merge" -> viewModel.requestMerge(record)
                    "destroy" -> viewModel.requestDelete(record)
                }
            })
        }
        SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter).padding(padding))
    }

    actions.pendingDelete?.let { record ->
        ConfirmDialog(
            title = stringResource(R.string.editorial_catalog_delete_title, record.label),
            text = actions.error ?: stringResource(if (kind == CatalogKind.Pages) R.string.editorial_catalog_delete_page_text else R.string.editorial_catalog_delete_text),
            confirmLabel = deleteLabel,
            onConfirm = viewModel::confirmDelete,
            onDismiss = viewModel::dismiss,
            destructive = true,
            enabled = !actions.busy,
        )
    }
    actions.merge?.let { merge -> MergeDialog(merge, actions.busy, actions.error, viewModel::searchMerge, viewModel::chooseMerge, viewModel::confirmMerge, viewModel::dismiss) }
}

/** Una riga dell'elenco: le colonne del pannello in due righe di testo, il menu «⋮» con le azioni permesse. */
@Composable
private fun CatalogRow(kind: CatalogKind, record: CatalogRecord, actions: List<RowAction>, onOpen: () -> Unit, onAction: (RowAction) -> Unit) {
    val editable = record.allows("update")
    val openLabel = stringResource(R.string.editorial_catalog_open, record.label)
    Row(
        Modifier.fillMaxWidth()
            .heightIn(min = 56.dp)
            .then(if (editable) Modifier.clickable(onClickLabel = openLabel, role = Role.Button, onClick = onOpen) else Modifier)
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(record.label, style = MaterialTheme.typography.titleMedium)
            catalogRowDetails(kind, record).takeIf { it.isNotBlank() }?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodyMedium) }
        }
        ActionMenu(actions, record.can, onAction, subject = record.label)
    }
    HorizontalDivider()
}

/** Le colonne della tabella del pannello per la seconda riga. */
@Composable
private fun catalogRowDetails(kind: CatalogKind, record: CatalogRecord): String {
    val parts = mutableListOf<String>()
    val events = pluralStringResource(R.plurals.editorial_catalog_events, record.eventsCount, record.eventsCount)
    when (kind) {
        CatalogKind.Categories -> {
            record.parent?.let { parts += stringResource(R.string.editorial_catalog_row_parent, it.name) }
            record.defaultDurationMinutes?.let { parts += stringResource(R.string.editorial_catalog_row_duration, it) }
            if (record.isNightlife) parts += stringResource(R.string.editorial_catalog_field_nightlife)
            if (!record.supportsOngoing) parts += stringResource(R.string.editorial_catalog_row_no_ongoing)
            parts += events
            if (!record.isActive) parts += stringResource(R.string.editorial_catalog_row_inactive)
        }
        CatalogKind.Tags -> {
            record.category?.let { parts += it.name }
            parts += events
            parts += stringResource(R.string.editorial_catalog_row_usage, record.usageCount)
            parts += stringResource(if (record.isApproved) R.string.editorial_catalog_row_approved else R.string.editorial_catalog_row_not_approved)
        }
        CatalogKind.Features -> {
            parts += record.group
            if (record.isSystem) parts += stringResource(R.string.editorial_catalog_row_system)
            if (!record.isActive) parts += stringResource(R.string.editorial_catalog_row_unavailable)
        }
        CatalogKind.Cities -> {
            parts += record.provinceName
            parts += stringResource(R.string.editorial_catalog_row_cutoff, record.nightCutoffTime)
            parts += stringResource(R.string.editorial_catalog_row_soon, record.startingSoonMinutes)
            parts += pluralStringResource(R.plurals.editorial_catalog_venues, record.venuesCount, record.venuesCount)
            parts += events
            if (!record.isActive) parts += stringResource(R.string.editorial_catalog_row_inactive)
        }
        CatalogKind.Pages -> {
            parts += "/" + record.slug
            parts += stringResource(if (record.isPublished) R.string.editorial_catalog_row_published else R.string.editorial_catalog_row_unpublished)
            parts += stringResource(R.string.editorial_catalog_row_order, record.sortOrder)
        }
    }
    return parts.filter { it.isNotBlank() }.joinToString(" · ")
}

/**
 * «Unisci» di un tag, come l'azione del pannello: si cerca il doppione, lo
 * si sceglie e si conferma. Il tag della riga resta; il doppione sparisce e
 * il suo nome diventa un sinonimo.
 */
@Composable
private fun MergeDialog(
    merge: MergeState,
    busy: Boolean,
    error: String?,
    onSearch: (String) -> Unit,
    onChoose: (CatalogRecord) -> Unit,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.editorial_catalog_merge_title, merge.keep.label)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_catalog_merge_help), color = Muted)
                OutlinedTextField(
                    value = merge.query,
                    onValueChange = onSearch,
                    label = { Text(stringResource(R.string.editorial_catalog_merge_search)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                LoadState(merge.results, onRetry = { onSearch(merge.query) }, emptyText = stringResource(R.string.editorial_catalog_merge_none)) { results ->
                    LazyColumn(Modifier.heightIn(max = 260.dp)) {
                        items(results, key = { it.id }) { tag ->
                            Row(
                                Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.RadioButton) { onChoose(tag) },
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                RadioButton(selected = merge.chosen?.id == tag.id, onClick = { onChoose(tag) })
                                Column {
                                    Text(tag.label)
                                    Text(pluralStringResource(R.plurals.editorial_catalog_events, tag.eventsCount, tag.eventsCount), color = Muted, style = MaterialTheme.typography.bodySmall)
                                }
                            }
                        }
                    }
                }
                merge.chosen?.let { Text(stringResource(R.string.editorial_catalog_merge_summary, it.label, merge.keep.label)) }
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(enabled = merge.chosen != null && !busy, onClick = onConfirm) {
                Text(stringResource(R.string.editorial_catalog_merge_confirm), color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_cancel)) } },
    )
}
