package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.OrderRow
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.Settlement
import it.fabiodalez.incitta.data.editorial.options
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipBadge
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipMoment
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Gli ordini di sponsorizzazione (R-SP-07…10), come
 * `SponsorshipOrderResource`: l'elenco con il filtro di stato e le tre
 * decisioni. Un ordine pagato si approva con una conferma; una richiesta
 * dicendo come è stata saldata (pagamento ricevuto: importo, metodo,
 * riferimento; oppure gratuita con il motivo); il rifiuto chiede il motivo,
 * che legge chi ha ordinato, e rimborsa su Stripe un ordine pagato.
 */

/** La decisione aperta su un ordine. */
internal sealed interface OrderDecisionDialog {
    val order: OrderRow
    data class ApprovePaid(override val order: OrderRow) : OrderDecisionDialog
    data class ApproveRequest(override val order: OrderRow, val settlement: Settlement) : OrderDecisionDialog
    data class Reject(override val order: OrderRow) : OrderDecisionDialog
}

internal data class OrderListUiState(
    val choices: PromoChoices = PromoChoices(),
    val dialog: OrderDecisionDialog? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: String? = null,
)

internal class OrderListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(OrderListUiState())
    val uiState: StateFlow<OrderListUiState> = state.asStateFlow()
    val list = PagedListController(viewModelScope, ListQuery()) { query, page ->
        val result = repository.call { source.orders(query.params(page)) }
        state.update { it.copy(choices = result.choices) }
        result.page()
    }

    init { list.refresh() }

    fun open(order: OrderRow, action: String) {
        val dialog = when (action) {
            "approve" -> OrderDecisionDialog.ApprovePaid(order)
            "approve_request" -> OrderDecisionDialog.ApproveRequest(order, Settlement(amount = order.suggestedAmount.orEmpty()))
            "reject" -> OrderDecisionDialog.Reject(order)
            else -> null
        }
        state.update { it.copy(dialog = dialog, errors = FormErrors.None, message = null) }
    }

    fun editSettlement(change: (Settlement) -> Settlement) = state.update { now ->
        val dialog = now.dialog as? OrderDecisionDialog.ApproveRequest ?: return@update now
        now.copy(dialog = dialog.copy(settlement = change(dialog.settlement)))
    }

    fun dismiss() = state.update { it.copy(dialog = null, errors = FormErrors.None) }

    fun approve() {
        val dialog = state.value.dialog ?: return
        val body = when (dialog) {
            is OrderDecisionDialog.ApproveRequest -> dialog.settlement.body()
            else -> kotlinx.serialization.json.JsonObject(emptyMap())
        }
        decide { source.approveOrder(dialog.order.id, body) }
    }

    fun reject(reason: String) {
        val dialog = state.value.dialog ?: return
        decide { source.rejectOrder(dialog.order.id, reason) }
    }

    private fun decide(call: suspend () -> it.fabiodalez.incitta.data.editorial.OrderDecision) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.call { call() }
                list.replace({ it.id == result.data.id }, result.data)
                state.update { it.copy(dialog = null, message = result.message) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, SETTLEMENT_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        val SETTLEMENT_FIELDS = listOf("settlement", "amount", "payment_method", "payment_reference", "reason")
    }
}

@Composable
internal fun OrderListScreen(model: OrderListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    OrderListContent(list, state, context.padding, context::back, model.list::search, model.list::applyFilters, model.list::loadMore, model.list::retry, model::open)
    when (val dialog = state.dialog) {
        is OrderDecisionDialog.ApprovePaid -> ConfirmDialog(
            title = stringResource(R.string.editorial_promo_order_approve),
            text = listOfNotNull(dialog.order.approveLead, state.errors.general).joinToString("\n\n"),
            confirmLabel = stringResource(R.string.editorial_promo_order_approve),
            onConfirm = model::approve,
            onDismiss = model::dismiss,
            enabled = !state.busy,
        )
        is OrderDecisionDialog.ApproveRequest -> SettlementDialog(dialog, state.choices, state.errors, state.busy, model::editSettlement, model::approve, model::dismiss)
        is OrderDecisionDialog.Reject -> ReasonDialog(
            title = stringResource(R.string.editorial_promo_order_reject),
            text = dialog.order.rejectLead,
            confirmLabel = stringResource(R.string.editorial_promo_order_reject),
            onConfirm = model::reject,
            onDismiss = model::dismiss,
            rules = ReasonRules(min = 1, max = REJECT_MAX),
            destructive = true,
            busy = state.busy,
            error = state.errors.field("reason") ?: state.errors.general,
            fieldLabel = stringResource(R.string.editorial_promo_order_reject_reason),
        )
        null -> Unit
    }
}

/* Il tetto del motivo del rifiuto nel pannello (`maxLength(2000)`), senza un minimo. */
internal const val REJECT_MAX = 2000

/** Il contenuto dell'elenco, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun OrderListContent(
    list: PagedState<OrderRow>,
    ui: OrderListUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onAction: (OrderRow, String) -> Unit,
) {
    val actions = listOf(
        RowAction("approve", stringResource(R.string.editorial_promo_order_approve)),
        RowAction("approve_request", stringResource(R.string.editorial_promo_order_approve)),
        RowAction("reject", stringResource(R.string.editorial_promo_order_reject), destructive = true),
    )
    val zone = promoZone(EDITORIAL_REPORT_ZONE)
    PagedListScreen(
        title = stringResource(R.string.editorial_promo_orders_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = OrderRow::id,
        searchable = false,
        filters = listOf(FilterDefinition("status", stringResource(R.string.editorial_promo_field_status), ui.choices.statuses.options())),
        onFilters = onFilters,
        emptyText = stringResource(R.string.editorial_promo_orders_empty),
        header = { ui.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) } },
    ) { order ->
        Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(order.sponsor, style = MaterialTheme.typography.titleMedium)
                Text("${order.sponsorKind} · ${order.eventLabel}", color = Muted, style = MaterialTheme.typography.bodySmall)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    SponsorshipBadge(order.status.label, order.status.color)
                    Text("${order.placement.label} · ${order.monthsLabel} · ${order.amountLabel}", style = MaterialTheme.typography.bodySmall)
                }
                Text(listOfNotNull(sponsorshipMoment(order.createdAt, zone), order.buyer).joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
                order.requestNote?.let { Text(stringResource(R.string.editorial_promo_order_note, it), style = MaterialTheme.typography.bodySmall) }
                order.decisionNote?.let { Text(stringResource(R.string.editorial_promo_order_decision, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
            ActionMenu(actions, abilitiesOf(order.abilities), { onAction(order, it.key) }, subject = order.sponsor)
        }
        HorizontalDivider()
    }
}

/** L'approvazione di una richiesta: come è stata saldata. */
@Composable
internal fun SettlementDialog(
    dialog: OrderDecisionDialog.ApproveRequest,
    choices: PromoChoices,
    errors: FormErrors,
    busy: Boolean,
    onEdit: ((Settlement) -> Settlement) -> Unit,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    val settlement = dialog.settlement
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_promo_order_approve)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(dialog.order.approveLead)
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                PromoRadioGroup(stringResource(R.string.editorial_promo_order_settlement), choices.settlements, if (settlement.free) "free" else "paid",
                    { v -> onEdit { it.copy(free = v == "free") } }, errors.field("settlement"))
                if (settlement.free) {
                    FormTextField(settlement.reason, { v -> onEdit { it.copy(reason = v) } }, stringResource(R.string.editorial_promo_order_free_reason), errors, "reason", minLines = 3)
                } else {
                    FormTextField(settlement.amount, { v -> onEdit { it.copy(amount = v) } }, stringResource(R.string.editorial_promo_order_amount), errors, "amount", singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                    ChoiceField(stringResource(R.string.editorial_promo_order_method), choices.methods.options(), settlement.method, { v -> onEdit { it.copy(method = v) } }, errors.field("payment_method"))
                    FormTextField(settlement.reference, { v -> onEdit { it.copy(reference = v) } }, stringResource(R.string.editorial_promo_order_reference), errors, "payment_reference", singleLine = true)
                }
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = onConfirm, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_order_approve)) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}
