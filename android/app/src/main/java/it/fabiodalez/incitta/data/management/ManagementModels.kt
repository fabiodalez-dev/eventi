package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.User
import java.time.ZoneId
import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.MapSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.*

/*
 * L'identità di gestione: chi sono i locali e le organizzazioni che la persona
 * gestisce e che cosa può fare in ciascuno. Ogni campo ha un valore
 * predefinito e i pezzi di forma incerta (città, logo, permessi) restano
 * tolleranti: un server più vecchio o più nuovo non deve mai far sparire
 * l'area di gestione, al massimo la riporta al comportamento di prima.
 */

/** Risposta di `GET /v1/management/subjects`. */
@Serializable
internal data class ManagementSubjects(
    val venues: List<ManagedVenue> = emptyList(),
    val organizers: List<ManagedOrganizer> = emptyList(),
    /* Vero se la persona è solo personale d'ingresso: atterra sull'elenco delle date. */
    @SerialName("checkin_only") val checkinOnly: Boolean = false,
) {
    /** Prima i locali, poi le organizzazioni: lo stesso ordine del sito dopo l'accesso (W-NV-01). */
    fun all(): List<ManagementSubject> = venues.map(ManagedVenue::toSubject) + organizers.map(ManagedOrganizer::toSubject)
    fun find(key: String?): ManagementSubject? = key?.let { wanted -> all().firstOrNull { it.key == wanted } }
}

@Serializable
internal data class ManagedVenue(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val city: JsonElement? = null,
    /* `owner` (referente) o `editor` (collaboratore). */
    val role: String = "",
    val status: String = "",
    val logo: JsonElement? = null,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    @SerialName("auto_publish") val autoPublish: Boolean = false,
    @Serializable(with = ManagementPermissionsSerializer::class) val permissions: Map<String, Boolean> = emptyMap(),
) {
    fun toSubject() = ManagementSubject(
        kind = SubjectKind.Venue, id = id, slug = slug, name = name, cityName = cityName(city), timezone = cityZone(city),
        role = role, status = status, logo = imageUrl(logo), ticketingEnabled = ticketingEnabled, autoPublish = autoPublish,
        permissions = SubjectPermissions(permissions),
    )
}

@Serializable
internal data class ManagedOrganizer(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val city: JsonElement? = null,
    /* `owner` (chi ha creato l'organizzazione) o `member`. */
    val role: String = "",
    val status: String = "",
    val logo: JsonElement? = null,
    @Serializable(with = ManagementPermissionsSerializer::class) val permissions: Map<String, Boolean> = emptyMap(),
) {
    fun toSubject() = ManagementSubject(
        kind = SubjectKind.Organizer, id = id, slug = slug, name = name, cityName = cityName(city), timezone = cityZone(city),
        role = role, status = status, logo = imageUrl(logo), ticketingEnabled = false, autoPublish = false,
        permissions = SubjectPermissions(permissions),
    )
}

internal enum class SubjectKind(val segment: String, val webSegment: String) {
    Venue("venues", "gestione"),
    Organizer("organizers", "organizza"),
}

/** Un locale o un'organizzazione gestita, nella forma che usano le schermate. */
internal data class ManagementSubject(
    val kind: SubjectKind,
    val id: Long,
    val slug: String,
    val name: String,
    val cityName: String?,
    /* Il fuso della città del soggetto: date e ore si scrivono sempre in questo, mai in quello del telefono. */
    val timezone: ZoneId,
    val role: String,
    val status: String,
    val logo: String?,
    val ticketingEnabled: Boolean,
    val autoPublish: Boolean,
    val permissions: SubjectPermissions,
) {
    /** Chiave stabile per il selettore e per lo stato salvato: `venue:12`, `organizer:3`. */
    val key: String get() = "${kind.name.lowercase()}:$id"
    /** Il prefisso delle rotte `V` e `O` del piano: `management/venues/12`. */
    val apiPath: String get() = "management/${kind.segment}/$id"
    /** Il pannello web dello stesso soggetto, per quello che l'app non fa ancora. */
    val webPath: String get() = "${kind.webSegment}/$slug"
    val isOwner: Boolean get() = role == "owner"
}

/** Le chiavi di `permissions`, una per area. Una chiave assente vale «no». */
internal class SubjectPermissions(private val values: Map<String, Boolean> = emptyMap()) {
    fun allows(key: String): Boolean = values[key] == true
    val eventsCreate get() = allows("events_create")
    val eventsPublishDirectly get() = allows("events_publish_directly")
    val ticketingManage get() = allows("ticketing_manage")
    val profileUpdate get() = allows("profile_update")
    val collaboratorsManage get() = allows("collaborators_manage")
    val importManage get() = allows("import_manage")
    val analyticsView get() = allows("analytics_view")
    val analyticsChannels get() = allows("analytics_channels")
    val sponsorshipsView get() = allows("sponsorships_view")
    val sponsorshipsPurchase get() = allows("sponsorships_purchase")
    val socialUse get() = allows("social_use")
    val commentsModerate get() = allows("comments_moderate")
    val reviewsReply get() = allows("reviews_reply")
    val leave get() = allows("leave")
    override fun equals(other: Any?) = other is SubjectPermissions && other.values == values
    override fun hashCode() = values.hashCode()
    override fun toString() = "SubjectPermissions($values)"
}

/**
 * I permessi come mappa di booleani. PHP manda una mappa vuota come `[]`, e un
 * valore può arrivare come `1`/`"1"`/`"true"`: tutto quello che non è un sì
 * chiaro diventa no, e un valore che non è una mappa diventa «nessun permesso».
 */
internal object ManagementPermissionsSerializer : JsonTransformingSerializer<Map<String, Boolean>>(
    MapSerializer(String.serializer(), Boolean.serializer()) as KSerializer<Map<String, Boolean>>,
) {
    override fun transformDeserialize(element: JsonElement): JsonElement {
        val source = element as? JsonObject ?: return JsonObject(emptyMap())
        return JsonObject(source.mapValues { (_, value) -> JsonPrimitive(truthy(value)) })
    }
}

/** Sì solo per `true`, `1`, `"1"`, `"true"`. */
internal fun truthy(value: JsonElement?): Boolean {
    val primitive = value as? JsonPrimitive ?: return false
    if (primitive is JsonNull) return false
    primitive.booleanOrNull?.let { return it }
    primitive.longOrNull?.let { return it != 0L }
    return primitive.contentOrNull?.trim()?.lowercase() in setOf("1", "true", "yes")
}

/** Un numero intero da un campo che potrebbe essere numero, booleano o stringa. */
internal fun countOf(value: JsonElement?): Int {
    val primitive = value as? JsonPrimitive ?: return 0
    if (primitive is JsonNull) return 0
    primitive.intOrNull?.let { return it.coerceAtLeast(0) }
    primitive.booleanOrNull?.let { return if (it) 1 else 0 }
    return primitive.contentOrNull?.trim()?.toIntOrNull()?.coerceAtLeast(0) ?: 0
}

/* La città può essere un oggetto `{name, timezone}` o, per un'organizzazione, solo il nome. */
private fun cityName(city: JsonElement?): String? = when (city) {
    is JsonObject -> (city["name"] as? JsonPrimitive)?.contentOrNull
    is JsonPrimitive -> city.contentOrNull
    else -> null
}?.takeIf { it.isNotBlank() }

internal val DEFAULT_MANAGEMENT_ZONE: ZoneId = ZoneId.of("Europe/Rome")

private fun cityZone(city: JsonElement?): ZoneId {
    val name = ((city as? JsonObject)?.get("timezone") as? JsonPrimitive)?.contentOrNull ?: return DEFAULT_MANAGEMENT_ZONE
    return runCatching { ZoneId.of(name) }.getOrDefault(DEFAULT_MANAGEMENT_ZONE)
}

/* Il logo può essere un indirizzo o le varianti di un'immagine, come la copertina del locale. */
private fun imageUrl(value: JsonElement?): String? = when (value) {
    is JsonPrimitive -> value.contentOrNull
    is JsonObject -> listOf("thumb", "card", "url", "full").firstNotNullOfOrNull { (value[it] as? JsonPrimitive)?.contentOrNull }
    else -> null
}?.takeIf { it.isNotBlank() }

/** I tre conteggi di `user.management` in `GET /me`. */
internal data class UserManagementCounts(val venues: Int, val organizers: Int, val checkin: Int) {
    val any: Boolean get() = venues > 0 || organizers > 0 || checkin > 0
}

/** `null` quando il server non manda il campo (versione vecchia): allora non si sa. */
internal fun User.managementCounts(): UserManagementCounts? {
    val value = management as? JsonObject ?: return null
    return UserManagementCounts(countOf(value["venues"]), countOf(value["organizers"]), countOf(value["checkin"]))
}

/**
 * La voce «Gestione eventi» del profilo: solo a chi gestisce qualcosa o
 * controlla gli ingressi di una data. Con un server che non manda ancora
 * `management` resta visibile a tutti, come prima.
 */
internal fun showsManagementEntry(user: User): Boolean = user.managementCounts()?.any ?: true

/** `features.management` di `GET /config`: l'area nuova si accende solo con un sì esplicito. */
internal fun managementFeatureEnabled(config: JsonElement?): Boolean =
    truthy(((config as? JsonObject)?.get("features") as? JsonObject)?.get("management"))

/** Una voce di un elenco di `GET /v1/management/options`. */
internal data class OptionChoice(val value: String, val label: String)

/**
 * Gli elenchi e i tetti di `GET /v1/management/options`. La forma esatta di
 * ogni elenco la decide il pacchetto che lo usa: qui si accettano le tre
 * forme che il server usa altrove (lista di stringhe, lista di
 * `{value,label}`, mappa valore → etichetta) e i numeri sia in cima sia
 * sotto `limits`.
 */
internal class ManagementOptions(val raw: JsonObject = JsonObject(emptyMap())) {
    /**
     * `group` sceglie un sottoelenco quando il server li divide per tipo di
     * soggetto, come `occurrence_statuses.venue` e `occurrence_statuses.organizer`.
     */
    fun choices(key: String, group: String? = null): List<OptionChoice> = choicesOf(if (group == null) raw[key] else (raw[key] as? JsonObject)?.get(group))

    private fun choicesOf(element: JsonElement?): List<OptionChoice> = when (val value = element) {
        is JsonArray -> value.mapNotNull { item ->
            when (item) {
                is JsonPrimitive -> item.contentOrNull?.let { OptionChoice(it, it) }
                is JsonObject -> {
                    val id = (item["value"] ?: item["key"] ?: item["id"]) as? JsonPrimitive
                    val label = (item["label"] ?: item["name"]) as? JsonPrimitive
                    id?.contentOrNull?.let { OptionChoice(it, label?.contentOrNull ?: it) }
                }
                else -> null
            }
        }
        is JsonObject -> value.map { (id, label) ->
            val text = (label as? JsonPrimitive)?.contentOrNull ?: ((label as? JsonObject)?.get("label") as? JsonPrimitive)?.contentOrNull
            OptionChoice(id, text ?: id)
        }
        else -> emptyList()
    }

    /** Un numero di `images` (`max_upload_bytes`, `max_pixels`, …). */
    fun image(key: String): Long? = ((raw["images"] as? JsonObject)?.get(key) as? JsonPrimitive)?.let { it.longOrNull ?: it.contentOrNull?.toLongOrNull() }

    fun limit(key: String): Long? {
        val limits = raw["limits"] as? JsonObject
        val value = (limits?.get(key) ?: raw[key]) as? JsonPrimitive ?: return null
        return value.longOrNull ?: value.contentOrNull?.toLongOrNull()
    }

    companion object {
        fun from(element: JsonElement?): ManagementOptions = ManagementOptions(element as? JsonObject ?: JsonObject(emptyMap()))
    }
}
