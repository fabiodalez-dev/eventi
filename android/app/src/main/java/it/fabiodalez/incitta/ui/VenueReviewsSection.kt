package it.fabiodalez.incitta.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.VenueReviewPage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
internal fun VenueReviewsSection(
    venue: String,
    userId: Long?,
    onLogin: () -> Unit,
    load: suspend (Int) -> VenueReviewPage,
    submit: suspend (Int, String) -> Unit,
    delete: suspend () -> Unit,
) {
    var data by remember(venue, userId) { mutableStateOf<VenueReviewPage?>(null) }
    var rating by rememberSaveable(venue, userId) { mutableIntStateOf(0) }
    var body by rememberSaveable(venue, userId) { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    suspend fun refresh(page: Int, fill: Boolean = false) {
        val result = load(page)
        data = result
        if (fill) { rating = result.myReview?.rating ?: 0; body = result.myReview?.body.orEmpty() }
    }
    fun run(action: suspend () -> Unit) {
        scope.launch {
            busy = true; error = null
            try { action() }
            catch (e: CancellationException) { throw e }
            catch (_: Exception) { error = "Operazione non riuscita. Controlla la connessione e riprova." }
            finally { busy = false }
        }
    }
    LaunchedEffect(venue, userId) {
        busy = true
        try { refresh(1, fill = body.isEmpty()) }
        catch (e: CancellationException) { throw e }
        catch (_: Exception) { error = "Non è stato possibile caricare le recensioni." }
        finally { busy = false }
    }
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text("RECENSIONI DEL LOCALE", style = MaterialTheme.typography.titleLarge)
        data?.let { reviews ->
            Text(reviews.average?.let { "★ $it / 5 · ${reviews.count} recensioni approvate" }
                ?: "Non ci sono ancora recensioni approvate.")
            reviews.reviews.forEach { review ->
                Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(review.author, style = MaterialTheme.typography.titleMedium)
                    Text("${"★".repeat(review.rating.coerceIn(0, 5))}${"☆".repeat(5 - review.rating.coerceIn(0, 5))} · ${review.rating} su 5")
                    Text(review.body)
                    review.date?.let { Text(it, style = MaterialTheme.typography.labelSmall) }
                }
            }
            if (reviews.lastPage > 1) Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                if (reviews.page > 1) TextButton(enabled = !busy, onClick = { run { refresh(reviews.page - 1) } }) { Text("Precedenti") }
                if (reviews.page < reviews.lastPage) TextButton(enabled = !busy, onClick = { run { refresh(reviews.page + 1) } }) { Text("Altre recensioni") }
            }
            if (userId != null) {
                Text("La tua recensione", style = MaterialTheme.typography.titleMedium)
                reviews.myReview?.let { own ->
                    Text(when (own.status) { "approved" -> "Approvata"; "rejected" -> "Non approvata"; else -> "In attesa di approvazione" })
                    own.moderationNote?.let { Text(it) }
                }
                if (reviews.canReview) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    (1..5).forEach { star ->
                        Box(Modifier.size(52.dp).selectable(selected = rating == star, enabled = !busy, role = Role.RadioButton, onClick = { rating = star }), contentAlignment = androidx.compose.ui.Alignment.Center) {
                            Text(if (star <= rating) "★" else "☆", style = MaterialTheme.typography.headlineMedium,
                                modifier = Modifier.semanticsRating(star))
                        }
                    }
                }
                OutlinedTextField(value = body, onValueChange = { if (it.length <= 3000) body = it }, enabled = !busy,
                    label = { Text("Racconta la tua esperienza") }, minLines = 4, modifier = Modifier.fillMaxWidth(), supportingText = { Text("${body.length}/3000 · minimo 10 caratteri") })
                Text("La recensione sarà pubblicata con il tuo nome dopo l’approvazione degli amministratori della piattaforma. Ogni modifica richiede una nuova approvazione.", style = MaterialTheme.typography.bodySmall)
                Button(enabled = !busy && rating in 1..5 && body.trim().length in 10..3000, onClick = { run { submit(rating, body); refresh(1, true); message = "Recensione inviata per approvazione." } }) { Text("Invia per approvazione") }
                } else Text("Questo locale non accetta nuove recensioni al momento.")
                if (reviews.myReview != null) TextButton(enabled = !busy, onClick = { run { delete(); refresh(1, true); message = "Recensione eliminata." } }) { Text("Elimina la mia recensione") }
            } else Button(onClick = onLogin) { Text("Accedi per lasciare una recensione") }
        }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error); TextButton(enabled = !busy, onClick = { run { refresh(data?.page ?: 1) } }) { Text("Riprova") } }
        message?.let { Text(it) }
    }
}

private fun Modifier.semanticsRating(star: Int): Modifier = this.then(Modifier.semantics { contentDescription = "$star su 5" })
