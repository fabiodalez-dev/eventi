package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.PromoChoice
import it.fabiodalez.incitta.data.editorial.PromoEvent
import it.fabiodalez.incitta.data.editorial.PromoProgress
import it.fabiodalez.incitta.data.editorial.PromoProgressDetail
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipBadge
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipEventLine
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipMetric
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipSection
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipColor
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipMoment
import java.time.ZoneId
import kotlinx.serialization.json.Json

/*
 * I pezzi comuni della promozione: il fuso di una riga, l'avanzamento (la
 * colonna e la scheda del pannello), le scelte a pulsanti con la riga di
 * aiuto, la scelta di un evento sponsorizzabile.
 */

internal val promoJson = Json { ignoreUnknownKeys = true; encodeDefaults = true }

/** Il fuso di una riga; quello di Padova se il server ne manda uno che il telefono non conosce. */
internal fun promoZone(id: String?): ZoneId = id?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: ZoneId.of(EDITORIAL_REPORT_ZONE)

/** «sab 22 nov, 21:00 — dom 21 dic, 21:00» nel fuso della città. */
internal fun promoPeriod(start: String?, end: String?, zone: ZoneId): String =
    listOfNotNull(sponsorshipMoment(start, zone), sponsorshipMoment(end, zone)).joinToString(" — ")

/** La colonna «Avanzamento»: numeri, barra con la tacca dell'atteso, stato. */
@Composable
internal fun PromoProgressLine(progress: PromoProgress) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(progress.summary, style = MaterialTheme.typography.bodyMedium)
            SponsorshipBadge(progress.stateLabel, progress.color)
        }
        progress.percent?.let { percent ->
            val label = stringResource(R.string.editorial_promo_progress_bar, percent.toInt())
            LinearProgressIndicator(
                progress = { (percent / 100.0).toFloat().coerceIn(0f, 1f) },
                color = sponsorshipColor(progress.color),
                modifier = Modifier.fillMaxWidth().semantics { contentDescription = label },
            )
        }
    }
}

/** La scheda «Avanzamento»: stato, fine vera, frase, barra, tre numeri, giorno per giorno, eventi. */
@Composable
internal fun PromoProgressDetailView(detail: PromoProgressDetail) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        SponsorshipSection(stringResource(R.string.editorial_promo_progress_title), stringResource(R.string.editorial_promo_progress_lead))
        val progress = detail.progress
        if (progress == null) {
            Text(detail.empty ?: stringResource(R.string.editorial_promo_progress_none), color = Muted)
            return@Column
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SponsorshipBadge(progress.stateLabel, progress.color)
            detail.trueEnd?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
        detail.sentence?.let { Text(it) }
        progress.percent?.let { PromoProgressLine(progress) }
        detail.legend?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        detail.cards.forEach { card -> SponsorshipMetric(card.label, card.value, card.hint) }
        detail.provisional?.let { Text(it, color = Muted) }
        if (detail.daily.isNotEmpty() || detail.dailyEmpty != null) {
            Text(stringResource(R.string.editorial_promo_progress_daily), style = MaterialTheme.typography.titleSmall)
            detail.dailyEmpty?.let { Text(it, color = Muted) }
            detail.daily.forEach { row ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(row.label)
                    Text(row.text, color = Muted)
                }
                HorizontalDivider()
            }
        }
        if (detail.events.isNotEmpty()) {
            Text(stringResource(R.string.editorial_promo_progress_events), style = MaterialTheme.typography.titleSmall)
            detail.eventsLead?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            detail.events.forEach { row ->
                val event = row.event
                if (event != null) {
                    SponsorshipEventLine(event.asSponsorshipEvent(), promoZone(event.timezone)) {
                        Text("${row.numbers} · ${row.share}", color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                } else {
                    Text(row.eventGone ?: stringResource(R.string.editorial_promo_event_gone), color = Muted)
                }
            }
        }
    }
}

/** Una scelta fra poche voci, ognuna con la sua riga di aiuto (i `Radio` del pannello). */
@Composable
internal fun PromoRadioGroup(
    label: String,
    choices: List<PromoChoice>,
    selected: String?,
    onSelect: (String) -> Unit,
    error: String? = null,
    disabled: Set<String> = emptySet(),
) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleMedium)
        choices.forEach { choice ->
            val enabled = choice.value !in disabled
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp)
                    .selectable(selected = selected == choice.value, enabled = enabled, role = Role.RadioButton, onClick = { onSelect(choice.value) }),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                RadioButton(selected = selected == choice.value, onClick = null, enabled = enabled)
                Column(Modifier.padding(start = 8.dp).weight(1f)) {
                    Text(choice.label, color = if (enabled) MaterialTheme.colorScheme.onSurface else Muted)
                    choice.help?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Una riga di scelta a pastiglie (stati, collocazioni nei filtri). */
@Composable
internal fun PromoChips(label: String, choices: List<PromoChoice>, selected: String?, onSelect: (String) -> Unit, error: String? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleMedium)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            choices.forEach { choice ->
                FilterChip(selected = selected == choice.value, onClick = { onSelect(choice.value) }, label = { Text(choice.label) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/**
 * La scelta di un evento sponsorizzabile (il selettore del pannello):
 * ricerca per titolo o locale, ogni voce con locandina, data, locale e
 * «già sponsorizzato».
 */
@Composable
internal fun PromoEventPickerDialog(
    events: List<PromoEvent>,
    busy: Boolean,
    onSearch: (String) -> Unit,
    onChoose: (PromoEvent) -> Unit,
    onDismiss: () -> Unit,
) {
    var query by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.editorial_promo_event_pick)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(query, { query = it.take(120); onSearch(query) }, label = { Text(stringResource(R.string.editorial_promo_event_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                if (!busy && events.isEmpty()) Text(stringResource(R.string.editorial_promo_event_none), color = Muted)
                events.forEach { event ->
                    Surface(onClick = { onChoose(event) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                        Column(Modifier.padding(vertical = 6.dp)) {
                            SponsorshipEventLine(event.asSponsorshipEvent(), promoZone(event.timezone)) {
                                if (event.alreadySponsored) Text(stringResource(R.string.editorial_promo_event_sponsored), color = MaterialTheme.colorScheme.tertiary, style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                    HorizontalDivider()
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}
