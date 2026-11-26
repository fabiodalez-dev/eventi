package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.BannerSettings
import it.fabiodalez.incitta.data.editorial.CampaignDetail
import it.fabiodalez.incitta.data.editorial.CampaignForm
import it.fabiodalez.incitta.data.editorial.CampaignRow
import it.fabiodalez.incitta.data.editorial.OrderRow
import it.fabiodalez.incitta.data.editorial.PriceRow
import it.fabiodalez.incitta.data.editorial.PromoChoice
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.PromoPhase
import it.fabiodalez.incitta.data.editorial.PromoProgress
import it.fabiodalez.incitta.data.editorial.PromoProgressDetail
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.promotion.BannersContent
import it.fabiodalez.incitta.ui.editorial.promotion.BannersUiState
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignConfirm
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignDetailContent
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignDraft
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignListContent
import it.fabiodalez.incitta.ui.editorial.promotion.CampaignListUiState
import it.fabiodalez.incitta.ui.editorial.promotion.OrderListContent
import it.fabiodalez.incitta.ui.editorial.promotion.OrderListUiState
import it.fabiodalez.incitta.ui.editorial.promotion.PriceListContent
import it.fabiodalez.incitta.ui.management.common.FormErrors
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R8 — le schermate principali della promozione su un'attività vuota:
 * l'elenco delle campagne con il menu della riga secondo `abilities` e la
 * selezione per eliminare in gruppo, la scheda con l'avanzamento e il
 * modulo, gli ordini con le decisioni permesse, il listino, i banner. I
 * testi si leggono dalle risorse.
 */
class EditorialPromotionUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun abilities(vararg allowed: String) = buildJsonObject { allowed.forEach { put(it, true) } }

    private val choices = PromoChoices(
        placements = listOf(PromoChoice("list_top", "In cima alla lista")),
        statuses = listOf(PromoChoice("active", "Attiva"), PromoChoice("paused", "Sospesa")),
        pacings = listOf(PromoChoice("even", "Regolare")),
    )

    @Test fun theCampaignListShowsTheAllowedActionsAndDeletesTheSelectedRows() {
        val asked = CopyOnWriteArrayList<CampaignConfirm>()
        val selected = CopyOnWriteArrayList<Long>()
        val rows = listOf(
            CampaignRow(id = 7, eventGone = "Jazz in cortile", advertiserName = "Birrificio", phase = PromoPhase("running", "In corso", "success"),
                placement = ValueLabel("list_top", "In cima alla lista"), status = ValueLabel("active", "Attiva"), toggleLabel = "Sospendi",
                progress = PromoProgress(stateLabel = "A periodo", summary = "12 visualizzazioni"), abilities = abilities("update", "toggle", "destroy")),
        )
        var ui by androidx.compose.runtime.mutableStateOf(CampaignListUiState(choices = choices))
        show {
            CampaignListContent(
                list = PagedState(query = ListQuery(), items = rows, page = 1, hasMore = false, total = 1),
                ui = ui, padding = PaddingValues(), onBack = {}, onSearch = {}, onFilters = {}, onLoadMore = {}, onRetry = {},
                onOpen = {}, onNew = {}, onExport = {},
                onToggleSelecting = { ui = ui.copy(selecting = !ui.selecting) },
                onSelect = { id -> selected += id; ui = ui.copy(selected = ui.selected + id) },
                onAsk = { asked += it },
            )
        }
        compose.onNodeWithText(text(R.string.editorial_promo_campaigns_title)).assertIsDisplayed()
        compose.onNodeWithText("In corso").assertIsDisplayed()
        compose.onNodeWithText("12 visualizzazioni").assertIsDisplayed()

        compose.onNodeWithContentDescription(compose.activity.getString(R.string.editorial_actions_for, "Jazz in cortile")).performClick()
        compose.onNodeWithText("Sospendi").performClick()
        compose.runOnIdle { assertEquals(CampaignConfirm.Toggle(7, "Sospendi"), asked.single()) }

        compose.onNodeWithText(text(R.string.editorial_promo_select)).performClick()
        compose.onNodeWithText("Birrificio", substring = true).performClick()
        compose.onNodeWithText(text(R.string.editorial_promo_delete_selected, 1)).performClick()
        compose.runOnIdle {
            assertEquals(listOf(7L), selected)
            assertEquals(CampaignConfirm.DeleteSelected(listOf(7L)), asked.last())
        }
    }

    @Test fun theCampaignDetailShowsTheProgressAndLocksTheDeliveryOfAGrantRow() {
        val detail = CampaignDetail(
            id = 7, eventGone = "Jazz in cortile", phase = PromoPhase("running", "In corso", "success"), toggleLabel = "Sospendi", onGrant = true,
            grantHelp = "Questa riga fa parte di un'abilitazione.", choices = choices,
            form = CampaignForm(placement = "list_top", status = "active", advertiserName = "Birrificio"),
            progressDetail = PromoProgressDetail(progress = PromoProgress(stateLabel = "A periodo", summary = "12 visualizzazioni"), sentence = "12 visualizzazioni finora."),
            abilities = abilities("update", "toggle", "destroy"),
        )
        val actions = CopyOnWriteArrayList<String>()
        show {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                CampaignDetailContent(detail, CampaignDraft.from(detail), FormErrors(fields = mapOf("ends_at" to "La fine deve venire dopo l'inizio.")), busy = false, message = null,
                    onEdit = {}, onSave = { actions += "save" }, onPickEvent = { actions += "pick" }, onToggle = { actions += "toggle" }, onDelete = { actions += "delete" }, onAnalytics = {})
            }
        }
        compose.onNodeWithText("12 visualizzazioni finora.").assertIsDisplayed()
        compose.onNodeWithText("Questa riga fa parte di un'abilitazione.").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText(text(R.string.editorial_promo_event_change)).assertDoesNotExist()
        compose.onNodeWithText("La fine deve venire dopo l'inizio.").performScrollTo().assertIsDisplayed()
        compose.onNodeWithText("Sospendi").performClick()
        compose.onNodeWithText(text(R.string.editorial_promo_save)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("toggle", "save"), actions) }
    }

    @Test fun ordersOfferOnlyTheDecisionsTheServerAllows() {
        val opened = CopyOnWriteArrayList<String>()
        val rows = listOf(
            OrderRow(id = 5, sponsor = "Circolo Aurora", sponsorKind = "Locale", eventLabel = "Tutti gli eventi", status = PromoPhase("requested", "Richiesta, da approvare", "warning"),
                abilities = abilities("approve_request", "reject")),
        )
        show {
            OrderListContent(PagedState(query = ListQuery(), items = rows, page = 1, hasMore = false, total = 1), OrderListUiState(), PaddingValues(), {}, {}, {}, {}, {}) { order, action -> opened += "${order.id}:$action" }
        }
        compose.onNodeWithText("Richiesta, da approvare").assertIsDisplayed()
        compose.onNodeWithContentDescription(compose.activity.getString(R.string.editorial_actions_for, "Circolo Aurora")).performClick()
        compose.onAllNodesWithText(text(R.string.editorial_promo_order_approve)).assertCountEquals(1)
        compose.onNodeWithText(text(R.string.editorial_promo_order_reject)).performClick()
        compose.runOnIdle { assertEquals(listOf("5:reject"), opened) }
    }

    @Test fun thePriceListOpensARowAndCreatesANewOne() {
        val events = CopyOnWriteArrayList<String>()
        show {
            PriceListContent(
                PagedState(query = ListQuery(), items = listOf(PriceRow(id = 1, placement = ValueLabel("home_hero", "Apertura della home"), months = 3, amountLabel = "450,00 €")), page = 1, hasMore = false, total = 1),
                PaddingValues(), {}, {}, {}, {}, {}, onOpen = { events += "open:${it.id}" }, onNew = { events += "new" },
            )
        }
        compose.onNodeWithText(text(R.string.editorial_promo_price_months, 3)).assertIsDisplayed()
        compose.onNodeWithText("450,00 €").performClick()
        compose.onNodeWithText(text(R.string.editorial_promo_price_new)).performClick()
        compose.runOnIdle { assertEquals(listOf("open:1", "new"), events) }
    }

    @Test fun theBannerSwitchesAreSaved() {
        val events = CopyOnWriteArrayList<String>()
        show {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                BannersContent(BannerSettings(lead = "I banner orizzontali.", rules = "Regole.", switchOff = "Spegnere."), BannersUiState(web = true, android = false),
                    onWeb = { events += "web:$it" }, onAndroid = { events += "android:$it" }, onSave = { events += "save" })
            }
        }
        compose.onNodeWithText("I banner orizzontali.").assertIsDisplayed()
        compose.onNodeWithText(text(R.string.editorial_promo_banners_android)).assertIsDisplayed()
        compose.onNodeWithText(text(R.string.editorial_promo_banners_save)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("save"), events) }
    }
}
