package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSponsorshipsSource
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.SelectableGrant
import it.fabiodalez.incitta.data.management.SponsorshipActionData
import it.fabiodalez.incitta.data.management.SponsorshipActionEnvelope
import it.fabiodalez.incitta.data.management.SponsorshipAnalytics
import it.fabiodalez.incitta.data.management.SponsorshipCampaign
import it.fabiodalez.incitta.data.management.SponsorshipCampaignDetail
import it.fabiodalez.incitta.data.management.SponsorshipCampaignOption
import it.fabiodalez.incitta.data.management.SponsorshipCampaignPage
import it.fabiodalez.incitta.data.management.SponsorshipCatalog
import it.fabiodalez.incitta.data.management.SponsorshipChoice
import it.fabiodalez.incitta.data.management.SponsorshipClickPage
import it.fabiodalez.incitta.data.management.SponsorshipDesk
import it.fabiodalez.incitta.data.management.SponsorshipDuration
import it.fabiodalez.incitta.data.management.SponsorshipEvent
import it.fabiodalez.incitta.data.management.SponsorshipEventPage
import it.fabiodalez.incitta.data.management.SponsorshipFilters
import it.fabiodalez.incitta.data.management.SponsorshipFormat
import it.fabiodalez.incitta.data.management.SponsorshipListMeta
import it.fabiodalez.incitta.data.management.SponsorshipOrder
import it.fabiodalez.incitta.data.management.SponsorshipOrderEnvelope
import it.fabiodalez.incitta.data.management.SponsorshipOrderResult
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipAnalyticsViewModel
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipConfirm
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipWizardViewModel
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsViewModel
import it.fabiodalez.incitta.ui.management.sponsorships.WizardResult
import it.fabiodalez.incitta.ui.management.sponsorships.WizardStep
import java.time.LocalDate
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

/* Le sponsorizzazioni (P6) con un server finto: stesse azioni, stessi permessi e stesso ritorno da Stripe del pannello. */
@OptIn(ExperimentalCoroutinesApi::class)
class SponsorshipsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val subjects = ManagementSubjects(
        venues = listOf(ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner", permissions = mapOf("sponsorships_view" to true))),
        organizers = listOf(ManagedOrganizer(id = 3, slug = "collettivo", name = "Collettivo", role = "member", permissions = mapOf("sponsorships_view" to true))),
    )

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun repository(scope: CoroutineScope, api: FakeSponsorships) =
        ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(list = subjects), null, MemoryDevice(), scope, sponsorships = api)

    @Test fun thePageOpensOnTheSubjectAndKeepsTheServerButtons() = runTest(dispatcher) {
        val api = FakeSponsorships(desk = SponsorshipDesk(canPurchase = false, orders = listOf(SponsorshipOrder(id = 5, canRetry = false)), selectableGrants = listOf(SelectableGrant(id = 4), SelectableGrant(id = 8))))
        val viewModel = SponsorshipsViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(mapOf("subject" to "organizer:3")))

        val state = viewModel.uiState.value
        assertEquals(3L, (state.subject as Loadable.Ready).value.id)
        assertEquals("desk:organizer:3", api.calls.first())
        assertFalse((state.desk as Loadable.Ready).value.canPurchase)
        // Il periodo per mettere in evidenza: il primo, quello che scade prima.
        assertEquals(4L, state.grantId)
        viewModel.chooseGrant(8)
        assertEquals(8L, viewModel.uiState.value.grantId)
    }

    @Test fun retryOpensTheBrowserAndChecksThePaymentWhenTheAppComesBack() = runTest(dispatcher) {
        val api = FakeSponsorships(desk = SponsorshipDesk(canPurchase = true, orders = listOf(SponsorshipOrder(id = 5, canRetry = true))))
        val saved = SavedStateHandle(mapOf("subject" to "venue:12"))
        val viewModel = SponsorshipsViewModel(repository(TestScope(testScheduler), api), saved)

        viewModel.retry(api.desk.orders.single())
        assertEquals("https://checkout.stripe.test/pay/cs_5", viewModel.uiState.value.checkoutUrl)
        assertEquals(5L, saved.get<Long>(SponsorshipsViewModel.KEY_PENDING))
        viewModel.consumeCheckout()
        assertNull(viewModel.uiState.value.checkoutUrl)

        // Di ritorno dal browser: una volta sola.
        viewModel.onResume()
        viewModel.onResume()
        assertEquals(1, api.calls.count { it.startsWith("reconcile:") })
        assertTrue("reconcile:venue:12:5:false" in api.calls)
        assertEquals(UiText.Raw("Pagamento ricevuto."), viewModel.uiState.value.message)
    }

    @Test fun theReturnFromStripeIsCheckedOnceEvenAfterARotation() = runTest(dispatcher) {
        val api = FakeSponsorships()
        val saved = SavedStateHandle(mapOf("subject" to "venue:12", "order" to 34L, "cancelled" to true))
        SponsorshipsViewModel(repository(TestScope(testScheduler), api), saved)
        assertTrue("reconcile:venue:12:34:true" in api.calls)

        // Stessa voce di navigazione, ViewModel nuovo: niente seconda chiamata.
        SponsorshipsViewModel(repository(TestScope(testScheduler), api), saved)
        assertEquals(1, api.calls.count { it.startsWith("reconcile:") })
    }

    @Test fun cancelWithdrawAndStopAskFirst() = runTest(dispatcher) {
        val order = SponsorshipOrder(id = 5, canCancel = true)
        val api = FakeSponsorships(desk = SponsorshipDesk(canPurchase = true, orders = listOf(order)))
        val viewModel = SponsorshipsViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(mapOf("subject" to "venue:12")))

        viewModel.askCancel(order)
        assertEquals(SponsorshipConfirm.Cancel(order), viewModel.uiState.value.confirm)
        assertFalse(api.calls.any { it.startsWith("cancel:") })
        viewModel.confirm()
        assertTrue("cancel:5" in api.calls)
        assertNull(viewModel.uiState.value.confirm)

        viewModel.askWithdraw(order); viewModel.dismissConfirm()
        assertFalse(api.calls.any { it.startsWith("withdraw:") })
    }

    @Test fun arrivingFromAnEventOpensTheWizardOnlyForWhoCanBuy() = runTest(dispatcher) {
        val buyer = SponsorshipsViewModel(repository(TestScope(testScheduler), FakeSponsorships(desk = SponsorshipDesk(canPurchase = true))), SavedStateHandle(mapOf("subject" to "venue:12", "event" to 9L)))
        assertEquals(9L, buyer.uiState.value.openWizardFor)
        val collaborator = SponsorshipsViewModel(repository(TestScope(testScheduler), FakeSponsorships(desk = SponsorshipDesk(canPurchase = false))), SavedStateHandle(mapOf("subject" to "venue:12", "event" to 9L)))
        assertNull(collaborator.uiState.value.openWizardFor)
    }

    @Test fun theWizardWalksTheFourStepsAndOpensTheCheckout() = runTest(dispatcher) {
        val api = FakeSponsorships()
        val viewModel = SponsorshipWizardViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(mapOf("subject" to "venue:12", "event" to 9L)))

        // L'evento arriva già scelto dalla scheda dell'evento.
        assertEquals(9L, viewModel.uiState.value.event?.id)
        assertTrue(viewModel.uiState.value.canAdvance)
        viewModel.next()
        assertEquals(WizardStep.Where, viewModel.uiState.value.step)
        assertFalse(viewModel.uiState.value.canAdvance)
        viewModel.choosePlacement("list_top")
        // La prima durata della posizione è già scelta, come nel pannello.
        assertEquals("price:2", viewModel.uiState.value.duration)
        viewModel.next(); viewModel.next()
        assertEquals(WizardStep.Summary, viewModel.uiState.value.step)
        assertTrue(viewModel.uiState.value.viaStripe)

        viewModel.submit()
        assertEquals(SponsorshipChoice("event", 9L, "list_top", "price:2", null), api.lastChoice)
        assertEquals(WizardResult.Checkout("https://checkout.stripe.test/pay/cs_77", 77L), viewModel.uiState.value.result)
    }

    @Test fun aRequestCarriesTheNoteAndAnErrorGoesBackToItsStep() = runTest(dispatcher) {
        val api = FakeSponsorships()
        val viewModel = SponsorshipWizardViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(mapOf("subject" to "venue:12")))
        viewModel.chooseScope("all"); viewModel.next()
        viewModel.choosePlacement("home_hero"); viewModel.next()
        viewModel.chooseDuration("months:3"); viewModel.next()
        assertFalse(viewModel.uiState.value.viaStripe)
        viewModel.editNote("Chiamatemi")

        api.submitFailure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("duration" to listOf("Scegli evento, posizione e durata prima di procedere."))))
        viewModel.submit()
        assertEquals(WizardStep.Time, viewModel.uiState.value.step)
        assertEquals("Scegli evento, posizione e durata prima di procedere.", viewModel.uiState.value.errors.field("duration"))

        api.submitFailure = null
        viewModel.next()
        viewModel.submit()
        assertEquals(SponsorshipChoice("all", null, "home_hero", "months:3", "Chiamatemi"), api.lastChoice)
        assertEquals(WizardResult.Requested, viewModel.uiState.value.result)
    }

    @Test fun theStatisticsKeepTheirFiltersAndWaitForBothDates() = runTest(dispatcher) {
        val api = FakeSponsorships()
        val saved = SavedStateHandle(mapOf("subject" to "venue:12", "campaign" to 4L))
        val viewModel = SponsorshipAnalyticsViewModel(repository(TestScope(testScheduler), api), saved)
        assertEquals(SponsorshipFilters(days = "30", campaignId = 4), api.lastFilters)

        viewModel.choosePeriod("custom")
        val before = api.calls.count { it.startsWith("analytics") }
        viewModel.chooseDates(LocalDate.of(2026, 11, 10), null)
        assertEquals(before, api.calls.count { it.startsWith("analytics") })
        viewModel.chooseDates(LocalDate.of(2026, 11, 10), LocalDate.of(2026, 11, 1))
        // «Al» prima di «Dal» diventa lo stesso giorno.
        assertEquals(SponsorshipFilters(days = "custom", from = "2026-11-10", to = "2026-11-10", campaignId = 4), api.lastFilters)

        viewModel.chooseCampaign(null)
        assertNull(api.lastFilters?.campaignId)
        assertEquals(0L, saved.get<Long>(SponsorshipAnalyticsViewModel.KEY_CAMPAIGN))
    }
}

/** Un server finto delle sponsorizzazioni: registra le chiamate e risponde come il vero. */
internal class FakeSponsorships(
    var desk: SponsorshipDesk = SponsorshipDesk(),
) : ManagementSponsorshipsSource {
    val calls = mutableListOf<String>()
    var lastChoice: SponsorshipChoice? = null
    var lastFilters: SponsorshipFilters? = null
    var submitFailure: Exception? = null
    private val events = listOf(SponsorshipEvent(id = 9, title = "Concerto"), SponsorshipEvent(id = 10, title = "Mostra"))
    private val catalog = SponsorshipCatalog(paysOnline = true, placements = listOf(
        SponsorshipFormat("list_top", "In cima alle liste", priced = true, durations = listOf(SponsorshipDuration("price:2", 3, "3 mesi", 16200, "162,00 €", paysOnline = true))),
        SponsorshipFormat("home_hero", "Apertura", durations = listOf(SponsorshipDuration("months:1", 1), SponsorshipDuration("months:3", 3))),
    ))

    private fun key(subject: ManagementSubject) = subject.key
    override suspend fun desk(subject: ManagementSubject, campaignsPage: Int): SponsorshipDesk { calls += "desk:${key(subject)}"; return desk }
    override suspend fun campaigns(subject: ManagementSubject, page: Int) = SponsorshipCampaignPage(listOf(SponsorshipCampaign(id = 40L + page)))
    override suspend fun events(subject: ManagementSubject, query: String, limit: Int, grantId: Long?): SponsorshipEventPage {
        calls += "events:$query:$limit:${grantId ?: 0}"
        return SponsorshipEventPage(events.filter { query.isBlank() || it.title.contains(query, ignoreCase = true) }.take(limit), SponsorshipListMeta(total = events.size))
    }
    override suspend fun catalog(subject: ManagementSubject) = catalog
    override suspend fun submit(subject: ManagementSubject, choice: SponsorshipChoice): SponsorshipOrderEnvelope {
        submitFailure?.let { throw it }
        lastChoice = choice
        val online = catalog.placements.flatMap { it.durations }.firstOrNull { it.key == choice.duration }?.paysOnline == true
        return SponsorshipOrderEnvelope(SponsorshipOrderResult(if (online) "checkout" else "request", if (online) "https://checkout.stripe.test/pay/cs_77" else null, SponsorshipOrder(id = 77)))
    }
    override suspend fun retry(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope {
        calls += "retry:$orderId"
        return SponsorshipOrderEnvelope(SponsorshipOrderResult("checkout", "https://checkout.stripe.test/pay/cs_$orderId", SponsorshipOrder(id = orderId)))
    }
    override suspend fun cancel(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope { calls += "cancel:$orderId"; return SponsorshipOrderEnvelope(SponsorshipOrderResult("cancelled"), "Ordine annullato.") }
    override suspend fun withdraw(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope { calls += "withdraw:$orderId"; return SponsorshipOrderEnvelope(SponsorshipOrderResult("withdrawn"), "Richiesta ritirata.") }
    override suspend fun reconcile(subject: ManagementSubject, orderId: Long, cancelled: Boolean): SponsorshipOrderEnvelope {
        calls += "reconcile:${key(subject)}:$orderId:$cancelled"
        return SponsorshipOrderEnvelope(SponsorshipOrderResult(if (cancelled) "returned_cancelled" else "paid"), if (cancelled) "Pagamento annullato." else "Pagamento ricevuto.")
    }
    override suspend fun activate(subject: ManagementSubject, grantId: Long, eventId: Long): SponsorshipActionEnvelope { calls += "activate:$grantId:$eventId"; return SponsorshipActionEnvelope(SponsorshipActionData(1), "Evento aggiunto") }
    override suspend fun stop(subject: ManagementSubject, campaignId: Long): SponsorshipActionEnvelope { calls += "stop:$campaignId"; return SponsorshipActionEnvelope(SponsorshipActionData(campaignId, "paused"), "Interrotta") }
    override suspend fun campaign(subject: ManagementSubject, campaignId: Long) = SponsorshipCampaignDetail(id = campaignId)
    override suspend fun analytics(subject: ManagementSubject, filters: SponsorshipFilters): SponsorshipAnalytics { calls += "analytics:${filters.query()}"; lastFilters = filters; return SponsorshipAnalytics() }
    override suspend fun clicks(subject: ManagementSubject, filters: SponsorshipFilters, page: Int) = SponsorshipClickPage()
    override suspend fun campaignOptions(subject: ManagementSubject, query: String) = listOf(SponsorshipCampaignOption(id = 4))
}
