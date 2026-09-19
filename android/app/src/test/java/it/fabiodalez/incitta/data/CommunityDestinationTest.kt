package it.fabiodalez.incitta.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class CommunityDestinationTest {
    private val origin = "https://eventi.fabiodalez.it/api/v1/"

    @Test fun `accepts only the configured origin for private carpool routes`() {
        assertEquals(
            CommunityDestination("carpool", "chats/42"),
            CommunityDestination.parse("https://eventi.fabiodalez.it/passaggi/messaggi/42", origin),
        )
        assertNull(CommunityDestination.parse("https://evil.example/passaggi/messaggi/42", origin))
        assertNull(CommunityDestination.parse("https://eventi.fabiodalez.it/passaggi/messaggi/0", origin))
    }

    @Test fun `maps social and review deep links without trusting query parameters`() {
        assertEquals(
            CommunityDestination("carpool", "drivers/7/reviews"),
            CommunityDestination.parse("https://eventi.fabiodalez.it/passaggi/conducenti/7/recensioni?ignored=1", origin),
        )
        assertEquals(
            CommunityDestination("social", "profile/elena_qa"),
            CommunityDestination.parse("https://eventi.fabiodalez.it/persone/elena_qa", origin),
        )
    }
}
