package it.fabiodalez.incitta.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class CarpoolOfferLineTest {
    @Test fun `blocked offers without zone and time leave no stray separators`() {
        assertEquals("Arcella · Andata", cpLine("Arcella", "Andata"))
        assertEquals("Andata", cpLine("", "Andata"))
        assertEquals("", cpLine("", " "))
    }
}
