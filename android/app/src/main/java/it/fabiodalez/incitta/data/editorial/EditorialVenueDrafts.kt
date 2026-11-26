package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * I moduli di R4 nella forma che il server riceve: le sezioni della
 * redazione del modulo del locale, il nuovo locale, l'organizzatore. Le
 * sezioni della scheda pubblica (descrizione, dove, orari…) usano
 * `VenueProfileDraft` della gestione, la stessa regola.
 */

/** Le sezioni del modulo del locale che scrive solo la redazione, come le schede del pannello. */
internal enum class EditorialVenueSection(val keys: List<String>) {
    Identity(listOf("name", "slug", "city_id", "type", "province_code")),
    Moderation(listOf("status", "is_verified", "auto_publish", "is_nonprofit", "plan", "rejection_reason")),
    Ticketing(listOf("ticketing_enabled")),
    Visibility(listOf("seo_indexing", "is_demo"));

    companion object {
        fun fromName(name: String?): EditorialVenueSection? = entries.firstOrNull { it.name == name }
    }
}

@Serializable
internal data class VenueEditorialDraft(
    val name: String = "",
    val slug: String = "",
    val cityId: String = "",
    val type: String = "",
    val provinceCode: String = "",
    val status: String = "",
    val isVerified: Boolean = false,
    val autoPublish: Boolean = false,
    val isNonprofit: Boolean = false,
    val plan: String = "free",
    val rejectionReason: String = "",
    val ticketingEnabled: Boolean = false,
    val seoIndexing: String = "automatic",
    val isDemo: Boolean = false,
) {
    /** Solo le chiavi della sezione: il server salva quelle e lascia le altre come sono. */
    fun payload(section: EditorialVenueSection): JsonObject = buildJsonObject {
        when (section) {
            EditorialVenueSection.Identity -> {
                put("name", name.trim())
                put("slug", textOrNull(slug))
                put("city_id", cityId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull)
                put("type", type)
                put("province_code", provinceCode.trim())
            }
            EditorialVenueSection.Moderation -> {
                put("status", status)
                put("is_verified", isVerified)
                put("auto_publish", autoPublish)
                put("is_nonprofit", isNonprofit)
                put("plan", plan)
                put("rejection_reason", textOrNull(rejectionReason))
            }
            EditorialVenueSection.Ticketing -> put("ticketing_enabled", ticketingEnabled)
            EditorialVenueSection.Visibility -> {
                put("seo_indexing", seoIndexing)
                put("is_demo", isDemo)
            }
        }
    }

    companion object {
        fun from(fields: VenueEditorialFields) = VenueEditorialDraft(
            name = fields.name,
            slug = fields.slug,
            cityId = fields.cityId.takeIf { it > 0 }?.toString().orEmpty(),
            type = fields.type,
            provinceCode = fields.provinceCode,
            status = fields.status,
            isVerified = fields.isVerified,
            autoPublish = fields.autoPublish,
            isNonprofit = fields.isNonprofit,
            plan = fields.plan,
            rejectionReason = fields.rejectionReason.orEmpty(),
            ticketingEnabled = fields.ticketingEnabled,
            seoIndexing = fields.seoIndexing,
            isDemo = fields.isDemo,
        )
    }
}

/** «Nuovo locale»: i campi obbligatori del modulo del pannello; il resto si compila dopo, sezione per sezione. */
@Serializable
internal data class NewVenueDraft(
    val name: String = "",
    val cityId: String = "",
    val type: String = "",
    val address: String = "",
    val municipality: String = "",
    val zone: String = "",
    val provinceCode: String = "",
    val lat: String = "",
    val lng: String = "",
) {
    fun needsDistrict(districtMunicipality: String): Boolean = municipality == districtMunicipality

    fun payload(districtMunicipality: String = "Padova"): JsonObject = buildJsonObject {
        put("name", name.trim())
        put("city_id", cityId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull)
        put("type", type)
        put("address", address.trim())
        put("municipality", municipality.trim())
        put("zone", if (needsDistrict(districtMunicipality)) textOrNull(zone) else JsonNull)
        put("province_code", provinceCode.trim())
        put("lat", coordinate(lat))
        put("lng", coordinate(lng))
    }
}

/** Una coordinata scritta a mano: la virgola italiana vale come punto; il resto lo giudica il server (422). */
internal fun coordinate(value: String): JsonPrimitive? {
    val text = value.trim().replace(',', '.')
    if (text.isEmpty()) return null
    return text.toDoubleOrNull()?.let(::JsonPrimitive) ?: JsonPrimitive(text)
}

private fun kotlinx.serialization.json.JsonObjectBuilder.put(key: String, value: JsonPrimitive?) {
    put(key, value ?: JsonNull)
}

/** Il modulo di un organizzatore, campo per campo come il pannello. */
@Serializable
internal data class OrganizerDraft(
    val name: String = "",
    val cityId: String = "",
    val owner: PersonChoice? = null,
    val users: List<PersonChoice> = emptyList(),
    val description: String = "",
    val website: String = "",
    val email: String = "",
    val contactMode: String = "disabled",
    val contactEmail: String = "",
    val isActive: Boolean = false,
) {
    /** Messaggi accesi: serve un indirizzo che li riceva. */
    val contactActive: Boolean get() = contactMode == "members" || contactMode == "everyone"

    fun payload(): JsonObject = buildJsonObject {
        put("name", name.trim())
        put("city_id", cityId.toLongOrNull()?.let(::JsonPrimitive) ?: JsonNull)
        put("owner_id", owner?.id?.let(::JsonPrimitive) ?: JsonNull)
        put("users", JsonArray(users.map { JsonPrimitive(it.id) }))
        put("description", textOrNull(description))
        put("website", textOrNull(website))
        put("email", textOrNull(email))
        put("contact_mode", contactMode)
        /* Come il pannello: il recapito si scrive (e si salva) solo con i messaggi accesi. */
        if (contactActive) put("contact_email", textOrNull(contactEmail))
        put("is_active", isActive)
    }

    companion object {
        fun from(detail: OrganizerDetail) = OrganizerDraft(
            name = detail.name,
            cityId = detail.city?.id?.takeIf { it > 0 }?.toString().orEmpty(),
            owner = detail.owner,
            users = detail.users,
            description = detail.description.orEmpty(),
            website = detail.website.orEmpty(),
            email = detail.email.orEmpty(),
            contactMode = detail.contactMode,
            contactEmail = detail.contactEmail.orEmpty(),
            isActive = detail.isActive,
        )
    }
}
