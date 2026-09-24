package it.fabiodalez.incitta

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.loginFailureMessage
import it.fabiodalez.incitta.data.requestFailureMessage
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

enum class AppTab { HOME, EVENTS, MAP, SEARCH, SAVED, ACCOUNT, CALENDAR, VENUES, TICKETS }

internal fun navigationTarget(tab: AppTab, authenticated: Boolean): AppTab =
    if (tab == AppTab.SAVED && !authenticated) AppTab.ACCOUNT else tab

data class AppUiState(
    val communityDestination: String? = null,
    val appearance: String = "dark",
    val defaultAppearance: String = "dark",
    val appearanceSaving: Boolean = false,
    val bookings: List<it.fabiodalez.incitta.data.Booking> = emptyList(),
    val bookingDate: Occurrence? = null,
    val bookingAvailability: it.fabiodalez.incitta.data.BookingAvailability? = null,
    val bookingBusy: Boolean = false,
    val bookingError: String? = null,
    val bookingRequestKey: String = "",
    /*
     * Il biglietto nel portafoglio: `walletEnabled` arriva dal server e vale
     * come permesso di disegnare il pulsante — finché l'emittente Google non
     * è configurato resta falso e il pulsante non esiste. `walletLink` è il
     * link firmato da aprire una volta sola, poi la schermata lo consuma.
     */
    val walletEnabled: Boolean = false,
    val walletLink: String? = null,
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
    private val detailHistory = mutableListOf<DetailSnapshot>()

    init {
        viewModelScope.launch {
            combine(repository.session, repository.savedIds) { session, saved -> session to saved }
                .collect { (session, saved) ->
                    val sessionChanged = _state.value.session?.token != session?.token
                    if (sessionChanged) {
                        bookingJob?.cancel()
                        _state.value = _state.value.copy(bookings = emptyList(), bookingDate = null, bookingAvailability = null, bookingBusy = false, bookingError = null)
                    }
                    _state.value = _state.value.copy(session = session, savedIds = saved, defaultAppearance = repository.appearance(), appearance = repository.appearance())
                    if (sessionChanged) interestsChanged()
                }
        }
        refresh(EventFilter.ALL)
        loadVenues()
        loadMap()
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
        _state.value = _state.value.copy(tab = tab, selected = null, selectedVenue = null, mapPreviewEvents = emptyList(), mapPreviewTotal = 0, message = null)
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
        _state.value = _state.value.copy(bookingDate = date, bookingAvailability = null, bookingBusy = true, bookingError = null, bookingRequestKey = java.util.UUID.randomUUID().toString())
        bookingJob = viewModelScope.launch {
            try {
                val availability = repository.bookingAvailability(date.occurrenceId)
                _state.value = _state.value.copy(bookingAvailability = availability, bookingBusy = false)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error))
            }
        }
    }

    fun reserve(names: List<it.fabiodalez.incitta.data.AttendeeName>, waitlist: Boolean, booker: Map<String, String>) {
        val date = _state.value.bookingDate ?: return
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        val key = _state.value.bookingRequestKey
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null)
        bookingJob = viewModelScope.launch {
            try {
                val booking = repository.reserve(date.occurrenceId, it.fabiodalez.incitta.data.ReserveBody(names.map { it.copy(firstName = it.firstName.trim(), lastName = it.lastName.trim()) }, key, waitlist, true, booker.mapValues { it.value.trim() }))
                if (repository.session.value?.token == token) {
                    _state.value = _state.value.copy(bookingBusy = false, bookingDate = null, selected = null, selectedVenue = null, tab = AppTab.TICKETS, bookings = listOf(booking), message = getApplication<Application>().getString(if (booking.status == "waitlisted") R.string.ticket_waitlisted_success else R.string.ticket_reserved_success))
                    loadBookings()
                }
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error))
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
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null)
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
                _state.value = _state.value.copy(bookingBusy = false, bookingError = message)
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
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null)
        bookingJob = viewModelScope.launch {
            try {
                val bookings = repository.bookings()
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookings = bookings, bookingBusy = false)
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error))
            }
        }
    }

    fun cancelBooking(id: Long, ticketId: Long?) {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null)
        bookingJob = viewModelScope.launch {
            try {
                val booking = repository.cancelBooking(id, ticketId)
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookings = _state.value.bookings.map { if (it.id == id) booking else it }, bookingBusy = false, message = getApplication<Application>().getString(R.string.ticket_cancelled_success))
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error))
            }
        }
    }

    fun resendBooking(id: Long) {
        if (_state.value.bookingBusy) return
        val token = _state.value.session?.token ?: return
        _state.value = _state.value.copy(bookingBusy = true, bookingError = null)
        bookingJob = viewModelScope.launch {
            try {
                repository.resendBooking(id)
                if (repository.session.value?.token == token) _state.value = _state.value.copy(bookingBusy = false, message = getApplication<Application>().getString(R.string.ticket_email_queued))
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                _state.value = _state.value.copy(bookingBusy = false, bookingError = userMessage(error))
            }
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
        if (_state.value.tab == AppTab.TICKETS) {
            selectTab(AppTab.ACCOUNT)
            return
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
                .onSuccess {
                    _state.value = _state.value.copy(
                        message = if (wasSaved) "Rimosso dai salvati" else "Salvato",
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
}
