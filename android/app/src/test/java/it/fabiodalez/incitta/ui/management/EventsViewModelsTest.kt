package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.management.EventsTab
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementEventsApi
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.events.EventEditorViewModel
import it.fabiodalez.incitta.ui.management.events.EventFormMode
import it.fabiodalez.incitta.ui.management.events.EventOverviewViewModel
import it.fabiodalez.incitta.ui.management.events.EventsListViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
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

/* I ViewModel degli eventi con un server finto: elenco, scheda, bozza salvata due volte, pubblicazione, errori sotto il campo. */
@OptIn(ExperimentalCoroutinesApi::class)
class EventsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    /* `ApiClient` lavora su `Dispatchers.IO`, fuori dal tempo finto del test: si aspetta che la condizione diventi vera. */
    private fun eventually(condition: () -> Boolean) {
        repeat(300) { if (condition()) return; Thread.sleep(10) }
        fail("la condizione non si è avverata")
    }

    /** Un server che risponde secondo metodo e percorso e ricorda le richieste. */
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

    private fun repository(scope: TestScope, server: Server) = ManagementRepository(
        7, "token", FakeTicketing(),
        FakeIdentity(list = ManagementSubjects(venues = listOf(ManagedVenue(id = 12, name = "Circolo Aurora", role = "owner",
            permissions = mapOf("events_create" to true, "events_publish_directly" to true))))),
        null, MemoryDevice(), scope, events = ManagementEventsApi(ApiClient("test", server.client), "token"),
    )

    private fun detail(id: Long, status: String = "draft") =
        """{"id":$id,"title":"Serata","status":{"value":"$status","label":"x"},"price_type":{"value":"free","label":"Ingresso libero"},"abilities":{"update":true,"publish":true}}"""

    @Test fun theListKeepsTabAndSearchInTheSavedStateAndPages() = runTest(dispatcher) {
        val server = Server { _, path, _ ->
            if (path.contains("page=2")) 200 to """{"data":[{"id":3,"title":"Tre"}],"meta":{"next_page":null,"total":3}}"""
            else 200 to """{"data":[{"id":1,"title":"Uno","status":{"value":"draft","label":"Bozza"}},{"id":2,"title":"Due"}],"meta":{"next_page":2,"total":3}}"""
        }
        val saved = SavedStateHandle()
        val viewModel = EventsListViewModel(repository(TestScope(testScheduler), server), saved)
        eventually { viewModel.uiState.value.loaded && !viewModel.uiState.value.busy }
        assertEquals("GET management/venues/12/events?tab=all&page=1", server.calls.last())
        viewModel.more()
        eventually { viewModel.uiState.value.events.size == 3 && !viewModel.uiState.value.busy }
        assertEquals(listOf(1L, 2L, 3L), viewModel.uiState.value.events.map { it.id })
        viewModel.showTab(EventsTab.Drafts)
        eventually { viewModel.uiState.value.loaded && !viewModel.uiState.value.busy }
        viewModel.typeQuery("jazz"); viewModel.search()
        eventually { server.calls.last().contains("q=jazz") && !viewModel.uiState.value.busy }
        assertEquals("GET management/venues/12/events?tab=drafts&page=1&q=jazz", server.calls.last())
        assertEquals("drafts", saved.get<String>(EventsListViewModel.KEY_TAB))
        assertEquals("venue:12", saved.get<String>("events_subject"))
    }

    @Test fun theDraftIsSavedOnceAndThenPublishedOnTheSameRow() = runTest(dispatcher) {
        val server = Server { method, path, body ->
            when {
                path.endsWith("/events/form") -> 200 to """{"data":{"categories":[{"id":4,"name":"Musica"}],"defaults":{"price_type":"free"}}}"""
                method == "POST" && path.endsWith("/events") && body!!.contains("\"publish\":true") -> 201 to """{"data":${detail(50, "published")},"meta":{"outcome":"published"}}"""
                method == "POST" && path.endsWith("/events") -> 201 to """{"data":${detail(50)},"meta":{"outcome":"draft"}}"""
                else -> 404 to """{"error":{"code":"NOT_FOUND","message":"x"}}"""
            }
        }
        val viewModel = EventEditorViewModel(repository(TestScope(testScheduler), server), SavedStateHandle())
        eventually { viewModel.uiState.value.options is Loadable.Ready }
        assertEquals(EventFormMode.VenueCreate, viewModel.uiState.value.mode)

        // Senza titolo la bozza non parte: l'errore è sotto il campo, nessuna chiamata.
        viewModel.saveDraft()
        assertEquals(EventEditorViewModel.TITLE_NEEDED, viewModel.uiState.value.errors.fields["title"])
        assertTrue(server.bodies.isEmpty())
        viewModel.edit { it.copy(title = "Jazz al circolo") }
        viewModel.saveDraft()
        eventually { viewModel.uiState.value.eventId == 50L && !viewModel.uiState.value.busy }
        assertEquals(1, server.bodies.size)
        assertFalse(server.bodies.last().contains("draft_id"))

        viewModel.saveDraft()
        eventually { server.bodies.size == 2 && !viewModel.uiState.value.busy }
        assertEquals(50, Json.parseToJsonElement(server.bodies.last()).let { (it as JsonObject)["draft_id"]!!.jsonPrimitive.content.toInt() })

        viewModel.publish()
        eventually { viewModel.uiState.value.finishedId == 50L }
        assertTrue(server.bodies.last().contains("\"draft_id\":50"))
        assertTrue(server.bodies.last().contains("\"publish\":true"))
    }

    @Test fun serverErrorsGoUnderTheirFields() = runTest(dispatcher) {
        val server = Server { method, path, _ ->
            when {
                path.endsWith("/events/form") -> 200 to """{"data":{"categories":[]}}"""
                method == "GET" && path.endsWith("/events/8") -> 200 to """{"data":${detail(8, "published")}}"""
                method == "PATCH" -> 422 to """{"error":{"code":"VALIDATION_FAILED","message":"Dati non validi","fields":{"ticket_url":["Il link non è valido."],"zzz":["Altro"]}}}"""
                else -> 404 to "{}"
            }
        }
        val viewModel = EventEditorViewModel(repository(TestScope(testScheduler), server), SavedStateHandle(mapOf("id" to 8L)))
        eventually { viewModel.uiState.value.options is Loadable.Ready }
        assertEquals(EventFormMode.VenueEdit, viewModel.uiState.value.mode)
        viewModel.save()
        eventually { !viewModel.uiState.value.errors.isEmpty }
        assertEquals("Il link non è valido.", viewModel.uiState.value.errors.field("ticket_url"))
        assertEquals("Altro", viewModel.uiState.value.errors.general)
    }

    @Test fun theOverviewPublishesAndShowsTheNewState() = runTest(dispatcher) {
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.endsWith("/events/9") -> 200 to """{"data":${detail(9)}}"""
                method == "POST" && path.endsWith("/events/9/publish") -> 200 to """{"data":${detail(9, "published")},"meta":{"outcome":"published"}}"""
                else -> 404 to "{}"
            }
        }
        val viewModel = EventOverviewViewModel(repository(TestScope(testScheduler), server), SavedStateHandle(mapOf("id" to 9L)))
        eventually { viewModel.uiState.value.event is Loadable.Ready }
        viewModel.publish()
        eventually { (viewModel.uiState.value.event as? Loadable.Ready)?.value?.status?.value == "published" }
        assertEquals("published", (viewModel.uiState.value.event as Loadable.Ready).value.status.value)
        assertEquals("POST management/venues/12/events/9/publish", server.calls.last())
    }
}
