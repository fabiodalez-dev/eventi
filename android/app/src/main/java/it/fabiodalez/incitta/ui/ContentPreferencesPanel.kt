package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
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
)
@Serializable
internal data class ContentCategory(val id: Long, val name: String)
@Serializable
internal data class ContentOptions(val selection: ContentSelection, val options: List<ContentCategory>)

@Composable
fun ContentPreferencesPanel(session: Session, onSaved: () -> Unit) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val json = remember { Json(api.json) { encodeDefaults = true } }
    val scope = rememberCoroutineScope()
    var expanded by remember(session.user.id) { mutableStateOf(false) }
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
    OutlinedButton(onClick = { expanded = !expanded }, shape = ControlShape, modifier = Modifier.fillMaxWidth().padding(top = 16.dp).heightIn(min = 48.dp)) {
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
    listOf("all" to "Tutto, tranne ciò che nascondo", "selected" to "Solo le categorie che mi interessano").forEach { (value, label) ->
        Row(Modifier.fillMaxWidth()) {
            RadioButton(selected = current.selection.mode == value, enabled = !busy, onClick = { change(current.selection.copy(mode = value)) })
            Text(label, modifier = Modifier.padding(top = 12.dp))
        }
    }
    Text("Le nuove categorie appaiono qui automaticamente. In modalità Solo devi sceglierle per mostrarle; senza selezioni le liste saranno vuote.", color = Muted)
    current.options.forEach { category ->
        var menu by remember(category.id) { mutableStateOf(false) }
        val value = when (category.id) {
            in current.selection.hiddenCategories -> "hidden"
            in current.selection.categories -> "interested"
            else -> "neutral"
        }
        Column(Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
            Text(category.name, style = MaterialTheme.typography.titleMedium)
            Box {
                OutlinedButton(onClick = { menu = true }, enabled = !busy, shape = ControlShape, modifier = Modifier.heightIn(min = 48.dp), colors = ButtonDefaults.outlinedButtonColors(contentColor = if (value == "interested") Acid else Paper)) {
                    Text(when(value) { "hidden" -> "Nascondi"; "interested" -> "Mi interessa"; else -> "Nessuna preferenza" })
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    listOf("neutral" to "Nessuna preferenza", "interested" to "Mi interessa", "hidden" to "Nascondi").forEach { (choice, label) ->
                        DropdownMenuItem(text = { Text(label) }, onClick = {
                            change(current.selection.copy(
                                categories = current.selection.categories - category.id + if (choice == "interested") listOf(category.id) else emptyList(),
                                hiddenCategories = current.selection.hiddenCategories - category.id + if (choice == "hidden") listOf(category.id) else emptyList(),
                            ))
                            menu = false
                        })
                    }
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
    TextButton(onClick = { change(ContentSelection(inferredAds = current.selection.inferredAds)) }, enabled = !busy) { Text("RIPRISTINA TUTTE LE CATEGORIE (POI SALVA)") }
}
