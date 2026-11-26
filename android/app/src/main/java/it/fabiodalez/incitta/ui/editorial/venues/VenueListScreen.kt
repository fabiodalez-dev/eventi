package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Verified
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenueRow
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * L'elenco dei locali (R-VN-01…03, 07…10): le schede del pannello con i
 * conteggi, i sei filtri, la ricerca su nome e comune, l'ordinamento, la
 * moderazione dal menu della riga e l'esportazione in Excel (decisione 8).
 */

internal data class VenueListUiState(
    val pending: PendingVenueAction? = null,
    val busy: Boolean = false,
    val actionError: String? = null,
    val message: String? = null,
    val exporting: Boolean = false,
    val exported: DownloadedFile? = null,
    val cityChoices: List<OptionChoice> = emptyList(),
    val typeChoices: List<OptionChoice> = emptyList(),
    val statusChoices: List<OptionChoice> = emptyList(),
)

internal class VenueListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery(tab = saved[KEY_TAB], sort = saved[KEY_SORT])) { query, page ->
        repository.call { source.venues(query.params(page)) }
    }
    private val state = MutableStateFlow(VenueListUiState())
    val uiState: StateFlow<VenueListUiState> = state.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch {
            val options = repository.options()
            val cities = repository.current.value?.cities.orEmpty().map { OptionChoice(it.id.toString(), it.name) }
            state.update {
                it.copy(
                    cityChoices = cities,
                    typeChoices = options?.choices("venue_types").orEmpty(),
                    statusChoices = options?.choices("venue_statuses").orEmpty(),
                )
            }
        }
    }

    fun selectTab(tab: String?) { saved[KEY_TAB] = tab; list.selectTab(tab) }
    fun sortBy(sort: String?) { saved[KEY_SORT] = sort; list.sortBy(sort) }

    fun ask(row: EditorialVenueRow, action: VenueAction) =
        state.update { it.copy(pending = PendingVenueAction(row.id, row.name, action, row.isVerified), actionError = null, message = null) }

    fun dismiss() = state.update { it.copy(pending = null, actionError = null) }

    fun confirm(reason: String) {
        val pending = state.value.pending ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, actionError = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { runVenueAction(source, pending, reason) }
                state.update { it.copy(pending = null, message = result.message) }
                list.refresh()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(actionError = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** Il foglio Excel dell'elenco così com'è filtrato, nella cache dell'app. */
    fun export(cacheDir: File) {
        if (state.value.exporting) return
        val query = list.current.value.query
        state.update { it.copy(exporting = true, message = null, exported = null) }
        viewModelScope.launch {
            try {
                ManagementFiles.purge(cacheDir)
                val target = File(ManagementFiles.directory(cacheDir), "locali-${System.currentTimeMillis()}.xlsx")
                val file = repository.call { source.exportVenues(query.params(1).filterNot { it.first == "page" || it.first == "per_page" }, target) }
                state.update { it.copy(exported = file) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(message = requestFailureMessage(e)) } }
            finally { state.update { it.copy(exporting = false) } }
        }
    }

    fun exportHandled() = state.update { it.copy(exported = null) }

    companion object {
        const val KEY_TAB = "tab"
        const val KEY_SORT = "sort"
    }
}

/** Esegue un'azione di moderazione sul server. */
internal suspend fun runVenueAction(source: EditorialVenuesSource, pending: PendingVenueAction, reason: String) = when (pending.action) {
    VenueAction.Approve -> source.approveVenue(pending.venueId)
    VenueAction.Verify -> source.verifyVenue(pending.venueId, !pending.verified)
    VenueAction.Suspend -> source.suspendVenue(pending.venueId, reason)
    VenueAction.Reject -> source.rejectVenue(pending.venueId, reason)
}

@Composable
internal fun VenueListScreen(model: VenueListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    val android = LocalContext.current
    val shareTitle = stringResource(R.string.editorial_venues_export_share)
    LaunchedEffect(state.exported) {
        state.exported?.let { file -> SharedFiles.share(android, file.file, file.mime, shareTitle); model.exportHandled() }
    }
    VenueListContent(
        state = list,
        ui = state,
        padding = context.padding,
        onBack = context::back,
        onSearch = model.list::search,
        onTab = model::selectTab,
        onFilters = model.list::applyFilters,
        onSort = model::sortBy,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        onOpen = { context.navController.navigate(VenueDetailRoute(it.id)) },
        onAction = model::ask,
        onExport = { model.export(android.cacheDir) },
    )
    state.pending?.let { pending -> VenueActionDialog(pending, state.busy, state.actionError, model::confirm, model::dismiss) }
}

/** Il contenuto dell'elenco, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun VenueListContent(
    state: PagedState<EditorialVenueRow>,
    ui: VenueListUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onTab: (String?) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (EditorialVenueRow) -> Unit,
    onAction: (EditorialVenueRow, VenueAction) -> Unit,
    onExport: () -> Unit,
) {
    val yes = listOf(OptionChoice("1", stringResource(R.string.editorial_venues_yes)), OptionChoice("0", stringResource(R.string.editorial_venues_no)))
    val filters = listOfNotNull(
        ui.cityChoices.takeIf { it.size > 1 }?.let { FilterDefinition("city", stringResource(R.string.editorial_venue_city), it) },
        FilterDefinition("type", stringResource(R.string.editorial_venue_type), ui.typeChoices),
        FilterDefinition("status", stringResource(R.string.editorial_venue_status), ui.statusChoices),
        FilterDefinition("is_verified", stringResource(R.string.editorial_venue_verified), yes),
        FilterDefinition("auto_publish", stringResource(R.string.editorial_venue_auto_publish), yes),
        FilterDefinition("inactive", stringResource(R.string.editorial_venue_inactive), listOf(OptionChoice("1", stringResource(R.string.editorial_venues_yes)))),
    ).filter { it.options.isNotEmpty() }
    PagedListScreen(
        title = stringResource(R.string.editorial_venues_list_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        tabs = listOf(
            ListTab(null, stringResource(R.string.editorial_venues_tab_all)),
            ListTab("pending", stringResource(R.string.editorial_venues_tab_pending)),
            ListTab("approved", stringResource(R.string.editorial_venues_tab_approved)),
            ListTab("suspended", stringResource(R.string.editorial_venues_tab_suspended)),
        ),
        onTab = onTab,
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_venues_search),
        emptyText = stringResource(R.string.editorial_venues_empty),
        header = {
            ui.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
            SortRow(state.query.sort, onSort)
            OutlinedButton(onClick = onExport, enabled = !ui.exporting, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(if (ui.exporting) R.string.editorial_venues_exporting else R.string.editorial_venues_export))
            }
        },
    ) { row -> VenueRowItem(row, onOpen = { onOpen(row) }, onAction = { action -> onAction(row, action) }) }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SortRow(sort: String?, onSort: (String?) -> Unit) {
    val options = listOf(
        null to R.string.editorial_venues_sort_name,
        "-name" to R.string.editorial_venues_sort_name_desc,
        "municipality" to R.string.editorial_venues_sort_municipality,
        "-events_count" to R.string.editorial_venues_sort_events,
    )
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (value, label) ->
            FilterChip(selected = sort == value, onClick = { onSort(value) }, label = { Text(stringResource(label)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
}

@Composable
private fun VenueRowItem(row: EditorialVenueRow, onOpen: () -> Unit, onAction: (VenueAction) -> Unit) {
    val abilities = abilitiesOf(row.abilities)
    Row(
        Modifier.fillMaxWidth().clickable(role = Role.Button, onClick = onOpen).padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        AsyncImage(
            model = row.coverUrl,
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier.size(64.dp, 48.dp).background(MaterialTheme.colorScheme.surfaceVariant),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(row.name, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f, fill = false))
                if (row.isVerified) Icon(Icons.Outlined.Verified, contentDescription = stringResource(R.string.editorial_venue_verified), tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(18.dp))
            }
            if (row.subtitle.isNotBlank()) Text(row.subtitle, color = Muted, style = MaterialTheme.typography.bodySmall)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                StatusLabel(row.status)
                Text(pluralStringResource(R.plurals.editorial_venue_events_count, row.eventsCount, row.eventsCount), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
        ActionMenu(venueRowActions(row.isVerified), abilities, onAction = { action -> VenueAction.entries.firstOrNull { it.key == action.key }?.let(onAction) }, subject = row.name)
    }
    HorizontalDivider()
}
