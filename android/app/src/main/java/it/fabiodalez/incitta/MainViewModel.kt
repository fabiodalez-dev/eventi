package it.fabiodalez.incitta

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.loginFailureMessage
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.data.requiresEmailVerification
import it.fabiodalez.incitta.data.isEmailConfirmationLink
import it.fabiodalez.incitta.data.shouldShowOffline
import it.fabiodalez.incitta.data.selectOccurrence
import it.fabiodalez.incitta.data.AppRepository
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.EventFilter
import it.fabiodalez.incitta.data.MapMarker
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Tag
import it.fabiodalez.incitta.data.Venue
import it.fabiodalez.incitta.data.Session
import java.io.IOException
import kotlinx.coroutines.Job
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.launch

enum class AppTab { HOME, EVENTS, MAP, SEARCH, SAVED, ACCOUNT, CALENDAR, VENUES, TICKETS, ATTENDED }

internal fun navigationTarget(tab: AppTab, authenticated: Boolean): AppTab =
    if (tab == AppTab.SAVED && !authenticated) AppTab.ACCOUNT else tab

data class AppUiState(
    val communityDestination: String? = null,
    val appearance: String = "dark",
    val defaultAppearance: String = "dark",
    val appearanceSaving: Boolean = false,
    val bookings: List<it.fabiodalez.incitta.data.Booking> = emptyList(),
    /* Prenotazioni recenti a cui la persona non si è presentata (`meta.no_shows`). */
    val noShows: Int = 0,
    val bookingDate: Occurrence? = null,
    val bookingAvailability: it.fabiodalez.incitta.data.BookingAvailability? = null,
    val bookingBusy: Boolean = false,
    val bookingError: String? = null,
    /**
     * L'ultimo errore dei biglietti è il 403 `EMAIL_VERIFICATION_REQUIRED`:
     * sotto il messaggio del server compare l'invito a confermare l'email.
     */
    val bookingVerificationRequired: Boolean = false,
    val bookingRequestKey: String = "",
    /*
     * Il biglietto nel portafoglio: `walletEnabled` arriva dal server e vale
     * come permesso di disegnare il pulsante — finché l'emittente Google non
     * è configurato resta falso e il pulsante non esiste. `walletLink` è il
     * link firmato da aprire una volta sola, poi la schermata lo consuma.
     */
    val walletEnabled: Boolean = false,
    val walletLink: String? = null,
    /** 11. Valorizzato solo quando i biglietti mostrati vengono dalla copia locale. */
    val bookingsSavedAt: String? = null,
    /** 8 e 9. Dalle preferenze di contenuto della persona. */
    val hiddenVenueIds: Set<Long> = emptySet(),
    val accessNeeds: List<String> = emptyList(),
    val profileAccessDismissed: Boolean = false,
    /** 5. Il filtro «Aperti adesso» dell'elenco dei locali, con il suo elenco a parte. */
    val venuesOpenNow: Boolean = false,
    val openNowVenues: List<Venue>? = null,
    val openNowLoading: Boolean = false,
    val openNowError: String? = null,
    /** 13. Le date per cui la persona aspetta un avviso. */
    val availabilityAlerts: Set<Long> = emptySet(),
    /** Le date per cui la persona aspetta l'apertura delle prenotazioni. */
    val openingAlerts: Set<Long> = emptySet(),
    /** Gli eventi di cui la persona aspetta la prossima edizione. */
    val editionAlerts: Set<Long> = emptySet(),
    val editionBusy: Boolean = false,
    val alertBusy: Set<Long> = emptySet(),
    /** 15. I link «sono qui» creati in questa sessione dell'app, per data. */
    val presenceLinks: Map<Long, it.fabiodalez.incitta.data.PresenceLink> = emptyMap(),
    val presenceBusy: Boolean = false,
    val tab: AppTab = AppTab.HOME,
    val sponsoredBanner: it.fabiodalez.incitta.data.SponsoredBanner? = null,
    val occurrences: List<Occurrence> = emptyList(),
    val eventFilter: EventFilter = EventFilter.ALL,
    val searchResults: List<Occurrence> = emptyList(),
    val searchNextCursor: String? = null,
    val searchError: String? = null,
    val isLoadingMoreEvents: Boolean = false,
    val searchVenues: List<Venue> = emptyList(),
    val searchOrganizers: List<it.fabiodalez.incitta.data.Organizer> = emptyList(),
    val searchTags: List<Tag> = emptyList(),
    val activeTag: Tag? = null,
    val discoveryFilters: Map<String, String> = emptyMap(),
    val discoverySummary: String? = null,
    val venues: List<Venue> = emptyList(),
    val mapFilter: EventFilter = EventFilter.TODAY,
    val mapSearchFilters: Map<String, String>? = null,
    val mapMarkers: List<MapMarker> = emptyList(),
    val mapPreviewEvents: List<Occurrence> = emptyList(),
    val mapPreviewTotal: Int = 0,
    val isMapLoading: Boolean = false,
    val savedOccurrences: List<Occurrence> = emptyList(),
    val savedIds: Set<Long> = emptySet(),
    val session: Session? = null,
    val selected: EventDetail? = null,
    val relatedOccurrences: List<Occurrence> = emptyList(),
    val selectedVenue: Venue? = null,
    val venueOccurrences: List<Occurrence> = emptyList(),
    val venuePastOccurrences: List<Occurrence> = emptyList(),
    val privacyConsent: Boolean? = null,
    val isLoading: Boolean = true,
    val isSearching: Boolean = false,
    val isAuthenticating: Boolean = false,
    val authError: String? = null,
    val isOffline: Boolean = false,
    val message: String? = null,
    /* Il gettone di una raccolta condivisa aperta da un link `/raccolte/{token}`. */
    val sharedCollectionToken: String? = null,
)

/**
 * Quando la schermata del biglietto disegna «Aggiungi a Google Wallet».
 *
 * Tre condizioni, e nessuna è superflua: il server deve dichiarare di saper
 * emettere il pass, il biglietto deve essere valido (un annullato o un già
 * usato non ha pass, e il server risponderebbe 404) e deve esserci il codice
 * di ingresso, perché è quello che finisce nel codice a barre del pass.
 */
internal fun AppUiState.offersWalletPass(ticket: it.fabiodalez.incitta.data.AdmissionTicket): Boolean =
    walletEnabled && ticket.status == "valid" && ticket.qrPayload != null

internal fun AppUiState.supportsSponsoredBanner(): Boolean = bookingDate == null &&
    (selected != null || selectedVenue != null || tab in listOf(AppTab.HOME, AppTab.SEARCH, AppTab.VENUES) ||
        (tab == AppTab.ACCOUNT && session != null))

class MainViewModel(application: Application) : AndroidViewModel(application) {
    private sealed interface DetailSnapshot {
        data class Event(val detail: EventDetail, val related: List<Occurrence>) : DetailSnapshot
        data class Place(val venue: Venue, val upcoming: List<Occurrence>, val past: List<Occurrence>) : DetailSnapshot
    }

    private val repository = AppRepository(application)
    private val _state = MutableStateFlow(
        AppUiState(
            appearance = repository.appearance(),
            defaultAppearance = repository.appearance(),
            occurrences = repository.cachedOccurrences(),
            session = repository.session.value,
            privacyConsent = repository.privacyConsent(),
        ),
    )
    val state: StateFlow<AppUiState> = _state.asStateFlow()
    fun openCommunityDestination(url: String) {
        _state.value = _state.value.copy(communityDestination = url)
    }
    fun openSharedCollection(token: String) {
        if (it.fabiodalez.incitta.data.CollectionsRoutes.validToken(token)) _state.value = _state.value.copy(sharedCollectionToken = token)
    }
    fun closeSharedCollection() {
        _state.value = _state.value.copy(sharedCollectionToken = null)
    }
    fun consumeCommunityDestination(url: String) {
        if(_state.value.communityDestination == url) _state.value = _state.value.copy(communityDestination = null)
    }
    private val bannerImpressions = mutableSetOf<Long>()
    private var discoveryGeneration = 0

    suspend fun refreshSponsoredBanner(excludeEvent: String?) {
        val generation = discoveryGeneration
        try {
            val current = _state.value
            val filters = current.discoveryFilters.toMutableMap()
            filters["categories"]?.let { filters["category"] = it }
            filters["preset"]?.let { filters["date"] = it }
            if (current.tab == AppTab.HOME) {
                when (current.eventFilter) {
                    EventFilter.TODAY -> filters["date"] = "today"
                    EventFilter.TOMORROW -> filters["date"] = "tomorrow"
                    EventFilter.WEEKEND -> filters["date"] = "weekend"
                    else -> Unit
                }
            }
            val banner = repository.sponsoredBanner(excludeEvent, current.selectedVenue?.slug, current.activeTag?.slug, filters)?.takeIf { it.validAt() && it.eventSlug != excludeEvent }
            if (generation == discoveryGeneration) _state.value = _state.value.copy(sponsoredBanner = banner)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            clearSponsoredBanner()
        }
    }

    fun clearSponsoredBanner() { _state.value = _state.value.copy(sponsoredBanner = null) }

    fun bannerMetric(banner: it.fabiodalez.incitta.data.SponsoredBanner, click: Boolean) {
        if (!banner.validAt() || (!click && !bannerImpressions.add(banner.id))) return
        val current = _state.value
        val page = when {
            current.selected != null -> "event"
            current.selectedVenue != null || current.tab == AppTab.VENUES -> "venues"
            current.tab == AppTab.ACCOUNT -> "profile"
            current.tab == AppTab.SEARCH -> "search"
            current.tab == AppTab.HOME -> "home"
            else -> "other"
        }
        viewModelScope.launch {
            try { repository.sponsorshipMetric(banner, click, page) }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (_: Exception) { /* Measurement failure must never interrupt navigation. */ }
        }
    }
    private var searchJob: Job? = null
    private var moreEventsJob: Job? = null
    private var currentSearchQuery = ""
    private var refreshJob: Job? = null
    private var mapJob: Job? = null
    private var detailJob: Job? = null
    private var bookingJob: Job? = null
    private var contentProfileJob: Job? = null
    private var openNowJob: Job? = null
    private val detailHistory = mutableListOf<DetailSnapshot>()

    init {
        viewModelScope.launch {
            combine(repository.session, repository.savedIds) { session, saved -> session to saved }
                .collect { (session, saved) ->
                    val sessionChanged = _state.value.session?.token != session?.token
                    if (sessionChanged) {
                        bookingJob?.cancel()
                        _state.value = _state.value.copy(bookings = emptyList(), noShows = 0, bookingDate = null, bookingAvailability = null, bookingBusy = false, bookingError = null, bookingVerificationRequired = false,
                            bookingsSavedAt = null, hiddenVenueIds = emptySet(), accessNeeds = emptyList(), profileAccessDismissed = false,
                            availabilityAlerts = emptySet(), openingAlerts = emptySet(), editionAlerts = emptySet(), alertBusy = emptySet(), presenceLinks = emptyMap(), presenceBusy = false)
                    }
                    _state.value = _state.value.copy(session = session, savedIds = saved, defaultAppearance = repository.appearance(), appearance = repository.appearance())
                    if (sessionChanged) {
                        interestsChanged()
                        if (session != null) refreshAvailabilityAlerts()
                    }
                }
        }
        refresh(EventFilter.ALL)
        loadVenues()
        loadMap()
        loadContentProfile()
        refreshAvailabilityAlerts()
    }

    /**
     * The header switch saves the choice like the website does. A session-only override used to
     * mask the profile: after one tap, changing the theme in the profile no longer showed, and the
     * tap itself was forgotten at the next launch.
     */
    fun toggleQuickAppearance() = setAppearance(if (_state.value.appearance == "light") "dark" else "light", announce = false)

    fun setAppearance(value: String, announce: Boolean = true) {
        if (_state.value.appearanceSaving || value !in listOf("dark", "light")) return
        val previous = _state.value.defaultAppearance
        val token = repository.session.value?.token
        _state.value = _state.value.copy(defaultAppearance = value, appearance = value, appearanceSaving = true)
        viewModelScope.launch {
            try {
                repository.setAppearance(value)
                _state.value = _state.value.copy(defaultAppearance = repository.appearance(), appearance = repository.appearance(), message = if (announce) "Tema predefinito salvato." else _state.value.message)
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (_: Exception) {
                if (repository.session.value?.token == token) _state.value = _state.value.copy(defaultAppearance = previous, appearance = previous, message = "Salvataggio non riuscito. Riprova quando sei online.")
            } finally { _state.value = _state.value.copy(appearanceSaving = false) }
        }
    }

    fun refreshProfile() { viewModelScope.launch { synchronizeAppearance() } }

    suspend fun synchronizeAppearance() {
        if (_state.value.appearanceSaving) return
        try { repository.refreshProfile() }
        catch (cancelled: CancellationException) { throw cancelled }
        catch (_: Exception) { /* Cached profile remains available offline. */ }
    }

    fun selectTab(tab: AppTab) {
        commentLoginSlug = null
        if (navigationTarget(tab, _state.value.session != null) != tab) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = getApplication<Application>().getString(R.string.saved_login_required))
            return
        }
        _state.value = _state.value.copy(bookingDate = null, bookingAvailability = null)
        detailHistory.clear()
        _state.value = _state.value.copy(tab = tab, selected = null, selectedVenue = null, mapPreviewEvents = emptyList(), mapPreviewTotal = 0, message = null, sharedCollectionToken = null)
        if (tab == AppTab.SAVED) loadSaved()
        if (tab == AppTab.MAP) loadMap()
        if (tab == AppTab.SEARCH || tab == AppTab.VENUES) loadVenues()
        if (tab == AppTab.CALENDAR) refresh(EventFilter.ALL)
        if (tab == AppTab.HOME) refresh()
        if (tab == AppTab.EVENTS) {
            _state.value = _state.value.copy(searchVenues = emptyList(), searchTags = emptyList(), searchOrganizers = emptyList())
            loadVenues()
            search("")
        }
        if (tab == AppTab.TICKETS) loadBookings()
        if (tab == AppTab.ACCOUNT && _state.value.session != null) viewModelScope.launch {
            val token = _state.value.session?.token
            try {
                repository.refreshProfile()
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: Exception) {
                if (_state.value.session?.token == token && _state.value.tab == AppTab.ACCOUNT) {
                    _state.value = _state.value.copy(message = userMessage(error))
                }
            }
        }
    }

    fun startReservation(date: Occurrence) {
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = getApplication<Application>().getString(R.string.ticket_login_reserve))
            return
        }
        bookingJob?.cancel()
        _state.value = _state.value.copy(bookingDate = date, bookingAvailability = null, bookingBusy = true, bookingError = null, bookingVerificationRequired = false, bookingRequestKey = java.util.UUID.randomUUID().toString())
        bookingJob = viewModelScope.launch {
            try {
                val availability = repository.bookingAvailability(date.occurrenceId)
                _state.value = _state.value.copy(bookingAvailability = availability, bookingBusy = false)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    fun reserve(names: List<it.fabiodalez.incitta.data.AttendeeName>, waitlist: Boolean, booker: Map<String, String>, answers: Map<String, String> = emptyMap()) {
        val date = _state.value.bookingDate ?: return
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        val key = _state.value.bookingRequestKey
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        bookingJob = viewModelScope.launch {
            try {
                val booking = repository.reserve(date.occurrenceId, it.fabiodalez.incitta.data.ReserveBody(names.map { it.copy(firstName = it.firstName.trim(), lastName = it.lastName.trim()) }, key, waitlist, true, booker.mapValues { it.value.trim() }, it.fabiodalez.incitta.data.answersPayload(_state.value.bookingAvailability?.questions.orEmpty(), answers)))
                if (repository.session.value?.token == token) {
                    _state.value = _state.value.copy(bookingBusy = false, bookingDate = null, selected = null, selectedVenue = null, tab = AppTab.TICKETS, bookings = listOf(booking), message = getApplication<Application>().getString(if (booking.status == "waitlisted") R.string.ticket_waitlisted_success else R.string.ticket_reserved_success))
                    loadBookings()
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    private var walletJob: Job? = null
    private var walletAsked = false

    /**
     * Si chiede una volta sola se il pass esiste: è una proprietà del server,
     * non dell'account, e non cambia mentre l'app è aperta.
     */
    private fun askWalletAvailability() {
        if (walletAsked) return
        walletAsked = true
        viewModelScope.launch {
            try {
                val enabled = repository.walletAvailable()
                _state.value = _state.value.copy(walletEnabled = enabled)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                // Rete assente al primo giro: si riprova al prossimo ingresso nei biglietti.
                walletAsked = false
            }
        }
    }

    fun addToWallet(ticketId: Long) {
        val token = _state.value.session?.token ?: return
        walletJob?.cancel()
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        walletJob = viewModelScope.launch {
            try {
                val link = repository.walletPass(ticketId)
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookingBusy = false, walletLink = link)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                /*
                 * 404 qui non è un guasto: è il server che dice che per QUESTO
                 * biglietto un pass non c'è — annullato, già usato, o funzione
                 * appena spenta. Un messaggio di rete sarebbe fuorviante.
                 */
                val message = if (error is ApiException && error.status == 404) {
                    getApplication<Application>().getString(R.string.ticket_wallet_unavailable)
                } else {
                    userMessage(error)
                }
                _state.value = _state.value.copy(bookingBusy = false, bookingError = message, bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    /** La schermata ha aperto il link: non va riaperto a ogni ricomposizione. */
    fun consumeWalletLink() {
        if (_state.value.walletLink != null) _state.value = _state.value.copy(walletLink = null)
    }

    fun loadBookings() {
        if (_state.value.session == null) return
        askWalletAvailability()
        bookingJob?.cancel()
        val token = _state.value.session?.token
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        bookingJob = viewModelScope.launch {
            try {
                val list = repository.bookingList()
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookings = list.bookings, noShows = list.noShows, bookingBusy = false, bookingsSavedAt = null)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                /*
                 * Senza rete si mostrano i biglietti dell'ultima lettura
                 * riuscita: all'ingresso il QR serve proprio quando la rete
                 * non c'è. Un errore del server (4xx, 5xx) non è «senza rete»
                 * e resta un errore.
                 */
                val cached = if (error is IOException && repository.session.value?.token == token) repository.cachedBookings() else null
                _state.value = if (cached != null) {
                    _state.value.copy(bookings = cached.bookings, bookingsSavedAt = cached.savedAt, bookingBusy = false, bookingError = null, bookingVerificationRequired = false)
                } else {
                    _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
                }
            }
        }
    }

    /** 12. Il biglietto mandato per email alla persona che lo userà. */
    fun sendTicket(ticketId: Long, email: String, onSent: () -> Unit) {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        if (!it.fabiodalez.incitta.data.plausibleEmail(email)) {
            _state.value = _state.value.copy(bookingError = getApplication<Application>().getString(R.string.ticket_send_invalid), bookingVerificationRequired = false)
            return
        }
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        viewModelScope.launch {
            try {
                val message = repository.sendTicket(ticketId, email)
                if (repository.session.value?.token == token) {
                    _state.value = _state.value.copy(bookingBusy = false, message = message ?: getApplication<Application>().getString(R.string.ticket_send_done))
                    onSent()
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    fun cancelBooking(id: Long, ticketId: Long?) {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        bookingJob = viewModelScope.launch {
            try {
                val booking = repository.cancelBooking(id, ticketId)
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookings = _state.value.bookings.map { if (it.id == id) booking else it }, bookingBusy = false, message = getApplication<Application>().getString(R.string.ticket_cancelled_success))
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    fun resendBooking(id: Long) {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null, bookingVerificationRequired = false)
        bookingJob = viewModelScope.launch {
            try {
                repository.resendBooking(id)
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookingBusy = false, message = getApplication<Application>().getString(R.string.ticket_email_queued))
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error), bookingVerificationRequired = requiresEmailVerification(error))
            }
        }
    }

    /**
     * Il reinvio della conferma dall'invito dei biglietti: lo stesso
     * `auth/verification/resend` dei commenti e della community. L'errore
     * resta dov'è, così l'invito non sparisce prima che la persona confermi.
     */
    fun resendEmailVerification() {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        _state.value = _state.value.copy(bookingBusy = true)
        viewModelScope.launch {
            val feedback = try {
                repository.resendConfirmation()
                text(R.string.email_verification_sent)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                userMessage(error)
            }
            _state.value = if (repository.session.value?.token == token) _state.value.copy(bookingBusy = false, message = feedback) else _state.value.copy(bookingBusy = false)
        }
    }

    /**
     * «Ho confermato»: si toglie l'invito e si rilegge il profilo. Il server
     * resta l'unico giudice: se l'indirizzo non è ancora confermato, il
     * prossimo tentativo riporta lo stesso 403 e l'invito ricompare.
     */
    fun emailConfirmed() {
        _state.value = _state.value.copy(bookingError = null, bookingVerificationRequired = false)
        refreshProfile()
    }

    /**
     * `incitta://auth/verify-email`: la pagina di conferma aperta nel browser
     * senza sessione riporta qui il collegamento firmato. Senza un account
     * collegato non si manda niente: confermare senza token verificherebbe
     * l'indirizzo e basta, che il browser ha già fatto.
     */
    fun confirmEmailLink(url: String) {
        val token = repository.session.value?.token
        if (token == null) {
            _state.value = _state.value.copy(message = text(R.string.email_link_login_first))
            return
        }
        if (!isEmailConfirmationLink(url, BuildConfig.API_BASE_URL)) {
            _state.value = _state.value.copy(message = text(R.string.email_link_invalid))
            return
        }
        viewModelScope.launch {
            val feedback = try {
                repository.confirmEmailLink(url)
                emailConfirmed()
                text(R.string.email_link_confirmed)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                userMessage(error)
            }
            if (repository.session.value?.token == token) _state.value = _state.value.copy(message = feedback)
        }
    }

    fun refresh(filter: EventFilter = _state.value.eventFilter) {
        refreshJob?.cancel()
        refreshJob = viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, eventFilter = filter, message = null)
            runCatching { repository.occurrences(filter) }
                .onSuccess {
                    if (_state.value.eventFilter == filter) {
                        _state.value = _state.value.copy(occurrences = it, isLoading = false, isOffline = false)
                    }
                }
                .onFailure { error ->
                    if (error is CancellationException) return@onFailure
                    _state.value = _state.value.copy(
                        isLoading = false,
                        isOffline = shouldShowOffline(error, _state.value.occurrences.isNotEmpty()),
                        message = userMessage(error),
                    )
                }
        }
    }

    fun interestsChanged() {
        moreEventsJob?.cancel()
        discoveryGeneration++
        detailJob?.cancel()
        detailHistory.clear()
        searchJob?.cancel()
        repository.clearDiscoveryCache()
        _state.value = _state.value.copy(occurrences = emptyList(), searchResults = emptyList(), searchOrganizers = emptyList(), searchVenues = emptyList(), searchTags = emptyList(), activeTag = null, mapMarkers = emptyList(), mapPreviewEvents = emptyList(), sponsoredBanner = null, relatedOccurrences = emptyList(), venueOccurrences = emptyList(), venuePastOccurrences = emptyList())
        refresh()
        loadMap()
        loadContentProfile()
        if (_state.value.venuesOpenNow) loadOpenNowVenues()
        if (_state.value.tab == AppTab.EVENTS || _state.value.tab == AppTab.SEARCH) search(currentSearchQuery)
        viewModelScope.launch { refreshSponsoredBanner(null) }
    }

    fun search(query: String) {
        searchJob?.cancel()
        moreEventsJob?.cancel()
        currentSearchQuery = query.trim()
        _state.value = _state.value.copy(searchNextCursor = null, searchError = null, isLoadingMoreEvents = false, message = null)
        if (_state.value.tab == AppTab.EVENTS || _state.value.discoveryFilters.isNotEmpty() || _state.value.discoverySummary != null) {
            val filters = _state.value.discoveryFilters
            // Keep the previous results readable while the next filter loads.
            _state.value = _state.value.copy(isSearching = true)
            searchJob = viewModelScope.launch {
                delay(280)
                runCatching { repository.filteredOccurrencesPage(filters, query.trim()) }
                    .onSuccess { _state.value = _state.value.copy(searchResults = it.data.distinctBy(Occurrence::occurrenceId), searchNextCursor = it.meta?.nextCursor, isSearching = false) }
                    .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(isSearching = false, searchError = userMessage(it)) }
            }
            return
        }
        _state.value = _state.value.copy(activeTag = null)
        if (query.trim().length < 3) {
            _state.value = _state.value.copy(searchResults = emptyList(), searchOrganizers = emptyList(), searchVenues = emptyList(), searchTags = emptyList(), isSearching = false)
            return
        }
        searchJob = viewModelScope.launch {
            delay(280)
            _state.value = _state.value.copy(isSearching = true, message = null)
            runCatching { repository.search(query.trim()) }
                .onSuccess {
                    _state.value = _state.value.copy(
                        searchResults = it.events,
                        searchVenues = it.venues,
                        searchOrganizers = it.organizers,
                        searchTags = it.tags,
                        isSearching = false,
                    )
                }
                .onFailure {
                    if (it !is CancellationException) {
                        _state.value = _state.value.copy(isSearching = false, message = userMessage(it))
                    }
                }
        }
    }

    fun loadMoreEvents() {
        val cursor = _state.value.searchNextCursor ?: return
        if (_state.value.isLoadingMoreEvents || _state.value.isSearching) return
        val filters = _state.value.discoveryFilters
        val query = currentSearchQuery
        _state.value = _state.value.copy(isLoadingMoreEvents = true, searchError = null)
        moreEventsJob = viewModelScope.launch {
            try {
                val page = repository.filteredOccurrencesPage(filters, query, cursor)
                _state.value = _state.value.copy(
                    searchResults = (_state.value.searchResults + page.data).distinctBy(Occurrence::occurrenceId),
                    searchNextCursor = page.meta?.nextCursor?.takeUnless { it == cursor },
                    isLoadingMoreEvents = false,
                )
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (error: Exception) {
                // A later page must never discard events already displayed. Keep its cursor for retry.
                _state.value = _state.value.copy(isLoadingMoreEvents = false, searchError = userMessage(error))
            }
        }
    }

    fun applyDiscovery(filters: Map<String, String>, summary: String) {
        selectTab(AppTab.SEARCH)
        _state.value = _state.value.copy(discoveryFilters = filters, discoverySummary = summary, activeTag = null,
            searchVenues = emptyList(), searchOrganizers = emptyList(), searchTags = emptyList())
        search("")
    }

    fun clearDiscovery() {
        _state.value = _state.value.copy(discoveryFilters = emptyMap(), discoverySummary = null)
        search("")
    }

    /**
     * «Avvisami per nuovi eventi come questi»: salva i filtri attivi e la
     * parola cercata come ricerca salvata. Fuori sessione porta all'accesso.
     */
    fun saveSearch(query: String) {
        val filters = _state.value.discoveryFilters
        if (!it.fabiodalez.incitta.data.SavedSearchFilters.canSave(filters, query)) return
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = getApplication<Application>().getString(R.string.saved_search_login))
            return
        }
        viewModelScope.launch {
            runCatching { repository.saveSearch(filters, query) }
                .onSuccess { _state.value = _state.value.copy(message = getApplication<Application>().getString(R.string.saved_search_stored, it.name)) }
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(message = userMessage(it)) }
        }
    }

    /** Apre una ricerca salvata nella pagina Eventi, con i suoi filtri. */
    fun openSavedSearch(search: it.fabiodalez.incitta.data.SavedSearch) {
        selectTab(AppTab.EVENTS)
        updateSearchFilters(it.fabiodalez.incitta.data.SavedSearchFilters.toApp(search.filters), search.name, search.filters["q"].orEmpty())
    }

    fun updateSearchFilters(filters: Map<String, String>, summary: String, query: String) {
        val tags = _state.value.activeTag?.slug
        val effective = if (tags != null) filters + ("tags" to tags) else filters
        _state.value = _state.value.copy(discoveryFilters = effective, discoverySummary = summary,
            searchVenues = emptyList(), searchOrganizers = emptyList(), searchTags = emptyList())
        search(query)
    }

    suspend fun synchronizeSaved() {
        val token = repository.session.value?.token ?: return
        try {
            val saved = repository.savedOccurrences()
            if (repository.session.value?.token == token) _state.value = _state.value.copy(savedOccurrences = saved)
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            // Keep the last successful snapshot when temporarily offline.
        }
    }

    fun open(occurrence: Occurrence) {
        detailJob?.cancel()
        detailJob = viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(isLoading = true, message = null)
            runCatching {
                val (detail, related) = detailWithRelated(occurrence.eventSlug)
                val venueSlug = occurrence.venue?.slug
                val venue = if (venueSlug != null && venueSlug != detail.venue?.slug) repository.venue(venueSlug) else detail.venue
                detail.selectOccurrence(occurrence, venue) to related
            }
                .onSuccess { (detail, related) ->
                    previous?.let(detailHistory::add)
                    refreshAvailabilityAlerts()
                    _state.value = _state.value.copy(
                        selected = detail,
                        selectedVenue = null,
                        relatedOccurrences = related,
                        mapPreviewEvents = emptyList(),
                        mapPreviewTotal = 0,
                        isLoading = false,
                    )
                }
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(isLoading = false, message = userMessage(it)) }
        }
    }

    fun openDate(slug: String, number: Int) {
        viewModelScope.launch {
            runCatching { repository.occurrenceByNumber(slug, number) }
                .onSuccess(::open)
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(message = userMessage(it)) }
        }
    }

    fun openOccurrence(id: Long) {
        viewModelScope.launch {
            runCatching { repository.occurrence(id) }.onSuccess(::open)
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(message = userMessage(it)) }
        }
    }

    fun openSlug(slug: String) {
        detailJob?.cancel()
        detailJob = viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(isLoading = true, message = null)
            runCatching { detailWithRelated(slug) }
                .onSuccess { (detail, related) ->
                    previous?.let(detailHistory::add)
                    _state.value = _state.value.copy(selected = detail, selectedVenue = null, relatedOccurrences = related, mapPreviewEvents = emptyList(), mapPreviewTotal = 0, isLoading = false)
                }
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(isLoading = false, message = userMessage(it)) }
        }
    }

    fun previewMarkers(markers: List<MapMarker>) {
        if (markers.isEmpty()) return
        viewModelScope.launch {
            runCatching {
                coroutineScope {
                    markers.take(20).map { marker -> async { repository.occurrence(marker.id) } }.awaitAll()
                }.sortedBy(Occurrence::startsAt)
            }
                .onSuccess {
                    _state.value = _state.value.copy(
                        mapPreviewEvents = it,
                        mapPreviewTotal = markers.size,
                        message = null,
                    )
                }
                .onFailure { _state.value = _state.value.copy(message = userMessage(it)) }
        }
    }

    fun dismissMapPreview() {
        _state.value = _state.value.copy(mapPreviewEvents = emptyList(), mapPreviewTotal = 0)
    }

    fun applyMapFilter(filter: EventFilter) {
        _state.value = _state.value.copy(mapSearchFilters = null)
        loadMap(filter)
    }

    fun updateMapFilters(filters: Map<String, String>) {
        _state.value = _state.value.copy(mapSearchFilters = filters)
        loadMap()
    }

    suspend fun eventWeather(id: Long) = repository.eventWeather(id)

    private var commentLoginSlug: String? = null
    fun loginForComments(slug: String) {
        selectTab(AppTab.ACCOUNT)
        commentLoginSlug = slug
    }
    suspend fun eventComments(slug: String, page: Int, thread: Long?, repliesPage: Int) = repository.eventComments(slug, page, thread, repliesPage)
    suspend fun postComment(slug: String, body: String, parentId: Long?) = repository.postComment(slug, body, parentId)
    suspend fun reactComment(slug: String, id: Long, type: String) = repository.reactComment(slug, id, type)
    suspend fun deleteComment(slug: String, id: Long) = repository.deleteComment(slug, id)
    suspend fun resendCommentConfirmation() = repository.resendConfirmation()

    suspend fun venueReviews(slug: String, page: Int) = repository.venueReviews(slug, page)
    suspend fun submitVenueReview(slug: String, rating: Int?, body: String, revision: Int) = repository.submitVenueReview(slug, rating, body, revision)
    suspend fun reportVenueReview(slug: String, id: Long, body: String) = repository.reportVenueReview(slug, id, body)
    suspend fun deleteVenueReview(slug: String) = repository.deleteVenueReview(slug)

    fun openVenue(venue: Venue) {
        val slug = venue.slug ?: return
        openVenueSlug(slug)
    }

    fun openVenueSlug(slug: String) {
        detailJob?.cancel()
        detailJob = viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(isLoading = true, message = null)
            runCatching { Triple(repository.venue(slug), repository.venueOccurrences(slug), repository.venuePastOccurrences(slug)) }
                .onSuccess { (detail, events, pastEvents) ->
                    previous?.let(detailHistory::add)
                    _state.value = _state.value.copy(
                        selected = null,
                        selectedVenue = detail,
                        venueOccurrences = events,
                        venuePastOccurrences = pastEvents,
                        isLoading = false,
                    )
                }
                .onFailure { if (it !is CancellationException) _state.value = _state.value.copy(isLoading = false, message = userMessage(it)) }
        }
    }

    fun browseTag(tag: Tag) {
        searchJob?.cancel()
        resetEventPagination()
        _state.value = _state.value.copy(discoveryFilters = emptyMap(), discoverySummary = null)
        viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(tab = AppTab.SEARCH, isSearching = true, message = null)
            runCatching { repository.eventsByTag(tag.slug) }
                .onSuccess {
                    previous?.let(detailHistory::add)
                    _state.value = _state.value.copy(
                        selected = null,
                        selectedVenue = null,
                        searchResults = it,
                        searchOrganizers = emptyList(), searchVenues = emptyList(),
                        searchTags = emptyList(),
                        activeTag = tag,
                        isSearching = false,
                    )
                }
                .onFailure { _state.value = _state.value.copy(isSearching = false, message = userMessage(it)) }
        }
    }

    fun browseCategory(slug: String) {
        searchJob?.cancel()
        resetEventPagination()
        _state.value = _state.value.copy(discoveryFilters = emptyMap(), discoverySummary = null)
        viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(tab = AppTab.SEARCH, isSearching = true, message = null)
            runCatching { repository.eventsByCategory(slug) }
                .onSuccess {
                    previous?.let(detailHistory::add)
                    _state.value = _state.value.copy(
                        selected = null,
                        selectedVenue = null,
                        searchResults = it,
                        searchOrganizers = emptyList(), searchVenues = emptyList(),
                        searchTags = emptyList(),
                        activeTag = null,
                        isSearching = false,
                    )
                }
                .onFailure { _state.value = _state.value.copy(isSearching = false, message = userMessage(it)) }
        }
    }

    fun clearTagFilter() {
        searchJob?.cancel()
        resetEventPagination()
        _state.value = _state.value.copy(
            activeTag = null,
            searchResults = emptyList(),
            searchTags = emptyList(),
            searchOrganizers = emptyList(), searchVenues = emptyList(),
            message = null,
        )
    }

    private fun resetEventPagination() {
        moreEventsJob?.cancel()
        _state.value = _state.value.copy(searchNextCursor = null, searchError = null, isLoadingMoreEvents = false)
    }

    fun goBack() {
        if (_state.value.bookingDate != null) {
            bookingJob?.cancel()
            _state.value = _state.value.copy(bookingDate = null, bookingAvailability = null, bookingBusy = false)
            return
        }
        if (_state.value.tab == AppTab.TICKETS || _state.value.tab == AppTab.ATTENDED) {
            if (_state.value.selected == null && _state.value.selectedVenue == null) {
                selectTab(AppTab.ACCOUNT)
                return
            }
        }
        val previous = detailHistory.removeLastOrNull()
        when (previous) {
            is DetailSnapshot.Event -> _state.value = _state.value.copy(
                selected = previous.detail,
                relatedOccurrences = previous.related,
                selectedVenue = null,
                mapPreviewEvents = emptyList(),
                mapPreviewTotal = 0,
            )
            is DetailSnapshot.Place -> _state.value = _state.value.copy(
                selected = null,
                selectedVenue = previous.venue,
                venueOccurrences = previous.upcoming,
                venuePastOccurrences = previous.past,
                mapPreviewEvents = emptyList(),
                mapPreviewTotal = 0,
            )
            null -> {
                val current = _state.value
                _state.value = if (current.selected != null || current.selectedVenue != null) {
                    current.copy(selected = null, selectedVenue = null, mapPreviewEvents = emptyList(), mapPreviewTotal = 0)
                } else {
                    current.copy(tab = AppTab.HOME, mapPreviewEvents = emptyList(), mapPreviewTotal = 0)
                }
            }
        }
    }

    private fun currentSnapshot(): DetailSnapshot? = _state.value.let { current ->
        current.selected?.let { DetailSnapshot.Event(it, current.relatedOccurrences) }
            ?: current.selectedVenue?.let { DetailSnapshot.Place(it, current.venueOccurrences, current.venuePastOccurrences) }
    }

    fun setPrivacyConsent(accepted: Boolean) {
        repository.setPrivacyConsent(accepted)
        _state.value = _state.value.copy(privacyConsent = accepted)
    }

    private val pendingSaves = mutableSetOf<Long>()

    fun toggleSaved(occurrenceId: Long) {
        if (!pendingSaves.add(occurrenceId)) return
        viewModelScope.launch {
            val wasSaved = occurrenceId in _state.value.savedIds
            runCatching { repository.toggleSaved(occurrenceId) }
                .onSuccess { conflicts ->
                    val overlap = if (wasSaved) null else it.fabiodalez.incitta.data.firstConflict(conflicts)
                    _state.value = _state.value.copy(
                        message = when {
                            wasSaved -> "Rimosso dai salvati"
                            overlap != null -> getApplication<Application>().getString(R.string.saved_conflict, overlap.first, overlap.second)
                            else -> "Salvato"
                        },
                    )
                    if (_state.value.tab == AppTab.SAVED) loadSaved()
                }
                .onFailure { _state.value = _state.value.copy(message = userMessage(it)) }
            pendingSaves.remove(occurrenceId)
        }
    }

    fun login(email: String, password: String) {
        authenticate(login = true) { repository.login(email, password) }
    }

    fun clearAuthError() {
        _state.value = _state.value.copy(authError = null)
    }

    fun register(firstName: String, lastName: String, email: String, password: String) {
        authenticate { repository.register(firstName, lastName, email, password) }
    }

    fun requestMagicLink(email: String) {
        if (_state.value.isAuthenticating) return
        viewModelScope.launch {
            _state.value = _state.value.copy(isAuthenticating = true, message = null, authError = null)
            runCatching { repository.requestMagicLink(email) }
                .onSuccess {
                    _state.value = _state.value.copy(
                        isAuthenticating = false,
                        message = "Se l’indirizzo è registrato, riceverai il link di accesso.",
                    )
                }
                .onFailure {
                    if (it is CancellationException) throw it
                    _state.value = _state.value.copy(isAuthenticating = false, authError = userMessage(it))
                }
        }
    }

    fun exchangeMagicToken(token: String) {
        authenticate { repository.exchangeMagicToken(token) }
    }

    fun logout() {
        viewModelScope.launch {
            repository.logout()
            _state.value = _state.value.copy(savedOccurrences = emptyList(), communityDestination = null, message = "Sessione chiusa")
        }
    }

    fun deleteAccount(password: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isAuthenticating = true, message = null)
            runCatching { repository.deleteAccount(password) }
                .onSuccess {
                    _state.value = _state.value.copy(
                        isAuthenticating = false,
                        savedOccurrences = emptyList(),
                        communityDestination = null,
                        message = "Account cancellato e dati personali rimossi.",
                    )
                }
                .onFailure { _state.value = _state.value.copy(isAuthenticating = false, message = userMessage(it)) }
        }
    }

    fun clearMessage() {
        _state.value = _state.value.copy(message = null)
    }

    private fun loadSaved() {
        viewModelScope.launch {
            val token = repository.session.value?.token
            _state.value = _state.value.copy(isLoading = true, message = null)
            runCatching { repository.savedOccurrences() }
                .onSuccess { if (repository.session.value?.token == token) _state.value = _state.value.copy(savedOccurrences = it, isLoading = false) }
                .onFailure { if (it !is CancellationException && repository.session.value?.token == token) _state.value = _state.value.copy(isLoading = false, message = userMessage(it)) }
        }
    }

    private fun loadVenues() {
        viewModelScope.launch {
            runCatching { repository.venues() }
                .onSuccess { _state.value = _state.value.copy(venues = it) }
                .onFailure { if (_state.value.venues.isEmpty()) _state.value = _state.value.copy(message = userMessage(it)) }
        }
    }

    private fun loadMap(filter: EventFilter = _state.value.mapFilter) {
        mapJob?.cancel()
        val filters = _state.value.mapSearchFilters
        mapJob = viewModelScope.launch {
            _state.value = _state.value.copy(
                mapFilter = filter,
                isMapLoading = true,
                mapPreviewEvents = emptyList(),
                mapPreviewTotal = 0,
                message = null,
            )
            runCatching { repository.mapMarkers(filter, filters) }
                .onSuccess {
                    if (_state.value.mapFilter == filter && _state.value.mapSearchFilters == filters) {
                        _state.value = _state.value.copy(mapMarkers = it, isMapLoading = false)
                    }
                }
                .onFailure {
                    if (it is CancellationException) return@onFailure
                    if (_state.value.mapFilter == filter && _state.value.mapSearchFilters == filters) {
                        _state.value = _state.value.copy(isMapLoading = false, message = userMessage(it))
                    }
                }
        }
    }

    private suspend fun detailWithRelated(slug: String): Pair<EventDetail, List<Occurrence>> {
        val detail = repository.detail(slug)
        val related = detail.category?.slug?.let { category ->
            runCatching { repository.eventsByCategory(category) }.getOrDefault(emptyList())
                .filterNot { it.eventId == detail.id }
                .take(4)
        }.orEmpty()
        return detail to related
    }

    private fun authenticate(login: Boolean = false, action: suspend () -> Any) {
        if (_state.value.isAuthenticating) return
        viewModelScope.launch {
            _state.value = _state.value.copy(isAuthenticating = true, message = null, authError = null)
            runCatching { action() }
                .onSuccess {
                    _state.value = _state.value.copy(
                        isAuthenticating = false,
                        authError = null,
                        message = "Accesso eseguito. I salvati del dispositivo sono stati sincronizzati.",
                    )
                    commentLoginSlug?.let { slug ->
                        commentLoginSlug = null
                        openSlug(slug)
                    }
                }
                .onFailure {
                    if (it is CancellationException) throw it
                    val feedback = if (login) getApplication<Application>().getString(loginFailureMessage(it)) else userMessage(it)
                    _state.value = _state.value.copy(
                        isAuthenticating = false,
                        authError = feedback,
                        // Magic-link exchange can fail while a different tab is open.
                        message = if (login) null else feedback,
                    )
                }
        }
    }

    private fun userMessage(error: Throwable): String = requestFailureMessage(error)

    private fun text(id: Int, vararg args: Any): String = getApplication<Application>().getString(id, *args)

    /* 8 e 9. Locali nascosti ed esigenze di accesso. */
    private fun loadContentProfile() {
        contentProfileJob?.cancel()
        val token = repository.session.value?.token ?: return
        contentProfileJob = viewModelScope.launch {
            try {
                val profile = repository.contentProfile()
                if (repository.session.value?.token == token) {
                    _state.value = _state.value.copy(hiddenVenueIds = profile.hiddenVenues.toSet(), accessNeeds = profile.accessNeeds)
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                // Senza preferenze si mostra tutto: nessun filtro imposto per errore.
            }
        }
    }

    fun setVenueHidden(venueId: Long, hidden: Boolean) {
        val token = repository.session.value?.token ?: return
        viewModelScope.launch {
            try {
                repository.setVenueHidden(venueId, hidden)
                if (repository.session.value?.token != token) return@launch
                val ids = if (hidden) _state.value.hiddenVenueIds + venueId else _state.value.hiddenVenueIds - venueId
                _state.value = _state.value.copy(hiddenVenueIds = ids, message = text(if (hidden) R.string.venue_hidden_done else R.string.venue_unhidden_done))
                interestsChanged()
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(message = userMessage(error))
            }
        }
    }

    /** 9. «Mostra tutto»: il profilo non riempie più i filtri finché non si rientra. */
    fun dismissProfileAccess() {
        if (!_state.value.profileAccessDismissed) _state.value = _state.value.copy(profileAccessDismissed = true)
    }

    /* 5. Aperti adesso. */
    fun setVenuesOpenNow(active: Boolean) {
        _state.value = _state.value.copy(venuesOpenNow = active, openNowError = null)
        if (active) loadOpenNowVenues() else openNowJob?.cancel()
    }

    fun loadOpenNowVenues() {
        openNowJob?.cancel()
        _state.value = _state.value.copy(openNowLoading = true, openNowError = null)
        openNowJob = viewModelScope.launch {
            try {
                val venues = repository.venues(openNow = true)
                _state.value = _state.value.copy(openNowVenues = venues, openNowLoading = false)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(openNowLoading = false, openNowError = userMessage(error))
            }
        }
    }

    /* 13. Avvisami se tornano disponibili. */
    private fun refreshAvailabilityAlerts() {
        val token = repository.session.value?.token ?: return
        viewModelScope.launch {
            try {
                val alerts = repository.availabilityAlerts()
                val openings = repository.openingAlerts()
                val editions = repository.editionAlerts()
                if (repository.session.value?.token == token) _state.value = _state.value.copy(availabilityAlerts = alerts, openingAlerts = openings, editionAlerts = editions)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
            }
        }
    }

    fun toggleAvailabilityAlert(occurrenceId: Long) {
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = text(R.string.alert_login))
            return
        }
        if (occurrenceId in _state.value.alertBusy) return
        val token = repository.session.value?.token ?: return
        val active = occurrenceId !in _state.value.availabilityAlerts
        _state.value = _state.value.copy(alertBusy = _state.value.alertBusy + occurrenceId)
        viewModelScope.launch {
            try {
                repository.setAvailabilityAlert(occurrenceId, active)
                if (repository.session.value?.token == token) {
                    val alerts = if (active) _state.value.availabilityAlerts + occurrenceId else _state.value.availabilityAlerts - occurrenceId
                    _state.value = _state.value.copy(availabilityAlerts = alerts, message = text(if (active) R.string.alert_on_done else R.string.alert_off_done))
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(message = userMessage(error))
            } finally {
                _state.value = _state.value.copy(alertBusy = _state.value.alertBusy - occurrenceId)
            }
        }
    }

    fun toggleOpeningAlert(occurrenceId: Long) {
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = text(R.string.opening_alert_login))
            return
        }
        if (occurrenceId in _state.value.alertBusy) return
        val token = repository.session.value?.token ?: return
        val active = occurrenceId !in _state.value.openingAlerts
        _state.value = _state.value.copy(alertBusy = _state.value.alertBusy + occurrenceId)
        viewModelScope.launch {
            try {
                repository.setOpeningAlert(occurrenceId, active)
                if (repository.session.value?.token == token) {
                    val alerts = if (active) _state.value.openingAlerts + occurrenceId else _state.value.openingAlerts - occurrenceId
                    _state.value = _state.value.copy(openingAlerts = alerts, message = text(if (active) R.string.opening_alert_on_done else R.string.alert_off_done))
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(message = userMessage(error))
            } finally {
                _state.value = _state.value.copy(alertBusy = _state.value.alertBusy - occurrenceId)
            }
        }
    }

    fun toggleEditionAlert(eventId: Long) {
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = text(R.string.edition_alert_login))
            return
        }
        if (_state.value.editionBusy) return
        val token = repository.session.value?.token ?: return
        val active = eventId !in _state.value.editionAlerts
        _state.value = _state.value.copy(editionBusy = true)
        viewModelScope.launch {
            try {
                repository.setEditionAlert(eventId, active)
                if (repository.session.value?.token == token) {
                    val alerts = if (active) _state.value.editionAlerts + eventId else _state.value.editionAlerts - eventId
                    _state.value = _state.value.copy(editionAlerts = alerts, message = text(if (active) R.string.edition_alert_on_done else R.string.alert_off_done))
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(message = userMessage(error))
            } finally {
                _state.value = _state.value.copy(editionBusy = false)
            }
        }
    }

    /* 14. Le mie serate. */
    suspend fun attended(page: Int) = repository.attended(page)

    /* 15. «Sono qui». */
    fun createPresence(occurrenceId: Long, displayName: String, note: String, onCreated: (it.fabiodalez.incitta.data.PresenceLink) -> Unit) {
        if (_state.value.presenceBusy) return
        if (_state.value.session == null) {
            selectTab(AppTab.ACCOUNT)
            _state.value = _state.value.copy(message = text(R.string.presence_login))
            return
        }
        val token = repository.session.value?.token ?: return
        _state.value = _state.value.copy(presenceBusy = true)
        viewModelScope.launch {
            try {
                val link = repository.createPresence(occurrenceId, displayName, note)
                if (repository.session.value?.token == token) {
                    _state.value = _state.value.copy(presenceLinks = _state.value.presenceLinks + (occurrenceId to link))
                    onCreated(link)
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(message = userMessage(error))
            } finally {
                _state.value = _state.value.copy(presenceBusy = false)
            }
        }
    }

    fun stopPresence(occurrenceId: Long) {
        val link = _state.value.presenceLinks[occurrenceId] ?: return
        if (_state.value.presenceBusy) return
        val token = repository.session.value?.token ?: return
        _state.value = _state.value.copy(presenceBusy = true)
        viewModelScope.launch {
            try {
                repository.revokePresence(link.token)
                forgetPresence(occurrenceId, token)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                // Un 404 vuol dire che il link è già scaduto o revocato: l'effetto voluto c'è già.
                if (error is ApiException && error.status == 404) forgetPresence(occurrenceId, token)
                else _state.value = _state.value.copy(message = userMessage(error))
            } finally {
                _state.value = _state.value.copy(presenceBusy = false)
            }
        }
    }

    private fun forgetPresence(occurrenceId: Long, token: String) {
        if (repository.session.value?.token != token) return
        _state.value = _state.value.copy(presenceLinks = _state.value.presenceLinks - occurrenceId, message = text(R.string.presence_stopped))
    }
}
