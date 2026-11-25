package it.fabiodalez.incitta.ui.management.ticketing

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.BOOKING_FILTERS
import it.fabiodalez.incitta.data.management.BookingCounts
import it.fabiodalez.incitta.data.management.EventTicketing
import it.fabiodalez.incitta.data.management.ManagedBooking
import it.fabiodalez.incitta.data.management.ManagedTicket
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import java.time.Instant
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Le prenotazioni di una data (W-TK-04, 07, 14), come la sezione
 * «Prenotazioni» di `/gestione-biglietti/{data}`: filtro per stato con i
 * conteggi, ricerca per nome o email, trenta per pagina, «Registra ingresso»
 * dalla riga e annullamento a nome del locale, anche di un solo biglietto.
 */

internal data class BookingsUiState(
    /** Lo stato filtrato (`confirmed`, `checked_in`, `waitlisted`, `cancelled`); `null` = tutte. */
    val status: String? = null,
    val query: String = "",
    val list: Loadable<List<ManagedBooking>> = Loadable.Loading,
    val counts: BookingCounts = BookingCounts(),
    val nextPage: Int? = null,
    val busy: Boolean = false,
    val error: UiText? = null,
    val message: UiText? = null,
) {
    val bookings: List<ManagedBooking> get() = (list as? Loadable.Ready)?.value.orEmpty()
}

internal class BookingsViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    val id: Long = saved.get<Long>(TicketingDateViewModel.KEY_ID) ?: 0L
    private val state = MutableStateFlow(BookingsUiState(
        status = saved.get<String>(KEY_STATUS)?.takeIf { it in BOOKING_FILTERS },
        query = saved.get<String>(KEY_QUERY).orEmpty(),
    ))
    val uiState: StateFlow<BookingsUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load(page = 1, clear = true) }

    fun filter(status: String?) {
        val wanted = status?.takeIf { it in BOOKING_FILTERS }
        if (wanted == state.value.status) return
        saved[KEY_STATUS] = wanted
        state.update { it.copy(status = wanted) }
        load(page = 1, clear = true)
    }

    fun search(query: String) {
        val clean = query.trim().take(120)
        if (clean == state.value.query) return
        saved[KEY_QUERY] = clean
        state.update { it.copy(query = clean) }
        load(page = 1, clear = true)
    }

    /** «Riprova» dopo un errore, e il ricaricamento dopo un'azione. */
    fun reload() = load(page = 1, clear = state.value.list !is Loadable.Ready)

    fun more() {
        val page = state.value.nextPage ?: return
        if (loading?.isActive == true) return
        load(page, clear = false)
    }

    private fun load(page: Int, clear: Boolean) {
        loading?.cancel()
        if (clear) state.update { it.copy(list = Loadable.Loading, nextPage = null) }
        val status = state.value.status
        val query = state.value.query
        loading = viewModelScope.launch {
            try {
                val response = repository.ticketing.bookings(id, status, query, page)
                state.update { current ->
                    val rows = if (page == 1) response.data else (current.bookings + response.data).distinctBy(ManagedBooking::id)
                    current.copy(list = if (rows.isEmpty()) Loadable.Empty else Loadable.Ready(rows), counts = response.meta.counts, nextPage = response.meta.nextPage)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val message = requestFailureMessage(e)
                state.update { if (page == 1 || it.list !is Loadable.Ready) it.copy(list = Loadable.Failed(message)) else it.copy(error = UiText.Raw(message)) }
            }
        }
    }

    /**
     * «Registra ingresso» dalla riga: passa dalla stessa coda cifrata dello
     * scanner, così senza rete la lettura resta in attesa e si ritenta, e
     * segna il biglietto come usato nell'elenco salvato per il controllo
     * senza rete. L'esito è quello del server.
     */
    fun checkIn(ticket: ManagedTicket) {
        val code = ticket.code?.takeIf { ticket.canCheckIn } ?: return
        action {
            repository.addCheckin(id, code, Instant.now())
            runCatching { repository.device.readManifest(id)?.markUsed(code)?.let { repository.device.writeManifest(id, it) } }
            val queue = repository.checkins.value
            val result = when {
                queue.pending.any { it.occurrenceId == id && it.code == code } -> UiText.Res(R.string.management_checkin_queued, listOf(ticket.attendeeName))
                else -> queue.outcomes.firstOrNull { it.occurrenceId == id }?.let { outcome ->
                    UiText.Res(if (outcome.accepted) R.string.management_accepted else R.string.management_rejected, listOf(outcome.message))
                }
            }
            state.update { it.copy(message = result) }
            refreshAfterAction()
        }
    }

    /** Annulla la prenotazione intera (`ticket` vuoto) o un solo biglietto, con il motivo facoltativo. */
    fun cancel(booking: ManagedBooking, ticket: ManagedTicket?, reason: String) = action {
        val updated = repository.ticketing.cancelBooking(id, booking.id, ticket?.id, reason)
        state.update { current ->
            current.copy(
                list = Loadable.Ready(current.bookings.map { if (it.id == updated.id) updated else it }),
                message = UiText.Res(if (ticket == null) R.string.management_booking_cancelled else R.string.management_ticket_cancelled),
            )
        }
        refreshAfterAction()
    }

    /* Dopo un'azione si rilegge la prima pagina: conteggi e filtri cambiano. */
    private suspend fun refreshAfterAction() {
        try {
            val response = repository.ticketing.bookings(id, state.value.status, state.value.query, 1)
            state.update { it.copy(list = if (response.data.isEmpty()) Loadable.Empty else Loadable.Ready(response.data), counts = response.meta.counts, nextPage = response.meta.nextPage) }
        } catch (e: CancellationException) { throw e }
        catch (_: Exception) { /* L'azione è riuscita: l'elenco si aggiorna al prossimo giro. */ }
    }

    private fun action(block: suspend () -> Unit) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try { block() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_STATUS = "bookings_status"
        const val KEY_QUERY = "bookings_query"
    }
}

/** Il riquadro «Ticketing inCittà» di un evento del locale (W-TK-02): le date che si gestiscono. */
internal class EventTicketingViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    val venueId: Long = saved.get<Long>(KEY_VENUE) ?: 0L
    val eventId: Long = saved.get<Long>(KEY_EVENT) ?: 0L
    private val state = MutableStateFlow<Loadable<EventTicketing>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<EventTicketing>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            state.value = try { Loadable.Ready(repository.ticketing.eventTicketing(venueId, eventId)) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
        }
    }

    companion object {
        const val KEY_VENUE = "venueId"
        const val KEY_EVENT = "eventId"
    }
}
