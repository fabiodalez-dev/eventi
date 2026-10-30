package it.fabiodalez.incitta.ui

import android.graphics.Bitmap
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.platform.LocalUriHandler
import it.fabiodalez.incitta.data.AttendeeName
import it.fabiodalez.incitta.data.BookingQuestion
import it.fabiodalez.incitta.data.answersComplete
import it.fabiodalez.incitta.data.supported
import it.fabiodalez.incitta.data.BookingPeriod
import it.fabiodalez.incitta.data.filterBookings
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.offersWalletPass
import it.fabiodalez.incitta.R
import com.google.zxing.BarcodeFormat
import com.google.zxing.MultiFormatWriter
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

@Composable
fun ReservationScreen(state: AppUiState, padding: PaddingValues, onReserve: (List<AttendeeName>, Boolean, Map<String, String>, Map<String, String>) -> Unit, onBack: () -> Unit, onResendVerification: () -> Unit = {}, onEmailConfirmed: () -> Unit = {}, onRetry: () -> Unit) {
    val date = state.bookingDate ?: return
    var names by remember(date.occurrenceId) { mutableStateOf(listOf(AttendeeName())) }
    var booker by remember(date.occurrenceId) { mutableStateOf(mapOf("first_name" to "", "last_name" to "")) }
    var answers by remember(date.occurrenceId) { mutableStateOf(mapOf<String, String>()) }
    val uriHandler = LocalUriHandler.current
    var waitlist by remember(date.occurrenceId) { mutableStateOf(false) }
    var accepted by remember(date.occurrenceId) { mutableStateOf(false) }
    val availability = state.bookingAvailability
    Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        TextButton(onClick = onBack) { Text(stringResource(R.string.ticket_back)) }
        Text(stringResource(R.string.ticket_free), color = Acid)
        Text(date.title.uppercase(), style = MaterialTheme.typography.headlineLarge)
        Text(ticketDate(date.startsAt))
        Text(date.venue?.name.orEmpty(), color = Muted)
        Text(stringResource(R.string.ticket_admission_notice), color = Muted)
        if (state.bookingBusy) LinearProgressIndicator(Modifier.fillMaxWidth())
        state.bookingError?.let {
            Text(it, color = Paper)
            if (state.bookingVerificationRequired) EmailVerificationPrompt(state.bookingBusy, onResendVerification, onEmailConfirmed)
            else if (availability == null) OutlinedButton(onClick = onRetry) { Text(stringResource(R.string.ticket_retry)) }
        }
        /*
         * Come sul sito, dove la pagina di prenotazione rimanda alla conferma
         * prima di mostrare il modulo: con l'email non confermata al posto del
         * modulo c'è l'invito. Il 403 resta la rete per un profilo salvato
         * vecchio; dopo «Ho confermato» il profilo si rilegge e il modulo
         * torna con i dati già scritti.
         */
        val unverified = state.session?.user?.emailVerified == false
        if (unverified && !state.bookingVerificationRequired) {
            Text(stringResource(R.string.ticket_verify_required))
            EmailVerificationPrompt(state.bookingBusy, onResendVerification, onEmailConfirmed)
        }
        if (availability != null && !unverified) {
            if (!availability.open) {
                Text(stringResource(R.string.ticket_closed))
            } else {
                Text(if (availability.remaining == null) stringResource(R.string.ticket_unlimited) else stringResource(R.string.ticket_remaining, availability.remaining), color = Acid)
                Text(stringResource(R.string.ticket_limit, availability.limitPerAccount), color = Muted)
                availability.instructions?.let { Text(it) }
                availability.cancellationClosesAt?.let { Text(stringResource(R.string.ticket_cancel_until, ticketDate(it)), color = Muted) }
                Text(stringResource(R.string.ticket_booker), style = MaterialTheme.typography.titleLarge)
                listOf("first_name" to stringResource(R.string.ticket_first_name), "last_name" to stringResource(R.string.ticket_last_name)).forEach { (field, label) ->
                    OutlinedTextField(booker[field].orEmpty(), { booker = booker + (field to it.take(120)) }, label = { Text(label) }, enabled = !state.bookingBusy, modifier = Modifier.fillMaxWidth(), shape = ControlShape, singleLine = true)
                }
                availability.bookerFields.forEach { field ->
                    OutlinedTextField(booker[field.key].orEmpty(), { booker = booker + (field.key to it.take(255)) }, label = { Text(field.label) }, supportingText = { Text(stringResource(if (field.required) R.string.ticket_required else R.string.ticket_optional)) }, enabled = !state.bookingBusy, modifier = Modifier.fillMaxWidth(), shape = ControlShape, singleLine = true)
                }
                val questions = availability.questions.filter { it.supported }
                if (questions.isNotEmpty()) {
                    Text(stringResource(R.string.ticket_questions_title), style = MaterialTheme.typography.titleLarge)
                    Text(stringResource(R.string.ticket_questions_hint), color = Muted)
                    questions.forEach { question ->
                        BookingQuestionField(question, answers[question.id].orEmpty(), !state.bookingBusy) { answers = answers + (question.id to it) }
                    }
                }
                Text(stringResource(R.string.ticket_participants), style = MaterialTheme.typography.titleLarge)
                names.forEachIndexed { index, name ->
                    OutlinedTextField(name.firstName, { value -> names = names.toMutableList().also { it[index] = name.copy(firstName = value.take(120)) } }, label = { Text(stringResource(R.string.ticket_first_name)) }, enabled = !state.bookingBusy, modifier = Modifier.fillMaxWidth(), shape = ControlShape, singleLine = true)
                    OutlinedTextField(name.lastName, { value -> names = names.toMutableList().also { it[index] = name.copy(lastName = value.take(120)) } }, label = { Text(stringResource(R.string.ticket_last_name)) }, enabled = !state.bookingBusy, modifier = Modifier.fillMaxWidth(), shape = ControlShape, singleLine = true)
                    if (names.size > 1) TextButton(onClick = { names = names.toMutableList().also { it.removeAt(index) } }, enabled = !state.bookingBusy) { Text(stringResource(R.string.ticket_remove)) }
                }
                if (names.size < availability.limitPerAccount) OutlinedButton(onClick = { names = names + AttendeeName() }, enabled = !state.bookingBusy, shape = ControlShape) { Text(stringResource(R.string.ticket_add)) }
                if (availability.waitlist) Row {
                    Checkbox(waitlist, { waitlist = it }, enabled = !state.bookingBusy)
                    Text(stringResource(R.string.ticket_waitlist_consent), modifier = Modifier.padding(top = 10.dp))
                }
                Row {
                    Checkbox(accepted, { accepted = it }, enabled = !state.bookingBusy)
                    Text(stringResource(R.string.ticket_privacy_acknowledgement), modifier = Modifier.padding(top = 10.dp))
                }
                availability.privacyUrl?.takeIf { it.startsWith("https://") || it.startsWith("http://") }?.let { url -> TextButton(onClick = { uriHandler.openUri(url) }) { Text(stringResource(R.string.ticket_privacy_link)) } }
                val complete = names.all { it.firstName.isNotBlank() && it.lastName.isNotBlank() } && listOf("first_name", "last_name").all { !booker[it].isNullOrBlank() } && availability.bookerFields.filter { it.required }.all { !booker[it.key].isNullOrBlank() } && answersComplete(availability.questions, answers)
                Button(onClick = { onReserve(names, waitlist, booker, answers) }, enabled = accepted && complete && !state.bookingBusy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = ControlShape) { Text(stringResource(R.string.ticket_confirm)) }
            }
        }
    }
}

@Composable
fun TicketsScreen(state: AppUiState, padding: PaddingValues, onCancel: (Long, Long?) -> Unit, onRefresh: () -> Unit, onEmail: (Long) -> Unit, onWallet: (Long) -> Unit, onWalletOpened: () -> Unit, onSendTicket: (Long, String, () -> Unit) -> Unit = { _, _, _ -> }, onResendVerification: () -> Unit = {}, onEmailConfirmed: () -> Unit = {}, onBack: () -> Unit) {
    var cancelTarget by remember(state.session?.user?.id) { mutableStateOf<Pair<Long, Long?>?>(null) }
    var period by remember(state.session?.user?.id) { mutableStateOf(BookingPeriod.UPCOMING) }
    var query by remember(state.session?.user?.id) { mutableStateOf("") }
    val visibleBookings = filterBookings(state.bookings, period, query)
    val uriHandler = LocalUriHandler.current
    /*
     * Il link del pass si apre una volta e poi si butta: senza il consumo,
     * ogni ricomposizione della schermata riaprirebbe Google Wallet.
     */
    LaunchedEffect(state.walletLink) {
        state.walletLink?.let { link ->
            uriHandler.openUri(link)
            onWalletOpened()
        }
    }
    Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        TextButton(onClick = onBack) { Text(stringResource(R.string.ticket_profile)) }
        Text(stringResource(R.string.ticket_title), style = MaterialTheme.typography.displayMedium)
        if (state.session == null) {
            Text(stringResource(R.string.ticket_login))
        } else {
            if (state.noShows > 0) {
                Text(
                    pluralStringResource(R.plurals.ticket_no_show_account, state.noShows, state.noShows, NO_SHOW_LIMIT, NO_SHOW_WINDOW_DAYS),
                    color = Paper,
                    modifier = Modifier.fillMaxWidth().border(2.dp, Danger, ControlShape).padding(14.dp),
                )
            }
            OutlinedButton(onClick = onRefresh, enabled = !state.bookingBusy, shape = ControlShape) { Text(stringResource(R.string.ticket_refresh)) }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(BookingPeriod.UPCOMING to "Prossimi", BookingPeriod.PAST to "Passati", BookingPeriod.CANCELLED to "Annullati").forEach { (value, label) ->
                    FilterChip(selected = period == value, onClick = { period = value }, label = { Text(label) }, modifier = Modifier.weight(1f), shape = ControlShape)
                }
            }
            OutlinedTextField(query, { query = it }, label = { Text("Cerca evento, locale o partecipante") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            if (state.bookingBusy) LinearProgressIndicator(Modifier.fillMaxWidth())
            state.bookingsSavedAt?.let { OfflineTicketsNotice(it, state.session.user.timezone) }
            state.bookingError?.let {
                Text(it)
                // Rinvio del riepilogo, invio del biglietto e pass Wallet chiedono l'email confermata.
                if (state.bookingVerificationRequired) EmailVerificationPrompt(state.bookingBusy, onResendVerification, onEmailConfirmed)
            }
            if (!state.bookingBusy && state.bookings.isEmpty() && state.bookingError == null) Text(stringResource(R.string.ticket_empty), color = Muted)
            if (!state.bookingBusy && state.bookings.isNotEmpty() && visibleBookings.isEmpty() && state.bookingError == null) Text("Nessun biglietto corrisponde ai filtri scelti.", color = Muted)
            visibleBookings.forEach { booking ->
                HorizontalDivider(thickness = 2.dp, color = Rule)
                Text("#${booking.id} · ${ticketStatus(booking.status)}", color = Acid)
                Text(booking.title.uppercase(), style = MaterialTheme.typography.headlineSmall)
                booking.startsAt?.let { Text(ticketDate(it)) }
                booking.venue?.let { Text(it, color = Muted) }
                booking.address?.let { Text(it, color = Muted) }
                TextButton(onClick = { onEmail(booking.id) }, enabled = !state.bookingBusy) { Text(stringResource(R.string.ticket_email)) }
                booking.instructions?.let { Text(it) }
                if (booking.answers.isNotEmpty()) {
                    Text(stringResource(R.string.ticket_questions_yours), style = MaterialTheme.typography.titleSmall)
                    booking.answers.forEach { Text("${it.question}: ${it.answer}", color = Muted) }
                }
                if (booking.status == "waitlisted") Text(stringResource(R.string.ticket_waiting), color = Muted)
                booking.tickets.forEach { ticket ->
                    key(ticket.id, state.session.user.id) {
                        var expanded by remember { mutableStateOf(false) }
                        Text(ticket.attendeeName, style = MaterialTheme.typography.titleLarge)
                        Text("#${ticket.id} · ${ticketStatus(ticket.status)}", color = Muted)
                        if (ticket.qrPayload != null && ticket.status == "valid") {
                            OutlinedButton(onClick = { expanded = !expanded }, shape = ControlShape) { Text(stringResource(if (expanded) R.string.ticket_hide_qr else R.string.ticket_show_qr)) }
                            if (expanded) {
                                // Il QR è il biglietto: finché è visibile niente screenshot né anteprima tra le app recenti.
                                SecureWindow()
                                val bitmap = remember(ticket.qrPayload) { ticketQr(ticket.qrPayload) }
                                Image(bitmap.asImageBitmap(), stringResource(R.string.ticket_qr_description, ticket.id), modifier = Modifier.background(Paper).padding(12.dp).fillMaxWidth().heightIn(max = 280.dp).aspectRatio(1f))
                                Text(stringResource(R.string.ticket_qr_hint), color = Muted)
                            }
                        }
                        /*
                         * Compare solo quando il server dichiara di saper
                         * emettere il pass: senza credenziali dell'emittente il
                         * pulsante non esiste, invece di esistere e non
                         * funzionare.
                         */
                        TicketShareActions(ticket, booking.title, state.bookingBusy) { email, sent -> onSendTicket(ticket.id, email, sent) }
                        if (state.offersWalletPass(ticket)) {
                            OutlinedButton(onClick = { onWallet(ticket.id) }, enabled = !state.bookingBusy, shape = ControlShape) { Text(stringResource(R.string.ticket_wallet_add)) }
                            Text(stringResource(R.string.ticket_wallet_hint), color = Muted)
                        }
                        if (booking.canCancel && ticket.status in listOf("valid", "waitlisted")) {
                            TextButton(onClick = { cancelTarget = booking.id to ticket.id }, enabled = !state.bookingBusy) { Text(stringResource(R.string.ticket_cancel_one)) }
                        }
                    }
                }
                if (booking.canCancel && booking.tickets.count { it.status in listOf("valid", "waitlisted") } > 1 && booking.tickets.none { it.status == "checked_in" }) {
                    OutlinedButton(onClick = { cancelTarget = booking.id to null }, enabled = !state.bookingBusy, shape = ControlShape) { Text(stringResource(R.string.ticket_cancel_all)) }
                }
            }
        }
    }
    cancelTarget?.let { target ->
        AlertDialog(onDismissRequest = { cancelTarget = null }, title = { Text(stringResource(R.string.ticket_cancel_all)) }, text = { Text(stringResource(R.string.ticket_cancel_warning)) },
            confirmButton = { TextButton(onClick = { cancelTarget = null; onCancel(target.first, target.second) }) { Text(stringResource(R.string.ticket_cancel_confirm)) } },
            dismissButton = { TextButton(onClick = { cancelTarget = null }) { Text(stringResource(R.string.ticket_keep)) } }, shape = ControlShape)
    }
}

/* Gli stessi valori di `config/ticketing.php` → `no_show`: tre assenze in novanta giorni bloccano le prenotazioni gratuite. */
private const val NO_SHOW_LIMIT = 3
private const val NO_SHOW_WINDOW_DAYS = 90

internal fun ticketQr(payload: String): Bitmap {
    val matrix = MultiFormatWriter().encode(payload, BarcodeFormat.QR_CODE, 512, 512)
    val pixels = IntArray(512 * 512) { index -> if (matrix[index % 512, index / 512]) android.graphics.Color.BLACK else android.graphics.Color.WHITE }
    return Bitmap.createBitmap(pixels, 512, 512, Bitmap.Config.ARGB_8888)
}

private fun ticketDate(value: String): String = runCatching {
    OffsetDateTime.parse(value).atZoneSameInstant(ZoneId.of("Europe/Rome")).format(DateTimeFormatter.ofPattern("EEE d MMM yyyy · HH:mm", Locale.ITALIAN))
}.getOrDefault(value)

@Composable
private fun ticketStatus(value: String): String = stringResource(when (value) {
    "valid" -> R.string.ticket_valid
    "confirmed" -> R.string.ticket_confirmed
    "waitlisted" -> R.string.ticket_waitlisted
    "checked_in" -> R.string.ticket_used
    "expired" -> R.string.ticket_expired
    "no_show" -> R.string.ticket_no_show
    else -> R.string.ticket_cancelled
})

/** Una domanda del gestore: testo libero, una scelta dall'elenco, oppure sì o no. */
@Composable
private fun BookingQuestionField(question: BookingQuestion, value: String, enabled: Boolean, onChange: (String) -> Unit) {
    val mode = stringResource(if (question.required) R.string.ticket_required else R.string.ticket_optional)
    when (question.type) {
        "choice" -> Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text("${question.label} · $mode")
            NativeChoicePicker(question.label, value, listOf("" to stringResource(R.string.ticket_questions_choose)) + question.options.map { it to it }, enabled, onChange)
        }
        "yes_no" -> Column {
            Text("${question.label} · $mode")
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                listOf("1" to stringResource(R.string.ticket_questions_yes), "0" to stringResource(R.string.ticket_questions_no)).forEach { (key, label) ->
                    Row(Modifier.heightIn(min = 48.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                        RadioButton(selected = value == key, onClick = { onChange(key) }, enabled = enabled)
                        Text(label)
                    }
                }
            }
        }
        else -> OutlinedTextField(value, { onChange(it.take(500)) }, label = { Text(question.label) }, supportingText = { Text(mode) }, enabled = enabled, modifier = Modifier.fillMaxWidth(), shape = ControlShape, minLines = 2)
    }
}
