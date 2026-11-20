package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.managementCount
import it.fabiodalez.incitta.ui.pacingStateLabel
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* L'avanzamento di una campagna nella schermata di gestione. */
class CampaignProgressTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    @Test fun readsGoalDeliveredAndPacingState() {
        val campaign = json.decodeFromString<ApiEnvelope<ManagedCampaign>>("""{"data":{"id":4,"title":"Concerto","starts_at":"2026-11-01T00:00:00+01:00","ends_at":"2026-11-30T00:00:00+01:00","goal":3000,"delivered":1240,"pacing_state":"on_track","economics":null}}""").data
        assertEquals(3000L, campaign.goal)
        assertEquals(1240L, campaign.delivered)
        assertEquals("on_track", campaign.pacingState)
        assertEquals(1240f / 3000f, campaign.progress!!, 0.0001f)
    }

    @Test fun showsOnlyTheNumberForAPeriodCampaign() {
        val campaign = json.decodeFromString<ApiEnvelope<ManagedCampaign>>("""{"data":{"id":4,"title":"Concerto","goal":null,"delivered":87,"pacing_state":null}}""").data
        assertNull(campaign.goal)
        assertNull(campaign.progress)
        assertEquals(87L, campaign.delivered)
    }

    @Test fun keepsWorkingWithAServerThatSendsNoProgress() {
        val campaign = json.decodeFromString<ApiEnvelope<ManagedCampaign>>("""{"data":{"id":4,"title":"Concerto","starts_at":"2026-11-01T00:00:00+01:00","ends_at":"2026-11-30T00:00:00+01:00"}}""").data
        assertNull(campaign.delivered)
        assertNull(campaign.goal)
        assertNull(campaign.progress)
    }

    @Test fun keepsTheBarBetweenEmptyAndFull() {
        assertEquals(1f, ManagedCampaign(1, goal = 100, delivered = 140).progress!!, 0f)
        assertEquals(0f, ManagedCampaign(1, goal = 100, delivered = null).progress!!, 0f)
        assertNull(ManagedCampaign(1, goal = 0, delivered = 10).progress)
    }

    @Test fun namesEveryPacingStateAndIgnoresUnknownOnes() {
        assertEquals(R.string.management_pacing_on_track, pacingStateLabel("on_track"))
        assertEquals(R.string.management_pacing_slightly_behind, pacingStateLabel("slightly_behind"))
        assertEquals(R.string.management_pacing_behind, pacingStateLabel("behind"))
        assertEquals(R.string.management_pacing_ahead, pacingStateLabel("ahead"))
        assertEquals(R.string.management_pacing_at_risk, pacingStateLabel("at_risk"))
        assertEquals(R.string.management_pacing_completed, pacingStateLabel("completed"))
        assertEquals(R.string.management_pacing_missed, pacingStateLabel("missed"))
        assertNull(pacingStateLabel("something_new"))
        assertNull(pacingStateLabel(null))
    }

    @Test fun writesNumbersTheItalianWay() {
        assertEquals("1.240", managementCount(1240))
        assertEquals("3.000", managementCount(3000))
        assertEquals("87", managementCount(87))
    }
}
