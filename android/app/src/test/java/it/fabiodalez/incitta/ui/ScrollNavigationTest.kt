package it.fabiodalez.incitta.ui

import org.junit.Assert.*
import org.junit.Test

class ScrollNavigationTest {
    @Test fun directionAndThreshold() {
        val navigation = ScrollNavigation(16f)
        assertNull(navigation.scroll(8f))
        assertEquals(true, navigation.scroll(8f))
        assertEquals(false, navigation.scroll(-16f))
    }

    @Test fun reversalDoesNotFlicker() {
        val navigation = ScrollNavigation(16f)
        assertNull(navigation.scroll(15f))
        assertNull(navigation.scroll(-2f))
        assertNull(navigation.scroll(0f))
        assertNull(navigation.scroll(Float.NaN))
        assertEquals(false, navigation.scroll(-14f))
    }
}
