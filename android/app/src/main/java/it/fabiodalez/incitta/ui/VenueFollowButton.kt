package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import it.fabiodalez.incitta.R
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
internal fun VenueFollowButton(id: Long?, session: Session?, onLogin: () -> Unit) {
    if (id == null) return
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val scope = rememberCoroutineScope()
    var follow by remember(id, session?.token) { mutableStateOf<OrganizerFollowState?>(null) }
    var busy by remember(id, session?.token) { mutableStateOf(false) }
    var error by remember(id, session?.token) { mutableStateOf<String?>(null) }
    var retry by remember(id, session?.token) { mutableIntStateOf(0) }
    LaunchedEffect(id, session?.token, retry) {
        if (session == null) return@LaunchedEffect
        try { follow = api.get<ApiEnvelope<OrganizerFollowState>>("me/follows/venue/$id", session.token).data; error = null }
        catch (e: Exception) { if (e is CancellationException) throw e; error = requestFailureMessage(e) }
    }
    OutlinedButton(onClick = {
        if (session == null) onLogin()
        else if (follow == null) retry++
        else scope.launch {
            busy = true
            try {
                if (follow!!.following) api.delete<ApiEnvelope<ApiMessage>>("me/follows/venue/$id", session.token)
                else api.post<ApiEnvelope<kotlinx.serialization.json.JsonObject>, OrganizerFollowRequest>("me/follows", OrganizerFollowRequest(id, true, "venue"), session.token, idempotent = true)
                follow = api.get<ApiEnvelope<OrganizerFollowState>>("me/follows/venue/$id", session.token).data
                error = null
            } catch (e: Exception) { if (e is CancellationException) throw e; error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(when { busy -> "Aggiornamento…"; follow?.following == true -> "Non seguire più"; else -> "Segui questo locale" })
    }
    if (follow?.following == true && session != null) {
        Text(stringResource(R.string.follow_notifications), style = MaterialTheme.typography.labelLarge)
        listOf("all" to R.string.follow_all, "new_only" to R.string.follow_new, "none" to R.string.follow_none).forEach { (mode, label) ->
            OutlinedButton(enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), onClick = {
                scope.launch {
                    busy = true
                    try {
                        api.post<ApiEnvelope<kotlinx.serialization.json.JsonObject>, OrganizerFollowRequest>("me/follows", OrganizerFollowRequest(id, mode != "none", "venue", mode), session.token, idempotent = true)
                        follow = api.get<ApiEnvelope<OrganizerFollowState>>("me/follows/venue/$id", session.token).data
                        error = null
                    } catch (e: Exception) { if (e is CancellationException) throw e; error = requestFailureMessage(e) }
                    finally { busy = false }
                }
            }) {
                RadioButton(selected = (if (follow?.notify == true) follow?.notification_mode else "none") == mode, onClick = null)
                Text(stringResource(label))
            }
        }
    }
    error?.let { Text(it, color = Muted) }
}
