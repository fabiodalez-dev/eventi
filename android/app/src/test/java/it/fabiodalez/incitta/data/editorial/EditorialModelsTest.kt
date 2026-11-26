package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.User
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* Il banco della redazione letto dal server: la forma del contratto, i valori tolleranti, l'interruttore e la voce del profilo. */
class EditorialModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun readsTheDeskOfTheContract() {
        val desk = json.decodeFromString<EditorialDesk>(
            """{"user":{"id":7,"name":"Ada"},"role":"moderator","city":{"id":1,"slug":"padova","name":"Padova","timezone":"Europe/Rome","is_active":true},
            "cities":[{"id":1,"slug":"padova","name":"Padova","timezone":"Europe/Rome","is_active":true},{"id":2,"slug":"vicenza","name":"Vicenza","timezone":"Europe/Rome","is_active":false}],
            "permissions":{"events_view":true,"events_edit":false,"events_publish":1,"users_manage":"0"},
            "badges":{"events_pending":4,"catalog_reviews_pending":null,"reports_open":"2"},
            "unlock":{"unlocked":true,"unlocked_until":"2026-11-26T22:00:00+01:00","password_confirmed_until":null,"unlock_hours":12,"password_minutes":15,"lock_after_minutes":15},
            "nuovo_campo":"ignorato"}""",
        )
        assertEquals("moderator", desk.role)
        assertTrue(desk.isModerator)
        assertFalse(desk.isAdmin)
        assertEquals("padova", desk.city?.slug)
        assertEquals(2, desk.cities.size)
        assertFalse(desk.cities[1].isActive)
        assertTrue(desk.can.eventsView)
        assertTrue(desk.can.eventsPublish)
        assertFalse(desk.can.eventsEdit)
        assertFalse(desk.can.usersManage)
        /* Una chiave che il server non manda vale no. */
        assertFalse(desk.can.mailManage)
        assertEquals(4, desk.badge("events_pending"))
        assertEquals(2, desk.badge("reports_open"))
        assertNull(desk.badge("catalog_reviews_pending"))
        assertNull(desk.badge("assente"))
        assertTrue(desk.unlock.unlocked)
        assertNull(desk.unlock.passwordConfirmedUntil)
    }

    @Test fun anEmptyOrStrangeAnswerOpensNothing() {
        val desk = json.decodeFromString<EditorialDesk>("""{"permissions":[],"badges":[]}""")
        assertEquals(EditorialPermissions(), desk.can)
        assertFalse(desk.can.eventsView)
        assertNull(desk.badge("events_pending"))
        assertFalse(desk.unlock.unlocked)
        assertEquals(15, desk.unlock.lockAfterMinutes)
        assertNull(desk.city)
        assertEquals("Europe/Rome", EditorialCity(timezone = "Non/Esiste").zone.id)
    }

    @Test fun theSwitchNeedsAnExplicitYes() {
        fun config(value: JsonElement?) = JsonObject(mapOf("features" to JsonObject(if (value == null) emptyMap() else mapOf("editorial" to value))))
        assertTrue(editorialFeatureEnabled(config(JsonPrimitive(true))))
        assertFalse(editorialFeatureEnabled(config(JsonPrimitive(false))))
        assertFalse(editorialFeatureEnabled(config(null)))
        assertFalse(editorialFeatureEnabled(null))
    }

    @Test fun theProfileRowOpensTheAreaOnlyForTheEditorialStaff() {
        val staff = User(1, "Ada", "ada@example.test", management = JsonObject(mapOf("venues" to JsonPrimitive(0), "editorial" to JsonPrimitive(true))))
        val owner = User(2, "Bea", "bea@example.test", management = JsonObject(mapOf("venues" to JsonPrimitive(1), "editorial" to JsonPrimitive(false))))
        val old = User(3, "Ciro", "ciro@example.test", management = JsonObject(mapOf("venues" to JsonPrimitive(1))))
        assertTrue(showsEditorialEntry(staff))
        assertFalse(showsEditorialEntry(owner))
        /* Un server vecchio non manda `editorial`: resta il collegamento al sito. */
        assertFalse(showsEditorialEntry(old))
        assertFalse(showsEditorialEntry(User(4, null, "x@example.test")))
    }

    @Test fun pathsCarryTheCityAndTheListParameters() {
        assertEquals("editorial/desk", editorialPath("desk", null))
        assertEquals("editorial/desk?city=padova", editorialPath("desk", "padova"))
        assertEquals("editorial/events?page=2&filter[status]=pending&city=vicenza", editorialPath("events", "vicenza", listOf("page" to "2", "filter[status]" to "pending")))
        assertEquals("editorial/events?q=sala%20verde", editorialPath("/events", " ", listOf("q" to "sala verde")))
    }
}
