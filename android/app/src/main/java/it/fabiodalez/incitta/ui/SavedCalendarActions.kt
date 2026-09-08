package it.fabiodalez.incitta.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.*
import kotlinx.serialization.Serializable

@Serializable
private data class CalendarFilePayload(val ics: String)

@Composable
fun CalendarBanner(onCreate: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(top = 24.dp).background(Acid).padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text(stringResource(R.string.calendar_banner_title), color = Ink, style = MaterialTheme.typography.headlineMedium)
        Text(stringResource(R.string.calendar_banner_help), color = Ink)
        Button(onClick = onCreate, colors = ButtonDefaults.buttonColors(containerColor = Ink, contentColor = Paper), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.calendar_customize))
        }
    }
}

@Composable
fun SavedCalendarActions(state: AppUiState) {
    val exportDone = stringResource(R.string.calendar_export_done)
    val exportError = stringResource(R.string.calendar_export_error)
    val savedTitle = stringResource(R.string.calendar_saved_title)
    val context = LocalContext.current
    val store = remember { LocalStore(context) }
    val scope = rememberCoroutineScope()
    val token = state.session?.token
    var payload by remember(token) { mutableStateOf<String?>(null) }
    var busy by remember(token) { mutableStateOf(false) }
    var message by remember(token) { mutableStateOf("") }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("text/calendar")) { uri ->
        val body = payload
        payload = null
        if (uri != null && body != null && store.readSession()?.token == token) scope.launch {
            try {
                withContext(Dispatchers.IO) { requireNotNull(context.contentResolver.openOutputStream(uri)).use { it.write(body.toByteArray(Charsets.UTF_8)) } }
                message = exportDone
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { message = exportError }
        }
    }
    Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Button(enabled = !busy && !state.isLoading && (token != null || state.savedOccurrences.isNotEmpty()), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), onClick = {
            scope.launch {
                busy = true; message = ""
                try {
                    val body = if (token != null) ApiClient(store.installationId).get<ApiEnvelope<CalendarFilePayload>>("me/saved/calendar", token).data.ics
                    else {
                        check(state.savedOccurrences.map { it.occurrenceId }.toSet() == state.savedIds)
                        SavedCalendarFile.render(state.savedOccurrences, BuildConfig.API_BASE_URL, savedTitle)
                    }
                    check(store.readSession()?.token == token)
                    payload = body
                    launcher.launch("incitta-salvati.ics")
                } catch (e: CancellationException) { throw e }
                catch (_: Exception) { message = exportError }
                finally { busy = false }
            }
        }) { Text(stringResource(if (busy) R.string.calendar_export_loading else R.string.calendar_export_saved)) }
        Text(stringResource(R.string.calendar_export_help), color = Muted)
        if (message.isNotBlank()) Text(message, color = Acid)
    }
    CalendarSubscriptionPanel()
}
