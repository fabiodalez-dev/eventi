package it.fabiodalez.incitta.ui.editorial.community

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CarpoolSection
import it.fabiodalez.incitta.data.editorial.CaseEvidence
import it.fabiodalez.incitta.data.editorial.CaseOperation
import it.fabiodalez.incitta.data.editorial.DeskCarpoolCase
import it.fabiodalez.incitta.data.editorial.DeskCarpoolItem
import it.fabiodalez.incitta.data.editorial.RestrictionScope
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.feedback.feedbackDate
import java.time.ZoneId

/* Le schermate di «Passaggi in auto» (R7): le sette schede e il caso aperto. */

private fun sectionTitle(section: String): Int = when (section) {
    CarpoolSection.RIDES -> R.string.ed_carpool_section_rides
    CarpoolSection.REVIEWS -> R.string.ed_carpool_section_reviews
    CarpoolSection.FEEDBACK -> R.string.ed_carpool_section_feedback
    CarpoolSection.USERS -> R.string.ed_carpool_section_users
    CarpoolSection.AUDIT -> R.string.ed_carpool_section_audit
    CarpoolSection.DELIVERIES -> R.string.ed_carpool_section_deliveries
    else -> R.string.ed_carpool_section_cases
}

private fun scopeLabel(scope: String): Int = when (scope) {
    RestrictionScope.SOCIAL -> R.string.ed_carpool_scope_social
    RestrictionScope.CARPOOL -> R.string.ed_carpool_scope_carpool
    else -> R.string.ed_carpool_scope_global
}

@Composable
internal fun CarpoolScreen(viewModel: CarpoolViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onOpenCase: (Long) -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    CarpoolList(list, state, options, viewModel.sections, padding, zone, onBack, viewModel::selectSection, viewModel::search, viewModel::applyFilters,
        viewModel.list::loadMore, viewModel.list::retry, onOpenCase, viewModel::ask)
    state.pending?.let { pending -> CarpoolActionDialog(pending, state.busy, state.errors, reasonRules(options), onConfirm = viewModel::confirm, onDismiss = viewModel::dismiss) }
}

@Composable
internal fun CarpoolList(
    list: PagedState<DeskCarpoolItem>,
    state: CarpoolUiState,
    options: ManagementOptions?,
    sections: List<String>,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onSection: (String) -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpenCase: (Long) -> Unit,
    onAction: (DeskCarpoolItem, CarpoolRowAction) -> Unit,
) {
    val section = list.query.tab ?: CarpoolSection.CASES
    val statuses = carpoolStatusChoices(options, section)
    PagedListScreen(
        title = stringResource(R.string.ed_carpool_page_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.key },
        tabs = sections.map { ListTab(it, stringResource(sectionTitle(it))) },
        onTab = { key -> key?.let(onSection) },
        filters = if (statuses.isEmpty()) emptyList() else listOf(FilterDefinition(CarpoolViewModel.FILTER_STATUS, stringResource(R.string.ed_carpool_status), statuses)),
        onFilters = onFilters,
        searchLabel = stringResource(R.string.ed_carpool_search),
        searchable = section in CarpoolSection.searchable,
        emptyText = stringResource(R.string.ed_carpool_empty),
        header = { DeskMessage(state.message) },
    ) { item -> CarpoolRow(item, zone, enabled = !state.busy, onOpenCase = { onOpenCase(item.id) }, onAction = { action -> onAction(item, action) }) }
}

@Composable
private fun CarpoolRow(item: DeskCarpoolItem, zone: ZoneId, enabled: Boolean, onOpenCase: () -> Unit, onAction: (CarpoolRowAction) -> Unit) {
    val none = stringResource(R.string.ed_carpool_none)
    val actions = carpoolActions(item, abilitiesOf(item.abilities))
    DeskRowCard {
        when (item.kind) {
            "case" -> {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(stringResource(R.string.ed_carpool_case_row, item.id, item.reporter ?: none), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    DeskBadge(item.statusLabel ?: item.status.orEmpty())
                }
                Text(listOf(feedbackDate(item.createdAt, zone) ?: none, item.assignee ?: stringResource(R.string.ed_carpool_unassigned), item.reason.orEmpty()).joinToString(" · "))
                OutlinedButton(onClick = onOpenCase, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.ed_carpool_open_case)) }
            }
            "ride" -> {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(stringResource(R.string.ed_carpool_ride_row, item.id, item.title.orEmpty(), item.legLabel ?: item.leg.orEmpty()), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    DeskBadge(item.statusLabel ?: item.status.orEmpty())
                }
                Text(listOf(item.driver ?: none, item.zone.orEmpty(), feedbackDate(item.departureAt, zone) ?: none).joinToString(" · "))
                Text(stringResource(R.string.ed_carpool_capacity_summary, item.capacity, item.requestsCount), style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            "review" -> {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    val who = item.rating?.let { stringResource(R.string.ed_carpool_review_row, item.author ?: none, item.driver ?: none, it) }
                        ?: stringResource(R.string.ed_carpool_review_row_no_rating, item.author ?: none, item.driver ?: none)
                    Text(who, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    if (item.hidden) DeskBadge(stringResource(R.string.ed_carpool_hidden), alert = true)
                }
                item.body?.takeIf { it.isNotBlank() }?.let { Text(it) }
                Text(stringResource(R.string.ed_carpool_review_confirmed, feedbackDate(item.confirmedAt, zone) ?: none, item.rideRequestId), style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            "feedback" -> {
                Text(stringResource(R.string.ed_carpool_feedback_row, item.author ?: none, item.kindLabel ?: item.feedbackKind.orEmpty(), item.rideRequestId),
                    style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                feedbackDate(item.createdAt, zone)?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                item.body?.takeIf { it.isNotBlank() }?.let { Text(it) }
            }
            "user" -> {
                Text(stringResource(R.string.ed_carpool_user_row, item.name.orEmpty(), item.id), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                Text(stringResource(R.string.ed_carpool_user_checks, feedbackDate(item.emailVerifiedAt, zone) ?: none, feedbackDate(item.whatsappVerifiedAt, zone) ?: none),
                    style = MaterialTheme.typography.bodySmall, color = Muted)
                val suspended = RestrictionScope.all.filter(item::suspendedIn).map { stringResource(scopeLabel(it)) }
                if (suspended.isNotEmpty()) DeskBadge(stringResource(R.string.ed_carpool_suspended_in, suspended.joinToString(", ")), alert = true)
            }
            "audit" -> Text(stringResource(R.string.ed_carpool_audit_row, item.id, feedbackDate(item.createdAt, zone) ?: none, item.action.orEmpty(), item.subjectType.orEmpty(),
                item.subjectId?.toString() ?: none, item.actor ?: stringResource(R.string.ed_carpool_system)))
            "delivery" -> {
                Text(stringResource(R.string.ed_carpool_delivery_row, item.id, item.userId, item.attempts))
                item.lastError?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
            }
        }
        if (actions.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            actions.forEach { action ->
                val destructive = action == CarpoolRowAction.CancelRide || action == CarpoolRowAction.HideReview || action is CarpoolRowAction.Suspend
                OutlinedButton(onClick = { onAction(action) }, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(carpoolActionLabel(action)), color = if (destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
                }
            }
        }
    }
}

private fun carpoolActionLabel(action: CarpoolRowAction): Int = when (action) {
    CarpoolRowAction.CancelRide -> R.string.ed_carpool_cancel_ride
    CarpoolRowAction.HideReview -> R.string.ed_carpool_review_hide
    CarpoolRowAction.RestoreReview -> R.string.ed_carpool_review_restore
    CarpoolRowAction.RetryDelivery -> R.string.ed_carpool_retry
    is CarpoolRowAction.Suspend -> when (action.scope) {
        RestrictionScope.SOCIAL -> R.string.ed_carpool_suspend_social
        RestrictionScope.CARPOOL -> R.string.ed_carpool_suspend_carpool
        else -> R.string.ed_carpool_suspend_global
    }
    is CarpoolRowAction.Restore -> when (action.scope) {
        RestrictionScope.SOCIAL -> R.string.ed_carpool_restore_social
        RestrictionScope.CARPOOL -> R.string.ed_carpool_restore_carpool
        else -> R.string.ed_carpool_restore_global
    }
}

@Composable
private fun CarpoolActionDialog(pending: CarpoolPendingAction, busy: Boolean, errors: FormErrors, rules: ReasonRules, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    val action = pending.action
    val text = when (action) {
        CarpoolRowAction.CancelRide -> R.string.ed_carpool_cancel_ride_text
        CarpoolRowAction.HideReview -> R.string.ed_carpool_review_hide_text
        CarpoolRowAction.RestoreReview -> R.string.ed_carpool_review_restore_text
        CarpoolRowAction.RetryDelivery -> R.string.ed_carpool_retry_text
        is CarpoolRowAction.Suspend -> R.string.ed_carpool_suspend_text
        is CarpoolRowAction.Restore -> R.string.ed_carpool_restore_text
    }
    ReasonDialog(
        title = stringResource(carpoolActionLabel(action)),
        text = stringResource(text),
        confirmLabel = stringResource(R.string.ed_community_confirm),
        onConfirm = onConfirm,
        onDismiss = onDismiss,
        rules = rules,
        destructive = action == CarpoolRowAction.CancelRide || action == CarpoolRowAction.HideReview || action is CarpoolRowAction.Suspend,
        busy = busy,
        error = reasonError(errors),
    )
}

// --------------------------------------------------------------- il caso

@Composable
internal fun CarpoolCaseScreen(viewModel: CarpoolCaseViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onOpenWeb: (String) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    CarpoolCaseDetail(state, padding, zone, onBack, viewModel::load, viewModel::ask, viewModel::closeEvidence, onOpenWeb)
    val rules = reasonRules(options)
    when (val dialog = state.dialog) {
        null -> Unit
        CaseDialog.Reply -> (state.case as? Loadable.Ready)?.value?.let { case ->
            ReplyDialog(case, state.busy, state.errors, rules, onSend = viewModel::reply, onDismiss = viewModel::dismiss)
        }
        else -> {
            val (title, text, destructive) = caseDialogTexts(dialog)
            ReasonDialog(
                title = stringResource(title),
                text = stringResource(text),
                confirmLabel = stringResource(R.string.ed_community_confirm),
                onConfirm = viewModel::confirm,
                onDismiss = viewModel::dismiss,
                rules = rules,
                destructive = destructive,
                busy = state.busy,
                error = reasonError(state.errors),
            )
        }
    }
}

private fun caseDialogTexts(dialog: CaseDialog): Triple<Int, Int, Boolean> = when (dialog) {
    is CaseDialog.Operation -> when (dialog.op) {
        CaseOperation.ASSIGN -> Triple(R.string.ed_carpool_op_assign, R.string.ed_carpool_op_assign_text, false)
        CaseOperation.RESOLVE -> Triple(R.string.ed_carpool_op_resolve, R.string.ed_carpool_op_resolve_text, false)
        CaseOperation.REOPEN -> Triple(R.string.ed_carpool_op_reopen, R.string.ed_carpool_op_reopen_text, false)
        else -> Triple(R.string.ed_carpool_op_note, R.string.ed_carpool_op_note_text, false)
    }
    is CaseDialog.Retention -> if (dialog.op == CaseOperation.RELEASE) Triple(R.string.ed_carpool_release, R.string.ed_carpool_release_text, false)
        else Triple(R.string.ed_carpool_hold, R.string.ed_carpool_hold_text, false)
    is CaseDialog.Freeze -> if (dialog.freeze) Triple(R.string.ed_carpool_freeze_chat, R.string.ed_carpool_freeze_text, true)
        else Triple(R.string.ed_carpool_unfreeze_chat, R.string.ed_carpool_unfreeze_text, false)
    CaseDialog.Evidence -> Triple(R.string.ed_carpool_inspect, R.string.ed_carpool_inspect_text, false)
    CaseDialog.Reply -> Triple(R.string.ed_carpool_reply, R.string.ed_carpool_reply_text, false)
}

private fun opLabel(op: String): Int = when (op) {
    CaseOperation.ASSIGN -> R.string.ed_carpool_op_assign
    CaseOperation.RESOLVE -> R.string.ed_carpool_op_resolve
    CaseOperation.REOPEN -> R.string.ed_carpool_op_reopen
    else -> R.string.ed_carpool_op_note
}

@Composable
internal fun CarpoolCaseDetail(
    state: CarpoolCaseUiState,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onAsk: (CaseDialog) -> Unit,
    onCloseEvidence: () -> Unit,
    onOpenWeb: (String) -> Unit,
) {
    val none = stringResource(R.string.ed_carpool_none)
    val title = (state.case as? Loadable.Ready)?.value?.let { stringResource(R.string.ed_carpool_case_title, it.id) }
        ?: stringResource(R.string.ed_carpool_page_title)
    ManagementScaffold(title = title, padding = padding, onBack = onBack, busy = state.busy) {
        LoadState(state.case, onRetry = onRetry, emptyText = stringResource(R.string.ed_carpool_case_missing)) { case ->
            val can = abilitiesOf(case.abilities)
            DeskMessage(state.message)
            DeskBadge(case.statusLabel.ifBlank { case.status })
            Text(stringResource(R.string.ed_carpool_case_by, case.reporter ?: none), style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.ed_carpool_case_reason, case.reason))
            feedbackDate(case.createdAt, zone)?.let { Text(stringResource(R.string.ed_carpool_case_received, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
            Text(stringResource(R.string.ed_carpool_case_assignee, case.assignee ?: stringResource(R.string.ed_carpool_unassigned)), style = MaterialTheme.typography.bodySmall, color = Muted)
            case.body?.takeIf { it.isNotBlank() }?.let { Text(it) }
            case.review?.let { review -> Text(stringResource(R.string.ed_carpool_case_review, review.rating ?: 0, review.body.orEmpty())) }
            case.social?.let { Text(stringResource(R.string.ed_carpool_social_reference, it.type, it.id)) }
            case.rideOfferId?.let { offer -> OpenOnWebButton(stringResource(R.string.ed_carpool_ride_open, offer)) { onOpenWeb("passaggi/offerte/$offer") } }

            HorizontalDivider()
            Text(stringResource(R.string.ed_carpool_case_actions), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                CarpoolCaseViewModel.operations(case.closedAt != null).filter { can[it] == true }.forEach { op ->
                    OutlinedButton(onClick = { onAsk(CaseDialog.Operation(op)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(opLabel(op))) }
                }
                if (can["reply"] == true && case.people.isNotEmpty()) OutlinedButton(onClick = { onAsk(CaseDialog.Reply) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.ed_carpool_reply))
                }
                if (can["freeze"] == true) OutlinedButton(onClick = { onAsk(CaseDialog.Freeze(true)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.ed_carpool_freeze_chat), color = MaterialTheme.colorScheme.error)
                }
                if (can["unfreeze"] == true) OutlinedButton(onClick = { onAsk(CaseDialog.Freeze(false)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.ed_carpool_unfreeze_chat))
                }
            }

            if (can["hold"] == true || can["release"] == true) {
                HorizontalDivider()
                Text(stringResource(R.string.ed_carpool_retention_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                Text(stringResource(R.string.ed_carpool_hold_hint, feedbackDate(case.holdUntil, zone) ?: none))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (can["hold"] == true) OutlinedButton(onClick = { onAsk(CaseDialog.Retention(CaseOperation.HOLD)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.ed_carpool_hold))
                    }
                    if (can["release"] == true) OutlinedButton(onClick = { onAsk(CaseDialog.Retention(CaseOperation.RELEASE)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.ed_carpool_release))
                    }
                }
            }

            if (can["evidence"] == true) {
                HorizontalDivider()
                Text(stringResource(R.string.ed_carpool_evidence_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                Text(stringResource(R.string.ed_carpool_access_logged), color = Muted)
                val evidence = state.evidence
                if (evidence == null) {
                    Button(onClick = { onAsk(CaseDialog.Evidence) }, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.ed_carpool_inspect)) }
                } else {
                    EvidenceBlock(evidence, zone, onCloseEvidence)
                }
                if (can["export"] == true) {
                    Text(stringResource(R.string.ed_carpool_export_on_web), style = MaterialTheme.typography.bodySmall, color = Muted)
                    OpenOnWebButton(stringResource(R.string.ed_carpool_export_open)) { onOpenWeb("admin/carpool") }
                }
            }

            HorizontalDivider()
            Text(stringResource(R.string.ed_carpool_conversation), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            if (case.messages.isEmpty()) Text(stringResource(R.string.ed_carpool_no_messages), color = Muted)
            case.messages.forEach { message ->
                Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    val to = if (message.internal) stringResource(R.string.ed_carpool_internal) else message.recipient ?: stringResource(R.string.ed_carpool_to_staff)
                    Text(stringResource(R.string.ed_carpool_message_meta, message.author ?: none, feedbackDate(message.createdAt, zone) ?: none, to), style = MaterialTheme.typography.labelMedium, color = Muted)
                    Text(message.body)
                }
            }
        }
    }
}

@Composable
private fun EvidenceBlock(evidence: CaseEvidence, zone: ZoneId, onClose: () -> Unit) {
    val none = stringResource(R.string.ed_carpool_none)
    DeskRowCard {
        evidence.review?.let { review -> Text(stringResource(R.string.ed_carpool_case_review, review.rating ?: 0, review.body.orEmpty())) }
        Text(stringResource(R.string.ed_carpool_evidence_messages), style = MaterialTheme.typography.titleSmall)
        if (evidence.messages.isEmpty()) Text(stringResource(R.string.ed_carpool_evidence_no_messages), color = Muted)
        evidence.messages.forEach { message ->
            Text(feedbackDate(message.at, zone) ?: none, style = MaterialTheme.typography.labelMedium, color = Muted)
            Text(message.body)
        }
        evidence.audit?.let { entries ->
            Text(stringResource(R.string.ed_carpool_evidence_audit), style = MaterialTheme.typography.titleSmall)
            entries.forEach { entry -> Text(stringResource(R.string.ed_carpool_evidence_audit_row, entry.id, feedbackDate(entry.at, zone) ?: none, entry.action), style = MaterialTheme.typography.bodySmall) }
        }
        Text(stringResource(R.string.ed_carpool_evidence_generated, feedbackDate(evidence.generatedAt, zone) ?: none, evidence.sha256.take(16)), style = MaterialTheme.typography.bodySmall, color = Muted)
        TextButton(onClick = onClose, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.ed_carpool_evidence_close)) }
    }
}

/** «Invia risposta»: la persona (una delle persone del caso) e il messaggio che riceve. */
@Composable
internal fun ReplyDialog(case: DeskCarpoolCase, busy: Boolean, errors: FormErrors, rules: ReasonRules, onSend: (Long, String) -> Unit, onDismiss: () -> Unit) {
    var recipient by rememberSaveable(case.id) { mutableStateOf(if (case.people.size == 1) case.people.first().id else 0L) }
    var message by rememberSaveable(case.id) { mutableStateOf("") }
    val error = errors.field(CarpoolCaseViewModel.FIELD_MESSAGE) ?: errors.field("recipient_id") ?: errors.general
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.ed_carpool_reply)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(stringResource(R.string.ed_carpool_reply_text))
                Text(stringResource(R.string.ed_carpool_recipient), style = MaterialTheme.typography.labelLarge)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    case.people.forEach { person ->
                        FilterChip(selected = recipient == person.id, enabled = !busy, onClick = { recipient = person.id }, label = { Text(person.label) }, modifier = Modifier.heightIn(min = 48.dp))
                    }
                }
                if (recipient == 0L) Text(stringResource(R.string.ed_carpool_choose_recipient), style = MaterialTheme.typography.bodySmall, color = Muted)
                OutlinedTextField(
                    value = message,
                    onValueChange = { message = it.take(rules.max + 50) },
                    label = { Text(stringResource(R.string.ed_carpool_message)) },
                    enabled = !busy,
                    minLines = 4,
                    isError = error != null,
                    supportingText = { Text(error ?: stringResource(R.string.editorial_reason_counter, message.trim().length, rules.max)) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && recipient != 0L && rules.accepts(message), onClick = { onSend(recipient, message.trim()) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.ed_carpool_reply))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

/** Le scelte di stato della scheda, per i test e per chi non ha ancora `options`. */
internal fun carpoolStatusChoices(options: ManagementOptions?, section: String): List<OptionChoice> = when (section) {
    CarpoolSection.CASES -> options?.choices("carpool_case_statuses").orEmpty()
    CarpoolSection.RIDES -> options?.choices("ride_statuses").orEmpty()
    else -> emptyList()
}
