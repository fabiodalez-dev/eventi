package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorDate
import it.fabiodalez.incitta.data.editorial.EditorDateEnvelope
import it.fabiodalez.incitta.data.editorial.EditorDateResult
import it.fabiodalez.incitta.data.editorial.EditorDatesPage
import it.fabiodalez.incitta.data.editorial.EditorEventResult
import it.fabiodalez.incitta.data.editorial.EditorFormEnvelope
import it.fabiodalez.incitta.data.editorial.EditorImages
import it.fabiodalez.incitta.data.editorial.EditorTagResults
import it.fabiodalez.incitta.data.editorial.EditorVenueChoice
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDeskSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialUnlockState
import it.fabiodalez.incitta.data.editorial.EventEditorSource
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.eventeditor.EditorLinks
import it.fabiodalez.incitta.ui.editorial.eventeditor.EventDatesScreen
import it.fabiodalez.incitta.ui.editorial.eventeditor.EventDatesViewModel
import it.fabiodalez.incitta.ui.editorial.eventeditor.EventEditorScreen
import it.fabiodalez.incitta.ui.editorial.eventeditor.EventEditorViewModel
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R3 — le schermate principali del modulo evento e delle date su
 * un'attività vuota, con un server finto: le otto schede, il salvataggio,
 * l'elenco delle date con il menu dei gesti, la scelta «solo questa / tutta
 * la serie» per una data di una ricorrenza, «Gestisci biglietti».
 */
class EditorialEventEditorUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private class Desk : EditorialDeskSource {
        override suspend fun enabled() = true
        override suspend fun desk(city: String?) = EditorialDesk(role = "admin", unlock = EditorialUnlockState(unlocked = true))
        override suspend fun options() = ManagementOptions()
        override suspend fun unlock(password: String) = EditorialUnlockState(unlocked = true)
    }

    private val json = Json { ignoreUnknownKeys = true }

    private val options = """{"categories":[{"id":2,"name":"Musica dal vivo"}],"cities":[{"id":1,"name":"Padova","timezone":"Europe/Rome"}],
        "statuses":[{"value":"draft","label":"Bozza"},{"value":"published","label":"Pubblicato"}],
        "sources":[{"value":"manual","label":"Inserimento manuale"}],"verification_statuses":[{"value":"unverified","label":"Non verificato"}],
        "price_types":[{"value":"free","label":"Ingresso libero"},{"value":"ticket","label":"Biglietto"}],
        "defaults":{"status":"draft","city_id":1,"price_type":"free","currency":"EUR","source":"manual","verification_status":"unverified","editorial_score":0},
        "can":{"feature":true,"admin":true}}"""

    private val event = """{"id":41,"slug":"rassegna","status":{"value":"draft","label":"Bozza"},"title":"Rassegna d'autunno",
        "category":{"id":2,"name":"Musica dal vivo"},"city":{"id":1,"name":"Padova","timezone":"Europe/Rome"},"venue":{"id":3,"name":"Circolo Aurora"},
        "price_type":{"value":"free","label":"Ingresso libero"},"source":{"value":"manual","label":"Inserimento manuale"},"verification_status":"unverified",
        "date_refs":[{"id":70,"starts_at":"2026-12-05T20:00:00+00:00","ticketing_path":"gestione-biglietti/70"}],
        "abilities":{"update":true,"feature":true,"admin":true,"schedule":true,"dates":true}}"""

    private val dateRow = """{"id":70,"starts_at":"2026-12-05T20:00:00+00:00","timezone":"Europe/Rome","series":true,
        "status":{"value":"scheduled","label":"In programma"},"venue":{"id":3,"name":"Circolo Aurora"},"ticketing_path":"gestione-biglietti/70",
        "abilities":{"update":true,"shift":true,"cancel":true,"sold_out":true,"destroy":true,"ticketing":true}}"""

    /** Un server finto che risponde alle chiamate del modulo e ricorda quelle fatte. */
    private inner class Source : EventEditorSource {
        val calls = CopyOnWriteArrayList<String>()
        val bodies = CopyOnWriteArrayList<JsonObject>()
        private fun form(withEvent: Boolean) = EditorFormEnvelope(json.decodeFromString(it.fabiodalez.incitta.data.editorial.EditorEventOptions.serializer(), options),
            if (withEvent) Json.parseToJsonElement(event).jsonObject else null)
        override suspend fun newForm() = form(false).also { calls += "newForm" }
        override suspend fun editForm(id: Long) = form(true).also { calls += "editForm:$id" }
        override suspend fun create(body: JsonObject) = EditorEventResult(Json.parseToJsonElement(event).jsonObject, "Salvato").also { calls += "create"; bodies += body }
        override suspend fun update(id: Long, body: JsonObject) = EditorEventResult(Json.parseToJsonElement(event).jsonObject, "Salvato").also { calls += "update:$id"; bodies += body }
        override suspend fun rebaseBeforeGoing(previousVenueId: Long?, venueId: Long?, details: JsonObject) = details
        override suspend fun venues(query: String, cityId: Long?, approved: Boolean) = listOf(EditorVenueChoice(3, "Circolo Aurora"))
        override suspend fun organizers(query: String) = listOf(NamedRef(8, "Rete delle Arti"))
        override suspend fun tags(query: String) = EditorTagResults()
        override suspend fun createTag(name: String, eventId: Long?) = NamedRef(5, name)
        override suspend fun uploadPoster(id: Long, image: PreparedImage) = EditorImages()
        override suspend fun removePoster(id: Long) = EditorImages()
        override suspend fun addGalleryPhoto(id: Long, image: PreparedImage) = EditorImages()
        override suspend fun removeGalleryPhoto(id: Long, mediaId: Long) = EditorImages()
        override suspend fun orderGallery(id: Long, ids: List<Long>) = EditorImages()
        override suspend fun schedule(id: Long, publishAt: String) = update(id, JsonObject(emptyMap()))
        override suspend fun unschedule(id: Long) = update(id, JsonObject(emptyMap()))
        override suspend fun generate(id: Long) = update(id, JsonObject(emptyMap()))
        override suspend fun dates(eventId: Long, page: Int) = json.decodeFromString(EditorDatesPage.serializer(), """{"data":[$dateRow],
            "meta":{"current_page":1,"last_page":1,"total":1,"event":{"id":41,"title":"Rassegna d'autunno","city":"Padova","timezone":"Europe/Rome"},
            "abilities":{"create":true},"choices":{"scopes":[{"value":"single","label":"Solo questa data","help":"Le altre restano"},{"value":"series","label":"Tutta la serie","help":"Da qui in avanti"}]}}}""")
            .also { calls += "dates:$eventId" }
        override suspend fun date(eventId: Long, dateId: Long) = EditorDateEnvelope(json.decodeFromString(EditorDate.serializer(), dateRow))
        override suspend fun createDate(eventId: Long, body: JsonObject) = EditorDateResult(json.decodeFromString(EditorDate.serializer(), dateRow))
        override suspend fun updateDate(eventId: Long, dateId: Long, body: JsonObject) = createDate(eventId, body)
        override suspend fun shiftDate(eventId: Long, dateId: Long, minutes: Int, scope: String) =
            EditorDateResult(json.decodeFromString(EditorDate.serializer(), dateRow), "1 data aggiornata").also { calls += "shift:$dateId:$minutes:$scope" }
        override suspend fun cancelDate(eventId: Long, dateId: Long, note: String?, scope: String) = shiftDate(eventId, dateId, 0, scope)
        override suspend fun toggleSoldOut(eventId: Long, dateId: Long) = shiftDate(eventId, dateId, 0, "single")
        override suspend fun deleteDate(eventId: Long, dateId: Long) { calls += "delete:$dateId" }
    }

    private fun repository() = EditorialRepository(1, "t", Desk(), EditorialLocalLock(clock = { 0L }))

    @Test fun theEditorShowsTheEightTabsAndSavesTheWholeForm() {
        val source = Source()
        val opened = CopyOnWriteArrayList<String>()
        val viewModel = EventEditorViewModel(repository(), SavedStateHandle(mapOf("id" to 41L)), source)
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme { Surface { EventEditorScreen(viewModel, PaddingValues(), onBack = {}, onCreated = {}, links = EditorLinks(onDates = { opened += "dates:$it" }, onOpenInApp = { opened += it })) } }
            }
        }
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Rassegna d'autunno").fetchSemanticsNodes().isNotEmpty() }
        listOf(R.string.editorial_ee_tab_what, R.string.editorial_ee_tab_when, R.string.editorial_ee_tab_where, R.string.editorial_ee_tab_tickets,
            R.string.editorial_ee_tab_publish).forEach { compose.onNodeWithText(text(it)).assertExists() }

        compose.onNodeWithText(text(R.string.editorial_ee_open_dates)).performClick()
        compose.runOnIdle { assertEquals(listOf("dates:41"), opened.toList()) }

        // «Biglietti»: il collegamento alla biglietteria della data si apre nell'app.
        compose.onNodeWithTag("editor-tab-Tickets").performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_ee_ticketing), substring = true).assertExists()
        compose.onNode(hasText(text(R.string.editorial_dates_tickets), substring = true)).performScrollTo().performClick()
        compose.runOnIdle { assertTrue(opened.contains("gestione-biglietti/70")) }

        // «Pubblicazione»: provenienza, verifica e «Programma pubblicazione».
        compose.onNodeWithTag("editor-tab-Publish").performScrollTo().performClick()
        // La scheda è un elenco che disegna solo ciò che è a schermo: si scende fino al pulsante.
        listOf(R.string.editorial_ee_verification, R.string.editorial_ee_schedule).forEach { id ->
            compose.onAllNodes(hasScrollToNodeAction()).fetchSemanticsNodes().indices.forEach { index ->
                runCatching { compose.onAllNodes(hasScrollToNodeAction())[index].performScrollToNode(hasText(text(id))) }
            }
            compose.onNodeWithText(text(id), useUnmergedTree = true).assertExists()
        }

        compose.onNodeWithTag("editor-save").performScrollTo().performClick()
        compose.waitUntil(5_000) { source.calls.contains("update:41") }
        assertEquals("Rassegna d'autunno", source.bodies.last()["title"].toString().trim('"'))
    }

    @Test fun theDatesListAsksSingleOrSeriesBeforeShifting() {
        val source = Source()
        val tickets = CopyOnWriteArrayList<String>()
        val viewModel = EventDatesViewModel(repository(), SavedStateHandle(mapOf(EventDatesViewModel.KEY_EVENT to 41L)), source)
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme { Surface { EventDatesScreen(viewModel, PaddingValues(), onBack = {}, onAdd = {}, onEdit = {}, onOpenInApp = { tickets += it }) } }
            }
        }
        compose.waitUntil(5_000) { compose.onAllNodesWithTag("date-row-70").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_dates_add)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_dates_series)).assertExists()

        compose.onNodeWithText(text(R.string.editorial_dates_tickets)).performClick()
        compose.runOnIdle { assertEquals(listOf("gestione-biglietti/70"), tickets.toList()) }

        compose.onNode(hasContentDescription(text(R.string.editorial_actions_for, ""), substring = true)).performClick()
        compose.onNodeWithText(text(R.string.editorial_dates_shift)).performClick()
        compose.onNodeWithText("Tutta la serie").assertExists().performClick()
        compose.onNodeWithText(text(R.string.editorial_dates_shift_minutes)).performTextInput("30")
        compose.onNodeWithTag("gesture-confirm").performClick()
        compose.waitUntil(5_000) { source.calls.contains("shift:70:30:series") }
    }
}
