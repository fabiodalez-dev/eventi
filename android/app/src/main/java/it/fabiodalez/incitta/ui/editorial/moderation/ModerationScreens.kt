package it.fabiodalez.incitta.ui.editorial.moderation

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.StarOutline
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialComment
import it.fabiodalez.incitta.data.editorial.EditorialReport
import it.fabiodalez.incitta.data.editorial.EditorialReview
import it.fabiodalez.incitta.data.editorial.ModerationLimits
import it.fabiodalez.incitta.data.editorial.ReportStatusValue
import it.fabiodalez.incitta.data.editorial.ReviewStatusValue
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.feedback.feedbackDate
import java.time.ZoneId

/* Le schermate della moderazione (R6). Le azioni di ogni riga sono solo quelle che le `abilities` del server permettono. */

// --------------------------------------------------------------------- menu

@Composable
internal fun ModerationHubScreen(viewModel: ModerationHubViewModel, title: String, padding: PaddingValues, onBack: () -> Unit, onOpen: (ModerationSection) -> Unit) {
    val desk by viewModel.desk.collectAsStateWithLifecycle()
    ManagementScaffold(title = title, padding = padding, onBack = onBack, subtitle = stringResource(R.string.moderation_hub_intro)) {
        val current = desk
        if (current == null) {
            LoadState<Unit>(Loadable.Loading, onRetry = viewModel::refresh) {}
        } else {
            val sections = moderationSections(current.can)
            sections.forEach { section ->
                val badge = current.badge(section.badge)?.takeIf { it > 0 }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { ManagementRow(stringResource(section.title), stringResource(section.help), onClick = { onOpen(section) }) }
                    if (badge != null) Badge(Modifier.padding(start = 8.dp)) { Text(stringResource(R.string.editorial_badge, badge)) }
                }
            }
            if (sections.isEmpty()) Text(stringResource(R.string.moderation_nothing_allowed), color = Muted)
        }
    }
}

// ----------------------------------------------------------------- in comune

/** Un ordinamento proposto in cima all'elenco: `value` è il `sort` del server (`null` = quello del pannello). */
internal data class SortChoice(val value: String?, val label: String)

@Composable
private fun SortChips(choices: List<SortChoice>, selected: String?, onSelect: (String?) -> Unit) {
    val label = stringResource(R.string.moderation_sort)
    LazyRow(Modifier.semantics { contentDescription = label }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(choices, key = { it.value ?: "default" }) { choice ->
            FilterChip(selected = selected == choice.value, onClick = { onSelect(choice.value) }, label = { Text(choice.label) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
}

@Composable
private fun Message(text: UiText?) {
    text?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
}

/** Le scelte di un elenco chiuso del server, o i valori grezzi se `options` non è arrivato. */
private fun choicesOf(options: ManagementOptions?, key: String, fallback: List<String>): List<OptionChoice> =
    options?.choices(key)?.takeIf { it.isNotEmpty() } ?: fallback.map { OptionChoice(it, it) }

@Composable
private fun RowCard(content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp), content = content)
}

// ------------------------------------------------------------- segnalazioni

@Composable
internal fun ReportsScreen(viewModel: ReportsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onOpen: (Long) -> Unit) {
    val state by viewModel.list.current.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ReportsList(state, options, padding, zone, onBack, onOpen, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry)
}

@Composable
internal fun ReportsList(
    state: PagedState<EditorialReport>,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onOpen: (Long) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
) {
    val filters = listOf(
        FilterDefinition(ReportsViewModel.FILTER_STATUS, stringResource(R.string.moderation_filter_status), choicesOf(options, "report_statuses", ReportStatusValue.all)),
        FilterDefinition(ReportsViewModel.FILTER_REASON, stringResource(R.string.moderation_filter_reason), choicesOf(options, "report_reasons", emptyList())),
        FilterDefinition(ReportsViewModel.FILTER_OPEN, stringResource(R.string.moderation_filter_open), listOf(OptionChoice("1", stringResource(R.string.moderation_yes)))),
    ).filter { it.options.isNotEmpty() }
    val sorts = listOf(SortChoice(null, stringResource(R.string.moderation_sort_newest)), SortChoice(ReportsViewModel.SORT_OLDEST, stringResource(R.string.moderation_sort_oldest)))
    PagedListScreen(
        title = stringResource(R.string.moderation_reports_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = {},
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchable = false,
        emptyText = stringResource(R.string.moderation_reports_empty),
        header = { SortChips(sorts, state.query.sort, onSort) },
    ) { report -> ReportRow(report, zone, onClick = { onOpen(report.id) }) }
}

@Composable
private fun ReportRow(report: EditorialReport, zone: ZoneId, onClick: () -> Unit) {
    val open = stringResource(R.string.moderation_report_open, report.subject.label)
    Column(
        Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant)
            .clickable(onClickLabel = open, role = Role.Button, onClick = onClick).heightIn(min = 48.dp).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(report.subject.label, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        Text(report.reasonLabel.ifBlank { report.reason }, style = MaterialTheme.typography.labelLarge)
        feedbackDate(report.createdAt, zone)?.let { Text(stringResource(R.string.moderation_report_received, it), style = MaterialTheme.typography.labelSmall, color = Muted) }
        report.note?.takeIf { it.isNotBlank() }?.let { Text(it, maxLines = 3) }
        Text(report.statusLabel.ifBlank { report.status }, color = if (report.open) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.bodySmall)
        report.reviewedBy?.let { Text(stringResource(R.string.moderation_report_reviewed, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
    }
}

@Composable
internal fun ReportDetailScreen(viewModel: ReportDetailViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onCommunity: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ReportDetail(state, options, padding, zone, onBack, onCommunity, viewModel::load, viewModel::setStatus, viewModel::setNote, viewModel::save, viewModel::askDelete)
    if (state.confirmingDelete) {
        ConfirmDialog(
            title = stringResource(R.string.moderation_report_delete_title),
            text = stringResource(R.string.moderation_report_delete_text) + (state.deleteError?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.moderation_report_delete),
            onConfirm = viewModel::delete,
            onDismiss = viewModel::dismissDelete,
            destructive = true,
            enabled = !state.deleting,
        )
    }
}

@Composable
internal fun ReportDetail(
    state: ReportDetailUiState,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onCommunity: () -> Unit,
    onRetry: () -> Unit,
    onStatus: (String) -> Unit,
    onNote: (String) -> Unit,
    onSave: () -> Unit,
    onDelete: () -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.moderation_report_title), padding = padding, onBack = onBack, busy = state.busy) {
        LoadState(state.report, onRetry = onRetry, emptyText = stringResource(R.string.moderation_report_missing)) { report ->
            val can = abilitiesOf(report.abilities)
            val resolve = can["resolve"] == true
            Text(stringResource(R.string.moderation_report_subject), style = MaterialTheme.typography.labelLarge, color = Muted)
            Text(report.subject.label, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            if (report.subject.community) {
                OutlinedButton(onClick = onCommunity, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
                    Text(stringResource(R.string.moderation_report_community), Modifier.padding(start = 8.dp))
                }
                Text(stringResource(R.string.moderation_report_community_help), style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            Text(stringResource(R.string.moderation_report_reason), style = MaterialTheme.typography.labelLarge, color = Muted)
            Text(report.reasonLabel.ifBlank { report.reason })
            feedbackDate(report.createdAt, zone)?.let { Text(stringResource(R.string.moderation_report_received, it), style = MaterialTheme.typography.labelSmall, color = Muted) }
            Text(stringResource(R.string.moderation_report_note), style = MaterialTheme.typography.labelLarge, color = Muted)
            Text(report.note?.takeIf { it.isNotBlank() } ?: stringResource(R.string.moderation_report_no_note))

            HorizontalDivider()
            Text(stringResource(R.string.moderation_report_outcome), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Message(state.message)
            state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Text(stringResource(R.string.moderation_report_status), style = MaterialTheme.typography.labelLarge)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                choicesOf(options, "report_statuses", ReportStatusValue.all).forEach { choice ->
                    FilterChip(selected = state.status == choice.value, enabled = resolve && !state.busy, onClick = { onStatus(choice.value) }, label = { Text(choice.label) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
            state.errors.field(ReportDetailViewModel.FIELD_STATUS)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            val noteError = state.errors.field(ReportDetailViewModel.FIELD_NOTE)
                ?: if (state.noteTooLong) stringResource(R.string.moderation_report_unsaved, ModerationLimits.RESOLUTION_NOTE_MAX) else null
            OutlinedTextField(
                value = state.note,
                onValueChange = onNote,
                label = { Text(stringResource(R.string.moderation_report_resolution_note)) },
                enabled = resolve && !state.busy,
                minLines = 4,
                isError = noteError != null,
                supportingText = { Text(noteError ?: stringResource(R.string.moderation_report_resolution_help)) },
                modifier = Modifier.fillMaxWidth(),
            )
            report.reviewedBy?.let { name ->
                val at = feedbackDate(report.reviewedAt, zone)
                Text(if (at != null) stringResource(R.string.moderation_report_reviewed_at, name, at) else stringResource(R.string.moderation_report_reviewed, name), style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            if (resolve) Button(onClick = onSave, enabled = state.canSave, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_report_save))
            }
            if (can["destroy"] == true) OutlinedButton(onClick = onDelete, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_report_delete), color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

// --------------------------------------------------------------- recensioni

@Composable
internal fun ReviewsScreen(viewModel: ReviewsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ReviewsList(list, state, options, padding, zone, onBack, viewModel::search, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry,
        onApprove = viewModel::askApprove, onReject = viewModel::askReject, onRemoveReply = viewModel::askRemoveReply)
    when (val dialog = state.dialog) {
        is ReviewDialog.Moderate -> ReviewModerateDialog(dialog.review, dialog.status, state.busy, state.errors, onConfirm = viewModel::moderate, onDismiss = viewModel::dismiss)
        is ReviewDialog.RemoveReply -> ConfirmDialog(
            title = stringResource(R.string.moderation_review_remove_reply_title),
            text = stringResource(R.string.moderation_review_remove_reply_text) + (state.errors.general?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.moderation_review_remove_reply),
            onConfirm = viewModel::removeReply,
            onDismiss = viewModel::dismiss,
            destructive = true,
            enabled = !state.busy,
        )
        null -> Unit
    }
}

@Composable
internal fun ReviewsList(
    list: PagedState<EditorialReview>,
    state: ReviewsUiState,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onApprove: (EditorialReview) -> Unit,
    onReject: (EditorialReview) -> Unit,
    onRemoveReply: (EditorialReview) -> Unit,
) {
    val filters = listOf(
        FilterDefinition(ReviewsViewModel.FILTER_TYPE, stringResource(R.string.moderation_filter_type), listOf(
            OptionChoice("venue", stringResource(R.string.moderation_review_type_venue)),
            OptionChoice("organizer", stringResource(R.string.moderation_review_type_organizer)),
        )),
        FilterDefinition(ReviewsViewModel.FILTER_STATUS, stringResource(R.string.moderation_filter_status),
            choicesOf(options, "review_statuses", listOf(ReviewStatusValue.PENDING, ReviewStatusValue.APPROVED, ReviewStatusValue.REJECTED))),
    )
    val sorts = listOf(
        SortChoice(null, stringResource(R.string.moderation_sort_updated)),
        SortChoice(ReviewsViewModel.SORT_NEWEST, stringResource(R.string.moderation_sort_newest)),
        SortChoice(ReviewsViewModel.SORT_OLDEST, stringResource(R.string.moderation_sort_oldest)),
    )
    PagedListScreen(
        title = stringResource(R.string.moderation_reviews_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.moderation_reviews_search),
        emptyText = stringResource(R.string.moderation_reviews_empty),
        header = {
            SortChips(sorts, list.query.sort, onSort)
            Message(state.message)
        },
    ) { review -> ReviewRow(review, zone, enabled = !state.busy, onApprove = { onApprove(review) }, onReject = { onReject(review) }, onRemoveReply = { onRemoveReply(review) }) }
}

@Composable
private fun ReviewRow(review: EditorialReview, zone: ZoneId, enabled: Boolean, onApprove: () -> Unit, onReject: () -> Unit, onRemoveReply: () -> Unit) {
    val can = abilitiesOf(review.abilities)
    RowCard {
        Text(stringResource(R.string.moderation_review_subject, review.subject.name, review.subject.typeLabel.ifBlank { review.subject.type }),
            style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        review.author?.let { Text(stringResource(R.string.moderation_review_author, it), style = MaterialTheme.typography.labelLarge, color = Muted) }
        review.stars?.let { Stars(it) } ?: Text(stringResource(R.string.moderation_review_no_rating), style = MaterialTheme.typography.bodySmall, color = Muted)
        review.body?.takeIf { it.isNotBlank() }?.let { Text(it) }
        feedbackDate(review.createdAt, zone)?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Muted) }
        Text(stringResource(R.string.moderation_review_status, review.statusLabel.ifBlank { review.status }), style = MaterialTheme.typography.bodySmall,
            color = when (review.status) {
                ReviewStatusValue.PENDING -> MaterialTheme.colorScheme.error
                else -> MaterialTheme.colorScheme.primary
            })
        review.moderatedBy?.let { name ->
            feedbackDate(review.moderatedAt, zone)?.let { Text(stringResource(R.string.moderation_review_moderated, name, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
        }
        review.moderationNote?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.moderation_review_moderation_note, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
        review.reply?.let { reply ->
            Column(Modifier.fillMaxWidth().padding(start = 12.dp, top = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.moderation_review_reply), style = MaterialTheme.typography.titleSmall)
                Text(reply.body)
                feedbackDate(reply.repliedAt, zone)?.let { Text(stringResource(R.string.moderation_review_replied_at, it), style = MaterialTheme.typography.labelSmall, color = Muted) }
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (can["approve"] == true) OutlinedButton(onClick = onApprove, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.moderation_review_approve)) }
            if (can["reject"] == true) OutlinedButton(onClick = onReject, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_review_reject), color = MaterialTheme.colorScheme.error)
            }
            if (can["remove_reply"] == true && review.reply != null) TextButton(onClick = onRemoveReply, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_review_remove_reply), color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

/* Le stelle disegnate, come la colonna del pannello; il lettore di schermo sente «4 stelle su 5». */
@Composable
private fun Stars(rating: Int) {
    val label = stringResource(R.string.moderation_review_rating, rating)
    Row(Modifier.clearAndSetSemantics { contentDescription = label }, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        (1..5).forEach { star ->
            Icon(if (star <= rating) Icons.Filled.Star else Icons.Outlined.StarOutline, contentDescription = null,
                tint = if (star <= rating) MaterialTheme.colorScheme.primary else Muted, modifier = Modifier.size(18.dp))
        }
    }
}

@Composable
internal fun ReviewModerateDialog(review: EditorialReview, status: String, busy: Boolean, errors: FormErrors, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    var note by rememberSaveable(review.id, status) { mutableStateOf("") }
    val rejecting = status == ReviewStatusValue.REJECTED
    val error = errors.field(ReviewsViewModel.FIELD_NOTE)
    val help = stringResource(if (rejecting) R.string.moderation_review_note_required else R.string.moderation_review_note_optional, ModerationLimits.REVIEW_NOTE_MIN, ModerationLimits.REVIEW_NOTE_MAX)
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(if (rejecting) R.string.moderation_review_reject_title else R.string.moderation_review_approve_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                review.body?.takeIf { it.isNotBlank() }?.let {
                    Text(stringResource(R.string.moderation_review_text), style = MaterialTheme.typography.labelLarge)
                    Text(it, style = MaterialTheme.typography.bodyMedium, maxLines = 8)
                }
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it.take(ModerationLimits.REVIEW_NOTE_MAX + 50) },
                    label = { Text(stringResource(R.string.moderation_review_note)) },
                    enabled = !busy,
                    minLines = 3,
                    isError = error != null,
                    supportingText = { Text(error ?: (help + "  " + stringResource(R.string.moderation_comment_hide_counter, note.trim().length, ModerationLimits.REVIEW_NOTE_MAX))) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && reviewNoteAccepted(status, note), onClick = { onConfirm(note) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (rejecting) R.string.moderation_review_reject else R.string.moderation_review_approve),
                    color = if (rejecting) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

// ----------------------------------------------------------------- commenti

@Composable
internal fun CommentsScreen(viewModel: CommentsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    CommentsList(list, state, padding, zone, onBack, viewModel::search, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry,
        onDetails = viewModel::showDetails, onHide = viewModel::askHide, onRestore = viewModel::askRestore, onDelete = viewModel::askDelete, options = options)
    when (val dialog = state.dialog) {
        is CommentDialog.Details -> CommentDetailsDialog(dialog, zone, onDismiss = viewModel::dismiss)
        is CommentDialog.Hide -> CommentHideDialog(dialog.comment, state.busy, state.errors, onHide = viewModel::hide, onDismiss = viewModel::dismiss)
        is CommentDialog.Restore -> ConfirmDialog(
            title = stringResource(R.string.moderation_comment_restore_title),
            text = stringResource(R.string.moderation_comment_restore_text) + (state.errors.general?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.moderation_comment_restore),
            onConfirm = viewModel::restore,
            onDismiss = viewModel::dismiss,
            enabled = !state.busy,
        )
        is CommentDialog.Delete -> ConfirmDialog(
            title = stringResource(R.string.moderation_comment_delete_title),
            text = stringResource(R.string.moderation_comment_delete_text) + (state.errors.general?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.moderation_comment_delete),
            onConfirm = viewModel::delete,
            onDismiss = viewModel::dismiss,
            destructive = true,
            enabled = !state.busy,
        )
        null -> Unit
    }
}

@Composable
internal fun CommentsList(
    list: PagedState<EditorialComment>,
    state: CommentsUiState,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onDetails: (EditorialComment) -> Unit,
    onHide: (EditorialComment) -> Unit,
    onRestore: (EditorialComment) -> Unit,
    onDelete: (EditorialComment) -> Unit,
    options: ManagementOptions? = null,
) {
    val filters = listOf(
        FilterDefinition(CommentsViewModel.FILTER_STATUS, stringResource(R.string.moderation_filter_status), choicesOf(options, "comment_statuses", emptyList()).ifEmpty {
            listOf(OptionChoice("published", stringResource(R.string.moderation_comment_status_published)), OptionChoice("hidden", stringResource(R.string.moderation_comment_status_hidden)))
        }),
    )
    val sorts = listOf(SortChoice(null, stringResource(R.string.moderation_sort_newest)), SortChoice(CommentsViewModel.SORT_MOST_LIKED, stringResource(R.string.moderation_sort_most_liked)))
    PagedListScreen(
        title = stringResource(R.string.moderation_comments_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.moderation_comments_search),
        emptyText = stringResource(R.string.moderation_comments_empty),
        header = {
            SortChips(sorts, list.query.sort, onSort)
            Message(state.message)
        },
    ) { comment -> CommentRow(comment, zone, enabled = !state.busy, onDetails = { onDetails(comment) }, onHide = { onHide(comment) }, onRestore = { onRestore(comment) }, onDelete = { onDelete(comment) }) }
}

@Composable
private fun CommentRow(comment: EditorialComment, zone: ZoneId, enabled: Boolean, onDetails: () -> Unit, onHide: () -> Unit, onRestore: () -> Unit, onDelete: () -> Unit) {
    val can = abilitiesOf(comment.abilities)
    RowCard {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            comment.event?.poster?.let { poster -> (poster.thumb ?: poster.card ?: poster.full) }?.let { url ->
                AsyncImage(model = url, contentDescription = stringResource(R.string.moderation_comment_poster, comment.event?.title.orEmpty()), contentScale = ContentScale.Crop,
                    modifier = Modifier.width(48.dp).height(64.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                comment.event?.title?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() }) }
                val who = listOfNotNull(comment.author, if (comment.isReply) stringResource(R.string.moderation_comment_reply) else null, feedbackDate(comment.createdAt, zone)).joinToString(" · ")
                if (who.isNotBlank()) Text(who, style = MaterialTheme.typography.labelLarge, color = Muted)
            }
        }
        Text(comment.body)
        Text(stringResource(R.string.moderation_comment_status, comment.statusLabel.ifBlank { comment.status }), style = MaterialTheme.typography.bodySmall,
            color = if (comment.hidden) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
        Text(stringResource(R.string.moderation_comment_likes, comment.reactionsCount), style = MaterialTheme.typography.bodySmall, color = Muted)
        comment.moderatedBy?.let { Text(stringResource(R.string.moderation_comment_moderated, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (can["show"] == true) TextButton(onClick = onDetails, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.moderation_comment_details)) }
            if (can["hide"] == true) OutlinedButton(onClick = onHide, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_comment_hide), color = MaterialTheme.colorScheme.error)
            }
            if (can["restore"] == true) OutlinedButton(onClick = onRestore, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.moderation_comment_restore)) }
            if (can["destroy"] == true) TextButton(onClick = onDelete, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_comment_delete), color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

@Composable
private fun CommentDetailsDialog(dialog: CommentDialog.Details, zone: ZoneId, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.moderation_comment_details_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                LoadState(dialog.loaded, onRetry = {}) { comment ->
                    val none = stringResource(R.string.moderation_comment_none)
                    DetailLine(stringResource(R.string.moderation_comment_event), comment.event?.title ?: none)
                    DetailLine(stringResource(R.string.moderation_comment_author), comment.author ?: none)
                    DetailLine(stringResource(R.string.moderation_comment_body), comment.body)
                    DetailLine(stringResource(R.string.moderation_comment_note), comment.moderationNote?.takeIf { it.isNotBlank() } ?: none)
                    DetailLine(stringResource(R.string.moderation_comment_moderator), comment.moderatedBy ?: none)
                    DetailLine(stringResource(R.string.moderation_comment_date), feedbackDate(comment.moderatedAt, zone) ?: none)
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.moderation_comment_close)) } },
    )
}

@Composable
private fun DetailLine(label: String, value: String) {
    Column {
        Text(label, style = MaterialTheme.typography.labelLarge, color = Muted)
        Text(value)
    }
}

@Composable
internal fun CommentHideDialog(comment: EditorialComment, busy: Boolean, errors: FormErrors, onHide: (String) -> Unit, onDismiss: () -> Unit) {
    var note by rememberSaveable(comment.id) { mutableStateOf("") }
    val error = errors.field(CommentsViewModel.FIELD_NOTE)
    val tooLong = note.trim().length > ModerationLimits.COMMENT_NOTE_MAX
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.moderation_comment_hide_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(stringResource(R.string.moderation_comment_hide_text))
                Text(stringResource(R.string.moderation_comment_body), style = MaterialTheme.typography.labelLarge)
                Text(comment.body, style = MaterialTheme.typography.bodyMedium, maxLines = 8)
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it.take(ModerationLimits.COMMENT_NOTE_MAX + 50) },
                    label = { Text(stringResource(R.string.moderation_comment_hide_note)) },
                    enabled = !busy,
                    minLines = 3,
                    isError = error != null || tooLong,
                    supportingText = { Text(error ?: stringResource(R.string.moderation_comment_hide_counter, note.trim().length, ModerationLimits.COMMENT_NOTE_MAX)) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && !tooLong, onClick = { onHide(note) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.moderation_comment_hide), color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}
