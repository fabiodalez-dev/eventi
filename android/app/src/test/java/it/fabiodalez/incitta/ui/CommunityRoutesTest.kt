package it.fabiodalez.incitta.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class CommunityRoutesTest {
    @Test fun newRouteIsPushedOnTop() {
        assertEquals(listOf("feed", "whatsapp"), pushRoute(listOf("feed"), "whatsapp"))
    }

    @Test fun routeAlreadyOnTopIsNotDuplicated() {
        val stack = listOf("feed", "whatsapp")
        assertEquals(stack, pushRoute(stack, "whatsapp"))
    }

    @Test fun sameRouteLowerInTheStackIsStillPushed() {
        assertEquals(listOf("feed", "settings", "feed"), pushRoute(listOf("feed", "settings"), "feed"))
    }

    @Test fun emptyStackReceivesTheRoute() {
        assertEquals(listOf("people"), pushRoute(emptyList(), "people"))
    }
}
