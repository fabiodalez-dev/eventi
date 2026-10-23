package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.ui.ContentOptions
import it.fabiodalez.incitta.ui.ContentSelection
import java.time.Instant
import java.time.ZoneId
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/** Le risposte scritte a mano secondo docs/PIANO-UTENTE-2026-10-23.md. */
class UserFeaturesContractTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    // 3
    @Test fun salvataggioConSovrapposizioniLeggeLaPrima() {
        val result = json.decodeFromString<ApiEnvelope<SaveResult>>("""
            {"data":{"message":"Salvato.","conflicts":[
              {"occurrence_id":44,"title":"Jazz al Pedrocchi","starts_at":"2026-10-24T21:00:00+02:00","ends_at":"2026-10-24T23:00:00+02:00","kind":"booked"},
              {"occurrence_id":45,"title":"Altro","starts_at":"2026-10-24T22:00:00+02:00","ends_at":null,"kind":"saved"}]}}
        """.trimIndent()).data
        assertEquals(2, result.conflicts.size)
        assertEquals("booked", result.conflicts.first().kind)
        assertEquals("Jazz al Pedrocchi" to "21:00", firstConflict(result.conflicts, ZoneId.of("Europe/Rome")))
    }

    @Test fun salvataggioSenzaSovrapposizioniOServerVecchio() {
        assertNull(firstConflict(json.decodeFromString<ApiEnvelope<SaveResult>>("""{"data":{"message":"Salvato.","conflicts":[]}}""").data.conflicts))
        assertTrue(json.decodeFromString<ApiEnvelope<SaveResult>>("""{"data":{"message":"Salvato."}}""").data.conflicts.isEmpty())
    }

    // 4
    @Test fun ilRiepilogoDelLunediApreISalvati() {
        assertEquals(PersonalDestination.SAVED, personalDestination(listOf("i-miei-salvataggi")))
        assertEquals(PersonalDestination.ATTENDED, personalDestination(listOf("le-mie-serate")))
        assertNull(personalDestination(listOf("eventi", "x")))
        val inbox = json.decodeFromString<ApiEnvelope<List<InboxNotification>>>("""
            {"data":[{"id":"a1","type":"saved_weekly","data":{"type":"saved_weekly","title":"La tua settimana","body":"3 date","url":"https://eventi.fabiodalez.it/i-miei-salvataggi"},"read":false}]}
        """.trimIndent()).data
        assertEquals("https://eventi.fabiodalez.it/i-miei-salvataggi", inbox.single().data.url)
    }

    // 5
    @Test fun apertoAdessoHaTreStatiPiuUnoSenzaOrari() {
        val venues = json.decodeFromString<ApiEnvelope<List<Venue>>>("""
            {"data":[
              {"name":"A","open_now":true,"open_until":"19:30"},
              {"name":"B","open_now":true,"open_until":null},
              {"name":"C","open_now":false,"open_until":null},
              {"name":"D","open_now":null},
              {"name":"E"}]}
        """.trimIndent()).data
        assertEquals(listOf(OpenState.OPEN_UNTIL, OpenState.OPEN, OpenState.CLOSED, OpenState.UNKNOWN, OpenState.UNKNOWN), venues.map { it.openState() })
        assertEquals("19:30", venues.first().openUntil)
    }

    // 8 e 9
    @Test fun preferenzeConLocaliNascostiEdEsigenze() {
        val payload = """
            {"data":{"selection":{"mode":"all","categories":[],"hidden_categories":[],"inferred_ads":true,
              "hidden_venues":[12],"access_needs":["step_free_entrance","guide_dog_allowed"]},
              "options":[{"id":7,"name":"Musica"}],
              "hidden_venue_options":[{"id":12,"name":"Bar Nascosto","slug":"bar-nascosto"}]}}
        """.trimIndent()
        val options = json.decodeFromString<ApiEnvelope<ContentOptions>>(payload).data
        assertEquals(listOf(12L), options.selection.hiddenVenues)
        assertEquals("Bar Nascosto", options.hiddenVenueOptions.single().name)
        val profile = json.decodeFromString<ApiEnvelope<ContentProfile>>(payload).data.selection
        assertEquals(setOf(12L), profile.hiddenVenues.toSet())
        assertEquals(listOf("step_free_entrance", "guide_dog_allowed"), profile.accessNeeds)
    }

    @Test fun conUnServerVecchioIlPatchNonAzzeraICampiFacoltativi() {
        val old = json.decodeFromString<ContentOptions>("""{"selection":{"mode":"all","categories":[],"hidden_categories":[]},"options":[]}""")
        assertNull(old.selection.hiddenVenues)
        val patch = Json(json) { encodeDefaults = true }.encodeToString(old.selection)
        assertFalse(patch.contains("hidden_venues"))
        assertFalse(patch.contains("access_needs"))
        val full = Json(json) { encodeDefaults = true }.encodeToString(ContentSelection(hiddenVenues = listOf(3), accessNeeds = listOf("tactile_path")))
        assertTrue(full.contains("\"hidden_venues\":[3]"))
        assertTrue(full.contains("\"access_needs\":[\"tactile_path\"]"))
    }

    @Test fun leEsigenzeDelProfiloPrecompilanoSoloSenzaAccessGiaScelto() {
        val needs = listOf("step_free_entrance", "sconosciuta", "accessible_toilets")
        assertEquals(mapOf("preset" to "today", "access" to "step_free_entrance,accessible_toilets"), profileAccessFilters(mapOf("preset" to "today"), needs, dismissed = false))
        assertNull(profileAccessFilters(mapOf("access" to "tactile_path"), needs, dismissed = false))
        assertNull(profileAccessFilters(emptyMap(), needs, dismissed = true))
        assertNull(profileAccessFilters(emptyMap(), emptyList(), dismissed = false))
        assertTrue(showsProfileAccess(mapOf("access" to "accessible_toilets,step_free_entrance"), needs))
        assertFalse(showsProfileAccess(mapOf("access" to "tactile_path"), needs))
        assertFalse(showsProfileAccess(emptyMap(), needs))
    }

    // 11
    @Test fun laCopiaDeiBigliettiSiRileggeUgualeConLOra() {
        val booking = json.decodeFromString<ApiEnvelope<List<Booking>>>(BOOKINGS).data
        val stored = StoredBookings(5, "2026-10-23T18:00:00Z", booking)
        val back = json.decodeFromString<StoredBookings>(json.encodeToString(stored))
        assertEquals(stored, back)
        assertEquals("QR-1", back.bookings.single().tickets.first().qrPayload)
    }

    // 12
    @Test fun ilLinkPersonaleEsisteSoloPerIBigliettiValidi() {
        val tickets = json.decodeFromString<ApiEnvelope<List<Booking>>>(BOOKINGS).data.single().tickets
        assertTrue(tickets[0].shareable())
        assertEquals("https://eventi.fabiodalez.it/biglietto/1?expires=1&signature=abc", tickets[0].shareUrl)
        assertFalse(tickets[1].shareable())
        val body = json.encodeToString(TicketSendBody("ospite@example.org", consent = true))
        assertEquals("""{"email":"ospite@example.org","consent":true}""", body)
        assertEquals("Inviato.", json.decodeFromString<ApiEnvelope<ApiMessage>>("""{"data":{"message":"Inviato."}}""").data.message)
        assertTrue(plausibleEmail(" mario.rossi@example.org "))
        assertFalse(plausibleEmail("mario"))
        assertFalse(plausibleEmail("mario@"))
        assertFalse(plausibleEmail("ma rio@example.org"))
    }

    // 13
    @Test fun lAvvisoSiOffreSoloSulleDateEsauriteFuture() {
        val now = Instant.parse("2026-10-23T12:00:00Z")
        fun date(status: String = "scheduled", tiers: List<TicketTier> = emptyList(), start: String = "2026-10-30T21:00:00+01:00") =
            Occurrence(occurrenceId = 1, eventId = 1, eventSlug = "x", startsAt = start, title = "X", status = status, tiers = tiers)
        assertTrue(date("sold_out").offersAvailabilityAlert(emptyList(), now))
        assertTrue(date(tiers = listOf(TicketTier("Intero", status = "sold_out"))).offersAvailabilityAlert(emptyList(), now))
        assertTrue(date().offersAvailabilityAlert(listOf(TicketTier("Intero", status = "sold_out")), now))
        assertFalse(date(tiers = listOf(TicketTier("Intero", status = "sold_out"), TicketTier("Ridotto"))).offersAvailabilityAlert(emptyList(), now))
        assertFalse(date().offersAvailabilityAlert(emptyList(), now))
        assertFalse(date("sold_out", start = "2026-10-01T21:00:00+02:00").offersAvailabilityAlert(emptyList(), now))
        assertEquals(setOf(10L, 11L), json.decodeFromString<ApiEnvelope<List<Long>>>("""{"data":[10,11]}""").data.toSet())
    }

    // 14
    @Test fun leMieSeratePaginate() {
        val page = json.decodeFromString<ApiEnvelope<List<AttendedEvening>>>("""
            {"data":[{"occurrence_id":9,"title":"Concerto","event_slug":"concerto","starts_at":"2026-10-10T21:00:00+02:00",
              "url":"https://eventi.fabiodalez.it/eventi/concerto/1","venue":{"name":"Hall","slug":"hall"},"organizer":null,"checked_in":2}],
             "meta":{"next_page":2}}
        """.trimIndent())
        val evening = page.data.single()
        assertEquals(9L, evening.occurrenceId)
        assertEquals("hall", evening.venue?.slug)
        assertNull(evening.organizer)
        assertEquals(2, evening.checkedIn)
        assertEquals(2, page.meta?.nextPage)
        assertNull(json.decodeFromString<ApiEnvelope<List<AttendedEvening>>>("""{"data":[],"meta":{"next_page":null}}""").meta?.nextPage)
    }

    // 15
    @Test fun sonoQuiCreatoERegolaDellaFinestra() {
        val link = json.decodeFromString<ApiEnvelope<PresenceLink>>("""{"data":{"token":"tok123","url":"https://eventi.fabiodalez.it/sono-qui/tok123","expires_at":"2026-10-24T01:00:00+02:00"}}""").data
        assertEquals("tok123", link.token)
        assertTrue(link.activeAt(Instant.parse("2026-10-23T20:00:00Z")))
        assertFalse(link.activeAt(Instant.parse("2026-10-24T00:00:00Z")))
        assertEquals("""{"occurrence_id":7,"display_name":"Fabio"}""", json.encodeToString(PresenceBody(7, "Fabio", null)))

        val now = Instant.parse("2026-10-23T12:00:00Z")
        fun date(start: String, end: String? = null, status: String = "scheduled") =
            Occurrence(occurrenceId = 7, eventId = 1, eventSlug = "x", startsAt = start, effectiveEndsAt = end, title = "X", status = status)
        assertTrue(date("2026-10-23T21:00:00+02:00").offersPresence(now))
        assertTrue(date("2026-10-23T10:00:00+02:00", "2026-10-23T16:00:00+02:00").offersPresence(now))
        assertFalse(date("2026-10-24T21:00:00+02:00").offersPresence(now))
        assertFalse(date("2026-10-23T08:00:00+02:00", "2026-10-23T11:00:00+02:00").offersPresence(now))
        assertFalse(date("2026-10-23T21:00:00+02:00", status = "cancelled").offersPresence(now))
    }

    private companion object {
        const val BOOKINGS = """
            {"data":[{"id":3,"occurrence_id":7,"title":"Teatro","status":"confirmed","can_cancel":true,"tickets":[
              {"id":1,"attendee_name":"Mario Rossi","status":"valid","qr_payload":"QR-1","share_url":"https://eventi.fabiodalez.it/biglietto/1?expires=1&signature=abc"},
              {"id":2,"attendee_name":"Anna Bianchi","status":"cancelled","qr_payload":null,"share_url":null}]}],
             "meta":{"next_page":null}}
        """
    }
}
