package it.fabiodalez.incitta.ui.editorial.analytics

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.AnalyticsProfileChoice
import it.fabiodalez.incitta.data.editorial.EditorialAnalyticsSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialReportQuery
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.SearchMissChoices
import it.fabiodalez.incitta.data.editorial.SearchMissPage
import it.fabiodalez.incitta.data.editorial.SearchMissRemoved
import it.fabiodalez.incitta.data.editorial.SearchMissRow
import it.fabiodalez.incitta.data.editorial.SeoAuditRow
import it.fabiodalez.incitta.data.editorial.SeoCity
import it.fabiodalez.incitta.data.editorial.SeoMessage
import it.fabiodalez.incitta.data.editorial.SeoPage
import it.fabiodalez.incitta.data.editorial.SeoSearchConsole
import it.fabiodalez.incitta.data.editorial.SeoVerificationResult
import it.fabiodalez.incitta.data.management.AnalyticsOption
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R10 con un server finto: le voci per ruolo, i filtri del rapporto, il modulo di Search Console, le ricerche da eliminare. */
@OptIn(ExperimentalCoroutinesApi::class)
class AnalyticsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeAnalytics : EditorialAnalyticsSource {
        val calls = mutableListOf<String>()
        var failNext: Exception? = null
        var misses = listOf(
            SearchMissRow(id = 1, term = "burraco", count = 4, abilities = buildJsonObject { put("remove", JsonPrimitive(true)) }),
            SearchMissRow(id = 2, term = "karaoke", count = 9, abilities = buildJsonObject { put("remove", JsonPrimitive(true)) }),
        )
        val cities = listOf(SeoCity(1, "padova", "Padova", true, "vecchio"), SeoCity(2, "vicenza", "Vicenza", false, null))

        private fun maybeFail() { failNext?.let { failNext = null; throw it } }

        override suspend fun report(target: AnalyticsTarget?, query: EditorialReportQuery, city: String?): AnalyticsReport {
            calls += "report:${target?.type}:${target?.id}:" + query.params(target == null).joinToString("&") { "${it.first}=${it.second}" }
            maybeFail()
            return AnalyticsReport(period = query.period, until = "2026-09-10")
        }
        override suspend fun table(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, page: Int, city: String?): AnalyticsTable {
            calls += "table:$dataset:$page"
            return AnalyticsTable(dataset = dataset)
        }
        override suspend fun export(target: AnalyticsTarget?, query: EditorialReportQuery, dataset: String, format: String, city: String?, directory: File): DownloadedFile {
            calls += "export:$dataset:$format"
            return DownloadedFile(File(directory, "statistiche.$format"), "text/csv")
        }
        override suspend fun events(search: String, venue: Long?, organizer: Long?, exclude: Long?, city: String?): EventChoiceGroups {
            calls += "events:$search:$venue:$organizer:$exclude"
            return EventChoiceGroups()
        }
        override suspend fun profiles(type: String, search: String, city: String?): List<AnalyticsProfileChoice> {
            calls += "profiles:$type:$search"
            return listOf(AnalyticsProfileChoice(4, "Circolo Aurora"))
        }
        override suspend fun seo(): SeoPage { calls += "seo"; return SeoPage(searchConsole = SeoSearchConsole(defaultCityId = 1, cities = cities)) }
        override suspend fun seoAudit(params: List<Pair<String, String>>): EditorialPage<SeoAuditRow> {
            calls += "audit:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(listOf(SeoAuditRow(key = "a", title = "Concerto", issues = listOf("Manca la locandina."))), PageMeta(total = 1))
        }
        override suspend fun saveVerification(cityId: Long, code: String?): SeoVerificationResult {
            calls += "save:$cityId:$code"
            maybeFail()
            return SeoVerificationResult(cities.first { it.id == cityId }.copy(verification = code?.ifBlank { null }), SeoMessage("Codice di verifica salvato"))
        }
        override suspend fun searchMisses(params: List<Pair<String, String>>): SearchMissPage {
            calls += "misses:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return SearchMissPage(misses, PageMeta(total = misses.size), SearchMissChoices(listOf(AnalyticsOption("1", "Padova"), AnalyticsOption("2", "Vicenza"))))
        }
        override suspend fun removeSearchMiss(id: Long): SearchMissRemoved { calls += "remove:$id"; maybeFail(); return SearchMissRemoved(meta = SeoMessage("Eliminato")) }
        override suspend fun removeSearchMisses(ids: List<Long>): SearchMissRemoved { calls += "bulk:" + ids.sorted().joinToString(","); return SearchMissRemoved(meta = SeoMessage("Eliminati")) }
    }

    private val repository = EditorialRepository(1, "t", FakeDesk(), EditorialLocalLock(clock = { 0L }))

    @Test fun theModeratorSeesOnlyTheSearchMissesLikeInThePanel() {
        val moderator = EditorialPermissions(mapOf("analytics_view" to false, "search_misses_manage" to true))
        val admin = EditorialPermissions(mapOf("analytics_view" to true, "search_misses_manage" to true))
        assertEquals(listOf(AnalyticsSection.SearchMisses), analyticsSections(moderator))
        assertEquals(AnalyticsSection.entries, analyticsSections(admin))
        assertEquals(emptyList<AnalyticsSection>(), analyticsSections(EditorialPermissions()))
    }

    @Test fun theReportAppliesFiltersAndChangingPeriodForgetsTheDates() {
        val source = FakeAnalytics()
        val saved = SavedStateHandle()
        val viewModel = EditorialReportViewModel(repository, source, saved)
        assertTrue(viewModel.uiState.value.report is Loadable.Ready)
        assertEquals("report:null:null:period=month", source.calls.last())

        viewModel.apply(EditorialReportQuery(period = "custom", from = "2026-09-01", until = "2026-09-10", venue = 4, channel = "whatsapp"), FilterLabels(venue = "Circolo Aurora", event = "rimasto"))
        assertEquals("report:null:null:period=custom&from=2026-09-01&until=2026-09-10&venue=4&channel=whatsapp", source.calls.last())
        // Il nome di un filtro spento non resta.
        assertEquals(FilterLabels(venue = "Circolo Aurora"), viewModel.uiState.value.labels)
        assertEquals(4L, saved.get<Long>("venue"))

        viewModel.setPeriod("year")
        assertEquals("report:null:null:period=year&venue=4&channel=whatsapp", source.calls.last())
        viewModel.reset()
        assertEquals(EditorialReportQuery(), viewModel.uiState.value.query)
    }

    @Test fun theSubjectPageKeepsOnlyPeriodAndChannelAndExcludesItselfFromOtherEvents() = runBlocking {
        val source = FakeAnalytics()
        val saved = SavedStateHandle(mapOf("type" to "event", "id" to 9L, "venue" to 4L, "channel" to "telegram"))
        val viewModel = EditorialReportViewModel(repository, source, saved)
        assertEquals("report:event:9:period=month&channel=telegram", source.calls.last())
        viewModel.apply(EditorialReportQuery(venue = 4, organizer = 7, event = 3, channel = "whatsapp"), FilterLabels())
        assertEquals("report:event:9:period=month&channel=whatsapp", source.calls.last())
        viewModel.searchEvents("conc", null, null)
        assertEquals("events:conc:null:null:9", source.calls.last())
    }

    @Test fun aFailedReportShowsTheErrorAndTheExportGoesToTheCache() {
        val source = FakeAnalytics().apply { failNext = ApiException(500, ApiProblem(message = "Errore del server", code = "SERVER_ERROR")) }
        val viewModel = EditorialReportViewModel(repository, source, SavedStateHandle())
        assertTrue(viewModel.uiState.value.report is Loadable.Failed)
        viewModel.load()
        assertTrue(viewModel.uiState.value.report is Loadable.Ready)

        val table = EditorialTableViewModel(repository, source, SavedStateHandle(mapOf("dataset" to "links", "page" to 2)))
        assertEquals("table:links:2", source.calls.last())
        table.export("csv", File(System.getProperty("java.io.tmpdir"), "r10-test"))
        assertEquals("export:links:csv", source.calls.last())
        assertNotNull(table.uiState.value.export.file)
        assertEquals("events", EditorialTableViewModel(repository, source, SavedStateHandle(mapOf("dataset" to "persone"))).dataset)
    }

    @Test fun theSearchConsoleFormStartsFromThePanelCityAndTakesTheCodeOfTheChosenCity() {
        val source = FakeAnalytics()
        val viewModel = SeoViewModel(repository, source, SavedStateHandle())
        assertEquals(1L, viewModel.uiState.value.cityId)
        assertEquals("vecchio", viewModel.uiState.value.code)
        assertEquals("audit:page=1&per_page=20&tab=issues", source.calls.last())

        viewModel.selectCity(2)
        assertEquals("", viewModel.uiState.value.code)
        viewModel.setCode("<meta>")
        assertFalse(viewModel.uiState.value.canSave)
        viewModel.save()
        assertFalse(source.calls.any { it.startsWith("save") })

        viewModel.setCode("Google-test_token-123")
        viewModel.save()
        assertEquals("save:2:Google-test_token-123", source.calls.last())
        assertEquals(UiText.Raw("Codice di verifica salvato"), viewModel.uiState.value.message)
        val page = (viewModel.uiState.value.page as Loadable.Ready).value
        assertEquals("Google-test_token-123", page.searchConsole.cities.first { it.id == 2L }.verification)

        viewModel.selectTab("all")
        assertEquals("audit:page=1&per_page=20&tab=all", source.calls.last())
    }

    @Test fun aRejectedCodeShowsTheServerErrorUnderTheField() {
        val source = FakeAnalytics()
        val viewModel = SeoViewModel(repository, source, SavedStateHandle())
        source.failNext = ApiException(422, ApiProblem(message = "x", code = "VALIDATION_FAILED", fields = mapOf("verification" to listOf("Il formato non è valido."))))
        viewModel.setCode("abc")
        viewModel.save()
        assertEquals("Il formato non è valido.", viewModel.uiState.value.errors.field("verification"))
        assertFalse(viewModel.uiState.value.saving)
    }

    @Test fun searchMissesAreFilteredSortedAndRemovedOneOrManyWithoutPassword() {
        val source = FakeAnalytics()
        val saved = SavedStateHandle()
        val viewModel = SearchMissesViewModel(repository, source, saved)
        assertEquals("misses:page=1&per_page=20", source.calls.last())
        assertEquals(listOf("Padova", "Vicenza"), viewModel.uiState.value.cities.map { it.label })

        viewModel.applyFilters(mapOf("city" to "2"))
        viewModel.sortBy(SearchMissesViewModel.SORT_LAST)
        assertEquals("misses:page=1&per_page=20&filter[city]=2&sort=-last_searched_at", source.calls.last())
        assertEquals("2", saved.get<String>("missCity"))
        viewModel.sortBy("altro")
        assertEquals("misses:page=1&per_page=20&filter[city]=2", source.calls.last())

        val rows = viewModel.list.current.value.items
        viewModel.askRemove(rows.first())
        viewModel.confirm()
        assertEquals("remove:1", source.calls.last())
        assertEquals(listOf(2L), viewModel.list.current.value.items.map { it.id })
        assertEquals(UiText.Raw("Eliminato"), viewModel.uiState.value.message)

        viewModel.toggle(rows[1])
        assertEquals(setOf(2L), viewModel.uiState.value.selected)
        viewModel.askRemoveSelected()
        assertTrue(viewModel.uiState.value.confirmingBulk)
        viewModel.confirm()
        assertEquals("bulk:2", source.calls.last())
        assertTrue(viewModel.uiState.value.selected.isEmpty())
        assertTrue(viewModel.list.current.value.items.isEmpty())
    }

    @Test fun aFailedRemovalKeepsTheDialogOpenWithTheError() {
        val source = FakeAnalytics()
        val viewModel = SearchMissesViewModel(repository, source, SavedStateHandle())
        source.failNext = ApiException(404, ApiProblem(message = "Non trovato", code = "NOT_FOUND"))
        viewModel.askRemove(viewModel.list.current.value.items.first())
        viewModel.confirm()
        assertNotNull(viewModel.uiState.value.confirming)
        assertNotNull(viewModel.uiState.value.error)
        assertEquals(2, viewModel.list.current.value.items.size)
    }
}
