package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.EventChoice
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay

/*
 * «Scegli un evento»: il selettore di eventi dei pannelli (`EventSelect`)
 * nell'app. Ogni voce ha la locandina 72×96, il titolo, la data leggibile
 * («sab 22 nov 2026, 21:00 · Officina dei Portici + altre 2 date») e il locale; le voci sono
 * divise in «Prossimi» e «Passati», con la ricerca per titolo e per locale.
 *
 * Il foglio non sa da dove arrivano gli eventi: chi lo apre passa `search`,
 * la chiamata al proprio endpoint (le statistiche `GET S/analytics/events`;
 * sponsorizzazioni e grafiche social il loro, con la stessa forma). Così
 * ogni pagina mostra solo gli eventi che il server le lascia scegliere.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun EventPickerSheet(
    search: suspend (String) -> EventChoiceGroups,
    onPick: (EventChoice) -> Unit,
    onDismiss: () -> Unit,
    title: String = stringResource(R.string.event_picker_title),
    hint: String? = null,
    excludeId: Long? = null,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var query by rememberSaveable { mutableStateOf("") }
    var state by remember { mutableStateOf<Loadable<EventChoiceGroups>>(Loadable.Loading) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(query, attempt) {
        state = Loadable.Loading
        // Si aspetta che la persona smetta di scrivere: una richiesta per parola, non per lettera.
        if (query.isNotEmpty()) delay(SEARCH_DELAY_MILLIS)
        state = try {
            val groups = search(query).let { found -> if (excludeId == null) found else found.copy(upcoming = found.upcoming.filter { it.id != excludeId }, past = found.past.filter { it.id != excludeId }) }
            if (groups.isEmpty) Loadable.Empty else Loadable.Ready(groups)
        } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f).semantics { heading() })
                IconButton(onClick = onDismiss, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.event_picker_close))
                }
            }
            hint?.let { Text(it, color = Muted) }
            OutlinedTextField(
                value = query,
                onValueChange = { query = it.take(100) },
                label = { Text(stringResource(R.string.event_picker_search)) },
                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                modifier = Modifier.fillMaxWidth(),
            )
            LoadState(state, onRetry = { attempt++ }, emptyText = stringResource(R.string.event_picker_empty)) { groups ->
                LazyColumn(Modifier.fillMaxWidth().heightIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (groups.upcoming.isNotEmpty()) {
                        item { GroupTitle(stringResource(R.string.event_picker_upcoming)) }
                        items(groups.upcoming, key = { "u${it.id}" }) { EventChoiceRow(it, onClick = { onPick(it) }) }
                    }
                    if (groups.past.isNotEmpty()) {
                        item { GroupTitle(stringResource(R.string.event_picker_past)) }
                        items(groups.past, key = { "p${it.id}" }) { EventChoiceRow(it, onClick = { onPick(it) }) }
                    }
                }
            }
        }
    }
}

@Composable
private fun GroupTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp).semantics { heading() })
}

/**
 * Una voce: la stessa riga del selettore dei pannelli. Si usa anche fuori dal
 * foglio, per dire di quale evento si parla (in testa alla pagina delle
 * statistiche di un evento).
 */
@Composable
internal fun EventChoiceRow(choice: EventChoice, onClick: (() -> Unit)? = null) {
    val whenText = eventChoiceWhen(choice) ?: stringResource(R.string.analytics_no_dates)
    val more = choice.more.takeIf { it > 0 }?.let { pluralStringResource(R.plurals.event_picker_more_dates, it, it) }
    val meta = listOfNotNull(whenText, choice.venue?.takeIf { it.isNotBlank() }).joinToString(" · ") + (more?.let { " $it" } ?: "")
    val spoken = listOf(choice.title, meta).joinToString(". ")
    Row(
        Modifier.fillMaxWidth()
            .then(if (onClick != null) Modifier.heightIn(min = 56.dp).clickable(role = Role.Button, onClick = onClick) else Modifier)
            .padding(vertical = 6.dp)
            .clearAndSetSemantics { contentDescription = spoken },
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PosterImage(choice.poster?.thumb ?: choice.poster?.card ?: choice.poster?.full, Modifier.size(width = 72.dp, height = 96.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(choice.title, style = MaterialTheme.typography.titleMedium, maxLines = 3, overflow = TextOverflow.Ellipsis)
            Text(meta, style = MaterialTheme.typography.bodyMedium, color = Muted)
        }
    }
}

private val choiceDateFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)

/** «sab 22 nov 2026, 21:00» nel fuso della città dell'evento; `null` se l'evento non ha date. */
internal fun eventChoiceWhen(choice: EventChoice): String? {
    val iso = choice.startsAt ?: return null
    val zone = choice.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: ZoneId.of("Europe/Rome")
    return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(choiceDateFormat) }.getOrDefault(iso)
}

private const val SEARCH_DELAY_MILLIS = 300L
