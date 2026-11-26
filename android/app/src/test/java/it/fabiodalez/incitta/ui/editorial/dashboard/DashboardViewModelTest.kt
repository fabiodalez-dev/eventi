package it.fabiodalez.incitta.ui.editorial.dashboard

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.DashboardSection
import it.fabiodalez.incitta.data.editorial.DashboardSource
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialDashboard
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonArray
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Il riepilogo della redazione: caricamento, città, 423. Dove porta un riquadro: `DashboardTargetsTest`. */
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

    @Test fun aTargetWithoutFiltersFromAnOldServerIsEmpty() {
        assertEquals(emptyMap<String, String>(), DashboardTarget(area = "users", filters = JsonArray(emptyList())).filterMap)
    }

    @Test fun theRegistrationMomentIsInTheCityZone() {
        assertEquals("26 nov 2026, 11:05", dashboardMoment("2026-11-26T10:05:00+00:00", ZoneId.of("Europe/Rome")))
        assertNull(dashboardMoment("non è una data", ZoneId.of("Europe/Rome")))
        assertNull(dashboardMoment(null, ZoneId.of("Europe/Rome")))
    }
}
