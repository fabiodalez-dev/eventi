package it.fabiodalez.incitta.ui

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.NotificationsActive
import androidx.compose.material.icons.outlined.NotificationsNone
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/*
 * Le parti dell'interfaccia delle dieci funzioni per chi usa inCittà
 * (docs/PIANO-UTENTE-2026-10-23.md). Ogni stato si legge anche senza colori:
 * c'è sempre una parola o un'icona con la sua descrizione.
 */

internal fun personalZone(timezone: String?): ZoneId = runCatching { ZoneId.of(timezone ?: "Europe/Rome") }.getOrDefault(ZoneId.of("Europe/Rome"))

private fun localClock(value: String, zone: ZoneId): String? = runCatching {
    OffsetDateTime.parse(value).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN))
}.getOrNull()

private fun localDateTime(value: String, zone: ZoneId): String = runCatching {
    OffsetDateTime.parse(value).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("EEE d MMM yyyy · HH:mm", Locale.ITALIAN))
}.recoverCatching {
    java.time.Instant.parse(value).atZone(zone).format(DateTimeFormatter.ofPattern("EEE d MMM yyyy · HH:mm", Locale.ITALIAN))
}.getOrDefault(value)

internal fun shareLink(context: Context, chooser: String, subject: String, text: String) {
    runCatching {
        context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, subject)
            putExtra(Intent.EXTRA_TEXT, text)
        }, chooser))
    }
}

/* 5. Aperto adesso. */

@Composable
internal fun OpenNowLabel(venue: Venue, modifier: Modifier = Modifier) {
    val text = when (venue.openState()) {
        OpenState.UNKNOWN -> return
        OpenState.CLOSED -> stringResource(R.string.venue_closed_now)
        OpenState.OPEN -> stringResource(R.string.venue_open_now)
        OpenState.OPEN_UNTIL -> stringResource(R.string.venue_open_until, venue.openUntil.orEmpty())
    }
    val open = venue.openNow == true
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Icon(Icons.Outlined.Schedule, contentDescription = null, tint = if (open) Acid else Muted, modifier = Modifier.size(16.dp))
        Text(text, color = if (open) Acid else Muted, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
internal fun OpenNowFilter(state: it.fabiodalez.incitta.AppUiState, onToggle: (Boolean) -> Unit, onRetry: () -> Unit) {
    FilterChip(
        selected = state.venuesOpenNow,
        onClick = { onToggle(!state.venuesOpenNow) },
        label = { Text(stringResource(R.string.venues_filter_open_now)) },
        leadingIcon = if (state.venuesOpenNow) ({ Icon(Icons.Outlined.Check, contentDescription = null, modifier = Modifier.size(18.dp)) }) else null,
        shape = ControlShape,
        modifier = Modifier.heightIn(min = 48.dp),
    )
    if (!state.venuesOpenNow) return
    if (state.openNowLoading) Text(stringResource(R.string.venues_open_now_loading), color = Muted)
    state.openNowError?.let { error ->
        Text(stringResource(R.string.venues_open_now_error, error))
        TextButton(onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.venues_open_now_retry)) }
    }
}

/* 8. Nascondere un locale. */

@Composable
internal fun HideVenueAction(venue: Venue, hidden: Boolean, onChange: (Boolean) -> Unit) {
    val id = venue.id ?: return
    var confirm by remember(id) { mutableStateOf(false) }
    if (hidden) Text(stringResource(R.string.venue_hidden_notice), color = Muted, modifier = Modifier.padding(top = 12.dp))
    OutlinedButton(
        onClick = { if (hidden) onChange(false) else confirm = true },
        shape = ControlShape,
        modifier = Modifier.fillMaxWidth().padding(top = 12.dp).heightIn(min = 48.dp),
    ) {
        Icon(Icons.Outlined.VisibilityOff, contentDescription = null, modifier = Modifier.size(18.dp))
        Text(stringResource(if (hidden) R.string.venue_unhide else R.string.venue_hide), modifier = Modifier.padding(start = 8.dp))
    }
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false },
        title = { Text(stringResource(R.string.venue_hide_confirm_title)) },
        text = { Text(stringResource(R.string.venue_hide_confirm_body, venue.name)) },
        confirmButton = { TextButton(onClick = { confirm = false; onChange(true) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.venue_hide_confirm)) } },
        dismissButton = { TextButton(onClick = { confirm = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.venue_hide_cancel)) } },
    )
}

/* 9. Esigenze di accessibilità. */

@Composable
internal fun accessNeedLabel(key: String): String = when (key) {
    "step_free_entrance" -> stringResource(R.string.access_need_step_free_entrance)
    "accessible_toilets" -> stringResource(R.string.access_need_accessible_toilets)
    "reserved_seating" -> stringResource(R.string.access_need_reserved_seating)
    "tactile_path" -> stringResource(R.string.access_need_tactile_path)
    "assistance_on_request" -> stringResource(R.string.access_need_assistance_on_request)
    "guide_dog_allowed" -> stringResource(R.string.access_need_guide_dog_allowed)
    else -> key.replace('_', ' ')
}

@Composable
internal fun ProfileAccessStrip(needs: List<String>, onShowAll: () -> Unit) {
    val labels = needs.filter { it in ACCESS_NEEDS }.map { accessNeedLabel(it).lowercase(Locale.ITALIAN) }
    if (labels.isEmpty()) return
    Row(
        Modifier.fillMaxWidth().padding(top = 12.dp).border(1.dp, Rule, ControlShape).padding(horizontal = 12.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(stringResource(R.string.filter_profile_access, labels.joinToString(" · ")), modifier = Modifier.weight(1f))
        TextButton(onClick = onShowAll, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.filter_show_all)) }
    }
}

/* 13. Avvisami se tornano disponibili. */

@Composable
internal fun AvailabilityAlertButton(active: Boolean, busy: Boolean, onToggle: () -> Unit) {
    Text(stringResource(R.string.alert_sold_out), color = Muted, style = MaterialTheme.typography.labelLarge, modifier = Modifier.padding(top = 10.dp))
    OutlinedButton(
        onClick = onToggle,
        enabled = !busy,
        shape = ControlShape,
        modifier = Modifier.fillMaxWidth().padding(top = 6.dp).heightIn(min = 48.dp),
    ) {
        Icon(if (active) Icons.Outlined.NotificationsActive else Icons.Outlined.NotificationsNone, contentDescription = null, modifier = Modifier.size(18.dp))
        Text(stringResource(if (active) R.string.alert_active else R.string.alert_offer), modifier = Modifier.padding(start = 8.dp))
    }
}

/* 15. «Sono qui». */

@Composable
internal fun PresenceSection(
    occurrence: Occurrence,
    title: String,
    link: PresenceLink?,
    busy: Boolean,
    defaultName: String,
    timezone: String?,
    onCreate: (String, String, (PresenceLink) -> Unit) -> Unit,
    onStop: () -> Unit,
) {
    val context = LocalContext.current
    var asking by remember(occurrence.occurrenceId) { mutableStateOf(false) }
    var name by remember(occurrence.occurrenceId) { mutableStateOf(defaultName) }
    var note by remember(occurrence.occurrenceId) { mutableStateOf("") }
    val chooser = stringResource(R.string.presence_chooser)
    val place = occurrence.placeName() ?: title
    val shareText = stringResource(R.string.presence_share_text, title, place)
    fun share(target: PresenceLink) = shareLink(context, chooser, title, "$shareText\n${target.url}")
    val active = link?.takeIf { it.activeAt() }
    if (active == null) {
        OutlinedButton(onClick = { asking = true }, enabled = !busy, shape = ControlShape, modifier = Modifier.fillMaxWidth().padding(top = 10.dp).heightIn(min = 48.dp)) {
            Icon(Icons.Outlined.Share, contentDescription = null, modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.presence_offer), modifier = Modifier.padding(start = 8.dp))
        }
    } else {
        val until = active.expiresAt?.let { localClock(it, personalZone(timezone)) }
        Text(if (until != null) stringResource(R.string.presence_active_until, until) else stringResource(R.string.presence_active), color = Acid, modifier = Modifier.padding(top = 10.dp))
        OutlinedButton(onClick = { share(active) }, enabled = !busy, shape = ControlShape, modifier = Modifier.fillMaxWidth().padding(top = 6.dp).heightIn(min = 48.dp)) {
            Text(stringResource(R.string.presence_share_again))
        }
        TextButton(onClick = onStop, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.presence_stop)) }
    }
    if (asking) AlertDialog(
        onDismissRequest = { asking = false },
        title = { Text(stringResource(R.string.presence_offer)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.presence_help), color = Muted)
                OutlinedTextField(name, { name = it.take(60) }, label = { Text(stringResource(R.string.presence_name)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(note, { note = it.take(200) }, label = { Text(stringResource(R.string.presence_note)) }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(onClick = { asking = false; onCreate(name, note) { share(it) } }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.presence_create))
            }
        },
        dismissButton = { TextButton(onClick = { asking = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.presence_cancel)) } },
    )
}

/* 11. Biglietti senza rete. */

@Composable
internal fun OfflineTicketsNotice(savedAt: String, timezone: String?) {
    Column(Modifier.fillMaxWidth().border(1.dp, Rule, ControlShape).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.ticket_offline_updated, localDateTime(savedAt, personalZone(timezone))), style = MaterialTheme.typography.titleSmall)
        Text(stringResource(R.string.ticket_offline_notice), color = Muted)
    }
}

/* 12. Un biglietto per ciascun partecipante. */

@Composable
internal fun TicketShareActions(ticket: AdmissionTicket, eventTitle: String, busy: Boolean, onSend: (String, () -> Unit) -> Unit) {
    if (!ticket.shareable()) return
    val context = LocalContext.current
    val url = ticket.shareUrl.orEmpty()
    val chooser = stringResource(R.string.ticket_share_chooser)
    val text = stringResource(R.string.ticket_share_text, eventTitle, url)
    var emailOpen by remember(ticket.id) { mutableStateOf(false) }
    var email by remember(ticket.id) { mutableStateOf("") }
    var consent by remember(ticket.id) { mutableStateOf(false) }
    OutlinedButton(onClick = { shareLink(context, chooser, eventTitle, text) }, shape = ControlShape, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Icon(Icons.Outlined.Share, contentDescription = null, modifier = Modifier.size(18.dp))
        Text(stringResource(R.string.ticket_share_person), modifier = Modifier.padding(start = 8.dp))
    }
    Text(stringResource(R.string.ticket_share_hint), color = Muted)
    TextButton(onClick = { emailOpen = !emailOpen }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(if (emailOpen) R.string.ticket_send_email_close else R.string.ticket_send_email_toggle))
    }
    if (!emailOpen) return
    OutlinedTextField(
        email, { email = it.take(254) },
        label = { Text(stringResource(R.string.ticket_send_email_field)) },
        singleLine = true,
        enabled = !busy,
        isError = email.isNotBlank() && !plausibleEmail(email),
        supportingText = if (email.isNotBlank() && !plausibleEmail(email)) ({ Text(stringResource(R.string.ticket_send_invalid)) }) else null,
        keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Email),
        shape = ControlShape,
        modifier = Modifier.fillMaxWidth(),
    )
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(enabled = !busy) { consent = !consent },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(consent, { consent = it }, enabled = !busy)
        Text(stringResource(R.string.ticket_send_consent), modifier = Modifier.weight(1f))
    }
    Text(stringResource(R.string.ticket_send_privacy), color = Muted)
    Button(
        onClick = { onSend(email) { email = ""; consent = false; emailOpen = false } },
        enabled = consent && plausibleEmail(email) && !busy,
        shape = ControlShape,
        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
    ) { Text(stringResource(R.string.ticket_send_button)) }
}

/* 14. Le mie serate. */

@Composable
internal fun AttendedScreen(
    session: Session?,
    padding: PaddingValues,
    load: suspend (Int) -> ApiEnvelope<List<AttendedEvening>>,
    onOpen: (Long) -> Unit,
    onVenue: (String) -> Unit,
    onOrganizer: (String) -> Unit,
    onBack: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var items by remember(session?.user?.id) { mutableStateOf<List<AttendedEvening>>(emptyList()) }
    var nextPage by remember(session?.user?.id) { mutableStateOf<Int?>(1) }
    var loaded by remember(session?.user?.id) { mutableStateOf(false) }
    var busy by remember(session?.user?.id) { mutableStateOf(false) }
    var error by remember(session?.user?.id) { mutableStateOf<String?>(null) }
    val zone = personalZone(session?.user?.timezone)
    suspend fun more() {
        val page = nextPage ?: return
        if (busy) return
        busy = true
        error = null
        try {
            val response = load(page)
            items = (items + response.data).distinctBy(AttendedEvening::occurrenceId)
            nextPage = response.meta?.nextPage?.takeIf { it > page }
            loaded = true
        } catch (failure: Exception) {
            if (failure is CancellationException) throw failure
            error = requestFailureMessage(failure)
        } finally { busy = false }
    }
    LaunchedEffect(session?.user?.id) { if (session != null && !loaded) more() }
    LazyColumn(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()), contentPadding = PaddingValues(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            TextButton(onClick = onBack, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.ticket_profile)) }
            Text(stringResource(R.string.attended_title), style = MaterialTheme.typography.displayMedium)
            Text(stringResource(R.string.attended_subtitle), color = Muted)
        }
        if (session == null) {
            item { Text(stringResource(R.string.attended_login)) }
            return@LazyColumn
        }
        if (busy && items.isEmpty()) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        error?.let { message ->
            item {
                Text(stringResource(R.string.attended_error, message))
                TextButton(onClick = { scope.launch { more() } }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.attended_retry)) }
            }
        }
        if (loaded && items.isEmpty() && error == null) item { Text(stringResource(R.string.attended_empty), color = Muted) }
        items(items, key = AttendedEvening::occurrenceId) { evening ->
            Column(Modifier.fillMaxWidth()) {
                HorizontalDivider(color = Rule)
                val open = stringResource(R.string.attended_open)
                Column(
                    Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable { onOpen(evening.occurrenceId) }.semantics { contentDescription = "$open: ${evening.title}" }.padding(vertical = 8.dp),
                ) {
                    Text(localDateTime(evening.startsAt, zone), color = Acid, style = MaterialTheme.typography.labelLarge)
                    Text(evening.title, style = MaterialTheme.typography.titleLarge)
                    if (evening.checkedIn > 0) Text(pluralStringResource(R.plurals.attended_checked_in, evening.checkedIn, evening.checkedIn), color = Muted)
                }
                evening.venue?.let { venue ->
                    val slug = venue.slug
                    if (slug != null) TextButton(onClick = { onVenue(slug) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.attended_venue, venue.name)) }
                    else Text(stringResource(R.string.attended_venue, venue.name), color = Muted)
                }
                evening.organizer?.let { organizer ->
                    val slug = organizer.slug
                    if (slug != null) TextButton(onClick = { onOrganizer(slug) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.attended_organizer, organizer.name)) }
                    else Text(stringResource(R.string.attended_organizer, organizer.name), color = Muted)
                }
            }
        }
        if (items.isNotEmpty() && nextPage != null && error == null) item {
            OutlinedButton(onClick = { scope.launch { more() } }, enabled = !busy, shape = ControlShape, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.attended_more))
            }
        }
    }
}
