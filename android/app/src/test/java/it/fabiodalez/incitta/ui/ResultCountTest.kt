package it.fabiodalez.incitta.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class ResultCountTest {
    @Test
    fun `un solo risultato va al singolare`() {
        assertEquals("1 RISULTATO", resultCount(1))
        assertEquals("0 RISULTATI", resultCount(0))
        assertEquals("24 RISULTATI", resultCount(24))
    }
}
