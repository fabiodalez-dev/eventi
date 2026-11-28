package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* R13: le risposte del server lette dall'app e le regole che l'app ripete prima di mandare. */
class SettingsModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun theTextsAreReadByGroupWithVariablesAndRevision() {
        val texts = json.decodeFromString<EmailTexts>(
            """{"groups":[{"key":"moved","label":"Evento spostato","texts":[{"key":"notifications.moved.subject","label":"Oggetto","default":"Spostato: :title","value":"Nuovo: :title","modified":true,"variables":[{"name":"title","label":"titolo evento"}],"preview":"Nuovo: Jazz","rows":2,"revision":"abc"}]}],"newsletter_included":false,"extra":1}""",
        )
        val text = texts.text("notifications.moved.subject")!!
        assertTrue(text.modified)
        assertEquals(":title", text.variables.single().token)
        assertEquals("moved", texts.groupOf(text.key)?.key)
        assertNull(texts.text("notifications.weekend.subject"))
    }

    @Test fun thePasswordsNeverArriveOnlyWhetherTheyExist() {
        val mail = json.decodeFromString<MailSettingsView>("""{"host":"smtp.example.test","port":587,"has_password":true,"status":{"custom":false,"title":"La posta esce dalla configurazione del server"},"stale_check":true}""")
        assertTrue(mail.hasPassword)
        assertTrue(mail.staleCheck)
        assertEquals(587, mail.port)
        val cache = json.decodeFromString<CacheSettingsView>("""{"redis":{"host":"127.0.0.1","port":6379,"has_password":false},"environment":{"redis_extension":true,"server":"LiteSpeed"}}""")
        assertFalse(cache.redis.hasPassword)
        assertTrue(cache.environment.redisExtension)
    }

    @Test fun theCookiePageCarriesThePurposesAndTheTabCounts() {
        val page = json.decodeFromString<CookiePage>(
            """{"data":[{"id":1,"category":"necessary","name":"XSRF-TOKEN","provider":null,"purpose":"Protegge i moduli","abilities":{"reorder":true}}],"meta":{"current_page":1,"last_page":1,"per_page":50,"total":1,"tabs":{"necessary":1,"statistics":0,"marketing":2},"tab":"necessary","categories":[{"value":"necessary","label":"Necessari","description":"x"}]}}""",
        )
        assertNull(page.data.single().provider)
        assertEquals(mapOf("necessary" to 1, "statistics" to 0, "marketing" to 2), page.meta.page().tabCounts())
        assertEquals("Necessari", page.meta.categories.single().label)
    }

    @Test fun placeholdersFollowTheServerRule() {
        assertEquals(listOf("title", "when"), emailTextVariables("Il :title di :when e ancora :title"))
        assertEquals(listOf("boh"), unknownEmailVariables("Nuovo :title :boh", "Spostato: :title"))
        assertEquals("Ciao :name", insertTextVariable("Ciao   ", EmailTextVariable("name")))
    }

    @Test fun aCookieMovesOneStepInsideTheTab() {
        assertEquals(listOf(2L, 1L, 3L), movedCookieOrder(listOf(1, 2, 3), 2, -1))
        assertEquals(listOf(1L, 3L, 2L), movedCookieOrder(listOf(1, 2, 3), 2, 1))
        assertNull(movedCookieOrder(listOf(1, 2, 3), 1, -1))
        assertNull(movedCookieOrder(listOf(1, 2, 3), 3, 1))
        assertNull(movedCookieOrder(listOf(1, 2, 3), 9, 1))
    }

    @Test fun theRedirectTargetIsAnInternalPathLikeOnTheSite() {
        assertTrue(isInternalRedirectTarget("/eventi"))
        assertTrue(isInternalRedirectTarget("/eventi/concerto?x=1"))
        assertFalse(isInternalRedirectTarget("//evil.example"))
        assertFalse(isInternalRedirectTarget("%2F%2Fevil.example"))
        assertFalse(isInternalRedirectTarget("https://evil.example"))
        assertFalse(isInternalRedirectTarget("/a\\b"))
        assertFalse(isInternalRedirectTarget("/a b"))
        assertFalse(isInternalRedirectTarget("eventi"))
    }

    @Test fun theNewsletterTimeIsAClockTime() {
        assertTrue(NewsletterFields.TIME.matches("18:45"))
        assertTrue(NewsletterFields.TIME.matches("00:00"))
        assertFalse(NewsletterFields.TIME.matches("24:00"))
        assertFalse(NewsletterFields.TIME.matches("8:30"))
    }
}
