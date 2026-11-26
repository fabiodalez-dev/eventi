package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementMediaApi
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.data.management.VenueProfile
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import java.io.File
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * R4 — Locali, richieste, organizzatori della redazione (`E/venues`,
 * `E/applications`, `E/organizers`). Ogni campo ha un valore predefinito: un
 * server diverso non deve impedire di aprire l'elenco. Le azioni permesse di
 * ogni riga arrivano in `abilities` (vedi `ActionMenu`).
 */

@Serializable
internal data class PlaceRef(val id: Long = 0, val name: String = "")

/** Una riga dell'elenco dei locali. */
@Serializable
internal data class EditorialVenueRow(
    val id: Long = 0,
    val name: String = "",
    val slug: String = "",
    val municipality: String? = null,
    val subtitle: String = "",
    val type: ValueLabel = ValueLabel(),
    val status: ValueLabel = ValueLabel(),
    @SerialName("is_verified") val isVerified: Boolean = false,
    @SerialName("auto_publish") val autoPublish: Boolean = false,
    val city: PlaceRef? = null,
    @SerialName("events_count") val eventsCount: Int = 0,
    @SerialName("cover_url") val coverUrl: String? = null,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class VenueUpcomingEvent(
    val id: Long = 0,
    val title: String = "",
    @SerialName("starts_at") val startsAt: String? = null,
    val category: String? = null,
    val status: ValueLabel = ValueLabel(),
)

@Serializable
internal data class VenueMemberSummary(val name: String = "", val role: String? = null, val pending: Boolean = false)

/** La scheda in sola lettura di un locale (`GET E/venues/{id}`). */
@Serializable
internal data class EditorialVenueOverview(
    val id: Long = 0,
    val name: String = "",
    val subtitle: String = "",
    val type: ValueLabel = ValueLabel(),
    val status: ValueLabel = ValueLabel(),
    @SerialName("is_verified") val isVerified: Boolean = false,
    @SerialName("auto_publish") val autoPublish: Boolean = false,
    val city: PlaceRef? = null,
    @SerialName("events_count") val eventsCount: Int = 0,
    @SerialName("cover_url") val coverUrl: String? = null,
    val address: String? = null,
    @SerialName("address_extra") val addressExtra: String? = null,
    @SerialName("postal_code") val postalCode: String? = null,
    val municipality: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val website: String? = null,
    val capacity: Long? = null,
    val plan: ValueLabel = ValueLabel(),
    @SerialName("rejection_reason") val rejectionReason: String? = null,
    val description: String? = null,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    @SerialName("site_url") val siteUrl: String? = null,
    val upcoming: List<VenueUpcomingEvent> = emptyList(),
    val members: List<VenueMemberSummary> = emptyList(),
    val abilities: JsonElement? = null,
)

@Serializable
internal data class LocatedPoint(val lat: Double = 0.0, val lng: Double = 0.0, val message: String = "")

@Serializable
internal data class VenueModerationResult(val message: String = "", val venue: EditorialVenueOverview = EditorialVenueOverview())

/** I campi del modulo che tiene solo la redazione. */
@Serializable
internal data class VenueEditorialFields(
    val name: String = "",
    val slug: String = "",
    @SerialName("city_id") val cityId: Long = 0,
    val type: String = "",
    @SerialName("province_code") val provinceCode: String = "",
    val status: String = "",
    @SerialName("is_verified") val isVerified: Boolean = false,
    @SerialName("auto_publish") val autoPublish: Boolean = false,
    @SerialName("is_nonprofit") val isNonprofit: Boolean = false,
    val plan: String = "free",
    @SerialName("rejection_reason") val rejectionReason: String? = null,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    @SerialName("seo_indexing") val seoIndexing: String = "automatic",
    @SerialName("is_demo") val isDemo: Boolean = false,
    @SerialName("can_ticketing") val canTicketing: Boolean = false,
)

/** Le scelte del modulo del locale (`GET E/venues/choices` e `editorial_choices` del modulo). */
internal class VenueChoices(val raw: JsonObject = JsonObject(emptyMap())) {
    private val options = ManagementOptions(raw)
    val cities: List<OptionChoice> get() = options.choices("cities")
    val types: List<OptionChoice> get() = options.choices("types")
    val statuses: List<OptionChoice> get() = options.choices("statuses")
    val plans: List<OptionChoice> get() = options.choices("plans")
    val seoIndexing: List<OptionChoice> get() = options.choices("seo_indexing")
    val districts: List<OptionChoice> get() = options.choices("districts")
    val districtMunicipality: String get() = options.choices("district_municipality").firstOrNull()?.value ?: "Padova"
    /** Il centro di una città («Centra sulla città»), come testo da mettere nei campi. */
    fun center(cityId: Long): Pair<String, String>? {
        val point = options.choices("centers", cityId.toString()).associate { it.value to it.label }
        val lat = point["lat"]?.takeIf { it.isNotBlank() } ?: return null
        val lng = point["lng"]?.takeIf { it.isNotBlank() } ?: return null
        return lat to lng
    }

    /** I comuni ammessi per una città (la sua provincia). */
    fun municipalities(cityId: Long): List<OptionChoice> = options.choices("municipalities", cityId.toString())
}

/**
 * Il modulo del locale (`GET E/venues/{id}/form`): la scheda pubblica nella
 * forma di «Il tuo locale» (`VenueProfile`, così le sezioni si disegnano con
 * gli stessi campi della gestione), i campi della redazione e le scelte.
 */
internal data class EditorialVenueForm(
    val profile: VenueProfile = VenueProfile(),
    val editorial: VenueEditorialFields = VenueEditorialFields(),
    val choices: VenueChoices = VenueChoices(),
    val options: ManagementOptions = ManagementOptions(),
) {
    companion object {
        private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

        fun from(data: JsonObject): EditorialVenueForm = EditorialVenueForm(
            profile = json.decodeFromJsonElement(VenueProfile.serializer(), data),
            editorial = (data["editorial"] as? JsonObject)?.let { json.decodeFromJsonElement(VenueEditorialFields.serializer(), it) } ?: VenueEditorialFields(),
            choices = VenueChoices(data["editorial_choices"] as? JsonObject ?: JsonObject(emptyMap())),
            options = ManagementOptions.from(data["profile_options"]),
        )
    }
}

@Serializable
internal data class VenueEventRow(
    val id: Long = 0,
    val title: String = "",
    val category: String? = null,
    val status: ValueLabel = ValueLabel(),
    @SerialName("occurrences_count") val occurrencesCount: Int = 0,
    @SerialName("published_at") val publishedAt: String? = null,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class VenueActivityRow(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val event: ValueLabel = ValueLabel(),
    val causer: String = "",
    val changes: String = "",
    @SerialName("restorable_fields") val restorableFields: List<String> = emptyList(),
    val abilities: JsonElement? = null,
)

@Serializable
internal data class VenueMemberRow(
    val id: Long = 0,
    val name: String = "",
    val email: String = "",
    val role: ValueLabel? = null,
    @SerialName("invited_at") val invitedAt: String? = null,
    @SerialName("accepted_at") val acceptedAt: String? = null,
    val pending: Boolean = false,
)

@Serializable
internal data class VenueMembers(
    val members: List<VenueMemberRow> = emptyList(),
    val roles: List<ValueLabel> = emptyList(),
    val message: String? = null,
)

@Serializable
internal data class PersonChoice(val id: Long = 0, val name: String = "", val email: String = "")

/** Una riga della coda delle richieste. */
@Serializable
internal data class ApplicationRow(
    val id: Long = 0,
    @SerialName("venue_name") val venueName: String = "",
    val kind: ValueLabel = ValueLabel(),
    val type: ValueLabel? = null,
    val status: ValueLabel = ValueLabel(),
    @SerialName("contact_name") val contactName: String = "",
    @SerialName("contact_email") val contactEmail: String = "",
    @SerialName("possible_duplicate") val possibleDuplicate: Boolean = false,
    val reviewer: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class ApplicationDuplicate(val kind: String = "", val id: Long = 0, val label: String = "", val active: Boolean = true)

@Serializable
internal data class ApplicationLinked(val kind: String = "", val id: Long = 0, val name: String = "", val url: String? = null)

@Serializable
internal data class ApplicationPublicContacts(
    val website: String? = null,
    @SerialName("public_email") val publicEmail: String? = null,
    @SerialName("public_phone") val publicPhone: String? = null,
    val instagram: String? = null,
    val facebook: String? = null,
)

@Serializable
internal data class ApplicationAccount(val id: Long = 0, val name: String = "", val email: String = "", val verified: Boolean = false)

/** La pagina di una richiesta (`GET E/applications/{id}`). */
@Serializable
internal data class ApplicationDetail(
    val id: Long = 0,
    @SerialName("venue_name") val venueName: String = "",
    val kind: ValueLabel = ValueLabel(),
    val type: ValueLabel? = null,
    val status: ValueLabel = ValueLabel(),
    @SerialName("contact_name") val contactName: String = "",
    @SerialName("contact_email") val contactEmail: String = "",
    @SerialName("possible_duplicate") val possibleDuplicate: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("outcome_hint") val outcomeHint: String = "",
    @SerialName("queued_at") val queuedAt: String? = null,
    @SerialName("reviewed_at") val reviewedAt: String? = null,
    @SerialName("reviewed_by") val reviewedBy: String? = null,
    val linked: ApplicationLinked? = null,
    val duplicates: List<ApplicationDuplicate> = emptyList(),
    val address: String? = null,
    @SerialName("postal_code") val postalCode: String? = null,
    val municipality: String? = null,
    val capacity: String? = null,
    val description: String? = null,
    val message: String? = null,
    @SerialName("message_label") val messageLabel: String = "",
    @SerialName("public_contacts") val publicContacts: ApplicationPublicContacts? = null,
    @SerialName("has_image") val hasImage: Boolean = false,
    @SerialName("contact_role") val contactRole: String? = null,
    @SerialName("contact_phone") val contactPhone: String? = null,
    val account: ApplicationAccount? = null,
    val notes: String? = null,
    @SerialName("notes_max") val notesMax: Int = 5000,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class ApplicationOutcome(
    val message: String = "",
    val detail: String? = null,
    val application: ApplicationDetail = ApplicationDetail(),
)

@Serializable
internal data class OrganizerRow(
    val id: Long = 0,
    val name: String = "",
    val city: PlaceRef? = null,
    val owner: PersonChoice? = null,
    @SerialName("is_active") val isActive: Boolean = false,
    @SerialName("status_label") val statusLabel: String = "",
    val abilities: JsonElement? = null,
)

/** Il modulo di un organizzatore (`GET E/organizers/{id}`). */
@Serializable
internal data class OrganizerDetail(
    val id: Long = 0,
    val name: String = "",
    val city: PlaceRef? = null,
    val owner: PersonChoice? = null,
    val users: List<PersonChoice> = emptyList(),
    @SerialName("is_active") val isActive: Boolean = false,
    val description: String? = null,
    val website: String? = null,
    val email: String? = null,
    @SerialName("contact_mode") val contactMode: String = "disabled",
    @SerialName("contact_email") val contactEmail: String? = null,
    @SerialName("contact_confirmed") val contactConfirmed: Boolean = false,
    @SerialName("public_url") val publicUrl: String? = null,
    val choices: JsonObject = JsonObject(emptyMap()),
) {
    val cities: List<OptionChoice> get() = ManagementOptions(choices).choices("cities")
    val contactModes: List<OptionChoice> get() = ManagementOptions(choices).choices("contact_modes")
}

/** Le chiamate del pacchetto: un'interfaccia, così i ViewModel si provano senza rete. */
internal interface EditorialVenuesSource {
    suspend fun venues(params: List<Pair<String, String>>): EditorialPage<EditorialVenueRow>
    suspend fun exportVenues(params: List<Pair<String, String>>, target: File): DownloadedFile
    suspend fun venueChoices(): VenueChoices
    /** «Trova»: il punto dall'indirizzo e dal comune; 422 su `address` se non si trova. */
    suspend fun locate(address: String, municipality: String): LocatedPoint
    suspend fun venue(id: Long): EditorialVenueOverview
    suspend fun venueForm(id: Long): EditorialVenueForm
    suspend fun createVenue(body: JsonObject): EditorialVenueForm
    suspend fun updateVenue(id: Long, body: JsonObject): EditorialVenueForm
    suspend fun approveVenue(id: Long): VenueModerationResult
    suspend fun rejectVenue(id: Long, reason: String): VenueModerationResult
    suspend fun suspendVenue(id: Long, reason: String): VenueModerationResult
    suspend fun verifyVenue(id: Long, verified: Boolean): VenueModerationResult
    suspend fun setTicketing(id: Long, enabled: Boolean): EditorialVenueOverview
    suspend fun deleteVenue(id: Long)
    suspend fun venueEvents(id: Long, params: List<Pair<String, String>>): EditorialPage<VenueEventRow>
    suspend fun venueActivity(id: Long, params: List<Pair<String, String>>): EditorialPage<VenueActivityRow>
    suspend fun restoreActivity(venueId: Long, activityId: Long)
    suspend fun members(venueId: Long): VenueMembers
    suspend fun memberCandidates(venueId: Long, q: String): List<PersonChoice>
    suspend fun inviteMember(venueId: Long, name: String, email: String, role: String): VenueMembers
    suspend fun attachMember(venueId: Long, userId: Long, role: String): VenueMembers
    suspend fun updateMember(venueId: Long, userId: Long, role: String, acceptedAt: String?): VenueMembers
    suspend fun removeMember(venueId: Long, userId: Long): VenueMembers
    suspend fun applications(params: List<Pair<String, String>>): EditorialPage<ApplicationRow>
    suspend fun application(id: Long): ApplicationDetail
    suspend fun applicationImage(id: Long, target: File): DownloadedFile
    suspend fun approveApplication(id: Long): ApplicationOutcome
    suspend fun rejectApplication(id: Long, notes: String, notify: Boolean, message: String?): ApplicationOutcome
    suspend fun saveApplicationNotes(id: Long, notes: String?): ApplicationOutcome
    suspend fun deleteApplication(id: Long)
    suspend fun organizers(params: List<Pair<String, String>>): EditorialPage<OrganizerRow>
    suspend fun organizer(id: Long): OrganizerDetail
    suspend fun organizerChoices(): JsonObject
    suspend fun people(q: String): List<PersonChoice>
    suspend fun createOrganizer(body: JsonObject): OrganizerDetail
    suspend fun updateOrganizer(id: Long, body: JsonObject): OrganizerDetail
    /** Le immagini del locale con gli stessi percorsi della gestione, sotto `editorial/venues/{id}`. */
    fun media(): ManagementMediaApi?
}

@Serializable
private data class OrganizerList(val data: List<OrganizerRow> = emptyList(), val choices: JsonObject = JsonObject(emptyMap()))

internal class EditorialVenuesApi(private val api: ApiClient, private val token: String) : EditorialVenuesSource {
    override suspend fun venues(params: List<Pair<String, String>>): EditorialPage<EditorialVenueRow> = api.get(editorialPath("venues", null, params), token)
    override suspend fun exportVenues(params: List<Pair<String, String>>, target: File): DownloadedFile = api.download(editorialPath("venues/export", null, params), token, target)
    override suspend fun venueChoices(): VenueChoices = VenueChoices(api.get<ApiEnvelope<JsonObject>>("editorial/venues/choices", token).data)
    override suspend fun locate(address: String, municipality: String): LocatedPoint =
        api.post<ApiEnvelope<LocatedPoint>, JsonObject>("editorial/venues/locate", buildJsonObject { put("address", address.trim()); put("municipality", municipality.trim()) }, token).data
    override suspend fun venue(id: Long): EditorialVenueOverview = api.get<ApiEnvelope<EditorialVenueOverview>>("editorial/venues/$id", token).data
    override suspend fun venueForm(id: Long): EditorialVenueForm = EditorialVenueForm.from(api.get<ApiEnvelope<JsonObject>>("editorial/venues/$id/form", token).data)
    override suspend fun createVenue(body: JsonObject): EditorialVenueForm = EditorialVenueForm.from(api.post<ApiEnvelope<JsonObject>, JsonObject>("editorial/venues", body, token).data)
    override suspend fun updateVenue(id: Long, body: JsonObject): EditorialVenueForm =
        EditorialVenueForm.from(api.execute<ApiEnvelope<JsonObject>>("editorial/venues/$id", "PATCH", body.toString(), token).data)
    override suspend fun approveVenue(id: Long): VenueModerationResult = moderate(id, "approve", JsonObject(emptyMap()))
    override suspend fun rejectVenue(id: Long, reason: String): VenueModerationResult = moderate(id, "reject", reasonBody(reason))
    override suspend fun suspendVenue(id: Long, reason: String): VenueModerationResult = moderate(id, "suspend", reasonBody(reason))
    override suspend fun verifyVenue(id: Long, verified: Boolean): VenueModerationResult = moderate(id, "verify", buildJsonObject { put("verified", verified) })
    override suspend fun setTicketing(id: Long, enabled: Boolean): EditorialVenueOverview =
        api.execute<ApiEnvelope<EditorialVenueOverview>>("editorial/venues/$id/ticketing", "PUT", buildJsonObject { put("enabled", enabled) }.toString(), token).data
    override suspend fun deleteVenue(id: Long) = api.send("editorial/venues/$id", "DELETE", token)
    override suspend fun venueEvents(id: Long, params: List<Pair<String, String>>): EditorialPage<VenueEventRow> = api.get(editorialPath("venues/$id/events", null, params), token)
    override suspend fun venueActivity(id: Long, params: List<Pair<String, String>>): EditorialPage<VenueActivityRow> = api.get(editorialPath("venues/$id/activity", null, params), token)
    override suspend fun restoreActivity(venueId: Long, activityId: Long) = api.send("editorial/venues/$venueId/activity/$activityId/restore", "POST", token)
    override suspend fun members(venueId: Long): VenueMembers = api.get<ApiEnvelope<VenueMembers>>("editorial/venues/$venueId/members", token).data
    override suspend fun memberCandidates(venueId: Long, q: String): List<PersonChoice> =
        api.get<ApiEnvelope<List<PersonChoice>>>(editorialPath("venues/$venueId/members/candidates", null, listOf("q" to q)), token).data
    override suspend fun inviteMember(venueId: Long, name: String, email: String, role: String): VenueMembers =
        api.post<ApiEnvelope<VenueMembers>, JsonObject>("editorial/venues/$venueId/members/invite", buildJsonObject { put("name", name); put("email", email); put("role", role) }, token).data
    override suspend fun attachMember(venueId: Long, userId: Long, role: String): VenueMembers =
        api.post<ApiEnvelope<VenueMembers>, JsonObject>("editorial/venues/$venueId/members", buildJsonObject { put("user_id", userId); put("role", role) }, token).data
    override suspend fun updateMember(venueId: Long, userId: Long, role: String, acceptedAt: String?): VenueMembers =
        api.execute<ApiEnvelope<VenueMembers>>("editorial/venues/$venueId/members/$userId", "PATCH", memberBody(role, acceptedAt).toString(), token).data
    override suspend fun removeMember(venueId: Long, userId: Long): VenueMembers = api.delete<ApiEnvelope<VenueMembers>>("editorial/venues/$venueId/members/$userId", token).data
    override suspend fun applications(params: List<Pair<String, String>>): EditorialPage<ApplicationRow> = api.get(editorialPath("applications", null, params), token)
    override suspend fun application(id: Long): ApplicationDetail = api.get<ApiEnvelope<ApplicationDetail>>("editorial/applications/$id", token).data
    override suspend fun applicationImage(id: Long, target: File): DownloadedFile = api.download("editorial/applications/$id/image", token, target)
    override suspend fun approveApplication(id: Long): ApplicationOutcome =
        api.post<ApiEnvelope<ApplicationOutcome>, JsonObject>("editorial/applications/$id/approve", JsonObject(emptyMap()), token).data
    override suspend fun rejectApplication(id: Long, notes: String, notify: Boolean, message: String?): ApplicationOutcome =
        api.post<ApiEnvelope<ApplicationOutcome>, JsonObject>("editorial/applications/$id/reject", rejectApplicationBody(notes, notify, message), token).data
    override suspend fun saveApplicationNotes(id: Long, notes: String?): ApplicationOutcome =
        api.execute<ApiEnvelope<ApplicationOutcome>>("editorial/applications/$id/notes", "PATCH", buildJsonObject { put("notes", notes?.takeIf { it.isNotBlank() }) }.toString(), token).data
    override suspend fun deleteApplication(id: Long) = api.send("editorial/applications/$id", "DELETE", token)
    override suspend fun organizers(params: List<Pair<String, String>>): EditorialPage<OrganizerRow> = api.get(editorialPath("organizers", null, params), token)
    override suspend fun organizer(id: Long): OrganizerDetail = api.get<ApiEnvelope<OrganizerDetail>>("editorial/organizers/$id", token).data
    override suspend fun organizerChoices(): JsonObject = api.get<OrganizerList>(editorialPath("organizers", null, listOf("per_page" to "1")), token).choices
    override suspend fun people(q: String): List<PersonChoice> = api.get<ApiEnvelope<List<PersonChoice>>>(editorialPath("organizers/people", null, listOf("q" to q)), token).data
    override suspend fun createOrganizer(body: JsonObject): OrganizerDetail = api.post<ApiEnvelope<OrganizerDetail>, JsonObject>("editorial/organizers", body, token).data
    override suspend fun updateOrganizer(id: Long, body: JsonObject): OrganizerDetail =
        api.execute<ApiEnvelope<OrganizerDetail>>("editorial/organizers/$id", "PATCH", body.toString(), token).data
    override fun media(): ManagementMediaApi = ManagementMediaApi(api, token) { id -> "editorial/venues/$id" }

    private suspend fun moderate(id: Long, action: String, body: JsonObject): VenueModerationResult =
        api.post<ApiEnvelope<VenueModerationResult>, JsonObject>("editorial/venues/$id/$action", body, token).data
}

/** Il corpo di un rifiuto o di una sospensione: il motivo, senza spazi ai bordi. */
internal fun reasonBody(reason: String): JsonObject = buildJsonObject { put("reason", reason.trim()) }

/** Il corpo di «Modifica» di una persona: `accepted_at` vuoto = invito ancora da accettare. */
internal fun memberBody(role: String, acceptedAt: String?): JsonObject = buildJsonObject {
    put("role", role)
    if (acceptedAt.isNullOrBlank()) put("accepted_at", JsonNull) else put("accepted_at", acceptedAt)
}

/** Il corpo del rifiuto di una richiesta: il messaggio parte solo se si avvisa chi l'ha mandata. */
internal fun rejectApplicationBody(notes: String, notify: Boolean, message: String?): JsonObject = buildJsonObject {
    put("notes", notes.trim())
    put("notify", notify)
    if (notify && !message.isNullOrBlank()) put("message", message.trim())
}

/** Il valore di un campo testo da mandare: vuoto diventa `null`. */
internal fun textOrNull(value: String): JsonElement = value.trim().takeIf { it.isNotEmpty() }?.let { JsonPrimitive(it) } ?: JsonNull
