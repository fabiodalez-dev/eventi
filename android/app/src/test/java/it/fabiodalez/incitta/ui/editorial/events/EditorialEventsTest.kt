package it.fabiodalez.incitta.ui.editorial.events

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.editorial.EditorialChoice
import it.fabiodalez.incitta.data.editorial.EditorialEventFilters
import it.fabiodalez.incitta.data.editorial.EditorialEventRow
import it.fabiodalez.incitta.data.editorial.EditorialEventsApi
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionDetail
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.SubmissionForm
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.searchableOptions
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
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

/*
 * R2 — gli eventi della redazione con un server finto: i percorsi e i
 * parametri che l'app manda (città compresa), la lettura delle risposte, le
 * decisioni, la selezione per l'eliminazione in gruppo, il modulo di una
 * proposta con le date nell'ora della città, i filtri del pannello.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class EditorialEventsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun eventually(condition: () -> Boolean) {
        repeat(300) { if (condition()) return; Thread.sleep(10) }
        fail("la condizione non si è avverata")
    }

    /** Un server che risponde secondo metodo e percorso e ricorda le richieste. */
    private class Server(private val routes: (method: String, path: String, body: String?) -> Pair<Int, String>) {
        val calls = java.util.concurrent.CopyOnWriteArrayList<String>()
        val bodies = java.util.concurrent.CopyOnWriteArrayList<String>()
        val client: OkHttpClient = OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val body = request.body?.let { Buffer().also(it::writeTo).readUtf8() }
            val path = request.url.encodedPath.substringAfter("/v1/") + (request.url.encodedQuery?.let { "?$it" } ?: "")
            calls += "${request.method} $path"
            body?.let { bodies += it }
            val (status, payload) = routes(request.method, path, body)
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(status).message("x")
                .body(payload.toResponseBody("application/json".toMediaType())).build()
        }.build()
    }

    private fun repository(server: Server): EditorialRepository =
        EditorialRepository(1, "t", FakeDesk(unlocked = true), EditorialLocalLock(clock = { 0L }), ApiClient("test", server.client)).also { it.selectedCity = "padova" }

    private val row = """{"id":41,"slug":"concerto","title":"Concerto","status":{"value":"pending","label":"In attesa"},
        "source":{"value":"import_ics","label":"Import","imported":true},"venue":{"id":3,"name":"Circolo Aurora"},
        "city":{"id":1,"name":"Padova","timezone":"Europe/Rome"},"occurrences_count":2,"is_featured":false,"trashed":false,
        "abilities":{"view":true,"publish":true,"reject":true,"feature":false,"destroy":false}}"""

    private fun detail(status: String = "pending") = """{"id":41,"title":"Concerto","status":{"value":"$status","label":"x"},
        "city":{"id":1,"name":"Padova","timezone":"Europe/Rome"},"cancel_impact":{"upcoming_dates":3,"saved_by":12},
        "editorial":{"source":"Manuale","editorial_score":40},"links":{"statistics":"admin/statistiche-condivisioni/event/41"},
        "abilities":{"publish":true,"merge":true}}"""

    @Test fun theListAsksWithTheCriteriaAndTheCityAndReadsRowsAndTabs() = runTest {
        val server = Server { _, path, _ ->
            if (path.startsWith("editorial/events/filters")) 200 to """{"data":{"venues":[{"value":"3","label":"Circolo Aurora"}],"statuses":[{"value":"draft","label":"Bozza"}]}}"""
            else 200 to """{"data":[$row],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":1,"tabs":{"all":9,"pending":1}}}"""
        }
        val viewModel = EventsQueueViewModel(repository(server), SavedStateHandle(mapOf("tab" to "pending", "filter" to "missing_poster:1")))
        eventually { viewModel.rows.value.page == 1 && viewModel.filters.value.venues.isNotEmpty() }

        val list = server.calls.first { it.startsWith("GET editorial/events?") }
        assertTrue(list, list.contains("tab=pending") && list.contains("filter[missing_poster]=1") && list.contains("city=padova") && list.contains("per_page=20"))
        val first = viewModel.rows.value.items.single()
        assertEquals("Circolo Aurora", first.venue?.name)
        assertEquals(true, first.can["publish"])
        assertEquals(false, first.can["destroy"])
        assertEquals(mapOf("all" to 9, "pending" to 1), viewModel.rows.value.tabCounts)
    }

    @Test fun aDecisionFromTheRecordReplacesTheRecordAndADeletionClosesIt() = runTest {
        val server = Server { method, path, _ ->
            when {
                method == "GET" -> 200 to """{"data":${detail()}}"""
                path.startsWith("editorial/events/41/publish") -> 200 to """{"data":${detail("published")},"message":"Evento pubblicato."}"""
                method == "DELETE" -> 200 to """{"data":{"id":41,"trashed":true},"message":"Evento spostato nel cestino."}"""
                else -> 500 to "{}"
            }
        }
        val viewModel = EventRecordViewModel(repository(server), SavedStateHandle(mapOf("id" to 41L)))
        eventually { viewModel.uiState.value is Loadable.Ready }
        val loaded = (viewModel.uiState.value as Loadable.Ready).value
        assertEquals(3, loaded.cancelImpact.upcomingDates)
        assertEquals(12, loaded.cancelImpact.savedBy)
        assertEquals("admin/statistiche-condivisioni/event/41", loaded.links.statistics)

        viewModel.decide(EventDecision.Publish)
        eventually { !viewModel.action.value.busy && viewModel.action.value.message != null }
        assertEquals("published", (viewModel.uiState.value as Loadable.Ready).value.status.value)
        assertEquals("Evento pubblicato.", viewModel.action.value.message)

        viewModel.decide(EventDecision.Destroy)
        eventually { viewModel.trashed.value }
        assertTrue(server.calls.contains("DELETE editorial/events/41?city=padova"))
    }

    @Test fun decisionsSendTheBodiesThePanelForms() = runTest {
        val server = Server { _, _, _ -> 200 to """{"data":${detail()}}""" }
        val api = EditorialEventsApi(ApiClient("test", server.client), "t") { null }
        api.decide(41, EventDecision.Reject("Mancano gli orari"))
        api.decide(41, EventDecision.Cancel(" "))
        api.decide(41, EventDecision.Feature(true, "2026-12-24T23:00:00+01:00"))
        api.decide(41, EventDecision.Feature(false, "2026-12-24T23:00:00+01:00"))
        api.decide(41, EventDecision.Merge(77))
        val json = Json { ignoreUnknownKeys = true }
        assertEquals("Mancano gli orari", json.parseToJsonElement(server.bodies[0]).let { (it as JsonObject)["reason"]!!.jsonPrimitive.content })
        assertEquals("{}", server.bodies[1])
        assertEquals("""{"featured":true,"until":"2026-12-24T23:00:00+01:00"}""", server.bodies[2])
        assertEquals("""{"featured":false}""", server.bodies[3])
        assertEquals("""{"absorbed":77}""", server.bodies[4])
        assertEquals(listOf("reject", "cancel", "feature", "feature", "merge"), server.calls.map { it.substringAfterLast('/') })
    }

    @Test fun theSelectionStopsAtFiftyAndTheBulkDeleteSendsTheIds() = runTest {
        val server = Server { method, path, _ ->
            when {
                path.startsWith("editorial/events/bulk-delete") -> 200 to """{"data":{"deleted":[1,2],"skipped":1}}"""
                path.startsWith("editorial/events/filters") -> 200 to """{"data":{}}"""
                else -> 200 to """{"data":[],"meta":{"current_page":1,"last_page":1,"total":0}}"""
            }
        }
        val viewModel = EventsQueueViewModel(repository(server), SavedStateHandle())
        (1L..60L).forEach(viewModel::toggleSelection)
        assertEquals(EventsQueueViewModel.MAX_BULK, viewModel.selection.value.size)
        viewModel.clearSelection()
        listOf(1L, 2L, 3L).forEach(viewModel::toggleSelection)
        viewModel.toggleSelection(3L)
        viewModel.deleteSelected { deleted, skipped -> "$deleted/$skipped" }
        eventually { viewModel.action.value.message != null }
        assertEquals("2/1", viewModel.action.value.message)
        assertTrue(viewModel.selection.value.isEmpty())
        assertTrue(server.bodies.any { it == """{"ids":[1,2]}""" })
    }

    @Test fun aLockedCallFailsWithTheMessageAndKeepsTheRecord() = runTest {
        val server = Server { method, _, _ ->
            if (method == "GET") 200 to """{"data":${detail()}}"""
            else 403 to """{"error":{"code":"FORBIDDEN","message":"Non hai il permesso."}}"""
        }
        val viewModel = EventRecordViewModel(repository(server), SavedStateHandle(mapOf("id" to 41L)))
        eventually { viewModel.uiState.value is Loadable.Ready }
        viewModel.decide(EventDecision.Duplicate)
        eventually { viewModel.action.value.error != null }
        assertNull(viewModel.openCopy.value)
        assertTrue(viewModel.uiState.value is Loadable.Ready)
    }

    @Test fun theFilterParameterOfARouteBecomesTheInitialFilters() {
        assertEquals(mapOf("status" to "pending", "missing_poster" to "1"), EventsQueueViewModel.parseFilterParam("status:pending, missing_poster:1,broken,:x"))
        assertTrue(EventsQueueViewModel.parseFilterParam("").isEmpty())
    }

    @Test fun theTwelvePanelFiltersComeInThePanelOrderAndEmptyOnesHide() {
        val labels = EventFilterLabels("Stato", "Città", "Locale", "Categoria", "Provenienza", "In evidenza", "Sì", "No",
            mapOf("published_today" to "Oggi"), "Cestino", "Anche", "Solo")
        val filters = EditorialEventFilters(
            cities = listOf(EditorialChoice("1", "Padova")), categories = listOf(EditorialChoice("2", "Musica")),
            venues = listOf(EditorialChoice("3", "Circolo")), sources = listOf(EditorialChoice("manual", "Manuale")),
            statuses = listOf(EditorialChoice("draft", "Bozza")),
        )
        val all = eventFilterDefinitions(filters, labels)
        assertEquals(listOf("status", "city", "venue", "category", "source", "is_featured", "published_today", "published_this_week",
            "missing_poster", "incomplete", "possible_duplicates", "trashed"), all.map { it.key })
        assertTrue(all.first { it.key == "venue" }.searchable)
        assertEquals(listOf("with", "only"), all.last().options.map { it.value })
        // Senza le scelte del server restano i filtri che non ne hanno bisogno.
        assertEquals(listOf("is_featured", "published_today", "published_this_week", "missing_poster", "incomplete", "possible_duplicates", "trashed"), eventFilterDefinitions(EditorialEventFilters(), labels).map { it.key })
    }

    @Test fun aSearchableFilterShowsTheMatchesAndKeepsTheChosenOne() {
        val venues = (1..100).map { OptionChoice(it.toString(), "Locale $it") }
        val definition = FilterDefinition("venue", "Locale", venues, searchable = true)
        assertEquals(30, searchableOptions(definition, "", null).size)
        assertEquals(listOf("Locale 7", "Locale 70", "Locale 71", "Locale 72", "Locale 73", "Locale 74", "Locale 75", "Locale 76", "Locale 77", "Locale 78", "Locale 79"),
            searchableOptions(definition, "locale 7", null).map { it.label })
        assertEquals("99", searchableOptions(definition, "Locale 1", "99").first().value)
        assertEquals(venues, searchableOptions(definition.copy(searchable = false), "x", null))
    }

    @Test fun theRowMenuFollowsThePanelOrderAndMarksTheDangerousOnes() {
        val actions = eventRowActions { it }
        assertEquals(listOf("view", "publish", "unpublish", "reject", "cancel", "feature", "duplicate", "merge", "destroy", "restore"), actions.map { it.key })
        assertEquals(setOf("reject", "cancel", "destroy"), actions.filter { it.destructive }.map { it.key }.toSet())
    }

    @Test fun theSubmissionFormChecksTheFieldsAndSendsTheCityTime() {
        val detail = EditorialSubmissionDetail(id = 5, title = "Proposta", timezone = "Europe/Rome",
            form = SubmissionForm(title = "Proposta", rawText = "<p>Testo <strong>forte</strong></p>", startsAtHint = "2026-12-10T19:00:00+00:00", priceType = "unknown"))
        val draft = SubmissionDraft.from(detail)
        assertEquals(LocalDateTime.of(2026, 12, 10, 20, 0), draft.startsAt)
        assertEquals(setOf("venue_id", "category_id"), draft.problems())
        val ready = draft.copy(venueId = "3", categoryId = "2", priceMin = "12,50", endsAt = LocalDateTime.of(2026, 12, 10, 19, 0))
        assertEquals(setOf("ends_at"), ready.problems())
        val body = ready.copy(endsAt = null).body(ZoneId.of("Europe/Rome"))
        assertEquals("2026-12-10T20:00:00+01:00", body["starts_at_hint"]!!.jsonPrimitive.content)
        assertEquals("12.5", body["price_min"]!!.jsonPrimitive.content)
        assertEquals("3", body["venue_id"]!!.jsonPrimitive.content)
        assertTrue(body["raw_text"]!!.jsonPrimitive.content.contains("<strong>forte</strong>"))
        assertEquals(setOf("title", "price_min"), ready.copy(endsAt = null, title = " ", priceMin = "-1").problems())
    }

    @Test fun aRefusedPublicationPutsTheServerErrorsUnderTheFields() = runTest {
        val server = Server { method, _, _ ->
            if (method == "GET") 200 to """{"data":{"id":5,"title":"P","timezone":"Europe/Rome","form":{"title":"P","starts_at_hint":"2026-12-10T19:00:00+00:00","venue_id":3,"category_id":2,"price_type":"free"},"choices":{},"abilities":{"publish":true}}}"""
            else 422 to """{"error":{"code":"VALIDATION_FAILED","message":"x","fields":{"venue_id":["Il locale non è valido."]}}}"""
        }
        val viewModel = SubmissionReviewViewModel(repository(server), SavedStateHandle(mapOf("id" to 5L)))
        eventually { viewModel.uiState.value is Loadable.Ready }
        viewModel.publish("Campo da compilare")
        eventually { viewModel.errors.value.fields.isNotEmpty() }
        assertEquals("Il locale non è valido.", viewModel.errors.value.field("venue_id"))
        assertNull(viewModel.published.value)
        assertTrue(server.calls.any { it.startsWith("POST editorial/submissions/5/publish") })
    }

    @Test fun aRowWithoutAbilitiesAllowsNothing() {
        val parsed = Json { ignoreUnknownKeys = true }.decodeFromString(EditorialEventRow.serializer(), """{"id":1,"abilities":[]}""")
        assertTrue(parsed.can.isEmpty())
        assertEquals(ListQuery.DEFAULT_PER_PAGE, ListQuery().perPage)
        assertTrue(ApiException(423, null).status == 423)
    }
}
