package it.fabiodalez.incitta.ui.management.ticketing

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.CheckinManifest
import it.fabiodalez.incitta.data.CheckinOutcome
import it.fabiodalez.incitta.data.CheckinStaff
import it.fabiodalez.incitta.data.ManagedCampaign
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.ManifestVerdict
import it.fabiodalez.incitta.data.ScanDecision
import it.fabiodalez.incitta.data.management.CheckinQueue
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.refreshedWith
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.data.scanDecision
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
import kotlinx.serialization.json.JsonObject

/*
 * La biglietteria di prima, dietro tre ViewModel: elenco (date e campagne),
 * scheda della data, scheda della campagna. Le chiamate e le regole sono le
 * stesse di `ManagementScreen` fino alla 1.23.0; cambia solo dove vive lo
 * stato, che ora sopravvive alla rotazione e, per id e filtri, anche alla
 * chiusura dell'app da parte del sistema.
 */

internal data class TicketingDatesUiState(
    val reports: Boolean = false,
    val past: Boolean = false,
    val dates: List<ManagedDate> = emptyList(),
    val campaigns: List<ManagedCampaign> = emptyList(),
    val nextPage: Int? = null,
    val busy: Boolean = false,
    val loaded: Boolean = false,
    val error: String? = null,
)

internal class TicketingDatesViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val state = MutableStateFlow(TicketingDatesUiState(reports = saved[KEY_REPORTS] ?: false, past = saved[KEY_PAST] ?: false))
    val uiState: StateFlow<TicketingDatesUiState> = state.asStateFlow()
    val checkins: StateFlow<CheckinQueue> = repository.checkins
    private var loading: Job? = null

    init { load(page = 1, clear = true) }

    fun showReports(reports: Boolean) {
        if (state.value.busy || state.value.reports == reports) return
        saved[KEY_REPORTS] = reports
        state.update { it.copy(reports = reports) }
        load(page = 1, clear = true)
    }

    fun showPast(past: Boolean) {
        if (state.value.busy || state.value.past == past) return
        saved[KEY_PAST] = past
        state.update { it.copy(past = past) }
        load(page = 1, clear = true)
    }

    fun refresh() { if (!state.value.busy) load(page = 1, clear = false) }
    fun more() { val page = state.value.nextPage ?: return; if (!state.value.busy) load(page, clear = false) }

    /** «Riprova le letture»: lo stesso invio del ritentativo automatico, a mano. */
    fun retryCheckins() {
        if (state.value.busy) return
        viewModelScope.launch {
            state.update { it.copy(busy = true, error = null) }
            try { repository.flushCheckins() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun load(page: Int, clear: Boolean) {
        loading?.cancel()
        state.update { if (clear) it.copy(busy = true, error = null, nextPage = null, dates = emptyList(), campaigns = emptyList(), loaded = false) else it.copy(busy = true, error = null) }
        val reports = state.value.reports
        val past = state.value.past
        loading = viewModelScope.launch {
            try {
                if (reports) {
                    val response = repository.ticketing.campaigns(page)
                    state.update { it.copy(campaigns = if (page == 1) response.data else (it.campaigns + response.data).distinctBy(ManagedCampaign::id), nextPage = response.meta?.nextPage, loaded = true) }
                } else {
                    val response = repository.ticketing.dates(past, page)
                    state.update { it.copy(dates = if (page == 1) response.data else (it.dates + response.data).distinctBy(ManagedDate::id), nextPage = response.meta?.nextPage, loaded = true) }
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_REPORTS = "reports"
        const val KEY_PAST = "past"
    }
}

/** I testi che finiscono negli esiti delle letture decise sul telefono: li passa la schermata, già tradotti. */
internal data class ScanTexts(val notInList: String, val alreadyUsed: String)

internal data class TicketingDateUiState(
    val date: Loadable<ManagedDate> = Loadable.Loading,
    val busy: Boolean = false,
    val error: UiText? = null,
    val message: UiText? = null,
    val manifest: CheckinManifest? = null,
    val manifestBusy: Boolean = false,
    val manifestError: String? = null,
    val messageDailyLimit: Int = DEFAULT_MESSAGE_DAILY_LIMIT,
) {
    val current: ManagedDate? get() = (date as? Loadable.Ready)?.value
}

internal class TicketingDateViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow(TicketingDateUiState())
    val uiState: StateFlow<TicketingDateUiState> = state.asStateFlow()
    val checkins: StateFlow<CheckinQueue> = repository.checkins
    private var manifestFor: Long? = null

    init {
        load()
        viewModelScope.launch {
            repository.options()?.limit(MESSAGE_DAILY_LIMIT_KEY)?.toInt()?.takeIf { it > 0 }?.let { limit -> state.update { it.copy(messageDailyLimit = limit) } }
        }
    }

    /** Il primo caricamento, e «Riprova» se non è riuscito. */
    fun load() {
        state.update { it.copy(date = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try { show(repository.ticketing.date(id)) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(date = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun refresh() = action { show(repository.ticketing.date(id)) }

    /*
     * L'elenco per il controllo senza rete: prima quello salvato sul telefono,
     * poi uno fresco dal server, una volta per data aperta come faceva la
     * schermata. Se la rete manca resta quello salvato (o nessuno).
     */
    private fun show(date: ManagedDate) {
        state.update { it.copy(date = Loadable.Ready(date)) }
        if (!date.canCheckIn) { manifestFor = null; state.update { it.copy(manifest = null, manifestError = null) }; return }
        if (manifestFor == date.id) return
        manifestFor = date.id
        if (state.value.manifest?.occurrenceId != date.id) {
            val stored = try { repository.device.readManifest(date.id) } catch (e: CancellationException) { throw e } catch (_: Exception) { null }
            state.update { it.copy(manifest = stored) }
        }
        refreshManifest()
    }

    fun refreshManifest() {
        if (state.value.manifestBusy) return
        viewModelScope.launch { refreshManifestNow() }
    }

    private suspend fun refreshManifestNow() {
        state.update { it.copy(manifestBusy = true, manifestError = null) }
        try {
            val fresh = CheckinManifest.from(id, repository.ticketing.manifest(id), System.currentTimeMillis()).refreshedWith(repository.device.readManifest(id))
            repository.device.writeManifest(id, fresh)
            state.update { it.copy(manifest = fresh) }
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) { state.update { it.copy(manifestError = requestFailureMessage(e)) } }
        finally { state.update { it.copy(manifestBusy = false) } }
    }

    /**
     * Una lettura dallo scanner o dal campo «Codice biglietto». Le regole sono
     * quelle di sempre: 64 caratteri, tetto di 200 in coda, e l'elenco
     * scaricato che senza rete respinge i codici già usati e avvisa su quelli
     * sconosciuti. Ogni lettura accettata è salvata cifrata prima dell'invio.
     */
    fun addCode(value: String, online: Boolean, texts: ScanTexts) {
        val current = state.value.current ?: return
        val code = value.trim()
        val scannedAt = Instant.now()
        if (!code.matches(TICKET_CODE)) { state.update { it.copy(error = UiText.Res(R.string.management_invalid_code)) }; return }
        if (repository.checkins.value.pending.size >= MAX_PENDING) { state.update { it.copy(error = UiText.Res(R.string.management_queue_full)) }; return }
        val list = state.value.manifest?.takeIf { it.occurrenceId == current.id }
        val verdict = list?.verdict(code)
        when (scanDecision(online, verdict)) {
            ScanDecision.RejectUsed -> { repository.recordOutcome(CheckinOutcome(current.id, false, texts.alreadyUsed.format((verdict as ManifestVerdict.AlreadyUsed).name))); return }
            ScanDecision.SendWarnUsed -> repository.recordOutcome(CheckinOutcome(current.id, false, texts.alreadyUsed.format((verdict as ManifestVerdict.AlreadyUsed).name)))
            ScanDecision.PendingUnknown, ScanDecision.SendWarnUnknown -> repository.recordOutcome(CheckinOutcome(current.id, false, texts.notInList))
            ScanDecision.Send, ScanDecision.Pending -> Unit
        }
        if (list != null && verdict is ManifestVerdict.Known) {
            val marked = list.markUsed(code)
            runCatching { repository.device.writeManifest(current.id, marked) }
            state.update { it.copy(manifest = marked) }
        }
        action { repository.addCheckin(current.id, code, scannedAt) }
    }

    fun retryCheckins() = action { repository.flushCheckins() }

    fun inviteStaff(email: String, onDone: () -> Unit) = action {
        repository.ticketing.staff(id, email.trim(), false)
        show(repository.ticketing.date(id))
        onDone()
        state.update { it.copy(message = UiText.Res(R.string.management_saved)) }
    }

    fun removeStaff(member: CheckinStaff) = action {
        repository.ticketing.staff(id, member.email, true)
        show(repository.ticketing.date(id))
        state.update { it.copy(message = UiText.Res(R.string.management_saved)) }
    }

    fun sendMessage(subject: String, body: String, waitlisted: Boolean, onSent: () -> Unit) = action {
        val result = repository.ticketing.message(id, subject, body, waitlisted)
        state.update { it.copy(message = UiText.Plural(R.plurals.management_message_sent, result.recipientsCount, listOf(result.recipientsCount))) }
        onSent()
    }

    fun addGuestBooking(names: String, email: String, phone: String, note: String, onSaved: () -> Unit) = action {
        val booking = repository.ticketing.guestBooking(id, names, email, phone, note)
        onSaved()
        val date = repository.ticketing.date(id)
        show(date)
        // I biglietti appena registrati devono entrare nell'elenco senza rete.
        if (date.canCheckIn) refreshManifestNow()
        state.update { it.copy(message = UiText.Plural(R.plurals.management_guest_saved, booking.tickets.size, listOf(booking.tickets.size))) }
    }

    fun saveDetails(body: JsonObject) = action {
        repository.ticketing.details(id, body)
        show(repository.ticketing.date(id))
        state.update { it.copy(message = UiText.Res(R.string.management_saved)) }
    }

    /** Un'azione alla volta: barra di avanzamento, errore e messaggio azzerati, errore del server se non riesce. */
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
        const val KEY_ID = "id"
        const val MAX_PENDING = 200
        private val TICKET_CODE = Regex("[A-Za-z0-9]{64}")
    }
}

internal class CampaignViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    val id: Long = saved.get<Long>(KEY_ID) ?: 0L
    private val state = MutableStateFlow<Loadable<ManagedCampaign>>(Loadable.Loading)
    val uiState: StateFlow<Loadable<ManagedCampaign>> = state.asStateFlow()

    init { load() }

    fun load() {
        state.value = Loadable.Loading
        viewModelScope.launch {
            state.value = try { Loadable.Ready(repository.ticketing.campaign(id)) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
        }
    }

    companion object { const val KEY_ID = "id" }
}

/* Lo stesso tetto di `config/ticketing.php` → `messages.daily_limit`, finché `GET /management/options` non lo manda. */
internal const val DEFAULT_MESSAGE_DAILY_LIMIT = 3
internal const val MESSAGE_DAILY_LIMIT_KEY = "attendee_messages_per_day"
