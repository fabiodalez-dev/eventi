package it.fabiodalez.incitta.ui.editorial.calendar

import androidx.compose.ui.graphics.Color
import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.BellChange
import it.fabiodalez.incitta.data.editorial.BellChangeMeta
import it.fabiodalez.incitta.data.editorial.BellItem
import it.fabiodalez.incitta.data.editorial.BellMeta
import it.fabiodalez.incitta.data.editorial.BellPage
import it.fabiodalez.incitta.data.editorial.CalendarItem
import it.fabiodalez.incitta.data.editorial.CalendarMeta
import it.fabiodalez.incitta.data.editorial.CalendarWindow
import it.fabiodalez.incitta.data.editorial.EditorialCalendarSource
import it.fabiodalez.incitta.data.editorial.EditorialLinkTarget
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.YearMonth
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R14 con un server finto: la griglia del mese, la finestra chiesta al server, la campanella con le sue azioni. */
@OptIn(ExperimentalCoroutinesApi::class)
class EditorialCalendarTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeCalendar : EditorialCalendarSource {
        val calls = mutableListOf<String>()
        var items = listOf(
            CalendarItem(id = 1, eventId = 41, title = "Concerto · Teatro", date = "2027-03-12", color = "#b45309"),
            CalendarItem(id = 2, eventId = 42, title = "Mostra", date = "2027-03-12"),
            CalendarItem(id = 3, eventId = 43, title = "Mercato", date = "2027-03-20"),
        )
        var bell = listOf(BellItem(id = "a", title = "Una sponsorizzazione in ritardo", url = "https://eventi.test/admin/sponsorships"), BellItem(id = "b", title = "Letta", read = true))
        var failNext: Exception? = null
        private fun maybeFail() { failNext?.let { failNext = null; throw it } }

        override suspend fun calendar(from: String, to: String, city: String?): CalendarWindow {
            calls += "calendar:$from:$to:$city"; maybeFail()
            return CalendarWindow(items, CalendarMeta(from = from, to = to))
        }
        override suspend fun notifications(page: Int): BellPage {
            calls += "bell:$page"; maybeFail()
            return BellPage(bell, BellMeta(total = bell.size, unread = bell.count { !it.read }))
        }
        private fun change(name: String, update: () -> Unit): BellChange { calls += name; maybeFail(); update(); return BellChange(meta = BellChangeMeta(message = "fatto", unread = bell.count { !it.read })) }
        override suspend fun markRead(id: String) = change("read:$id") { bell = bell.map { if (it.id == id) it.copy(read = true) else it } }
        override suspend fun markUnread(id: String) = change("unread:$id") { bell = bell.map { if (it.id == id) it.copy(read = false) else it } }
        override suspend fun remove(id: String) = change("remove:$id") { bell = bell.filterNot { it.id == id } }
        override suspend fun markAllRead() = change("read-all") { bell = bell.map { it.copy(read = true) } }
        override suspend fun clear() = change("clear") { bell = emptyList() }
        override suspend fun resolve(type: String, slug: String): EditorialLinkTarget { calls += "resolve:$type:$slug"; return EditorialLinkTarget(type, 41, "Concerto") }
    }

    private val repository = EditorialRepository(1, "t", FakeDesk(), EditorialLocalLock(clock = { 0L })).also { it.selectedCity = "padova" }

    @Test fun theGridHasWholeWeeksFromMondayToSunday() {
        val march = calendarGrid(YearMonth.of(2027, 3))
        assertEquals(LocalDate.of(2027, 3, 1), march.first())
        assertEquals(DayOfWeek.MONDAY, march.first().dayOfWeek)
        assertEquals(LocalDate.of(2027, 4, 4), march.last())
        assertEquals(DayOfWeek.SUNDAY, march.last().dayOfWeek)
        assertEquals(0, march.size % 7)
        /* Febbraio 2027 comincia di lunedì e finisce di domenica: quattro righe sole. */
        assertEquals(28, calendarGrid(YearMonth.of(2027, 2)).size)
        /* Un mese che comincia di domenica arriva a sei righe, mai di più: il server ne accetta fino a 62 giorni. */
        val august = calendarGrid(YearMonth.of(2027, 8))
        assertEquals(LocalDate.of(2027, 7, 26), august.first())
        assertEquals(42, august.size)
    }

    @Test fun theMonthAsksTheServerForTheWholeGridAndGroupsTheDatesByDay() {
        val source = FakeCalendar()
        val model = EditorialCalendarViewModel(repository, source, SavedStateHandle(), today = { LocalDate.of(2027, 3, 12) })
        assertEquals(listOf("calendar:2027-03-01:2027-04-04:padova"), source.calls)
        val state = model.uiState.value
        assertTrue(state.window is Loadable.Ready)
        assertEquals("2027-03-12", state.selected)
        assertEquals(listOf(1L, 2L), state.byDay["2027-03-12"]?.map { it.id })
        assertEquals(listOf(3L), state.byDay["2027-03-20"]?.map { it.id })

        model.pick("2027-03-20")
        assertEquals("2027-03-20", model.uiState.value.selected)
        model.pick("non è un giorno")
        assertEquals("2027-03-20", model.uiState.value.selected)

        /* Il mese dopo comincia dal primo; tornando a oggi si torna al giorno di oggi. */
        model.shift(1)
        assertEquals("2027-04", model.uiState.value.month)
        assertEquals("2027-04-01", model.uiState.value.selected)
        assertEquals("calendar:2027-03-29:2027-05-02:padova", source.calls.last())
        model.goToToday()
        assertEquals("2027-03", model.uiState.value.month)
        assertEquals("2027-03-12", model.uiState.value.selected)
    }

    @Test fun monthAndDayStayInTheSavedStateAndAFailureCanBeRetried() {
        val source = FakeCalendar().apply { failNext = ApiException(500, ApiProblem(message = "Errore", code = "SERVER_ERROR")) }
        val saved = SavedStateHandle(mapOf("editorial.calendar.month" to "2026-12", "editorial.calendar.day" to "2026-12-24"))
        val model = EditorialCalendarViewModel(repository, source, saved, today = { LocalDate.of(2027, 3, 12) })
        assertEquals("2026-12", model.uiState.value.month)
        assertEquals("2026-12-24", model.uiState.value.selected)
        assertTrue(model.uiState.value.window is Loadable.Failed)
        model.load()
        assertTrue(model.uiState.value.window is Loadable.Ready)
        model.shift(-1)
        assertEquals("2026-11", saved.get<String>("editorial.calendar.month"))
    }

    @Test fun theBellReadsMarksRemovesAndClearsLikeThePanel() {
        val source = FakeCalendar()
        val model = EditorialBellViewModel(repository, source)
        assertEquals(1, model.uiState.value.unread)
        assertEquals(2, model.uiState.value.items.size)

        /* Aprire una notifica la segna come letta e dà l'indirizzo del pannello da aprire nell'app. */
        assertEquals("https://eventi.test/admin/sponsorships", model.open(model.uiState.value.items.first()))
        assertTrue("read:a" in source.calls)
        assertEquals(0, model.uiState.value.unread)
        /* Una già letta si apre senza un'altra richiesta. */
        val before = source.calls.size
        model.open(model.uiState.value.items.first { it.id == "b" })
        assertEquals(before, source.calls.size)

        model.markUnread(model.uiState.value.items.first())
        assertEquals(1, model.uiState.value.unread)
        model.markAllRead()
        assertEquals(0, model.uiState.value.unread)
        model.remove(model.uiState.value.items.first { it.id == "b" })
        assertEquals(listOf("a"), model.uiState.value.items.map { it.id })
        model.clear()
        assertEquals(Loadable.Empty, model.uiState.value.page)
        assertEquals("fatto", model.uiState.value.message)
    }

    @Test fun aFailedActionShowsItsErrorAndKeepsTheList() {
        val source = FakeCalendar()
        val model = EditorialBellViewModel(repository, source)
        source.failNext = ApiException(404, ApiProblem(message = "Non trovata", code = "NOT_FOUND"))
        model.remove(model.uiState.value.items.first())
        assertNotNull(model.uiState.value.error)
        assertEquals(2, model.uiState.value.items.size)
        model.dismissMessage()
        assertNull(model.uiState.value.error)
    }

    @Test fun theFirstPageShowsTheUnreadCountWithoutAskingForThePassword() {
        val source = FakeCalendar()
        val home = it.fabiodalez.incitta.ui.editorial.home.EditorialHomeViewModel(repository, source)
        assertNull(home.unread.value)
        home.loadUnread()
        assertEquals(1, home.unread.value)
        /* Un errore (anche un 423) lascia la campanella senza numero, senza aprire la richiesta della password. */
        source.failNext = ApiException(423, ApiProblem(message = "Password", code = "EDITORIAL_LOCKED"))
        home.loadUnread()
        assertNull(home.unread.value)
        assertNull(repository.guard.prompt.value)
        /* Senza client (i test della prima pagina) niente numero e nessuna richiesta. */
        val bare = it.fabiodalez.incitta.ui.editorial.home.EditorialHomeViewModel(repository, null)
        bare.loadUnread()
        assertNull(bare.unread.value)
    }

    @Test fun theCalendarAndBellPayloadsReadTheServerShape() {
        val json = Json { ignoreUnknownKeys = true }
        val window = json.decodeFromString<CalendarWindow>(
            """{"data":[{"id":7,"event_id":41,"title":"Notte · Portello","event_title":"Notte","venue":"Portello","city":{"id":1,"name":"Padova","timezone":"Europe/Rome"},
            "date":"2027-03-31","starts_at":"2027-03-31T23:30:00+02:00","ends_at":"2027-04-01T01:00:00+02:00","all_day":false,
            "event_status":{"value":"pending","label":"In attesa"},"status":{"value":"scheduled","label":"In programma"},"color":"#b45309","abilities":{"open":true,"edit":false}}],
            "meta":{"from":"2027-03-01","to":"2027-04-04","timezone":"Europe/Rome","total":1,"truncated":false,"legend":[{"value":"draft","label":"Bozza","color":"#6b6b66"}]}}""",
        )
        val item = window.data.single()
        assertEquals(41L, item.eventId)
        assertTrue(item.abilities.open)
        assertFalse(item.abilities.edit)
        assertEquals("pending", item.eventStatus.value)
        assertEquals("Bozza", window.meta.legend.single().label)
        val bell = json.decodeFromString<BellPage>(
            """{"data":[{"id":"9b1","title":"2 sponsorizzazioni","body":"…","status":"warning","url":"https://eventi.test/admin/sponsorships",
            "actions":[{"label":"Apri","url":"https://eventi.test/admin/sponsorships"}],"read":false,"read_at":null,"created_at":"2027-03-10T07:00:00+01:00"}],
            "meta":{"current_page":1,"last_page":1,"per_page":50,"total":1,"unread":1}}""",
        )
        assertEquals(1, bell.meta.unread)
        assertEquals("Apri", bell.data.single().actions.single().label)
        /* `read-all` e `clear` rispondono con un conteggio al posto della riga: si legge lo stesso. */
        assertEquals(0, json.decodeFromString<BellChange>("""{"data":{"changed":2},"meta":{"message":"Tutte segnate come lette.","unread":0}}""").meta.unread)
    }

    @Test fun theColorOfACellComesFromTheServerAndAStrangeValueIsGrey() {
        assertEquals(Color(0xFF7A9900), calendarColor("#7a9900"))
        assertEquals(calendarColor("#6b6b66"), calendarColor("rosso"))
    }

    @Test fun theBellZoneFormatsTheServerDate() {
        assertEquals("10 marzo, 07:00", bellDate("2027-03-10T07:00:00+01:00", java.time.ZoneId.of("Europe/Rome")))
        assertNull(bellDate(null, java.time.ZoneId.of("Europe/Rome")))
        assertNull(bellDate("ieri", java.time.ZoneId.of("Europe/Rome")))
    }
}
