package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.*
import org.junit.Test

/* R4: le forme che il server riceve e che l'app legge per locali, richieste e organizzatori. */
class EditorialVenuesModelsTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun eachEditorialSectionSendsOnlyItsKeys() {
        val draft = VenueEditorialDraft(name = " Circolo Aurora ", slug = "", cityId = "3", type = "circolo", provinceCode = "PD", status = "approved",
            isVerified = true, plan = "premium", rejectionReason = "  ", ticketingEnabled = true, seoIndexing = "excluded", isDemo = true)
        EditorialVenueSection.entries.forEach { section -> assertEquals(section.keys.toSet(), draft.payload(section).keys) }
        val identity = draft.payload(EditorialVenueSection.Identity)
        assertEquals(JsonPrimitive("Circolo Aurora"), identity["name"])
        assertEquals(JsonNull, identity["slug"]) // vuoto: il server lo ricava dal nome
        assertEquals(JsonPrimitive(3L), identity["city_id"])
        assertEquals(JsonNull, draft.payload(EditorialVenueSection.Moderation)["rejection_reason"])
        /* Lo stato non si manda con il modulo: approva, rifiuta e sospendi hanno le loro rotte. */
        assertFalse(draft.payload(EditorialVenueSection.Moderation).containsKey("status"))
        assertEquals(JsonPrimitive(true), draft.payload(EditorialVenueSection.Ticketing)["ticketing_enabled"])
        assertEquals(JsonPrimitive("excluded"), draft.payload(EditorialVenueSection.Visibility)["seo_indexing"])
    }

    @Test fun aNewVenueSendsTheRequiredFieldsAndTheDistrictOnlyForPadova() {
        val padova = NewVenueDraft(name = "Bottega", cityId = "1", type = "bar", address = "Via Roma 1", municipality = "Padova", zone = "Centro", provinceCode = "PD", lat = "45,40", lng = "11.87")
        val body = padova.payload("Padova")
        assertEquals(setOf("name", "city_id", "type", "address", "municipality", "zone", "province_code", "lat", "lng"), body.keys)
        assertEquals(JsonPrimitive("Centro"), body["zone"])
        assertEquals(JsonPrimitive(45.40), body["lat"]) // la virgola italiana vale come punto
        assertEquals(JsonNull, padova.copy(municipality = "Abano Terme").payload("Padova")["zone"])
        /* Un valore che non è un numero arriva com'è: lo giudica il server (422 sul campo). */
        assertEquals(JsonPrimitive("nord"), NewVenueDraft(lat = "nord").payload()["lat"])
        assertEquals(JsonNull, NewVenueDraft().payload()["lng"])
    }

    @Test fun theOrganizerFormSendsTheContactRecipientOnlyWithMessagesOn() {
        val draft = OrganizerDraft(name = "Musica Insieme", cityId = "2", owner = PersonChoice(7, "Ada", "ada@example.test"),
            users = listOf(PersonChoice(8, "Bea", "bea@example.test")), contactMode = "disabled", contactEmail = "vecchio@example.test", isActive = true)
        val off = draft.payload()
        assertFalse(off.containsKey("contact_email"))
        assertEquals(JsonPrimitive(7L), off["owner_id"])
        assertEquals(JsonArray(listOf(JsonPrimitive(8L))), off["users"])
        assertEquals(JsonPrimitive(true), off["is_active"])
        assertEquals(JsonPrimitive("vecchio@example.test"), draft.copy(contactMode = "everyone").payload()["contact_email"])
        assertEquals(JsonNull, OrganizerDraft().payload()["owner_id"])
    }

    @Test fun memberAndRejectionBodiesFollowThePanel() {
        assertEquals(JsonNull, memberBody("editor", null)["accepted_at"])
        assertEquals(JsonPrimitive("2026-11-26T10:00:00Z"), memberBody("owner", "2026-11-26T10:00:00Z")["accepted_at"])
        val silent = rejectApplicationBody(" Non è un locale ", notify = false, message = "ciao")
        assertEquals(JsonPrimitive("Non è un locale"), silent["notes"])
        assertFalse(silent.containsKey("message"))
        assertEquals(JsonPrimitive("Riprova"), rejectApplicationBody("x", notify = true, message = " Riprova ")["message"])
        assertEquals(JsonPrimitive("Segnalazioni"), reasonBody(" Segnalazioni ")["reason"])
    }

    @Test fun theVenueFormKeepsProfileEditorialFieldsChoicesAndOptions() {
        val data = json.parseToJsonElement("""
            {"id":5,"name":"Circolo Aurora","type":{"value":"circolo","label":"Circolo"},"address":"Via Roma 1","municipality":"Padova","lat":45.4,"lng":11.8,
             "content_details":{"introduction":null},"choices":{"district_municipality":"Padova"},
             "editorial":{"name":"Circolo Aurora","city_id":1,"type":"circolo","status":"approved","is_verified":true,"plan":"free","can_ticketing":true,"seo_indexing":"automatic"},
             "editorial_choices":{"cities":[{"value":"1","label":"Padova"}],"municipalities":{"1":[{"value":"Padova","label":"Padova"},{"value":"Abano Terme","label":"Abano Terme"}]},
               "districts":[{"value":"Centro","label":"Centro"}],"district_municipality":[{"value":"Padova","label":"Padova"}],"statuses":[{"value":"approved","label":"Approvato"}]},
             "profile_options":{"transit_modes":[{"value":"bus","label":"Autobus"}],"limits":{"gallery_images":30}},
             "unknown":"ignored"}
        """.trimIndent()).jsonObject
        val form = EditorialVenueForm.from(data)
        assertEquals(5L, form.profile.id)
        assertEquals("Via Roma 1", form.profile.address)
        assertTrue(form.editorial.isVerified && form.editorial.canTicketing)
        assertEquals(listOf("Padova", "Abano Terme"), form.choices.municipalities(1).map { it.value })
        assertTrue(form.choices.municipalities(99).isEmpty())
        assertEquals("Padova", form.choices.districtMunicipality)
        assertEquals("Autobus", form.options.choices("transit_modes").single().label)
        assertEquals(30L, form.options.limit("gallery_images"))
        assertEquals(VenueEditorialDraft(name = "Circolo Aurora", cityId = "1", type = "circolo", status = "approved", isVerified = true, plan = "free", seoIndexing = "automatic"),
            VenueEditorialDraft.from(form.editorial))
    }

    @Test fun rowsToleratePhpEmptyMapsAndMissingFields() {
        val row = json.decodeFromString(EditorialVenueRow.serializer(), """{"id":3,"name":"Teatro","abilities":[]}""")
        assertEquals(3L, row.id)
        assertEquals("", row.status.value)
        val application = json.decodeFromString(ApplicationDetail.serializer(), """{"id":9,"venue_name":"Bottega","kind":{"value":"claim","label":"Rivendicazione"},"public_contacts":null,"duplicates":[{"kind":"venue","id":4,"label":"Bottega, Via Roma"}]}""")
        assertNull(application.publicContacts)
        assertEquals("venue", application.duplicates.single().kind)
        val organizer = json.decodeFromString(OrganizerDetail.serializer(), """{"id":2,"name":"Assoc","choices":{"cities":[{"value":"1","label":"Padova"}],"contact_modes":[{"value":"disabled","label":"Nessuno"}]}}""")
        assertEquals("Padova", organizer.cities.single().label)
        assertEquals(OrganizerDraft(name = "Assoc"), OrganizerDraft.from(organizer))
        assertTrue(JsonObject(emptyMap()).isEmpty())
    }
}
