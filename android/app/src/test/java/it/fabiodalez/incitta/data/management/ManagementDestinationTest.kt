package it.fabiodalez.incitta.data.management

import org.junit.Assert.*
import org.junit.Test

class ManagementDestinationTest {
    private val origin = "https://eventi.fabiodalez.it/api/v1/"
    private fun parse(url: String) = ManagementDestination.parse(url, origin)

    @Test fun ticketingPagesOfTheSiteOpenTheNativeScreens() {
        assertEquals(ManagementDestination.TicketingDates, parse("https://eventi.fabiodalez.it/gestione-biglietti"))
        assertEquals(ManagementDestination.TicketingDates, parse("https://eventi.fabiodalez.it/gestione-biglietti/"))
        assertEquals(ManagementDestination.TicketingDate(41), parse("https://eventi.fabiodalez.it/gestione-biglietti/41"))
        assertEquals(ManagementDestination.TicketingDate(41), parse("https://eventi.fabiodalez.it/gestione-biglietti/41/scanner?x=1#top"))
    }

    @Test fun pagesWithoutANativeScreenStayInTheBrowser() {
        listOf(
            "https://eventi.fabiodalez.it/gestione/teatro-verdi",
            "https://eventi.fabiodalez.it/gestione/teatro-verdi/eventi",
            "https://eventi.fabiodalez.it/organizza/jazz-club",
            "https://eventi.fabiodalez.it/gestione-biglietti/41/csv",
            "https://eventi.fabiodalez.it/gestione-biglietti/41/lista.pdf",
            "https://eventi.fabiodalez.it/gestione-biglietti/prenotazione/5/annulla",
            "https://eventi.fabiodalez.it/gestione-biglietti/0",
            "https://eventi.fabiodalez.it/gestione-biglietti/-4",
            "https://eventi.fabiodalez.it/gestione-biglietti/99999999999999999999",
            "https://eventi.fabiodalez.it/biglietti",
        ).forEach { assertNull(it, parse(it)) }
    }

    @Test fun otherHostsSchemesPortsAndCredentialsAreRejected() {
        listOf(
            "https://altro.example/gestione-biglietti/41",
            "http://eventi.fabiodalez.it/gestione-biglietti/41",
            "https://eventi.fabiodalez.it:8443/gestione-biglietti/41",
            "https://utente@eventi.fabiodalez.it/gestione-biglietti/41",
            "non è un indirizzo",
        ).forEach { assertNull(it, parse(it)) }
    }
}
