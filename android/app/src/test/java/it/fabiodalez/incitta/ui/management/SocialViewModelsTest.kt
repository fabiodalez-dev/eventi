package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoice
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSocialSource
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.SocialBatch
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCalendarMeta
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialCatalogMeta
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialDayMeta
import it.fabiodalez.incitta.data.management.SocialEventRef
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.data.management.socialCatalogPath
import it.fabiodalez.incitta.data.management.socialFileName
import it.fabiodalez.incitta.data.management.socialPrepareBody
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.social.SocialStudioViewModel
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/*
 * P10 con un server finto: lo studio si apre sul giorno che dice il server con
 * tutte le sue serate, una card di un altro giorno sposta il giorno e resta
 * sola, una dello stesso giorno entra o esce, il titolo alternativo solo con
 * una serata, il filtro dell'evento.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class SocialViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun card(id: Long, day: String, selectable: Boolean = true) =
        SocialCard(id = id, eventId = id * 10, title = "Serata $id", startsAt = "${day}T19:00:00+00:00", timezone = "Europe/Rome", businessDate = day, selectable = selectable)

    private class FakeSocial : ManagementSocialSource {
        val calls = mutableListOf<String>()
        val days = mapOf(
            "2026-09-11" to listOf(SocialCard(id = 1, eventId = 10, title = "Uno", businessDate = "2026-09-11", selectable = true), SocialCard(id = 2, eventId = 20, title = "Due", businessDate = "2026-09-11", selectable = true)),
            "2026-09-20" to listOf(SocialCard(id = 3, eventId = 30, title = "Tre", businessDate = "2026-09-20", selectable = true)),
        )
        var prepareFailure: Exception? = null
        var prepared: Triple<String, List<Long>, SocialOptions>? = null
        override suspend fun catalog(venueId: Long, query: SocialCatalogQuery): SocialCatalogPage {
            calls += "catalog:${query.period}:${query.from}:${query.until}:${query.search}:${query.limit}:${query.eventId}"
            val all = days.values.flatten()
            return SocialCatalogPage(all, SocialCatalogMeta(period = query.period, from = query.from.ifBlank { "2026-09-10" }, until = query.until.ifBlank { "2026-10-09" }, total = all.size, limit = query.limit))
        }
        override suspend fun calendar(venueId: Long, month: String, date: String, eventId: Long?, search: String): SocialCalendar {
            calls += "calendar:$month:$date"
            return SocialCalendar(meta = SocialCalendarMeta(month = month, label = month))
        }
        override suspend fun day(venueId: Long, date: String?, eventId: Long?): SocialDay {
            calls += "day:$date:$eventId"
            val chosen = date ?: if (eventId != null) "2026-09-20" else "2026-09-11"
            return SocialDay(days[chosen].orEmpty(), SocialDayMeta(date = chosen, event = eventId?.let { SocialEventRef(it, "Evento $it") }))
        }
        override suspend fun events(venueId: Long, search: String) = EventChoiceGroups(upcoming = listOf(EventChoice(id = 30, title = "Tre")))
        override suspend fun recent(venueId: Long): List<SocialBatchSummary> { calls += "recent"; return emptyList() }
        override suspend fun batch(venueId: Long, id: String) = SocialBatch(id = id)
        override suspend fun prepare(venueId: Long, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): SocialBatch {
            prepareFailure?.let { throw it }
            prepared = Triple(date, occurrences, options)
            return SocialBatch(id = "pacchetto-1", date = date, items = occurrences.mapIndexed { i, id -> SocialBatchItem(index = i, title = "Serata $id") })
        }
        override suspend fun image(venueId: Long, batchId: String, item: SocialBatchItem, directory: File) = DownloadedFile(File(directory, "x.jpg"), "image/jpeg")
        override suspend fun zip(venueId: Long, batch: SocialBatch, directory: File) = DownloadedFile(File(directory, "x.zip"), "application/zip")
        override suspend fun preview(subject: ManagementSubject, eventId: Long, dateId: Long, directory: File) = DownloadedFile(File(directory, "p.jpg"), "image/jpeg")
    }

    private fun repository(scope: CoroutineScope, social: FakeSocial, socialUse: Boolean = true): ManagementRepository {
        val identity = FakeIdentity(list = ManagementSubjects(
            venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "editor", permissions = mapOf("social_use" to socialUse))),
            organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo", name = "Collettivo", role = "owner")),
        ))
        return ManagementRepository(7, "token", FakeTicketing(), identity, null, MemoryDevice(), scope, social = social)
    }

    private fun viewModel(social: FakeSocial, saved: SavedStateHandle = SavedStateHandle(), socialUse: Boolean = true) =
        SocialStudioViewModel(repository(TestScope(dispatcher), social, socialUse), saved, searchDelayMillis = 0)

    @Test fun opensOnTheDayTheServerChoosesWithAllItsEvenings() = runTest(dispatcher) {
        val social = FakeSocial()
        val viewModel = viewModel(social)
        val state = viewModel.uiState.value
        assertEquals("2026-09-11", state.date)
        assertEquals(listOf(1L, 2L), state.selected)
        assertEquals("2026-09", state.month)
        assertTrue(social.calls.contains("day:null:null"))
        assertTrue(social.calls.contains("calendar:2026-09:2026-09-11"))
        assertTrue(social.calls.any { it.startsWith("catalog:next30") })
    }

    @Test fun aCardOfAnotherDayMovesTheDayAndStaysAloneOneOfTheSameDayToggles() = runTest(dispatcher) {
        val social = FakeSocial()
        val viewModel = viewModel(social)
        viewModel.pick(card(2, "2026-09-11"))
        assertEquals(listOf(1L), viewModel.uiState.value.selected)
        viewModel.pick(card(3, "2026-09-20"))
        assertEquals("2026-09-20", viewModel.uiState.value.date)
        assertEquals(listOf(3L), viewModel.uiState.value.selected)
        // Una serata che non si può preparare non cambia niente.
        viewModel.pick(card(9, "2026-09-25", selectable = false))
        assertEquals("2026-09-20", viewModel.uiState.value.date)
    }

    @Test fun aDayOfTheCalendarBecomesThePeriodAndSelectsAllItsEvenings() = runTest(dispatcher) {
        val social = FakeSocial()
        val viewModel = viewModel(social)
        viewModel.pickDay("2026-09-11")
        val state = viewModel.uiState.value
        assertEquals("custom", state.period)
        assertEquals("2026-09-11", state.from)
        assertEquals("2026-09-11", state.until)
        assertEquals(listOf(1L, 2L), state.selected)
        assertTrue(social.calls.contains("catalog:custom:2026-09-11:2026-09-11::24:null"))
    }

    @Test fun theAlternativeTitleNeedsASingleEveningAndPreparesTheChosenOnes() = runTest(dispatcher) {
        val social = FakeSocial()
        val viewModel = viewModel(social)
        viewModel.setOptions(SocialOptions(format = SocialFormatChoice.Story, title = "Stasera"))
        viewModel.generate()
        assertNull(social.prepared)
        assertEquals(SocialStudioViewModel.TITLE_SINGLE, viewModel.uiState.value.errors.field("title"))

        viewModel.toggle(2)
        viewModel.generate()
        assertEquals(Triple("2026-09-11", listOf(1L), SocialOptions(format = SocialFormatChoice.Story, title = "Stasera")), social.prepared)
        assertEquals("pacchetto-1", viewModel.uiState.value.prepared)
        viewModel.consumePrepared()
        assertNull(viewModel.uiState.value.prepared)
    }

    @Test fun theServerErrorsGoUnderTheTitleOrOnTop() = runTest(dispatcher) {
        val social = FakeSocial()
        social.prepareFailure = ApiException(422, ApiProblem(code = "validation_failed", message = "Dati non validi", fields = mapOf("generate" to listOf("Un testo è troppo lungo per una grafica leggibile."))))
        val viewModel = viewModel(social)
        viewModel.generate()
        assertEquals("Un testo è troppo lungo per una grafica leggibile.", viewModel.uiState.value.errors.general)
        assertFalse(viewModel.uiState.value.generating)
    }

    @Test fun anEventNarrowsTheStudioAndClearingItKeepsTheDay() = runTest(dispatcher) {
        val social = FakeSocial()
        val viewModel = viewModel(social)
        viewModel.setEvent(EventChoice(id = 30, title = "Tre"))
        assertEquals(SocialEventRef(30, "Evento 30"), viewModel.uiState.value.event)
        assertEquals("2026-09-20", viewModel.uiState.value.date)
        assertTrue(social.calls.contains("day:null:30"))
        viewModel.setOptions(viewModel.uiState.value.options.copy(title = "Solo questa"))
        viewModel.clearEvent()
        assertNull(viewModel.uiState.value.event)
        assertEquals("", viewModel.uiState.value.options.title)
        assertEquals("2026-09-20", viewModel.uiState.value.date)
    }

    @Test fun selectionAndOptionsSurviveTheProcess() = runTest(dispatcher) {
        val social = FakeSocial()
        val saved = SavedStateHandle()
        val first = viewModel(social, saved)
        first.toggle(1)
        first.setOptions(SocialOptions(monochrome = false, cover = true))
        val restored = viewModel(social, saved)
        assertEquals(listOf(2L), restored.uiState.value.selected)
        assertFalse(restored.uiState.value.options.monochrome)
        assertTrue(restored.uiState.value.options.cover)
    }

    @Test fun onlyAVenueWithTheStudioOpensIt() = runTest(dispatcher) {
        val viewModel = viewModel(FakeSocial(), SavedStateHandle(mapOf("subject" to "organizer:3")))
        assertEquals(Loadable.Empty, viewModel.uiState.value.venue)
        assertEquals(Loadable.Empty, viewModel(FakeSocial(), socialUse = false).uiState.value.venue)
    }

    @Test fun pathsAndBodyFollowTheServer() {
        assertEquals("management/venues/12/social/catalog?period=next30", socialCatalogPath(12, SocialCatalogQuery()))
        assertEquals(
            "management/venues/12/social/catalog?period=custom&from=2026-09-01&until=2026-09-30&search=jazz%20live&limit=48&event=5",
            socialCatalogPath(12, SocialCatalogQuery("custom", "2026-09-01", "2026-09-30", " jazz live ", 48, 5)),
        )
        val body = socialPrepareBody("2026-09-11", listOf(1, 2), SocialOptions(cover = true, title = "  "), null)
        assertEquals("cover", body["image_fit"]!!.jsonPrimitive.content)
        assertEquals("portrait", body["format"]!!.jsonPrimitive.content)
        assertEquals(2, body["occurrences"]!!.jsonArray.size)
        assertNull(body["title"])
        assertNull(body["event"])
        assertEquals("01-serata-jazz.jpg", socialFileName("01-serata-jazz.jpg", "jpg"))
        assertEquals("grafica.jpg", socialFileName("../", "jpg"))
    }
}
