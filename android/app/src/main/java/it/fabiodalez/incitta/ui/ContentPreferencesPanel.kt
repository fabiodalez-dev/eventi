package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.selection.toggleable
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.Role
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
import kotlinx.serialization.SerialName
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

@Serializable
internal data class ContentSelection(
    val mode: String = "all",
    val categories: List<Long> = emptyList(),
    @SerialName("hidden_categories") val hiddenCategories: List<Long> = emptyList(),
    @SerialName("inferred_ads") val inferredAds: Boolean = true,
    /*
     * 8 e 9. Facoltativi nel PATCH: null quando il server non li manda (un
     * server vecchio) e allora non si spediscono, così nessuno li azzera.
     */
    @SerialName("hidden_venues") val hiddenVenues: List<Long>? = null,
    @SerialName("access_needs") val accessNeeds: List<String>? = null,
)
@Serializable
internal data class ContentCategory(val id: Long, val name: String)
@Serializable
internal data class ContentOptions(
    val selection: ContentSelection,
    val options: List<ContentCategory>,
    @SerialName("hidden_venue_options") val hiddenVenueOptions: List<HiddenVenueOption> = emptyList(),
)

@Composable
fun ContentPreferencesPanel(session: Session, standalone: Boolean = false, onSaved: () -> Unit) {
    val context = LocalContext.current
    val resources = androidx.compose.ui.platform.LocalResources.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val json = remember { Json(api.json) { encodeDefaults = true } }
    val scope = rememberCoroutineScope()
    var expanded by remember(session.user.id) { mutableStateOf(standalone) }
    var data by remember(session.user.id) { mutableStateOf<ContentOptions?>(null) }
    var busy by remember(session.user.id) { mutableStateOf(false) }
    var message by remember(session.user.id) { mutableStateOf("") }
    suspend fun load() {
        busy = true
        try {
            data = api.get<ApiEnvelope<ContentOptions>>("me/content-preferences", session.token).data
            message = ""
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            message = "Interessi non caricati. ${requestFailureMessage(error)}"
        } finally { busy = false }
    }
    LaunchedEffect(expanded, session.user.id) { if (expanded && data == null) load() }
    if (!standalone) OutlinedButton(onClick = { expanded = !expanded }, shape = ControlShape, modifier = Modifier.fillMaxWidth().padding(top = 16.dp).heightIn(min = 48.dp)) {
        Text(if (expanded) "CHIUDI I MIEI INTERESSI" else "I MIEI INTERESSI · COSA VEDERE")
    }
    if (!expanded) return
    Text("Le scelte valgono anche sul sito. Puoi cambiarle quando vuoi.", color = Muted)
    if (message.isNotEmpty()) Text(message, modifier = Modifier.padding(vertical = 8.dp))
    if (busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
    if (data == null) {
        TextButton(onClick = { scope.launch { load() } }, enabled = !busy) { Text("RIPROVA") }
        return
    }
    val current = data!!
    fun change(selection: ContentSelection) { data = current.copy(selection = selection) }
    ContentModeSelector(current.selection.mode, !busy) { change(current.selection.copy(mode = it)) }
    Text("Le nuove categorie appaiono qui automaticamente. In modalità Solo devi sceglierle per mostrarle; senza selezioni le liste saranno vuote.", color = Muted)
    current.options.forEach { category ->
        val value = when (category.id) {
            in current.selection.hiddenCategories -> "hidden"
            in current.selection.categories -> "interested"
            else -> "neutral"
        }
        Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(category.name, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge)
            Box(Modifier.width(156.dp)) {
                NativeChoicePicker(category.name, value,
                    listOf("neutral" to "Nessuna preferenza", "interested" to "Mi interessa", "hidden" to "Nascondi"), enabled = !busy) { choice ->
                            change(current.selection.copy(
                                categories = current.selection.categories - category.id + if (choice == "interested") listOf(category.id) else emptyList(),
                                hiddenCategories = current.selection.hiddenCategories - category.id + if (choice == "hidden") listOf(category.id) else emptyList(),
                            ))
                }
            }
        }
        HorizontalDivider(color = Rule)
    }
    current.selection.accessNeeds?.let { needs ->
        Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_access_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 12.dp))
        Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_access_help), color = Muted)
        ACCESS_NEEDS.forEach { need ->
            val checked = need in needs
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(value = checked, enabled = !busy, role = Role.Checkbox) {
                    change(current.selection.copy(accessNeeds = if (it) needs + need else needs - need))
                },
                verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
            ) {
                Checkbox(checked = checked, onCheckedChange = null, enabled = !busy)
                Text(accessNeedLabel(need), modifier = Modifier.padding(start = 8.dp))
            }
        }
        HorizontalDivider(color = Rule)
    }
    if (current.selection.hiddenVenues != null) {
        Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_hidden_venues_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 12.dp))
        if (current.hiddenVenueOptions.isEmpty()) Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_hidden_venues_empty), color = Muted)
        current.hiddenVenueOptions.forEach { venue ->
            val description = androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_show_again_description, venue.name)
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                Text(venue.name, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge)
                TextButton(onClick = {
                    scope.launch {
                        busy = true
                        try {
                            api.send("me/hidden-venues/${venue.id}", "DELETE", session.token)
                            val latest = data ?: current
                            data = latest.copy(
                                selection = latest.selection.copy(hiddenVenues = latest.selection.hiddenVenues?.minus(venue.id)),
                                hiddenVenueOptions = latest.hiddenVenueOptions.filterNot { it.id == venue.id },
                            )
                            message = ""
                            onSaved()
                        } catch (error: Exception) {
                            if (error is CancellationException) throw error
                            message = resources.getString(it.fabiodalez.incitta.R.string.interests_show_again_failed, requestFailureMessage(error))
                        } finally { busy = false }
                    }
                }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = description }) {
                    Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.interests_show_again))
                }
            }
        }
        HorizontalDivider(color = Rule)
    }
    Row {
        Checkbox(checked = current.selection.inferredAds, enabled = !busy, onCheckedChange = { change(current.selection.copy(inferredAds = it)) })
        Text("Usa anche i salvataggi recenti per AD pertinenti, con meno peso delle mie scelte.", modifier = Modifier.padding(top = 12.dp))
    }
    Text("Biglietti, prenotazioni e salvataggi non vengono cancellati. Le notifiche hanno impostazioni separate.", color = Muted)
    Button(onClick = {
        scope.launch {
            busy = true
            try {
                data = api.execute<ApiEnvelope<ContentOptions>>("me/content-preferences", "PATCH", json.encodeToString(current.selection), session.token).data
                message = "Interessi salvati. Eventi e mappe aggiornati."
                onSaved()
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                message = "Salvataggio non confermato. ${requestFailureMessage(error)}"
            } finally { busy = false }
        }
    }, enabled = !busy, shape = ControlShape, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("SALVA I MIEI INTERESSI") }
    TextButton(onClick = { change(ContentSelection(inferredAds = current.selection.inferredAds, hiddenVenues = current.selection.hiddenVenues, accessNeeds = current.selection.accessNeeds)) }, enabled = !busy) { Text("RIPRISTINA TUTTE LE CATEGORIE (POI SALVA)") }
}

@Composable
internal fun ContentModeSelector(mode: String, enabled: Boolean, onSelect: (String) -> Unit) {
    Column(Modifier.selectableGroup()) {
        listOf("all" to "Tutto, tranne ciò che nascondo", "selected" to "Solo le categorie che mi interessano").forEach { (value, label) ->
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp)
                    .selectable(selected = mode == value, enabled = enabled, role = Role.RadioButton, onClick = { onSelect(value) })
                    .padding(vertical = 8.dp),
                verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                RadioButton(selected = mode == value, enabled = enabled, onClick = null)
                Text(label, modifier = Modifier.weight(1f))
            }
        }
    }
}
