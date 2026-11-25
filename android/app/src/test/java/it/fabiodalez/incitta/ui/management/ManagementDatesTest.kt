package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.management.DatesPage
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedEventOccurrence
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementDatesApi
import it.fabiodalez.incitta.data.management.ManagementEventsApi
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.dates.DateEditorViewModel
import it.fabiodalez.incitta.ui.management.dates.DateForm
import it.fabiodalez.incitta.ui.management.dates.EventDatesViewModel
import it.fabiodalez.incitta.ui.management.dates.LineupRow
import it.fabiodalez.incitta.ui.management.dates.weekdayLabel
import it.fabiodalez.incitta.ui.management.events.AgendaTime
import it.fabiodalez.incitta.ui.management.events.EventsListViewModel
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Le date della gestione (P2): il modulo come dato, i fusi, i ViewModel con un server finto. */
@OptIn(ExperimentalCoroutinesApi::class)
class ManagementDatesTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val rome = ZoneId.of("Europe/Rome")
    private val json = Json { ignoreUnknownKeys = true }

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun eventually(condition: () -> Boolean) {
        repeat(300) { if (condition()) return; Thread.sleep(10) }
        fail("la condizione non si è avverata")
    }

    private class Server(private val routes: (method: String, path: String, body: String?) -> Pair<Int, String>) {
        val calls = mutableListOf<String>()
        val bodies = mutableListOf<String>()
        val client: OkHttpClient = OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { Buffer().also(it::writeTo).readUtf8() }
            val path = request.url.encodedPath.substringAfter("/v1/") + (request.url.encodedQuery?.let { "?$it" } ?: "")
            calls += "${request.method} $path"
            if (body != null && request.method != "GET") bodies += body
            val (status, payload) = routes(request.method, path, body)
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(status).message("")
                .body(payload.toResponseBody("application/json".toMediaType())).build()
        }.build()
    }

    private fun repository(scope: TestScope, server: Server, organizer: Boolean = false): ManagementRepository {
        val client = ApiClient("test", server.client)
        val subjects = if (organizer) ManagementSubjects(organizers = listOf(ManagedOrganizer(id = 3, name = "Collettivo")))
        else ManagementSubjects(venues = listOf(ManagedVenue(id = 12, name = "Circolo Aurora", role = "owner")))
        return ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), scope,
            events = ManagementEventsApi(client, "token"), dates = ManagementDatesApi(client, "token"))
    }

    private fun row(id: Long, status: String = "scheduled", series: Boolean = false) =
        """{"id":$id,"starts_at":"2026-09-11T19:00:00+00:00","status":{"value":"$status","label":"x"},"series":$series,"practical_details":{},"cost_breakdown":{},"abilities":{"update":true,"cancel":true,"sold_out":true,"delete":true}}"""

    private val meta = """{"next_page":null,"total":1,"event":{"id":5,"title":"Serata","timezone":"Europe/Rome","city":"Padova"},"abilities":{"create":true,"recurrence":true}}"""

    @Test fun theVenueFormSendsTheCityTimeLineupAndReplacesPracticalDetails() {
        val form = DateForm(
            startsAt = LocalDateTime.of(2026, 10, 2, 21, 30), endsAt = LocalDateTime.of(2026, 10, 2, 23, 0),
            capacity = "120", capacityLeft = "", highlight = " Ultimi posti ",
            lineup = listOf(LineupRow(4, "Trio", "live"), LineupRow(null, "DJ", "dj"), LineupRow(null, " ", "live")),
            status = "postponed", practical = mapOf("entrance_notes" to "Cortile"), costs = mapOf("admission" to "8,50"),
        )
        val body = form.toJson(SubjectKind.Venue, rome)
        assertEquals("2026-10-02T21:30:00+02:00", body["starts_at"]!!.jsonPrimitive.content)
        assertEquals(120, body["capacity"]!!.jsonPrimitive.content.toInt())
        assertEquals(JsonNull, body["capacity_left"])
        assertEquals("Ultimi posti", body["highlight"]!!.jsonPrimitive.content)
        val lineup = body["lineup"]!!.jsonArray
        assertEquals(2, lineup.size)
        assertEquals("4", lineup[0].jsonObject["id"]!!.jsonPrimitive.content)
        assertNull(lineup[1].jsonObject["id"])
        // Il locale non manda stato e avviso: sono dell'organizzazione.
        assertNull(body["status"])
        // Informazioni pratiche e costi vanno sempre per intero: vuoto = eredita.
        val practical = body["practical_details"]!!.jsonObject
        assertEquals(8, practical.size)
        assertEquals(JsonNull, practical["parking_notes"])
        assertEquals("8.50", body["cost_breakdown"]!!.jsonObject["admission"]!!.jsonPrimitive.content)
        assertEquals(JsonNull, body["cost_breakdown"]!!.jsonObject["drink"])
    }

    @Test fun theOrganizerFormSendsStatusVenueAndNoticeAndStaysInTheCityAcrossDst() {
        val form = DateForm(startsAt = LocalDateTime.of(2026, 10, 25, 2, 30), isAllDay = true, venue = NamedRef(9, "Teatro"), status = "moved", statusNote = "Nuovo **luogo**")
        val body = form.toJson(SubjectKind.Organizer, rome)
        // Nell'ora doppia del ritorno all'ora solare si prende la prima.
        assertEquals("2026-10-25T02:30:00+02:00", body["starts_at"]!!.jsonPrimitive.content)
        assertEquals("9", body["venue_id"]!!.jsonPrimitive.content)
        assertEquals("moved", body["status"]!!.jsonPrimitive.content)
        assertTrue(body["status_note"]!!.jsonPrimitive.content.contains("<strong>luogo</strong>"))
        assertNull(body["lineup"])
        assertNull(body["capacity"])
    }

    @Test fun aSavedDateReopensInTheCityAndTheLocalChecksCatchMistakes() {
        val date = json.decodeFromString<ManagedEventOccurrence>("""
            {"id":3,"starts_at":"2026-09-11T19:00:00+00:00","ends_at":"2026-09-11T21:30:00+00:00","capacity":80,"capacity_left":12,
             "lineup":[{"id":4,"name":"Trio","role":{"value":"opening","label":"Apertura"}}],
             "practical_details":{"accessibility":"yes","entrance_notes":null},"cost_breakdown":{"admission":"8.50","drink":"3.00"}}
        """.trimIndent())
        val form = DateForm.from(date, rome)
        assertEquals(LocalDateTime.of(2026, 9, 11, 21, 0), form.startsAt)
        assertEquals("12", form.capacityLeft)
        assertEquals(LineupRow(4, "Trio", "opening"), form.lineup.single())
        assertEquals("yes", form.practical["accessibility"])
        assertEquals("8.5", form.costs["admission"]); assertEquals("3", form.costs["drink"])
        assertTrue(form.localErrors().isEmpty())
        assertEquals(setOf("ends_at", "capacity", "cost_breakdown"),
            form.copy(endsAt = form.startsAt, capacity = "abc", costs = mapOf("admission" to "1,234")).localErrors())
        assertEquals(setOf("starts_at"), DateForm().localErrors())
        // Il server manda `{}` per le parti vuote (mai `[]`): la pagina si legge.
        assertTrue(json.decodeFromString<DatesPage>("""{"data":[${row(1)}],"meta":$meta}""").data.single().costBreakdown.isEmpty())
    }

    @Test fun agendaTimesAreStoredLikeThePanelAndShownInTheCity() {
        assertEquals(LocalDateTime.of(2026, 9, 3, 20, 0), AgendaTime.toCity("2026-09-03 18:00:00", rome))
        assertEquals(LocalDateTime.of(2026, 9, 3, 20, 0), AgendaTime.toCity("2026-09-03T18:00", rome))
        assertEquals(LocalDateTime.of(2026, 9, 3, 20, 0), AgendaTime.toCity("2026-09-03T20:00:00+02:00", rome))
        assertNull(AgendaTime.toCity("domani", rome))
        assertEquals("2026-12-03 19:00:00", AgendaTime.fromCity(LocalDateTime.of(2026, 12, 3, 20, 0), rome))
        assertEquals("Giovedì", weekdayLabel("thu"))
    }

    @Test fun theListLoadsAndCancelsTheSeriesThenReloads() = runTest(dispatcher) {
        val server = Server { method, path, _ ->
            when {
                method == "POST" && path.endsWith("/status") -> 200 to """{"data":${row(3, "cancelled", true)},"meta":{"changed":4}}"""
                else -> 200 to """{"data":[${row(3, series = true)}],"meta":$meta}"""
            }
        }
        val viewModel = EventDatesViewModel(repository(TestScope(testScheduler), server), SavedStateHandle(mapOf("eventId" to 5L)))
        eventually { viewModel.uiState.value.dates is Loadable.Ready }
        assertEquals("GET management/venues/12/events/5/dates?period=all&page=1", server.calls.last())
        val date = (viewModel.uiState.value.dates as Loadable.Ready).value.single()
        viewModel.cancel(date, series = true)
        eventually { viewModel.uiState.value.message != null && server.calls.size >= 3 }
        assertTrue(server.calls.contains("POST management/venues/12/events/5/dates/3/status"))
        assertTrue(server.bodies.single().contains("\"scope\":\"series\""))
        assertEquals(UiText.Plural(R.plurals.management_dates_updated, 4, listOf(4)), viewModel.uiState.value.message)
    }

    @Test fun theEditorPutsServerErrorsUnderTheFieldAndTellsWhenTheEventGoesBackToReview() = runTest(dispatcher) {
        var fail = true
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.contains("/dates/3") -> 200 to """{"data":${row(3)},"meta":$meta}"""
                method == "PATCH" && fail -> 422 to """{"error":{"message":"Dati non validi","code":"VALIDATION_FAILED","fields":{"venue_id":["Locale non valido."],"altro":["Messaggio generale."]}}}"""
                method == "PATCH" -> 200 to """{"data":${row(3)},"meta":{"sent_to_review":true}}"""
                else -> 404 to "{}"
            }
        }
        val viewModel = DateEditorViewModel(repository(TestScope(testScheduler), server, organizer = true), SavedStateHandle(mapOf("eventId" to 5L, "dateId" to 3L)))
        eventually { viewModel.uiState.value.loading is Loadable.Ready }
        assertEquals(LocalDateTime.of(2026, 9, 11, 21, 0), viewModel.uiState.value.form.startsAt)
        viewModel.edit { it.copy(venue = NamedRef(9, "Teatro")) }
        viewModel.save()
        eventually { !viewModel.uiState.value.busy && !viewModel.uiState.value.errors.isEmpty }
        assertEquals("Locale non valido.", viewModel.uiState.value.errors.field("venue_id"))
        assertEquals("Messaggio generale.", viewModel.uiState.value.errors.general)
        assertTrue(server.calls.contains("PATCH management/organizers/3/events/5/dates/3"))

        fail = false
        viewModel.save()
        eventually { viewModel.uiState.value.done != null }
        assertEquals(UiText.Res(R.string.management_dates_sent_to_review), viewModel.uiState.value.done)

        viewModel.edit { it.copy(startsAt = null) }
        val calls = server.calls.size
        viewModel.save()
        assertEquals(setOf("starts_at"), viewModel.uiState.value.local)
        assertEquals(calls, server.calls.size)
    }

    @Test fun theEventsListMarksTheNextDateSoldOutWithAnExplicitValue() = runTest(dispatcher) {
        val server = Server { method, _, _ ->
            if (method == "PUT") 200 to """{"data":{"id":3,"status":{"value":"sold_out","label":"Esaurito"}},"meta":{"changed":1}}"""
            else 200 to """{"data":[{"id":5,"title":"Serata","next_date":{"id":3,"starts_at":"2026-09-11T19:00:00+00:00","status":{"value":"scheduled","label":"In programma"}}}],"meta":{"next_page":null,"total":1}}"""
        }
        val viewModel = EventsListViewModel(repository(TestScope(testScheduler), server), SavedStateHandle())
        eventually { viewModel.uiState.value.loaded && !viewModel.uiState.value.busy }
        viewModel.setNextSoldOut(viewModel.uiState.value.events.single(), soldOut = true)
        eventually { viewModel.uiState.value.events.single().nextDate?.status?.value == "sold_out" }
        assertTrue(server.calls.contains("PUT management/venues/12/events/5/next-date/sold-out"))
        assertEquals("""{"sold_out":true}""", server.bodies.single())
    }
}
