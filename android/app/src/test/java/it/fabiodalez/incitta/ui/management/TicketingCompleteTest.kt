package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.management.BookingCounts
import it.fabiodalez.incitta.data.management.BookingSettings
import it.fabiodalez.incitta.data.management.EventTicketing
import it.fabiodalez.incitta.data.management.ExportKind
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedBooking
import it.fabiodalez.incitta.data.management.ManagedTicket
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.MessageHistory
import it.fabiodalez.incitta.data.management.SentMessage
import it.fabiodalez.incitta.data.management.fakeRepository
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.ticketing.BookingsViewModel
import it.fabiodalez.incitta.ui.management.ticketing.DateTab
import it.fabiodalez.incitta.ui.management.ticketing.EventTicketingViewModel
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateViewModel
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDatesViewModel
import java.nio.file.Files
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* La biglietteria completa (P3) con un server finto: prenotazioni, annullamento, impostazioni, messaggi, file, riquadro dell'evento. */
@OptIn(ExperimentalCoroutinesApi::class)
class TicketingCompleteTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val code = "A".repeat(64)

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun booking(id: Long = 5, vararg tickets: ManagedTicket) = ManagedBooking(id = id, status = "confirmed", tickets = tickets.toList(), ticketsCount = tickets.size)

    @Test fun datesSearchIsSavedAndSentToTheServer() = runTest(dispatcher) {
        val ticketing = FakeTicketing()
        val saved = SavedStateHandle()
        val viewModel = TicketingDatesViewModel(fakeRepository(TestScope(testScheduler), ticketing), saved)
        viewModel.search("  Aurora ")
        assertEquals("Aurora", saved.get<String>(TicketingDatesViewModel.KEY_QUERY))
        assertEquals("dates:upcoming:1:Aurora", ticketing.calls.last())
        viewModel.search("")
        assertEquals("dates:upcoming:1", ticketing.calls.last())
    }

    @Test fun bookingsFilterAndSearchKeepTheirStateAndShowTheCounts() = runTest(dispatcher) {
        val ticketing = FakeTicketing().apply {
            bookingRows = listOf(booking(5, ManagedTicket(1, "Ada", status = "valid", code = code)))
            bookingCounts = BookingCounts(all = 3, confirmed = 2, checkedIn = 1, waitlisted = 0, cancelled = 1)
        }
        val saved = SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L))
        val viewModel = BookingsViewModel(fakeRepository(TestScope(testScheduler), ticketing), saved)
        assertEquals("bookings:41:all::1", ticketing.calls.last())
        assertEquals(2, viewModel.uiState.value.counts.of("confirmed"))
        assertEquals(3, viewModel.uiState.value.counts.of(null))
        viewModel.filter("cancelled")
        assertEquals("bookings:41:cancelled::1", ticketing.calls.last())
        assertEquals("cancelled", saved.get<String>(BookingsViewModel.KEY_STATUS))
        viewModel.filter("non-esiste")
        assertNull(viewModel.uiState.value.status)
        viewModel.search("ada@example.org")
        assertEquals("bookings:41:all:ada@example.org:1", ticketing.calls.last())
        ticketing.bookingRows = emptyList()
        viewModel.search("nessuno")
        assertEquals(Loadable.Empty, viewModel.uiState.value.list)
    }

    @Test fun cancellingABookingOrATicketSendsTheReasonAndRefreshes() = runTest(dispatcher) {
        val first = ManagedTicket(1, "Ada", status = "valid", code = code)
        val second = ManagedTicket(2, "Bruno", status = "valid", code = "B".repeat(64))
        val ticketing = FakeTicketing().apply { bookingRows = listOf(booking(5, first, second)) }
        val viewModel = BookingsViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L)))
        viewModel.cancel(viewModel.uiState.value.bookings.single(), second, "")
        assertTrue(ticketing.calls.contains("cancel:41:5:2:"))
        assertEquals(UiText.Res(R.string.management_ticket_cancelled), viewModel.uiState.value.message)
        assertEquals(listOf("valid", "cancelled"), viewModel.uiState.value.bookings.single().tickets.map { it.status })
        viewModel.cancel(viewModel.uiState.value.bookings.single(), null, "Serata annullata")
        assertTrue(ticketing.calls.contains("cancel:41:5:all:Serata annullata"))
        assertEquals("cancelled", viewModel.uiState.value.bookings.single().status)
        assertEquals("bookings:41:all::1", ticketing.calls.last())
    }

    @Test fun checkInFromTheRowGoesThroughTheEncryptedQueueAndMarksTheListUsed() = runTest(dispatcher) {
        val ticket = ManagedTicket(1, "Ada", status = "valid", code = code)
        val ticketing = FakeTicketing().apply { bookingRows = listOf(booking(5, ticket)) }
        val device = MemoryDevice()
        val repository = fakeRepository(TestScope(testScheduler), ticketing, device = device)
        val viewModel = BookingsViewModel(repository, SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L)))
        viewModel.checkIn(ticket)
        assertTrue(ticketing.calls.any { it.startsWith("checkin:41:AAAA") })
        assertEquals(UiText.Res(R.string.management_accepted, listOf("Ada Rossi")), viewModel.uiState.value.message)
        assertTrue(repository.checkins.value.pending.isEmpty())
        // Un biglietto senza codice (già entrato o annullato) non parte.
        val calls = ticketing.calls.size
        viewModel.checkIn(ManagedTicket(2, "Bruno", status = "checked_in"))
        assertEquals(calls, ticketing.calls.size)
    }

    @Test fun settingsFormStartsFromTheServerAndShowsFieldErrors() = runTest(dispatcher) {
        val settings = BookingSettings(bookingEnabled = true, bookingCapacity = 40, bookingLimit = 2, bookingOpensAt = "2026-12-01T09:00:00+00:00",
            bookingInstructions = "Porta la tessera.", bookingFields = mapOf("phone" to "required"))
        val ticketing = FakeTicketing(date = ManagedDate(41, "Concerto", canManage = true, canCheckIn = true, timezone = "Europe/Rome", settings = settings))
        val viewModel = TicketingDateViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L, TicketingDateViewModel.KEY_TAB to "settings")))
        assertEquals(DateTab.Settings, viewModel.uiState.value.tab)
        val draft = viewModel.uiState.value.settingsDraft!!
        assertEquals("40", draft.capacity)
        assertEquals(10, draft.opensAt!!.hour)
        assertEquals("required", draft.fields["phone"])
        assertEquals("hidden", draft.fields["country"])

        viewModel.editSettings { it.copy(capacity = "1") }
        ticketing.settingsFailure = ApiException(422, ApiProblem(message = "Dati non validi", code = "VALIDATION_FAILED", fields = mapOf("booking_capacity" to listOf("Troppo pochi posti."), "ticketing" to listOf("I posti non possono essere inferiori ai biglietti già confermati."))))
        viewModel.saveSettings()
        val errors = viewModel.uiState.value.settingsErrors
        assertEquals("Troppo pochi posti.", errors.field("booking_capacity"))
        assertEquals("I posti non possono essere inferiori ai biglietti già confermati.", errors.general)
        assertTrue(viewModel.uiState.value.settingsDirty)
        assertEquals(JsonPrimitive(1L), ticketing.lastSettings!!["booking_capacity"])
        // Le istruzioni non toccate non si mandano.
        assertFalse(ticketing.lastSettings!!.containsKey("booking_instructions"))

        ticketing.settingsFailure = null
        viewModel.saveSettings()
        assertFalse(viewModel.uiState.value.settingsDirty)
        assertEquals(UiText.Res(R.string.management_settings_saved), viewModel.uiState.value.message)
        assertEquals("40", viewModel.uiState.value.settingsDraft!!.capacity)
    }

    @Test fun messagesTabLoadsTheHistoryOnceAndAfterSending() = runTest(dispatcher) {
        val ticketing = FakeTicketing().apply { history = MessageHistory(listOf(SentMessage(1, "Orario", "Si comincia alle 22.", sender = "Marta", recipientsCount = 12))) }
        val viewModel = TicketingDateViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L)))
        assertFalse(ticketing.calls.any { it.startsWith("messages") })
        viewModel.selectTab(DateTab.Messages)
        assertEquals("Orario", (viewModel.uiState.value.messages as Loadable.Ready).value.data.single().subject)
        viewModel.selectTab(DateTab.Staff)
        viewModel.selectTab(DateTab.Messages)
        assertEquals(1, ticketing.calls.count { it.startsWith("messages") })
        viewModel.sendMessage("Tessera", "Portatela", false) {}
        assertEquals(2, ticketing.calls.count { it.startsWith("messages") })
    }

    @Test fun exportsLandInTheAppCacheAndOldFilesArePurged() = runTest(dispatcher) {
        val cache = Files.createTempDirectory("cache").toFile()
        val old = java.io.File(ManagementFiles.directory(cache).apply { mkdirs() }, "vecchio.csv").apply { writeText("x"); setLastModified(System.currentTimeMillis() - 2 * ManagementFiles.MAX_AGE_MILLIS) }
        val ticketing = FakeTicketing()
        val viewModel = TicketingDateViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf(TicketingDateViewModel.KEY_ID to 41L)))
        viewModel.export(ExportKind.Participants, cache)
        val file = viewModel.uiState.value.exported!!
        assertEquals("partecipanti-41.csv", file.file.name)
        assertEquals("text/csv", file.mime)
        assertEquals(ManagementFiles.directory(cache), file.file.parentFile)
        assertFalse(old.exists())
        assertEquals(1, ManagementFiles.purge(cache, maxAgeMillis = 0))
        cache.deleteRecursively()
    }

    @Test fun eventBoxReadsTheVenueAndEventFromTheRoute() = runTest(dispatcher) {
        val ticketing = FakeTicketing().apply { box = EventTicketing(eventId = 9, title = "Concerto", ticketingEnabled = true, datesCount = 2) }
        val viewModel = EventTicketingViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf(EventTicketingViewModel.KEY_VENUE to 12L, EventTicketingViewModel.KEY_EVENT to 9L)))
        assertEquals("event:12:9", ticketing.calls.last())
        assertEquals(2, (viewModel.uiState.value as Loadable.Ready).value.datesCount)
    }

    @Test fun settingsBodyIsTheSiteFormWithCityTimes() {
        val settings = BookingSettings(bookingInstructions = "<p>Ciao</p>")
        val draft = it.fabiodalez.incitta.data.management.BookingSettingsDraft(
            enabled = true, capacity = "", limit = "abc", opensAt = java.time.LocalDateTime.of(2026, 12, 5, 21, 0), instructions = "Nuove",
            questions = listOf(it.fabiodalez.incitta.data.management.QuestionDraft(label = " Turno ", type = "choice", options = "Primo\n\n Secondo "),
                it.fabiodalez.incitta.data.management.QuestionDraft(id = "qabcdefgh", label = "Allergie", type = "text", options = "ignorate", required = true)),
        )
        val body = it.fabiodalez.incitta.data.management.bookingSettingsBody(draft, settings, java.time.ZoneId.of("Europe/Rome"))
        assertEquals(kotlinx.serialization.json.JsonNull, body["booking_capacity"])
        assertEquals(JsonPrimitive("abc"), body["booking_limit"])
        assertEquals(JsonPrimitive("2026-12-05T21:00:00+01:00"), body["booking_opens_at"])
        assertEquals(JsonPrimitive("Nuove"), body["booking_instructions"])
        assertEquals(JsonPrimitive("hidden"), body["booking_fields"]!!.jsonObject["phone"])
        val questions = body["booking_questions"] as kotlinx.serialization.json.JsonArray
        assertEquals(JsonPrimitive("Turno"), questions[0].jsonObject["label"])
        assertEquals(JsonPrimitive("Primo\nSecondo"), questions[0].jsonObject["options"])
        assertFalse(questions[0].jsonObject.containsKey("id"))
        assertEquals(JsonPrimitive("qabcdefgh"), questions[1].jsonObject["id"])
        assertFalse(questions[1].jsonObject.containsKey("options"))
    }

    @Test fun managedDateReadsTheNewFieldsAndToleratesAnOldServer() {
        val json = kotlinx.serialization.json.Json { ignoreUnknownKeys = true }
        val full = json.decodeFromString<ManagedDate>("""{"id":41,"title":"Concerto","booking_enabled":true,"ticketing_enabled":false,"ended":true,"timezone":"Europe/Rome",
            "counts":{"confirmed":3,"checked_in":1,"waitlisted":2,"cancelled":0,"no_show":1,"capacity":null},
            "settings":{"booking_enabled":true,"booking_limit":4,"booking_fields":{"phone":"optional"},"booking_questions":[{"id":"qabcdefgh","label":"Turno","type":"choice","options":["A","B"],"required":false}],"limits":{"questions":5}}}""")
        assertTrue(full.bookingEnabled)
        assertFalse(full.ticketingEnabled)
        assertTrue(full.ended)
        assertNull(full.counts!!.capacity)
        assertEquals(listOf("A", "B"), full.settings!!.bookingQuestions.single().options)
        val old = json.decodeFromString<ManagedDate>("""{"id":41,"title":"Concerto"}""")
        assertNull(old.settings)
        assertTrue(old.ticketingEnabled)
        assertFalse(old.ended)
        val row = json.decodeFromString<ManagedBooking>("""{"id":5,"status":"confirmed","booker":{"phone":"+39 333","note":" ","city":null},"tickets":[{"id":1,"attendee_name":"Ada","status":"valid","code":"x"}]}""")
        assertEquals(listOf("phone" to "+39 333"), row.bookerFields)
        assertTrue(row.tickets.single().canCheckIn)
    }
}
