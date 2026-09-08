package it.fabiodalez.incitta

import it.fabiodalez.incitta.ui.markerDiameterDp
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class TouchNavigationTest {
    @Test fun savedRedirectsGuestsToProfile() {
        assertEquals(AppTab.ACCOUNT, navigationTarget(AppTab.SAVED, false))
        assertEquals(AppTab.SAVED, navigationTarget(AppTab.SAVED, true))
    }

    @Test fun publicTabsRemainAccessibleToGuests() {
        listOf(AppTab.EVENTS, AppTab.MAP, AppTab.SEARCH, AppTab.ACCOUNT).forEach {
            assertEquals(it, navigationTarget(it, false))
        }
    }

    @Test fun markersHaveAtLeast48DpTargetsAtEveryClusterSize() {
        listOf(0, 1, 2, 9, 10, 49, 50, 1000).forEach { assertTrue(markerDiameterDp(it) >= 48) }
        assertEquals(64, markerDiameterDp(1))
        assertTrue(markerDiameterDp(50) > markerDiameterDp(1))
    }
}
