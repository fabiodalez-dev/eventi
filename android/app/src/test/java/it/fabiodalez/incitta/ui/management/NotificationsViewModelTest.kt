package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedEventRef
import it.fabiodalez.incitta.data.management.ManagedNotice
import it.fabiodalez.incitta.data.management.ManagedNoticeData
import it.fabiodalez.incitta.data.management.ManagedNoticeMeta
import it.fabiodalez.incitta.data.management.ManagedNoticePage
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementNoticeTarget
import it.fabiodalez.incitta.data.management.ManagementNoticesSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.ManagerPreferences
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.PanelPage
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.SubjectRef
import it.fabiodalez.incitta.ui.management.analytics.SubjectAnalyticsRoute
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.events.EventEditorRoute
import it.fabiodalez.incitta.ui.management.events.EventOverviewRoute
import it.fabiodalez.incitta.ui.management.feedback.CommentsModerationRoute
import it.fabiodalez.incitta.ui.management.notifications.ManagementNoticesViewModel
import it.fabiodalez.incitta.ui.management.notifications.PanelResolution
import it.fabiodalez.incitta.ui.management.notifications.resolvePanel
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
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

/* P12: l'area «Notifiche» e la traduzione degli indirizzi del pannello e degli avvisi, con un server finto. */
@OptIn(ExperimentalCoroutinesApi::class)
class NotificationsViewModelTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner")),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo-portici", name = "Collettivo Portici", role = "member")),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun repository(scope: CoroutineScope, notices: FakeNotices, identity: FakeIdentity = FakeIdentity(list = subjects)) =
        ManagementRepository(7, "token", FakeTicketing(), identity, null, MemoryDevice(), scope, notices = notices)

    @Test fun theAreaListsTheNoticesOfTheSubjectAndTheMonthlyReportOfTheVenue() = runTest(dispatcher) {
        val notices = FakeNotices()
        val viewModel = ManagementNoticesViewModel(repository(TestScope(testScheduler), notices), SavedStateHandle(mapOf("subject" to "venue:12")))
        val state = viewModel.uiState.value
        assertEquals("venue:12", state.subject?.key)
        assertEquals(listOf("a"), (state.notices as Loadable.Ready).value.map(ManagedNotice::id))
        assertEquals("c2", state.nextCursor)
        assertTrue(state.preferences!!.venueReportAvailable)

        viewModel.more()
        assertEquals(listOf("a", "b"), (viewModel.uiState.value.notices as Loadable.Ready).value.map(ManagedNotice::id))
        assertNull(viewModel.uiState.value.nextCursor)
        assertEquals(listOf("notices:venue:12:null", "preferences", "notices:venue:12:c2"), notices.calls)

        viewModel.markRead((viewModel.uiState.value.notices as Loadable.Ready).value.first())
        assertTrue((viewModel.uiState.value.notices as Loadable.Ready).value.first().read)
        assertEquals("read:a", notices.calls.last())

        viewModel.setVenueReport(false)
        assertFalse(viewModel.uiState.value.preferences!!.venueReport)
        assertEquals(UiText.Res(R.string.management_notices_report_off), viewModel.uiState.value.message)
    }

    @Test fun theOrganizerHasNoMonthlyReportAndAnUnknownSubjectSaysSo() = runTest(dispatcher) {
        val notices = FakeNotices(page = ManagedNoticePage())
        val organizer = ManagementNoticesViewModel(repository(TestScope(testScheduler), notices), SavedStateHandle(mapOf("subject" to "organizer:3")))
        assertEquals(Loadable.Empty, organizer.uiState.value.notices)
        assertNull(organizer.uiState.value.preferences)
        assertFalse("preferences" in notices.calls)

        val gone = ManagementNoticesViewModel(repository(TestScope(testScheduler), notices), SavedStateHandle(mapOf("subject" to "venue:99")))
        assertTrue(gone.uiState.value.missingSubject)
    }

    @Test fun panelPagesAndNoticesOpenTheNativeScreenOfTheRightSubject() = runTest(dispatcher) {
        val notices = FakeNotices()
        val repository = repository(TestScope(testScheduler), notices)
        val venue = SubjectRef(SubjectKind.Venue, slug = "circolo-aurora")

        val edit = resolvePanel(repository, ManagementDestination.Panel(venue, PanelPage.Event(slug = "concerto", edit = true), "https://x/gestione/circolo-aurora/eventi/concerto/modifica"), enabled = true)
        assertEquals(EventEditorRoute(41), (edit as PanelResolution.Native).route)
        assertEquals("venue:12", edit.subject.key)
        assertEquals("slug:venue:12:concerto", notices.calls.last())

        val comment = resolvePanel(repository, ManagementDestination.parse(ManagementNoticeTarget("organizer", 3, "comments", 41).uri()!!, "https://x/api/v1/") as ManagementDestination.Panel, enabled = true)
        assertEquals(CommentsModerationRoute(subject = "organizer:3", event = 41), (comment as PanelResolution.Native).route)

        val event = resolvePanel(repository, ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 12), PanelPage.Event(id = 34)), enabled = true)
        assertEquals(EventOverviewRoute(34), (event as PanelResolution.Native).route)
        val stats = resolvePanel(repository, ManagementDestination.Panel(venue, PanelPage.SubjectAnalytics("event", 34)), enabled = true)
        assertEquals(SubjectAnalyticsRoute(subject = "venue:12", type = "event", id = 34), (stats as PanelResolution.Native).route)
        val sponsor = resolvePanel(repository, ManagementDestination.Panel(venue, PanelPage.Sponsorships), enabled = true)
        assertEquals(SponsorshipsRoute(subject = "venue:12"), (sponsor as PanelResolution.Native).route)
        val home = resolvePanel(repository, ManagementDestination.Panel(venue, PanelPage.Home), enabled = true)
        assertNull((home as PanelResolution.Native).route)
    }

    @Test fun whatTheAppCannotOpenGoesBackToTheSite() = runTest(dispatcher) {
        val notices = FakeNotices(slugFailure = ApiException(404, null))
        val repository = repository(TestScope(testScheduler), notices)
        val url = "https://x/gestione/teatro-belzoni/eventi"
        /* Un locale che la persona non gestisce. */
        assertEquals(PanelResolution.Web(url), resolvePanel(repository, ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, slug = "teatro-belzoni"), PanelPage.Events, url), enabled = true))
        /* Un evento che il server non trova fra quelli del soggetto. */
        val missing = "https://x/gestione/circolo-aurora/eventi/sparito"
        assertEquals(PanelResolution.Web(missing), resolvePanel(repository, ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, slug = "circolo-aurora"), PanelPage.Event(slug = "sparito"), missing), enabled = true))
        /* La gestione nell'app spenta. */
        val own = "https://x/gestione/circolo-aurora"
        assertEquals(PanelResolution.Web(own), resolvePanel(repository, ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, slug = "circolo-aurora"), PanelPage.Home, own), enabled = false))
        /* Un avviso di un soggetto non più gestito: resta dov'è. */
        assertEquals(PanelResolution.Nothing, resolvePanel(repository, ManagementDestination.Panel(SubjectRef(SubjectKind.Venue, id = 99), PanelPage.Home), enabled = true))
    }

    @Test fun theWebPanelButtonListsOnlyTheAreasStillMissing() {
        val venue = ManagedVenue(id = 12, slug = "circolo-aurora", role = "owner", permissions = mapOf("social_use" to true)).toSubject()
        val missing = webOnlyAreas(venue, hasCheckinDates = false)
        assertFalse(ManagementArea.Dates in missing)
        assertFalse(ManagementArea.Notifications in missing)
        assertTrue(missing.all { !it.implemented })
        val withoutSocial = webOnlyAreas(ManagedVenue(id = 12, slug = "circolo-aurora", role = "editor").toSubject(), hasCheckinDates = false)
        assertFalse(ManagementArea.Social in withoutSocial)
    }
}

internal class FakeNotices(
    var page: ManagedNoticePage = ManagedNoticePage(
        listOf(ManagedNotice("a", "event_published", ManagedNoticeData("Evento pubblicato", "Concerto", "https://x/eventi/concerto", ManagementNoticeTarget("venue", 12, "event", 34)))),
        ManagedNoticeMeta(nextCursor = "c2", hasMore = true, unreadCount = 1),
    ),
    var slugFailure: Exception? = null,
) : ManagementNoticesSource {
    val calls = mutableListOf<String>()
    private var report = true
    override suspend fun notices(subject: ManagementSubject, cursor: String?): ManagedNoticePage {
        calls += "notices:${subject.key}:$cursor"
        return if (cursor == null) page else ManagedNoticePage(listOf(ManagedNotice("b", "venue_inactive", ManagedNoticeData("Locale fermo"), read = true)))
    }
    override suspend fun markRead(id: String) { calls += "read:$id" }
    override suspend fun preferences(): ManagerPreferences { calls += "preferences"; return ManagerPreferences(report, venueReportAvailable = true) }
    override suspend fun setVenueReport(enabled: Boolean): ManagerPreferences { calls += "report:$enabled"; report = enabled; return ManagerPreferences(report, true) }
    override suspend fun eventBySlug(subject: ManagementSubject, slug: String): ManagedEventRef {
        calls += "slug:${subject.key}:$slug"
        slugFailure?.let { throw it }
        return ManagedEventRef(41, slug, "Concerto")
    }
}
