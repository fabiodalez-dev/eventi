package it.fabiodalez.incitta.ui
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import java.net.URLEncoder

@Serializable
internal data class OrganizerArchive(val name: String, val id: Long = 0, val description: String? = null, val events: List<Occurrence> = emptyList(), val has_more: Boolean = false)

@Serializable
internal data class OrganizerFollowState(val following: Boolean = false, val notify: Boolean = false)

@Serializable
internal data class OrganizerFollowRequest(val id: Long, val notify: Boolean, val type: String)

@Composable
fun OrganizerScreen(slug: String, session: Session?, savedIds: Set<Long>, onBack: () -> Unit, onOrganizer: (String) -> Unit, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit, onLogin: () -> Unit = {}) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var organizers by remember(slug) { mutableStateOf<List<Organizer>>(emptyList()) }
    var archive by remember(slug) { mutableStateOf<OrganizerArchive?>(null) }
    var query by remember(slug) { mutableStateOf("") }
    var page by remember(slug) { mutableIntStateOf(1) }
    var past by remember(slug) { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var retry by remember { mutableIntStateOf(0) }
    var more by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val currentIdentity by rememberUpdatedState(session?.token to slug)
    var follow by remember(slug, session?.token) { mutableStateOf<OrganizerFollowState?>(null) }
    var followError by remember(slug, session?.token) { mutableStateOf<String?>(null) }
    var followBusy by remember { mutableStateOf(false) }
    LaunchedEffect(archive?.id, session?.token, retry) {
        val id = archive?.id ?: return@LaunchedEffect
        val token = session?.token ?: return@LaunchedEffect
        if (id == 0L) return@LaunchedEffect
        followError = null
        try {
            follow = api.get<ApiEnvelope<OrganizerFollowState>>("me/follows/organizer/$id", token).data
        } catch (e: Exception) {
            if (e is CancellationException) throw e
            followError = requestFailureMessage(e)
        }
    }
    fun updateFollow(remove: Boolean = false, notify: Boolean = false) {
        val id = archive?.id ?: return
        val token = session?.token ?: return
        val requestIdentity = token to slug
        scope.launch {
            followBusy = true
            followError = null
            try {
                if (remove) api.delete<ApiEnvelope<kotlinx.serialization.json.JsonObject>>("me/follows/organizer/$id", token)
                else api.post<ApiEnvelope<kotlinx.serialization.json.JsonObject>, OrganizerFollowRequest>("me/follows", OrganizerFollowRequest(id, notify, "organizer"), token, idempotent = true)
                if (currentIdentity == requestIdentity) follow = OrganizerFollowState(!remove, !remove && notify)
            } catch (e: Exception) {
                if (e is CancellationException) throw e
                if (currentIdentity == requestIdentity) followError = requestFailureMessage(e)
            } finally { followBusy = false }
        }
    }
    LaunchedEffect(slug, session?.token, page, past, query, retry) {
        loading = true
        error = null
        try {
            if (slug.isEmpty()) {
                kotlinx.coroutines.delay(250)
                val result = api.get<ApiEnvelope<List<Organizer>>>("organizers?page=${page}&q=${URLEncoder.encode(query, "UTF-8")}", session?.token)
                organizers = result.data
                more = result.meta?.hasMore == true
            } else {
                archive = api.get<ApiEnvelope<OrganizerArchive>>("organizers/${slug}?page=${page}&past=${if(past) 1 else 0}", session?.token).data
                more = archive?.has_more == true
            }
        } catch(e: Exception) {
            if(e is CancellationException) throw e
            error = requestFailureMessage(e)
        } finally { loading = false }
    }
    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 18.dp)) {
        item {
            TextButton(onClick=onBack) { Text("INDIETRO") }
            Text(archive?.name ?: "ORGANIZZATORI", style=MaterialTheme.typography.headlineLarge)
            if(slug.isEmpty()) OutlinedTextField(query, { query=it; page=1 }, label={Text("Cerca organizzatore")}, modifier=Modifier.fillMaxWidth(), singleLine=true)
            else {
                PublicContactSection("organizers", slug, session, onLogin)
                archive?.description?.let { Text(it, modifier=Modifier.padding(vertical=16.dp)) }
                if (session == null) Text("Accedi da Profilo per seguire questo organizzatore.")
                else {
                    follow?.let { current ->
                        OutlinedButton(onClick = { updateFollow(remove = current.following) }, enabled = !followBusy) {
                            Text(if (current.following) "SMETTI DI SEGUIRE" else "SEGUI ORGANIZZATORE")
                        }
                        if (current.following) Row {
                            Checkbox(checked = current.notify, onCheckedChange = { updateFollow(notify = it) }, enabled = !followBusy)
                            Text("Includi nei riepiloghi, secondo le tue preferenze di notifica.", modifier = Modifier.weight(1f).padding(top = 12.dp))
                        }
                    }
                    followError?.let { Text(it); TextButton(onClick = { retry++ }) { Text("RIPROVA") } }
                }
                Row {
                    TextButton(onClick={past=false;page=1}) { Text("IN PROGRAMMA", color=if(!past) Acid else Paper) }
                    TextButton(onClick={past=true;page=1}) { Text("ARCHIVIO", color=if(past) Acid else Paper) }
                }
            }
            if(loading) LinearProgressIndicator(Modifier.fillMaxWidth())
            error?.let { Text(it); TextButton(onClick={retry++}) { Text("RIPROVA") } }
        }
        if(!loading && error==null) {
            if(slug.isEmpty()) {
                items(organizers, key={it.id ?: it.name.orEmpty()}) { organizer ->
                    OutlinedButton(onClick={organizer.slug?.let(onOrganizer)}, shape=RectangleShape, modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)) { Text(organizer.name.orEmpty()) }
                }
                if(organizers.isEmpty()) item { Text("Nessun organizzatore trovato.") }
            } else {
                items(archive?.events.orEmpty(), key=Occurrence::occurrenceId) { occurrence ->
                    EventRow(occurrence, occurrence.occurrenceId in savedIds, onOpen, onSave)
                }
                if(archive?.events.isNullOrEmpty()) item { Text("Nessuna data per le preferenze attuali.") }
            }
        }
        item {
            Row(Modifier.padding(vertical=16.dp)) {
                if(page>1) TextButton(onClick={page--},enabled=!loading) { Text("PRECEDENTI") }
                if(more) TextButton(onClick={page++},enabled=!loading) { Text("SUCCESSIVI") }
            }
        }
    }
}
