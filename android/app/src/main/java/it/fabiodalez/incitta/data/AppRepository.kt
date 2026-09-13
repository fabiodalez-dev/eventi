package it.fabiodalez.incitta.data

import android.content.Context
import android.os.Build
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

class AppRepository(context: Context) {
    private val store = LocalStore(context)
    private val api = ApiClient(store.installationId)

    suspend fun sponsoredBanner(excludeEvent: String?, venue: String? = null, tag: String? = null, filters: Map<String, String> = emptyMap()): SponsoredBanner? =
        api.get<ApiEnvelope<SponsoredBanner?>>("sponsorships/banner?platform=android" +
            filters.filterKeys { it in setOf("category", "date", "from", "to") }.entries.joinToString("") { "&${it.key}=${URLEncoder.encode(it.value, "UTF-8")}" } +
            (excludeEvent?.let { "&exclude_event=" + URLEncoder.encode(it, "UTF-8") } ?: "") +
            (venue?.let { "&venue=" + URLEncoder.encode(it, "UTF-8") } ?: "") +
            (tag?.let { "&tag=" + URLEncoder.encode(it, "UTF-8") } ?: ""), _session.value?.token).data

    suspend fun sponsorshipMetric(banner: SponsoredBanner, click: Boolean, page: String) = api.sponsorshipMetric(banner, click, page)
    private val deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    private val _session = MutableStateFlow(store.readSession())
    val session: StateFlow<Session?> = _session.asStateFlow()

    private val savedMutex = Mutex()
    private val _savedIds = MutableStateFlow(if (_session.value == null) store.guestSavedIds() else emptySet())
    val savedIds: StateFlow<Set<Long>> = _savedIds.asStateFlow()

    fun cachedOccurrences(): List<Occurrence> = store.cachedOccurrences()
    fun clearDiscoveryCache() = store.cacheOccurrences(emptyList())

    suspend fun bookingAvailability(id: Long): BookingAvailability =
        api.get<ApiEnvelope<BookingAvailability>>("occurrences/$id/booking").data

    suspend fun bookings(): List<Booking> {
        val token = requireNotNull(_session.value?.token)
        val result = mutableListOf<Booking>()
        var page: Int? = 1
        while (page != null) {
            val response = api.get<ApiEnvelope<List<Booking>>>("me/bookings?page=$page", token)
            result.addAll(response.data)
            page = response.meta?.nextPage
        }
        return result
    }

    suspend fun reserve(id: Long, body: ReserveBody): Booking =
        api.post<ApiEnvelope<Booking>, ReserveBody>("occurrences/$id/bookings", body, requireNotNull(_session.value?.token)).data

    suspend fun cancelBooking(id: Long, ticketId: Long?): Booking =
        api.post<ApiEnvelope<Booking>, CancelBookingBody>("me/bookings/$id/cancel", CancelBookingBody(ticketId), requireNotNull(_session.value?.token)).data

    suspend fun resendBooking(id: Long) {
        api.post<ApiEnvelope<kotlinx.serialization.json.JsonObject>, CancelBookingBody>("me/bookings/$id/email", CancelBookingBody(), requireNotNull(_session.value?.token))
    }

    suspend fun occurrences(filter: EventFilter = EventFilter.ALL): List<Occurrence> {
        val token = _session.value?.token
        val suffix = when (filter) {
            EventFilter.ALL -> ""
            EventFilter.TODAY -> "&preset=today"
            EventFilter.TOMORROW -> "&preset=tomorrow"
            EventFilter.WEEKEND -> "&preset=weekend"
            EventFilter.FREE -> "&price=free"
        }
        val items = api.get<ApiEnvelope<List<Occurrence>>>(
            "events?city=padova$suffix",
            token,
        ).data
        if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
        if (filter == EventFilter.ALL) store.cacheOccurrences(items)
        return items
    }

    suspend fun eventsByTag(slug: String): List<Occurrence> =
        api.get<ApiEnvelope<List<Occurrence>>>(
            "events?city=padova&tags=${slug.urlEncoded()}",
            _session.value?.token,
        ).data

    suspend fun eventsByCategory(slug: String): List<Occurrence> =
        api.get<ApiEnvelope<List<Occurrence>>>(
            "events?city=padova&categories=${slug.urlEncoded()}",
            _session.value?.token,
        ).data

    suspend fun search(query: String): SearchResults =
        api.get<ApiEnvelope<SearchResults>>(
            "search?city=padova&q=${query.urlEncoded()}",
            _session.value?.token,
        ).data

    suspend fun venueReviews(slug: String, page: Int = 1): VenueReviewPage =
        api.get<ApiEnvelope<VenueReviewPage>>("venues/${slug.urlEncoded()}/reviews?page=$page", _session.value?.token).data

    suspend fun submitVenueReview(slug: String, rating: Int, body: String) {
        api.post<ApiEnvelope<ApiMessage>, VenueReviewBody>("venues/${slug.urlEncoded()}/reviews", VenueReviewBody(rating, body.trim()), requireNotNull(_session.value?.token))
    }

    suspend fun deleteVenueReview(slug: String) {
        api.delete<ApiEnvelope<ApiMessage>>("venues/${slug.urlEncoded()}/reviews", requireNotNull(_session.value?.token))
    }

    suspend fun venues(): List<Venue> =
        api.get<ApiEnvelope<List<Venue>>>("venues?city=padova", _session.value?.token).data

    suspend fun venue(slug: String): Venue =
        api.get<ApiEnvelope<Venue>>("venues/${slug.urlEncoded()}", _session.value?.token).data

    suspend fun venueOccurrences(slug: String): List<Occurrence> =
        api.get<ApiEnvelope<List<Occurrence>>>(
            "venues/${slug.urlEncoded()}/events?city=padova",
            _session.value?.token,
        ).data

    suspend fun venuePastOccurrences(slug: String): List<Occurrence> =
        api.get<ApiEnvelope<List<Occurrence>>>(
            "venues/${slug.urlEncoded()}/past?city=padova",
            _session.value?.token,
        ).data

    suspend fun mapMarkers(filter: EventFilter = EventFilter.TODAY, filters: Map<String, String>? = null): List<MapMarker> {
        val suffix = if (filters != null) "&" + filterQuery(filters, filters["q"].orEmpty()) else when (filter) {
            EventFilter.ALL -> ""
            EventFilter.TODAY -> "&preset=today"
            EventFilter.TOMORROW -> "&preset=tomorrow"
            EventFilter.WEEKEND -> "&preset=weekend"
            EventFilter.FREE -> "&price=free"
        }
        return api.get<ApiEnvelope<List<MapMarker>>>(
            "map/occurrences?city=padova$suffix",
            _session.value?.token,
        ).data
    }

    suspend fun detail(slug: String): EventDetail =
        api.get<ApiEnvelope<EventDetail>>("events/${slug.urlEncoded()}", _session.value?.token).data

    suspend fun occurrenceByNumber(slug: String, number: Int): Occurrence =
        api.get<ApiEnvelope<Occurrence>>("events/${slug.urlEncoded()}/dates/$number", _session.value?.token).data

    suspend fun occurrence(id: Long): Occurrence =
        api.get<ApiEnvelope<Occurrence>>("occurrences/$id", _session.value?.token).data

    fun privacyConsent(): Boolean? = store.privacyConsent()

    fun setPrivacyConsent(accepted: Boolean) = store.setPrivacyConsent(accepted)

    suspend fun login(email: String, password: String): User {
        // Prevent stale data from one account surviving an account switch.
        clearAuthenticatedState()
        val payload = api.post<ApiEnvelope<AuthPayload>, LoginBody>(
            "auth/login",
            LoginBody(email.trim(), password, deviceName),
        ).data
        acceptSession(payload)
        runCatching {
            mergeGuestWishlist()
            refreshServerWishlist()
        }
        return payload.user
    }

    suspend fun register(firstName: String, lastName: String, email: String, password: String): User {
        clearAuthenticatedState()
        val payload = api.post<ApiEnvelope<AuthPayload>, RegisterBody>(
            "auth/register",
            RegisterBody(listOf(firstName.trim(), lastName.trim()).filter(String::isNotBlank).joinToString(" ").ifBlank { null }, email.trim(), password, password, deviceName,
                firstName.trim().ifBlank { null }, lastName.trim().ifBlank { null }),
        ).data
        acceptSession(payload)
        runCatching {
            mergeGuestWishlist()
            refreshServerWishlist()
        }
        return payload.user
    }

    suspend fun requestMagicLink(email: String) {
        val verifier = MagicLinkProof.createVerifier()
        store.writeMagicVerifier(verifier)
        api.post<ApiEnvelope<ApiMessage>, MagicLinkBody>("auth/magic-link", MagicLinkBody(email.trim(), MagicLinkProof.challenge(verifier)))
    }

    suspend fun exchangeMagicToken(rawToken: String): User {
        val verifier = store.readMagicVerifier() ?: error("Richiedi un nuovo link da questo dispositivo.")
        val payload = api.post<ApiEnvelope<AuthPayload>, MagicExchangeBody>(
            "auth/magic-link/exchange",
            MagicExchangeBody(rawToken, verifier, deviceName),
        ).data
        store.clearMagicVerifier()
        clearAuthenticatedState()
        acceptSession(payload)
        runCatching {
            mergeGuestWishlist()
            refreshServerWishlist()
        }
        return payload.user
    }

    suspend fun filteredOccurrences(filters: Map<String, String>, query: String): List<Occurrence> {
        val token = _session.value?.token
        val parameters = filterQuery(filters + ("limit" to "50"), query)
        val result = mutableListOf<Occurrence>()
        val seen = mutableSetOf<String>()
        var cursor: String? = null
        do {
            val page = api.get<ApiEnvelope<List<Occurrence>>>("events?$parameters" + (cursor?.let { "&cursor=${it.urlEncoded()}" } ?: ""), token)
            if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
            result += page.data
            cursor = page.meta?.nextCursor?.takeIf { seen.add(it) }
        } while (cursor != null)
        return result.distinctBy(Occurrence::occurrenceId)
    }

    suspend fun toggleSaved(occurrenceId: Long) = savedMutex.withLock {
        val token = _session.value?.token
        if (token == null) {
            val next = _savedIds.value.toMutableSet().apply {
                if (!add(occurrenceId)) remove(occurrenceId)
            }.toSet()
            _savedIds.value = next
            store.setGuestSaved(next)
            return@withLock
        }

        try {
            if (occurrenceId in _savedIds.value) {
                api.delete<ApiEnvelope<ApiMessage>>("me/saved/$occurrenceId", token)
                if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
                _savedIds.value = _savedIds.value - occurrenceId
            } else {
                api.post<ApiEnvelope<ApiMessage>, SaveBody>(
                    "me/saved",
                    SaveBody(occurrenceId),
                    token,
                    idempotent = true,
                )
                if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
                _savedIds.value = _savedIds.value + occurrenceId
            }
        } catch (error: ApiException) {
            if (error.status == 401 && _session.value?.token == token) clearAuthenticatedState()
            throw error
        }
    }

    suspend fun savedOccurrences(): List<Occurrence> = savedMutex.withLock {
        val token = _session.value?.token
        if (token == null) {
            val cached = cachedOccurrences().associateBy(Occurrence::occurrenceId)
            return@withLock coroutineScope {
                _savedIds.value.map { id ->
                    async { cached[id] ?: runCatching { occurrence(id) }.getOrNull() }
                }.awaitAll().filterNotNull().sortedBy(Occurrence::startsAt)
            }
        }
        try {
            mergeGuestWishlist()
            val result = mutableListOf<Occurrence>()
            var cursor: String? = null
            val seen = mutableSetOf<String>()
            do {
                if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
                val page = api.get<ApiEnvelope<List<Occurrence>>>("me/saved?city=padova&upcoming=0&limit=50" + (cursor?.let { "&cursor=${it.urlEncoded()}" } ?: ""), token)
                result += page.data
                cursor = page.meta?.nextCursor?.takeIf { seen.add(it) }
            } while (cursor != null)
            result.distinctBy(Occurrence::occurrenceId).sortedBy(Occurrence::startsAt).also { items ->
                if (_session.value?.token != token) throw kotlinx.coroutines.CancellationException("Session changed")
                _savedIds.value = items.map(Occurrence::occurrenceId).toSet()
            }
        } catch (error: ApiException) {
            if (error.status == 401 && _session.value?.token == token) clearAuthenticatedState()
            throw error
        }
    }

    fun appearance(): String = _session.value?.user?.appearance ?: store.guestAppearance()
    private var appearanceRevision = 0
    private var appearanceUpdating = false

    suspend fun setAppearance(value: String) {
        require(value in listOf("dark", "light"))
        appearanceRevision++
        val current = _session.value
        if (current == null) { store.setGuestAppearance(value); return }
        appearanceUpdating = true
        try {
            val user = api.execute<ApiEnvelope<User>>("me", "PATCH", "{\"appearance\":\"$value\"}", current.token).data
            if (_session.value?.token != current.token) throw kotlinx.coroutines.CancellationException("Session changed")
            val updated = current.copy(user = user)
            store.writeSession(updated)
            _session.value = updated
        } finally { appearanceUpdating = false }
    }

    suspend fun refreshProfile() {
        if (appearanceUpdating) return
        val revision = appearanceRevision
        val current = _session.value ?: return
        try {
            val user = api.get<ApiEnvelope<User>>("me", current.token).data
            if (_session.value?.token != current.token || user.id != current.user.id || revision != appearanceRevision) return
            val updated = current.copy(user = user)
            store.writeSession(updated)
            _session.value = updated
        } catch (error: ApiException) {
            if (error.status == 401 && _session.value?.token == current.token) clearAuthenticatedState()
            throw error
        }
    }

    suspend fun logout() {
        _session.value?.token?.let { token ->
            runCatching { api.post<ApiEnvelope<ApiMessage>, Map<String, String>>("auth/logout", emptyMap(), token) }
        }
        clearAuthenticatedState()
    }

    suspend fun deleteAccount(password: String) {
        val token = _session.value?.token ?: return
        api.delete<ApiEnvelope<ApiMessage>, DeleteAccountBody>(
            "me",
            DeleteAccountBody("CANCELLA", password),
            token,
        )
        clearAuthenticatedState()
    }

    private fun acceptSession(payload: AuthPayload) {
        val session = Session(payload.token, payload.user, payload.expiresAt)
        store.writeSession(session)
        _session.value = session
        // Keep guest ids in storage until the merge transaction has succeeded.
        _savedIds.value = emptySet()
    }

    private suspend fun mergeGuestWishlist() {
        val guestIds = store.guestSavedIds()
        val token = _session.value?.token ?: return
        if (guestIds.isEmpty()) return

        api.post<ApiEnvelope<MergeResult>, MergeBody>(
            "me/saved/merge",
            MergeBody(guestIds.toList()),
            token,
            idempotent = true,
        )
        store.setGuestSaved(emptySet())
    }

    private suspend fun refreshServerWishlist() {
        savedOccurrences()
    }

    private fun clearAuthenticatedState() {
        store.clearSession()
        _session.value = null
        _savedIds.value = store.guestSavedIds()
    }

    private fun String.urlEncoded(): String = URLEncoder.encode(this, StandardCharsets.UTF_8.toString())
}

enum class EventFilter { ALL, TODAY, TOMORROW, WEEKEND, FREE }
