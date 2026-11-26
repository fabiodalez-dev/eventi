package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CampaignDetail
import it.fabiodalez.incitta.data.editorial.CampaignForm
import it.fabiodalez.incitta.data.editorial.CampaignRow
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.PromoEvent
import it.fabiodalez.incitta.data.editorial.euroOrNull
import it.fabiodalez.incitta.data.editorial.numberOrNull
import it.fabiodalez.incitta.data.editorial.options
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipBadge
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipEventLine
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * Le campagne (R-SP-01…06), come `SponsorshipResource`: l'elenco con i
 * filtri del pannello (collocazione, stato, avanzamento, «solo quelle in
 * corso»), «Esporta», la selezione per eliminare in gruppo, la scheda con
 * l'avanzamento e il modulo in quattro parti (campagna, periodo e limiti,
 * visualizzazioni, cliente e ricavo), «Sospendi»/«Attiva», «Elimina».
 */

/** Un'azione su una campagna che chiede conferma. */
internal sealed interface CampaignConfirm {
    data class Toggle(val id: Long, val label: String) : CampaignConfirm
    data class Delete(val id: Long, val title: String) : CampaignConfirm
    data class DeleteSelected(val ids: List<Long>) : CampaignConfirm
}

internal data class CampaignListUiState(
    val choices: PromoChoices = PromoChoices(),
    val selecting: Boolean = false,
    val selected: Set<Long> = emptySet(),
    val confirm: CampaignConfirm? = null,
    val busy: Boolean = false,
    val exporting: Boolean = false,
    val exported: DownloadedFile? = null,
    val message: String? = null,
    val error: String? = null,
)

internal class CampaignListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(CampaignListUiState())
    val uiState: StateFlow<CampaignListUiState> = state.asStateFlow()
    val list = PagedListController(viewModelScope, ListQuery()) { query, page ->
        val result = repository.call { source.campaigns(query.params(page)) }
        state.update { it.copy(choices = result.choices) }
        result.page()
    }

    init { list.refresh() }

    fun toggleSelecting() = state.update { it.copy(selecting = !it.selecting, selected = emptySet()) }
    fun select(id: Long) = state.update { it.copy(selected = if (id in it.selected) it.selected - id else it.selected + id) }
    fun ask(confirm: CampaignConfirm) = state.update { it.copy(confirm = confirm, error = null) }
    fun dismiss() = state.update { it.copy(confirm = null) }
    fun consumeMessage() = state.update { it.copy(message = null) }

    fun confirm() {
        val confirm = state.value.confirm ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                when (confirm) {
                    is CampaignConfirm.Toggle -> {
                        val result = repository.call { source.toggleCampaign(confirm.id) }
                        list.replace({ it.id == confirm.id }, result.data)
                        state.update { it.copy(message = result.message) }
                    }
                    is CampaignConfirm.Delete -> {
                        repository.call { source.deleteCampaign(confirm.id) }
                        list.remove { it.id == confirm.id }
                    }
                    is CampaignConfirm.DeleteSelected -> {
                        val deleted = repository.call { source.deleteCampaigns(confirm.ids) }.deleted.toSet()
                        list.remove { it.id in deleted }
                        state.update { it.copy(selecting = false, selected = emptySet()) }
                    }
                }
                state.update { it.copy(confirm = null) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** Il foglio Excel dell'elenco così com'è filtrato, nella cache dell'app. */
    fun export(cacheDir: File) {
        if (state.value.exporting) return
        val query = list.current.value.query
        state.update { it.copy(exporting = true, error = null, exported = null) }
        viewModelScope.launch {
            try {
                ManagementFiles.purge(cacheDir)
                val target = File(ManagementFiles.directory(cacheDir), "sponsorizzazioni-${System.currentTimeMillis()}.xlsx")
                val file = repository.call { source.exportCampaigns(query.params(1).filterNot { it.first == "page" || it.first == "per_page" }, target) }
                state.update { it.copy(exported = file) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(exporting = false) } }
        }
    }

    fun exportHandled() = state.update { it.copy(exported = null) }
}

@Composable
internal fun CampaignListScreen(model: CampaignListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    val android = LocalContext.current
    val shareTitle = stringResource(R.string.editorial_promo_export_share)
    var visited by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (visited) model.list.refresh() else visited = true }
    LaunchedEffect(state.exported) {
        state.exported?.let { file -> SharedFiles.share(android, file.file, file.mime, shareTitle); model.exportHandled() }
    }
    CampaignListContent(
        list = list,
        ui = state,
        padding = context.padding,
        onBack = context::back,
        onSearch = model.list::search,
        onFilters = model.list::applyFilters,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        onOpen = { context.navController.navigate(CampaignDetailRoute(it.id)) },
        onNew = { context.navController.navigate(CampaignWizardRoute()) },
        onExport = { model.export(android.cacheDir) },
        onToggleSelecting = model::toggleSelecting,
        onSelect = model::select,
        onAsk = model::ask,
    )
    state.confirm?.let { confirm -> CampaignConfirmDialog(confirm, state.busy, state.error, model::confirm, model::dismiss) }
}

/** Il contenuto dell'elenco, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun CampaignListContent(
    list: PagedState<CampaignRow>,
    ui: CampaignListUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (CampaignRow) -> Unit,
    onNew: () -> Unit,
    onExport: () -> Unit,
    onToggleSelecting: () -> Unit,
    onSelect: (Long) -> Unit,
    onAsk: (CampaignConfirm) -> Unit,
) {
    val yes = listOf(it.fabiodalez.incitta.data.management.OptionChoice("1", stringResource(R.string.editorial_promo_yes)))
    val filters = listOf(
        FilterDefinition("placement", stringResource(R.string.editorial_promo_field_placement), ui.choices.placements.options()),
        FilterDefinition("status", stringResource(R.string.editorial_promo_field_status), ui.choices.statuses.options()),
        FilterDefinition("pacing_state", stringResource(R.string.editorial_promo_field_progress), ui.choices.pacingStates.options()),
        FilterDefinition("running", stringResource(R.string.editorial_promo_filter_running), yes),
    )
    val actions = listOf(
        RowAction("update", stringResource(R.string.editorial_promo_open)),
        RowAction("toggle", stringResource(R.string.editorial_promo_toggle)),
        RowAction("destroy", stringResource(R.string.editorial_promo_delete), destructive = true),
    )
    PagedListScreen(
        title = stringResource(R.string.editorial_promo_campaigns_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = CampaignRow::id,
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_promo_campaigns_search),
        emptyText = stringResource(R.string.editorial_promo_campaigns_empty),
        header = {
            ui.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
            ui.error?.takeIf { ui.confirm == null }?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = onNew, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_wizard_title)) }
                OutlinedButton(enabled = !ui.exporting, onClick = onExport, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text(stringResource(if (ui.exporting) R.string.editorial_promo_exporting else R.string.editorial_promo_export))
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = onToggleSelecting, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (ui.selecting) R.string.editorial_promo_select_stop else R.string.editorial_promo_select))
                }
                if (ui.selecting && ui.selected.isNotEmpty()) {
                    TextButton(onClick = { onAsk(CampaignConfirm.DeleteSelected(ui.selected.toList())) }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_promo_delete_selected, ui.selected.size), color = MaterialTheme.colorScheme.error)
                    }
                }
            }
        },
    ) { row ->
        val zone = promoZone(row.timezone)
        Row(
            Modifier.fillMaxWidth().clickable(role = Role.Button) { if (ui.selecting) onSelect(row.id) else onOpen(row) }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.Top,
        ) {
            if (ui.selecting) Checkbox(checked = row.id in ui.selected, onCheckedChange = { onSelect(row.id) })
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                val event = row.event
                if (event != null) SponsorshipEventLine(event.asSponsorshipEvent(), promoZone(event.timezone ?: row.timezone)) else Text(row.title, style = MaterialTheme.typography.titleMedium)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    SponsorshipBadge(row.phase.label, row.phase.color)
                    Text("${row.placement.label} · ${row.status.label}", color = Muted, style = MaterialTheme.typography.bodySmall)
                }
                Text(promoPeriod(row.startsAt, row.endsAt, zone), color = Muted, style = MaterialTheme.typography.bodySmall)
                Text(stringResource(R.string.editorial_promo_row_numbers, row.advertiserName, row.impressions, row.clicks, row.clickRate), style = MaterialTheme.typography.bodySmall)
                row.progress?.let { PromoProgressLine(it) }
            }
            if (!ui.selecting) ActionMenu(actions.map { if (it.key == "toggle") it.copy(label = row.toggleLabel.ifBlank { it.label }) else it }, abilitiesOf(row.abilities), { action ->
                when (action.key) {
                    "update" -> onOpen(row)
                    "toggle" -> onAsk(CampaignConfirm.Toggle(row.id, row.toggleLabel))
                    "destroy" -> onAsk(CampaignConfirm.Delete(row.id, row.title))
                }
            }, subject = row.title)
        }
        HorizontalDivider()
    }
}

@Composable
internal fun CampaignConfirmDialog(confirm: CampaignConfirm, busy: Boolean, error: String?, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    val (title, text, label, destructive) = when (confirm) {
        is CampaignConfirm.Toggle -> Quad(confirm.label, stringResource(R.string.editorial_promo_toggle_confirm), confirm.label, false)
        is CampaignConfirm.Delete -> Quad(stringResource(R.string.editorial_promo_delete_title), stringResource(R.string.editorial_promo_delete_confirm, confirm.title), stringResource(R.string.editorial_promo_delete), true)
        is CampaignConfirm.DeleteSelected -> Quad(stringResource(R.string.editorial_promo_delete_title), stringResource(R.string.editorial_promo_delete_selected_confirm, confirm.ids.size), stringResource(R.string.editorial_promo_delete), true)
    }
    ConfirmDialog(title = title, text = listOfNotNull(text, error).joinToString("\n\n"), confirmLabel = label, onConfirm = onConfirm, onDismiss = onDismiss, destructive = destructive, enabled = !busy)
}

private data class Quad(val title: String, val text: String, val label: String, val destructive: Boolean)

// ------------------------------------------------------------- la scheda

/** Il modulo di modifica: testo come lo scrive la persona, il server lo converte e lo controlla. */
@Serializable
internal data class CampaignDraft(
    val eventId: Long? = null,
    val eventTitle: String = "",
    val placement: String = "",
    val status: String = "",
    val startsAt: String? = null,
    val endsAt: String? = null,
    val priority: String = "0",
    val weight: String = "1",
    val impressionsCap: String = "",
    val clicksCap: String = "",
    val impressionsGoal: String = "",
    val pacing: String = "even",
    val advertiserName: String = "",
    val advertiserEmail: String = "",
    val advertiserUrl: String = "",
    val amount: String = "",
    val currency: String = "EUR",
    val invoiceReference: String = "",
    val notes: String = "",
) {
    /** Il corpo della modifica. Su una riga di un'abilitazione evento e consegna non si mandano (spenti nel pannello). */
    fun body(onGrant: Boolean): JsonObject = buildJsonObject {
        if (!onGrant) {
            eventId?.let { put("event_id", it) }
            put("priority", numberOrNull(priority))
            put("weight", numberOrNull(weight))
            put("impressions_goal", numberOrNull(impressionsGoal))
            put("pacing", pacing)
        }
        put("placement", placement)
        put("status", status)
        startsAt?.let { put("starts_at", it) }
        endsAt?.let { put("ends_at", it) }
        put("impressions_cap", numberOrNull(impressionsCap))
        put("clicks_cap", numberOrNull(clicksCap))
        put("advertiser_name", advertiserName.trim())
        put("advertiser_email", advertiserEmail.trim().takeIf { it.isNotEmpty() }?.let { JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
        put("advertiser_url", advertiserUrl.trim().takeIf { it.isNotEmpty() }?.let { JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
        put("amount", euroOrNull(amount))
        put("currency", currency.trim().ifEmpty { "EUR" })
        put("invoice_reference", invoiceReference.trim().takeIf { it.isNotEmpty() }?.let { JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
        put("notes", notes.takeIf { it.isNotBlank() }?.let { JsonPrimitive(it) } ?: kotlinx.serialization.json.JsonNull)
    }

    companion object {
        fun from(detail: CampaignDetail): CampaignDraft {
            val form: CampaignForm = detail.form
            return CampaignDraft(
                eventId = form.eventId, eventTitle = detail.event?.title ?: detail.eventGone.orEmpty(), placement = form.placement, status = form.status,
                startsAt = form.startsAt, endsAt = form.endsAt, priority = form.priority.toString(), weight = form.weight.toString(),
                impressionsCap = form.impressionsCap?.toString().orEmpty(), clicksCap = form.clicksCap?.toString().orEmpty(),
                impressionsGoal = form.impressionsGoal?.toString().orEmpty(), pacing = form.pacing, advertiserName = form.advertiserName,
                advertiserEmail = form.advertiserEmail.orEmpty(), advertiserUrl = form.advertiserUrl.orEmpty(), amount = form.amount.orEmpty(),
                currency = form.currency, invoiceReference = form.invoiceReference.orEmpty(), notes = form.notes.orEmpty(),
            )
        }

        val FIELDS = listOf("event_id", "placement", "status", "starts_at", "ends_at", "priority", "weight", "impressions_cap", "clicks_cap",
            "impressions_goal", "pacing", "advertiser_name", "advertiser_email", "advertiser_url", "amount", "currency", "invoice_reference", "notes")
    }
}

internal data class CampaignDetailUiState(
    val detail: Loadable<CampaignDetail> = Loadable.Loading,
    val draft: CampaignDraft? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: String? = null,
    val confirm: CampaignConfirm? = null,
    val deleted: Boolean = false,
    val picker: List<PromoEvent>? = null,
    val pickerBusy: Boolean = false,
)

internal class CampaignDetailViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val campaignId: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(CampaignDetailUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { promoJson.decodeFromString(CampaignDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<CampaignDetailUiState> = state.asStateFlow()
    private var search: Job? = null

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val detail = repository.call { source.campaign(campaignId) }
                state.update { it.copy(detail = Loadable.Ready(detail), draft = it.draft ?: CampaignDraft.from(detail)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(detail = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (CampaignDraft) -> CampaignDraft) {
        state.update { it.copy(draft = it.draft?.let(change), message = null) }
        persist()
    }

    fun save() {
        val draft = state.value.draft ?: return
        val detail = (state.value.detail as? Loadable.Ready)?.value ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val saved = repository.call { source.updateCampaign(campaignId, draft.body(detail.onGrant)) }
                state.update { it.copy(detail = Loadable.Ready(saved), draft = CampaignDraft.from(saved), message = SAVED) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, CampaignDraft.FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun ask(confirm: CampaignConfirm) = state.update { it.copy(confirm = confirm, errors = FormErrors.None) }
    fun dismiss() = state.update { it.copy(confirm = null) }

    fun confirm() {
        val confirm = state.value.confirm ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                when (confirm) {
                    is CampaignConfirm.Toggle -> {
                        val result = repository.call { source.toggleCampaign(campaignId) }
                        val fresh = repository.call { source.campaign(campaignId) }
                        state.update { it.copy(detail = Loadable.Ready(fresh), draft = it.draft?.copy(status = fresh.form.status), message = result.message) }
                        persist()
                    }
                    is CampaignConfirm.Delete -> {
                        repository.call { source.deleteCampaign(campaignId) }
                        state.update { it.copy(deleted = true) }
                    }
                    is CampaignConfirm.DeleteSelected -> Unit
                }
                state.update { it.copy(confirm = null) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(confirm = null, errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** La scelta di un altro evento: tutti gli sponsorizzabili, più quello di adesso. */
    fun openPicker() { state.update { it.copy(picker = emptyList()) }; searchEvents("") }
    fun closePicker() { search?.cancel(); state.update { it.copy(picker = null, pickerBusy = false) } }

    fun searchEvents(q: String) {
        search?.cancel()
        state.update { it.copy(pickerBusy = true) }
        search = viewModelScope.launch {
            delay(SEARCH_PAUSE)
            try {
                val keep = state.value.draft?.eventId
                val params = buildList {
                    add("sponsor_type" to "any")
                    keep?.let { add("keep" to it.toString()) }
                    q.trim().takeIf { it.isNotEmpty() }?.let { add("q" to it) }
                }
                val found = repository.call { source.events(params) }.data
                state.update { if (it.picker == null) it else it.copy(picker = found, pickerBusy = false) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(pickerBusy = false, errors = FormErrors(general = requestFailureMessage(e))) } }
        }
    }

    fun chooseEvent(event: PromoEvent) {
        edit { it.copy(eventId = event.id, eventTitle = event.title) }
        closePicker()
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = promoJson.encodeToString(CampaignDraft.serializer(), it) } }

    companion object {
        const val KEY_ID = "campaignId"
        const val KEY_DRAFT = "draft"
        const val SAVED = "saved"
        private const val SEARCH_PAUSE = 300L
    }
}

@Composable
internal fun CampaignDetailScreen(model: CampaignDetailViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    LaunchedEffect(state.deleted) { if (state.deleted) context.back() }
    val detail = (state.detail as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.editorial_promo_campaign_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = detail?.let { it.event?.title ?: it.eventGone },
        busy = state.busy,
    ) {
        LoadState(state.detail, onRetry = model::load) { loaded ->
            val draft = state.draft ?: return@LoadState
            CampaignDetailContent(
                detail = loaded,
                draft = draft,
                errors = state.errors,
                busy = state.busy,
                message = state.message?.let { if (it == CampaignDetailViewModel.SAVED) stringResource(R.string.editorial_promo_saved) else it },
                onEdit = model::edit,
                onSave = model::save,
                onPickEvent = model::openPicker,
                onToggle = { model.ask(CampaignConfirm.Toggle(loaded.id, loaded.toggleLabel)) },
                onDelete = { model.ask(CampaignConfirm.Delete(loaded.id, loaded.event?.title ?: loaded.eventGone.orEmpty())) },
                onAnalytics = { context.navController.navigate(PromotionAnalyticsRoute(loaded.id)) },
            )
        }
    }
    state.confirm?.let { CampaignConfirmDialog(it, state.busy, null, model::confirm, model::dismiss) }
    state.picker?.let { events -> PromoEventPickerDialog(events, state.pickerBusy, model::searchEvents, model::chooseEvent, model::closePicker) }
}

/** La scheda e il modulo, senza ViewModel: li provano i test strumentati. */
@Composable
internal fun CampaignDetailContent(
    detail: CampaignDetail,
    draft: CampaignDraft,
    errors: FormErrors,
    busy: Boolean,
    message: String?,
    onEdit: ((CampaignDraft) -> CampaignDraft) -> Unit,
    onSave: () -> Unit,
    onPickEvent: () -> Unit,
    onToggle: () -> Unit,
    onDelete: () -> Unit,
    onAnalytics: () -> Unit,
) {
    val abilities = abilitiesOf(detail.abilities)
    val zone = promoZone(detail.timezone)
    val choices = detail.choices
    message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        SponsorshipBadge(detail.phase.label, detail.phase.color)
        Text("${detail.placement.label} · ${detail.status.label}", color = Muted)
    }
    Text(stringResource(R.string.editorial_promo_row_numbers, detail.advertiserName, detail.impressions, detail.clicks, detail.clickRate))
    detail.eventWarning?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (abilities["toggle"] == true) OutlinedButton(enabled = !busy, onClick = onToggle, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(detail.toggleLabel) }
        OutlinedButton(onClick = onAnalytics, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_campaign_stats)) }
    }
    PromoProgressDetailView(detail.progressDetail)
    if (abilities["update"] != true) return
    HorizontalDivider()
    FormScaffold(errors = errors, busy = busy, submitLabel = stringResource(R.string.editorial_promo_save), onSubmit = onSave) {
        Text(stringResource(R.string.editorial_promo_tab_campaign), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_promo_field_event), style = MaterialTheme.typography.titleMedium)
        Text(draft.eventTitle.ifBlank { stringResource(R.string.editorial_promo_event_none_chosen) })
        errors.field("event_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (!detail.onGrant) OutlinedButton(enabled = !busy, onClick = onPickEvent, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_event_change)) }
        ChoiceField(stringResource(R.string.editorial_promo_field_placement), choices.placements.options(), draft.placement, { v -> onEdit { it.copy(placement = v) } }, errors.field("placement"))
        ChoiceField(stringResource(R.string.editorial_promo_field_status), choices.statuses.options(), draft.status, { v -> onEdit { it.copy(status = v) } }, errors.field("status"))

        Text(stringResource(R.string.editorial_promo_tab_period), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_promo_window_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        CityDateTimeField(stringResource(R.string.editorial_promo_field_starts), cityLocalDateTime(draft.startsAt, zone), zone, null, { v -> onEdit { it.copy(startsAt = v?.let { local -> cityIso(local, zone) }) } }, errors.field("starts_at"))
        CityDateTimeField(stringResource(R.string.editorial_promo_field_ends), cityLocalDateTime(draft.endsAt, zone), zone, null, { v -> onEdit { it.copy(endsAt = v?.let { local -> cityIso(local, zone) }) } }, errors.field("ends_at"))
        detail.grantHelp?.let { Text(it, color = Muted) }
        NumberField(draft.priority, { v -> onEdit { it.copy(priority = v) } }, stringResource(R.string.editorial_promo_field_priority), errors, "priority", enabled = !detail.onGrant, help = stringResource(R.string.editorial_promo_priority_help))
        NumberField(draft.weight, { v -> onEdit { it.copy(weight = v) } }, stringResource(R.string.editorial_promo_field_weight), errors, "weight", enabled = !detail.onGrant, help = stringResource(R.string.editorial_promo_weight_help))
        Text(stringResource(R.string.editorial_promo_caps_title), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.editorial_promo_caps_lead), color = Muted, style = MaterialTheme.typography.bodySmall)
        NumberField(draft.impressionsCap, { v -> onEdit { it.copy(impressionsCap = v) } }, stringResource(R.string.editorial_promo_field_impressions_cap), errors, "impressions_cap")
        NumberField(draft.clicksCap, { v -> onEdit { it.copy(clicksCap = v) } }, stringResource(R.string.editorial_promo_field_clicks_cap), errors, "clicks_cap")

        Text(stringResource(R.string.editorial_promo_tab_delivery), style = MaterialTheme.typography.titleLarge)
        NumberField(draft.impressionsGoal, { v -> onEdit { it.copy(impressionsGoal = v) } }, stringResource(R.string.editorial_promo_field_goal), errors, "impressions_goal",
            enabled = !detail.onGrant, help = detail.periodOnly ?: stringResource(R.string.editorial_promo_goal_help))
        ChoiceField(stringResource(R.string.editorial_promo_field_pacing), choices.pacings.options(), draft.pacing, { v -> if (!detail.onGrant) onEdit { it.copy(pacing = v) } }, errors.field("pacing"))

        Text(stringResource(R.string.editorial_promo_tab_client), style = MaterialTheme.typography.titleLarge)
        FormTextField(draft.advertiserName, { v -> onEdit { it.copy(advertiserName = v) } }, stringResource(R.string.editorial_promo_field_advertiser), errors, "advertiser_name", singleLine = true,
            help = stringResource(R.string.editorial_promo_advertiser_help))
        FormTextField(draft.advertiserEmail, { v -> onEdit { it.copy(advertiserEmail = v) } }, stringResource(R.string.editorial_promo_field_advertiser_email), errors, "advertiser_email", singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
        FormTextField(draft.advertiserUrl, { v -> onEdit { it.copy(advertiserUrl = v) } }, stringResource(R.string.editorial_promo_field_advertiser_url), errors, "advertiser_url", singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
        FormTextField(draft.amount, { v -> onEdit { it.copy(amount = v) } }, stringResource(R.string.editorial_promo_field_amount), errors, "amount", singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
        FormTextField(draft.currency, { v -> onEdit { it.copy(currency = v.take(3).uppercase()) } }, stringResource(R.string.editorial_promo_field_currency), errors, "currency", singleLine = true)
        FormTextField(draft.invoiceReference, { v -> onEdit { it.copy(invoiceReference = v) } }, stringResource(R.string.editorial_promo_field_invoice), errors, "invoice_reference", singleLine = true)
        FormTextField(draft.notes, { v -> onEdit { it.copy(notes = v) } }, stringResource(R.string.editorial_promo_field_notes), errors, "notes", minLines = 3)
    }
    if (abilities["destroy"] == true) {
        TextButton(enabled = !busy, onClick = onDelete, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.editorial_promo_delete), color = MaterialTheme.colorScheme.error)
        }
    }
}

/** Un campo numerico con l'errore del server sotto. */
@Composable
internal fun NumberField(value: String, onChange: (String) -> Unit, label: String, errors: FormErrors, field: String, enabled: Boolean = true, help: String? = null) {
    FormTextField(value, { onChange(it.filter(Char::isDigit).take(9)) }, label, errors, field, singleLine = true, enabled = enabled, help = help,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
}
