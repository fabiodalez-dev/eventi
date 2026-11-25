package it.fabiodalez.incitta.data.management

import java.net.URI
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* Le sponsorizzazioni (P6): lettura delle risposte, indirizzi delle chiamate e ritorno da Stripe. */
class ManagementSponsorshipsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val venue = ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora", role = "owner").toSubject()
    private val origin = "https://eventi.fabiodalez.it/api/v1/"

    @Test fun readsTheWholePageWithTheServerTexts() {
        val desk = json.decodeFromString<SponsorshipDeskEnvelope>("""{"data":{
            "can_purchase":true,"pays_online":false,
            "how":{"title":"Come funziona","steps":["Uno","Due","Tre"],"disclosure":"Sponsorizzato"},
            "not_owner":null,"limits":{"requests_per_hour":5,"max_open_requests":3,"note_max":1000,"events_max":60},
            "sponsorable_count":2,
            "selectable_grants":[{"id":4,"placement":{"value":"list_top","label":"In cima alle liste"},"ends_at":"2026-12-24T23:00:00+01:00","active_event_ids":[9]}],
            "orders":[{"id":7,"placement":{"value":"home_hero","label":"Apertura"},"months":3,"months_label":"3 mesi","what":"Tutti i miei eventi",
                "amount_label":"Prezzo da concordare","status":{"value":"requested","label":"Richiesta","color":"info"},"hint":"La redazione la sta valutando","can_withdraw":true,"novita":1}],
            "totals":{"impressions":1240,"clicks":31},
            "grants":[{"id":4,"placement":{"value":"list_top","label":"In cima"},"mode":"selected","mode_label":"Eventi scelti","status_label":"Attiva",
                "progress":{"goal":3000,"delivered":1240,"percent":41.3,"expected_percent":50.0,"color":"warning","state":"behind","badge":"In ritardo","show_badge":true,"phrase":"1.240 di 3.000","notes":["La tacca…"]},
                "events":[{"id":30,"event":{"id":9,"title":"Concerto","date":"2026-11-28T21:00:00+01:00"},"impressions":1240,"clicks":31,"numbers":"1.240 visualizzazioni · 31 aperture","active":true,"can_stop":true}]}],
            "campaigns":[{"id":31,"phase":{"value":"running","label":"In corso","color":"success"}}],"campaigns_next_page":2
        }}""").data
        assertTrue(desk.canPurchase)
        assertFalse(desk.paysOnline)
        assertEquals(listOf("Uno", "Due", "Tre"), desk.how.steps)
        assertEquals(listOf(9L), desk.selectableGrants.single().activeEventIds)
        assertTrue(desk.orders.single().canWithdraw)
        assertFalse(desk.orders.single().canRetry)
        assertEquals("Richiesta", desk.orders.single().status.label)
        val progress = desk.grants.single().progress
        assertEquals(0.413f, progress.fraction, 0.0001f)
        assertEquals(0.5f, progress.expectedFraction!!, 0.0001f)
        assertTrue(desk.grants.single().events.single().canStop)
        assertEquals(2, desk.campaignsNextPage)
    }

    @Test fun keepsTheBarInsideItsTrack() {
        assertEquals(1f, SponsorshipProgress(percent = 140.0).fraction, 0f)
        assertEquals(0f, SponsorshipProgress(percent = -3.0).fraction, 0f)
        assertNull(SponsorshipProgress().expectedFraction)
    }

    @Test fun readsAnOrderResultWithTheCheckoutPage() {
        val result = json.decodeFromString<SponsorshipOrderEnvelope>("""{"data":{"mode":"checkout","checkout_url":"https://checkout.stripe.com/c/pay/cs_1","order":{"id":5,"status":{"value":"pending_payment","label":"In attesa"},"can_retry":true}},"message":"Si apre Stripe"}""")
        assertEquals("checkout", result.data.mode)
        assertEquals("https://checkout.stripe.com/c/pay/cs_1", result.data.checkoutUrl)
        assertEquals(5L, result.data.order?.id)
        assertEquals("Si apre Stripe", result.message)
    }

    @Test fun buildsTheEventSearchWithItsLimits() {
        assertEquals("management/venues/12/sponsorships/events?limit=6", sponsorshipEventsPath(venue, "  ", 6, null))
        assertEquals("management/venues/12/sponsorships/events?q=jazz%20club&limit=60&grant=4", sponsorshipEventsPath(venue, "jazz club", 500, 4))
        assertTrue(sponsorshipEventsPath(venue, "x".repeat(200), 1, null).contains("q=" + "x".repeat(SPONSORSHIP_SEARCH_MAX) + "&"))
    }

    @Test fun sendsTheDatesOnlyForTheCustomPeriod() {
        assertEquals("days=30", SponsorshipFilters().query())
        assertEquals("days=7&campaign=4&page=2", SponsorshipFilters(days = "7", from = "2026-11-01", campaignId = 4).query(2))
        assertEquals("days=custom&from=2026-11-01&to=2026-11-20", SponsorshipFilters(days = "custom", from = "2026-11-01", to = "2026-11-20").query())
    }

    @Test fun recognisesTheReturnFromStripe() {
        val paid = ManagementDestination.parse("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?subject=venue&id=12&order=34", origin)
        assertEquals(ManagementDestination.SponsorshipCheckout(SponsorshipReturn(SubjectKind.Venue, 12, 34, cancelled = false)), paid)
        assertEquals("venue:12", (paid as ManagementDestination.SponsorshipCheckout).result.subjectKey)

        val cancelled = SponsorshipReturn.parse(URI("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?subject=organizer&id=3&order=9&cancelled=1"))
        assertEquals(SponsorshipReturn(SubjectKind.Organizer, 3, 9, cancelled = true), cancelled)
        assertEquals("organizer:3", cancelled!!.subjectKey)
    }

    @Test fun ignoresAReturnThatIsNotOurs() {
        assertNull(ManagementDestination.parse("https://altro.example/app/sponsorizzazioni/ritorno?subject=venue&id=12&order=34", origin))
        assertNull(SponsorshipReturn.parse(URI("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?subject=admin&id=12&order=34")))
        assertNull(SponsorshipReturn.parse(URI("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?subject=venue&id=0&order=34")))
        assertNull(SponsorshipReturn.parse(URI("https://eventi.fabiodalez.it/app/sponsorizzazioni/ritorno?subject=venue&id=12")))
        assertNull(SponsorshipReturn.parse(URI("https://eventi.fabiodalez.it/app/altro?subject=venue&id=12&order=34")))
    }
}
