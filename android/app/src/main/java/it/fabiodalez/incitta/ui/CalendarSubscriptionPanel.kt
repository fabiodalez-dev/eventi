package it.fabiodalez.incitta.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import it.fabiodalez.incitta.calendar.NativeCalendar
import it.fabiodalez.incitta.calendar.CalendarExport
import it.fabiodalez.incitta.MainActivity

@Composable
fun CalendarSubscriptionPanel(initiallyExpanded: Boolean = false) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val session = LocalStore(context).readSession()
    val savedSelection = remember(session?.user?.id) { Uri.parse("https://local.invalid/?${NativeCalendar.selection(context).orEmpty()}") }
    val scope = rememberCoroutineScope()
    var connected by remember { mutableStateOf(NativeCalendar.enabled(context)) }
    var busy by remember { mutableStateOf(false) }
    var pendingQuery by remember { mutableStateOf<String?>(null) }
    var confirmQuery by remember { mutableStateOf<String?>(null) }
    var importedCount by remember { mutableStateOf<Int?>(null) }
    var pendingIcs by remember(session?.token) { mutableStateOf<String?>(null) }
    var expanded by remember { mutableStateOf(initiallyExpanded) }
    var categories by remember { mutableStateOf(emptyList<Category>()) }
    var selected by remember { mutableStateOf(savedSelection.getQueryParameter("category")?.split(",")?.filter(String::isNotBlank)?.toSet() ?: emptySet()) }
    var venue by remember { mutableStateOf(savedSelection.getQueryParameter("venue")?.let { Venue(slug = it, name = it.replace('-', ' ')) }) }
    var query by remember { mutableStateOf(venue?.name.orEmpty()) }
    var suggestions by remember { mutableStateOf(emptyList<Venue>()) }
    var days by remember { mutableIntStateOf(savedSelection.getQueryParameter("days")?.toIntOrNull() ?: 30) }
    var free by remember { mutableStateOf(savedSelection.getQueryParameter("price") == "free") }
    var error by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    fun connect(selection: String) {
        scope.launch {
            busy = true
            try {
                val count = NativeCalendar.refresh(context, selection)
                connected = true
                importedCount = count
                error = "Calendario collegato: $count date. Si aggiorna quando apri inCittà e periodicamente mentre resti collegato."
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = e.message ?: "Collegamento non riuscito. Riprova." }
            finally { busy = false }
        }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        val selection = pendingQuery
        pendingQuery = null
        if (NativeCalendar.allowed(context) && selection != null) connect(selection)
        else error = "Permesso calendario negato. Puoi ancora scaricare un file ICS dopo l'accesso."
    }
    if (confirmQuery != null) AlertDialog(
        onDismissRequest = { confirmQuery = null },
        title = { Text(if (connected) "Aggiornare il calendario inCittà?" else "Aggiungere il calendario inCittà?") },
        text = { Text("Creiamo un calendario separato sul telefono con gli eventi selezionati. Non è un abbonamento nel tuo account Google: inCittà lo aggiorna mentre resti autenticato. Non modifichiamo gli altri calendari. Al logout viene rimosso.") },
        confirmButton = { TextButton(onClick = {
            val selection = confirmQuery ?: return@TextButton
            confirmQuery = null
            if (NativeCalendar.allowed(context)) connect(selection)
            else { pendingQuery = selection; permission.launch(arrayOf(android.Manifest.permission.READ_CALENDAR, android.Manifest.permission.WRITE_CALENDAR)) }
        }) { Text(if (connected) "Aggiorna" else "Aggiungi calendario") } },
        dismissButton = { TextButton(onClick = { confirmQuery = null }) { Text("Annulla") } },
    )
    if (importedCount != null) AlertDialog(
        onDismissRequest = { importedCount = null },
        title = { Text("Calendario aggiunto al telefono") },
        text = { Text("${importedCount} date nel calendario «inCittà · Eventi». Non devi aggiungerlo di nuovo in Google Calendar. Se non lo vedi, apri il menu della tua app Calendario e seleziona «inCittà · Eventi» tra i calendari del dispositivo.\n\n" + if (importedCount == 0) "Nessun evento corrisponde ai filtri scelti: arriveranno con i prossimi aggiornamenti." else "Puoi tornare qui per cambiare filtri o scollegarlo.") },
        confirmButton = { TextButton(onClick = {
            importedCount = null
            runCatching { NativeCalendar.open(context) }.onFailure { error = "Calendario creato. Installa o apri un’app compatibile con i calendari del dispositivo." }
        }) { Text("Apri Calendario") } },
        dismissButton = { TextButton(onClick = { importedCount = null }) { Text("Resta in inCittà") } },
    )
    val download = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("text/calendar")) { uri ->
        val text = pendingIcs
        pendingIcs = null
        if (uri != null && text != null && LocalStore(context).readSession()?.token == session?.token) scope.launch {
            try {
                withContext(Dispatchers.IO) { requireNotNull(context.contentResolver.openOutputStream(uri)).use { it.write(text.toByteArray(Charsets.UTF_8)) } }
                error = "File ICS salvato. È una copia, non un collegamento automatico."
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { error = "Non è stato possibile salvare il file." }
        }
    }
    LaunchedEffect(expanded) {
        if (expanded) {
            loading = true
            try { categories = api.get<ApiEnvelope<List<Category>>>("categories").data }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = e.message.orEmpty() }
            finally { loading = false }
        }
    }
    LaunchedEffect(query) {
        suggestions = emptyList()
        if (venue != null || query.trim().length < 2) return@LaunchedEffect
        delay(250)
        try { suggestions = api.get<ApiEnvelope<SearchResults>>("search?q=${Uri.encode(query.trim())}&limit=8").data.venues }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = e.message.orEmpty() }
    }
    OutlinedButton(onClick = { expanded = !expanded }, modifier = Modifier.fillMaxWidth().padding(12.dp)) {
        Text(stringResource(R.string.calendar_customize))
    }
    if (!expanded) return
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(stringResource(R.string.calendar_sync_help), color = Muted)
        Text("L'accesso è obbligatorio. Con il tuo consenso modifichiamo solo il calendario inCittà. Scollegamento e logout rimuovono questo calendario dal telefono, non gli altri.", color = Muted)
        if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (error.isNotBlank()) Text(error, color = Acid)
        Column(Modifier.heightIn(max = 240.dp).verticalScroll(rememberScrollState())) {
            categories.filter { it.slug != null }.forEach { category ->
                val slug = requireNotNull(category.slug)
                Row {
                    Checkbox(checked = slug in selected, onCheckedChange = { selected = if (it) selected + slug else selected - slug })
                    Text(category.name, Modifier.padding(top = 12.dp))
                }
            }
        }
        Text(stringResource(R.string.calendar_all_categories), color = Muted)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(7, 30, 90).forEach { value -> FilterChip(selected = days == value, onClick = { days = value }, label = { Text(stringResource(R.string.calendar_days, value)) }) }
        }
        OutlinedTextField(value = query, onValueChange = { query = it; venue = null }, label = { Text(stringResource(R.string.calendar_venue_search)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
        suggestions.forEach { item -> TextButton(onClick = { venue = item; query = item.name; suggestions = emptyList() }) { Text(item.name) } }
        if (query.isNotEmpty()) TextButton(onClick = { venue = null; query = "" }) { Text(stringResource(R.string.calendar_all_venues)) }
        Row { Checkbox(checked = free, onCheckedChange = { free = it }); Text(stringResource(R.string.calendar_free), Modifier.padding(top = 12.dp)) }
        val url = Uri.parse(BuildConfig.API_BASE_URL).buildUpon().path("/eventi.ics").clearQuery()
            .appendQueryParameter("days", days.toString()).apply {
                if (selected.isNotEmpty()) appendQueryParameter("category", selected.joinToString(","))
                venue?.let { appendQueryParameter("venue", it.slug) }
                if (free) appendQueryParameter("price", "free")
            }.build()
        val valid = !busy && !loading && (query.isBlank() || venue != null)
        if (session == null) {
            Button(onClick = {
                context.startActivity(Intent(context, MainActivity::class.java).setData(Uri.parse("incitta://account"))
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
            }, modifier = Modifier.fillMaxWidth()) { Text("Accedi per collegare il calendario") }
        } else {
            Button(enabled = valid, onClick = {
                confirmQuery = url.encodedQuery.orEmpty()
            }, modifier = Modifier.fillMaxWidth()) { Text(if (busy) "Aggiornamento…" else if (connected) "Aggiorna calendario sul telefono" else "Collega al calendario del telefono") }
            if (connected) {
                TextButton(onClick = { runCatching { NativeCalendar.open(context) }.onFailure { error = "Nessuna app Calendario disponibile sul telefono." } }) { Text("Apri app Calendario") }
                TextButton(enabled = !busy, onClick = { scope.launch {
                    try {
                        withContext(Dispatchers.IO) { NativeCalendar.disconnect(context) }
                        connected = false; error = "Calendario inCittà scollegato. Gli altri calendari non sono stati modificati."
                    } catch (e: CancellationException) { throw e }
                    catch (_: Exception) { connected = false; error = "Sincronizzazione fermata. Per rimuovere il calendario, ripristina il permesso calendario e riprova." }
                } }) { Text("Scollega e rimuovi calendario inCittà") }
            }
            TextButton(enabled = valid, onClick = { scope.launch {
                busy = true
                try {
                    val result = api.get<ApiEnvelope<CalendarExport>>("me/calendar/export?${url.encodedQuery}", session.token).data
                    check(LocalStore(context).readSession()?.token == session.token)
                    pendingIcs = result.ics
                    download.launch("incitta-personalizzato.ics")
                } catch (e: CancellationException) { throw e }
                catch (_: Exception) { error = "Download non riuscito. Verifica l'accesso e riprova." }
                finally { busy = false }
            } }) { Text(stringResource(R.string.calendar_download)) }
        }
    }
}
