package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*

@Composable
internal fun CommunityAttendance(id: Long, session: Session?, saved: Boolean, navigate: (String) -> Unit, login: () -> Unit, refresh: () -> Unit) {
    val context = LocalContext.current
    val api = remember(session?.token) { CommunityApi(ApiClient(LocalStore(context).installationId), session?.token) }
    val scope = rememberCoroutineScope()
    var data by remember(id, session?.token) { mutableStateOf<JsonObject?>(null) }
    var revision by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var unavailable by remember { mutableStateOf(false) }
    LaunchedEffect(id, session?.token, revision) {
        error = null
        try { data = api.get("occurrences/$id/attendance").obj("data") }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { if(e is ApiException && e.status == 404) unavailable = true else error = communityFailureMessage(e) }
    }
    if(unavailable) return
    Column(Modifier.fillMaxWidth().padding(top = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.community_attendance_title), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.community_attendance_help), style = MaterialTheme.typography.bodySmall, color = Muted)
        error?.let { Text(it, color = MaterialTheme.colorScheme.error); TextButton(onClick = { revision++ }) { Text(stringResource(R.string.community_attendance_retry)) } }
        val state = data
        if(state == null && error == null) Text(stringResource(R.string.community_attendance_loading))
        if(state != null) {
            Text(stringResource(R.string.community_attendance_count, state.number("count").toInt()))
            state.rows("attendees").forEach { person -> TextButton(onClick = { navigate("profile/${person.text("handle")}") }) { Text(person.text("display_name")) } }
            val access = state.obj("access")
            val going = state.flag("going")
            if(going || access.flag("can_attend")) {
                OutlinedButton(enabled = !busy, modifier = Modifier.heightIn(min = 48.dp), onClick = {
                    if(busy) return@OutlinedButton
                    scope.launch {
                        busy = true; error = null
                        try { api.change("saved/$id/attendance", buildJsonObject { put("going", !going) }); revision++; refresh() }
                        catch(e: CancellationException) { throw e }
                        catch(e: Exception) { error = communityFailureMessage(e) }
                        finally { busy = false }
                    }
                }) { Text(stringResource(if(going) R.string.community_not_going else R.string.community_going)) }
            } else if(access.text("reason") in listOf("suspended", "impersonation")) {
                Text(stringResource(R.string.community_attendance_blocked))
            } else {
                if(access.text("required_step").isBlank()) Text(stringResource(R.string.community_attendance_private))
                Button(onClick = { if(session == null) login() else navigate(if(access.text("required_step") == "whatsapp") "whatsapp" else "settings") }) { Text(stringResource(R.string.community_attendance_setup)) }
            }
            if(session != null && saved) TextButton(onClick = { navigate("save/$id") }) { Text(stringResource(R.string.community_recommend_action)) }
        }
    }
}
