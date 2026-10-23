package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.SavedSearch
import it.fabiodalez.incitta.data.SavedSearchesApi
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.requestFailureMessage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/**
 * «Le mie ricerche salvate», nelle preferenze di notifica: le ricerche per cui
 * arriva un avviso quando escono eventi nuovi, con «Apri» (riapplica i filtri
 * nella pagina Eventi) e «Rimuovi».
 */
@Composable
internal fun SavedSearchesSection(session: Session, onOpen: (SavedSearch) -> Unit) {
    val context = LocalContext.current
    val api = remember(session.token) { SavedSearchesApi(ApiClient(LocalStore(context).installationId), session.token) }
    val scope = rememberCoroutineScope()
    var searches by remember(session.token) { mutableStateOf<List<SavedSearch>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var removing by remember { mutableStateOf<SavedSearch?>(null) }
    val removed = stringResource(R.string.saved_search_removed)
    LaunchedEffect(session.token, reload) {
        busy = true; error = null
        try { searches = api.list() }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }
    Column(Modifier.fillMaxWidth().padding(top = 8.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        HorizontalDivider(color = Rule)
        Text(stringResource(R.string.saved_search_title), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.saved_search_intro), color = Muted)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error)
            OutlinedButton(onClick = { reload++ }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_retry)) }
        }
        message?.let { Text(it, color = Acid) }
        if (searches?.isEmpty() == true && !busy) Text(stringResource(R.string.saved_search_empty), color = Muted)
        searches.orEmpty().forEach { search ->
            Text(search.name, style = MaterialTheme.typography.titleMedium)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                val openLabel = stringResource(R.string.saved_search_open_label, search.name)
                val removeLabel = stringResource(R.string.saved_search_remove_label, search.name)
                OutlinedButton(onClick = { onOpen(search) }, modifier = Modifier.weight(1f).heightIn(min = 48.dp).semantics { contentDescription = openLabel }, shape = ControlShape) {
                    Text(stringResource(R.string.saved_search_open))
                }
                TextButton(onClick = { removing = search }, enabled = !busy, modifier = Modifier.weight(1f).heightIn(min = 48.dp).semantics { contentDescription = removeLabel }) {
                    Text(stringResource(R.string.saved_search_remove), color = Danger)
                }
            }
            HorizontalDivider(color = Rule)
        }
    }
    removing?.let { search ->
        AlertDialog(
            onDismissRequest = { removing = null },
            title = { Text(stringResource(R.string.saved_search_remove)) },
            text = { Text(stringResource(R.string.saved_search_remove_confirm, search.name)) },
            confirmButton = { TextButton(onClick = {
                removing = null
                if (!busy) scope.launch {
                    busy = true; error = null; message = null
                    try {
                        api.delete(search.id)
                        searches = searches.orEmpty().filterNot { it.id == search.id }
                        message = removed
                    } catch (e: CancellationException) { throw e }
                    catch (e: Exception) { error = requestFailureMessage(e) }
                    finally { busy = false }
                }
            }) { Text(stringResource(R.string.saved_search_remove), color = Danger) } },
            dismissButton = { TextButton(onClick = { removing = null }) { Text(stringResource(R.string.collections_cancel)) } },
        )
    }
}
