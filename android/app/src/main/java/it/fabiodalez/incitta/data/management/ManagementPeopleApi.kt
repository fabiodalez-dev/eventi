package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * P8 — collaboratori del locale, «Il tuo accesso» e gli inviti ricevuti.
 * Le forme sono quelle di `CollaboratorController`, `VenueAccessController`
 * e `InvitationController`; ogni campo ha un valore predefinito, così un
 * server più nuovo non rompe la lettura.
 */

/** Una persona del locale in `GET V/members`. Un invito in attesa ha `name` nullo ed `email` mascherata. */
@Serializable
internal data class VenueMember(
    val id: Long = 0,
    val name: String? = null,
    val email: String = "",
    /* `owner` (referente) o `editor` (collaboratore). */
    val role: String = "",
    @SerialName("role_label") val roleLabel: String = "",
    val pending: Boolean = false,
    @SerialName("accepted_at") val acceptedAt: String? = null,
    @SerialName("invited_at") val invitedAt: String? = null,
    @SerialName("is_me") val isMe: Boolean = false,
    @SerialName("can_resend") val canResend: Boolean = false,
    @SerialName("can_withdraw") val canWithdraw: Boolean = false,
    @SerialName("can_remove") val canRemove: Boolean = false,
)

@Serializable
internal data class VenueMembersMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    @SerialName("invite_expires_days") val inviteExpiresDays: Int = 7,
    @SerialName("invites_per_hour") val invitesPerHour: Int = 10,
    @SerialName("invites_per_day") val invitesPerDay: Int = 30,
)

@Serializable
internal data class VenueMembersPage(val data: List<VenueMember> = emptyList(), val meta: VenueMembersMeta = VenueMembersMeta())

/** Risposta di invito e reinvio: la riga aggiornata e il messaggio da mostrare. */
@Serializable
internal data class VenueMemberChange(val data: VenueMember = VenueMember(), val message: String = "")

@Serializable
internal data class ManagementNotice(val message: String = "")

/** `GET V/access`: «Il tuo accesso a questo locale». */
@Serializable
internal data class VenueAccess(
    val venue: AccessVenue = AccessVenue(),
    val role: String = "",
    @SerialName("role_label") val roleLabel: String = "",
    @SerialName("member_since") val memberSince: String? = null,
    @SerialName("is_last_owner") val isLastOwner: Boolean = false,
    @SerialName("can_leave") val canLeave: Boolean = false,
    @SerialName("can_manage_collaborators") val canManageCollaborators: Boolean = false,
) {
    val isOwner: Boolean get() = role == "owner"
}

@Serializable
internal data class AccessVenue(val id: Long = 0, val name: String = "")

/** `POST V/access/leave`: `remaining` sono i locali e le organizzazioni che restano da gestire. */
@Serializable
internal data class LeaveResult(val message: String = "", val remaining: Int = 0)

/** `GET /me/invitations`. */
@Serializable
internal data class ReceivedInvitations(
    val venues: List<VenueInvitation> = emptyList(),
    val checkin: List<CheckinInvitation> = emptyList(),
) {
    val isEmpty: Boolean get() = venues.isEmpty() && checkin.isEmpty()
}

@Serializable
internal data class VenueInvitation(
    val venue: InvitedVenue = InvitedVenue(),
    val role: String = "",
    @SerialName("role_label") val roleLabel: String = "",
    /* La data dell'invito: si rimanda con la risposta, e un invito reinviato nel frattempo non la riconosce. */
    val sent: Long = 0,
    @SerialName("invited_at") val invitedAt: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
)

@Serializable
internal data class InvitedVenue(val id: Long = 0, val slug: String = "", val name: String = "", val city: String? = null)

@Serializable
internal data class CheckinInvitation(
    val id: Long = 0,
    val occurrence: InvitedOccurrence = InvitedOccurrence(),
    val sent: Long = 0,
    @SerialName("invited_at") val invitedAt: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
)

@Serializable
internal data class InvitedOccurrence(
    val id: Long = 0,
    val title: String = "",
    @SerialName("starts_at") val startsAt: String? = null,
    val venue: String? = null,
)

/** La risposta a un invito: il locale in cui si è entrati, o la data di cui si controllano gli ingressi. */
@Serializable
internal data class InvitationAnswer(
    val accepted: Boolean = false,
    val message: String = "",
    val subject: AnsweredSubject? = null,
    @SerialName("occurrence_id") val occurrenceId: Long? = null,
)

@Serializable
internal data class AnsweredSubject(val type: String = "", val id: Long = 0) {
    /** La chiave di `ManagementSubject.key`: `venue:12`. */
    val key: String get() = "$type:$id"
}

/** Le chiamate di P8. È un'interfaccia perché i ViewModel si provano con un finto, senza rete. */
internal interface ManagementPeopleSource {
    suspend fun members(venueId: Long, query: String, page: Int): VenueMembersPage
    suspend fun invite(venueId: Long, name: String, email: String): VenueMemberChange
    suspend fun resend(venueId: Long, memberId: Long): VenueMemberChange
    suspend fun withdraw(venueId: Long, memberId: Long): String
    suspend fun remove(venueId: Long, memberId: Long): String
    suspend fun access(venueId: Long): VenueAccess
    suspend fun leave(venueId: Long): LeaveResult
    suspend fun invitations(): ReceivedInvitations
    suspend fun answerVenue(venueId: Long, sent: Long, accept: Boolean): InvitationAnswer
    suspend fun answerCheckin(invitationId: Long, sent: Long, accept: Boolean): InvitationAnswer
}

internal class ManagementPeopleApi(private val api: ApiClient, private val token: String) : ManagementPeopleSource {
    override suspend fun members(venueId: Long, query: String, page: Int): VenueMembersPage {
        val search = query.trim().takeIf { it.isNotEmpty() }?.let { "&q=" + URLEncoder.encode(it, "UTF-8") }.orEmpty()
        return api.get("${venuePath(venueId)}/members?page=$page$search", token)
    }

    override suspend fun invite(venueId: Long, name: String, email: String): VenueMemberChange =
        api.post<VenueMemberChange, JsonObject>("${venuePath(venueId)}/members", inviteBody(name, email), token)

    override suspend fun resend(venueId: Long, memberId: Long): VenueMemberChange =
        api.post<VenueMemberChange, JsonObject>("${venuePath(venueId)}/members/$memberId/resend", buildJsonObject {}, token)

    override suspend fun withdraw(venueId: Long, memberId: Long): String =
        api.delete<ApiEnvelope<ManagementNotice>>("${venuePath(venueId)}/members/$memberId/invitation", token).data.message

    override suspend fun remove(venueId: Long, memberId: Long): String =
        api.delete<ApiEnvelope<ManagementNotice>>("${venuePath(venueId)}/members/$memberId", token).data.message

    override suspend fun access(venueId: Long): VenueAccess =
        api.get<ApiEnvelope<VenueAccess>>("${venuePath(venueId)}/access", token).data

    override suspend fun leave(venueId: Long): LeaveResult =
        api.post<ApiEnvelope<LeaveResult>, JsonObject>("${venuePath(venueId)}/access/leave", buildJsonObject {}, token).data

    override suspend fun invitations(): ReceivedInvitations =
        api.get<ApiEnvelope<ReceivedInvitations>>("me/invitations", token).data

    override suspend fun answerVenue(venueId: Long, sent: Long, accept: Boolean): InvitationAnswer =
        api.post<ApiEnvelope<InvitationAnswer>, JsonObject>("me/invitations/venues/$venueId", answerBody(sent, accept), token).data

    override suspend fun answerCheckin(invitationId: Long, sent: Long, accept: Boolean): InvitationAnswer =
        api.post<ApiEnvelope<InvitationAnswer>, JsonObject>("me/invitations/checkin/$invitationId", answerBody(sent, accept), token).data

    private fun venuePath(id: Long) = "management/${SubjectKind.Venue.segment}/$id"
}

/** `POST V/members`: nome ed email; il ruolo non si sceglie, è sempre collaboratore. */
internal fun inviteBody(name: String, email: String): JsonObject = buildJsonObject {
    put("name", name.trim())
    put("email", email.trim())
}

/** La risposta a un invito: `decision` e la data dell'invito che si è visto. */
internal fun answerBody(sent: Long, accept: Boolean): JsonObject = buildJsonObject {
    put("decision", if (accept) "accept" else "decline")
    put("sent", sent)
}
