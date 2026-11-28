package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R7 — Community e passaggi: le righe delle pagine «Profili e post» e
 * «Passaggi in auto» del pannello, come le manda
 * `/api/v1/editorial/{community,carpool}/…`. Ogni sezione ha la sua `kind`
 * e i suoi campi; una riga sola per elenco tiene insieme le schede, come la
 * pagina del pannello. Ogni campo ha un valore predefinito: un server più
 * nuovo con un campo in più o in meno non rompe la lettura. Le azioni
 * permesse arrivano in `abilities` (vedi `abilitiesOf`).
 */

/** Le quattro schede di «Profili e post», nell'ordine del pannello. */
internal object CommunitySection {
    const val PROFILES = "profiles"
    const val POSTS = "posts"
    const val COMMENTS = "comments"
    const val RESTRICTIONS = "restrictions"
    val all = listOf(PROFILES, POSTS, COMMENTS, RESTRICTIONS)
}

/** Le sette schede di «Passaggi in auto», nell'ordine del pannello, e quelle che chiedono anche `carpool_manage`. */
internal object CarpoolSection {
    const val CASES = "cases"
    const val RIDES = "rides"
    const val REVIEWS = "reviews"
    const val FEEDBACK = "feedback"
    const val USERS = "users"
    const val AUDIT = "audit"
    const val DELIVERIES = "deliveries"
    val all = listOf(CASES, RIDES, REVIEWS, FEEDBACK, USERS, AUDIT, DELIVERIES)
    val needsCarpoolManage = setOf(RIDES, USERS, DELIVERIES, REVIEWS, FEEDBACK)
    /* Il pannello non cerca fra i casi né fra le consegne. */
    val searchable = setOf(RIDES, REVIEWS, FEEDBACK, USERS, AUDIT)
    /* Il filtro «Stato» c'è per i casi (`carpool_case_statuses`) e per i passaggi (`ride_statuses`). */
    val withStatus = setOf(CASES, RIDES)
}

/** Gli ambiti di una sospensione nella pagina dei passaggi. */
internal object RestrictionScope {
    const val SOCIAL = "social"
    const val CARPOOL = "carpool"
    const val GLOBAL = "global"
    val all = listOf(SOCIAL, CARPOOL, GLOBAL)
}

/** Le operazioni di un caso con la revisione: quelle di «manage» e quelle della conservazione. */
internal object CaseOperation {
    const val ASSIGN = "assign"
    const val NOTE = "note"
    const val RESOLVE = "resolve"
    const val REOPEN = "reopen"
    const val HOLD = "hold"
    const val RELEASE = "release"
}

/** Una riga di «Profili e post»: profilo, pubblicazione, commento o serata bloccata. */
@Serializable
internal data class DeskCommunityItem(
    /* `profile`, `post`, `comment`, `restriction`. */
    val kind: String = "",
    val id: Long = 0,
    @SerialName("user_id") val userId: Long = 0,
    /* Profilo. */
    @SerialName("display_name") val displayName: String? = null,
    val handle: String? = null,
    val bio: String? = null,
    val visibility: String? = null,
    @SerialName("visibility_label") val visibilityLabel: String? = null,
    val featured: Boolean = false,
    val suspended: Boolean = false,
    /* Pubblicazione e commento. */
    val author: String? = null,
    val body: String? = null,
    val hidden: Boolean = false,
    @SerialName("event_title") val eventTitle: String? = null,
    /* Serata bloccata. */
    val name: String? = null,
    @SerialName("occurrence_id") val occurrenceId: Long = 0,
    @SerialName("starts_at") val startsAt: String? = null,
    val timezone: String? = null,
    val removed: Boolean = false,
    val abilities: JsonElement? = null,
) {
    /** La chiave della riga: gli id delle quattro sezioni si possono ripetere. */
    val key: String get() = "$kind-$id"
}

/** Una riga di «Passaggi in auto»: caso, passaggio, recensione, feedback, persona, registro o consegna. */
@Serializable
internal data class DeskCarpoolItem(
    /* `case`, `ride`, `review`, `feedback`, `user`, `audit`, `delivery`. */
    val kind: String = "",
    val id: Long = 0,
    /* Caso. */
    val reporter: String? = null,
    val status: String? = null,
    @SerialName("status_label") val statusLabel: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    val assignee: String? = null,
    val reason: String? = null,
    /* Passaggio. */
    val title: String? = null,
    val leg: String? = null,
    @SerialName("leg_label") val legLabel: String? = null,
    val driver: String? = null,
    val zone: String? = null,
    @SerialName("departure_at") val departureAt: String? = null,
    val capacity: Int = 0,
    @SerialName("requests_count") val requestsCount: Int = 0,
    /* Recensione e feedback. */
    val author: String? = null,
    val rating: Int? = null,
    val body: String? = null,
    @SerialName("confirmed_at") val confirmedAt: String? = null,
    @SerialName("ride_request_id") val rideRequestId: Long = 0,
    val hidden: Boolean = false,
    @SerialName("feedback_kind") val feedbackKind: String? = null,
    @SerialName("kind_label") val kindLabel: String? = null,
    /* Persona. */
    val name: String? = null,
    @SerialName("email_verified_at") val emailVerifiedAt: String? = null,
    @SerialName("whatsapp_verified_at") val whatsappVerifiedAt: String? = null,
    @SerialName("social_suspended") val socialSuspended: Boolean = false,
    @SerialName("carpool_suspended") val carpoolSuspended: Boolean = false,
    @SerialName("community_suspended") val communitySuspended: Boolean = false,
    /* Registro. */
    val action: String? = null,
    @SerialName("subject_type") val subjectType: String? = null,
    @SerialName("subject_id") val subjectId: Long? = null,
    val actor: String? = null,
    /* Consegna. */
    @SerialName("user_id") val userId: Long = 0,
    val attempts: Int = 0,
    @SerialName("last_error") val lastError: String? = null,
    @SerialName("available_at") val availableAt: String? = null,
    val retried: Boolean = false,
    val abilities: JsonElement? = null,
) {
    val key: String get() = "$kind-$id"

    /** Se la persona è sospesa nell'ambito (`social`, `carpool`, `global`). */
    fun suspendedIn(scope: String): Boolean = when (scope) {
        RestrictionScope.SOCIAL -> socialSuspended
        RestrictionScope.CARPOOL -> carpoolSuspended
        RestrictionScope.GLOBAL -> communitySuspended
        else -> false
    }
}

@Serializable
internal data class CaseReviewSnapshot(val rating: Int? = null, val body: String? = null)

@Serializable
internal data class CaseSocialReference(val type: String = "", val id: Long = 0)

@Serializable
internal data class CaseMessage(
    val id: Long = 0,
    val author: String? = null,
    val recipient: String? = null,
    /* Nota interna: la legge solo lo staff. */
    val internal: Boolean = false,
    val body: String = "",
    @SerialName("created_at") val createdAt: String? = null,
)

/** Una persona a cui si può rispondere: chi ha segnalato, il conducente, chi ha chiesto il posto. */
@Serializable
internal data class CasePerson(val id: Long = 0, val label: String = "")

/** Il caso aperto, come la sezione in cima alla pagina dei passaggi del pannello. */
@Serializable
internal data class DeskCarpoolCase(
    val id: Long = 0,
    val reporter: String? = null,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    val assignee: String? = null,
    val reason: String = "",
    val body: String? = null,
    val review: CaseReviewSnapshot? = null,
    val social: CaseSocialReference? = null,
    @SerialName("ride_offer_id") val rideOfferId: Long? = null,
    @SerialName("ride_request_id") val rideRequestId: Long? = null,
    @SerialName("closed_at") val closedAt: String? = null,
    val revision: Int = 0,
    /* La scadenza della conservazione: solo a chi la può gestire. */
    @SerialName("hold_until") val holdUntil: String? = null,
    val messages: List<CaseMessage> = emptyList(),
    val people: List<CasePerson> = emptyList(),
    val abilities: JsonElement? = null,
)

@Serializable
internal data class EvidenceMessage(val id: Long = 0, val body: String = "", val at: String? = null)

@Serializable
internal data class EvidenceAuditEntry(
    val id: Long = 0,
    val action: String = "",
    @SerialName("actor_id") val actorId: Long? = null,
    val at: String? = null,
)

/** Le evidenze di un caso: la lettura resta nel registro dei passaggi con il motivo. */
@Serializable
internal data class CaseEvidence(
    @SerialName("case_id") val caseId: Long = 0,
    @SerialName("request_id") val requestId: Long? = null,
    val review: CaseReviewSnapshot? = null,
    val messages: List<EvidenceMessage> = emptyList(),
    val audit: List<EvidenceAuditEntry>? = null,
    @SerialName("generated_at") val generatedAt: String? = null,
    @SerialName("operator_id") val operatorId: Long = 0,
    val sha256: String = "",
)

@Serializable
internal data class CommunityDeskMeta(val message: String? = null)

/** La risposta di un'azione: la riga (o il caso) aggiornata e il messaggio del server. */
@Serializable
internal data class CommunityDeskResult<T>(val data: T, val meta: CommunityDeskMeta = CommunityDeskMeta())

@Serializable
internal data class DeskReasonBody(val reason: String)

@Serializable
internal data class DeskFeatureBody(val enabled: Boolean, val reason: String)

@Serializable
internal data class DeskFreezeBody(val freeze: Boolean, val reason: String)

@Serializable
internal data class DeskVisibleBody(val visible: Boolean, val reason: String)

@Serializable
internal data class DeskScopeBody(val scope: String, val reason: String)

@Serializable
internal data class DeskCaseOperationBody(val op: String, val reason: String, val revision: Int)

@Serializable
internal data class DeskReplyBody(
    @SerialName("recipient_id") val recipientId: Long,
    val message: String,
    val revision: Int,
)
