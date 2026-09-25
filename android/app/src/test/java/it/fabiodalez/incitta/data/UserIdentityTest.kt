package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class UserIdentityTest {
    @Test fun oldSessionsRemainReadableWithoutARole() {
        val user = Json.decodeFromString<User>("""{"id":1,"email":"user@example.test"}""")
        assertNull(user.roleLabel)
        assertNull(user.name)
        assertEquals("user@example.test", user.email)
    }

    @Test fun roleComesFromTheServerNotFromTheEmail() {
        val user = Json.decodeFromString<User>("""{"id":2,"email":"admin@example.test","role_label":"Utente"}""")
        assertEquals("Utente", user.roleLabel)
    }

    @Test fun roleAndIdentitySurviveSessionSerialization() {
        val user = User(3, "Giulia Rossi", "giulia@example.test", roleLabel = "Amministratore")
        assertEquals(user, Json.decodeFromString<User>(Json.encodeToString(User.serializer(), user)))
    }
    @Test fun exemptAdministratorUsesServerCapabilitiesWithoutPretendingToVerifyPhone() {
        val user = Json.decodeFromString<User>("""{"id":4,"email":"admin@example.test","whatsapp_verified":false,"community_access":{"eligible":true,"whatsapp_exempt":true,"can_publish":true,"can_attend":true},"carpool_access":{"eligible":true}}""")
        assertFalse(user.whatsappVerified)
        assertTrue(user.communityAccess.canPublish)
        assertTrue(user.communityAccess.canAttend)
        assertTrue(user.carpoolAccess.eligible)
    }

    @Test fun anAdministrativeLabelDoesNotGrantCapabilitiesOnTheDevice() {
        val user = User(5, "Admin", "admin@example.test", roleLabel = "Amministratore")
        assertFalse(user.communityAccess.eligible)
        assertFalse(user.carpoolAccess.eligible)
    }
}
