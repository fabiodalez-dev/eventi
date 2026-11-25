package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSponsorshipsSource
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.SPONSORSHIP_EVENTS_MAX
import it.fabiodalez.incitta.data.management.SPONSORSHIP_EVENTS_STEP
import it.fabiodalez.incitta.data.management.SPONSORSHIP_SEARCH_MAX
import it.fabiodalez.incitta.data.management.SPONSORSHIP_NOTE_MAX
import it.fabiodalez.incitta.data.management.SponsorshipAnalytics
import it.fabiodalez.incitta.data.management.SponsorshipCampaign
import it.fabiodalez.incitta.data.management.SponsorshipCampaignDetail
import it.fabiodalez.incitta.data.management.SponsorshipCampaignOption
import it.fabiodalez.incitta.data.management.SponsorshipCatalog
import it.fabiodalez.incitta.data.management.SponsorshipChoice
import it.fabiodalez.incitta.data.management.SponsorshipClick
import it.fabiodalez.incitta.data.management.SponsorshipDesk
import it.fabiodalez.incitta.data.management.SponsorshipDuration
import it.fabiodalez.incitta.data.management.SponsorshipEvent
import it.fabiodalez.incitta.data.management.SponsorshipFilters
import it.fabiodalez.incitta.data.management.SponsorshipFormat
import it.fabiodalez.incitta.data.management.SponsorshipGrantEvent
import it.fabiodalez.incitta.data.management.SponsorshipOrder
import it.fabiodalez.incitta.data.management.SponsorshipOrderEnvelope
import it.fabiodalez.incitta.data.management.asEventChoices
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.LocalDate
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Sponsorizzazioni (P6), come le pagine «Sponsorizzazioni» e «Statistiche
 * sponsorizzazioni» dei pannelli. Le regole (chi compra, il tetto degli
 * invii, quali eventi) le decide il server; qui si mostrano solo i pulsanti
 * che lui concede (`can_purchase`, `can_retry`, `can_stop`, …).
 *
 * Il pagamento si fa nel browser (decisione 1): l'app apre `checkout_url`,
 * ricorda l'ordine nello stato salvato e, quando torna in primo piano o
 * arriva dal ritorno di Stripe, chiede al server se è pagato (`reconcile`).
 */

/** Il soggetto della schermata; `null` se la persona non lo gestisce (più). */
internal suspend fun resolveSponsorshipSubject(repository: ManagementRepository, key: String?): ManagementSubject? {
    val pick: (ManagementSubjects) -> ManagementSubject? = { list ->
        if (key.isNullOrBlank()) list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull() else list.find(key)
    }
    val found = repository.subjects.value?.let(pick) ?: pick(repository.loadSubjects())
    found?.let { repository.selectedSubjectKey = it.key }
    return found
}

internal fun ManagementRepository.sponsorshipsApi(): ManagementSponsorshipsSource =
    sponsorships ?: throw IllegalStateException("Sponsorizzazioni non disponibili in questa sessione")

/** Il messaggio del server se c'è, altrimenti quello dell'app. */
private fun serverText(message: String?, fallback: Int): UiText = message?.takeIf { it.isNotBlank() }?.let(UiText::Raw) ?: UiText.Res(fallback)

// ---------------------------------------------------------- la pagina

/** Un'azione da confermare: una alla volta. */
internal sealed interface SponsorshipConfirm {
    data class Cancel(val order: SponsorshipOrder) : SponsorshipConfirm
    data class Withdraw(val order: SponsorshipOrder) : SponsorshipConfirm
    data class Stop(val row: SponsorshipGrantEvent) : SponsorshipConfirm
}

/**
 * La scelta di un evento da mettere in evidenza con un periodo attivo
 * (W-SP-09), nel selettore unico `common/EventPickerSheet`: il foglio cerca
 * da sé con `pickerChoices`; qui restano gli eventi già in evidenza sul
 * periodo scelto e un contatore che, cambiando, gli fa rileggere l'elenco.
 */
internal data class ActivationPicker(
    val active: Set<Long> = emptySet(),
    val revision: Int = 0,
)

internal data class SponsorshipsUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    val desk: Loadable<SponsorshipDesk> = Loadable.Loading,
    val campaigns: List<SponsorshipCampaign> = emptyList(),
    val campaignsNextPage: Int? = null,
    /* Il periodo «a scelta» con cui si mettono in evidenza gli eventi; il primo scade per primo. */
    val grantId: Long? = null,
    val picker: ActivationPicker? = null,
    val busy: Boolean = false,
    val message: UiText? = null,
    val error: UiText? = null,
    val confirm: SponsorshipConfirm? = null,
    /* La pagina di pagamento da aprire nel browser: la schermata la apre e la consuma. */
    val checkoutUrl: String? = null,
    /* Arrivati dalla scheda di un evento: si apre la scelta con l'evento già scelto. */
    val openWizardFor: Long? = null,
)

internal class SponsorshipsViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(SponsorshipsUiState(grantId = saved.get<Long>(KEY_GRANT)?.takeIf { it > 0 }))
    val uiState: StateFlow<SponsorshipsUiState> = state.asStateFlow()

    init {
        /* Il ritorno da Stripe arriva una volta: il flag nello stato salvato evita di rifarlo dopo una rotazione. */
        if (saved.get<Boolean>(KEY_RETURN_HANDLED) != true) {
            saved.get<Long>(KEY_ORDER)?.takeIf { it > 0 }?.let { saved[KEY_PENDING] = it }
            saved[KEY_RETURN_HANDLED] = true
        }
        if (saved.get<Boolean>(KEY_SENT) == true && saved.get<Boolean>(KEY_SENT_SHOWN) != true) {
            saved[KEY_SENT_SHOWN] = true
            state.update { it.copy(message = UiText.Res(R.string.sponsorships_request_sent)) }
        }
        start()
    }

    /** Trova il soggetto e legge la pagina; «Riprova» riparte da qui. */
    fun start() {
        state.update { it.copy(subject = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val subject = resolveSponsorshipSubject(repository, key)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (subject != null) {
                    load()
                    reconcilePending()
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subject = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun load() {
        val subject = state.value.subject.valueOrNull ?: return
        state.update { it.copy(desk = if (it.desk is Loadable.Ready) it.desk else Loadable.Loading, busy = true) }
        viewModelScope.launch {
            try {
                val desk = repository.sponsorshipsApi().desk(subject)
                val grant = state.value.grantId?.takeIf { id -> desk.selectableGrants.any { it.id == id } } ?: desk.selectableGrants.firstOrNull()?.id
                val wizardEvent = saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 && desk.canPurchase && saved.get<Boolean>(KEY_EVENT_HANDLED) != true }
                if (wizardEvent != null) saved[KEY_EVENT_HANDLED] = true
                state.update { it.copy(desk = Loadable.Ready(desk), campaigns = desk.campaigns, campaignsNextPage = desk.campaignsNextPage, grantId = grant, openWizardFor = wizardEvent ?: it.openWizardFor) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { current ->
                    if (current.desk is Loadable.Ready) current.copy(error = UiText.Raw(requestFailureMessage(e)))
                    else current.copy(desk = Loadable.Failed(requestFailureMessage(e)))
                }
            } finally { state.update { it.copy(busy = false) } }
        }
    }

    /** Altre campagne della redazione, dieci alla volta. */
    fun moreCampaigns() {
        val subject = state.value.subject.valueOrNull ?: return
        val page = state.value.campaignsNextPage ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true) }
        viewModelScope.launch {
            try {
                val next = repository.sponsorshipsApi().campaigns(subject, page)
                state.update { it.copy(campaigns = (it.campaigns + next.data).distinctBy(SponsorshipCampaign::id), campaignsNextPage = next.meta.nextPage) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /**
     * Torna in primo piano: se un pagamento era aperto, chiede al server com'è
     * andata. Lo chiama la schermata a ogni ripresa.
     */
    fun onResume() { if (state.value.subject is Loadable.Ready && saved.get<Long>(KEY_PENDING)?.let { it > 0 } == true) viewModelScope.launch { reconcilePending() } }

    private suspend fun reconcilePending() {
        val subject = state.value.subject.valueOrNull ?: return
        val order = saved.get<Long>(KEY_PENDING)?.takeIf { it > 0 } ?: return
        val cancelled = saved.get<Boolean>(KEY_CANCELLED) == true
        saved[KEY_PENDING] = 0L
        saved[KEY_CANCELLED] = false
        try {
            val result = repository.sponsorshipsApi().reconcile(subject, order, cancelled)
            state.update { it.copy(message = serverText(result.message, R.string.sponsorships_checked)) }
            load()
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) { state.update { it.copy(error = UiText.Raw(requestFailureMessage(e))) } }
    }

    /** «Riprova il pagamento» (W-SP-06): riapre la pagina di Stripe nel browser. */
    fun retry(order: SponsorshipOrder) = act { subject, api -> handleOrder(api.retry(subject, order.id)) }

    fun askCancel(order: SponsorshipOrder) { state.update { it.copy(confirm = SponsorshipConfirm.Cancel(order)) } }
    fun askWithdraw(order: SponsorshipOrder) { state.update { it.copy(confirm = SponsorshipConfirm.Withdraw(order)) } }
    fun askStop(row: SponsorshipGrantEvent) { state.update { it.copy(confirm = SponsorshipConfirm.Stop(row)) } }
    fun dismissConfirm() { state.update { it.copy(confirm = null) } }

    /** La conferma dell'azione in sospeso: annulla (W-SP-07), ritira (W-SP-08), interrompi (W-SP-10). */
    fun confirm() {
        val pending = state.value.confirm ?: return
        state.update { it.copy(confirm = null) }
        when (pending) {
            is SponsorshipConfirm.Cancel -> act { subject, api -> handleOrder(api.cancel(subject, pending.order.id)) }
            is SponsorshipConfirm.Withdraw -> act { subject, api -> handleOrder(api.withdraw(subject, pending.order.id)) }
            is SponsorshipConfirm.Stop -> act { subject, api ->
                val result = api.stop(subject, pending.row.id)
                serverText(result.message, R.string.sponsorships_stopped)
            }
        }
    }

    private fun handleOrder(result: SponsorshipOrderEnvelope): UiText {
        val url = result.data.checkoutUrl?.takeIf { result.data.mode == "checkout" && it.startsWith("https://") }
        if (url != null) {
            result.data.order?.id?.let { saved[KEY_PENDING] = it }
            state.update { it.copy(checkoutUrl = url) }
            return UiText.Res(R.string.sponsorships_checkout_opened)
        }
        return serverText(result.message, R.string.sponsorships_saved)
    }

    fun consumeCheckout() { state.update { it.copy(checkoutUrl = null) } }
    fun consumeMessage() { state.update { it.copy(message = null, error = null) } }
    fun consumeWizard() { state.update { it.copy(openWizardFor = null) } }

    /** Il periodo con cui mettere in evidenza, quando ce n'è più d'uno. */
    fun chooseGrant(id: Long) {
        saved[KEY_GRANT] = id
        state.update { it.copy(grantId = id, picker = it.picker?.let { picker -> ActivationPicker(revision = picker.revision + 1) }) }
    }

    fun openPicker() { state.update { it.copy(picker = ActivationPicker()) } }

    fun closePicker() { state.update { it.copy(picker = null) } }

    /**
     * La ricerca del foglio: gli eventi che il soggetto può mettere in
     * evidenza con il periodo scelto (al massimo `SPONSORSHIP_EVENTS_MAX`,
     * come il pannello), già nella forma del selettore unico. Ricorda quali
     * sono già in evidenza, così il foglio li mostra con il loro stato.
     */
    suspend fun pickerChoices(query: String): EventChoiceGroups {
        val subject = state.value.subject.valueOrNull ?: return EventChoiceGroups()
        val page = repository.sponsorshipsApi().events(subject, query.take(SPONSORSHIP_SEARCH_MAX), SPONSORSHIP_EVENTS_MAX, state.value.grantId)
        val active = page.data.filter { it.activeOnGrant }.map { it.id }.toSet()
        state.update { it.copy(picker = it.picker?.copy(active = it.picker.active + active)) }
        return page.data.asEventChoices(subject.timezone)
    }

    /** «Metti in evidenza» (W-SP-09): l'evento entra subito, senza altre richieste. */
    fun activate(eventId: Long) {
        val grant = state.value.grantId ?: return
        if (state.value.picker?.active?.contains(eventId) == true) return
        act { subject, api ->
            val result = api.activate(subject, grant, eventId)
            state.update { it.copy(picker = it.picker?.let { picker -> picker.copy(active = picker.active + eventId, revision = picker.revision + 1) }) }
            serverText(result.message, R.string.sponsorships_activated)
        }
    }

    private fun act(call: suspend (ManagementSubject, ManagementSponsorshipsSource) -> UiText) {
        val subject = state.value.subject.valueOrNull ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val text = call(subject, repository.sponsorshipsApi())
                state.update { it.copy(message = text) }
                load()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { it.copy(error = UiText.Raw(requestFailureMessage(e)), busy = false) }
                load()
            }
        }
    }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val KEY_EVENT = "event"
        const val KEY_ORDER = "order"
        const val KEY_CANCELLED = "cancelled"
        const val KEY_SENT = "sent"
        const val KEY_PENDING = "pending_order"
        const val KEY_GRANT = "grant"
        private const val KEY_RETURN_HANDLED = "return_handled"
        private const val KEY_SENT_SHOWN = "sent_shown"
        private const val KEY_EVENT_HANDLED = "event_handled"
    }
}

// ------------------------------------------------ la scelta in quattro passi

/** I quattro passi del pannello: cosa, dove, per quanto, riepilogo. */
internal enum class WizardStep { What, Where, Time, Summary }

internal data class SponsorshipWizardUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    val catalog: Loadable<SponsorshipCatalog> = Loadable.Loading,
    val step: WizardStep = WizardStep.What,
    /* `event` o `all`, come il pannello. */
    val scope: String = "event",
    val event: SponsorshipEvent? = null,
    val placement: String? = null,
    val duration: String? = null,
    val note: String = "",
    val query: String = "",
    val events: List<SponsorshipEvent> = emptyList(),
    val eventsLimit: Int = SPONSORSHIP_EVENTS_STEP,
    val eventsHasMore: Boolean = false,
    val eventsTotal: Int = 0,
    val eventsLoaded: Boolean = false,
    val eventsBusy: Boolean = false,
    val busy: Boolean = false,
    val errors: FormErrors = FormErrors.None,
    /* L'esito dell'invio: la schermata apre il pagamento o torna alla pagina. */
    val result: WizardResult? = null,
) {
    val format: SponsorshipFormat? get() = catalog.valueOrNull?.placements?.firstOrNull { it.value == placement }
    val chosenDuration: SponsorshipDuration? get() = format?.durations?.firstOrNull { it.key == duration }
    /* Si paga online solo con Stripe e una voce del listino; altrimenti è una richiesta con la nota. */
    val viaStripe: Boolean get() = chosenDuration?.paysOnline == true
    val canAdvance: Boolean get() = when (step) {
        WizardStep.What -> scope == "all" || event != null
        WizardStep.Where -> format != null
        WizardStep.Time -> chosenDuration != null
        WizardStep.Summary -> (scope == "all" || event != null) && chosenDuration != null && note.length <= SPONSORSHIP_NOTE_MAX
    }
}

internal sealed interface WizardResult {
    data class Checkout(val url: String, val orderId: Long) : WizardResult
    data object Requested : WizardResult
}

internal class SponsorshipWizardViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(SponsorshipWizardUiState(
        step = saved.get<String>(KEY_STEP)?.let { name -> WizardStep.entries.firstOrNull { it.name == name } } ?: WizardStep.What,
        scope = saved.get<String>(KEY_SCOPE)?.takeIf { it == "all" } ?: "event",
        placement = saved[KEY_PLACEMENT],
        duration = saved[KEY_DURATION],
        note = saved[KEY_NOTE] ?: "",
        query = saved[KEY_QUERY] ?: "",
    ))
    val uiState: StateFlow<SponsorshipWizardUiState> = state.asStateFlow()
    private var eventsJob: Job? = null

    init { start() }

    fun start() {
        state.update { it.copy(subject = Loadable.Loading, catalog = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = resolveSponsorshipSubject(repository, key)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (subject == null) return@launch
                val catalog = repository.sponsorshipsApi().catalog(subject)
                state.update { it.copy(catalog = Loadable.Ready(catalog)) }
                loadEvents(preselect = saved.get<Long>(KEY_EVENT)?.takeIf { it > 0 })
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val message = requestFailureMessage(e)
                state.update { if (it.subject is Loadable.Ready) it.copy(catalog = Loadable.Failed(message)) else it.copy(subject = Loadable.Failed(message)) }
            }
        }
    }

    fun chooseScope(scope: String) {
        if (scope != "event" && scope != "all") return
        saved[KEY_SCOPE] = scope
        state.update { it.copy(scope = scope, errors = FormErrors.None) }
    }

    fun chooseEvent(event: SponsorshipEvent) {
        saved[KEY_EVENT] = event.id
        state.update { it.copy(event = event, scope = "event", errors = FormErrors.None) }
        saved[KEY_SCOPE] = "event"
    }

    /** Come il pannello: cambiando posizione resta la durata se c'è ancora, altrimenti la prima. */
    fun choosePlacement(value: String) {
        val format = state.value.catalog.valueOrNull?.placements?.firstOrNull { it.value == value } ?: return
        val keep = state.value.duration?.takeIf { key -> format.durations.any { it.key == key } } ?: format.durations.firstOrNull()?.key
        saved[KEY_PLACEMENT] = value
        saved[KEY_DURATION] = keep
        state.update { it.copy(placement = value, duration = keep, errors = FormErrors.None) }
    }

    fun chooseDuration(key: String) {
        if (state.value.format?.durations?.any { it.key == key } != true) return
        saved[KEY_DURATION] = key
        state.update { it.copy(duration = key, errors = FormErrors.None) }
    }

    fun editNote(value: String) {
        val clean = value.take(SPONSORSHIP_NOTE_MAX)
        saved[KEY_NOTE] = clean
        state.update { it.copy(note = clean) }
    }

    fun next() {
        val current = state.value
        if (!current.canAdvance || current.step == WizardStep.Summary) return
        go(WizardStep.entries[current.step.ordinal + 1])
    }

    /** Indietro di un passo; dal primo restituisce false e la schermata si chiude. */
    fun previous(): Boolean {
        val current = state.value.step
        if (current == WizardStep.What) return false
        go(WizardStep.entries[current.ordinal - 1])
        return true
    }

    fun goTo(step: WizardStep) { if (step.ordinal <= state.value.step.ordinal) go(step) }

    private fun go(step: WizardStep) {
        saved[KEY_STEP] = step.name
        state.update { it.copy(step = step) }
    }

    fun search(query: String) {
        val clean = query.trim().take(80)
        saved[KEY_QUERY] = clean
        state.update { it.copy(query = clean, eventsLimit = SPONSORSHIP_EVENTS_STEP) }
        loadEvents()
    }

    /** «Mostra altri eventi»: sei alla volta, fino a sessanta. */
    fun moreEvents() {
        state.update { it.copy(eventsLimit = (it.eventsLimit + SPONSORSHIP_EVENTS_STEP).coerceAtMost(SPONSORSHIP_EVENTS_MAX)) }
        loadEvents()
    }

    private fun loadEvents(preselect: Long? = null) {
        val subject = state.value.subject.valueOrNull ?: return
        val query = state.value.query
        val limit = if (preselect != null) SPONSORSHIP_EVENTS_MAX else state.value.eventsLimit
        eventsJob?.cancel()
        state.update { it.copy(eventsBusy = true) }
        eventsJob = viewModelScope.launch {
            try {
                val page = repository.sponsorshipsApi().events(subject, query, limit, null)
                val chosen = preselect?.let { id -> page.data.firstOrNull { it.id == id } }
                state.update { current ->
                    current.copy(
                        events = if (preselect != null) page.data.take(current.eventsLimit.coerceAtLeast(page.data.indexOfFirst { it.id == preselect } + 1)) else page.data,
                        eventsHasMore = page.meta.hasMore || (preselect != null && page.data.size > current.eventsLimit),
                        eventsTotal = page.meta.total,
                        eventsLoaded = true,
                        eventsBusy = false,
                        event = chosen ?: current.event?.let { selected -> page.data.firstOrNull { it.id == selected.id } ?: selected },
                        scope = if (chosen != null) "event" else current.scope,
                    )
                }
                if (chosen == null && preselect != null) saved[KEY_EVENT] = 0L
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(eventsBusy = false, errors = FormErrors(general = requestFailureMessage(e))) } }
        }
    }

    /** «Vai al pagamento» o «Invia la richiesta» (W-SP-03). */
    fun submit() {
        val current = state.value
        val subject = current.subject.valueOrNull ?: return
        if (current.busy || !current.canAdvance || current.step != WizardStep.Summary) return
        val placement = current.placement ?: return
        val duration = current.duration ?: return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val choice = SponsorshipChoice(current.scope, current.event?.id, placement, duration, current.note.takeIf { !current.viaStripe })
                val response = repository.sponsorshipsApi().submit(subject, choice)
                val url = response.data.checkoutUrl?.takeIf { response.data.mode == "checkout" && it.startsWith("https://") }
                val orderId = response.data.order?.id ?: 0L
                state.update { it.copy(busy = false, result = if (url != null) WizardResult.Checkout(url, orderId) else WizardResult.Requested) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val errors = FormErrors.from(e, FIELDS)
                /* Un errore sull'evento o sulla durata riporta al passo che lo riguarda. */
                val step = when {
                    errors.field("event_id") != null || errors.field("scope") != null -> WizardStep.What
                    errors.field("placement") != null -> WizardStep.Where
                    errors.field("duration") != null -> WizardStep.Time
                    else -> WizardStep.Summary
                }
                saved[KEY_STEP] = step.name
                state.update { it.copy(busy = false, errors = errors, step = step) }
            }
        }
    }

    fun consumeResult() { state.update { it.copy(result = null) } }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val KEY_EVENT = "event"
        private const val KEY_STEP = "step"
        private const val KEY_SCOPE = "scope"
        private const val KEY_PLACEMENT = "placement"
        private const val KEY_DURATION = "duration"
        private const val KEY_NOTE = "note"
        private const val KEY_QUERY = "query"
        val FIELDS = listOf("scope", "event_id", "placement", "duration", "note")
    }
}

// --------------------------------------------------------- una campagna

internal data class SponsorshipCampaignUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    val campaign: Loadable<SponsorshipCampaignDetail> = Loadable.Loading,
)

internal class SponsorshipCampaignViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved["subject"]
    val id: Long = saved.get<Long>("id") ?: 0L
    private val state = MutableStateFlow(SponsorshipCampaignUiState())
    val uiState: StateFlow<SponsorshipCampaignUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(campaign = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = state.value.subject.valueOrNull ?: resolveSponsorshipSubject(repository, key)
                if (subject == null) { state.update { it.copy(subject = Loadable.Empty, campaign = Loadable.Empty) }; return@launch }
                state.update { it.copy(subject = Loadable.Ready(subject)) }
                val detail = repository.sponsorshipsApi().campaign(subject, id)
                state.update { it.copy(campaign = Loadable.Ready(detail)) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(campaign = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }
}

// ------------------------------------------------------ le statistiche

internal data class SponsorshipAnalyticsUiState(
    val subject: Loadable<ManagementSubject> = Loadable.Loading,
    val filters: SponsorshipFilters = SponsorshipFilters(),
    val report: Loadable<SponsorshipAnalytics> = Loadable.Loading,
    val clicks: List<SponsorshipClick> = emptyList(),
    val clicksNextPage: Int? = null,
    val clicksTotal: Int = 0,
    val clicksBusy: Boolean = false,
    val campaignTitle: String? = null,
    /* La scelta della campagna: aperta con l'elenco e la ricerca. */
    val options: List<SponsorshipCampaignOption>? = null,
    val optionsQuery: String = "",
    val optionsBusy: Boolean = false,
    val error: String? = null,
)

internal class SponsorshipAnalyticsViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved[KEY_SUBJECT]
    private val state = MutableStateFlow(SponsorshipAnalyticsUiState(filters = SponsorshipFilters(
        days = saved.get<String>(KEY_DAYS)?.takeIf { it in PERIODS } ?: "30",
        from = saved[KEY_FROM],
        to = saved[KEY_TO],
        campaignId = saved.get<Long>(KEY_CAMPAIGN)?.takeIf { it > 0 },
    )))
    val uiState: StateFlow<SponsorshipAnalyticsUiState> = state.asStateFlow()
    private var job: Job? = null
    private var optionsJob: Job? = null

    init { start() }

    fun start() {
        state.update { it.copy(subject = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val subject = resolveSponsorshipSubject(repository, key)
                state.update { it.copy(subject = subject?.let { found -> Loadable.Ready(found) } ?: Loadable.Empty) }
                if (subject != null) load()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subject = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun load() {
        val subject = state.value.subject.valueOrNull ?: return
        val filters = state.value.filters
        if (filters.days == "custom" && (filters.from == null || filters.to == null)) return
        job?.cancel()
        state.update { it.copy(report = if (it.report is Loadable.Ready) it.report else Loadable.Loading, clicksBusy = true, error = null) }
        job = viewModelScope.launch {
            try {
                val api = repository.sponsorshipsApi()
                val report = api.analytics(subject, filters)
                val clicks = api.clicks(subject, filters, 1)
                state.update { it.copy(report = Loadable.Ready(report), clicks = clicks.data, clicksNextPage = clicks.meta.nextPage, clicksTotal = clicks.meta.total, clicksBusy = false,
                    campaignTitle = report.campaign?.let { c -> c.event?.title ?: c.eventGone }) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { current ->
                    if (current.report is Loadable.Ready) current.copy(error = requestFailureMessage(e), clicksBusy = false)
                    else current.copy(report = Loadable.Failed(requestFailureMessage(e)), clicksBusy = false)
                }
            }
        }
    }

    /** Il periodo; «Fra due date» aspetta le due date prima di leggere. */
    fun choosePeriod(days: String) {
        if (days !in PERIODS) return
        saved[KEY_DAYS] = days
        state.update { it.copy(filters = it.filters.copy(days = days)) }
        load()
    }

    /** Le date di «Fra due date», nel fuso della città; `to` non prima di `from`. */
    fun chooseDates(from: LocalDate?, to: LocalDate?) {
        val first = from?.toString()
        val last = to?.let { end -> if (from != null && end.isBefore(from)) from else end }?.toString()
        saved[KEY_FROM] = first
        saved[KEY_TO] = last
        state.update { it.copy(filters = it.filters.copy(from = first, to = last)) }
        load()
    }

    fun openOptions() {
        state.update { it.copy(options = emptyList(), optionsQuery = "") }
        loadOptions()
    }

    fun closeOptions() { optionsJob?.cancel(); state.update { it.copy(options = null) } }

    fun searchOptions(query: String) {
        state.update { it.copy(optionsQuery = query.take(120)) }
        loadOptions()
    }

    private fun loadOptions() {
        val subject = state.value.subject.valueOrNull ?: return
        val query = state.value.optionsQuery
        optionsJob?.cancel()
        state.update { it.copy(optionsBusy = true) }
        optionsJob = viewModelScope.launch {
            try {
                val list = repository.sponsorshipsApi().campaignOptions(subject, query)
                state.update { it.copy(options = if (it.options == null) null else list, optionsBusy = false) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(optionsBusy = false, error = requestFailureMessage(e)) } }
        }
    }

    /** Una campagna sola, o tutte (`null`). */
    fun chooseCampaign(option: SponsorshipCampaignOption?) {
        saved[KEY_CAMPAIGN] = option?.id ?: 0L
        state.update { it.copy(filters = it.filters.copy(campaignId = option?.id), options = null, campaignTitle = option?.let { c -> c.event?.title ?: c.eventGone }) }
        load()
    }

    /** Altri clic del registro, 25 alla volta. */
    fun moreClicks() {
        val subject = state.value.subject.valueOrNull ?: return
        val page = state.value.clicksNextPage ?: return
        if (state.value.clicksBusy) return
        val filters = state.value.filters
        state.update { it.copy(clicksBusy = true) }
        viewModelScope.launch {
            try {
                val next = repository.sponsorshipsApi().clicks(subject, filters, page)
                state.update { it.copy(clicks = (it.clicks + next.data).distinctBy(SponsorshipClick::id), clicksNextPage = next.meta.nextPage, clicksBusy = false) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(clicksBusy = false, error = requestFailureMessage(e)) } }
        }
    }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val KEY_CAMPAIGN = "campaign"
        private const val KEY_DAYS = "days"
        private const val KEY_FROM = "from"
        private const val KEY_TO = "to"
        val PERIODS = listOf("7", "30", "90", "365", "all", "custom")
    }
}
