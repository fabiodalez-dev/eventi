package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.User
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* P8: le forme di collaboratori, accesso e inviti, il conteggio di `/me` e i collegamenti d'invito aperti nell'app. */
class ManagementPeopleTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val origin = "https://eventi.fabiodalez.it/api/v1/"

    @Test fun readsTheMembersInTheShapeOfTheServer() {
        val page = json.decodeFromString<VenueMembersPage>(
            """{"data":[{"id":4,"name":null,"email":"m****@esempio.it","role":"editor","role_label":"Collaboratore","pending":true,
            "accepted_at":null,"invited_at":"2026-11-25T10:00:00+01:00","is_me":false,"can_resend":true,"can_withdraw":true,"can_remove":false}],
            "meta":{"next_page":2,"invite_expires_days":7,"invites_per_hour":10,"invites_per_day":30}}""")
        val row = page.data.single()
        assertNull(row.name)
        assertTrue(row.pending && row.canResend && row.canWithdraw && !row.canRemove)
        assertEquals(2, page.meta.nextPage)
        assertEquals(7, page.meta.inviteExpiresDays)
        /* Un server che non manda `meta` non rompe niente: valgono i tetti del sito. */
        assertEquals(VenueMembersMeta(), json.decodeFromString<VenueMembersPage>("""{"data":[]}""").meta)
    }

    @Test fun readsAccessAndInvitations() {
        val access = json.decodeFromString<ApiEnvelope<VenueAccess>>(
            """{"data":{"venue":{"id":12,"name":"Circolo Aurora"},"role":"owner","role_label":"Referente","member_since":null,"is_last_owner":true,"can_leave":false,"can_manage_collaborators":true}}""").data
        assertTrue(access.isOwner && access.isLastOwner && !access.canLeave && access.canManageCollaborators)

        val received = json.decodeFromString<ApiEnvelope<ReceivedInvitations>>(
            """{"data":{"venues":[{"venue":{"id":12,"slug":"aurora","name":"Circolo Aurora","city":"Padova"},"role":"editor","role_label":"Collaboratore","sent":1764061200}],
            "checkin":[{"id":9,"occurrence":{"id":41,"title":"Concerto","starts_at":"2026-12-01T21:00:00+01:00","venue":"Circolo Aurora"},"sent":1764061300}]}}""").data
        assertFalse(received.isEmpty)
        assertEquals(1764061200L, received.venues.single().sent)
        assertEquals(41L, received.checkin.single().occurrence.id)
        assertTrue(json.decodeFromString<ReceivedInvitations>("""{"venues":[],"checkin":[]}""").isEmpty)

        val answer = json.decodeFromString<ApiEnvelope<InvitationAnswer>>("""{"data":{"accepted":true,"message":"ok","subject":{"type":"venue","id":12}}}""").data
        assertEquals("venue:12", answer.subject?.key)
    }

    @Test fun bodiesCarryWhatTheServerAsks() {
        val invite = inviteBody("  Marta  ", " marta@esempio.it ")
        assertEquals(JsonPrimitive("Marta"), invite["name"])
        assertEquals(JsonPrimitive("marta@esempio.it"), invite["email"])
        assertNull("il ruolo non si sceglie", invite["role"])
        assertEquals(JsonPrimitive("accept"), answerBody(1764061200, true)["decision"])
        assertEquals(JsonPrimitive("decline"), answerBody(1764061200, false)["decision"])
        assertEquals(JsonPrimitive(1764061200L), answerBody(1764061200, false)["sent"])
    }

    @Test fun invitationsCountHasItsOwnProfileEntry() {
        fun user(management: String?): User = json.decodeFromString(
            """{"id":1,"email":"a@example.test"${management?.let { ",\"management\":$it" } ?: ""}}""")
        assertEquals(0, pendingInvitations(user(null)))
        assertEquals(2, pendingInvitations(user("""{"venues":0,"organizers":0,"checkin":0,"invitations":2}""")))
        /* Un invito non accende «Gestione eventi»: chi è solo invitato non gestisce ancora niente. */
        assertFalse(showsManagementEntry(user("""{"venues":0,"organizers":0,"checkin":0,"invitations":2}""")))
    }

    @Test fun invitationLinksOpenedInTheAppShowTheNativeList() {
        assertEquals(ManagementDestination.Invitations, ManagementDestination.parse("https://eventi.fabiodalez.it/inviti-locali/12/7/1764061200?expires=1&signature=abc", origin))
        assertEquals(ManagementDestination.Invitations, ManagementDestination.parse("https://eventi.fabiodalez.it/inviti-ingressi/9/1764061200?signature=abc", origin))
        listOf(
            "https://eventi.fabiodalez.it/inviti-locali/12",
            "https://eventi.fabiodalez.it/inviti-ingressi/9",
            "https://altro.example/inviti-locali/12/7/1764061200",
        ).forEach { assertNull(it, ManagementDestination.parse(it, origin)) }
    }
}
