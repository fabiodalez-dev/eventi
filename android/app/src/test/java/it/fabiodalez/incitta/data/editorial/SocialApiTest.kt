package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.editorial.social.insertCaptionToken
import it.fabiodalez.incitta.ui.editorial.social.socialLocalParam
import java.time.LocalDateTime
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* R9: i percorsi sotto `E/social` con la città, il corpo delle impostazioni senza segreti, la lettura tollerante delle risposte. */
class SocialApiTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun thePathsCarryTheWorkingCityOnlyWhereThePanelFiltersByCity() {
        assertEquals("editorial/social/catalog?period=next30&city=padova", editorialSocialCatalogPath("padova", SocialCatalogQuery()))
        assertEquals(
            "editorial/social/catalog?period=custom&from=2026-09-01&until=2026-09-30&search=jazz%20club&limit=48&event=12&city=vicenza",
            editorialSocialCatalogPath("vicenza", SocialCatalogQuery("custom", "2026-09-01", "2026-09-30", " jazz club ", 48, 12)),
        )
        // Dal e al contano solo con il periodo personalizzato; senza città il server usa la prima attiva.
        assertEquals("editorial/social/catalog?period=week", editorialSocialCatalogPath(null, SocialCatalogQuery("week", "2026-09-01", "2026-09-30")))
        assertEquals("editorial/social/day?date=2026-09-11&city=padova", editorialSocialPath("day", "padova", listOf("date" to "2026-09-11", "event" to null)))
        assertEquals("editorial/social/dates/41/preview", editorialSocialPreviewPath(41))
    }

    @Test fun theSettingsBodyHasEveryFieldOfTheFormAndNoSecret() {
        val body = socialSettingsBody(SocialSettingsValues(appId = " ", cityId = 2, pageId = "500", telegramChatId = "@incitta", automatic = true,
            graphicOptions = SocialGraphicOptions(monochrome = false, imageFit = "cover"), publishTime = "10:30", caption = "Il :data"))
        assertEquals(JsonNull, body["app_id"])
        assertEquals("2", body["city_id"]?.jsonPrimitive?.content)
        assertEquals("500", body["page_id"]?.jsonPrimitive?.content)
        assertEquals(JsonNull, body["instagram_id"])
        assertEquals("cover", body["graphic_options"]?.jsonObject?.get("image_fit")?.jsonPrimitive?.content)
        assertEquals("false", body["graphic_options"]?.jsonObject?.get("monochrome")?.jsonPrimitive?.content)
        assertEquals("true", body["automatic"]?.jsonPrimitive?.content)
        listOf("app_secret", "access_token", "telegram_bot_token").forEach { assertFalse(it, body.containsKey(it)) }
    }

    @Test fun theSettingsAreReadWithOnlyTheSavedFlagsOfTheSecrets() {
        val settings = json.decodeFromString<SocialSettings>(
            """{"values":{"app_id":"12345","city_id":1,"page_id":null,"graph_version":"v25.0","facebook_enabled":true,"graphic_options":{"monochrome":true,"show_address":false,"show_price":true,"image_fit":"contain"},"publish_time":"09:00","caption":"Il :data"},
               "status":{"exists":true,"app_secret_saved":true,"access_token_saved":false,"telegram_bot_token_saved":true,"meta_verified_at":null},
               "cities":[{"id":1,"name":"Padova"}],"tokens":[{"token":":data","label":"Data"}],"web_path":"admin/social-settings","nuovo":1}""",
        )
        assertEquals("12345", settings.values.appId)
        assertNull(settings.values.pageId)
        assertFalse(settings.values.graphicOptions.showAddress)
        assertTrue(settings.status.appSecretSaved)
        assertFalse(settings.status.accessTokenSaved)
        assertNull(settings.status.metaVerifiedAt)
        assertEquals(":data", settings.tokens.single().token)
        assertEquals(2200, settings.limits.captionMax)
    }

    @Test fun aPublicationRowSaysWhatItCanDoAndWhenTheBatchIsGone() {
        val row = json.decodeFromString<SocialPublicationRow>(
            """{"id":7,"platform":"telegram","platform_label":"Telegram","part":2,"status":{"value":"failed","label":"Non pubblicato"},"error":"Telegram non ha risposto",
               "batch_id":null,"batch_gone":true,"abilities":{"reschedule":false,"cancel":false,"retry":false}}""",
        )
        assertEquals(2, row.part)
        assertTrue(row.batchGone)
        assertNull(row.batchId)
        assertEquals(mapOf("reschedule" to false, "cancel" to false, "retry" to false), abilitiesOf(row.abilities))
        // Un pacchetto della redazione con il testo automatico di ogni parte del carosello.
        val batch = json.decodeFromString<EditorialSocialBatch>("""{"id":"abc","date":"2026-09-11","captions":["Parte 1","Parte 2"],"in_history":true,"city":{"id":1,"slug":"padova","name":"Padova"}}""")
        assertEquals(listOf("Parte 1", "Parte 2"), batch.captions)
        assertTrue(batch.inHistory)
        assertEquals("Padova", batch.city?.name)
    }

    @Test fun theDateTimeAndTheCaptionTokensAreWrittenLikeThePage() {
        assertEquals("2026-09-10T08:05", socialLocalParam(LocalDateTime.of(2026, 9, 10, 8, 5, 59)))
        assertEquals(":data", insertCaptionToken("", ":data"))
        assertEquals("Il :data", insertCaptionToken("Il", ":data"))
        assertEquals("Il\n:data", insertCaptionToken("Il\n", ":data"))
    }
}
