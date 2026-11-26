package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.editorial.EditorCan
import it.fabiodalez.incitta.data.editorial.EditorDate
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.LocalDateTime
import java.time.ZoneId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
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
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test

/*
 * R3 — il modulo evento e le date della redazione: il corpo che l'app manda
 * (date nell'ora della città, campi nascosti come nel pannello, campi
 * riservati), la lettura della scheda, il salvataggio e i gesti sulle date
 * con un server finto.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class EditorialEventEditorTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val rome: ZoneId = ZoneId.of("Europe/Rome")

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun eventually(condition: () -> Boolean) {
        repeat(300) { if (condition()) return; Thread.sleep(10) }
        fail("la condizione non si è avverata")
    }

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

    private val options = """{"categories":[{"id":2,"name":"Musica"}],"cities":[{"id":1,"name":"Padova","timezone":"Europe/Rome"}],
        "statuses":[{"value":"draft","label":"Bozza"}],"price_types":[{"value":"ticket","label":"Biglietto"}],
        "defaults":{"status":"draft","city_id":1,"price_type":"unknown","currency":"EUR","source":"manual","verification_status":"unverified","editorial_score":0},
        "can":{"feature":true,"admin":true},"catalog":{"age_groups":[{"value":"kids","label":"Bambini"}]}}"""

    private val event = """{"id":41,"slug":"rassegna","status":{"value":"draft","label":"Bozza"},"title":"Rassegna","subtitle":"Tre serate",
        "description":"<p>Musica</p>","category":{"id":2,"name":"Musica"},"tags":[{"id":5,"name":"Folk"}],"city":{"id":1,"name":"Padova","timezone":"Europe/Rome"},
        "venue":{"id":3,"name":"Circolo Aurora"},"custom_location":null,"is_outdoor":true,"organizer":{"id":8,"name":"Rete"},"organizers":[{"id":9,"name":"Pro loco"}],
        "price_type":{"value":"ticket","label":"Biglietto"},"price_min":8.0,"currency":"EUR","booking_required":false,"booking_phone":"049 1",
        "ticket_tiers":[{"id":4,"name":"Intero","price":10.0,"status":"available"}],"content_details":{"stroller":"yes","introduction":"<p>Ciao</p>"},
        "facts":[{"label":"Durata","value":"2 ore"}],"external_links":[],"seo":{"title":"T","description":null,"image":null,"indexing":"excluded"},
        "is_demo":false,"source":{"value":"manual","label":"Manuale"},"verification_status":"editorial_checked","editorial_score":40,
        "is_featured":true,"featured_until":"2026-12-24T22:59:00+00:00","rejection_reason":null,
        "recurrences":[{"id":6,"rrule":"FREQ=WEEKLY","until":"2027-01-31T22:59:00+00:00","exdates":["2026-12-26"]}],
        "date_refs":[{"id":70,"starts_at":"2026-12-05T20:00:00+00:00","ticketing_path":"gestione-biglietti/70"}],
        "abilities":{"update":true,"feature":true,"admin":true,"schedule":true,"generate":true,"dates":true}}"""

    @Test
    fun `la scheda del server diventa il modulo con i campi della redazione e quelli comuni`() {
        val (draft, values) = EditorialEventDraft.from(Json.parseToJsonElement(event).jsonObject)
        assertEquals("Rassegna", draft.base.title)
        assertEquals(NamedRef(3, "Circolo Aurora"), draft.base.venue)
        assertEquals(1L, draft.base.cityId)
        assertEquals("yes", draft.base.stroller)
        assertEquals(listOf(NamedRef(9, "Pro loco")), draft.coOrganizers)
        assertEquals("excluded", draft.seoIndexing)
        assertEquals("editorial_checked", draft.verificationStatus)
        // Le 23:59 di Padova d'inverno.
        assertEquals(LocalDateTime.of(2026, 12, 24, 23, 59), draft.featuredUntil)
        assertEquals("2026-12-26", draft.recurrences.single().exdates)
        assertEquals("gestione-biglietti/70", values.dateRefs.single().ticketingPath)
        assertTrue(values.abilities.generate)
    }

    @Test
    fun `il corpo manda le date nell'ora della città e toglie i campi che il pannello nasconde`() {
        val draft = EditorialEventDraft(
            base = it.fabiodalez.incitta.ui.management.events.EventForm(title = " Rassegna ", priceType = "free", cityId = 1, venue = NamedRef(3, "Aurora"), locationAddress = "Via Roma"),
            bookingRequired = false,
            bookingPhone = "049",
            isFeatured = true,
            featuredUntil = LocalDateTime.of(2026, 12, 24, 23, 0),
            seoIndexing = "excluded",
            newDates = listOf(NewDateRow(startsAt = LocalDateTime.of(2026, 12, 5, 21, 0), endsAt = LocalDateTime.of(2026, 7, 5, 23, 0))),
            recurrences = listOf(RecurrenceRow(rrule = "FREQ=WEEKLY", exdates = "2026-12-26\n\n 2027-01-02 ")),
        )
        val body = draft.toJson(rome, creating = true, can = EditorCan(feature = false, admin = false))
        assertEquals("Rassegna", body["title"]!!.jsonPrimitive.content)
        assertEquals(1L, body["city_id"]!!.jsonPrimitive.content.toLong())
        assertEquals(3L, body["venue_id"]!!.jsonPrimitive.content.toLong())
        val first = body["occurrences"]!!.jsonArray.single().jsonObject
        assertEquals("2026-12-05T21:00:00+01:00", first["starts_at"]!!.jsonPrimitive.content)
        assertEquals("2026-07-05T23:00:00+02:00", first["ends_at"]!!.jsonPrimitive.content)
        assertEquals(listOf("2026-12-26", "2027-01-02"), body["recurrences"]!!.jsonArray.single().jsonObject["exdates"]!!.jsonArray.map { it.jsonPrimitive.content })
        // Nascosti nel pannello: telefono senza prenotazione, valuta e listino senza biglietto, luogo libero con un locale.
        assertFalse(body.containsKey("booking_phone"))
        assertFalse(body.containsKey("booking_url"))
        assertFalse(body.containsKey("currency"))
        assertFalse(body.containsKey("ticket_tiers"))
        assertFalse(body.containsKey("custom_location"))
        // Riservati: evidenza, indicizzazione e dimostrativo solo a chi li può scrivere.
        assertFalse(body.containsKey("is_featured"))
        assertFalse(body.containsKey("is_demo"))
        assertFalse(body["seo"]!!.jsonObject.containsKey("indexing"))

        val admin = draft.copy(bookingRequired = true, base = draft.base.copy(priceType = "ticket", venue = null)).toJson(rome, creating = false, can = EditorCan(feature = true, admin = true))
        assertEquals("049", admin["booking_phone"]!!.jsonPrimitive.content)
        assertEquals("EUR", admin["currency"]!!.jsonPrimitive.content)
        assertTrue(admin["ticket_tiers"] is JsonArray)
        assertTrue(admin["custom_location"] is JsonObject)
        assertEquals("2026-12-24T23:00:00+01:00", admin["featured_until"]!!.jsonPrimitive.content)
        assertEquals("excluded", admin["seo"]!!.jsonObject["indexing"]!!.jsonPrimitive.content)
        assertFalse(admin.containsKey("occurrences"))
    }

    @Test
    fun `un errore del server apre la scheda del campo sbagliato`() {
        assertEquals(EditorTab.What, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("title" to "x"))))
        assertEquals(EditorTab.When, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("occurrences" to "x"))))
        assertEquals(EditorTab.Where, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("venue_id" to "x"))))
        assertEquals(EditorTab.Tickets, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("currency" to "x"))))
        assertEquals(EditorTab.BeforeGoing, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("content_details.stroller" to "x"))))
        assertEquals(EditorTab.Content, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("content_details.online_url" to "x"))))
        assertEquals(EditorTab.Extra, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("facts" to "x"))))
        assertEquals(EditorTab.Publish, EventEditorViewModel.tabFor(FormErrors(fields = mapOf("editorial_score" to "x"))))
        assertNull(EventEditorViewModel.tabFor(FormErrors.None))
    }

    @Test
    fun `crea l'evento nella città scelta e poi resta sulla sua modifica`() {
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.startsWith("editorial/events/form") -> 200 to """{"data":{"options":$options,"event":null}}"""
                method == "POST" && path.startsWith("editorial/events?") -> 201 to """{"data":$event,"message":"Salvato"}"""
                else -> 404 to "{}"
            }
        }
        val saved = SavedStateHandle()
        val viewModel = EventEditorViewModel(repository(server), saved)
        eventually { viewModel.uiState.value.options != null }
        assertTrue(viewModel.uiState.value.creating)
        assertEquals(1L, viewModel.uiState.value.draft.base.cityId)
        assertEquals("unknown", viewModel.uiState.value.draft.base.priceType)

        viewModel.editBase { it.copy(title = "Rassegna", categoryId = 2) }
        viewModel.edit { it.copy(newDates = listOf(NewDateRow(startsAt = LocalDateTime.of(2026, 12, 5, 21, 0)))) }
        viewModel.save()
        eventually { !viewModel.uiState.value.creating }
        assertEquals("GET editorial/events/form?city=padova", server.calls.first())
        assertTrue(server.calls.contains("POST editorial/events?city=padova"))
        val body = Json.parseToJsonElement(server.bodies.last()).jsonObject
        assertEquals("2026-12-05T21:00:00+01:00", body["occurrences"]!!.jsonArray.single().jsonObject["starts_at"]!!.jsonPrimitive.content)
        assertEquals(41L, viewModel.eventId)
        assertEquals(41L, saved.get<Long>(EventEditorViewModel.KEY_ID))
        assertEquals("Salvato", viewModel.uiState.value.message)
    }

    @Test
    fun `gli errori del server vanno sotto i campi e la scheda si apre su quello sbagliato`() {
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.startsWith("editorial/events/41/form") -> 200 to """{"data":{"options":$options,"event":$event}}"""
                method == "PATCH" -> 422 to """{"error":{"code":"VALIDATION_FAILED","message":"x","fields":{"currency":["Il campo valuta è obbligatorio."]}}}"""
                else -> 404 to "{}"
            }
        }
        val viewModel = EventEditorViewModel(repository(server), SavedStateHandle(mapOf("id" to 41L)))
        eventually { viewModel.uiState.value.values != null }
        viewModel.save()
        eventually { viewModel.uiState.value.errors.fields.isNotEmpty() }
        assertEquals(EditorTab.Tickets, viewModel.uiState.value.tab)
        assertEquals("Il campo valuta è obbligatorio.", viewModel.uiState.value.errors.field("currency"))
        assertTrue(server.calls.any { it.startsWith("PATCH editorial/events/41") })
    }

    @Test
    fun `cambiare locale chiede al server di ricalcolare «Prima di andare»`() {
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.startsWith("editorial/events/41/form") -> 200 to """{"data":{"options":$options,"event":$event}}"""
                method == "POST" && path.startsWith("editorial/events/before-going") -> 200 to """{"data":{"content_details":{"stroller":null,"kids_area":"yes"}}}"""
                else -> 404 to "{}"
            }
        }
        val viewModel = EventEditorViewModel(repository(server), SavedStateHandle(mapOf("id" to 41L)))
        eventually { viewModel.uiState.value.values != null }
        viewModel.selectVenue(NamedRef(7, "Teatro"))
        eventually { viewModel.uiState.value.draft.base.kidsArea == "yes" }
        val body = Json.parseToJsonElement(server.bodies.last()).jsonObject
        assertEquals(3L, body["previous_venue_id"]!!.jsonPrimitive.content.toLong())
        assertEquals(7L, body["venue_id"]!!.jsonPrimitive.content.toLong())
        assertEquals("", viewModel.uiState.value.draft.base.stroller)
    }

    @Test
    fun `il modulo di una data va e torna con orari, listino e lineup`() {
        val date = Json { ignoreUnknownKeys = true }.decodeFromString(EditorDate.serializer(), """{"id":70,"starts_at":"2026-12-05T20:00:00+00:00","timezone":"Europe/Rome",
            "status":{"value":"sold_out","label":"Esaurito"},"capacity":180,"practical_details":{"accessibility":"yes"},"cost_breakdown":{"admission":12.5},
            "ticket_tiers":[{"id":1,"name":"Platea","price":15.0,"status":"available"}],"lineup":[{"id":2,"name":"Trio","role":"live","starts_at":"2026-12-05T20:30:00+00:00"}]}""")
        val draft = EditorialDateDraft.from(date)
        assertEquals(LocalDateTime.of(2026, 12, 5, 21, 0), draft.startsAt)
        assertEquals("12.5", draft.costs["admission"])
        assertEquals("15", draft.tiers.single().price)
        val body = draft.copy(highlight = " Ultimi posti ").toJson(rome)
        assertEquals("2026-12-05T21:00:00+01:00", body["starts_at"]!!.jsonPrimitive.content)
        assertEquals("Ultimi posti", body["highlight"]!!.jsonPrimitive.content)
        assertEquals(180L, body["capacity"]!!.jsonPrimitive.content.toLong())
        assertEquals(JsonNull, body["ends_at"])
        assertEquals("2026-12-05T21:30:00+01:00", body["lineup"]!!.jsonArray.single().jsonObject["starts_at"]!!.jsonPrimitive.content)
        assertEquals(12.5, body["cost_breakdown"]!!.jsonObject["admission"]!!.jsonPrimitive.content.toDouble(), 0.0)
    }

    @Test
    fun `i gesti sulle date chiamano le rotte del pannello e l'elenco si rilegge`() {
        val list = """{"data":[{"id":70,"starts_at":"2026-12-05T20:00:00+00:00","series":true,"status":{"value":"scheduled","label":"In programma"},
            "abilities":{"update":true,"shift":true,"cancel":true,"sold_out":true,"destroy":true}}],
            "meta":{"current_page":1,"last_page":1,"total":1,"event":{"id":41,"title":"Rassegna","city":"Padova","timezone":"Europe/Rome"},
            "abilities":{"create":true},"choices":{"scopes":[{"value":"single","label":"Solo questa"},{"value":"series","label":"Tutta la serie"}]}}}"""
        val one = """{"data":{"id":70,"status":{"value":"sold_out","label":"Esaurito"}},"meta":{"changed":1},"message":"1 data aggiornata"}"""
        val server = Server { method, path, _ ->
            when {
                method == "GET" && path.startsWith("editorial/events/41/dates") -> 200 to list
                method == "POST" && path.contains("/dates/70/") -> 200 to one
                method == "DELETE" -> 204 to ""
                else -> 404 to "{}"
            }
        }
        val viewModel = EventDatesViewModel(repository(server), SavedStateHandle(mapOf(EventDatesViewModel.KEY_EVENT to 41L)))
        eventually { viewModel.uiState.value.dates is Loadable.Ready }
        assertEquals(2, viewModel.uiState.value.meta.choices.scopes.size)
        val date = (viewModel.uiState.value.dates as Loadable.Ready).value.single()

        viewModel.shift(date, 30, "series")
        eventually { viewModel.uiState.value.message == "1 data aggiornata" }
        val shift = Json.parseToJsonElement(server.bodies.last()).jsonObject
        assertEquals(30, shift["shift_minutes"]!!.jsonPrimitive.content.toInt())
        assertEquals("series", shift["scope"]!!.jsonPrimitive.content)

        viewModel.toggleSoldOut(date)
        eventually { server.bodies.last().contains("sold_out") }
        viewModel.cancel(date, "<p>Festa</p>", "single")
        eventually { server.bodies.last().contains("cancel") }
        assertEquals("<p>Festa</p>", Json.parseToJsonElement(server.bodies.last()).jsonObject["status_note"]!!.jsonPrimitive.content)

        viewModel.delete(date)
        eventually { viewModel.uiState.value.dates is Loadable.Empty }
        assertTrue(server.calls.contains("DELETE editorial/events/41/dates/70?city=padova"))
        assertTrue(server.calls.count { it.startsWith("GET editorial/events/41/dates") } >= 3)
    }
}
