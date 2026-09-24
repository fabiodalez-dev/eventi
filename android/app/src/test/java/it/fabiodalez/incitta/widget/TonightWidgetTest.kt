package it.fabiodalez.incitta.widget

import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Venue
import java.time.OffsetDateTime
import org.junit.Assert.*
import org.junit.Test

class TonightWidgetTest {
    private fun occurrence(
        id: Long,
        startsAt: String = "2026-09-24T21:00:00+02:00",
        title: String = "Concerto",
        venue: Venue? = Venue(name = "Teatro Verdi"),
        status: String = "scheduled",
        allDay: Boolean = false,
        endsAt: String? = null,
        effectiveEndsAt: String? = null,
        dateUrl: String? = null,
        url: String? = null,
    ) = Occurrence(
        occurrenceId = id,
        eventId = id,
        eventSlug = "evento-$id",
        startsAt = startsAt,
        endsAt = endsAt,
        effectiveEndsAt = effectiveEndsAt,
        isAllDay = allDay,
        status = status,
        title = title,
        venue = venue,
        dateUrl = dateUrl,
        url = url,
    )

    private fun millis(value: String) = OffsetDateTime.parse(value).toInstant().toEpochMilli()

    @Test fun mostraOraELuogoDellaCitta() {
        val entry = tonightWidgetEntries(listOf(occurrence(1, startsAt = "2026-09-24T19:30:00+00:00"))).single()
        // L'orario arriva con lo scostamento del server: il widget lo riporta all'ora di Padova.
        assertEquals("21:30", entry.time)
        assertEquals("Teatro Verdi", entry.place)
        assertEquals("Concerto", entry.title)
    }

    @Test fun laDataDiTuttoIlGiornoNonHaUnOrario() {
        assertEquals("", tonightWidgetEntries(listOf(occurrence(1, allDay = true))).single().time)
    }

    @Test fun ilLuogoAssenteNonDiventaLaParolaNull() {
        assertEquals("", tonightWidgetEntries(listOf(occurrence(1, venue = null))).single().place)
    }

    @Test fun leDateAnnullateNonFinisconoNelWidget() {
        val entries = tonightWidgetEntries(listOf(occurrence(1, status = "cancelled"), occurrence(2)))
        assertEquals(listOf(2L), entries.map { it.occurrenceId })
    }

    @Test fun nonSuperaIlNumeroDiRigheChiesto() {
        val entries = tonightWidgetEntries((1L..10L).map { occurrence(it) })
        assertEquals(TONIGHT_WIDGET_LIMIT, entries.size)
    }

    @Test fun laStessaDataRipetutaOccupaUnaRigaSola() {
        assertEquals(1, tonightWidgetEntries(listOf(occurrence(7), occurrence(7))).size)
    }

    @Test fun apreLaSingolaDataEPoiLEventoEPoiIlPercorsoNoto() {
        assertEquals(
            "https://eventi.fabiodalez.it/eventi/concerto/2",
            tonightWidgetUrl(occurrence(1, dateUrl = "https://eventi.fabiodalez.it/eventi/concerto/2", url = "https://eventi.fabiodalez.it/eventi/concerto")),
        )
        assertEquals(
            "https://eventi.fabiodalez.it/eventi/concerto",
            tonightWidgetUrl(occurrence(1, url = "https://eventi.fabiodalez.it/eventi/concerto")),
        )
        assertEquals("https://eventi.fabiodalez.it/eventi/evento-1", tonightWidgetUrl(occurrence(1)))
    }

    @Test fun unaDataFinitaSparisceDallaCopiaInCache() {
        // Dodici ore fra un aggiornamento e l'altro: senza questo filtro il
        // widget mostrerebbe a mezzogiorno il programma della sera prima.
        val snapshot = TonightWidgetSnapshot(
            tonightWidgetEntries(
                listOf(
                    occurrence(1, startsAt = "2026-09-24T21:00:00+02:00", effectiveEndsAt = "2026-09-24T23:30:00+02:00"),
                    occurrence(2, startsAt = "2026-09-25T21:00:00+02:00", effectiveEndsAt = "2026-09-25T23:30:00+02:00"),
                ),
            ),
            updatedAt = millis("2026-09-24T18:00:00+02:00"),
            loaded = true,
        )
        val visible = tonightWidgetVisible(snapshot, millis("2026-09-25T09:00:00+02:00"))
        assertEquals(listOf(2L), visible.map { it.occurrenceId })
    }

    @Test fun senzaFineDichiarataLaDataValeTreOre() {
        val entry = tonightWidgetEntries(listOf(occurrence(1, startsAt = "2026-09-24T21:00:00+02:00"))).single()
        assertEquals(millis("2026-09-25T00:00:00+02:00"), entry.expiresAt)
    }

    @Test fun ilRisparmioEnergeticoFermaIlGiroDiRete() {
        val vecchio = TonightWidgetSnapshot(updatedAt = 0, loaded = true)
        assertFalse(tonightWidgetShouldRefresh(vecchio, now = TONIGHT_WIDGET_INTERVAL_MILLIS * 4, powerSaveMode = true))
        assertTrue(tonightWidgetShouldRefresh(vecchio, now = TONIGHT_WIDGET_INTERVAL_MILLIS * 4, powerSaveMode = false))
    }

    @Test fun ilPrimoGiroSiFaSempreTranneInRisparmio() {
        assertTrue(tonightWidgetShouldRefresh(TonightWidgetSnapshot(), now = 1_000, powerSaveMode = false))
        assertFalse(tonightWidgetShouldRefresh(TonightWidgetSnapshot(), now = 1_000, powerSaveMode = true))
    }

    @Test fun dueVolteAlGiornoNonDiPiu() {
        val appena = TonightWidgetSnapshot(updatedAt = 1_000_000, loaded = true)
        assertFalse(tonightWidgetShouldRefresh(appena, now = 1_000_000 + TONIGHT_WIDGET_INTERVAL_MILLIS - 1, powerSaveMode = false))
        assertTrue(tonightWidgetShouldRefresh(appena, now = 1_000_000 + TONIGHT_WIDGET_INTERVAL_MILLIS, powerSaveMode = false))
    }

    @Test fun lOrologioCheTornaIndietroNonCongelaIlWidget() {
        // Un istante nel futuro non arriva mai a scadenza: senza questo caso il
        // widget resterebbe fermo finché l'orologio non lo raggiunge di nuovo.
        val futuro = TonightWidgetSnapshot(updatedAt = 5_000_000, loaded = true)
        assertTrue(tonightWidgetShouldRefresh(futuro, now = 1_000, powerSaveMode = false))
    }
}
