package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Data e ora di un evento si leggono e si scrivono sempre nel fuso della città
 * del locale o dell'organizzazione, mai in quello del telefono: chi prepara a
 * Lisbona la serata di Padova deve vedere le 21:00 di Padova. Il server
 * riceve l'istante con il suo scarto (`2026-12-05T21:00:00+01:00`).
 */

/** L'ora locale della città per un istante del server; `null` se vuoto o illeggibile. */
internal fun cityLocalDateTime(iso: String?, zone: ZoneId): LocalDateTime? {
    if (iso.isNullOrBlank()) return null
    return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).toLocalDateTime() }
        .recoverCatching { Instant.parse(iso).atZone(zone).toLocalDateTime() }
        .getOrNull()
}

/**
 * L'istante da mandare al server per un'ora scelta nella città. Nell'ora che
 * manca al passaggio all'ora legale vale la regola di `java.time` (si va
 * avanti); nell'ora doppia del ritorno si prende la prima.
 */
internal fun cityIso(local: LocalDateTime, zone: ZoneId): String =
    local.atZone(zone).withEarlierOffsetAtOverlap().toOffsetDateTime().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)

/* Il selettore di Material lavora in millisecondi UTC a mezzanotte. */
internal fun pickerMillis(date: LocalDate): Long = date.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli()
internal fun pickerDate(millis: Long): LocalDate = Instant.ofEpochMilli(millis).atZone(ZoneOffset.UTC).toLocalDate()

private val dateFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ITALIAN)
private val timeFormat = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

/**
 * Due pulsanti, data e ora, con la riga «Ora di Padova» sotto. `value` è già
 * l'ora della città (vedi `cityLocalDateTime`). Senza una data scelta l'ora
 * parte dalle 21:00, l'orario più comune per una serata.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CityDateTimeField(
    label: String,
    value: LocalDateTime?,
    zone: ZoneId,
    cityName: String?,
    onChange: (LocalDateTime?) -> Unit,
    error: String? = null,
    enabled: Boolean = true,
    clearable: Boolean = false,
) {
    var pickDate by remember { mutableStateOf(false) }
    var pickTime by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, style = MaterialTheme.typography.titleMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(enabled = enabled, onClick = { pickDate = true }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                Text(value?.toLocalDate()?.format(dateFormat) ?: stringResource(R.string.management_datetime_pick_date))
            }
            OutlinedButton(enabled = enabled && value != null, onClick = { pickTime = true }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                Text(value?.toLocalTime()?.format(timeFormat) ?: stringResource(R.string.management_datetime_time))
            }
        }
        Text(stringResource(R.string.management_datetime_zone, cityName ?: zone.id), color = Muted)
        if (clearable && value != null) {
            TextButton(enabled = enabled, onClick = { onChange(null) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_datetime_clear))
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
    if (pickDate) {
        val state = rememberDatePickerState(initialSelectedDateMillis = (value?.toLocalDate() ?: LocalDate.now(zone)).let(::pickerMillis))
        DatePickerDialog(
            onDismissRequest = { pickDate = false },
            confirmButton = {
                TextButton(onClick = {
                    pickDate = false
                    state.selectedDateMillis?.let { millis -> onChange(pickerDate(millis).atTime(value?.toLocalTime() ?: LocalTime.of(21, 0))) }
                }) { Text(stringResource(R.string.management_datetime_done)) }
            },
            dismissButton = { TextButton(onClick = { pickDate = false }) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = state) }
    }
    if (pickTime && value != null) {
        val state = rememberTimePickerState(initialHour = value.hour, initialMinute = value.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = { pickTime = false },
            title = { Text(stringResource(R.string.management_datetime_pick_time)) },
            text = { TimePicker(state = state) },
            confirmButton = {
                TextButton(onClick = { pickTime = false; onChange(value.toLocalDate().atTime(state.hour, state.minute)) }) {
                    Text(stringResource(R.string.management_datetime_done))
                }
            },
            dismissButton = { TextButton(onClick = { pickTime = false }) { Text(stringResource(R.string.management_cancel)) } },
        )
    }
}
