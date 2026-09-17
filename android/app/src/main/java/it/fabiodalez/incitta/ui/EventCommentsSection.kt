package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.EventComment
import it.fabiodalez.incitta.data.EventCommentPage
import it.fabiodalez.incitta.data.requestFailureMessage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
fun EventCommentsSection(
    slug: String,
    userId: Long?,
    onLogin: () -> Unit,
    load: suspend (Int, Long?, Int) -> EventCommentPage,
    submit: suspend (String, Long?) -> Long,
    react: suspend (Long, String) -> Unit,
    delete: suspend (Long) -> Unit,
    resend: suspend () -> Unit,
) = key(slug, userId) {
    var data by remember { mutableStateOf<EventCommentPage?>(null) }
    var body by rememberSaveable { mutableStateOf("") }
    var reply by remember { mutableStateOf<EventComment?>(null) }
    var deleting by remember { mutableStateOf<EventComment?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val sent = stringResource(R.string.comments_sent)
    val verificationSent = stringResource(R.string.comments_verification_sent)
    suspend fun refresh(page: Int = data?.page ?: 1, thread: Long? = data?.thread, replies: Int = data?.repliesPage ?: 1) {
        data = load(page, thread, replies)
    }
    fun run(action: suspend () -> Unit) {
        if (busy) return
        busy = true; error = null; message = null
        scope.launch {
            try { action() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }
    LaunchedEffect(slug, userId) { run { refresh() } }
    DetailSection(stringResource(R.string.comments_title)) {
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error); TextButton(enabled = !busy, onClick = { run { refresh() } }) { Text(stringResource(R.string.comments_reload)) } }
        message?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
        data?.let { page ->
            if (page.comments.isEmpty()) Text(stringResource(R.string.comments_empty))
            page.comments.forEach { comment ->
                CommentRow(comment, page.canComment, busy, onReply = { reply = it },
                    onReact = { id, type -> run { react(id, type); refresh() } }, onDelete = { deleting = it })
                comment.replies.forEach { child ->
                    Column(Modifier.padding(start = 20.dp)) {
                        CommentRow(child, page.canComment, busy, onReply = { reply = it },
                            onReact = { id, type -> run { react(id, type); refresh() } }, onDelete = { deleting = it })
                    }
                }
                if (page.thread == null && comment.repliesCount > comment.replies.size) {
                    TextButton(enabled = !busy, onClick = { run { refresh(1, comment.id, 1) } }) { Text(stringResource(R.string.comments_thread)) }
                }
            }
            if (page.thread != null) {
                TextButton(enabled = !busy, onClick = { run { refresh(1, null, 1) } }) { Text(stringResource(R.string.comments_all)) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                val current = if (page.thread == null) page.page else page.repliesPage
                val last = if (page.thread == null) page.lastPage else page.repliesLastPage
                if (current > 1) TextButton(enabled = !busy, onClick = { run { if (page.thread == null) refresh(current - 1) else refresh(replies = current - 1) } }) { Text(stringResource(R.string.comments_previous)) }
                if (current < last) TextButton(enabled = !busy, onClick = { run { if (page.thread == null) refresh(current + 1) else refresh(replies = current + 1) } }) { Text(stringResource(R.string.comments_next)) }
            }
            when {
                userId == null -> Button(onClick = onLogin, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.comments_login)) }
                !page.canComment -> {
                    Text(stringResource(R.string.comments_verify))
                    TextButton(enabled = !busy, onClick = { run { resend(); message = verificationSent } }) { Text(stringResource(R.string.comments_resend)) }
                    TextButton(enabled = !busy, onClick = { run { refresh() } }) { Text(stringResource(R.string.comments_verified)) }
                }
                else -> {
                    reply?.let {
                        Text(stringResource(R.string.comments_reply_to, it.author), style = MaterialTheme.typography.labelLarge)
                        TextButton(onClick = { reply = null }, enabled = !busy) { Text(stringResource(R.string.comments_cancel_reply)) }
                    }
                    OutlinedTextField(value = body, onValueChange = { if (it.length <= 2000) body = it }, enabled = !busy,
                        label = { Text(stringResource(R.string.comments_write)) }, minLines = 3, modifier = Modifier.fillMaxWidth(),
                        supportingText = { Text(stringResource(R.string.comments_length, body.length)) })
                    Button(enabled = !busy && body.trim().length in 3..2000, modifier = Modifier.heightIn(min = 48.dp), onClick = {
                        val parent = reply?.id
                        run {
                            val created = submit(body, parent)
                            body = ""; reply = null
                            refresh(1, created, 1)
                            message = sent
                        }
                    }) { Text(stringResource(R.string.comments_publish)) }
                }
            }
        }
    }
    deleting?.let { comment ->
        AlertDialog(onDismissRequest = { if (!busy) deleting = null },
            title = { Text(stringResource(R.string.comments_delete)) },
            text = { Text(stringResource(R.string.comments_delete_confirm)) },
            confirmButton = { TextButton(enabled = !busy, onClick = { run { delete(comment.id); deleting = null; refresh(1, null, 1) } }) { Text(stringResource(R.string.comments_delete)) } },
            dismissButton = { TextButton(enabled = !busy, onClick = { deleting = null }) { Text(stringResource(R.string.comments_cancel)) } })
    }
}

@Composable
private fun CommentRow(comment: EventComment, canComment: Boolean, busy: Boolean, onReply: (EventComment) -> Unit, onReact: (Long, String) -> Unit, onDelete: (EventComment) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(comment.author, style = MaterialTheme.typography.titleMedium)
        Text(if (comment.hidden) stringResource(R.string.comments_hidden) else comment.body.orEmpty())
        if (!comment.hidden) {
            Text(stringResource(R.string.comments_reaction_count, comment.reactionsCount), style = MaterialTheme.typography.labelSmall)
            if (canComment) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("like" to R.string.comments_like, "love" to R.string.comments_love, "useful" to R.string.comments_useful).forEach { (type, label) ->
                        FilterChip(selected = comment.myReaction == type, enabled = !busy, onClick = { onReact(comment.id, type) }, label = { Text(stringResource(label)) }, modifier = Modifier.heightIn(min = 48.dp))
                    }
                }
            }
        }
        Row {
            if (!comment.hidden && canComment) TextButton(enabled = !busy, onClick = { onReply(comment) }) { Text(stringResource(R.string.comments_reply)) }
            if (comment.canDelete) TextButton(enabled = !busy, onClick = { onDelete(comment) }) { Text(stringResource(R.string.comments_delete)) }
        }
        HorizontalDivider()
    }
}
