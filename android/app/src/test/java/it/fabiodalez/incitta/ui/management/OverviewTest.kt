package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.management.DashboardEnvelope
import it.fabiodalez.incitta.data.management.DashboardSummary
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementDashboardSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.dashboardProgrammeName
import it.fabiodalez.incitta.data.management.dashboardProgrammePath
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import it.fabiodalez.incitta.ui.management.home.ManagementHomeViewModel
import it.fabiodalez.incitta.ui.management.overview.overviewSocialRoute
import it.fabiodalez.incitta.ui.management.overview.shortcutGroups
import it.fabiodalez.incitta.ui.management.social.SocialStudioRoute
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Il riepilogo (P11): forma della risposta, scorciatoie dai permessi, ViewModel con un server finto. */
@OptIn(ExperimentalCoroutinesApi::class)
class OverviewTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner",
            permissions = mapOf("events_create" to true, "ticketing_manage" to true, "profile_update" to true, "analytics_view" to true,
                "sponsorships_view" to true, "comments_moderate" to true, "import_manage" to true))),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo", name = "Collettivo", role = "member",
            permissions = mapOf("ticketing_manage" to true, "analytics_view" to true, "sponsorships_view" to true))),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeDashboard : ManagementDashboardSource {
        val calls = mutableListOf<String>()
        var failure: Exception? = null
        var summary = DashboardSummary()
        override suspend fun summary(subject: ManagementSubject): DashboardSummary {
            calls += "summary:${subject.key}"
            failure?.let { throw it }
            return summary
        }
        override suspend fun programme(subject: ManagementSubject, week: String, directory: File): DownloadedFile {
            calls += "programme:${subject.key}:$week"
            return DownloadedFile(File(directory, dashboardProgrammeName(subject, week)), "application/pdf")
        }
    }

    private fun repository(scope: CoroutineScope, dashboard: ManagementDashboardSource?) =
        ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), scope, dashboard = dashboard)

    private val payload = """{"data":{"subject":{"type":"venue","id":12,"name":"Circolo Aurora"},"timezone":"Europe/Rome","missing_location":true,
        "counts":{"today":1,"upcoming":3,"drafts":1},
        "today":[{"event":{"id":41,"slug":"concerto","title":"Concerto di musica popolare","status":{"value":"published","label":"Pubblicato"},
            "poster":{"thumb":"https://x/p.jpg","card":"https://x/p.jpg","full":"https://x/p.jpg","blurhash":null,"width":null,"height":null}},
            "date":{"id":90,"starts_at":"2026-09-10T19:00:00+00:00","place":"Circolo Aurora, Via Roma 1","status":{"value":"scheduled","label":"In programma"},"scheduled":true},
            "views":null,"links":{"edit":true,"tickets_occurrence_id":90,"statistics":true,"social":true,"sponsor":true}}],
        "upcoming":[{"event":{"id":42,"slug":"jazz","title":"Jazz in cortile","status":{"value":"published","label":"Pubblicato"},"poster":null},
            "date":{"id":91,"starts_at":"2026-09-13T19:00:00+00:00","place":"Circolo Aurora","status":{"value":"sold_out","label":"Tutto esaurito"},"scheduled":false},
            "links":{"edit":false,"tickets_occurrence_id":null,"statistics":true,"social":true,"sponsor":true}}],
        "recent_views":{"days":7,"items":[{"event":{"id":43,"title":"Bozza"},"date":null,"views":9,"links":{"statistics":true}}]},
        "programme":{"available":true,"this_week":"2026-09-07","next_week":"2026-09-14"},"nuovo":1}}"""

    @Test fun readsTheSummaryAndToleratesAnOlderServer() {
        val data = json.decodeFromString<DashboardEnvelope>(payload).data
        assertTrue(data.missingLocation)
        assertEquals(3, data.counts.upcoming)
        assertEquals(90L, data.today.single().links.ticketsOccurrenceId)
        assertEquals("https://x/p.jpg", data.today.single().event.poster?.thumb)
        assertFalse(data.upcoming.single().date!!.scheduled)
        assertEquals("Tutto esaurito", data.upcoming.single().date!!.status.label)
        assertNull(data.recentViews.items.single().date)
        assertEquals(9L, data.recentViews.items.single().views)
        assertEquals("2026-09-14", data.programme?.nextWeek)
        /* L'organizzazione non ha programma; un server che manda meno campi non fa fallire la lettura. */
        assertEquals(DashboardSummary(), json.decodeFromString<DashboardEnvelope>("""{"data":{}}""").data)
        assertNull(json.decodeFromString<DashboardEnvelope>("""{"data":{"programme":null}}""").data.programme)
    }

    @Test fun theProgrammeAsksForTheChosenWeek() {
        val venue = subjects.venues.single().toSubject()
        assertEquals("management/venues/12/dashboard/programme?week=2026-09-14", dashboardProgrammePath(venue, "2026-09-14"))
        assertEquals("management/venues/12/dashboard/programme", dashboardProgrammePath(venue, ""))
        assertEquals("programma-circolo-aurora-2026-09-14.pdf", dashboardProgrammeName(venue, "2026-09-14"))
    }

    @Test fun theOverviewIsTheSubjectPageAndTheLanding() {
        assertSame(ManagementHomeRoute, ManagementArea.Overview.entry)
        assertTrue(ManagementArea.Overview in availableAreas(subjects.organizers.single().toSubject(), hasCheckinDates = false))
    }

    @Test fun shortcutsFollowThePanelGroupsAndThePermissions() {
        val venue = shortcutGroups(subjects.venues.single().toSubject(), hasCheckinDates = false)
        assertEquals(3, venue.size)
        assertTrue(venue[0].createEvent)
        assertEquals(listOf(ManagementArea.Events, ManagementArea.Sponsorships, ManagementArea.Ticketing), venue[0].areas.filter { it != ManagementArea.Social })
        assertEquals(listOf(ManagementArea.Analytics, ManagementArea.Feedback), venue[1].areas)
        assertEquals(listOf(ManagementArea.Profile, ManagementArea.Collaborators, ManagementArea.Import), venue[2].areas)
        /* Il riepilogo non porta a sé stesso, e le aree senza voce nel pannello restano in «Tutte le aree». */
        venue.flatMap { it.areas }.forEach { assertTrue(it !in listOf(ManagementArea.Overview, ManagementArea.Registration, ManagementArea.Dates)) }

        val organizer = shortcutGroups(subjects.organizers.single().toSubject(), hasCheckinDates = false)
        assertFalse(organizer[0].createEvent)
        assertTrue(organizer.flatMap { it.areas }.none { it == ManagementArea.Collaborators || it == ManagementArea.Import || it == ManagementArea.Profile })
        assertTrue(organizer.none { it.areas.isEmpty() && !it.createEvent })
    }

    @Test fun theSocialButtonOfAnUpcomingEventOpensTheStudioOfThatEvent() {
        val card = json.decodeFromString<DashboardEnvelope>(payload).data.upcoming.single()
        val withStudio = ManagedVenue(id = 12, slug = "circolo-aurora", role = "owner", permissions = mapOf("social_use" to true)).toSubject()
        /* P10: lo studio del locale scelto, ristretto all'evento della card. */
        assertEquals(SocialStudioRoute(subject = "venue:12", event = 42), overviewSocialRoute(withStudio, card))
        /* Senza il permesso dello studio, o senza il collegamento del server (l'organizzazione), il pulsante non c'è. */
        assertNull(overviewSocialRoute(subjects.venues.single().toSubject(), card))
        assertNull(overviewSocialRoute(withStudio, card.copy(links = card.links.copy(social = false))))
    }

    @Test fun loadsTheSummaryOfTheChosenSubjectAndReloadsWhenItChanges() = runTest(dispatcher) {
        val dashboard = FakeDashboard().apply { summary = json.decodeFromString<DashboardEnvelope>(payload).data }
        val repository = repository(TestScope(testScheduler), dashboard)
        repository.loadSubjects()
        val viewModel = ManagementHomeViewModel(repository, SavedStateHandle())
        assertEquals(listOf("summary:venue:12"), dashboard.calls)
        assertEquals(1, (viewModel.uiState.value.summary as Loadable.Ready).value.counts.today)

        viewModel.select(subjects.organizers.single().toSubject())
        assertEquals("summary:organizer:3", dashboard.calls.last())
        assertEquals("organizer:3", repository.selectedSubjectKey)
        /* Lo stesso soggetto non rilegge. */
        viewModel.select(subjects.organizers.single().toSubject())
        assertEquals(2, dashboard.calls.size)
    }

    @Test fun comingBackRereadsOnlyAfterAWhileAndKeepsTheOldNumbersOnScreen() = runTest(dispatcher) {
        var clock = 1_000L
        val dashboard = FakeDashboard()
        val repository = repository(TestScope(testScheduler), dashboard)
        repository.loadSubjects()
        val viewModel = ManagementHomeViewModel(repository, SavedStateHandle(mapOf("subject" to "venue:12")), now = { clock })
        viewModel.onResume()
        assertEquals(1, dashboard.calls.size)

        clock += ManagementHomeViewModel.REFRESH_AFTER_MILLIS
        dashboard.failure = ApiException(500, null)
        viewModel.onResume()
        assertEquals(2, dashboard.calls.size)
        assertTrue(viewModel.uiState.value.summary is Loadable.Failed)
        assertFalse(viewModel.uiState.value.refreshing)

        dashboard.failure = null
        viewModel.loadSummary()
        assertTrue(viewModel.uiState.value.summary is Loadable.Ready)
    }

    @Test fun theProgrammeIsDownloadedOnceAtATime() = runTest(dispatcher) {
        val dashboard = FakeDashboard()
        val repository = repository(TestScope(testScheduler), dashboard)
        repository.loadSubjects()
        val viewModel = ManagementHomeViewModel(repository, SavedStateHandle())
        val cache = File(System.getProperty("java.io.tmpdir"), "overview-test-${System.nanoTime()}")
        viewModel.programme("2026-09-14", cache)
        assertEquals("programme:venue:12:2026-09-14", dashboard.calls.last())
        assertEquals("programma-circolo-aurora-2026-09-14.pdf", viewModel.uiState.value.programme.file?.file?.name)
        assertNull(viewModel.uiState.value.programme.busy)
        cache.deleteRecursively()
    }

    @Test fun withoutTheSourceTheSummaryStaysQuietAndTheShortcutsRemain() = runTest(dispatcher) {
        val repository = repository(TestScope(testScheduler), null)
        repository.loadSubjects()
        val viewModel = ManagementHomeViewModel(repository, SavedStateHandle())
        assertEquals(Loadable.Empty, viewModel.uiState.value.summary)
        assertEquals("venue:12", viewModel.uiState.value.selected?.key)
    }
}
