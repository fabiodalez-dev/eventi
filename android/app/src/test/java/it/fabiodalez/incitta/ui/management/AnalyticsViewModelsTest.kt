package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsSubjectHeader
import it.fabiodalez.incitta.data.management.AnalyticsSummary
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.ChannelsList
import it.fabiodalez.incitta.data.management.DateAnalyticsRow
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.EventChoice
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.EventShareLinks
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementAnalyticsSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.OccurrenceAnalyticsReport
import it.fabiodalez.incitta.data.management.ReportQuery
import it.fabiodalez.incitta.data.management.SummaryPeriod
import it.fabiodalez.incitta.ui.management.analytics.AnalyticsRoute
import it.fabiodalez.incitta.ui.management.analytics.AnalyticsTableViewModel
import it.fabiodalez.incitta.ui.management.analytics.ChannelsViewModel
import it.fabiodalez.incitta.ui.management.analytics.ReportViewModel
import it.fabiodalez.incitta.ui.management.analytics.StatisticsViewModel
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Statistiche (P5) con un server finto: periodi, filtri salvati, pagina di un evento, canali. */
@OptIn(ExperimentalCoroutinesApi::class)
class AnalyticsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner", permissions = mapOf("analytics_view" to true, "analytics_channels" to true))),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo", name = "Collettivo", role = "member", permissions = mapOf("analytics_view" to true))),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun repository(scope: CoroutineScope, analytics: FakeAnalytics) =
        ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), scope, analytics = analytics)

    @Test fun theAreaOpensFromTheMenu() {
        assertEquals(AnalyticsRoute(), ManagementArea.Analytics.entry)
        assertTrue(ManagementArea.Analytics.allowedFor(subjects.venues.single().toSubject(), false))
        assertFalse(ManagementArea.Analytics.allowedFor(ManagedVenue(id = 1).toSubject(), false))
    }

    @Test fun statisticsChangeThePeriodAndThePagesAndRememberThem() = runTest(dispatcher) {
        val analytics = FakeAnalytics()
        val saved = SavedStateHandle(mapOf("subject" to "venue:12"))
        val viewModel = StatisticsViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals("summary:venue:12:month:1:1", analytics.calls.last())
        assertTrue(viewModel.uiState.value.summary is Loadable.Ready)

        viewModel.setPeriod("week")
        assertEquals("summary:venue:12:week:1:1", analytics.calls.last())
        /* «365 giorni» non c'è in questa pagina: resta la scelta di prima, senza chiamate. */
        val before = analytics.calls.size
        viewModel.setPeriod("year")
        assertEquals(before, analytics.calls.size)
        viewModel.eventsPage(2)
        viewModel.sharesPage(3)
        assertEquals("summary:venue:12:week:2:3", analytics.calls.last())

        val restored = StatisticsViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals("week", restored.uiState.value.period)
        assertEquals("summary:venue:12:week:2:3", analytics.calls.last())
    }

    @Test fun theDetailedReportKeepsItsFiltersAndForgetsTheDatesWithANamedPeriod() = runTest(dispatcher) {
        val analytics = FakeAnalytics()
        val saved = SavedStateHandle(mapOf("subject" to "venue:12"))
        val viewModel = ReportViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals("report:venue:12:all:${ReportQuery()}", analytics.calls.last())

        viewModel.apply(ReportQuery(period = "custom", from = "2026-09-01", until = "2026-09-10", event = 41, channel = "whatsapp"), "Concerto")
        assertEquals("Concerto", viewModel.uiState.value.eventTitle)
        val restored = ReportViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals(ReportQuery(period = "custom", from = "2026-09-01", until = "2026-09-10", event = 41, channel = "whatsapp"), restored.uiState.value.query)
        assertEquals("Concerto", restored.uiState.value.eventTitle)

        viewModel.setPeriod("year")
        assertEquals(ReportQuery(period = "year", event = 41, channel = "whatsapp"), viewModel.uiState.value.query)
        viewModel.reset()
        assertEquals(ReportQuery(), viewModel.uiState.value.query)
        assertNull(viewModel.uiState.value.eventTitle)
        /* Nessuna data né evento per l'insieme dei dati di un soggetto: niente date, niente link. */
        assertNull(viewModel.uiState.value.dates)
    }

    @Test fun anEventPageDropsTheEventFilterAndLoadsDatesLinksAndTheSheet() = runTest(dispatcher) {
        val analytics = FakeAnalytics()
        val saved = SavedStateHandle(mapOf("subject" to "organizer:3", "type" to "event", "id" to 41L, "period" to "week", "event" to 99L, "channel" to "email"))
        val viewModel = ReportViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals("report:organizer:3:event:41:${ReportQuery(period = "week", channel = "email")}", analytics.calls.first { it.startsWith("report") })
        assertTrue(viewModel.uiState.value.dates is Loadable.Ready)
        assertTrue(viewModel.uiState.value.shareLinks is Loadable.Ready)

        viewModel.openOccurrence(5)
        assertEquals("occurrence:organizer:3:41:5", analytics.calls.last())
        assertTrue(viewModel.uiState.value.occurrence is Loadable.Ready)
        viewModel.closeOccurrence()
        assertNull(viewModel.uiState.value.occurrence)

        /* «Guarda un altro evento» chiede gli eventi del soggetto. */
        assertEquals(41L, viewModel.searchEvents("conc").upcoming.single().id)
        assertEquals("events:organizer:3:conc", analytics.calls.last())

        viewModel.export("events", "xlsx", File("build/tmp/analytics-test"))
        assertEquals("export:organizer:3:event:41:events:xlsx", analytics.calls.last())
        assertNotNull(viewModel.uiState.value.export.file)
    }

    @Test fun aFailureShowsTheMessageAndRetryWorks() = runTest(dispatcher) {
        val analytics = FakeAnalytics().apply { failure = ApiException(429, ApiProblem("Troppe richieste, riprova tra poco.", "TOO_MANY_REQUESTS")) }
        val viewModel = ReportViewModel(repository(TestScope(testScheduler), analytics), SavedStateHandle(mapOf("subject" to "venue:12")))
        assertTrue(viewModel.uiState.value.report is Loadable.Failed)
        analytics.failure = null
        viewModel.load()
        assertTrue(viewModel.uiState.value.report is Loadable.Ready)
    }

    @Test fun theTableKeepsDatasetFiltersAndPage() = runTest(dispatcher) {
        val analytics = FakeAnalytics()
        val saved = SavedStateHandle(mapOf("subject" to "venue:12", "type" to "", "id" to 0L, "dataset" to "links", "period" to "quarter", "event" to 41L))
        val viewModel = AnalyticsTableViewModel(repository(TestScope(testScheduler), analytics), saved)
        assertEquals("table:venue:12:all:links:1:${ReportQuery(period = "quarter", event = 41)}", analytics.calls.last())
        viewModel.page(2)
        assertEquals("table:venue:12:all:links:2:${ReportQuery(period = "quarter", event = 41)}", analytics.calls.last())
        assertEquals(2, AnalyticsTableViewModel(repository(TestScope(testScheduler), analytics), saved).uiState.value.page)
    }

    @Test fun channelsAreOnlyForTheOwnerAndShowTheServerErrorUnderTheField() = runTest(dispatcher) {
        val analytics = FakeAnalytics()
        val viewModel = ChannelsViewModel(repository(TestScope(testScheduler), analytics), SavedStateHandle(mapOf("subject" to "venue:12")))
        assertTrue(viewModel.uiState.value.channels is Loadable.Ready)

        analytics.failure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("label" to listOf("Serve un nome di almeno due lettere."))))
        viewModel.setLabel("!")
        viewModel.create()
        assertEquals("Serve un nome di almeno due lettere.", viewModel.uiState.value.errors.field("label"))

        analytics.failure = null
        viewModel.setLabel("Volantino estate con un nome lunghissimo oltre il tetto")
        assertEquals(38, viewModel.uiState.value.label.length)
        viewModel.setEvent(EventChoice(id = 41, title = "Concerto"))
        viewModel.create()
        assertEquals("add:venue:12:Volantino estate con un nome lunghissi:41", analytics.calls.last())
        assertEquals("", viewModel.uiState.value.label)
        assertEquals("abcdefg", viewModel.uiState.value.created)

        /* Il collaboratore dell'organizzazione non ha i canali: nessuna chiamata. */
        val calls = analytics.calls.size
        val member = ChannelsViewModel(repository(TestScope(testScheduler), analytics), SavedStateHandle(mapOf("subject" to "organizer:3")))
        assertEquals(Loadable.Empty, member.uiState.value.channels)
        assertEquals(calls, analytics.calls.size)
    }
}

/** Un server delle statistiche finto: annota le chiamate e risponde con il minimo. */
internal class FakeAnalytics : ManagementAnalyticsSource {
    val calls = mutableListOf<String>()
    var failure: Exception? = null

    private fun target(target: AnalyticsTarget?) = target?.let { "${it.type}:${it.id}" } ?: "all"

    override suspend fun summary(subject: ManagementSubject, period: String, page: Int, sharePage: Int): AnalyticsSummary {
        calls += "summary:${subject.key}:$period:$page:$sharePage"
        failure?.let { throw it }
        return AnalyticsSummary(period = SummaryPeriod(value = period))
    }

    override suspend fun report(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery): AnalyticsReport {
        calls += "report:${subject.key}:${target(target)}:$query"
        failure?.let { throw it }
        return AnalyticsReport(period = query.period, subject = target?.let { AnalyticsSubjectHeader(type = it.type, id = it.id) })
    }

    override suspend fun table(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, page: Int): AnalyticsTable {
        calls += "table:${subject.key}:${target(target)}:$dataset:$page:$query"
        failure?.let { throw it }
        return AnalyticsTable(dataset = dataset)
    }

    override suspend fun export(subject: ManagementSubject, target: AnalyticsTarget?, query: ReportQuery, dataset: String, format: String, directory: File): DownloadedFile {
        calls += "export:${subject.key}:${target(target)}:$dataset:$format"
        failure?.let { throw it }
        return DownloadedFile(File(directory, "statistiche.$format"), "application/octet-stream")
    }

    override suspend fun events(subject: ManagementSubject, search: String): EventChoiceGroups {
        calls += "events:${subject.key}:$search"
        return EventChoiceGroups(upcoming = listOf(EventChoice(id = 41, title = "Concerto")))
    }

    override suspend fun dates(subject: ManagementSubject, eventId: Long): List<DateAnalyticsRow> {
        calls += "dates:${subject.key}:$eventId"
        return listOf(DateAnalyticsRow(id = 5, canViewReport = true))
    }

    override suspend fun occurrence(subject: ManagementSubject, eventId: Long, occurrenceId: Long): OccurrenceAnalyticsReport {
        calls += "occurrence:${subject.key}:$eventId:$occurrenceId"
        return OccurrenceAnalyticsReport(id = occurrenceId)
    }

    override suspend fun shareLinks(subject: ManagementSubject, eventId: Long): EventShareLinks {
        calls += "links:${subject.key}:$eventId"
        return EventShareLinks(available = true)
    }

    override suspend fun channels(subject: ManagementSubject): ChannelsList {
        calls += "channels:${subject.key}"
        failure?.let { throw it }
        return ChannelsList()
    }

    override suspend fun addChannel(subject: ManagementSubject, label: String, eventId: Long?): ChannelsList {
        calls += "add:${subject.key}:$label:$eventId"
        failure?.let { throw it }
        return ChannelsList(created = "abcdefg", message = "Canale «$label» creato.")
    }
}
