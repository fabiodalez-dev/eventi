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
}
