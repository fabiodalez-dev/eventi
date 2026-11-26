package it.fabiodalez.incitta.ui.editorial.analytics

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.SearchMissRow
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.formatCount
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.resolve

/*
 * Le ricerche senza risultati (R-ST-06), la tabella del pannello in una
 * lista: la parola cercata, quante volte, l'ultima e la prima volta, la
 * città. Ricerca, filtro «Città», ordinamento; «Elimina» nel menu della riga
 * e, scegliendo più righe, «Elimina le scelte» come l'azione di gruppo.
 */

/** Un ordinamento proposto in cima all'elenco: `value` è il `sort` del server (`null` = quello del pannello). */
internal data class MissSort(val value: String?, val label: Int)

internal val MISS_SORTS = listOf(
    MissSort(null, R.string.editorial_misses_sort_count),
    MissSort(SearchMissesViewModel.SORT_COUNT_ASC, R.string.editorial_misses_sort_count_asc),
    MissSort(SearchMissesViewModel.SORT_LAST, R.string.editorial_misses_sort_last),
    MissSort(SearchMissesViewModel.SORT_FIRST, R.string.editorial_misses_sort_first),
)

@Composable
internal fun SearchMissesScreen(viewModel: SearchMissesViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    SearchMissesList(
        list = list,
        state = state,
        padding = padding,
        onBack = onBack,
        onSearch = viewModel::search,
        onFilters = viewModel::applyFilters,
        onSort = viewModel::sortBy,
        onLoadMore = viewModel.list::loadMore,
        onRetry = viewModel.list::retry,
        onToggle = viewModel::toggle,
        onRemove = viewModel::askRemove,
        onRemoveSelected = viewModel::askRemoveSelected,
        onClearSelection = viewModel::clearSelection,
        onConfirm = viewModel::confirm,
        onDismiss = viewModel::dismiss,
        onMessageShown = viewModel::consumeMessage,
    )
}

@Composable
internal fun SearchMissesList(
    list: PagedState<SearchMissRow>,
    state: SearchMissesUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onToggle: (SearchMissRow) -> Unit,
    onRemove: (SearchMissRow) -> Unit,
    onRemoveSelected: () -> Unit,
    onClearSelection: () -> Unit,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
    onMessageShown: () -> Unit = {},
) {
    val cityFilter = FilterDefinition(
        key = SearchMissesViewModel.FILTER_CITY,
        label = stringResource(R.string.editorial_misses_city),
        options = state.cities.map { OptionChoice(it.value, it.label) },
    )
    val removeAction = RowAction("remove", stringResource(R.string.editorial_misses_remove), destructive = true)
    PagedListScreen(
        title = stringResource(R.string.editorial_analytics_misses_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = if (state.cities.isNotEmpty()) listOf(cityFilter) else emptyList(),
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_misses_search),
        emptyText = stringResource(R.string.editorial_misses_empty),
        header = {
            Text(stringResource(R.string.editorial_misses_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
            val sortLabel = stringResource(R.string.editorial_misses_sort)
            LazyRow(Modifier.semantics { contentDescription = sortLabel }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(MISS_SORTS, key = { it.value ?: "default" }) { sort ->
                    FilterChip(selected = list.query.sort == sort.value, onClick = { onSort(sort.value) }, label = { Text(stringResource(sort.label)) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
            if (state.selected.isNotEmpty()) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stringResource(R.string.editorial_misses_selected, state.selected.size), modifier = Modifier.weight(1f))
                    TextButton(onClick = onClearSelection, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_misses_clear_selection)) }
                    Button(onClick = onRemoveSelected, colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error), modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_misses_remove_selected))
                    }
                }
            }
            state.message?.let { message ->
                Text(message.resolve(), color = MaterialTheme.colorScheme.primary)
                LaunchedEffect(message) { kotlinx.coroutines.delay(4000); onMessageShown() }
            }
        },
    ) { row ->
        val abilities = abilitiesOf(row.abilities)
        val selectable = abilities["remove"] == true
        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            if (selectable) {
                val label = stringResource(R.string.editorial_misses_select, row.term)
                Checkbox(checked = row.id in state.selected, onCheckedChange = { onToggle(row) }, modifier = Modifier.size(48.dp).semantics { contentDescription = label })
            }
            Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(row.term, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.editorial_misses_count, formatCount(row.count)))
                row.lastSearchedLabel?.let { Text(stringResource(R.string.editorial_misses_last, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
                row.firstSearchedLabel?.let { Text(stringResource(R.string.editorial_misses_first, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
                row.city?.let { Text(it.name, color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
            ActionMenu(listOf(removeAction), abilities, onAction = { onRemove(row) }, subject = row.term)
        }
        HorizontalDivider()
    }
    val single = state.confirming
    if (single != null || state.confirmingBulk) {
        ConfirmDialog(
            title = if (single != null) stringResource(R.string.editorial_misses_remove_title, single.term) else stringResource(R.string.editorial_misses_bulk_title, state.selected.size),
            text = listOfNotNull(
                stringResource(if (single != null) R.string.editorial_misses_remove_text else R.string.editorial_misses_bulk_text),
                state.error,
            ).joinToString("\n\n"),
            confirmLabel = stringResource(R.string.editorial_misses_remove),
            onConfirm = onConfirm,
            onDismiss = onDismiss,
            destructive = true,
            enabled = !state.busy,
        )
    }
}
