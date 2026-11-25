package it.fabiodalez.incitta.ui.management.ticketing

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.CheckinStaff
import it.fabiodalez.incitta.data.DateCounts
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.management.BOOKER_FIELDS
import it.fabiodalez.incitta.data.management.BOOKER_FIELD_MODES
import it.fabiodalez.incitta.data.management.BOOKING_FILTERS
import it.fabiodalez.incitta.data.management.BookingSettingsDraft
import it.fabiodalez.incitta.data.management.ExportKind
import it.fabiodalez.incitta.data.management.ManagedBooking
import it.fabiodalez.incitta.data.management.ManagedTicket
import it.fabiodalez.incitta.data.management.QUESTION_TYPES
import it.fabiodalez.incitta.data.management.QuestionDraft
import it.fabiodalez.incitta.data.management.SentMessage
import it.fabiodalez.incitta.ui.Acid
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.SecureWindow
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Le schede della data per chi la gestisce, una per sezione della pagina
 * `/gestione-biglietti/{data}` del sito, e il riquadro «Ticketing inCittà»
 * dell'evento. I testi sono quelli del sito.
 */

/** Un campo di ricerca con il pulsante «Cerca»; la ricerca parte anche dal tasto della tastiera. */
@Composable
internal fun SearchField(current: String, label: String, action: String, enabled: Boolean, onSearch: (String) -> Unit) {
    var text by rememberSaveable(current) { mutableStateOf(current) }
    OutlinedTextField(
        text, { text = it.take(120) }, label = { Text(label) }, singleLine = true, enabled = enabled,
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search), keyboardActions = KeyboardActions(onSearch = { onSearch(text) }),
        modifier = Modifier.fillMaxWidth(),
    )
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(enabled = enabled, onClick = { onSearch(text) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(action) }
        if (current.isNotEmpty()) TextButton(enabled = enabled, onClick = { text = ""; onSearch("") }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_search_clear))
        }
    }
}

/** La locandina piccola di una data; niente se manca. */
@Composable
internal fun PosterThumb(url: String?) {
    if (url.isNullOrBlank()) return
    AsyncImage(url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.width(64.dp).height(80.dp))
}

/** «12/40 posti occupati», «12 posti occupati, senza limite», con la lista d'attesa se c'è. */
@Composable
internal fun seatsLine(counts: DateCounts): String {
    val seats = counts.capacity?.let { stringResource(R.string.management_seats, counts.confirmed, it) } ?: stringResource(R.string.management_seats_unlimited, counts.confirmed)
    return if (counts.waitlisted > 0) seats + " · " + stringResource(R.string.management_waitlisted_count, counts.waitlisted) else seats
}

/** Un avviso in evidenza, come le note del sito. */
@Composable
internal fun Notice(title: String, text: String, alert: Boolean = false) {
    val color = if (alert) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.outline
    Column(Modifier.fillMaxWidth().border(1.dp, color).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium, color = if (alert) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface)
        Text(text, color = Muted)
    }
}

/** I numeri della data: posti, rimasti, i cinque conteggi e la quota di assenze. */
@Composable
internal fun DateNumbers(date: ManagedDate) {
    val counts = date.counts ?: return
    Text(stringResource(R.string.management_numbers), style = MaterialTheme.typography.titleLarge)
    Text(seatsLine(counts), style = MaterialTheme.typography.titleMedium)
    date.availability?.let { availability ->
        Text(availability.remaining?.let { stringResource(R.string.ticket_remaining, it) } ?: stringResource(R.string.management_capacity_unknown), color = Muted)
    }
    listOf(
        R.string.management_stat_confirmed to counts.confirmed,
        R.string.management_stat_checked_in to counts.checkedIn,
        R.string.management_stat_waitlisted to counts.waitlisted,
        R.string.management_stat_cancelled to counts.cancelled,
        R.string.management_stat_no_show to counts.noShow,
    ).forEach { (label, value) ->
        Row(Modifier.fillMaxWidth()) { Text(stringResource(label), color = Muted, modifier = Modifier.weight(1f)); Text(value.toString()) }
    }
    val present = counts.checkedIn + counts.noShow
    if (counts.noShow > 0 && present > 0) Text(stringResource(R.string.management_no_show_rate, Math.round(counts.noShow * 100.0 / present).toInt()), color = Muted)
}

/** Le schede della data; scorrono in orizzontale perché sono sette. */
@Composable
internal fun DateTabs(selected: DateTab, onSelect: (DateTab) -> Unit) {
    val labels = mapOf(
        DateTab.Checkin to R.string.management_tab_checkin, DateTab.Bookings to R.string.management_tab_bookings,
        DateTab.Settings to R.string.management_tab_settings, DateTab.Messages to R.string.management_tab_messages,
        DateTab.Staff to R.string.management_tab_staff, DateTab.Details to R.string.management_tab_details,
        DateTab.Export to R.string.management_tab_export,
    )
    ScrollableTabRow(selectedTabIndex = selected.ordinal, edgePadding = 0.dp) {
        DateTab.entries.forEach { tab ->
            Tab(selected = tab == selected, onClick = { onSelect(tab) }, modifier = Modifier.heightIn(min = 48.dp), text = { Text(stringResource(labels.getValue(tab))) })
        }
    }
}

/* ------------------------------------------------------------------ */
/* Prenotazioni                                                        */
/* ------------------------------------------------------------------ */

@Composable
internal fun BookingsSection(viewModel: BookingsViewModel, date: ManagedDate, onCheckedIn: (String) -> Unit) {
    // Nomi, recapiti e codici: niente screenshot né anteprima tra le app recenti.
    SecureWindow()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var cancelling by remember { mutableStateOf<Pair<ManagedBooking, ManagedTicket?>?>(null) }
    Text(stringResource(R.string.management_tab_bookings), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_bookings_hint), color = Muted)
    SearchField(state.query, stringResource(R.string.management_bookings_search), stringResource(R.string.management_dates_search_action), enabled = true, onSearch = viewModel::search)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        (listOf<String?>(null) + BOOKING_FILTERS).forEach { status ->
            FilterChip(state.status == status, { viewModel.filter(status) },
                label = { Text(stringResource(bookingFilterLabel(status)) + " · " + state.counts.of(status)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
    if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth())
    state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
    state.message?.let { Text(it.resolve(), color = Acid) }
    val empty = stringResource(when {
        state.query.isNotEmpty() -> R.string.management_bookings_empty_search
        state.status != null -> R.string.management_bookings_empty_filter
        else -> R.string.management_bookings_empty
    })
    LoadState(state.list, onRetry = viewModel::reload, emptyText = empty) { bookings ->
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            bookings.forEach { booking ->
                HorizontalDivider()
                BookingCard(booking, date, state.busy,
                    onCheckIn = { ticket -> viewModel.checkIn(ticket); ticket.code?.let(onCheckedIn) },
                    onCancel = { ticket -> cancelling = booking to ticket })
            }
            state.nextPage?.let { OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_more)) } }
        }
    }
    cancelling?.let { (booking, ticket) ->
        CancelBookingDialog(booking, ticket, enabled = !state.busy,
            onConfirm = { reason -> cancelling = null; viewModel.cancel(booking, ticket, reason) },
            onDismiss = { cancelling = null })
    }
}

internal fun bookingFilterLabel(status: String?): Int = when (status) {
    "confirmed" -> R.string.management_filter_confirmed
    "checked_in" -> R.string.management_filter_checked_in
    "waitlisted" -> R.string.management_filter_waitlisted
    "cancelled" -> R.string.management_filter_cancelled
    else -> R.string.management_filter_all
}

internal fun bookingStatusLabel(status: String): Int = when (status) {
    "confirmed" -> R.string.management_booking_status_confirmed
    "waitlisted" -> R.string.management_booking_status_waitlisted
    "cancelled" -> R.string.management_booking_status_cancelled
    else -> R.string.management_booking_status_other
}

internal fun ticketStatusLabel(status: String): Int = when (status) {
    "valid" -> R.string.management_ticket_status_valid
    "checked_in" -> R.string.management_ticket_status_checked_in
    "waitlisted" -> R.string.management_ticket_status_waitlisted
    "cancelled" -> R.string.management_ticket_status_cancelled
    "expired" -> R.string.management_ticket_status_expired
    "no_show" -> R.string.management_ticket_status_no_show
    else -> R.string.management_booking_status_other
}

internal fun bookerFieldLabel(key: String): Int? = when (key) {
    "first_name" -> R.string.management_field_first_name
    "last_name" -> R.string.management_field_last_name
    "phone" -> R.string.management_field_phone
    "address" -> R.string.management_field_address
    "city" -> R.string.management_field_city
    "postal_code" -> R.string.management_field_postal_code
    "country" -> R.string.management_field_country
    "email" -> R.string.management_field_email
    "note" -> R.string.management_field_note
    else -> null
}

/** Una prenotazione: numero, stato, recapiti, biglietti con «Registra ingresso» e «Annulla», dati e risposte. */
@Composable
private fun BookingCard(booking: ManagedBooking, date: ManagedDate, busy: Boolean, onCheckIn: (ManagedTicket) -> Unit, onCancel: (ManagedTicket?) -> Unit) {
    val zone = zoneOf(date)
    var showBooker by rememberSaveable(booking.id) { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.management_booking_number, booking.id), style = MaterialTheme.typography.titleMedium)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(bookingStatusLabel(booking.status)), color = if (booking.status == "confirmed") Acid else Muted)
            if (booking.staffBooking) Text(stringResource(R.string.management_booking_staff_badge), color = Muted)
        }
        Text(listOfNotNull(
            booking.email,
            booking.createdAt?.let { stringResource(R.string.management_booked_at, cityClock(it, zone)) },
            pluralStringResource(R.plurals.management_tickets_count, booking.ticketsCount, booking.ticketsCount),
        ).joinToString(" · "), color = Muted)
        booking.cancellationReason?.takeIf { booking.cancelled && it.isNotBlank() }?.let { Text(stringResource(R.string.management_cancellation_reason_value, it), color = Muted) }
        booking.tickets.forEach { ticket ->
            Column(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                Text(ticket.attendeeName, style = MaterialTheme.typography.titleSmall)
                Text("#${ticket.id} · " + stringResource(ticketStatusLabel(ticket.status)), color = Muted)
                if (!date.ended) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (ticket.canCheckIn) Button(enabled = !busy, onClick = { onCheckIn(ticket) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_checkin_row)) }
                    if (ticket.canCancel) OutlinedButton(enabled = !busy, onClick = { onCancel(ticket) }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.management_cancel_ticket))
                    }
                }
            }
        }
        val booker = booking.bookerFields
        if (booker.isNotEmpty() || booking.privacyAcceptedAt != null) {
            TextButton(onClick = { showBooker = !showBooker }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (showBooker) R.string.management_booker_hide else R.string.management_booker_show))
            }
            if (showBooker) {
                booker.forEach { (key, value) -> LabeledValue(bookerFieldLabel(key)?.let { stringResource(it) } ?: key, value) }
                if (!booking.staffBooking) LabeledValue(stringResource(R.string.management_privacy_record), booking.privacyAcceptedAt?.let { cityClock(it, zone) } ?: "—")
            }
        }
        if (booking.answers.isNotEmpty()) {
            Text(stringResource(R.string.management_answers), style = MaterialTheme.typography.titleSmall)
            booking.answers.forEach { LabeledValue(it.question, it.answer) }
        }
        if (!booking.cancelled && !date.ended) OutlinedButton(enabled = !busy, onClick = { onCancel(null) }, modifier = Modifier.heightIn(min = 48.dp),
            colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error)) { Text(stringResource(R.string.management_cancel_booking)) }
    }
}

@Composable
private fun LabeledValue(label: String, value: String) {
    Column { Text(label, color = Muted); Text(value) }
}

/** La conferma dell'annullamento, con il motivo facoltativo che arriva a chi ha prenotato. */
@Composable
private fun CancelBookingDialog(booking: ManagedBooking, ticket: ManagedTicket?, enabled: Boolean, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    var reason by rememberSaveable(booking.id, ticket?.id) { mutableStateOf("") }
    val title = stringResource(if (ticket == null) R.string.management_cancel_booking else R.string.management_cancel_ticket)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(ticket?.let { stringResource(R.string.management_cancel_ticket_confirm, it.attendeeName) } ?: stringResource(R.string.management_cancel_confirm, booking.id))
                OutlinedTextField(reason, { reason = it.take(500) }, label = { Text(stringResource(R.string.management_cancel_reason)) }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(enabled = enabled, onClick = { onConfirm(reason) }) { Text(title, color = MaterialTheme.colorScheme.error) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_keep)) } },
    )
}

/* ------------------------------------------------------------------ */
/* Impostazioni                                                        */
/* ------------------------------------------------------------------ */

/**
 * Le impostazioni delle prenotazioni della data con le domande (W-TK-05, e
 * W-TK-01 dall'evento). A data finita non si cambiano; con il locale non
 * abilitato si leggono ma non si salvano, come nel pannello.
 */
@Composable
internal fun SettingsSection(date: ManagedDate, state: TicketingDateUiState, viewModel: TicketingDateViewModel) {
    val draft = state.settingsDraft
    if (date.ended || draft == null) {
        Text(stringResource(if (date.ended) R.string.management_ended_hint else R.string.management_settings_unavailable), color = Muted)
        return
    }
    val settings = date.settings
    val limits = settings?.limits ?: it.fabiodalez.incitta.data.management.BookingSettingsLimits()
    val zone = zoneOf(date)
    val errors = state.settingsErrors
    val busy = state.busy
    val edit = viewModel::editSettings
    Text(stringResource(R.string.management_settings_title), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_settings_hint), color = Muted)
    FormScaffold(errors, busy, stringResource(R.string.management_settings_save), onSubmit = viewModel::saveSettings, submitEnabled = date.ticketingEnabled) {
        CheckRow(stringResource(R.string.management_settings_enabled), draft.enabled, !busy) { value -> edit { it.copy(enabled = value) } }
        CheckRow(stringResource(R.string.management_settings_waitlist), draft.waitlist, !busy) { value -> edit { it.copy(waitlist = value) } }
        FormTextField(draft.capacity, { value -> edit { it.copy(capacity = value.filter(Char::isDigit).take(7)) } }, stringResource(R.string.management_settings_capacity), errors, "booking_capacity",
            enabled = !busy, singleLine = true, help = stringResource(R.string.management_settings_capacity_hint), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
        FormTextField(draft.limit, { value -> edit { it.copy(limit = value.filter(Char::isDigit).take(2)) } }, stringResource(R.string.management_settings_limit), errors, "booking_limit",
            enabled = !busy, singleLine = true, help = stringResource(R.string.management_settings_limit_hint, limits.bookingLimitMax), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
        Text(stringResource(R.string.management_settings_time_hint, zone.id), color = Muted)
        CityDateTimeField(stringResource(R.string.management_settings_opens), draft.opensAt, zone, date.venue, { value -> edit { it.copy(opensAt = value) } }, errors.field("booking_opens_at"), enabled = !busy, clearable = true)
        CityDateTimeField(stringResource(R.string.management_settings_closes), draft.closesAt, zone, date.venue, { value -> edit { it.copy(closesAt = value) } }, errors.field("booking_closes_at"), enabled = !busy, clearable = true)
        CityDateTimeField(stringResource(R.string.management_settings_cancellation), draft.cancellationClosesAt, zone, date.venue, { value -> edit { it.copy(cancellationClosesAt = value) } }, errors.field("cancellation_closes_at"), enabled = !busy, clearable = true)
        RichTextField(draft.instructions, { value -> edit { it.copy(instructions = value.take(limits.instructionsMax)) } }, stringResource(R.string.management_settings_instructions),
            error = errors.field("booking_instructions"), minLines = 3, enabled = !busy)
        HorizontalDivider()
        Text(stringResource(R.string.management_settings_fields), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.management_settings_fields_hint), color = Muted)
        errors.field("booking_fields")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        BOOKER_FIELDS.forEach { key ->
            Text(bookerFieldLabel(key)?.let { stringResource(it) } ?: key, style = MaterialTheme.typography.titleSmall)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                BOOKER_FIELD_MODES.forEach { mode ->
                    FilterChip(draft.fields[key] == mode, { edit { it.copy(fields = it.fields + (key to mode)) } }, enabled = !busy,
                        label = { Text(stringResource(fieldModeLabel(mode))) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
        }
        HorizontalDivider()
        QuestionsEditor(draft, limits.questions, limits.questionLabelMax, errors, busy, edit)
        if (!date.ticketingEnabled) Text(stringResource(R.string.management_disabled_hint), color = MaterialTheme.colorScheme.error)
    }
    if (state.settingsDirty) TextButton(enabled = !busy, onClick = viewModel::resetSettings, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_settings_reset)) }
}

internal fun fieldModeLabel(mode: String): Int = when (mode) {
    "optional" -> R.string.management_field_mode_optional
    "required" -> R.string.management_field_mode_required
    else -> R.string.management_field_mode_hidden
}

internal fun questionTypeLabel(type: String): Int = when (type) {
    "choice" -> R.string.management_question_type_choice
    "yes_no" -> R.string.management_question_type_yes_no
    else -> R.string.management_question_type_text
}

@Composable
private fun CheckRow(label: String, checked: Boolean, enabled: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(checked, enabled = enabled, role = Role.Switch) { onChange(it) }, verticalAlignment = Alignment.CenterVertically) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = null, enabled = enabled)
    }
}

/** Le domande a chi prenota: fino al tetto del server, ognuna con tipo, scelte e obbligo. */
@Composable
private fun QuestionsEditor(draft: BookingSettingsDraft, max: Int, labelMax: Int, errors: FormErrors, busy: Boolean, edit: ((BookingSettingsDraft) -> BookingSettingsDraft) -> Unit) {
    Text(stringResource(R.string.management_questions_title), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.management_questions_hint, max), color = Muted)
    errors.fields["booking_questions"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    fun update(index: Int, change: (QuestionDraft) -> QuestionDraft) = edit { current -> current.copy(questions = current.questions.mapIndexed { i, q -> if (i == index) change(q) else q }) }
    draft.questions.forEachIndexed { index, question ->
        Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outline).padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            FormTextField(question.label, { value -> update(index) { it.copy(label = value.take(labelMax)) } }, stringResource(R.string.management_question_label, index + 1), errors, "booking_questions.$index.label", enabled = !busy, singleLine = true)
            Text(stringResource(R.string.management_question_type), style = MaterialTheme.typography.titleSmall)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                QUESTION_TYPES.forEach { type ->
                    FilterChip(question.type == type, { update(index) { it.copy(type = type) } }, enabled = !busy, label = { Text(stringResource(questionTypeLabel(type))) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
            if (question.type == "choice") FormTextField(question.options, { value -> update(index) { it.copy(options = value.take(1000)) } }, stringResource(R.string.management_question_options), errors, "booking_questions.$index.options", enabled = !busy, minLines = 3)
            CheckRow(stringResource(R.string.management_question_required), question.required, !busy) { value -> update(index) { it.copy(required = value) } }
            TextButton(enabled = !busy, onClick = { edit { current -> current.copy(questions = current.questions.filterIndexed { i, _ -> i != index }) } }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_question_remove), color = MaterialTheme.colorScheme.error)
            }
        }
    }
    if (draft.questions.size < max) OutlinedButton(enabled = !busy, onClick = { edit { it.copy(questions = it.questions + QuestionDraft()) } }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_question_add))
    }
}

/* ------------------------------------------------------------------ */
/* Messaggi, staff, file                                               */
/* ------------------------------------------------------------------ */

/** Il modulo per scrivere a chi ha prenotato e lo storico degli ultimi dieci messaggi (W-TK-10). */
@Composable
internal fun MessagesSection(date: ManagedDate, state: TicketingDateUiState, viewModel: TicketingDateViewModel) {
    if (!date.ended) key(date.id) {
        AttendeeMessageForm(state.busy, state.messageDailyLimit) { subject, body, waitlisted, sent -> viewModel.sendMessage(subject, body, waitlisted, sent) }
    }
    HorizontalDivider()
    Text(stringResource(R.string.management_messages_history), style = MaterialTheme.typography.titleLarge)
    LoadState(state.messages, onRetry = viewModel::loadMessages) { history ->
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.management_messages_today, history.meta.sentLast24h, history.meta.dailyLimit), color = Muted)
            if (history.data.isEmpty()) Text(stringResource(R.string.management_messages_empty), color = Muted)
            history.data.forEach { MessageRow(it, zoneOf(date)) }
        }
    }
}

@Composable
private fun MessageRow(message: SentMessage, zone: ZoneId) {
    HorizontalDivider()
    Text(message.subject, style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.management_messages_line, message.createdAt?.let { cityClock(it, zone) } ?: "—", message.sender ?: "—", message.recipientsCount), color = Muted)
    if (message.includeWaitlisted) Text(stringResource(R.string.management_messages_with_waitlist), color = Muted)
    Text(message.body)
}

/** Il personale d'ingresso della data (W-TK-09): a data finita resta solo l'elenco. */
@Composable
internal fun StaffSection(date: ManagedDate, busy: Boolean, onInvite: (String, () -> Unit) -> Unit, onRemove: (CheckinStaff) -> Unit) {
    Text(stringResource(R.string.management_staff), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_staff_help), color = Muted)
    date.staff.forEach { member ->
        Text(if (member.pending) stringResource(R.string.management_staff_pending, member.email) else member.email)
        if (!date.ended) TextButton(enabled = !busy, onClick = { onRemove(member) }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(if (member.pending) R.string.management_withdraw else R.string.management_revoke))
        }
    }
    if (date.ended) return
    var email by rememberSaveable(date.id) { mutableStateOf("") }
    OutlinedTextField(email, { email = it.take(255) }, label = { Text(stringResource(R.string.management_email)) }, singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
    OutlinedButton(enabled = !busy && email.isNotBlank(), onClick = { onInvite(email) { email = "" } }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_assign))
    }
}

/**
 * Il CSV dei partecipanti e la lista d'ingresso in PDF (W-TK-12, 13): il
 * server li produce, l'app li scarica nella sua cache e li passa a «Apri con»
 * o «Condividi». Si possono scaricare anche a data finita, come sul sito.
 */
@Composable
internal fun ExportSection(date: ManagedDate, state: TicketingDateUiState, viewModel: TicketingDateViewModel) {
    val context = LocalContext.current
    val chooser = stringResource(R.string.management_export_chooser)
    val noApp = stringResource(R.string.management_export_no_app)
    var failure by remember { mutableStateOf<String?>(null) }
    Text(stringResource(R.string.management_tab_export), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_export_hint), color = Muted)
    listOf(ExportKind.Participants to R.string.management_export_csv, ExportKind.EntryList to R.string.management_export_pdf).forEach { (kind, label) ->
        Button(enabled = state.exportBusy == null, onClick = { failure = null; viewModel.export(kind, context.cacheDir) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(if (state.exportBusy == kind) stringResource(R.string.management_export_busy) else stringResource(label))
        }
    }
    state.exportError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    failure?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    state.exported?.let { file ->
        Text(stringResource(R.string.management_export_ready, file.file.name), color = Acid)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { if (!SharedFiles.open(context, file.file, file.mime, chooser)) failure = noApp }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_export_open)) }
            OutlinedButton(onClick = { if (!SharedFiles.share(context, file.file, file.mime, chooser)) failure = noApp }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_export_share)) }
        }
        Text(stringResource(R.string.management_export_privacy), color = Muted)
    }
}

/* ------------------------------------------------------------------ */
/* Riquadro «Ticketing inCittà» dell'evento                            */
/* ------------------------------------------------------------------ */

/**
 * Il riquadro «Ticketing inCittà» di un evento del locale (W-TK-02): l'avviso
 * se il locale non è abilitato e una riga per ogni data che la persona
 * gestisce, che apre la scheda della data sulle impostazioni (W-TK-01).
 */
@Composable
internal fun EventTicketingScreen(viewModel: EventTicketingViewModel, context: ManagementGraphContext, onOpenDate: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val title = (state as? Loadable.Ready)?.value?.title
    ManagementScaffold(title = stringResource(R.string.management_event_ticketing_title), subtitle = title, padding = context.padding, onBack = context::back) {
        LoadState(state, onRetry = viewModel::load) { box ->
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(stringResource(R.string.management_event_ticketing_intro), color = Muted)
                if (!box.ticketingEnabled) Notice(stringResource(R.string.management_disabled_title), stringResource(R.string.management_event_ticketing_disabled), alert = true)
                when {
                    box.datesCount == 0 -> Text(stringResource(R.string.management_event_ticketing_no_dates), color = Muted)
                    box.dates.isEmpty() -> Text(stringResource(R.string.management_event_ticketing_not_manager), color = Muted)
                }
                val zone = box.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: ZoneId.of("Europe/Rome")
                box.dates.forEach { date ->
                    TextButton(onClick = { onOpenDate(date.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                        Column(Modifier.fillMaxWidth()) {
                            Text(stringResource(R.string.management_event_ticketing_date, cityClock(date.startsAt, zone)), style = MaterialTheme.typography.titleMedium)
                            date.venue?.let { Text(it, color = Muted) }
                            Text(stringResource(if (date.ended) R.string.management_ended_title else if (date.bookingEnabled) R.string.management_booking_on else R.string.management_booking_off), color = if (date.bookingEnabled && !date.ended) Acid else Muted)
                            date.counts?.let { Text(seatsLine(it), color = Muted) }
                        }
                    }
                    HorizontalDivider()
                }
            }
        }
    }
}

/** Data e ora nel fuso della città: «28/11/2026 21:00». */
internal fun cityClock(iso: String, zone: ZoneId): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm", Locale.ITALIAN))
}.getOrDefault(iso)
