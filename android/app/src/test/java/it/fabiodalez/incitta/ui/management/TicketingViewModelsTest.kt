package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.CheckinManifest
import it.fabiodalez.incitta.data.CheckinStaff
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.ManifestTicket
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.fakeRepository
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.ticketing.CampaignViewModel
import it.fabiodalez.incitta.ui.management.ticketing.ScanTexts
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateViewModel
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDatesViewModel
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* I ViewModel della biglietteria con un server finto: stesse chiamate e stesse regole della schermata di prima. */
@OptIn(ExperimentalCoroutinesApi::class)
class TicketingViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val texts = ScanTexts(notInList = "Non è nell'elenco", alreadyUsed = "%1\$s: già entrato")
    private val code = "C".repeat(64)

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    @Test fun datesListKeepsItsFiltersInTheSavedStateAndPages() = runTest(dispatcher) {
        val ticketing = FakeTicketing()
        val saved = SavedStateHandle()
        val viewModel = TicketingDatesViewModel(fakeRepository(TestScope(testScheduler), ticketing), saved)
        assertEquals(listOf("dates:upcoming:1"), ticketing.calls)
        assertEquals(2, viewModel.uiState.value.nextPage)
        viewModel.more()
        assertEquals(listOf(41L, 42L), viewModel.uiState.value.dates.map(ManagedDate::id))
        viewModel.showPast(true)
        assertEquals(true, saved.get<Boolean>(TicketingDatesViewModel.KEY_PAST))
        assertEquals("dates:past:1", ticketing.calls.last())
        assertEquals(listOf(41L), viewModel.uiState.value.dates.map(ManagedDate::id))
        viewModel.showReports(true)
        assertEquals("campaigns:1", ticketing.calls.last())
        assertEquals(listOf(7L), viewModel.uiState.value.campaigns.map { it.id })

        /* Dopo la morte del processo il ViewModel rinasce con gli stessi filtri. */
        val restored = TicketingDatesViewModel(fakeRepository(TestScope(testScheduler), ticketing), saved)
        assertTrue(restored.uiState.value.reports)
        assertTrue(restored.uiState.value.past)
    }

    @Test fun aFailedListShowsTheUsualMessage() = runTest(dispatcher) {
        val ticketing = FakeTicketing().apply { failDates = IOException("giù") }
        val viewModel = TicketingDatesViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle())
        assertEquals("Non riesco a raggiungere il server. Controlla Wi-Fi o rete mobile e riprova.", viewModel.uiState.value.error)
        assertFalse(viewModel.uiState.value.loaded)
    }

    @Test fun dateOpensFromItsIdAndDownloadsTheOfflineList() = runTest(dispatcher) {
        val ticketing = FakeTicketing(manifestTickets = listOf(ManifestTicket(CheckinManifest.hash(code), "Ada Rossi", "confirmed")))
        val device = MemoryDevice()
        val viewModel = TicketingDateViewModel(fakeRepository(TestScope(testScheduler), ticketing, device = device), SavedStateHandle(mapOf("id" to 41L)))
        assertEquals(41L, (viewModel.uiState.value.date as Loadable.Ready).value.id)
        assertEquals(listOf("date:41", "manifest:41"), ticketing.calls)
        assertEquals(1, viewModel.uiState.value.manifest?.size)
        assertNotNull(device.manifests[41])
        /* «Aggiorna» rilegge la data ma non riscarica l'elenco, come prima. */
        viewModel.refresh()
        assertEquals(listOf("date:41", "manifest:41", "date:41"), ticketing.calls)
    }

    @Test fun offlineAKnownReadIsMarkedAndQueuedAndAUsedOneIsRejectedOnThePhone() = runTest(dispatcher) {
        val ticketing = FakeTicketing(manifestTickets = listOf(ManifestTicket(CheckinManifest.hash(code), "Ada Rossi", "confirmed")))
        ticketing.checkInFailure = IOException("senza rete")
        val device = MemoryDevice()
        val scope = TestScope(testScheduler)
        val repository = fakeRepository(scope, ticketing, device = device)
        val viewModel = TicketingDateViewModel(repository, SavedStateHandle(mapOf("id" to 41L)))
        viewModel.addCode(code, online = false, texts = texts)
        assertEquals(1, repository.checkins.value.pending.size)
        assertTrue(device.manifests[41]!!.usedHashes.contains(CheckinManifest.hash(code)))
        viewModel.addCode(code, online = false, texts = texts)
        assertEquals("Ada Rossi: già entrato", repository.checkins.value.outcomes.first().message)
        assertEquals(1, repository.checkins.value.pending.size)
        repository.close()
    }

    @Test fun anInvalidCodeNeverEntersTheQueue() = runTest(dispatcher) {
        val repository = fakeRepository(TestScope(testScheduler))
        val viewModel = TicketingDateViewModel(repository, SavedStateHandle(mapOf("id" to 41L)))
        viewModel.addCode("corto", online = true, texts = texts)
        assertEquals(UiText.Res(R.string.management_invalid_code), viewModel.uiState.value.error)
        assertTrue(repository.checkins.value.pending.isEmpty())
    }

    @Test fun managerActionsCallTheSameEndpointsAndReloadTheDate() = runTest(dispatcher) {
        val ticketing = FakeTicketing()
        val viewModel = TicketingDateViewModel(fakeRepository(TestScope(testScheduler), ticketing), SavedStateHandle(mapOf("id" to 41L)))
        var cleared = false
        viewModel.inviteStaff(" porta@example.test ") { cleared = true }
        assertTrue(cleared)
        assertTrue(ticketing.calls.contains("staff:41:porta@example.test:false"))
        assertEquals(UiText.Res(R.string.management_saved), viewModel.uiState.value.message)
        viewModel.removeStaff(CheckinStaff(3, "porta@example.test"))
        assertTrue(ticketing.calls.contains("staff:41:porta@example.test:true"))
        viewModel.sendMessage("Cambio orario", "Si parte alle 22", false) {}
        assertEquals(UiText.Plural(R.plurals.management_message_sent, 12, listOf(12)), viewModel.uiState.value.message)
        viewModel.saveDetails(buildJsonObject { put("cost_breakdown", buildJsonObject { put("admission", JsonPrimitive("12.50")) }) })
        assertTrue(ticketing.calls.any { it.startsWith("details:41:") && it.contains("12.50") })
        val before = ticketing.calls.count { it == "manifest:41" }
        viewModel.addGuestBooking("Ada\nBea", "", "", "", {})
        assertTrue(ticketing.calls.contains("guest:41:Ada\nBea"))
        /* I biglietti appena registrati entrano subito nell'elenco senza rete. */
        assertEquals(before + 1, ticketing.calls.count { it == "manifest:41" })
    }

    @Test fun theDailyMessageLimitComesFromTheServerWhenItSendsIt() = runTest(dispatcher) {
        val identity = FakeIdentity(options = ManagementOptions.from(buildJsonObject { put("limits", buildJsonObject { put("attendee_messages_per_day", JsonPrimitive(5)) }) }))
        val repository = fakeRepository(TestScope(testScheduler), identity = identity)
        repository.landing()
        assertEquals(5, TicketingDateViewModel(repository, SavedStateHandle(mapOf("id" to 41L))).uiState.value.messageDailyLimit)
        val old = fakeRepository(TestScope(testScheduler), identity = FakeIdentity(enabled = false))
        old.landing()
        assertEquals(3, TicketingDateViewModel(old, SavedStateHandle(mapOf("id" to 41L))).uiState.value.messageDailyLimit)
    }

    @Test fun campaignLoadsFromItsId() = runTest(dispatcher) {
        val viewModel = CampaignViewModel(fakeRepository(TestScope(testScheduler)), SavedStateHandle(mapOf("id" to 7L)))
        assertEquals(7L, (viewModel.uiState.value as Loadable.Ready).value.id)
    }
}
