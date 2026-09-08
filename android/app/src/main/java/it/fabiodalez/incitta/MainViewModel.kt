package it.fabiodalez.incitta

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.loginFailureMessage
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

enum class AppTab { EVENTS, MAP, SEARCH, SAVED, ACCOUNT, CALENDAR, VENUES, TICKETS }

data class AppUiState(
    val bookings: List<it.fabiodalez.incitta.data.Booking> = emptyList(),
    val bookingDate: Occurrence? = null,
    val bookingAvailability: it.fabiodalez.incitta.data.BookingAvailability? = null,
    val bookingBusy: Boolean = false,
    val bookingError: String? = null,
    val bookingRequestKey: String = "",
    val tab: AppTab = AppTab.EVENTS,
    val sponsoredBanner: it.fabiodalez.incitta.data.SponsoredBanner? = null,
    val occurrences: List<Occurrence> = emptyList(),
    val eventFilter: EventFilter = EventFilter.ALL,
    val searchResults: List<Occurrence> = emptyList(),
    val searchVenues: List<Venue> = emptyList(),
    val searchTags: List<Tag> = emptyList(),
    val activeTag: Tag? = null,
    val venues: List<Venue> = emptyList(),
    val mapFilter: EventFilter = EventFilter.TODAY,
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

internal fun AppUiState.supportsSponsoredBanner(): Boolean = bookingDate == null &&
    (selected != null || selectedVenue != null || tab in listOf(AppTab.EVENTS, AppTab.SEARCH, AppTab.VENUES) ||
        (tab == AppTab.ACCOUNT && session != null))

class MainViewModel(application: Application) : AndroidViewModel(application) {
    private sealed interface DetailSnapshot {
        data class Event(val detail: EventDetail, val related: List<Occurrence>) : DetailSnapshot
        data class Place(val venue: Venue, val upcoming: List<Occurrence>, val past: List<Occurrence>) : DetailSnapshot
    }

    private val repository = AppRepository(application)
    private val _state = MutableStateFlow(
        AppUiState(
            occurrences = repository.cachedOccurrences(),
            privacyConsent = repository.privacyConsent(),
        ),
    )
    val state: StateFlow<AppUiState> = _state.asStateFlow()
    private val bannerImpressions = mutableSetOf<Long>()

    suspend fun refreshSponsoredBanner(excludeEvent: String?) {
        try {
            val banner = repository.sponsoredBanner(excludeEvent, _state.value.selectedVenue?.slug, _state.value.activeTag?.slug)?.takeIf { it.validAt() }
            _state.value = _state.value.copy(sponsoredBanner = banner)
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
            current.tab == AppTab.EVENTS -> "home"
            else -> "other"
        }
        viewModelScope.launch {
            try { repository.sponsorshipMetric(banner, click, page) }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (_: Exception) { /* Measurement failure must never interrupt navigation. */ }
        }
    }
    private var searchJob: Job? = null
    private var refreshJob: Job? = null
    private var detailJob: Job? = null
    private var bookingJob: Job? = null
    private val detailHistory = mutableListOf<DetailSnapshot>()

    init {
        viewModelScope.launch {
            combine(repository.session, repository.savedIds) { session, saved -> session to saved }
                .collect { (session, saved) ->
                    if (_state.value.session?.token != session?.token) {
                        bookingJob?.cancel()
                        _state.value = _state.value.copy(bookings = emptyList(), bookingDate = null, bookingAvailability = null, bookingBusy = false, bookingError = null)
                    }
                    _state.value = _state.value.copy(session = session, savedIds = saved)
                }
        }
        refresh(EventFilter.ALL)
        loadVenues()
        loadMap()
    }

    fun selectTab(tab: AppTab) {
        _state.value = _state.value.copy(bookingDate = null, bookingAvailability = null)
        detailHistory.clear()
        _state.value = _state.value.copy(tab = tab, selected = null, selectedVenue = null, mapPreviewEvents = emptyList(), mapPreviewTotal = 0, message = null)
        if (tab == AppTab.SAVED) loadSaved()
        if (tab == AppTab.MAP) loadMap()
        if (tab == AppTab.SEARCH || tab == AppTab.VENUES) loadVenues()
        if (tab == AppTab.CALENDAR) refresh(EventFilter.ALL)
        if (tab == AppTab.TICKETS) loadBookings()
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

    fun loadBookings() {
        if (_state.value.session == null) return
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
                        isOffline = _state.value.occurrences.isNotEmpty(),
                        message = userMessage(error),
                    )
                }
        }
    }

    fun search(query: String) {
        searchJob?.cancel()
        _state.value = _state.value.copy(activeTag = null)
        if (query.trim().length < 3) {
            _state.value = _state.value.copy(searchResults = emptyList(), searchVenues = emptyList(), searchTags = emptyList(), isSearching = false)
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

    fun open(occurrence: Occurrence) {
        detailJob?.cancel()
        detailJob = viewModelScope.launch {
            val previous = currentSnapshot()
            _state.value = _state.value.copy(isLoading = true, message = null)
            runCatching { detailWithRelated(occurrence.eventSlug) }
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

    fun applyMapFilter(filter: EventFilter) = loadMap(filter)

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
                        searchVenues = emptyList(),
                        searchTags = emptyList(),
                        activeTag = tag,
                        isSearching = false,
                    )
                }
                .onFailure { _state.value = _state.value.copy(isSearching = false, message = userMessage(it)) }
        }
    }

    fun browseCategory(slug: String) {
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
                        searchVenues = emptyList(),
                        searchTags = emptyList(),
                        activeTag = null,
                        isSearching = false,
                    )
                }
                .onFailure { _state.value = _state.value.copy(isSearching = false, message = userMessage(it)) }
        }
    }

    fun clearTagFilter() {
        _state.value = _state.value.copy(
            activeTag = null,
            searchResults = emptyList(),
            searchTags = emptyList(),
            searchVenues = emptyList(),
            message = null,
        )
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
                    current.copy(tab = AppTab.EVENTS, mapPreviewEvents = emptyList(), mapPreviewTotal = 0)
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

    fun toggleSaved(occurrenceId: Long) {
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
            _state.value = _state.value.copy(savedOccurrences = emptyList(), message = "Sessione chiusa")
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
        viewModelScope.launch {
            _state.value = _state.value.copy(
                mapFilter = filter,
                isMapLoading = true,
                mapPreviewEvents = emptyList(),
                mapPreviewTotal = 0,
                message = null,
            )
            runCatching { repository.mapMarkers(filter) }
                .onSuccess {
                    if (_state.value.mapFilter == filter) {
                        _state.value = _state.value.copy(mapMarkers = it, isMapLoading = false)
                    }
                }
                .onFailure {
                    if (_state.value.mapFilter == filter) {
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

    private fun userMessage(error: Throwable): String = when (error) {
        is ApiException -> error.message ?: "Richiesta non riuscita"
        is IOException -> error.message ?: "Connessione non disponibile"
        else -> "Qualcosa non ha funzionato. Riprova."
    }
}
