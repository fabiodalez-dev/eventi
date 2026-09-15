package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.EventWeather
import kotlinx.coroutines.CancellationException

@Composable
internal fun EventWeatherSection(id: Long, load: suspend (Long) -> EventWeather) {
    var weather by remember(id) { mutableStateOf<EventWeather?>(null) }
    var failed by remember(id) { mutableStateOf(false) }
    LaunchedEffect(id) {
        try { weather = load(id) }
        catch (e: CancellationException) { throw e }
        catch (_: Exception) { failed = true }
    }
    if (weather?.available != true) return
    /*
     * Il titolo lo mette la sezione, non il contenuto.
     *
     * Prima questo blocco viveva dentro "TUTTE LE DATE" e si intestava da
     * solo; ora che e' una sezione a se' avrebbe due titoli uno sopra
     * l'altro. L'uscita anticipata qui sopra e' anche cio' che evita un
     * riquadro vuoto quando la previsione non c'e'.
     */
    DetailSection("METEO") {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
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
                    if (data.temperatureAtStart != null) {
                        Text("Temperatura ${if (data.temperatureEstimated) "stimata" else "prevista"} alle ${data.startTime.orEmpty()}")
                        Text("${data.temperatureAtStart}°", style = MaterialTheme.typography.headlineSmall)
                    } else Text(listOfNotNull(data.minimum, data.maximum).joinToString(" / ") { "$it°" }, style = MaterialTheme.typography.headlineSmall)
                }
            }
            if (data.temperatureAtStart != null) {
                val range = listOfNotNull(data.minimum, data.maximum).joinToString(" / ") { "$it°" }
                if (range.isNotEmpty()) Text("Min / max: $range")
            }
            Text("Previsione della giornata · ${data.date.orEmpty()}")
            data.rain?.let { Text("Probabilità di pioggia: $it%") }
            data.wind?.let { Text("Vento: $it km/h") }
            if (data.indicative) Text("Previsione indicativa: ricontrolla avvicinandoti alla data.", style = MaterialTheme.typography.bodySmall)
        }
    }
    }
}
