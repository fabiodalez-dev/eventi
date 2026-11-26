package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter

/** Una scheda in cima all'elenco: `key` è il valore di `tab` (`null` = tutte, il cui conteggio arriva come `all`). */
internal data class ListTab(val key: String?, val label: String)

/**
 * Lo scheletro di ogni elenco della redazione: indietro, titolo, ricerca (con
 * una pausa di 350 ms prima di chiedere), schede con i conteggi del server,
 * «Filtri» che apre `FilterSheet`, righe che si caricano scorrendo, e i
 * quattro stati (carica, vuoto, errore con «Riprova», fine).
 *
 * È un `LazyColumn`: a differenza di `ManagementScaffold` non scorre dentro
 * una colonna, perché gli elenchi della redazione sono lunghi.
 */
@Composable
internal fun <T> PagedListScreen(
    title: String,
    padding: PaddingValues,
    state: PagedState<T>,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    rowKey: (T) -> Any,
    modifier: Modifier = Modifier,
    tabs: List<ListTab> = emptyList(),
    onTab: (String?) -> Unit = {},
    filters: List<FilterDefinition> = emptyList(),
    onFilters: (Map<String, String>) -> Unit = {},
    searchLabel: String = stringResource(R.string.editorial_list_search),
    /* Falso per gli elenchi che nel pannello non hanno una ricerca (le segnalazioni, la cronologia di una scheda). */
    searchable: Boolean = true,
    emptyText: String = stringResource(R.string.editorial_list_empty),
    header: @Composable ColumnScope.() -> Unit = {},
    row: @Composable (T) -> Unit,
) {
    var text by rememberSaveable { mutableStateOf(state.query.q) }
    var sheetOpen by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(text) {
        if (text == state.query.q) return@LaunchedEffect
        delay(SEARCH_PAUSE_MILLIS)
        onSearch(text.trim())
    }
    val listState = rememberLazyListState()
    val latestLoadMore by rememberUpdatedState(onLoadMore)
    LaunchedEffect(listState) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .distinctUntilChanged()
            .filter { (last, total) -> last != null && total > 0 && last >= total - LOAD_AHEAD }
            .collect { latestLoadMore() }
    }

    Column(modifier.fillMaxSize().padding(padding).padding(horizontal = 18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Spacer(Modifier.height(4.dp))
        IconButton(onClick = onBack, modifier = Modifier.size(48.dp)) {
            Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = stringResource(R.string.management_back))
        }
        Text(title, style = MaterialTheme.typography.headlineLarge)
        header()
        if (searchable) OutlinedTextField(
            value = text,
            onValueChange = { text = it },
            label = { Text(searchLabel) },
            singleLine = true,
            leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
            trailingIcon = {
                if (text.isNotEmpty()) IconButton(onClick = { text = "" }, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.editorial_list_clear_search))
                }
            },
            modifier = Modifier.fillMaxWidth(),
        )
        if (tabs.isNotEmpty() || filters.isNotEmpty()) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                LazyRow(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(tabs, key = { it.key ?: ALL_TAB }) { tab ->
                        val count = state.tabCounts[tab.key ?: ALL_TAB]
                        FilterChip(
                            selected = state.query.tab == tab.key,
                            onClick = { onTab(tab.key) },
                            label = { Text(if (count != null) stringResource(R.string.editorial_list_tab_count, tab.label, count) else tab.label) },
                            modifier = Modifier.heightIn(min = 48.dp),
                        )
                    }
                }
                if (filters.isNotEmpty()) {
                    val active = state.query.activeFilters
                    OutlinedButton(onClick = { sheetOpen = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Icon(Icons.Outlined.FilterList, contentDescription = null)
                        Text(if (active > 0) stringResource(R.string.editorial_list_filters_count, active) else stringResource(R.string.editorial_list_filters), Modifier.padding(start = 6.dp))
                    }
                }
            }
        }
        if (state.page > 0 && state.total > 0) Text(stringResource(R.string.editorial_list_total, state.total), color = Muted)
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, verticalArrangement = Arrangement.spacedBy(4.dp)) {
            items(state.items, key = rowKey) { item -> row(item) }
            item(key = "editorial-list-footer") {
                when {
                    state.loading -> {
                        val label = stringResource(R.string.editorial_list_loading_more)
                        LinearProgressIndicator(Modifier.fillMaxWidth().padding(vertical = 12.dp).semantics { contentDescription = label })
                    }
                    state.error != null -> Column(Modifier.padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(state.error, color = MaterialTheme.colorScheme.error)
                        OutlinedButton(onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
                    }
                    state.isEmpty -> Text(emptyText, color = Muted, modifier = Modifier.padding(vertical = 12.dp))
                    else -> Spacer(Modifier.height(24.dp))
                }
            }
        }
    }
    if (sheetOpen) FilterSheet(filters, state.query.filters, onApply = { sheetOpen = false; onFilters(it) }, onDismiss = { sheetOpen = false })
}

private const val SEARCH_PAUSE_MILLIS = 350L
/* Quante righe prima della fine si chiede la pagina successiva. */
private const val LOAD_AHEAD = 3
/* La chiave del conteggio della scheda «Tutti» in `meta.tabs`. */
internal const val ALL_TAB = "all"
