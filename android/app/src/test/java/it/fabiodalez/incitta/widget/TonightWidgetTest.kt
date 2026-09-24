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
        val snapshot = TonightWidgetSnapshot(tonightWidgetEntries((1L..20L).map { occurrence(it) }), loaded = true)
        assertEquals(TONIGHT_WIDGET_LIMIT, tonightWidgetVisible(snapshot, millis("2026-09-24T20:00:00+02:00")).size)
    }

    /*
     * La cache tiene più date di quante se ne mostrano, ed è voluto.
     *
     * Chiederne tre e mostrarne tre sembra la stessa cosa: se però fra quelle
     * tre ce n'è una annullata o già finita, il widget resta mezzo vuoto mentre
     * la quarta serata della città è ancora valida. E fra un aggiornamento e
     * l'altro passano dodici ore, durante le quali le date scadono una a una.
     */
    @Test fun tieneDaParteDateInPiuDiQuanteNeMostra() {
        val entries = tonightWidgetEntries((1L..20L).map { occurrence(it) })
        assertEquals(TONIGHT_WIDGET_CACHE, entries.size)
    }

    @Test fun leDateScaduteNonSvuotanoIlWidgetSeCeNeSonoAltre() {
        val passate = (1L..3L).map { occurrence(it, startsAt = "2026-09-24T09:00:00+02:00", endsAt = "2026-09-24T10:00:00+02:00") }
        val future = (4L..8L).map { occurrence(it) }
        val snapshot = TonightWidgetSnapshot(tonightWidgetEntries(passate + future), loaded = true)

        val visibili = tonightWidgetVisible(snapshot, millis("2026-09-24T20:00:00+02:00"))

        assertEquals(TONIGHT_WIDGET_LIMIT, visibili.size)
        assertEquals(listOf(4L, 5L, 6L), visibili.map { it.occurrenceId })
    }

    /*
     * Una data di tutto il giorno comincia a mezzanotte: con la durata presunta
     * di tre ore scadeva alle tre del mattino, e il widget la toglieva prima
     * ancora che venisse sera — cioè prima dell'unico momento in cui serviva.
     */
    @Test fun laDataDiTuttoIlGiornoValeFinoASera() {
        val entry = tonightWidgetEntries(
            listOf(occurrence(1, startsAt = "2026-09-24T00:00:00+02:00", endsAt = null, allDay = true)),
        ).single()

        assertTrue(entry.expiresAt > millis("2026-09-24T22:00:00+02:00"))
        assertTrue(entry.expiresAt < millis("2026-09-25T00:00:01+02:00"))
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

    /*
     * Le tre frasi.
     *
     * Verificato su emulatore il 24/09/2026: un giro di rete fallito lasciava
     * `loaded` falso e il widget scriveva «cerco» all'infinito, riavvio
     * compreso. Su una schermata iniziale «cerco» che non finisce mai è
     * indistinguibile da un widget bloccato, e non c'è niente da aprire per
     * capire: la differenza fra «non ho ancora chiesto» e «ho chiesto e non ci
     * sono riuscito» deve stare scritta.
     */
    @Test fun primaDiChiedereDiceCheStaCercando() {
        val snapshot = TonightWidgetSnapshot()
        assertEquals(TonightWidgetState.Loading, tonightWidgetState(snapshot, emptyList()))
    }

    @Test fun dopoUnTentativoFallitoLoDice() {
        val snapshot = TonightWidgetSnapshot(lastAttemptAt = 1_000L)
        assertEquals(TonightWidgetState.Unreachable, tonightWidgetState(snapshot, emptyList()))
    }

    @Test fun conUnaRispostaVuotaDiceCheNonCEniente() {
        val snapshot = TonightWidgetSnapshot(loaded = true, updatedAt = 1_000L, lastAttemptAt = 1_000L)
        assertEquals(TonightWidgetState.Empty, tonightWidgetState(snapshot, emptyList()))
    }

    @Test fun leDateInCacheBattonoUnAggiornamentoFallito() {
        val voce = TonightWidgetEntry(1L, "Una sera", "21:00", "Un posto", "https://esempio", Long.MAX_VALUE)
        val snapshot = TonightWidgetSnapshot(listOf(voce), updatedAt = 1_000L, loaded = true, lastAttemptAt = 9_000L)
        assertEquals(TonightWidgetState.Entries, tonightWidgetState(snapshot, listOf(voce)))
    }
}
