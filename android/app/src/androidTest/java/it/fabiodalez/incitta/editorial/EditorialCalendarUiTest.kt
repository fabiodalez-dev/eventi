package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.BellAction
import it.fabiodalez.incitta.data.editorial.BellItem
import it.fabiodalez.incitta.data.editorial.BellMeta
import it.fabiodalez.incitta.data.editorial.BellPage
import it.fabiodalez.incitta.data.editorial.CalendarAbilities
import it.fabiodalez.incitta.data.editorial.CalendarCity
import it.fabiodalez.incitta.data.editorial.CalendarItem
import it.fabiodalez.incitta.data.editorial.CalendarLabel
import it.fabiodalez.incitta.data.editorial.CalendarLegendEntry
import it.fabiodalez.incitta.data.editorial.CalendarMeta
import it.fabiodalez.incitta.data.editorial.CalendarWindow
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.calendar.BellUiState
import it.fabiodalez.incitta.ui.editorial.calendar.CalendarUiState
import it.fabiodalez.incitta.ui.editorial.calendar.EditorialBellContent
import it.fabiodalez.incitta.ui.editorial.calendar.EditorialCalendarContent
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R14 su un'attività vuota: il mese con le date del giorno scelto (toccare
 * una data apre la scheda dell'evento), la legenda, la campanella con le sue
 * azioni e la conferma prima di svuotarla. I testi si leggono dalle risorse.
 */
class EditorialCalendarUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private val items = listOf(
        CalendarItem(id = 1, eventId = 41, title = "Notte al Portello · Portello", eventTitle = "Notte al Portello", city = CalendarCity(1, "Padova"), date = "2027-03-12",
            startsAt = "2027-03-12T21:00:00+01:00", endsAt = "2027-03-12T23:30:00+01:00", eventStatus = CalendarLabel("pending", "In attesa"),
            status = CalendarLabel("scheduled", "In programma"), color = "#b45309", abilities = CalendarAbilities(open = true)),
        CalendarItem(id = 2, eventId = 42, title = "Mercato", eventTitle = "Mercato", city = CalendarCity(2, "Vicenza"), date = "2027-03-20", allDay = true,
            eventStatus = CalendarLabel("published", "Pubblicato"), status = CalendarLabel("cancelled", "Annullata"), color = "#7a9900", abilities = CalendarAbilities(open = true)),
    )

    @Test fun theDayShowsItsDatesAndATapOpensTheEvent() {
        val opened = CopyOnWriteArrayList<Long>()
        val picked = CopyOnWriteArrayList<String>()
        val window = CalendarWindow(items, CalendarMeta(legend = listOf(CalendarLegendEntry("pending", "In attesa", "#b45309"))))
        show {
            EditorialCalendarContent(CalendarUiState("2027-03", "2027-03-12", Loadable.Ready(window)), PaddingValues(), onBack = {}, onRetry = {},
                onShift = {}, onToday = {}, onPick = { picked += it }, onOpenEvent = { opened += it })
        }
        compose.onNodeWithText("Marzo 2027").assertIsDisplayed()
        compose.onNodeWithText("Notte al Portello · Portello").assertIsDisplayed()
        compose.onNodeWithText(text(R.string.editorial_calendar_item_meta, "21:00–23:30", "Padova")).assertExists()
        compose.onNodeWithText("Mercato").assertDoesNotExist()
        compose.onNodeWithText("Notte al Portello · Portello").performClick()
        compose.runOnIdle { assertEquals(listOf(41L), opened) }
        compose.onNodeWithText(text(R.string.editorial_calendar_legend)).performScrollTo().assertIsDisplayed()
        compose.onNodeWithText(text(R.string.editorial_calendar_today)).assertHasClickAction()
    }

    @Test fun anEmptyDaySaysSoAndACancelledDateIsMarked() {
        show {
            EditorialCalendarContent(CalendarUiState("2027-03", "2027-03-20", Loadable.Ready(CalendarWindow(items))), PaddingValues(), onBack = {}, onRetry = {},
                onShift = {}, onToday = {}, onPick = {}, onOpenEvent = {})
        }
        compose.onNodeWithText(text(R.string.editorial_calendar_date_status, "Annullata")).assertExists()
        compose.onNodeWithText(text(R.string.editorial_calendar_item_meta, text(R.string.editorial_calendar_all_day), "Vicenza")).assertExists()
        show {
            EditorialCalendarContent(CalendarUiState("2027-03", "2027-03-02", Loadable.Ready(CalendarWindow(items))), PaddingValues(), onBack = {}, onRetry = {},
                onShift = {}, onToday = {}, onPick = {}, onOpenEvent = {})
        }
        compose.onNodeWithText(text(R.string.editorial_calendar_day_empty)).assertExists()
    }

    @Test fun theBellOpensANotificationAndAsksBeforeClearing() {
        val opened = CopyOnWriteArrayList<String>()
        var cleared = 0
        val page = BellPage(
            listOf(BellItem(id = "a", title = "Una sponsorizzazione in ritardo", body = "Caffè Pedrocchi · Home (in ritardo).", status = "warning",
                url = "https://eventi.test/admin/sponsorships", actions = listOf(BellAction("Apri le sponsorizzazioni", "https://eventi.test/admin/sponsorships")))),
            BellMeta(total = 1, unread = 1),
        )
        show {
            EditorialBellContent(BellUiState(Loadable.Ready(page)), PaddingValues(), ZoneId.of("Europe/Rome"), onBack = {}, onRetry = {},
                onOpen = { opened += it.id }, onRead = {}, onUnread = {}, onRemove = {}, onReadAll = {}, onClear = { cleared++ }, onDismissMessage = {})
        }
        compose.onNodeWithText(text(R.string.editorial_bell_unread_mark)).assertExists()
        compose.onNodeWithText("Apri le sponsorizzazioni").performClick()
        compose.runOnIdle { assertEquals(listOf("a"), opened) }
        compose.onNodeWithText(text(R.string.editorial_bell_read_all)).assertHasClickAction()
        compose.onNodeWithText(text(R.string.editorial_bell_clear)).performClick()
        compose.onNodeWithText(text(R.string.editorial_bell_clear_title)).assertIsDisplayed()
        compose.onAllNodesWithText(text(R.string.editorial_bell_clear)).filterToOne(hasAnyAncestor(isDialog())).performClick()
        compose.runOnIdle { assertEquals(1, cleared) }
    }

    @Test fun anEmptyBellSaysSo() {
        show {
            EditorialBellContent(BellUiState(Loadable.Empty), PaddingValues(), ZoneId.of("Europe/Rome"), onBack = {}, onRetry = {},
                onOpen = {}, onRead = {}, onUnread = {}, onRemove = {}, onReadAll = {}, onClear = {}, onDismissMessage = {})
        }
        compose.onNodeWithText(text(R.string.editorial_bell_empty)).assertExists()
    }
}
