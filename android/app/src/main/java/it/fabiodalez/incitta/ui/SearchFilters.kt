package it.fabiodalez.incitta.ui

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Looper
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.res.stringResource
import it.fabiodalez.incitta.R
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.*
import kotlin.coroutines.resume

@Composable
internal fun SearchFilters(state: AppUiState, query: String = "", collapsible: Boolean = false, onCollapse: () -> Unit = {}, apply: (Map<String, String>, String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var panelOpen by rememberSaveable { mutableStateOf(!collapsible) }
    var expanded by rememberSaveable { mutableStateOf(false) }
    var choices by remember { mutableStateOf(TonightPayload()) }
    var status by remember { mutableStateOf<String?>(null) }
    var locating by remember { mutableStateOf(false) }
    var checking by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    var radii by remember { mutableStateOf<Map<String, Boolean>>(emptyMap()) }
    var facets by remember { mutableStateOf<Map<String, Map<String, Int>>?>(null) }
    val filters = state.discoveryFilters
    LaunchedEffect(filters, query, state.session?.token, reload) {
        facets = null
        radii = emptyMap()
        try {
            delay(180)
            val params = filterQuery(filters, query)
            facets = ApiClient(LocalStore(context).installationId).get<ApiEnvelope<Map<String, Map<String, Int>>>>("events/facets?$params", state.session?.token).data
            if (filters.containsKey("near")) {
                radii = coroutineScope {
                    listOf("1", "5", "10", "25").map { km -> async {
                        km to ApiClient(LocalStore(context).installationId).get<ApiEnvelope<List<Occurrence>>>(
                            "events?${filterQuery(filters + ("radius_km" to km), query)}&limit=1", state.session?.token).data.isNotEmpty()
                    } }.awaitAll().toMap()
                }
            }
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            status = "Non riesco a verificare i filtri disponibili. Puoi comunque rimuovere quelli selezionati."
        }
    }
    fun available(group: String, options: List<Pair<String, String>>): List<Pair<String, String>> {
        val field = mapOf("date" to "preset", "category" to "categories", "time" to "time_of_day", "tag" to "tags")[group] ?: group
        val selected = filters[field].orEmpty().split(',')
        return options.filter { (key, _) -> key in selected || (facets?.get(group)?.get(key) ?: 0) > 0 }
    }
    val latestFilters by rememberUpdatedState(filters)
    val latestApply by rememberUpdatedState(apply)
    val latestQuery by rememberUpdatedState(query)
    val latestToken by rememberUpdatedState(state.session?.token)
    suspend fun applyChecked(next: Map<String, String>, label: String) {
        if (checking) return
        val previous = latestFilters
        if (removesSearchFilters(previous, next)) { latestApply(next, label); status = null; return }
        checking = true
        val token = latestToken
        val search = latestQuery
        try {
            val results = ApiClient(LocalStore(context).installationId).get<ApiEnvelope<List<Occurrence>>>(
                "events?${filterQuery(next, search)}&limit=1", token).data
            if (latestFilters != previous || latestToken != token || latestQuery != search) return
            if (results.isEmpty()) {
                status = "Nessun evento con questa combinazione. Ho mantenuto i filtri precedenti: togli un filtro o amplia la zona."
            } else { status = null; latestApply(next, label) }
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            status = "Non riesco a verificare questa scelta. I filtri precedenti sono rimasti invariati. Riprova."
        } finally { checking = false }
    }
    fun change(key: String, value: String) {
        if (checking) return
        val next = filters.toMutableMap()
        if (key == "municipality") { next.remove("zone"); next.remove("venue") }
        if (key == "zone") next.remove("venue")
        if (key == "preset") { next.remove("date"); next.remove("from"); next.remove("to") }
        if (key == "from" || key == "to") { next.remove("preset"); next.remove("date") }
        if (value.isBlank()) next.remove(key) else next[key] = value
        scope.launch { applyChecked(next, "Filtri personalizzati") }
    }
    LaunchedEffect(state.session?.token) {
        try {
            choices = ApiClient(LocalStore(context).installationId).get<ApiEnvelope<TonightPayload>>("tonight?step=1", state.session?.token).data
        } catch (error: Exception) { if (error is CancellationException) throw error }
    }
    fun locate() {
        if (locating) return
        scope.launch {
            locating = true
            status = "Cerco la tua posizione…"
            try {
                val location = withTimeout(15000) { deviceLocation(context) }
                applyChecked(latestFilters + mapOf("near" to "${location.latitude},${location.longitude}", "radius_km" to "5", "sort" to "distance"), "Entro 5 km dalla tua posizione")
            } catch (error: Exception) {
                if (error is CancellationException && error !is TimeoutCancellationException) throw error
                status = "Posizione non disponibile. Attiva la localizzazione e riprova, oppure scegli una zona."
            } finally { locating = false }
        }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) locate()
        else status = "Permesso posizione negato. Puoi abilitarlo nelle impostazioni dell’app o scegliere una zona."
    }
    if (collapsible) OutlinedButton(onClick = { panelOpen = !panelOpen }, modifier = Modifier.fillMaxWidth().padding(top = 12.dp).heightIn(min = 48.dp), shape = ControlShape) {
        Text(if (panelOpen) "Chiudi i filtri" else "Filtra i risultati")
    }
    if (!panelOpen) return
    Column(Modifier.fillMaxWidth().padding(top = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (filters.isNotEmpty()) TextButton(onClick = { scope.launch { applyChecked(emptyMap(), "Tutti gli eventi") } }, enabled = !checking) { Text("Azzera i filtri ×") }
        QuickFilterGroup("Quando", "preset", available("date", listOf("today" to "Oggi", "tonight" to "Stasera", "starting_soon" to "Inizia tra poco", "tomorrow" to "Domani", "weekend" to "Weekend", "week" to "Questa settimana")), filters, ::change)
        QuickFilterGroup("Categoria", "categories", available("category", choices.categories.map { it.slug to it.name }), filters, ::change)
        QuickFilterGroup("Prezzo", "price", available("price", listOf("free" to "Gratis", "donation" to "Offerta libera", "max10" to "Fino a 10 €", "max20" to "Fino a 20 €")), filters, ::change)
        QuickFilterGroup("Fascia oraria", "time_of_day", available("time", listOf("day" to "Di giorno", "evening" to "Di sera", "night" to "Di notte")), filters, ::change)
        Text("Caratteristiche", color = Muted)
        val features = listOf("outdoor" to "All’aperto", "accessible" to "Accessibile", "family" to "Adatto alle famiglie")
        val activeFeatures = features.filter { filters[it.first] == "1" }
        PeekTabRow {
            features.filter { it in activeFeatures || (facets?.get("features")?.get(it.first) ?: 0) > 0 }.forEach { (key, label) ->
                val active = filters[key] == "1"
                FilterLabel(label + if (active) " ×" else "", active) { change(key, if (active) "" else "1") }
            }
        }
        OutlinedButton(onClick = { expanded = !expanded }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) {
            Text("Filtri avanzati ${if (expanded) "⌃" else "⌄"}")
        }
        if (expanded) {
            QuickFilterGroup(stringResource(R.string.membership_label), "membership", listOf("required" to stringResource(R.string.membership_required), "not_required" to stringResource(R.string.membership_not_required)), filters, ::change)
            QuickFilterGroup("Tag", "tags", available("tag", facets?.get("tag").orEmpty().keys.map { it to "#$it" }), filters, ::change)
            QuickFilterGroup("Comune", "municipality", available("municipality", choices.municipalities.map { it to it }), filters, ::change)
            QuickFilterGroup("Quartiere", "zone", available("zone", facets?.get("zone").orEmpty().keys.map { it to it }), filters, ::change)
            QuickFilterGroup("Locale", "venue", available("venue", state.venues.mapNotNull { venue -> venue.slug?.let { it to venue.name } }), filters, ::change)
            listOf("from" to "Dal", "to" to "Al").forEach { (key, label) ->
                OutlinedButton(onClick = {
                    val initial = runCatching { java.time.LocalDate.parse(filters[key]) }.getOrNull() ?: java.time.LocalDate.now()
                    android.app.DatePickerDialog(context, { _, year, month, day -> change(key, java.time.LocalDate.of(year, month + 1, day).toString()) }, initial.year, initial.monthValue - 1, initial.dayOfMonth).show()
                }, enabled = !checking, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) { Text("$label: ${filters[key] ?: "Scegli una data"}") }
                if (filters.containsKey(key)) TextButton(onClick = { change(key, "") }) { Text("Togli $label ×") }
            }
            QuickFilterGroup("Ordina per", "sort", listOf("start" to "Orario", "relevance" to "Rilevanza") + (if (filters.containsKey("near")) listOf("distance" to "Distanza") else emptyList()), filters, ::change)
        }
        if (filters.containsKey("near")) {
            Text("Distanza", color = Muted)
            PeekTabRow {
                val current = filters["radius_km"] ?: "5"
                listOf("1", "5", "10", "25").filter { it == current || radii[it] == true }.forEach { km ->
                    FilterLabel("Entro $km km" + if (km == current) " ×" else "", km == current) {
                        if (km == current) scope.launch { applyChecked(withoutSearchPosition(filters), "Filtri personalizzati") }
                        else change("radius_km", km)
                    }
                }
            }
            OutlinedButton(onClick = { scope.launch { applyChecked(withoutSearchPosition(filters), "Filtri personalizzati") } }, enabled = !checking, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) { Text("Togli la posizione ×") }
        } else {
            Text("La posizione serve a cercare eventi entro 5 km. Viene richiesta solo al tuo tap.", color = Muted)
            OutlinedButton(onClick = {
                if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) locate()
                else permission.launch(arrayOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION))
            }, enabled = !locating, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) { Text("Usa la mia posizione") }
        }
        if (checking) Text("Verifico gli eventi disponibili…", color = Muted)
        status?.let { Text(it, color = Muted) }
        if (facets == null && status != null) TextButton(onClick = { status = null; reload++ }) { Text("Riprova") }
        if (collapsible) Button(onClick = { panelOpen = false; onCollapse() }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) { Text("Mostra i risultati") }
    }
}

@Composable
private fun QuickFilterGroup(label: String, key: String, options: List<Pair<String, String>>, filters: Map<String, String>, change: (String, String) -> Unit) {
    val selected = filters[key].orEmpty().split(',').filter { it.isNotBlank() }
    val searchable = key in listOf("municipality", "zone", "venue", "tags")
    var search by rememberSaveable(key) { mutableStateOf("") }
    val choices = if (selected.isEmpty() || searchable) options else selected.map { value -> value to (options.find { it.first == value }?.second ?: value) }
    if (choices.isEmpty()) return
    Text(label, color = Muted)
    if (searchable) {
        OutlinedTextField(value = search, onValueChange = { search = it }, label = { Text(stringResource(R.string.filter_search_options, label)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
    }
    val visible = choices.filter { !searchable || it.second.contains(search, ignoreCase = true) }
    if (visible.isEmpty()) Text(stringResource(R.string.filter_search_empty), color = Muted)
    PeekTabRow {
        if (key == "preset" && selected.isEmpty() && listOf("date", "from", "to").none { !filters[it].isNullOrBlank() }) {
            FilterLabel("Tutte le date", true) { change(key, "") }
        }
        visible.forEach { (value, name) ->
            val active = value in selected
            FilterLabel(name + if (active) " ×" else "", active) { change(key, if (active) (selected - value).joinToString(",") else value) }
        }
    }
}

@Suppress("MissingPermission", "DEPRECATION")
private suspend fun deviceLocation(context: Context): Location = suspendCancellableCoroutine { continuation ->
    val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    val provider = listOf(LocationManager.NETWORK_PROVIDER, LocationManager.GPS_PROVIDER).firstOrNull {
        (it != LocationManager.GPS_PROVIDER || fine) && manager.isProviderEnabled(it)
    }
    if (provider == null) { continuation.resumeWith(Result.failure(IllegalStateException("Location disabled"))); return@suspendCancellableCoroutine }
    val listener = object : LocationListener {
        override fun onLocationChanged(location: Location) {
            manager.removeUpdates(this)
            if (continuation.isActive) continuation.resume(location)
        }
    }
    continuation.invokeOnCancellation { manager.removeUpdates(listener) }
    try { manager.requestSingleUpdate(provider, listener, Looper.getMainLooper()) }
    catch (error: Exception) { continuation.resumeWith(Result.failure(error)) }
}
