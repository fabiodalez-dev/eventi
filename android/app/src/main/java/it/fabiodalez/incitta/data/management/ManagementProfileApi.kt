package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * Il profilo del locale e dell'organizzazione (P4): la stessa scheda delle
 * pagine «Il tuo locale» e «Profilo» dei pannelli. Logo, copertina e galleria
 * passano da `ManagementMediaApi` (fondamenta).
 *
 * Ogni campo ha un valore predefinito. `content_details` resta JSON grezzo e
 * si legge con `ProfileDraft`: lo scrivono anche la redazione e il sito, e un
 * tipo inatteso in una voce non deve impedire di aprire tutta la scheda.
 */

/** Una riga degli orari: giorno (`mon`…`sun`), dalle, alle (`HH:mm`). */
@Serializable
internal data class OpeningHourRow(val day: String = "", val open: String? = null, val close: String? = null)

/** Una chiusura straordinaria: giorni di calendario `yyyy-MM-dd`, estremi compresi. */
@Serializable
internal data class ClosureRow(val from: String = "", val to: String = "", val note: String? = null)

@Serializable
internal data class SocialRow(val name: String = "", val url: String? = null)

@Serializable
internal data class TransitRow(val mode: String = "", val text: String = "")

@Serializable
internal data class FactRow(val label: String = "", val value: String = "")

@Serializable
internal data class ValueLabel(val value: String = "", val label: String = "")

@Serializable
internal data class VenueTypeInfo(val value: String = "", val label: String = "")

@Serializable
internal data class ProfileCity(val name: String = "", val timezone: String = "")

@Serializable
internal data class ProfileSeo(val title: String? = null, val description: String? = null, val image: String? = null)

/** Il modulo di contatto pubblico: chi può scrivere, chi riceve, lo stato della conferma. */
@Serializable
internal data class ContactSettings(
    val mode: String = "disabled",
    val email: String? = null,
    val confirmed: Boolean = false,
    val pending: Boolean = false,
    @SerialName("can_resend") val canResend: Boolean = false,
    @SerialName("link_days") val linkDays: Int = 7,
    val note: String? = null,
    val modes: List<ValueLabel> = emptyList(),
) {
    /** Messaggi accesi: serve un indirizzo che li riceva. */
    val active: Boolean get() = mode == "members" || mode == "everyone"
}

@Serializable
internal data class VenueNotice(
    val id: Long = 0,
    val kind: String = "",
    @SerialName("kind_label") val kindLabel: String = "",
    val body: String = "",
    val until: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable
internal data class NoticeState(
    @SerialName("min_days_between") val minDaysBetween: Int = 7,
    val latest: VenueNotice? = null,
    /* Da quando si può pubblicare il prossimo; `null` se si può già. */
    @SerialName("next_available_at") val nextAvailableAt: String? = null,
    val followers: Int = 0,
)

@Serializable
internal data class WidgetEmbed(val url: String = "", val code: String = "")

@Serializable
internal data class ProfileChoices(
    val weekdays: List<ValueLabel> = emptyList(),
    val municipalities: List<String> = emptyList(),
    val districts: List<String> = emptyList(),
    @SerialName("district_municipality") val districtMunicipality: String = "Padova",
    @SerialName("parking_types") val parkingTypes: List<ValueLabel> = emptyList(),
    @SerialName("notice_kinds") val noticeKinds: List<ValueLabel> = emptyList(),
)

/** Risposta di `GET` e `PATCH V/profile`. */
@Serializable
internal data class VenueProfile(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val type: VenueTypeInfo = VenueTypeInfo(),
    val status: String = "",
    val city: ProfileCity? = null,
    @SerialName("short_description") val shortDescription: String? = null,
    val description: String? = null,
    val address: String? = null,
    @SerialName("address_extra") val addressExtra: String? = null,
    @SerialName("postal_code") val postalCode: String? = null,
    val municipality: String? = null,
    val zone: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    @SerialName("opening_hours") val openingHours: List<OpeningHourRow> = emptyList(),
    val closures: List<ClosureRow> = emptyList(),
    val capacity: Long? = null,
    val phone: String? = null,
    val email: String? = null,
    val website: String? = null,
    val socials: List<SocialRow> = emptyList(),
    val transit: List<TransitRow> = emptyList(),
    val accessibility: Map<String, Boolean?> = emptyMap(),
    val info: List<FactRow> = emptyList(),
    @SerialName("requires_membership") val requiresMembership: Boolean = false,
    @SerialName("membership_notes") val membershipNotes: String? = null,
    @SerialName("content_details") val contentDetails: JsonObject = JsonObject(emptyMap()),
    val seo: ProfileSeo = ProfileSeo(),
    val contact: ContactSettings = ContactSettings(),
    val notices: NoticeState = NoticeState(),
    @SerialName("public_url") val publicUrl: String = "",
    @SerialName("calendar_url") val calendarUrl: String = "",
    val widget: WidgetEmbed = WidgetEmbed(),
    val choices: ProfileChoices = ProfileChoices(),
    val limits: Map<String, Long> = emptyMap(),
) {
    fun limit(key: String, fallback: Int): Int = limits[key]?.toInt() ?: fallback
}

/** Risposta di `GET` e `PATCH O/profile`. */
@Serializable
internal data class OrganizerProfile(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val description: String? = null,
    val website: String? = null,
    val email: String? = null,
    val contact: ContactSettings = ContactSettings(),
    @SerialName("public_url") val publicUrl: String = "",
    @SerialName("calendar_url") val calendarUrl: String = "",
    val limits: Map<String, Long> = emptyMap(),
)

/** Risposta di `POST V/notices`. */
@Serializable
internal data class PublishedNotice(val notice: VenueNotice = VenueNotice(), val notified: Int = 0, val message: String = "")

/** Risposta di `POST S/contact/resend`. */
@Serializable
internal data class ContactResent(val contact: ContactSettings = ContactSettings(), val message: String = "")

/** Le chiamate del profilo; un'interfaccia perché i test dei ViewModel la sostituiscono. */
internal interface ManagementProfileSource {
    suspend fun venueProfile(venueId: Long): VenueProfile
    /** Salva solo le chiavi di `changes` (una sezione) e risponde con la scheda aggiornata. */
    suspend fun updateVenueProfile(venueId: Long, changes: JsonObject): VenueProfile
    suspend fun publishNotice(venueId: Long, kind: String, body: String, until: String?): PublishedNotice
    suspend fun organizerProfile(organizerId: Long): OrganizerProfile
    suspend fun updateOrganizerProfile(organizerId: Long, changes: JsonObject): OrganizerProfile
    suspend fun resendContactConfirmation(subject: ManagementSubject): ContactResent
}

internal class ManagementProfileApi(private val api: ApiClient, private val token: String) : ManagementProfileSource {
    override suspend fun venueProfile(venueId: Long): VenueProfile =
        api.get<ApiEnvelope<VenueProfile>>("${venuePath(venueId)}/profile", token).data

    override suspend fun updateVenueProfile(venueId: Long, changes: JsonObject): VenueProfile =
        api.execute<ApiEnvelope<VenueProfile>>("${venuePath(venueId)}/profile", "PATCH", changes.toString(), token).data

    override suspend fun publishNotice(venueId: Long, kind: String, body: String, until: String?): PublishedNotice =
        api.execute<ApiEnvelope<PublishedNotice>>("${venuePath(venueId)}/notices", "POST", noticeBody(kind, body, until).toString(), token).data

    override suspend fun organizerProfile(organizerId: Long): OrganizerProfile =
        api.get<ApiEnvelope<OrganizerProfile>>("${organizerPath(organizerId)}/profile", token).data

    override suspend fun updateOrganizerProfile(organizerId: Long, changes: JsonObject): OrganizerProfile =
        api.execute<ApiEnvelope<OrganizerProfile>>("${organizerPath(organizerId)}/profile", "PATCH", changes.toString(), token).data

    override suspend fun resendContactConfirmation(subject: ManagementSubject): ContactResent =
        api.execute<ApiEnvelope<ContactResent>>("${subject.apiPath}/contact/resend", "POST", "{}", token).data

    private fun venuePath(id: Long) = "management/${SubjectKind.Venue.segment}/$id"
    private fun organizerPath(id: Long) = "management/${SubjectKind.Organizer.segment}/$id"
}

/** Il corpo di `POST V/notices`: `until` resta fuori quando non c'è. */
internal fun noticeBody(kind: String, body: String, until: String?): JsonObject = buildJsonObject {
    put("kind", kind)
    put("body", body)
    if (!until.isNullOrBlank()) put("until", until)
}
