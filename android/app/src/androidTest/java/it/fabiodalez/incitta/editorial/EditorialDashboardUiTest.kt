package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.DashboardChart
import it.fabiodalez.incitta.data.editorial.DashboardEmpty
import it.fabiodalez.incitta.data.editorial.DashboardLink
import it.fabiodalez.incitta.data.editorial.DashboardRegistration
import it.fabiodalez.incitta.data.editorial.DashboardRegistrationState
import it.fabiodalez.incitta.data.editorial.DashboardSection
import it.fabiodalez.incitta.data.editorial.DashboardSeries
import it.fabiodalez.incitta.data.editorial.DashboardStat
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialCity
import it.fabiodalez.incitta.data.editorial.EditorialDashboard
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.dashboard.DashboardScreen
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Il riepilogo della redazione (R1) su un'attività vuota: le sezioni che il
 * server manda, i riquadri che aprono l'elenco filtrato, le iscrizioni che
 * si aprono solo per chi può modificarle, il vuoto senza città.
 */
class EditorialDashboardUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private val padova = EditorialCity(1, "padova", "Padova", isActive = true)
    private val vicenza = EditorialCity(2, "vicenza", "Vicenza")
    private val pendingTarget = DashboardTarget("events", filters = JsonObject(mapOf("status" to JsonPrimitive("pending"))), webPath = "admin/events?filters%5Bstatus%5D%5Bvalue%5D=pending")

    private val dashboard = EditorialDashboard(
        city = padova,
        sections = listOf(
            DashboardSection(
                key = DashboardSection.QUEUE, title = "Da lavorare adesso", description = "Tutto ciò che aspetta una decisione della redazione.",
                stats = listOf(
                    DashboardStat("pending_events", "Eventi in attesa", "2", JsonPrimitive(2), "2 eventi aspettano che tu li pubblichi o li rifiuti.", "warning", pendingTarget),
                    DashboardStat("open_reports", "Segnalazioni aperte", "0", JsonPrimitive(0), "Nessuna segnalazione da guardare.", "gray",
                        DashboardTarget("reports", filters = JsonObject(mapOf("open" to JsonPrimitive("1"))), webPath = "admin/reports")),
                ),
            ),
            DashboardSection(
                key = DashboardSection.PUBLISHING, title = "Pubblicazione",
                stats = listOf(DashboardStat("scheduled_today", "In programma oggi", "7", JsonPrimitive(7), "Date in cartellone nella giornata evento corrente.")),
            ),
            DashboardSection(
                key = DashboardSection.REGISTRATIONS, title = "Nuove iscrizioni", description = "2 iscrizioni negli ultimi 7 giorni · una in attesa di conferma",
                links = listOf(
                    DashboardLink("pending", "Solo in attesa", 1, DashboardTarget("users", tab = "pending", webPath = "admin/users?tab=pending")),
                    DashboardLink("all", "Tutti gli utenti", null, DashboardTarget("users", webPath = "admin/users")),
                ),
                rows = listOf(
                    DashboardRegistration(9, "Ada Lovelace", "ada@example.test", DashboardRegistrationState("pending", "In attesa di conferma", tone = "warning"), "2026-11-26T10:05:00+00:00", JsonObject(mapOf("update" to JsonPrimitive(true))), "admin/users/9/edit"),
                    DashboardRegistration(8, "bob@example.test", "Senza nome", DashboardRegistrationState("confirmed", "Confermato", tone = "success"), null, JsonObject(mapOf("update" to JsonPrimitive(false))), null),
                ),
                empty = DashboardEmpty("Ancora nessun iscritto", "Qui compaiono le persone appena si registrano."),
            ),
            DashboardSection(
                key = DashboardSection.SPONSORSHIP_TREND, title = "Andamento degli ultimi 30 giorni · Padova", description = "La distanza fra le due linee…",
                chart = DashboardChart(listOf("2026-11-25", "2026-11-26"), listOf("25 nov", "26 nov"), listOf(DashboardSeries("impressions", "Visualizzazioni", listOf(10L, 4L)), DashboardSeries("clicks", "Aperture", listOf(1L, 0L)))),
            ),
        ),
    )

    private val desk = EditorialDesk(role = EditorialDesk.ROLE_MODERATOR, city = padova, cities = listOf(padova, vicenza))

    private fun show(state: Loadable<EditorialDashboard>, targets: MutableList<DashboardTarget> = mutableListOf(), rows: MutableList<Long> = mutableListOf(), cities: MutableList<String> = mutableListOf()) {
        compose.runOnIdle {
            compose.activity.setContent {
                InCittaTheme {
                    Surface {
                        DashboardScreen(
                            state = state, desk = desk, busy = false, padding = PaddingValues(),
                            onBack = {}, onRetry = {}, onRefresh = {}, onCity = { cities += it },
                            onTarget = { targets += it }, onRegistration = { rows += it.id },
                        )
                    }
                }
            }
        }
    }

    @Test fun showsTheSectionsAndATileOpensItsFilteredList() {
        val targets = CopyOnWriteArrayList<DashboardTarget>()
        show(Loadable.Ready(dashboard), targets)
        compose.onNodeWithText(text(R.string.editorial_dashboard_title)).assertExists()
        compose.onNodeWithText("Da lavorare adesso").assertExists()
        compose.onNodeWithText("Pubblicazione").assertExists()
        compose.onNodeWithContentDescription("2, Eventi in attesa", substring = true).performClick()
        compose.runOnIdle { assertEquals(listOf(pendingTarget), targets.toList()) }
        /* «In programma oggi» non apre un elenco, come nel pannello. */
        compose.onNodeWithContentDescription("7, In programma oggi", substring = true).assertHasNoClickAction()
    }

    @Test fun registrationsOpenOnlyForWhoCanEditThem() {
        val targets = CopyOnWriteArrayList<DashboardTarget>()
        val rows = CopyOnWriteArrayList<Long>()
        show(Loadable.Ready(dashboard), targets, rows)
        compose.onNodeWithText("Nuove iscrizioni").performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.editorial_dashboard_link_badge, "Solo in attesa", 1)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals("pending", targets.single().tab) }
        compose.onNodeWithContentDescription("Ada Lovelace", substring = true).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf(9L), rows.toList()) }
        compose.onNodeWithContentDescription("bob@example.test", substring = true).performScrollTo().assertHasNoClickAction()
        compose.onNodeWithText("Andamento degli ultimi 30 giorni · Padova").performScrollTo().assertExists()
    }

    @Test fun withoutACityOnlyTheRegistrationsRemainAndTheCityCanBeChanged() {
        val cities = CopyOnWriteArrayList<String>()
        show(Loadable.Ready(EditorialDashboard(city = null, sections = dashboard.sections.filter { it.key == DashboardSection.REGISTRATIONS })), cities = cities)
        compose.onNodeWithText(text(R.string.editorial_dashboard_no_city)).assertExists()
        compose.onNodeWithText("Da lavorare adesso").assertDoesNotExist()
        compose.onNodeWithContentDescription(text(R.string.editorial_city_switch)).performClick()
        compose.onNodeWithText("Vicenza", substring = true).performClick()
        compose.runOnIdle { assertEquals(listOf("vicenza"), cities.toList()) }
    }

    @Test fun aFailureOffersRetry() {
        show(Loadable.Failed("Connessione assente"))
        compose.onNodeWithText("Connessione assente").assertExists()
        compose.onNodeWithText(text(R.string.management_retry)).assertExists()
    }
}
