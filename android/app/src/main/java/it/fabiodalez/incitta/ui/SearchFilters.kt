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
internal fun SearchFilters(state: AppUiState, query: String = "", apply: (Map<String, String>, String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var expanded by rememberSaveable { mutableStateOf(false) }
    var choices by remember { mutableStateOf(TonightPayload()) }
    var status by remember { mutableStateOf<String?>(null) }
    var locating by remember { mutableStateOf(false) }
    var facets by remember { mutableStateOf<Map<String, Map<String, Int>>?>(null) }
    val filters = state.discoveryFilters
    LaunchedEffect(filters, query, state.session?.token) {
        facets = null
        try {
            delay(180)
            val params = (filters + mapOf("q" to query)).entries.joinToString("&") { (key, value) ->
                val normalized = if (key == "price") when (value) { "max10" -> "max:10"; "max20" -> "max:20"; else -> value } else value
                "${java.net.URLEncoder.encode(key, "UTF-8")}=${java.net.URLEncoder.encode(normalized, "UTF-8")}"
            }
            facets = ApiClient(LocalStore(context).installationId).get<ApiEnvelope<Map<String, Map<String, Int>>>>("events/facets?$params", state.session?.token).data
        } catch (error: Exception) { if (error is CancellationException) throw error }
    }
    fun available(group: String, options: List<Pair<String, String>>) = options.filter { (key, _) -> facets == null || (facets?.get(group)?.get(key) ?: 0) > 0 }
    fun change(key: String, value: String) {
        val next = filters.toMutableMap()
        if (key == "municipality") next.remove("zone")
        if (value.isBlank()) next.remove(key) else next[key] = value
        apply(next, "Filtri personalizzati")
    }
    LaunchedEffect(state.session?.token) {
        try {
            choices = ApiClient(LocalStore(context).installationId).get<ApiEnvelope<TonightPayload>>("tonight?step=1", state.session?.token).data
        } catch (error: Exception) { if (error is CancellationException) throw error }
    }
    val latestFilters by rememberUpdatedState(filters)
    val latestApply by rememberUpdatedState(apply)
    fun locate() {
        if (locating) return
        scope.launch {
            locating = true
            status = "Cerco la tua posizione…"
            try {
                val location = withTimeout(15000) { deviceLocation(context) }
                latestApply(latestFilters + mapOf("near" to "${location.latitude},${location.longitude}", "radius_km" to "5", "sort" to "distance"), "Entro 5 km dalla tua posizione")
                status = null
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
    Column(Modifier.fillMaxWidth().padding(top = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        QuickFilterGroup("Quando", "preset", available("date", listOf("today" to "Oggi", "tonight" to "Stasera", "starting_soon" to "Inizia tra poco", "tomorrow" to "Domani", "weekend" to "Weekend")), filters, ::change)
        QuickFilterGroup("Categoria", "categories", available("category", choices.categories.map { it.slug to it.name }), filters, ::change)
        QuickFilterGroup("Prezzo", "price", available("price", listOf("free" to "Gratis", "donation" to "Offerta libera", "max10" to "Fino a 10 €", "max20" to "Fino a 20 €")), filters, ::change)
        QuickFilterGroup("Fascia oraria", "time_of_day", available("time", listOf("day" to "Di giorno", "evening" to "Di sera", "night" to "Di notte")), filters, ::change)
        Text("Caratteristiche", color = Muted)
        val features = listOf("outdoor" to "All’aperto", "accessible" to "Accessibile", "family" to "Adatto alle famiglie")
        val activeFeatures = features.filter { filters[it.first] == "1" }
        PeekTabRow {
            features.filter { it in activeFeatures || facets == null || (facets?.get("features")?.get(it.first) ?: 0) > 0 }.forEach { (key, label) ->
                val active = filters[key] == "1"
                FilterLabel(label + if (active) " ×" else "", active) { change(key, if (active) "" else "1") }
            }
        }
        OutlinedButton(onClick = { expanded = !expanded }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = RectangleShape) {
            Text("Filtri avanzati ${if (expanded) "⌃" else "⌄"}")
        }
        if (expanded) {
            QuickFilterGroup("Comune", "municipality", available("municipality", choices.municipalities.map { it to it }), filters, ::change)
            if (filters["municipality"] == "Padova") QuickFilterGroup("Quartiere", "zone", available("zone", choices.zones.map { it to it }), filters, ::change)
            QuickFilterGroup("Locale", "venue", available("venue", state.venues.mapNotNull { venue -> venue.slug?.let { it to venue.name } }), filters, ::change)
        }
        if (filters.containsKey("near")) {
            TextButton(onClick = { apply(filters - setOf("near", "radius_km", "sort"), "Filtri personalizzati") }) { Text("Togli la posizione ×") }
        } else {
            Text("La posizione serve a cercare eventi entro 5 km. Viene richiesta solo al tuo tap.", color = Muted)
            OutlinedButton(onClick = {
                if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) locate()
                else permission.launch(arrayOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION))
            }, enabled = !locating, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = RectangleShape) { Text("Usa la mia posizione") }
        }
        status?.let { Text(it, color = Muted) }
    }
}

@Composable
private fun QuickFilterGroup(label: String, key: String, options: List<Pair<String, String>>, filters: Map<String, String>, change: (String, String) -> Unit) {
    val selected = filters[key].orEmpty().split(',').filter { it.isNotBlank() }
    val visible = if (selected.isEmpty()) options else selected.map { value -> value to (options.find { it.first == value }?.second ?: value) }
    if (visible.isEmpty()) return
    Text(label, color = Muted)
    PeekTabRow {
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
