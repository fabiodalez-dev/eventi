package it.fabiodalez.incitta.ui.management

import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementNoticeTarget
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.ui.management.analytics.StatisticsRoute
import it.fabiodalez.incitta.ui.management.notifications.PanelResolution
import it.fabiodalez.incitta.ui.management.notifications.resolvePanel
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipAnalyticsRoute
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
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

/* Q3: dove porta, dentro la gestione, ciascun avviso che era solo email. */
@OptIn(ExperimentalCoroutinesApi::class)
class AppNoticesNavigationTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner")),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo-portici", name = "Collettivo Portici", role = "member")),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun panel(target: ManagementNoticeTarget) =
        ManagementDestination.parse(target.uri()!!, "https://x/api/v1/") as ManagementDestination.Panel

    @Test fun eachNoticeOpensItsNativeScreen() = runTest(dispatcher) {
        val repository = ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), TestScope(testScheduler), notices = FakeNotices())

        val order = resolvePanel(repository, panel(ManagementNoticeTarget("venue", 12, "sponsorships", 7)), enabled = true)
        assertEquals(SponsorshipsRoute(subject = "venue:12"), (order as PanelResolution.Native).route)

        val campaign = resolvePanel(repository, panel(ManagementNoticeTarget("organizer", 3, "sponsorship_stats", 55)), enabled = true)
        assertEquals(SponsorshipAnalyticsRoute(subject = "organizer:3", campaign = 55), (campaign as PanelResolution.Native).route)
        assertEquals("organizer:3", campaign.subject.key)

        val monthly = resolvePanel(repository, panel(ManagementNoticeTarget("venue", 12, "statistics")), enabled = true)
        assertEquals(StatisticsRoute(subject = "venue:12"), (monthly as PanelResolution.Native).route)

        val home = resolvePanel(repository, panel(ManagementNoticeTarget("organizer", 3, "home")), enabled = true)
        assertNull((home as PanelResolution.Native).route)
    }

    @Test fun aSubjectThePersonNoLongerManagesOpensNothing() = runTest(dispatcher) {
        val repository = ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), TestScope(testScheduler), notices = FakeNotices())
        /* Un avviso non ha un indirizzo del sito: se il locale non è (più) fra quelli gestiti, si resta dove si è. */
        assertEquals(PanelResolution.Nothing, resolvePanel(repository, panel(ManagementNoticeTarget("venue", 99, "sponsorship_stats", 55)), enabled = true))
        assertEquals(PanelResolution.Nothing, resolvePanel(repository, panel(ManagementNoticeTarget("venue", 12, "statistics")), enabled = false))
    }
}
