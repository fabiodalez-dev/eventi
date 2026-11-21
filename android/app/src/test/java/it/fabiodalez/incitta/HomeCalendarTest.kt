package it.fabiodalez.incitta

import it.fabiodalez.incitta.ui.HomeOverview
import it.fabiodalez.incitta.ui.monthCells
import it.fabiodalez.incitta.ui.monthTitle
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.LocalDate
import java.time.YearMonth

class HomeCalendarTest {
    @Test
    fun `il mese parte dal lunedi come sul sito`() {
        // Novembre 2026 comincia di domenica: sei celle vuote, poi l'1.
        val cells = monthCells(YearMonth.of(2026, 11))
        assertEquals(6 + 30, cells.size)
        (0 until 6).forEach { assertNull(cells[it]) }
        assertEquals(LocalDate.of(2026, 11, 1), cells[6])
        assertEquals(LocalDate.of(2026, 11, 30), cells.last())
        // Giugno 2026 comincia di lunedì: nessuna cella vuota.
        assertEquals(LocalDate.of(2026, 6, 1), monthCells(YearMonth.of(2026, 6)).first())
        assertEquals("novembre 2026", monthTitle(YearMonth.of(2026, 11)))
    }

    @Test
    fun `la home dell'API porta i quattro ritagli e la prossima data`() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
        val body = """{"sections":{"next":[]},"next_mode":"personal","stats":{"upcoming":204,"week":54,"venues":24,"categories":14,"today":7,"tomorrow":10,"weekend":25,"free":85}}"""
        val home = json.decodeFromString<HomeOverview>(body)
        assertEquals(listOf(7, 10, 25, 85), listOf(home.stats.today, home.stats.tomorrow, home.stats.weekend, home.stats.free))
        assertEquals("personal", home.nextMode)
        // Un server senza i ritagli non li nasconde: null non è zero.
        assertNull(json.decodeFromString<HomeOverview>("""{"stats":{"upcoming":3}}""").stats.today)
    }
}
