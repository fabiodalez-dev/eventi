package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.BannerSettings
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.PriceDraft
import it.fabiodalez.incitta.data.editorial.PriceRow
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.options
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Il listino (R-SP-13) e i banner degli sponsor (R-SP-14), come
 * `SponsorshipPriceResource` e `SponsorshipBanners`: una voce per
 * collocazione e durata (nuova, modifica, elimina con la password recente) e
 * i due interruttori, sito e app Android.
 */

internal data class PriceListUiState(
    val choices: PromoChoices = PromoChoices(),
    val editing: PriceDraft? = null,
    val deleting: PriceRow? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
)

internal class PriceListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(PriceListUiState())
    val uiState: StateFlow<PriceListUiState> = state.asStateFlow()
    val list = PagedListController(viewModelScope, ListQuery(perPage = ListQuery.MAX_PER_PAGE)) { query, page ->
        val result = repository.call { source.prices(query.params(page)) }
        state.update { it.copy(choices = result.choices) }
        result.page()
    }

    init { list.refresh() }

    fun create() = state.update { it.copy(editing = PriceDraft(placement = it.choices.placements.firstOrNull()?.value.orEmpty(), months = "1"), errors = FormErrors.None) }
    fun open(row: PriceRow) = state.update { it.copy(editing = PriceDraft.from(row), errors = FormErrors.None) }
    fun edit(change: (PriceDraft) -> PriceDraft) = state.update { it.copy(editing = it.editing?.let(change)) }
    fun askDelete(row: PriceRow) = state.update { it.copy(deleting = row, editing = null, errors = FormErrors.None) }
    fun dismiss() = state.update { it.copy(editing = null, deleting = null, errors = FormErrors.None) }

    fun save() {
        val draft = state.value.editing ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                repository.call { if (draft.id == 0L) source.createPrice(draft.body()) else source.updatePrice(draft.id, draft.body()) }
                state.update { it.copy(editing = null) }
                list.refresh()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, listOf("placement", "months", "amount", "is_active"))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun delete() {
        val row = state.value.deleting ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                repository.call { source.deletePrice(row.id) }
                list.remove { it.id == row.id }
                state.update { it.copy(deleting = null) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }
}

@Composable
internal fun PriceListScreen(model: PriceListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    PriceListContent(list, context.padding, context::back, model.list::search, model.list::applyFilters, model.list::loadMore, model.list::retry, model::open, model::create)
    state.editing?.let { draft -> PriceDialog(draft, state.choices, state.errors, state.busy, model::edit, model::save, model::dismiss, onDelete = list.items.firstOrNull { it.id == draft.id }
        ?.takeIf { abilitiesOf(it.abilities)["destroy"] == true }?.let { row -> { model.askDelete(row) } }) }
    state.deleting?.let { row ->
        ConfirmDialog(
            title = stringResource(R.string.editorial_promo_price_delete),
            text = listOfNotNull(stringResource(R.string.editorial_promo_price_delete_confirm, row.placement.label, row.months), state.errors.general).joinToString("\n\n"),
            confirmLabel = stringResource(R.string.editorial_promo_delete),
            onConfirm = model::delete,
            onDismiss = model::dismiss,
            destructive = true,
            enabled = !state.busy,
        )
    }
}

/** Il contenuto dell'elenco, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun PriceListContent(
    list: PagedState<PriceRow>,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (PriceRow) -> Unit,
    onNew: () -> Unit,
) {
    val active = listOf(OptionChoice("1", stringResource(R.string.editorial_promo_yes)), OptionChoice("0", stringResource(R.string.editorial_promo_no)))
    PagedListScreen(
        title = stringResource(R.string.editorial_promo_prices_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = PriceRow::id,
        searchable = false,
        filters = listOf(FilterDefinition("is_active", stringResource(R.string.editorial_promo_price_active), active)),
        onFilters = onFilters,
        emptyText = stringResource(R.string.editorial_promo_prices_empty),
        header = { Button(onClick = onNew, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_price_new)) } },
    ) { row ->
        Row(Modifier.fillMaxWidth().clickable(role = Role.Button) { onOpen(row) }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(row.placement.label, style = MaterialTheme.typography.titleMedium)
                Text(stringResource(R.string.editorial_promo_price_months, row.months), color = Muted)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(row.amountLabel, style = MaterialTheme.typography.titleMedium)
                Text(stringResource(if (row.isActive) R.string.editorial_promo_price_on else R.string.editorial_promo_price_off), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
        HorizontalDivider()
    }
}

@Composable
internal fun PriceDialog(
    draft: PriceDraft,
    choices: PromoChoices,
    errors: FormErrors,
    busy: Boolean,
    onEdit: ((PriceDraft) -> PriceDraft) -> Unit,
    onSave: () -> Unit,
    onDismiss: () -> Unit,
    onDelete: (() -> Unit)?,
) {
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(if (draft.id == 0L) R.string.editorial_promo_price_new else R.string.editorial_promo_price_edit)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_promo_price_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                ChoiceField(stringResource(R.string.editorial_promo_field_placement), choices.placements.options(), draft.placement, { v -> onEdit { it.copy(placement = v) } }, errors.field("placement"))
                ChoiceField(stringResource(R.string.editorial_promo_field_months), choices.months.options(), draft.months, { v -> onEdit { it.copy(months = v) } }, errors.field("months"))
                FormTextField(draft.amount, { v -> onEdit { it.copy(amount = v) } }, stringResource(R.string.editorial_promo_field_amount), errors, "amount", singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                PromoSwitch(stringResource(R.string.editorial_promo_price_active), null, draft.active) { v -> onEdit { it.copy(active = v) } }
                onDelete?.let { delete -> TextButton(enabled = !busy, onClick = delete, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_delete), color = MaterialTheme.colorScheme.error) } }
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = onSave, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_save)) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

// -------------------------------------------------------------- banner

internal data class BannersUiState(
    val settings: Loadable<BannerSettings> = Loadable.Loading,
    val web: Boolean = false,
    val android: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
)

internal class BannersViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(BannersUiState())
    val uiState: StateFlow<BannersUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val settings = repository.call { source.banners() }
                state.update { it.copy(settings = Loadable.Ready(settings), web = settings.webEnabled, android = settings.androidEnabled) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(settings = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun setWeb(value: Boolean) = state.update { it.copy(web = value, message = null) }
    fun setAndroid(value: Boolean) = state.update { it.copy(android = value, message = null) }

    fun save() {
        if (state.value.busy) return
        val now = state.value
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { source.saveBanners(now.web, now.android) }
                state.update { it.copy(settings = Loadable.Ready(result.data), web = result.data.webEnabled, android = result.data.androidEnabled, message = result.message) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }
}

@Composable
internal fun BannersScreen(model: BannersViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(title = stringResource(R.string.editorial_promo_banners_title), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.settings, onRetry = model::load) { settings -> BannersContent(settings, state, model::setWeb, model::setAndroid, model::save) }
    }
}

/** I due interruttori, senza ViewModel: li provano i test strumentati. */
@Composable
internal fun BannersContent(settings: BannerSettings, state: BannersUiState, onWeb: (Boolean) -> Unit, onAndroid: (Boolean) -> Unit, onSave: () -> Unit) {
    Text(settings.lead)
    Text(settings.rules, color = Muted, style = MaterialTheme.typography.bodySmall)
    Text(stringResource(R.string.editorial_promo_banners_where), style = MaterialTheme.typography.titleMedium)
    PromoSwitch(stringResource(R.string.editorial_promo_banners_web), null, state.web, onWeb)
    PromoSwitch(stringResource(R.string.editorial_promo_banners_android), null, state.android, onAndroid)
    Text(settings.switchOff, color = Muted, style = MaterialTheme.typography.bodySmall)
    state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
    state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    Button(enabled = !state.busy, onClick = onSave, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_banners_save)) }
}
