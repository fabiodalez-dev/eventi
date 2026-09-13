package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.EventWeather
import kotlinx.coroutines.CancellationException

@Composable
internal fun EventWeatherSection(id: Long, load: suspend (Long) -> EventWeather) {
    var weather by remember(id) { mutableStateOf<EventWeather?>(null) }
    var failed by remember(id) { mutableStateOf(false) }
    val uri = LocalUriHandler.current
    LaunchedEffect(id) {
        try { weather = load(id) }
        catch (e: CancellationException) { throw e }
        catch (_: Exception) { failed = true }
    }
    if (weather?.available != true) return
    Column(Modifier.fillMaxWidth().padding(vertical = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("METEO PER L’EVENTO", style = MaterialTheme.typography.titleMedium)
        val data = weather
        if (data == null) Text(if (failed) "Il meteo non è disponibile al momento." else "Caricamento delle previsioni…")
        else if (!data.available) Text(data.message ?: "Previsioni non ancora disponibili per questa data.")
        else {
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Icon(when(data.icon) {
                    "sun" -> Icons.Outlined.WbSunny
                    "partly-cloudy" -> Icons.Outlined.WbCloudy
                    "rainy" -> Icons.Outlined.Grain
                    "snow" -> Icons.Outlined.AcUnit
                    "storm" -> Icons.Outlined.Thunderstorm
                    "fog" -> Icons.Outlined.BlurOn
                    else -> Icons.Outlined.Cloud
                }, data.description, Modifier.size(48.dp), tint = MaterialTheme.colorScheme.primary)
                Column {
                    Text(data.description, style = MaterialTheme.typography.titleMedium)
                    Text(listOfNotNull(data.minimum, data.maximum).joinToString(" / ") { "$it°" }, style = MaterialTheme.typography.headlineSmall)
                }
            }
            Text("Previsione della giornata · ${data.date.orEmpty()}")
            data.rain?.let { Text("Probabilità di pioggia: $it%") }
            data.wind?.let { Text("Vento: $it km/h") }
            if (data.indicative) Text("Previsione indicativa: ricontrolla avvicinandoti alla data.", style = MaterialTheme.typography.bodySmall)
            TextButton(onClick = { uri.openUri("https://open-meteo.com/") }) { Text("Open-Meteo · CC BY 4.0") }
        }
    }
}
