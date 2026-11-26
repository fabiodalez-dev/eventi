package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.DashboardSection
import it.fabiodalez.incitta.data.editorial.DashboardSource
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialDashboard
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.common.EditorialListTarget
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.putEditorialListTarget
import it.fabiodalez.incitta.ui.editorial.common.takeEditorialListTarget
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Il riepilogo della redazione: caricamento, città, 423, dove porta un riquadro, il filtro passato all'elenco. */
@OptIn(ExperimentalCoroutinesApi::class)
class DashboardViewModelTest {
    @Before fun main() { Dispatchers.setMain(UnconfinedTestDispatcher()) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeDashboard(var failure: Exception? = null) : DashboardSource {
        val cities = mutableListOf<String?>()
        override suspend fun dashboard(city: String?): EditorialDashboard {
            cities += city
            failure?.let { failure = null; throw it }
            return EditorialDashboard(sections = listOf(DashboardSection(key = DashboardSection.QUEUE, title = "Da lavorare adesso")))
        }
    }

    private fun repository(desk: FakeDesk = FakeDesk(unlocked = true)) = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))

    @Test fun loadsTheSummaryOfTheChosenCity() {
        val repository = repository().apply { selectedCity = "padova" }
        val source = FakeDashboard()
        val model = DashboardViewModel(repository, source)
        val state = model.state.value
        assertTrue(state is Loadable.Ready)
        assertEquals("queue", (state as Loadable.Ready).value.sections.single().key)
        assertEquals(listOf<String?>("padova"), source.cities)
        assertFalse(model.busy.value)
    }

    @Test fun changingCityReadsTheDeskAgainAndThenTheSummary() {
        val desk = FakeDesk(unlocked = true)
        val repository = repository(desk)
        val source = FakeDashboard()
        val model = DashboardViewModel(repository, source)
        model.selectCity("vicenza")
        assertEquals("vicenza", repository.selectedCity)
        assertEquals("desk:vicenza", desk.calls.last())
        assertEquals("vicenza", source.cities.last())
        assertTrue(model.state.value is Loadable.Ready)
    }

    @Test fun aFailureShowsTheMessageAndRetryWorks() {
        val source = FakeDashboard(failure = ApiException(500, ApiProblem(message = "Errore del server")))
        val model = DashboardViewModel(repository(), source)
        assertTrue(model.state.value is Loadable.Failed)
        model.load()
        assertTrue(model.state.value is Loadable.Ready)
    }

    private val moderator = EditorialPermissions(mapOf("events_view" to true, "users_view" to true, "reports_resolve" to true, "venues_view" to true, "notifications_view" to true))
    private val pendingEvents = DashboardTarget(area = "events", filters = JsonObject(mapOf("status" to JsonPrimitive("pending"))), webPath = "admin/events?filters%5Bstatus%5D%5Bvalue%5D=pending")

    @Test fun aTileOpensThePanelPageUntilTheListIsNativeAndAcceptsTheFilter() {
        /* Oggi solo il riepilogo è nativo: gli eventi si aprono nel pannello, già filtrati. */
        assertFalse(EditorialArea.Events.native)
        assertEquals(DashboardDestination.Web(pendingEvents.webPath), dashboardDestination(pendingEvents, moderator, accepts = { true }))
        /* Senza destinazione web né area nota non c'è niente da aprire. */
        assertNull(dashboardDestination(DashboardTarget(area = "sconosciuta"), moderator))
        assertEquals(EditorialArea.Venues, dashboardArea("applications"))
        assertEquals(EditorialArea.Moderation, dashboardArea("reports"))
        assertEquals(EditorialArea.System, dashboardArea("import_sources"))
        assertEquals(EditorialArea.Users, dashboardArea("users"))
        assertEquals(EditorialArea.Events, dashboardArea("events"))
        assertTrue(EditorialArea.Dashboard.native)
    }

    @Test fun theFilterReachesTheListOnceAndOnTopOfItsDefaults() {
        val handle = SavedStateHandle()
        handle.putEditorialListTarget(EditorialListTarget("events", tab = null, filters = mapOf("status" to "pending")))
        val target = handle.takeEditorialListTarget()
        assertEquals(EditorialListTarget("events", null, mapOf("status" to "pending")), target)
        /* Una volta sola: tornando indietro l'elenco non riprende il filtro. */
        assertNull(handle.takeEditorialListTarget())
        val query = target!!.applyTo(ListQuery(tab = "all", filters = mapOf("city" to "padova")))
        assertEquals("all", query.tab)
        assertEquals(mapOf("city" to "padova", "status" to "pending"), query.filters)
        assertTrue(query.params(1).contains("filter[status]" to "pending"))
        val users = EditorialListTarget("users", tab = "pending").applyTo(ListQuery())
        assertEquals("pending", users.tab)
    }

    @Test fun aTargetWithoutFiltersFromAnOldServerIsEmpty() {
        assertEquals(emptyMap<String, String>(), DashboardTarget(area = "users", filters = JsonArray(emptyList())).filterMap)
    }

    @Test fun theRegistrationMomentIsInTheCityZone() {
        assertEquals("26 nov 2026, 11:05", dashboardMoment("2026-11-26T10:05:00+00:00", ZoneId.of("Europe/Rome")))
        assertNull(dashboardMoment("non è una data", ZoneId.of("Europe/Rome")))
        assertNull(dashboardMoment(null, ZoneId.of("Europe/Rome")))
    }
}
