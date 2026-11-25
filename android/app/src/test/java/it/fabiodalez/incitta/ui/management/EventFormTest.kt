package it.fabiodalez.incitta.ui.management

import it.fabiodalez.incitta.data.management.EventFormOptions
import it.fabiodalez.incitta.data.management.ManagedEventDetail
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.management.events.EventForm
import it.fabiodalez.incitta.ui.management.events.EventFormMode
import it.fabiodalez.incitta.ui.management.events.RepeatState
import it.fabiodalez.incitta.ui.management.events.TierRow
import it.fabiodalez.incitta.ui.management.events.showsEventTicketing
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
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

/* Il modulo dell'evento: quali chiavi manda ogni variante, le date nel fuso della città, il ritorno dei dati salvati. */
class EventFormTest {
    private val rome = ZoneId.of("Europe/Rome")
    private val json = Json { ignoreUnknownKeys = true }

    @Test fun venueCreationSendsTheFirstDateWithTheCityOffsetAndTheRepeat() {
        val form = EventForm(
            title = " Serata swing ", categoryId = 4, priceType = "ticket", priceMin = "12,5", tags = listOf(NamedRef(9, "Jazz")),
            description = "Una **serata**", startsAt = LocalDateTime.of(2026, 9, 3, 21, 0),
            repeat = RepeatState(enabled = true, frequency = "weekly", weekdays = setOf("thu"), until = LocalDate.of(2026, 9, 24)),
        )
        val body = form.toJson(EventFormMode.VenueCreate, rome, publish = true, draftId = 77)
        assertEquals("Serata swing", body["title"]!!.jsonPrimitive.content)
        assertEquals("2026-09-03T21:00:00+02:00", body["starts_at"]!!.jsonPrimitive.content)
        assertEquals(12.5, body["price_min"]!!.jsonPrimitive.content.toDouble(), 0.0)
        assertEquals("<p>Una <strong>serata</strong></p>", body["description"]!!.jsonPrimitive.content)
        assertEquals(JsonPrimitive(true), body["publish"])
        assertEquals(JsonPrimitive(77), body["draft_id"])
        assertEquals("thu", body["repeat"]!!.jsonObject["weekdays"]!!.jsonArray[0].jsonPrimitive.content)
        assertEquals("2026-09-24", body["repeat"]!!.jsonObject["until"]!!.jsonPrimitive.content)
        assertEquals(JsonArray(listOf(JsonPrimitive(9))), body["tags"])
        // La creazione del locale non manda listino, SEO né informazioni editoriali.
        assertNull(body["ticket_tiers"]); assertNull(body["seo"])
        assertNull(body["content_details"]!!.jsonObject["introduction"])
        assertEquals(JsonNull, body["custom_location"])
    }

    @Test fun theVenueEditSendsTiersAndSeoAndNoDates() {
        val form = EventForm(title = "x", priceType = "free", priceMin = "9", tiers = listOf(TierRow(id = 3, name = "Platea", price = "10", status = "sold_out")),
            startsAt = LocalDateTime.of(2026, 9, 3, 21, 0), seoTitle = "Concerto")
        val body = form.toJson(EventFormMode.VenueEdit, rome)
        assertNull(body["starts_at"]); assertNull(body["publish"])
        // Senza importo il prezzo non si manda, come il campo nascosto del pannello.
        assertNull(body["price_min"])
        val tier = body["ticket_tiers"]!!.jsonArray[0].jsonObject
        assertEquals(JsonPrimitive(3), tier["id"]); assertEquals("sold_out", tier["status"]!!.jsonPrimitive.content)
        assertEquals("Concerto", body["seo"]!!.jsonObject["title"]!!.jsonPrimitive.content)
        assertTrue(body["content_details"]!!.jsonObject.containsKey("faqs"))
    }

    @Test fun theOrganizerSendsCityAndVenueButNoVenueOnlyFields() {
        val form = EventForm(title = "Rassegna", cityId = 2, venue = NamedRef(5, "Circolo"), bookingUrl = "https://x.test", locationAddress = "Via Roma 1")
        val create = form.toJson(EventFormMode.OrganizerCreate, rome)
        assertEquals(JsonPrimitive(2), create["city_id"]); assertEquals(JsonPrimitive(5), create["venue_id"])
        assertNull(create["booking_url"]); assertNull(create["external_links"]); assertNull(create["publish"])
        assertEquals("Via Roma 1", create["custom_location"]!!.jsonObject["address"]!!.jsonPrimitive.content)
        // In modifica il luogo non c'è, come nel pannello.
        assertNull(form.toJson(EventFormMode.OrganizerEdit, rome)["custom_location"])
    }

    @Test fun aSavedEventReopensWithItsAgendaExtrasAndBeforeGoing() {
        val detail = json.decodeFromString<ManagedEventDetail>("""
            {"id":5,"title":"Serata","description":"<p>Ciao <em>tutti</em></p>","price_type":{"value":"ticket","label":"Biglietto"},"price_min":12.0,
             "category":{"id":4,"name":"Musica"},"content_details":{"stroller":"yes","feature_ids":[3,7],"introduction":"<p>Intro</p>",
             "agenda":[{"title":"Apertura","when":"20:00","occurrence_id":11,"starts_at":"2026-09-03T20:00"}],"minimum_age":18},
             "ticket_tiers":[{"id":3,"name":"Platea","price":10.0,"status":"available"}]}
        """.trimIndent())
        val form = EventForm.from(detail)
        assertEquals("Ciao *tutti*", form.description)
        assertEquals("12", form.priceMin)
        assertEquals(setOf(3L, 7L), form.featureIds)
        assertEquals("yes", form.stroller)
        assertEquals("18", form.editorial["minimum_age"])
        val agenda = form.toJson(EventFormMode.VenueEdit, rome)["content_details"]!!.jsonObject["agenda"]!!.jsonArray[0].jsonObject
        assertEquals(JsonPrimitive(11), agenda["occurrence_id"])
        assertEquals("Apertura", agenda["title"]!!.jsonPrimitive.content)
    }

    @Test fun aBlankFormStartsFromTheVenueHabitsAndFacebookFillsIt() {
        val options = json.decodeFromString<EventFormOptions>("""
            {"defaults":{"category_id":4,"price_type":"ticket","price_min":"10"},"before_going":{"kids_area":"yes","practical_custom":[{"label":"Parcheggio","icon":"truck","text":null}]}}
        """.trimIndent())
        val blank = EventForm.blank(options, EventFormMode.VenueCreate)
        assertEquals(4L, blank.categoryId); assertEquals("ticket", blank.priceType); assertEquals("10", blank.priceMin)
        assertEquals("yes", blank.kidsArea); assertEquals("Parcheggio", blank.practical.single().label)

        val fields = json.decodeFromString<JsonObject>("""
            {"title":"Da Facebook","description":"<p>Riga</p>","starts_at":"2026-09-12T21:00:00+02:00","price_type":"unknown","price_min":null,
             "custom_location":{"name":"Parco","address":"Via Verdi 2","lat":45.4,"lng":11.8},"external_links":[{"label":"Evento su Facebook","url":"https://www.facebook.com/events/1/"}]}
        """.trimIndent())
        val filled = blank.withFacebook(fields, rome)
        assertEquals("Da Facebook", filled.title)
        assertEquals(LocalDateTime.of(2026, 9, 12, 21, 0), filled.startsAt)
        assertEquals("unknown", filled.priceType); assertEquals("", filled.priceMin)
        assertEquals("Via Verdi 2", filled.locationAddress); assertEquals("45.4", filled.locationLat)
        assertEquals("Evento su Facebook", filled.externalLinks.single().label)
    }

    @Test fun theTicketingBoxIsForTheVenueThatManagesTicketing() {
        fun subject(kind: it.fabiodalez.incitta.data.management.SubjectKind, ticketing: Boolean) = it.fabiodalez.incitta.data.management.ManagementSubject(
            kind = kind, id = 12, slug = "circolo", name = "Circolo", cityName = "Torino", timezone = ZoneId.of("Europe/Rome"), role = if (ticketing) "owner" else "editor",
            status = "active", logo = null, ticketingEnabled = true, autoPublish = false,
            permissions = it.fabiodalez.incitta.data.management.SubjectPermissions(mapOf("ticketing_manage" to ticketing)))
        val venue = it.fabiodalez.incitta.data.management.SubjectKind.Venue
        val event = ManagedEventDetail(id = 9, priceType = it.fabiodalez.incitta.data.management.LabeledValue("ticket", "Biglietto"),
            abilities = it.fabiodalez.incitta.data.management.EventAbilities(update = true))
        assertTrue(showsEventTicketing(subject(venue, true), event))
        // Il collaboratore non gestisce la biglietteria, l'organizzazione non ha il riquadro.
        assertFalse(showsEventTicketing(subject(venue, false), event))
        assertFalse(showsEventTicketing(subject(it.fabiodalez.incitta.data.management.SubjectKind.Organizer, true), event))
        // Come nel pannello: niente riquadro per l'offerta libera o il prezzo da definire, né per chi non modifica l'evento.
        assertFalse(showsEventTicketing(subject(venue, true), event.copy(priceType = it.fabiodalez.incitta.data.management.LabeledValue("donation", "Offerta libera"))))
        assertFalse(showsEventTicketing(subject(venue, true), event.copy(abilities = it.fabiodalez.incitta.data.management.EventAbilities(update = false))))
    }
}
