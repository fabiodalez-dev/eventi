package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.*
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* I ViewModel di R8 con un server finto: che cosa chiedono, con quali dati, e che cosa mostrano dopo. */
@OptIn(ExperimentalCoroutinesApi::class)
class PromotionViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val source = FakePromotion()
    private val repository = EditorialRepository(1, "t", FakeDesk(unlocked = true), EditorialLocalLock(clock = { 0L }))

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    @Test fun theCampaignListPausesARowAndDeletesTheSelectedOnes() = runTest(dispatcher) {
        val model = CampaignListViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        assertEquals(listOf("page" to "1", "per_page" to "20"), source.listParams.single())
        assertEquals(listOf(7L, 8L), model.list.current.value.items.map { it.id })
        assertEquals("Collocazione in cima", model.uiState.value.choices.placements.single().label)

        model.ask(CampaignConfirm.Toggle(7, "Sospendi"))
        model.confirm()
        advanceUntilIdle()
        assertEquals("paused", model.list.current.value.items.first().status.value)
        assertEquals("Campagna sospesa.", model.uiState.value.message)

        model.toggleSelecting()
        model.select(7)
        model.select(8)
        model.ask(CampaignConfirm.DeleteSelected(model.uiState.value.selected.toList()))
        model.confirm()
        advanceUntilIdle()
        assertEquals(listOf("toggle:7", "bulk:[7, 8]"), source.actions)
        assertTrue(model.list.current.value.items.isEmpty())
        assertFalse(model.uiState.value.selecting)
    }

    @Test fun aPasswordRefusalKeepsTheDeleteDialogWithTheMessage() = runTest(dispatcher) {
        val model = CampaignListViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        source.failNext = ApiException(422, ApiProblem(message = "Non puoi eliminarla", code = "VALIDATION_FAILED"))
        model.ask(CampaignConfirm.Delete(7, "Jazz"))
        model.confirm()
        advanceUntilIdle()
        assertNotNull(model.uiState.value.confirm)
        assertNotNull(model.uiState.value.error)
    }

    @Test fun theDetailOfAGrantRowDoesNotSendDeliveryAndShowsFieldErrors() = runTest(dispatcher) {
        source.onGrant = true
        val saved = SavedStateHandle(mapOf(CampaignDetailViewModel.KEY_ID to 7L))
        val model = CampaignDetailViewModel(repository, source, saved)
        advanceUntilIdle()
        assertEquals("Birrificio", model.uiState.value.draft?.advertiserName)
        model.edit { it.copy(notes = "Solo la nota", priority = "900") }
        /* Il modulo sopravvive alla morte del processo. */
        assertTrue(saved.get<String>(CampaignDetailViewModel.KEY_DRAFT)!!.contains("Solo la nota"))
        model.save()
        advanceUntilIdle()
        val body = source.bodies.last()
        assertFalse("priority" in body)
        assertFalse("event_id" in body)
        assertEquals("Solo la nota", body["notes"]?.jsonPrimitive?.content)

        source.failNext = ApiException(422, ApiProblem(code = "VALIDATION_FAILED", fields = mapOf("ends_at" to listOf("La fine deve venire dopo l'inizio."))))
        model.save()
        advanceUntilIdle()
        assertEquals("La fine deve venire dopo l'inizio.", model.uiState.value.errors.field("ends_at"))
    }

    @Test fun theWizardChecksEachStepOnTheServerAndCreatesTheCampaign() = runTest(dispatcher) {
        val model = CampaignWizardViewModel(repository, source, SavedStateHandle(mapOf(CampaignWizardViewModel.KEY_EVENT to 0L)))
        advanceUntilIdle()
        assertTrue(model.uiState.value.start is Loadable.Ready)

        source.failNext = ApiException(422, ApiProblem(code = "VALIDATION_FAILED", fields = mapOf("venue_id" to listOf("Il campo locale è obbligatorio."))))
        model.next()
        advanceUntilIdle()
        assertEquals(1, model.uiState.value.step)
        assertEquals("Il campo locale è obbligatorio.", model.uiState.value.errors.field("venue_id"))

        model.openPicker(WizardPicker.Sponsor)
        advanceUntilIdle()
        model.chooseSponsor(model.uiState.value.sponsors.single())
        assertEquals("Circolo Aurora", model.uiState.value.draft.advertiserName)
        assertEquals("aurora@example.test", model.uiState.value.draft.advertiserEmail)
        assertEquals(3L, model.uiState.value.draft.venueId)

        repeat(3) { model.next(); advanceUntilIdle() }
        assertEquals(4, model.uiState.value.step)
        assertEquals(listOf("1", "2", "3"), source.steps.filterNotNull())

        model.next()
        advanceUntilIdle()
        assertEquals(WizardCreated(kind = "sponsorship", id = 11, message = "Sponsorizzazione creata."), model.uiState.value.created)
        assertTrue(model.previous())
        assertEquals(3, model.uiState.value.step)
    }

    @Test fun theMonthsOfTheWizardTakeTheEndAndThePriceFromTheServer() = runTest(dispatcher) {
        val model = CampaignWizardViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        model.choosePlacement("home_hero")
        advanceUntilIdle()
        assertEquals("2026-12-21T09:00:00+01:00", model.uiState.value.draft.endsAt)
        assertEquals("120.00", model.uiState.value.draft.amount)
        /* Una collocazione solo a periodo spegne «a visualizzazioni». */
        model.chooseProduct("views")
        advanceUntilIdle()
        assertEquals("period", model.uiState.value.draft.product)
        model.chooseEnd("2026-12-24T09:00:00+01:00")
        assertEquals("", model.uiState.value.draft.months)
    }

    @Test fun ordersAreApprovedWithTheSettlementAndRejectedWithTheReason() = runTest(dispatcher) {
        val model = OrderListViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        val order = model.list.current.value.items.single()
        model.open(order, "approve_request")
        assertEquals("150.00", (model.uiState.value.dialog as OrderDecisionDialog.ApproveRequest).settlement.amount)
        model.editSettlement { it.copy(method = "cash", reference = "Ricevuta 4") }
        model.approve()
        advanceUntilIdle()
        assertNull(model.uiState.value.dialog)
        assertEquals("Ordine approvato: abilitazione creata.", model.uiState.value.message)
        val body = source.bodies.last()
        assertEquals("paid", body["settlement"]?.jsonPrimitive?.content)
        assertEquals("cash", body["payment_method"]?.jsonPrimitive?.content)
        assertEquals("150.00", body["amount"]?.jsonPrimitive?.content)

        model.open(order, "reject")
        model.reject("Fuori tema")
        advanceUntilIdle()
        assertEquals("reject:5:Fuori tema", source.actions.last())
    }

    @Test fun theGrantFormEmptiesThePaymentWhenFreeAndMovesTheEndWithTheMonths() = runTest(dispatcher) {
        val model = GrantFormViewModel(repository, source, SavedStateHandle(mapOf(GrantFormViewModel.KEY_ID to 0L)))
        advanceUntilIdle()
        model.edit { it.copy(startsAt = "2026-01-31T10:00:00+01:00", amount = "100", paymentMethod = "Bonifico") }
        model.chooseMonths("1")
        assertEquals("2026-02-28T10:00:00+01:00", model.uiState.value.draft?.endsAt)
        model.chooseComplimentary(true)
        assertEquals("", model.uiState.value.draft?.amount)
        assertEquals("", model.uiState.value.draft?.paymentMethod)
        model.openPicker()
        advanceUntilIdle()
        model.chooseSponsor(model.uiState.value.sponsors.single())
        model.save()
        advanceUntilIdle()
        val body = source.bodies.last()
        assertEquals("3", body["venue_id"]?.jsonPrimitive?.content)
        assertEquals("true", body["complimentary"]?.jsonPrimitive?.content)
        assertFalse("amount" in body)
        assertEquals(21L, model.grantId)
    }

    @Test fun aNewPriceIsSavedAndTheListIsReadAgain() = runTest(dispatcher) {
        val model = PriceListViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        model.create()
        model.edit { it.copy(months = "3", amount = "450,00") }
        model.save()
        advanceUntilIdle()
        assertNull(model.uiState.value.editing)
        assertEquals("450.00", source.bodies.last()["amount"]?.jsonPrimitive?.content)
        assertEquals(2, source.priceReads)
    }

    @Test fun bannersSaveBothSwitches() = runTest(dispatcher) {
        val model = BannersViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        model.setAndroid(true)
        model.save()
        advanceUntilIdle()
        assertEquals("banners:false:true", source.actions.last())
        assertEquals("Impostazioni salvate", model.uiState.value.message)
    }
}

internal class FakePromotion : EditorialPromotionSource {
    val listParams = mutableListOf<List<Pair<String, String>>>()
    val actions = mutableListOf<String>()
    val bodies = mutableListOf<JsonObject>()
    val steps = mutableListOf<String?>()
    var failNext: Exception? = null
    var onGrant = false
    var priceReads = 0
    private var paused = false

    private fun fail() { failNext?.let { failNext = null; throw it } }
    private fun row(id: Long) = CampaignRow(id = id, advertiserName = "Birrificio", status = ValueLabel(if (paused && id == 7L) "paused" else "active", "Attiva"), abilities = JsonObject(mapOf("toggle" to JsonPrimitive(true))))
    private val choices = PromoChoices(placements = listOf(PromoChoice("list_top", "Collocazione in cima")), months = listOf(PromoChoice("1", "1 mese")))

    override suspend fun campaigns(params: List<Pair<String, String>>): CampaignList { listParams += params; return CampaignList(listOf(row(7), row(8)), PageMeta(total = 2), choices) }
    override suspend fun exportCampaigns(params: List<Pair<String, String>>, target: File) = DownloadedFile(target, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
    override suspend fun campaign(id: Long) = CampaignDetail(id = id, onGrant = onGrant, form = CampaignForm(eventId = 3, placement = "list_top", status = "active", advertiserName = "Birrificio"))
    override suspend fun updateCampaign(id: Long, body: JsonObject): CampaignDetail { fail(); bodies += body; return campaign(id) }
    override suspend fun toggleCampaign(id: Long): CampaignToggle { fail(); actions += "toggle:$id"; paused = true; return CampaignToggle(row(id), "Campagna sospesa.") }
    override suspend fun deleteCampaign(id: Long) { fail(); actions += "delete:$id" }
    override suspend fun deleteCampaigns(ids: List<Long>): DeletedIds { fail(); actions += "bulk:$ids"; return DeletedIds(ids) }
    override suspend fun sponsors(type: String, q: String, all: Boolean) = listOf(SponsorChoice(3, "Circolo Aurora", "Circolo Aurora", 1, "aurora@example.test", null))
    override suspend fun events(params: List<Pair<String, String>>) = EventChoices(listOf(PromoEvent(id = 9, title = "Jazz in cortile")), EventChoicesMeta(1, true))
    override suspend fun prefill(eventId: Long) = WizardPrefill(startsAt = "2026-11-21T09:00:00+01:00", endsAt = "2026-12-21T09:00:00+01:00", choices = choices)
    override suspend fun estimate(body: JsonObject): WizardPreview {
        fail()
        steps += body["step"]?.jsonPrimitive?.content
        val heroOnlyPeriod = body["placement"]?.jsonPrimitive?.content == "home_hero"
        return WizardPreview(endsAt = "2026-12-21T09:00:00+01:00", price = WizardPrice("120.00", "120,00 €"), periodOnly = if (heroOnlyPeriod) "Solo a periodo" else null)
    }
    override suspend fun createCampaign(body: JsonObject): WizardCreated { bodies += body; return WizardCreated("sponsorship", 11, "Sponsorizzazione creata.") }
    override suspend fun orders(params: List<Pair<String, String>>) = OrderList(listOf(OrderRow(id = 5, sponsor = "Circolo Aurora", suggestedAmount = "150.00")), PageMeta(total = 1))
    override suspend fun approveOrder(id: Long, body: JsonObject): OrderDecision { bodies += body; actions += "approve:$id"; return OrderDecision(OrderRow(id = id), "Ordine approvato: abilitazione creata.") }
    override suspend fun rejectOrder(id: Long, reason: String): OrderDecision { actions += "reject:$id:$reason"; return OrderDecision(OrderRow(id = id), "Ordine rifiutato.") }
    override suspend fun grants(params: List<Pair<String, String>>) = GrantList(emptyList(), PageMeta(), choices)
    override suspend fun grant(id: Long) = GrantDetail(id = id, form = GrantForm(venueId = 3, venueLabel = "Circolo Aurora", complimentary = true))
    override suspend fun createGrant(body: JsonObject): GrantDetail { bodies += body; return grant(21) }
    override suspend fun updateGrant(id: Long, body: JsonObject): GrantDetail { bodies += body; return grant(id) }
    override suspend fun prices(params: List<Pair<String, String>>): PriceList { priceReads++; return PriceList(emptyList(), PageMeta(), choices) }
    override suspend fun createPrice(body: JsonObject): PriceRow { bodies += body; return PriceRow(id = 1) }
    override suspend fun updatePrice(id: Long, body: JsonObject): PriceRow { bodies += body; return PriceRow(id = id) }
    override suspend fun deletePrice(id: Long) { actions += "price-delete:$id" }
    override suspend fun banners() = BannerSettings(webEnabled = false, androidEnabled = false)
    override suspend fun saveBanners(web: Boolean, android: Boolean): BannerSaved { actions += "banners:$web:$android"; return BannerSaved(BannerSettings(web, android), "Impostazioni salvate") }
}
