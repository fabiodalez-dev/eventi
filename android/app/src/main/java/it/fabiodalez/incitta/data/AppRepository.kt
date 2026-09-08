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

class AppRepository(context: Context) {
    private val store = LocalStore(context)
    private val api = ApiClient(store.installationId)
    private val deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    private val _session = MutableStateFlow(store.readSession())
    val session: StateFlow<Session?> = _session.asStateFlow()

    private val _savedIds = MutableStateFlow(store.guestSavedIds())
    val savedIds: StateFlow<Set<Long>> = _savedIds.asStateFlow()

    fun cachedOccurrences(): List<Occurrence> = store.cachedOccurrences()

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
        val suffix = when (filter) {
            EventFilter.ALL -> ""
            EventFilter.TODAY -> "&preset=today"
            EventFilter.TOMORROW -> "&preset=tomorrow"
            EventFilter.WEEKEND -> "&preset=weekend"
            EventFilter.FREE -> "&price=free"
        }
        val items = api.get<ApiEnvelope<List<Occurrence>>>(
            "events?city=padova$suffix",
            _session.value?.token,
        ).data
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

    suspend fun mapMarkers(filter: EventFilter = EventFilter.TODAY): List<MapMarker> {
        val suffix = when (filter) {
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
        api.post<ApiEnvelope<ApiMessage>, MagicLinkBody>("auth/magic-link", MagicLinkBody(email.trim()))
    }

    suspend fun exchangeMagicToken(rawToken: String): User {
        clearAuthenticatedState()
        val payload = api.post<ApiEnvelope<AuthPayload>, MagicExchangeBody>(
            "auth/magic-link/exchange",
            MagicExchangeBody(rawToken, deviceName),
        ).data
        acceptSession(payload)
        runCatching {
            mergeGuestWishlist()
            refreshServerWishlist()
        }
        return payload.user
    }

    suspend fun toggleSaved(occurrenceId: Long) {
        val token = _session.value?.token
        if (token == null) {
            val next = _savedIds.value.toMutableSet().apply {
                if (!add(occurrenceId)) remove(occurrenceId)
            }.toSet()
            _savedIds.value = next
            store.setGuestSaved(next)
            return
        }

        try {
            if (occurrenceId in _savedIds.value) {
                api.delete<ApiEnvelope<ApiMessage>>("me/saved/$occurrenceId", token)
                _savedIds.value = _savedIds.value - occurrenceId
            } else {
                api.post<ApiEnvelope<ApiMessage>, SaveBody>(
                    "me/saved",
                    SaveBody(occurrenceId),
                    token,
                    idempotent = true,
                )
                _savedIds.value = _savedIds.value + occurrenceId
            }
        } catch (error: ApiException) {
            if (error.status == 401) clearAuthenticatedState()
            throw error
        }
    }

    suspend fun savedOccurrences(): List<Occurrence> {
        val token = _session.value?.token
        if (token == null) {
            val cached = cachedOccurrences().associateBy(Occurrence::occurrenceId)
            return coroutineScope {
                _savedIds.value.map { id ->
                    async { cached[id] ?: runCatching { occurrence(id) }.getOrNull() }
                }.awaitAll().filterNotNull().sortedBy(Occurrence::startsAt)
            }
        }
        return try {
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
            if (error.status == 401) clearAuthenticatedState()
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
