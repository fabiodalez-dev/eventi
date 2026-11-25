package it.fabiodalez.incitta.ui.management.feedback

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.StarOutline
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.COMMENT_NOTE_MAX
import it.fabiodalez.incitta.data.management.CommentStatus
import it.fabiodalez.incitta.data.management.ManagedComment
import it.fabiodalez.incitta.data.management.ManagedReview
import it.fabiodalez.incitta.data.management.REVIEW_REPLY_MAX
import it.fabiodalez.incitta.data.management.REVIEW_REPLY_MIN
import it.fabiodalez.incitta.data.management.REVIEW_REPORT_MAX
import it.fabiodalez.incitta.data.management.REVIEW_REPORT_MIN
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/* Le date dei commenti e delle recensioni, nel fuso della città del soggetto. */
private val feedbackDateFormat = DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)

internal fun feedbackDate(iso: String?, zone: ZoneId): String? = cityLocalDateTime(iso, zone)?.format(feedbackDateFormat)

// ------------------------------------------------------------------- menu

@Composable
internal fun FeedbackHubScreen(viewModel: FeedbackHubViewModel, context: ManagementGraphContext, onComments: (String) -> Unit, onReviews: (String) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = (state.subject as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.management_area_feedback),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
    ) {
        LoadState(state.subject, onRetry = viewModel::load, emptyText = stringResource(R.string.feedback_subject_missing)) { current ->
            val can = current.permissions
            Column {
                if (can.commentsModerate) ManagementRow(stringResource(R.string.feedback_comments_title), stringResource(R.string.feedback_comments_help), onClick = { onComments(current.key) })
                if (can.reviewsReply) ManagementRow(stringResource(R.string.feedback_reviews_title), stringResource(R.string.feedback_reviews_help), onClick = { onReviews(current.key) })
            }
            if (!can.commentsModerate && !can.reviewsReply) Text(stringResource(R.string.feedback_nothing_allowed), color = Muted)
        }
    }
}

// ---------------------------------------------------------------- commenti

@Composable
internal fun CommentsModerationScreen(viewModel: CommentsModerationViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = (state.subject as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.feedback_comments_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.busy && state.loaded,
    ) {
        LoadState(state.subject, onRetry = viewModel::start, emptyText = stringResource(R.string.feedback_subject_missing)) { current ->
            state.eventId?.let {
                Text(state.eventTitle?.let { title -> stringResource(R.string.feedback_event_filter, title) } ?: stringResource(R.string.feedback_event_filter_unknown),
                    style = MaterialTheme.typography.titleSmall)
                OutlinedButton(onClick = viewModel::showAllEvents, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.feedback_event_filter_clear))
                }
            }
            StatusFilter(state.status, enabled = !state.busy, onSelect = viewModel::showStatus)
            SearchField(state.query, enabled = !state.busy, onSearch = viewModel::search)
            state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
            when {
                state.error != null && state.comments.isEmpty() -> LoadState<Unit>(Loadable.Failed(state.error!!), onRetry = viewModel::refresh) {}
                !state.loaded -> LoadState<Unit>(Loadable.Loading, onRetry = {}) {}
                state.comments.isEmpty() -> Text(stringResource(R.string.feedback_comments_empty), color = Muted)
                else -> {
                    Text(pluralStringResource(R.plurals.feedback_comments_count, state.total, state.total), style = MaterialTheme.typography.titleMedium)
                    state.comments.forEach { comment ->
                        CommentCard(comment, current.timezone, enabled = !state.dialogBusy, onHide = { viewModel.askHide(comment) }, onRestore = { viewModel.askRestore(comment) })
                    }
                    state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    if (state.nextPage != null) OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.feedback_load_more))
                    }
                }
            }
        }
    }
    state.hiding?.let { comment -> HideDialog(comment, state.dialogBusy, state.dialogErrors, onHide = viewModel::hide, onDismiss = viewModel::dismissDialog) }
    state.restoring?.let {
        val general = state.dialogErrors.general
        ConfirmDialog(
            title = stringResource(R.string.feedback_restore_title),
            text = stringResource(R.string.feedback_restore_text) + (general?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.feedback_restore),
            onConfirm = viewModel::restore,
            onDismiss = viewModel::dismissDialog,
            enabled = !state.dialogBusy,
        )
    }
}

@Composable
private fun StatusFilter(selected: String?, enabled: Boolean, onSelect: (String?) -> Unit) {
    val label = stringResource(R.string.feedback_filter_label)
    FlowRow(Modifier.semantics { contentDescription = label }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf(null to R.string.feedback_filter_all, CommentStatus.PUBLISHED to R.string.feedback_filter_published, CommentStatus.HIDDEN to R.string.feedback_filter_hidden)
            .forEach { (value, text) ->
                FilterChip(selected = selected == value, enabled = enabled, onClick = { onSelect(value) }, label = { Text(stringResource(text)) }, modifier = Modifier.heightIn(min = 48.dp))
            }
    }
}

@Composable
private fun SearchField(committed: String, enabled: Boolean, onSearch: (String) -> Unit) {
    var text by rememberSaveable(committed) { mutableStateOf(committed) }
    OutlinedTextField(
        value = text,
        onValueChange = { if (it.length <= CommentsModerationViewModel.QUERY_MAX) text = it },
        label = { Text(stringResource(R.string.feedback_search)) },
        singleLine = true,
        enabled = enabled,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        keyboardActions = KeyboardActions(onSearch = { onSearch(text) }),
        trailingIcon = {
            Row {
                if (committed.isNotEmpty()) IconButton(onClick = { text = ""; onSearch("") }, enabled = enabled, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.feedback_search_clear))
                }
                IconButton(onClick = { onSearch(text) }, enabled = enabled, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.Search, contentDescription = stringResource(R.string.feedback_search_action))
                }
            }
        },
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun CommentCard(comment: ManagedComment, zone: ZoneId, enabled: Boolean, onHide: () -> Unit, onRestore: () -> Unit) {
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        comment.event?.title?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.labelLarge, color = Muted) }
        Text(comment.author, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        feedbackDate(comment.createdAt, zone)?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = Muted) }
        if (comment.isReply) Text(stringResource(R.string.feedback_comment_reply), style = MaterialTheme.typography.labelSmall, color = Muted)
        Text(comment.body)
        Text(stringResource(R.string.feedback_comment_likes, comment.reactionsCount), style = MaterialTheme.typography.bodySmall)
        Text(
            stringResource(R.string.feedback_comment_status, comment.statusLabel.ifBlank { comment.status }),
            style = MaterialTheme.typography.bodySmall,
            color = if (comment.hidden) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary,
        )
        feedbackDate(comment.moderatedAt, zone)?.let { date ->
            Text(comment.moderatedBy?.let { stringResource(R.string.feedback_comment_moderated, it, date) } ?: stringResource(R.string.feedback_comment_moderated_unknown, date),
                style = MaterialTheme.typography.bodySmall, color = Muted)
        }
        if (comment.hidden && !comment.canRestore) Text(stringResource(R.string.feedback_comment_staff_hidden), style = MaterialTheme.typography.bodySmall, color = Muted)
        if (comment.canHide) OutlinedButton(enabled = enabled, onClick = onHide, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.feedback_hide), color = MaterialTheme.colorScheme.error)
        }
        if (comment.canRestore) OutlinedButton(enabled = enabled, onClick = onRestore, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.feedback_restore))
        }
    }
}

@Composable
private fun HideDialog(comment: ManagedComment, busy: Boolean, errors: FormErrors, onHide: (String) -> Unit, onDismiss: () -> Unit) {
    var note by rememberSaveable(comment.id) { mutableStateOf("") }
    val noteError = errors.field(CommentsModerationViewModel.FIELD_NOTE)
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.feedback_hide_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(stringResource(R.string.feedback_hide_text))
                Text(stringResource(R.string.feedback_comment_text), style = MaterialTheme.typography.labelLarge)
                Text(comment.body, style = MaterialTheme.typography.bodyMedium, maxLines = 8)
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it.take(COMMENT_NOTE_MAX) },
                    label = { Text(stringResource(R.string.feedback_hide_note)) },
                    enabled = !busy,
                    minLines = 3,
                    isError = noteError != null,
                    supportingText = { Text(noteError ?: stringResource(R.string.feedback_hide_note_counter, note.length)) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && note.trim().length <= COMMENT_NOTE_MAX, onClick = { onHide(note) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.feedback_hide), color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) } },
    )
}

// --------------------------------------------------------------- recensioni

@Composable
internal fun ReceivedReviewsScreen(viewModel: ReceivedReviewsViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = (state.subject as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.feedback_reviews_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.busy && state.loaded,
    ) {
        LoadState(state.subject, onRetry = viewModel::start, emptyText = stringResource(R.string.feedback_subject_missing)) { current ->
            Text(stringResource(R.string.feedback_reviews_intro), color = Muted)
            state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
            when {
                state.error != null && state.reviews.isEmpty() -> LoadState<Unit>(Loadable.Failed(state.error!!), onRetry = viewModel::refresh) {}
                !state.loaded -> LoadState<Unit>(Loadable.Loading, onRetry = {}) {}
                state.reviews.isEmpty() -> Text(stringResource(R.string.feedback_reviews_empty), color = Muted)
                else -> {
                    Text(pluralStringResource(R.plurals.feedback_reviews_count, state.total, state.total), style = MaterialTheme.typography.titleMedium)
                    state.reviews.forEach { review ->
                        ReviewCard(review, current.timezone, enabled = !state.dialogBusy,
                            onReply = { viewModel.askReply(review) }, onRemove = { viewModel.askRemove(review) }, onReport = { viewModel.askReport(review) })
                    }
                    state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    if (state.nextPage != null) OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.feedback_load_more))
                    }
                }
            }
        }
    }
    state.replying?.let { review -> ReplyDialog(review, state.dialogBusy, state.dialogErrors, onSave = viewModel::reply, onDismiss = viewModel::dismissDialog) }
    state.reporting?.let { review -> ReportDialog(review, state.dialogBusy, state.dialogErrors, onSend = viewModel::report, onDismiss = viewModel::dismissDialog) }
    state.removing?.let {
        val general = state.dialogErrors.general
        ConfirmDialog(
            title = stringResource(R.string.feedback_reply_remove_title),
            text = stringResource(R.string.feedback_reply_remove_text) + (general?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.feedback_reply_remove),
            onConfirm = viewModel::removeReply,
            onDismiss = viewModel::dismissDialog,
            destructive = true,
            enabled = !state.dialogBusy,
        )
    }
}

@Composable
private fun ReviewCard(review: ManagedReview, zone: ZoneId, enabled: Boolean, onReply: () -> Unit, onRemove: () -> Unit, onReport: () -> Unit) {
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(review.author, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        val stars = review.stars
        if (stars != null) Stars(stars) else Text(stringResource(R.string.feedback_no_rating), style = MaterialTheme.typography.bodySmall, color = Muted)
        review.body?.takeIf { it.isNotBlank() }?.let { Text(it) }
        feedbackDate(review.publishedAt ?: review.createdAt, zone)?.let { Text(stringResource(R.string.feedback_published_at, it), style = MaterialTheme.typography.labelSmall, color = Muted) }
        review.givenReply?.let { reply ->
            Column(Modifier.fillMaxWidth().padding(start = 12.dp, top = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.feedback_your_reply), style = MaterialTheme.typography.titleSmall)
                Text(reply.body)
                feedbackDate(reply.repliedAt, zone)?.let { Text(stringResource(R.string.feedback_replied_at, it), style = MaterialTheme.typography.labelSmall, color = Muted) }
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (review.canReply) OutlinedButton(enabled = enabled, onClick = onReply, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (review.givenReply == null) R.string.feedback_reply_write else R.string.feedback_reply_edit))
            }
            if (review.canRemoveReply && review.givenReply != null) OutlinedButton(enabled = enabled, onClick = onRemove, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.feedback_reply_remove), color = MaterialTheme.colorScheme.error)
            }
            if (review.canReport) TextButton(enabled = enabled, onClick = onReport, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.feedback_report))
            }
        }
    }
}

/* Le stelle disegnate, come nella scheda pubblica; il lettore di schermo sente «4 stelle su 5». */
@Composable
private fun Stars(rating: Int) {
    val label = stringResource(R.string.feedback_rating, rating)
    Row(Modifier.clearAndSetSemantics { contentDescription = label }, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        (1..5).forEach { star ->
            Icon(if (star <= rating) Icons.Filled.Star else Icons.Outlined.StarOutline, contentDescription = null,
                tint = if (star <= rating) MaterialTheme.colorScheme.primary else Muted, modifier = Modifier.size(18.dp))
        }
    }
}

@Composable
private fun ReplyDialog(review: ManagedReview, busy: Boolean, errors: FormErrors, onSave: (String) -> Unit, onDismiss: () -> Unit) {
    var text by rememberSaveable(review.id) { mutableStateOf(review.givenReply?.body.orEmpty()) }
    val error = errors.field(ReceivedReviewsViewModel.FIELD_REPLY)
    val length = text.trim().length
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(if (review.givenReply == null) R.string.feedback_reply_write else R.string.feedback_reply_edit)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                review.body?.takeIf { it.isNotBlank() }?.let {
                    Text(stringResource(R.string.feedback_review_text), style = MaterialTheme.typography.labelLarge)
                    Text(it, style = MaterialTheme.typography.bodyMedium, maxLines = 8)
                }
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = text,
                    onValueChange = { text = it.take(REVIEW_REPLY_MAX) },
                    label = { Text(stringResource(R.string.feedback_reply_field)) },
                    enabled = !busy,
                    minLines = 4,
                    isError = error != null,
                    supportingText = { Text(error ?: (stringResource(R.string.feedback_reply_hint) + "\n" + stringResource(R.string.feedback_reply_counter, length))) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && length in REVIEW_REPLY_MIN..REVIEW_REPLY_MAX, onClick = { onSave(text) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.feedback_reply_publish))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) } },
    )
}

@Composable
private fun ReportDialog(review: ManagedReview, busy: Boolean, errors: FormErrors, onSend: (String) -> Unit, onDismiss: () -> Unit) {
    var body by rememberSaveable(review.id) { mutableStateOf("") }
    val error = errors.field(ReceivedReviewsViewModel.FIELD_BODY)
    val length = body.trim().length
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.feedback_report_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(stringResource(R.string.feedback_report_text))
                errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = body,
                    onValueChange = { body = it.take(REVIEW_REPORT_MAX) },
                    label = { Text(stringResource(R.string.feedback_report_field)) },
                    enabled = !busy,
                    minLines = 3,
                    isError = error != null,
                    supportingText = { Text(error ?: stringResource(R.string.feedback_report_counter, length)) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && length in REVIEW_REPORT_MIN..REVIEW_REPORT_MAX, onClick = { onSend(body) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.feedback_report_send))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) } },
    )
}
