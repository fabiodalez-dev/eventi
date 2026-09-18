package it.fabiodalez.incitta.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.VenueReviewPage
import it.fabiodalez.incitta.data.requestFailureMessage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
internal fun VenueReviewsSection(
    venue: String,
    userId: Long?,
    onLogin: () -> Unit,
    load: suspend (Int) -> VenueReviewPage,
    submit: suspend (Int?, String, Int) -> Unit,
    delete: suspend () -> Unit,
    onVerify: () -> Unit = {},
    report: suspend (Long, String) -> Unit = { _, _ -> },
    title: String = stringResource(R.string.reviews_venue_title),
) {
    var data by remember(venue, userId) { mutableStateOf<VenueReviewPage?>(null) }
    var rating by remember(venue, userId) { mutableIntStateOf(0) }
    var body by remember(venue, userId) { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var reporting by remember(venue, userId) { mutableStateOf<Long?>(null) }
    var reportBody by remember(reporting) { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val context = androidx.compose.ui.platform.LocalContext.current
    val currentIdentity by rememberUpdatedState(venue to userId)
    val identity = venue to userId
    suspend fun refresh(page: Int, fill: Boolean = false) {
        val result = load(page)
        if (currentIdentity != identity) return
        data = result
        if (fill) { rating = result.myReview?.rating ?: 0; body = result.myReview?.body.orEmpty() }
    }
    fun run(action: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true; error = null
            try { action() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { if(currentIdentity == identity) error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }
    LaunchedEffect(venue, userId) {
        busy = true; error = null; message = null
        try { refresh(1, true) }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        data?.let { reviews ->
            Text(stringResource(if(reviews.count == 0) R.string.reviews_empty else R.string.reviews_count, reviews.count))
            reviews.average?.let { Text(stringResource(R.string.reviews_average, it.toString())) }
            reviews.reviews.forEach { review ->
                Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(review.author, style = MaterialTheme.typography.titleMedium)
                    review.rating?.let { Text("${"★".repeat(it.coerceIn(0, 5))}${"☆".repeat(5 - it.coerceIn(0, 5))}", modifier = Modifier.semanticsRating(it)) }
                    review.body?.let { Text(it) }
                    review.date?.let { Text(it, style = MaterialTheme.typography.labelSmall) }
                    if(reviews.verified) TextButton(onClick = { reporting = review.id }) { Text(stringResource(R.string.reviews_report)) }
                }
            }
            if (reviews.lastPage > 1) Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                if (reviews.page > 1) TextButton(enabled = !busy, onClick = { run { refresh(reviews.page - 1) } }) { Text(stringResource(R.string.reviews_previous)) }
                if (reviews.page < reviews.lastPage) TextButton(enabled = !busy, onClick = { run { refresh(reviews.page + 1) } }) { Text(stringResource(R.string.reviews_next)) }
            }
            if (userId != null) {
                Text(stringResource(R.string.reviews_write), style = MaterialTheme.typography.titleMedium)
                reviews.myReview?.let { own ->
                    Text(stringResource(when (own.status) { "approved" -> R.string.reviews_approved; "rejected" -> R.string.reviews_rejected; else -> R.string.reviews_pending }))
                    own.moderationNote?.let { Text(it) }
                }
                if (reviews.canReview) {
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        (1..5).forEach { star ->
                            Box(Modifier.weight(1f).heightIn(min=48.dp).selectable(selected = rating == star, enabled = !busy, role = Role.RadioButton, onClick = { rating = star }), contentAlignment = androidx.compose.ui.Alignment.Center) {
                                Text(if (star <= rating) "★" else "☆", style = MaterialTheme.typography.headlineMedium, modifier = Modifier.semanticsRating(star))
                            }
                        }
                    }
                    TextButton(onClick = { rating = 0 }, enabled = !busy) { Text(stringResource(R.string.reviews_no_rating)) }
                    OutlinedTextField(value = body, onValueChange = { if (it.length <= 3000) body = it }, enabled = !busy,
                        label = { Text(stringResource(R.string.reviews_body)) }, minLines = 4, modifier = Modifier.fillMaxWidth(), supportingText = { Text("${body.length}/3000") })
                    Text(stringResource(R.string.reviews_guidance), style = MaterialTheme.typography.bodySmall)
                    Button(enabled = !busy && (rating in 1..5 || body.trim().length >= 10) && (body.isBlank() || body.trim().length in 10..3000), onClick = { run { submit(rating.takeIf { it > 0 }, body, reviews.myReview?.revision ?: 0); refresh(1, true); message = context.getString(R.string.reviews_submitted) } }) { Text(stringResource(R.string.reviews_submit)) }
                } else if (!reviews.verified) {
                    Text(stringResource(R.string.reviews_verify_hint))
                    Button(onClick = onVerify) { Text(stringResource(R.string.reviews_verify)) }
                } else Text(stringResource(R.string.reviews_closed))
                if (reviews.myReview != null) TextButton(enabled = !busy, onClick = { run { delete(); refresh(1, true); message = context.getString(R.string.reviews_deleted) } }) { Text(stringResource(R.string.reviews_delete)) }
            } else Button(onClick = onLogin) { Text(stringResource(R.string.reviews_login)) }
        }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error); TextButton(enabled = !busy, onClick = { run { refresh(data?.page ?: 1) } }) { Text(stringResource(R.string.reviews_retry)) } }
        message?.let { Text(it) }
    }
    reporting?.let { id -> AlertDialog(onDismissRequest = { if(!busy) reporting = null }, title = { Text(stringResource(R.string.reviews_report)) },
        text = { Column { OutlinedTextField(reportBody, { if(it.length <= 3000) reportBody = it }, label = { Text(stringResource(R.string.reviews_report_reason)) }); error?.let { Text(it, color=MaterialTheme.colorScheme.error) } } },
        confirmButton = { TextButton(enabled = !busy && reportBody.trim().length >= 10, onClick = { run { report(id, reportBody.trim()); reporting = null; message = context.getString(R.string.reviews_reported) } }) { Text(stringResource(R.string.reviews_send_report)) } },
        dismissButton = { TextButton(enabled = !busy, onClick = { reporting = null }) { Text(stringResource(R.string.reviews_cancel)) } }) }
}

@Composable
private fun Modifier.semanticsRating(star: Int): Modifier {
    val label = stringResource(R.string.reviews_average, star.toString())
    return this.then(Modifier.semantics { contentDescription = label })
}
