package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.*
import org.junit.Test

class VenueReviewsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Test fun `empty public reviews accept null average and no personal review`() {
        val page = json.decodeFromString<ApiEnvelope<VenueReviewPage>>("""{"data":{"count":0,"average":null,"reviews":[],"page":1,"last_page":1,"my_review":null}}""").data
        assertEquals(0, page.count)
        assertNull(page.average)
        assertNull(page.myReview)
    }

    @Test fun `approved reviews and private rejection feedback decode independently`() {
        val page = json.decodeFromString<VenueReviewPage>("""{"count":11,"average":4.5,"page":2,"last_page":2,"reviews":[{"id":12,"author":"Anna","rating":5,"body":"Locale accogliente.","date":"2026-09-13"}],"my_review":{"rating":2,"body":"Esperienza diversa.","status":"rejected","moderation_note":"Rimuovi i dati personali."}}""")
        assertEquals(2, page.page)
        assertEquals(2, page.lastPage)
        assertEquals(5, page.reviews.single().rating)
        assertEquals("rejected", page.myReview?.status)
        assertEquals("Rimuovi i dati personali.", page.myReview?.moderationNote)
    }

    @Test fun `review updates carry their optimistic revision and omit an empty comment`() {
        val payload = json.encodeToString(VenueReviewBody(rating = 4, body = null, revision = 3))
        val objectPayload = json.parseToJsonElement(payload).jsonObject

        assertEquals("4", objectPayload.getValue("rating").jsonPrimitive.content)
        assertEquals("3", objectPayload.getValue("revision").jsonPrimitive.content)
        assertFalse(objectPayload.containsKey("body"))
    }

    @Test fun `v1 placeholders for a missing vote or comment read as absent`() {
        val page = json.decodeFromString<VenueReviewPage>("""{"count":2,"reviews":[{"id":1,"author":"Anna","rating":0,"body":"Locale accogliente.","date":"2026-09-13"},{"id":2,"author":"Luca","rating":4,"body":""}],"my_review":{"rating":0,"body":"Solo un commento.","status":"pending"}}""")
        assertNull(page.reviews[0].givenRating)
        assertEquals("Locale accogliente.", page.reviews[0].givenBody)
        assertEquals(4, page.reviews[1].givenRating)
        assertNull(page.reviews[1].givenBody)
        assertNull(page.myReview?.givenRating)
        assertEquals("Solo un commento.", page.myReview?.givenBody)
    }
}
