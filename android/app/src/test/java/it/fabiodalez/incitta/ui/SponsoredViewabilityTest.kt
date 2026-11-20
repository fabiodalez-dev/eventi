package it.fabiodalez.incitta.ui

import org.junit.Assert.*
import org.junit.Test

/* La regola del secondo a metà elemento, la stessa del sito. */
class SponsoredViewabilityTest {
    @Test fun measuresTheVisibleShareOfTheBanner() {
        assertEquals(1f, visibleFraction(300f, 100f, 300, 100), 0.0001f)
        assertEquals(0.5f, visibleFraction(300f, 50f, 300, 100), 0.0001f)
        assertEquals(0.25f, visibleFraction(150f, 50f, 300, 100), 0.0001f)
        assertEquals(0f, visibleFraction(300f, 0f, 300, 100), 0.0001f)
    }

    @Test fun neverGoesBeyondTheWholeBannerOrBelowNothing() {
        assertEquals(1f, visibleFraction(900f, 900f, 300, 100), 0.0001f)
        assertEquals(0f, visibleFraction(-10f, 50f, 300, 100), 0.0001f)
        // Un elemento non ancora misurato non è visibile.
        assertEquals(0f, visibleFraction(300f, 100f, 0, 0), 0.0001f)
    }

    @Test fun countsFromHalfTheBannerAndOnlyInTheForeground() {
        assertTrue(isViewable(0.5f, foreground = true))
        assertTrue(isViewable(1f, foreground = true))
        assertFalse(isViewable(0.49f, foreground = true))
        assertFalse(isViewable(1f, foreground = false))
        assertEquals(1_000L, SPONSORED_VIEWABLE_MILLIS)
        assertEquals(0.5f, SPONSORED_VIEWABLE_FRACTION, 0f)
    }
}
