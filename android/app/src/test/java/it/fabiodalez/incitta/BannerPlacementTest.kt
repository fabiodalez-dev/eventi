package it.fabiodalez.incitta

import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.User
import org.junit.Assert.*
import org.junit.Test

class BannerPlacementTest {
    @Test fun includesAuthenticatedProfileButNeverLoginOrMap() {
        val guest = AppUiState(tab = AppTab.ACCOUNT)
        assertFalse(guest.supportsSponsoredBanner())
        assertTrue(guest.copy(session = Session("test", User(1, "Test", "test@example.test"), "2099-01-01T00:00:00Z")).supportsSponsoredBanner())
        assertFalse(guest.copy(tab = AppTab.MAP).supportsSponsoredBanner())
        assertTrue(guest.copy(tab = AppTab.EVENTS).supportsSponsoredBanner())
        assertTrue(guest.copy(tab = AppTab.SEARCH).supportsSponsoredBanner())
        assertTrue(guest.copy(tab = AppTab.VENUES).supportsSponsoredBanner())
    }
}
