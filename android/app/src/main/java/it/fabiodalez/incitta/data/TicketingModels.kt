package it.fabiodalez.incitta.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class BookingAvailability(
    val enabled: Boolean = false,
    val open: Boolean = false,
    val capacity: Int? = null,
    val remaining: Int? = null,
    @SerialName("limit_per_account") val limitPerAccount: Int = 6,
    val waitlist: Boolean = false,
    @SerialName("opens_at") val opensAt: String? = null,
    @SerialName("closes_at") val closesAt: String? = null,
    @SerialName("cancellation_closes_at") val cancellationClosesAt: String? = null,
    val instructions: String? = null,
    @SerialName("booker_fields") val bookerFields: List<BookingFormField> = emptyList(),
    /** Le domande di chi gestisce la data; le risposte le vede solo il gestore. */
    val questions: List<BookingQuestion> = emptyList(),
    @SerialName("privacy_url") val privacyUrl: String? = null,
)

@Serializable
data class Booking(
    val id: Long,
    @SerialName("occurrence_id") val occurrenceId: Long,
    val title: String,
    @SerialName("event_slug") val eventSlug: String? = null,
    val venue: String? = null,
    val address: String? = null,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val status: String,
    val instructions: String? = null,
    @SerialName("cancellation_reason") val cancellationReason: String? = null,
    @SerialName("can_cancel") val canCancel: Boolean = false,
    val tickets: List<AdmissionTicket> = emptyList(),
    val answers: List<BookingAnswer> = emptyList(),
)

/** Le prenotazioni dell'account e quante assenze recenti il server ha contato. */
data class BookingList(val bookings: List<Booking>, val noShows: Int)

@Serializable
data class AdmissionTicket(
    val id: Long,
    @SerialName("attendee_name") val attendeeName: String,
    val status: String,
    @SerialName("qr_payload") val qrPayload: String? = null,
    @SerialName("checked_in_at") val checkedInAt: String? = null,
    /** Il link personale firmato che mostra solo questo QR; null se il biglietto non vale. */
    @SerialName("share_url") val shareUrl: String? = null,
)

@Serializable
data class ReserveBody(
    val attendees: List<AttendeeName>,
    @SerialName("request_key") val requestKey: String,
    val waitlist: Boolean,
    @SerialName("accept_terms") val acceptTerms: Boolean,
    val booker: Map<String, String>,
    /** Per identificativo della domanda; i sì e no viaggiano come "1" e "0". */
    val answers: Map<String, String> = emptyMap(),
)

@Serializable
data class AttendeeName(@SerialName("first_name") val firstName: String = "", @SerialName("last_name") val lastName: String = "")

@Serializable
data class BookingFormField(val key: String, val label: String, val required: Boolean)

@Serializable
data class BookingQuestion(val id: String, val label: String, val type: String = "text", val options: List<String> = emptyList(), val required: Boolean = false)

@Serializable
data class BookingAnswer(val question: String = "", val answer: String = "")

/** Le sole domande che l'app sa mostrare: un tipo nuovo del server non blocca la prenotazione, a meno che sia obbligatorio. */
internal val BookingQuestion.supported: Boolean get() = type in setOf("text", "choice", "yes_no") && (type != "choice" || options.size >= 2)

/** Risposte obbligatorie date, e scelte prese dall'elenco. */
internal fun answersComplete(questions: List<BookingQuestion>, answers: Map<String, String>): Boolean = questions.all { question ->
    val value = answers[question.id]?.trim().orEmpty()
    when {
        !question.supported -> !question.required
        value.isEmpty() -> !question.required
        question.type == "choice" -> value in question.options
        question.type == "yes_no" -> value == "1" || value == "0"
        else -> value.length <= 500
    }
}

/** Solo le risposte date, ripulite, per le domande che l'app ha mostrato. */
internal fun answersPayload(questions: List<BookingQuestion>, answers: Map<String, String>): Map<String, String> =
    questions.filter { it.supported }.mapNotNull { question -> answers[question.id]?.trim()?.takeIf { it.isNotEmpty() }?.let { question.id to it } }.toMap()

@Serializable
data class CancelBookingBody(@SerialName("ticket_id") val ticketId: Long? = null)

/*
 * Il biglietto nel portafoglio digitale.
 *
 * L'app non costruisce il pass: chiede al server se sa farlo e, in caso, si
 * fa dare l'indirizzo già firmato. Così la chiave dell'emittente resta dove
 * deve stare, e un'app vecchia non mostra mai un pulsante che non funziona.
 */
@Serializable
data class WalletFeature(@SerialName("google_wallet") val googleWallet: Boolean = false)

@Serializable
data class WalletPass(@SerialName("save_url") val saveUrl: String)
