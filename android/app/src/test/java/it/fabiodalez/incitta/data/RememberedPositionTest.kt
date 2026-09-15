package it.fabiodalez.incitta.data

import org.junit.Assert.*
import org.junit.Test

class RememberedPositionTest {
    @Test fun expiresAtTheExactDeadline() {
        val position = RememberedPosition(45.41, 11.88, 100, 200)
        assertTrue(position.valid(199))
        assertFalse(position.valid(200))
    }
    @Test fun rejectsFutureTimestampsAndInvalidCoordinates() {
        assertFalse(RememberedPosition(45.41, 11.88, 201, 300).valid(200))
        assertFalse(RememberedPosition(Double.NaN, 11.88, 100, 300).valid(200))
        assertFalse(RememberedPosition(91.0, 11.88, 100, 300).valid(200))
    }
    @Test fun acceptsZeroCoordinates() {
        assertTrue(RememberedPosition(0.0, 0.0, 100, 300).valid(200))
    }
}
