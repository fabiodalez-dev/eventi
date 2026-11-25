package it.fabiodalez.incitta.data.management

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* Il modulo della scheda del locale: lettura tollerante, e per ogni sezione solo le sue chiavi nella forma del server. */
class ProfileDraftTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    private val payload = """
        {"id": 12, "name": "Circolo Aurora", "type": {"value": "circolo", "label": "Circolo"},
         "city": {"name": "Padova", "timezone": "Europe/Rome"},
         "description": "<p>Musica <strong>dal vivo</strong></p>", "address": "Via Roma 1", "municipality": "Padova", "zone": "Portello",
         "lat": 45.40930, "lng": 11.88910,
         "opening_hours": [{"day": "fri", "open": "18:00", "close": "02:00"}],
         "closures": [{"from": "2026-12-24", "to": "2027-01-06", "note": null}],
         "capacity": 180, "socials": [{"name": "Instagram", "url": "https://instagram.com/aurora"}],
         "transit": [{"mode": "tram", "text": "SIR1"}], "accessibility": {"step_free_entrance": true, "tactile_path": false},
         "info": [{"label": "Guardaroba", "value": "Gratuito"}], "requires_membership": true,
         "content_details": {"age_groups": ["3-5"], "stroller": "yes", "feature_ids": ["4", 7],
            "practical_custom": {"0": {"label": "Ingresso", "icon": "map-pin", "text": "<p>Porta laterale</p>"}},
            "faqs": [{"question": "Si paga?", "answer": "<p>No</p>"}], "introduction": 12, "unknown": {"x": 1}},
         "seo": {"title": "Aurora", "description": null, "image": null},
         "contact": {"mode": "members", "email": "scrivici@aurora.test", "confirmed": false, "pending": true, "can_resend": true, "link_days": 7, "modes": [{"value": "disabled", "label": "Nessuno"}]},
         "notices": {"min_days_between": 7, "latest": null, "next_available_at": null, "followers": 3},
         "public_url": "https://x.test/locali/aurora", "calendar_url": "https://x.test/locali/aurora/calendario.ics",
         "widget": {"url": "https://x.test/widget/aurora", "code": "<iframe></iframe>"},
         "choices": {"weekdays": [{"value": "mon", "label": "Lunedì"}], "municipalities": ["Padova", "Este"], "districts": ["Portello"], "district_municipality": "Padova"},
         "limits": {"opening_hours": 28, "socials": 12}, "future_field": true}
    """.trimIndent()

    private fun profile() = json.decodeFromString(VenueProfile.serializer(), payload)

    @Test fun readsTheProfileAndToleratesOddContentDetails() {
        val draft = VenueProfileDraft.from(profile())
        assertEquals("Musica **dal vivo**", draft.description)
        assertEquals(listOf(HourDraft("fri", "18:00", "02:00")), draft.hours)
        assertEquals(listOf(ClosureDraft("2026-12-24", "2027-01-06", "")), draft.closures)
        assertEquals("180", draft.capacity)
        assertEquals(mapOf("step_free_entrance" to true, "tactile_path" to false), draft.accessibility)
        /* Id come testo, righe salvate come mappa, un numero dove ci si aspetta un testo: si legge lo stesso. */
        assertEquals(listOf(4L, 7L), draft.featureIds)
        assertEquals(listOf(PracticalDraft("Ingresso", "map-pin", "Porta laterale")), draft.practical)
        assertEquals("12", draft.introduction)
        assertEquals("members", draft.contactMode)
        assertEquals(28, profile().limit("opening_hours", 0))
        assertEquals(20, profile().limit("closures", 20))
    }

    @Test fun eachSectionSendsOnlyItsKeys() {
        val draft = VenueProfileDraft.from(profile())
        VenueSection.entries.forEach { section ->
            val keys = draft.payload(section).keys
            val expected = section.keys.map { it.substringBefore('.') }.toSet()
            assertEquals("Sezione $section", expected, keys)
        }
    }

    @Test fun hoursAndClosuresGoAsRowsAndEmptyBecomesNull() {
        val draft = VenueProfileDraft.from(profile()).copy(capacity = "", closures = listOf(ClosureDraft("2027-02-01", "2027-02-02", "  ")))
        val body = draft.payload(VenueSection.Hours)
        assertEquals(JsonNull, body["capacity"])
        assertEquals("fri", body["opening_hours"]!!.jsonArray[0].jsonObject["day"]!!.jsonPrimitive.content)
        assertEquals(JsonNull, body["closures"]!!.jsonArray[0].jsonObject["note"])
        assertEquals(JsonPrimitive(181L), draft.copy(capacity = "181").payload(VenueSection.Hours)["capacity"])
    }

    @Test fun districtOnlyForTheMunicipalityThatHasThem() {
        val draft = VenueProfileDraft.from(profile())
        assertEquals(JsonPrimitive("Portello"), draft.payload(VenueSection.Where)["zone"])
        assertEquals(JsonNull, draft.copy(municipality = "Este").payload(VenueSection.Where)["zone"])
        assertEquals(JsonPrimitive(45.4093), draft.payload(VenueSection.Where)["lat"])
    }

    @Test fun accessibilitySendsOnlyDeclaredFeatures() {
        val body = VenueProfileDraft.from(profile()).copy(accessibility = mapOf("tactile_path" to false)).payload(VenueSection.Accessibility)
        assertEquals(JsonObject(mapOf("tactile_path" to JsonPrimitive(false))), body["accessibility"])
    }

    @Test fun richFieldsTravelAsHtml() {
        val draft = VenueProfileDraft.from(profile())
        assertEquals(JsonPrimitive("<p>Musica <strong>dal vivo</strong></p>"), draft.payload(VenueSection.Description)["description"])
        val before = draft.payload(VenueSection.BeforeGoing)["content_details"]!!.jsonObject
        assertEquals("<p>Porta laterale</p>", before["practical_custom"]!!.jsonArray[0].jsonObject["text"]!!.jsonPrimitive.content)
        assertEquals(JsonArray(listOf(JsonPrimitive(4L), JsonPrimitive(7L))), before["feature_ids"])
        assertFalse(before.containsKey("introduction"))
        assertEquals("<p>No</p>", draft.payload(VenueSection.Editorial)["content_details"]!!.jsonObject["faqs"]!!.jsonArray[0].jsonObject["answer"]!!.jsonPrimitive.content)
    }

    @Test fun organizerDraftRoundTrips() {
        val organizer = OrganizerProfile(description = "<p>Chi <em>siamo</em></p>", website = "https://p.test", contact = ContactSettings(mode = "everyone", email = "a@p.test"))
        val body = OrganizerProfileDraft.from(organizer).copy(email = " ").payload()
        assertEquals(JsonPrimitive("<p>Chi <em>siamo</em></p>"), body["description"])
        assertEquals(JsonNull, body["email"])
        assertEquals(JsonPrimitive("everyone"), body["contact_mode"])
    }

    @Test fun noticeBodyLeavesOutAMissingDate() {
        assertFalse(noticeBody("info", "Ciao", null).containsKey("until"))
        assertEquals(JsonPrimitive("2026-12-01"), noticeBody("pause", "Ferie", "2026-12-01")["until"])
    }

    @Test fun cropKeepsTheFrameCoveredAndReturnsImagePixels() {
        /* Foto 2400×1600 in una cornice quadrata da 300: la scala di copertura è 300/1600. */
        val centered = ImageCrop.rect(2400, 1600, 300f, 300f, zoom = 1f, offsetX = 0f, offsetY = 0f)
        assertEquals(CropRect(400, 0, 1600, 1600), centered)
        /* Spostata tutta a destra (oltre il bordo): si ferma sul bordo sinistro della foto. */
        assertEquals(CropRect(0, 0, 1600, 1600), ImageCrop.rect(2400, 1600, 300f, 300f, 1f, offsetX = 10_000f, offsetY = 0f))
        /* Ingrandita due volte al centro: metà lato. */
        assertEquals(CropRect(800, 400, 800, 800), ImageCrop.rect(2400, 1600, 300f, 300f, 2f, 0f, 0f))
        /* 16:9 da una foto verticale. */
        val wide = ImageCrop.rect(1200, 1600, 320f, 180f, 1f, 0f, 0f)
        assertEquals(1200, wide.width)
        assertEquals(675, wide.height)
        assertTrue(wide.top in 462..463)
        assertEquals(0f, ImageCrop.clampOffset(50f, 1600, 300f / 1600f, 300f), 0.001f)
    }
}
