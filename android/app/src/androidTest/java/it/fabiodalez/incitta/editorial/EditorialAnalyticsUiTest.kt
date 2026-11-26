package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.SearchMissCity
import it.fabiodalez.incitta.data.editorial.SearchMissRow
import it.fabiodalez.incitta.data.editorial.SeoAuditInfo
import it.fabiodalez.incitta.data.editorial.SeoAuditRow
import it.fabiodalez.incitta.data.editorial.SeoCity
import it.fabiodalez.incitta.data.editorial.SeoPage
import it.fabiodalez.incitta.data.editorial.SeoSearchConsole
import it.fabiodalez.incitta.data.editorial.SeoTab
import it.fabiodalez.incitta.data.management.AnalyticsCard
import it.fabiodalez.incitta.data.management.AnalyticsColumn
import it.fabiodalez.incitta.data.management.AnalyticsPageMeta
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsSubjectHeader
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.AnalyticsTableRow
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import it.fabiodalez.incitta.data.management.ReportDataset
import it.fabiodalez.incitta.data.management.SubjectLink
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsHub
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsSection
import it.fabiodalez.incitta.ui.editorial.analytics.EditorialReport
import it.fabiodalez.incitta.ui.editorial.analytics.EditorialReportUiState
import it.fabiodalez.incitta.ui.editorial.analytics.EditorialTable
import it.fabiodalez.incitta.ui.editorial.analytics.EditorialTableUiState
import it.fabiodalez.incitta.ui.editorial.analytics.SearchMissesList
import it.fabiodalez.incitta.ui.editorial.analytics.SearchMissesUiState
import it.fabiodalez.incitta.ui.editorial.analytics.SeoContent
import it.fabiodalez.incitta.ui.editorial.analytics.SeoUiState
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali di R10 su un'attività vuota: il menu dell'area per
 * ruolo, «Statistiche in dettaglio» con i riquadri e le tabelle, una tabella
 * con i collegamenti, il modulo di Search Console con le regole del campo, le
 * ricerche senza risultati con «Elimina» e la scelta di più righe. I testi si
 * leggono dalle risorse.
 */
class EditorialAnalyticsUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    @Test fun theModeratorSeesOnlyTheSearchMisses() {
        val opened = CopyOnWriteArrayList<AnalyticsSection>()
        val moderator = EditorialDesk(role = "moderator", permissions = mapOf("analytics_view" to false, "search_misses_manage" to true))
        show { AnalyticsHub(moderator, "Statistiche e ricerche", PaddingValues(), onBack = {}, onOpen = { opened += it }) }
        compose.onNodeWithText(text(R.string.editorial_analytics_report_title)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_analytics_seo_title)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_analytics_misses_title)).performClick()
        compose.runOnIdle { assertEquals(listOf(AnalyticsSection.SearchMisses), opened) }
    }

    @Test fun theAdminSeesTheThreePagesInThePanelOrder() {
        val admin = EditorialDesk(role = "admin", permissions = mapOf("analytics_view" to true, "search_misses_manage" to true))
        show { AnalyticsHub(admin, "Statistiche e ricerche", PaddingValues(), onBack = {}, onOpen = {}) }
        compose.onNodeWithText(text(R.string.editorial_analytics_report_title)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_analytics_seo_title)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_analytics_misses_title)).assertExists()
    }

    @Test fun theReportShowsTheCardsAndOpensATable() {
        val tables = CopyOnWriteArrayList<String>()
        val report = AnalyticsReport(
            period = "month", from = "2026-08-12", until = "2026-09-10",
            cards = listOf(AnalyticsCard("views", "Visite all’evento", 110, "Quante volte è stata aperta la pagina")),
            datasets = listOf(ReportDataset("venues", "Locali", 2)),
        )
        show {
            EditorialReport(EditorialReportUiState(report = Loadable.Ready(report)), PaddingValues(), onBack = {}, onRetry = {}, onPeriod = {}, onFilters = {},
                onReset = {}, onOpen = {}, onOverview = {}, onOtherEvent = {}, onTable = { tables += it }, onExport = {})
        }
        compose.onNodeWithText(text(R.string.editorial_analytics_report_title)).assertExists()
        compose.onNodeWithText(text(R.string.analytics_back_overview)).assertDoesNotExist()
        // Il testo sta dentro la scheda del numero, che ne unisce i figli: si cerca nell'albero non unito.
        compose.onNodeWithText("Visite all’evento", substring = true, useUnmergedTree = true).assertExists()
        compose.onNodeWithText(text(R.string.analytics_dataset_count, "Locali", 2)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("venues"), tables) }
    }

    @Test fun theSubjectPageOffersTheWayBackToAllEvents() {
        val back = CopyOnWriteArrayList<Boolean>()
        val report = AnalyticsReport(subject = AnalyticsSubjectHeader(type = "venue", id = 4, name = "Circolo Aurora", title = "Statistiche: Circolo Aurora"))
        show {
            EditorialReport(EditorialReportUiState(target = AnalyticsTarget("venue", 4), report = Loadable.Ready(report)), PaddingValues(), onBack = {}, onRetry = {},
                onPeriod = {}, onFilters = {}, onReset = {}, onOpen = {}, onOverview = { back += true }, onOtherEvent = {}, onTable = {}, onExport = {})
        }
        compose.onNodeWithText("Statistiche: Circolo Aurora").assertExists()
        compose.onNodeWithText(text(R.string.analytics_reset_detail)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.analytics_back_overview)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf(true), back) }
    }

    @Test fun aTableRowOpensTheVenue() {
        val opened = CopyOnWriteArrayList<SubjectLink>()
        val table = AnalyticsTable(
            dataset = "venues", label = "Locali",
            columns = listOf(AnalyticsColumn("name", "Nome"), AnalyticsColumn("event_views", "Visite", numeric = true)),
            rows = listOf(AnalyticsTableRow(listOf(JsonPrimitive("Circolo Aurora"), JsonPrimitive(10)), mapOf("name" to SubjectLink("venue", 4)))),
            meta = AnalyticsPageMeta(page = 1, nextPage = null, total = 1),
        )
        show { EditorialTable(EditorialTableUiState(table = Loadable.Ready(table)), PaddingValues(), onBack = {}, onRetry = {}, onPage = {}, onOpen = { opened += it }, onExport = {}) }
        compose.onNodeWithText("Circolo Aurora").performClick()
        compose.onNodeWithText(text(R.string.analytics_export_csv)).performScrollTo().assertExists()
        compose.runOnIdle { assertEquals(listOf(SubjectLink("venue", 4)), opened) }
    }

    @Test fun theSearchConsoleCodeIsCheckedLikeThePanelField() {
        val page = SeoPage(
            title = "SEO e qualità del catalogo",
            searchConsole = SeoSearchConsole(title = "Collega Google Search Console", codeLabel = "Codice di verifica HTML", saveLabel = "Salva codice di verifica",
                defaultCityId = 1, cities = listOf(SeoCity(1, "padova", "Padova", true, "abc"), SeoCity(2, "vicenza", "Vicenza", false))),
            audit = SeoAuditInfo(heading = "Controllo del catalogo", tabs = listOf(SeoTab("issues", "Da sistemare"), SeoTab("all", "Tutti"))),
        )
        val cities = CopyOnWriteArrayList<Long>()
        val saves = CopyOnWriteArrayList<Boolean>()
        val audit = PagedState(query = ListQuery(tab = "issues"), items = listOf(SeoAuditRow("k", "Concerto all’alba", "https://example.test/eventi/concerto", listOf("Manca la locandina."))), page = 1, hasMore = false, total = 1)
        show {
            SeoContent(SeoUiState(page = Loadable.Ready(page), cityId = 1, code = "<meta>"), audit, PaddingValues(), onBack = {}, onRetry = {},
                onCity = { cities += it }, onCode = {}, onSave = { saves += true }, onTab = {}, onLoadMore = {}, onRetryAudit = {}, onOpenUrl = {})
        }
        compose.onNodeWithText(text(R.string.editorial_seo_code_invalid)).assertExists()
        compose.onNodeWithText("Salva codice di verifica").assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.editorial_seo_inactive, "Vicenza")).performClick()
        compose.onNodeWithText("Concerto all’alba").performScrollTo().assertExists()
        compose.onNodeWithText("Manca la locandina.").assertExists()
        compose.runOnIdle {
            assertEquals(listOf(2L), cities)
            assertTrue(saves.isEmpty())
        }
    }

    @Test fun aSearchMissIsRemovedFromItsMenuAndSeveralCanBeChosen() {
        val actions = CopyOnWriteArrayList<String>()
        val remove = buildJsonObject { put("remove", JsonPrimitive(true)) }
        val row = SearchMissRow(id = 3, term = "burraco", count = 4, lastSearchedLabel = "09/09/2026 21:15", city = SearchMissCity(1, "Padova"), abilities = remove)
        val list = PagedState(query = ListQuery(), items = listOf(row), page = 1, hasMore = false, total = 1)
        show {
            SearchMissesList(list, SearchMissesUiState(selected = setOf(3L)), PaddingValues(), onBack = {}, onSearch = {}, onFilters = {}, onSort = {},
                onLoadMore = {}, onRetry = {}, onToggle = { actions += "toggle:${it.id}" }, onRemove = { actions += "remove:${it.id}" },
                onRemoveSelected = { actions += "bulk" }, onClearSelection = {}, onConfirm = {}, onDismiss = {})
        }
        compose.onNodeWithText("burraco").assertExists()
        compose.onNodeWithText(text(R.string.editorial_misses_count, "4")).assertExists()
        compose.onNodeWithText(text(R.string.editorial_misses_selected, 1)).assertExists()
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "burraco")).performClick()
        compose.onNodeWithText(text(R.string.editorial_misses_remove)).performClick()
        compose.onNodeWithText(text(R.string.editorial_misses_remove_selected)).performClick()
        compose.onNodeWithContentDescription(text(R.string.editorial_misses_select, "burraco")).performClick()
        compose.runOnIdle { assertEquals(listOf("remove:3", "bulk", "toggle:3"), actions) }
    }

    @Test fun theRemovalAsksForConfirmation() {
        val confirmed = CopyOnWriteArrayList<Boolean>()
        val row = SearchMissRow(id = 3, term = "burraco", count = 4)
        show {
            SearchMissesList(PagedState(items = listOf(row), page = 1, hasMore = false, total = 1), SearchMissesUiState(confirming = row), PaddingValues(),
                onBack = {}, onSearch = {}, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {}, onToggle = {}, onRemove = {},
                onRemoveSelected = {}, onClearSelection = {}, onConfirm = { confirmed += true }, onDismiss = {})
        }
        compose.onNodeWithText(text(R.string.editorial_misses_remove_title, "burraco")).assertExists()
        compose.onNodeWithText(text(R.string.editorial_misses_remove_text)).assertExists()
        compose.onAllNodesWithText(text(R.string.editorial_misses_remove)).onLast().performClick()
        compose.runOnIdle { assertEquals(listOf(true), confirmed) }
    }
}
