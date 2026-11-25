package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.User
import java.time.ZoneId
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import org.junit.Assert.*
import org.junit.Test

class ManagementModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun readsTheSubjectsInTheShapeOfThePlan() {
        val payload = """{"data":{
            "venues":[{"id":12,"slug":"teatro-verdi","name":"Teatro Verdi","city":{"name":"Padova","timezone":"Europe/Rome"},"role":"owner","status":"active","logo":{"thumb":"https://example.test/logo.webp"},"ticketing_enabled":true,"auto_publish":false,"permissions":{"events_create":true,"ticketing_manage":true,"profile_update":true,"leave":false}}],
            "organizers":[{"id":3,"slug":"jazz-club","name":"Jazz Club","city":"Venezia","role":"member","permissions":{"events_create":true}}],
            "checkin_only":false}}"""
        val subjects = json.decodeFromString<ApiEnvelope<ManagementSubjects>>(payload).data
        val all = subjects.all()
        assertEquals(listOf("venue:12", "organizer:3"), all.map { it.key })
        val venue = all[0]
        assertEquals("Padova", venue.cityName)
        assertEquals(ZoneId.of("Europe/Rome"), venue.timezone)
        assertEquals("https://example.test/logo.webp", venue.logo)
        assertTrue(venue.ticketingEnabled)
        assertTrue(venue.permissions.ticketingManage)
        assertFalse(venue.permissions.leave)
        assertFalse(venue.permissions.analyticsView)
        assertEquals("management/venues/12", venue.apiPath)
        assertEquals("gestione/teatro-verdi", venue.webPath)
        val organizer = all[1]
        assertEquals("Venezia", organizer.cityName)
        assertEquals("management/organizers/3", organizer.apiPath)
        assertEquals("organizza/jazz-club", organizer.webPath)
        assertFalse(organizer.isOwner)
        assertEquals(organizer, subjects.find("organizer:3"))
        assertNull(subjects.find("venue:999"))
    }

    @Test fun missingFieldsAndPhpEmptyArraysNeverBreakTheArea() {
        val subjects = json.decodeFromString<ApiEnvelope<ManagementSubjects>>("""{"data":{"venues":[{"id":5,"permissions":[],"city":null,"logo":[]}]}}""").data
        val venue = subjects.all().single()
        assertEquals("", venue.name)
        assertNull(venue.cityName)
        assertNull(venue.logo)
        assertEquals(DEFAULT_MANAGEMENT_ZONE, venue.timezone)
        assertFalse(venue.permissions.eventsCreate)
        assertTrue(json.decodeFromString<ApiEnvelope<ManagementSubjects>>("""{"data":{}}""").data.all().isEmpty())
    }

    @Test fun permissionsAcceptOnlyAClearYes() {
        val venue = json.decodeFromString<ManagedVenue>("""{"id":1,"permissions":{"a":1,"b":"1","c":"true","d":0,"e":"no","f":null,"g":{"x":true},"h":true}}""")
        val can = venue.toSubject().permissions
        assertTrue(can.allows("a")); assertTrue(can.allows("b")); assertTrue(can.allows("c")); assertTrue(can.allows("h"))
        assertFalse(can.allows("d")); assertFalse(can.allows("e")); assertFalse(can.allows("f")); assertFalse(can.allows("g"))
        assertFalse(can.allows("assente"))
    }

    @Test fun unknownTimezoneFallsBackToRome() {
        val venue = json.decodeFromString<ManagedVenue>("""{"id":1,"city":{"name":"Atlantide","timezone":"Mare/Profondo"}}""")
        assertEquals(DEFAULT_MANAGEMENT_ZONE, venue.toSubject().timezone)
    }

    @Test fun managementEntryShowsOnlyToWhoManagesSomethingWithAFallbackForOldServers() {
        fun user(management: String?): User = json.decodeFromString(
            """{"id":1,"email":"a@example.test"${management?.let { ",\"management\":$it" } ?: ""}}""")
        assertNull(user(null).managementCounts())
        assertTrue(showsManagementEntry(user(null)))
        assertTrue(showsManagementEntry(user("null")))
        assertTrue(showsManagementEntry(user("[]")))
        assertFalse(showsManagementEntry(user("""{"venues":0,"organizers":0,"checkin":0}""")))
        assertTrue(showsManagementEntry(user("""{"venues":0,"organizers":0,"checkin":2}""")))
        assertTrue(showsManagementEntry(user("""{"venues":1}""")))
        assertTrue(showsManagementEntry(user("""{"organizers":true}""")))
        assertEquals(UserManagementCounts(2, 0, 1), user("""{"venues":"2","checkin":1}""").managementCounts())
    }

    @Test fun userWithManagementSurvivesTheEncryptedSessionRoundTrip() {
        val user = json.decodeFromString<User>("""{"id":1,"email":"a@example.test","management":{"venues":1,"organizers":0,"checkin":0}}""")
        val again = json.decodeFromString<User>(json.encodeToString(User.serializer(), user))
        assertEquals(user.managementCounts(), again.managementCounts())
    }

    @Test fun theNewAreaTurnsOnOnlyWithAnExplicitYes() {
        fun config(value: String) = json.decodeFromString<ApiEnvelope<JsonElement>>("""{"data":$value}""").data
        assertTrue(managementFeatureEnabled(config("""{"features":{"management":true,"map":true}}""")))
        assertFalse(managementFeatureEnabled(config("""{"features":{"management":false}}""")))
        assertFalse(managementFeatureEnabled(config("""{"features":{"map":true}}""")))
        assertFalse(managementFeatureEnabled(config("""{"features":[]}""")))
        assertFalse(managementFeatureEnabled(config("""{"id":41,"title":"Una data"}""")))
        assertFalse(managementFeatureEnabled(null))
    }

    @Test fun optionsAcceptTheThreeListShapesAndLimitsInEitherPlace() {
        val options = ManagementOptions.from(json.decodeFromString<ApiEnvelope<JsonElement>>("""{"data":{
            "price_types":["free","ticket"],
            "occurrence_statuses":[{"value":"scheduled","label":"In programma"},{"key":"cancelled","name":"Annullata"},{"label":"senza valore"}],
            "age_ranges":{"all":"Per tutti","adults":{"label":"Solo adulti"}},
            "limits":{"external_links":8,"attendee_messages_per_day":"3"},
            "closures":20}}""").data)
        assertEquals(listOf("free", "ticket"), options.choices("price_types").map { it.value })
        assertEquals(listOf(OptionChoice("scheduled", "In programma"), OptionChoice("cancelled", "Annullata")), options.choices("occurrence_statuses"))
        assertEquals(listOf(OptionChoice("all", "Per tutti"), OptionChoice("adults", "Solo adulti")), options.choices("age_ranges"))
        assertEquals(8L, options.limit("external_links"))
        assertEquals(3L, options.limit("attendee_messages_per_day"))
        assertEquals(20L, options.limit("closures"))
        assertNull(options.limit("assente"))
        assertTrue(options.choices("assente").isEmpty())
        assertTrue(ManagementOptions.from(null).choices("price_types").isEmpty())
    }

    @Test fun readsTheOptionsInTheShapeOfTheServerContract() {
        val options = ManagementOptions.from(json.decodeFromString<ApiEnvelope<JsonElement>>("""{"data":{
            "occurrence_statuses":{"venue":[{"value":"scheduled","label":"In programma"},{"value":"sold_out","label":"Tutto esaurito"}],"organizer":[{"value":"postponed","label":"Rinviata"}]},
            "event_features":[{"id":4,"name":"Bagno accessibile","group":null,"icon":"hand-raised"}],
            "limits":{"attendee_messages_per_day":3,"gallery_images":30},
            "images":{"max_upload_bytes":12582912,"max_pixels":50000000,"max_original_width":2400}}}""").data)
        assertEquals(listOf("scheduled", "sold_out"), options.choices("occurrence_statuses", "venue").map { it.value })
        assertEquals(listOf("postponed"), options.choices("occurrence_statuses", "organizer").map { it.value })
        assertEquals(listOf(OptionChoice("4", "Bagno accessibile")), options.choices("event_features"))
        assertEquals(30L, options.limit("gallery_images"))
        /* I tetti delle immagini dell'app sono gli stessi del server. */
        assertEquals(ImageUpload.MAX_UPLOAD_BYTES.toLong(), options.image("max_upload_bytes"))
        assertEquals(ImageUpload.MAX_PIXELS, options.image("max_pixels"))
        assertEquals(ImageUpload.MAX_SIDE.toLong(), options.image("max_original_width"))
    }

    @Test fun landingPicksTheFirstVenueThenTheFirstOrganizer() {
        val venue = ManagedVenue(id = 12)
        val organizer = ManagedOrganizer(id = 3)
        assertEquals(ManagementLanding.TicketingDates, landingFor(false, ManagementSubjects(listOf(venue))))
        assertEquals(ManagementLanding.TicketingDates, landingFor(true, null))
        assertEquals(ManagementLanding.TicketingDates, landingFor(true, ManagementSubjects(checkinOnly = true)))
        assertEquals(ManagementLanding.Subject("venue:12"), landingFor(true, ManagementSubjects(listOf(venue), listOf(organizer))))
        assertEquals(ManagementLanding.Subject("organizer:3"), landingFor(true, ManagementSubjects(organizers = listOf(organizer))))
    }

    @Test fun galleryOrderSendsTheIdsInTheNewOrder() {
        assertEquals("""{"ids":[9,3,5]}""", galleryOrderBody(listOf(9, 3, 5)).toString())
    }
}
