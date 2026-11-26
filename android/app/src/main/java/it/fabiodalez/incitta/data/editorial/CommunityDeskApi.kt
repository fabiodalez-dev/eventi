package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage

/**
 * Le chiamate di community e passaggi (R7). Le schermate le fanno dentro
 * `repository.call { }`, che si occupa dei 423 (sblocco, e password recente
 * per sospendere, conservare e leggere le evidenze). La città non conta: il
 * pannello vede tutte le città in queste due pagine, e il server anche.
 */
internal interface CommunityDeskSource {
    suspend fun community(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCommunityItem>
    suspend fun featureProfile(id: Long, enabled: Boolean, reason: String): CommunityDeskResult<DeskCommunityItem>
    /** Pericolosa: 423 `PASSWORD_REQUIRED` senza la password degli ultimi 15 minuti. */
    suspend fun suspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem>
    suspend fun unsuspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem>
    /** `kind` è `posts` o `comments`. */
    suspend fun hideContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem>
    suspend fun restoreContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem>
    suspend fun removeRestriction(id: Long, reason: String): CommunityDeskResult<DeskCommunityItem>

    suspend fun carpool(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCarpoolItem>
    suspend fun carpoolCase(id: Long): DeskCarpoolCase
    suspend fun manageCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase>
    suspend fun replyCase(id: Long, recipientId: Long, message: String, revision: Int): CommunityDeskResult<DeskCarpoolCase>
    /** Pericolosa, come `suspendMember`: `op` è `hold` o `release`. */
    suspend fun holdCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase>
    suspend fun freezeChat(id: Long, freeze: Boolean, reason: String): CommunityDeskResult<DeskCarpoolCase>
    /** Pericolosa: la lettura resta nel registro dei passaggi con il motivo. */
    suspend fun caseEvidence(id: Long, reason: String): CaseEvidence
    suspend fun cancelRide(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem>
    suspend fun moderateRideReview(id: Long, visible: Boolean, reason: String): CommunityDeskResult<DeskCarpoolItem>
    /** Pericolosa, come `suspendMember`. */
    suspend fun suspendRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem>
    suspend fun restoreRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem>
    suspend fun retryDelivery(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem>
}

internal class CommunityDeskApi(private val api: ApiClient, private val token: String) : CommunityDeskSource {
    override suspend fun community(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCommunityItem> =
        api.get(editorialPath("community/$section", null, params), token)

    override suspend fun featureProfile(id: Long, enabled: Boolean, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.post(editorialPath("community/profiles/$id/feature", null), DeskFeatureBody(enabled, reason), token)

    override suspend fun suspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.post(editorialPath("community/users/$userId/suspend", null), DeskReasonBody(reason), token)

    override suspend fun unsuspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.post(editorialPath("community/users/$userId/unsuspend", null), DeskReasonBody(reason), token)

    override suspend fun hideContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.post(editorialPath("community/$kind/$id/hide", null), DeskReasonBody(reason), token)

    override suspend fun restoreContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.post(editorialPath("community/$kind/$id/restore", null), DeskReasonBody(reason), token)

    override suspend fun removeRestriction(id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> =
        api.delete(editorialPath("community/restrictions/$id", null), DeskReasonBody(reason), token)

    override suspend fun carpool(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCarpoolItem> =
        api.get(editorialPath("carpool/$section", null, params), token)

    override suspend fun carpoolCase(id: Long): DeskCarpoolCase =
        api.get<ApiEnvelope<DeskCarpoolCase>>(editorialPath("carpool/cases/$id", null), token).data

    override suspend fun manageCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> =
        api.post(editorialPath("carpool/cases/$id/manage", null), DeskCaseOperationBody(op, reason, revision), token)

    override suspend fun replyCase(id: Long, recipientId: Long, message: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> =
        api.post(editorialPath("carpool/cases/$id/reply", null), DeskReplyBody(recipientId, message, revision), token)

    override suspend fun holdCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> =
        api.post(editorialPath("carpool/cases/$id/hold", null), DeskCaseOperationBody(op, reason, revision), token)

    override suspend fun freezeChat(id: Long, freeze: Boolean, reason: String): CommunityDeskResult<DeskCarpoolCase> =
        api.post(editorialPath("carpool/cases/$id/freeze", null), DeskFreezeBody(freeze, reason), token)

    override suspend fun caseEvidence(id: Long, reason: String): CaseEvidence =
        api.post<ApiEnvelope<CaseEvidence>, DeskReasonBody>(editorialPath("carpool/cases/$id/evidence", null), DeskReasonBody(reason), token).data

    override suspend fun cancelRide(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem> =
        api.post(editorialPath("carpool/rides/$id/cancel", null), DeskReasonBody(reason), token)

    override suspend fun moderateRideReview(id: Long, visible: Boolean, reason: String): CommunityDeskResult<DeskCarpoolItem> =
        api.post(editorialPath("carpool/reviews/$id/moderate", null), DeskVisibleBody(visible, reason), token)

    override suspend fun suspendRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem> =
        api.post(editorialPath("carpool/users/$userId/suspend", null), DeskScopeBody(scope, reason), token)

    override suspend fun restoreRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem> =
        api.post(editorialPath("carpool/users/$userId/restore", null), DeskScopeBody(scope, reason), token)

    override suspend fun retryDelivery(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem> =
        api.post(editorialPath("carpool/deliveries/$id/retry", null), DeskReasonBody(reason), token)
}

/** L'API di community e passaggi per la sessione del repository (il client HTTP lo dà `EditorialRepository.create`). */
internal fun EditorialRepository.communityDeskApi(): CommunityDeskSource =
    CommunityDeskApi(client ?: throw IllegalStateException("Community non disponibile in questa sessione"), token)
