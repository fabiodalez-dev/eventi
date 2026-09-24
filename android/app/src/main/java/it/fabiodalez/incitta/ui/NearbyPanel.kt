package it.fabiodalez.incitta.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.TimeoutCancellationException
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeout
import java.util.Calendar

@Composable
internal fun NearbyPanel(state: AppUiState, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    val context = LocalContext.current
    val store = remember { LocalStore(context) }
    val api = remember { ApiClient(store.installationId) }
    val token = state.session?.token
    val scope = rememberCoroutineScope()
    var position by remember(token) { mutableStateOf(store.rememberedPosition()) }
    var events by remember(token) { mutableStateOf(emptyList<Occurrence>()) }
    var today by remember(token) { mutableStateOf(emptyList<Occurrence>()) }
    var radius by remember { mutableIntStateOf(store.nearbyRadius()) }
    var busy by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf<String?>(null) }
    val unavailable = stringResource(R.string.location_unavailable)
    val failed = stringResource(R.string.location_failed)
    val saved = stringResource(R.string.location_saved)

    suspend fun loadNearby(selectedRadius: Int = radius) {
        busy = true
        try {
            val near = position?.let { "&near=${it.lat},${it.lng}" } ?: ""
            val sections = api.get<ApiEnvelope<NearbyHome>>("home?radius_km=$selectedRadius$near", token).data.sections
            today = sections["today"].orEmpty()
            events = sections["nearby"].orEmpty().take(5)
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            status = failed
        } finally { busy = false }
    }
    suspend fun locate() {
        if (busy) return
        busy = true
        try {
            val current = withTimeout(10000) { deviceLocation(context) }
            val expires = Calendar.getInstance().apply { add(Calendar.MONTH, 6) }.timeInMillis / 1000
            val local = RememberedPosition(current.latitude, current.longitude,
                System.currentTimeMillis() / 1000, expires)
            store.rememberPosition(local)
            position = local
            status = saved
            if (token != null) {
                try {
                    position = api.post<ApiEnvelope<RememberedPosition>, RememberPositionRequest>("me/location", RememberPositionRequest(local.lat, local.lng), token).data
                    store.rememberPosition(position!!)
                } catch (error: Exception) {
                    if (error is CancellationException) throw error
                    status = failed
                }
            }
        } catch (error: Exception) {
            if (error is CancellationException && error !is TimeoutCancellationException) throw error
            status = unavailable
        } finally { busy = false }
        loadNearby()
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) scope.launch { locate() } else status = unavailable
    }
    LaunchedEffect(token) {
        busy = true
        if (token != null) {
            try {
                val remote = api.get<ApiEnvelope<RememberedPosition?>>("me/location", token).data?.takeIf { it.valid() }
                if (remote != null && remote.savedAt > (position?.savedAt ?: 0)) {
                    position = remote
                    store.rememberPosition(remote)
                } else if (position != null && position!!.savedAt > (remote?.savedAt ?: 0)) {
                    val local = position!!
                    position = api.post<ApiEnvelope<RememberedPosition>, RememberPositionRequest>("me/location", RememberPositionRequest(local.lat, local.lng, observedAt = local.savedAt), token).data
                    store.rememberPosition(position!!)
                }
            } catch (error: Exception) { if (error is CancellationException) throw error }
        }
        busy = false
        if (position != null && ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) locate()
        else loadNearby()
    }
    if (today.isEmpty() && events.isEmpty()) return
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (today.isNotEmpty()) {
            Text(stringResource(R.string.home_today), style = MaterialTheme.typography.headlineSmall)
            today.forEach { occurrence -> EventRow(occurrence, occurrence.occurrenceId in state.savedIds, onOpen, onSave) }
        }
        if (events.isNotEmpty()) {
        Text(stringResource(R.string.location_title), style = MaterialTheme.typography.headlineSmall)
        Text(stringResource(R.string.location_help), style = MaterialTheme.typography.bodySmall)
        NativeChoicePicker(title = stringResource(R.string.location_radius), value = radius.toString(),
            options = listOf("5" to stringResource(R.string.location_radius_km, 5), "10" to stringResource(R.string.location_radius_km, 10)), enabled = !busy,
            onSelect = { selected ->
                radius = selected.toInt()
                store.setNearbyRadius(radius)
                scope.launch { loadNearby(radius) }
            })
        OutlinedButton(enabled = !busy, onClick = {
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) scope.launch { locate() }
            else permission.launch(Manifest.permission.ACCESS_COARSE_LOCATION)
        }) {
            Text(stringResource(R.string.location_remember))
        }
        if (position != null) TextButton(enabled = !busy, onClick = {
            scope.launch {
                busy = true
                try {
                    if (token != null) api.delete<ApiEnvelope<RememberedPosition?>>("me/location", token)
                    store.forgetPosition()
                    position = null
                    status = null
                    loadNearby()
                } catch (error: Exception) {
                    if (error is CancellationException) throw error
                    status = failed
                } finally { busy = false }
            }
        }) { Text(stringResource(R.string.location_forget)) }
        status?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
        events.forEach { occurrence -> EventRow(occurrence, occurrence.occurrenceId in state.savedIds, onOpen, onSave) }
        }
    }
}
