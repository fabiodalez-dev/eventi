package it.fabiodalez.incitta.data.management

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonObjectBuilder
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.addJsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.putJsonObject
import java.time.LocalDate
import java.time.LocalTime
import java.time.format.DateTimeFormatter

/*
 * Il modulo della scheda del locale nell'app: tutti i campi della pagina «Il
 * tuo locale», come li scrive chi compila (testi, righe, scelte), più la
 * traduzione verso il `PATCH` di una sezione. Il modulo intero sta nello stato
 * salvato della schermata (`SavedStateHandle`, come JSON): sopravvive alla
 * rotazione e alla chiusura dell'app da parte del sistema.
 *
 * I testi formattati (descrizione, note, risposte) sono già nei segni di
 * `RichMarkup`; tornano HTML solo nel corpo della richiesta.
 */

/** Le sezioni della scheda che si salvano con `PATCH V/profile`, con le chiavi del server di ciascuna. */
internal enum class VenueSection(val keys: List<String>) {
    Description(listOf("short_description", "description")),
    Where(listOf("address", "address_extra", "postal_code", "municipality", "zone", "lat", "lng")),
    Hours(listOf("opening_hours", "closures", "capacity")),
    Contacts(listOf("phone", "email", "website", "socials")),
    Transit(listOf("transit")),
    Accessibility(listOf("accessibility")),
    Card(listOf("info", "requires_membership", "membership_notes")),
    BeforeGoing(BEFORE_GOING_KEYS.map { "content_details.$it" }),
    Editorial(EDITORIAL_KEYS.map { "content_details.$it" }),
    Seo(listOf("seo.title", "seo.description", "seo.image", "seo")),
    ContactForm(listOf("contact_mode", "contact_email"));

    companion object {
        fun fromName(name: String?): VenueSection? = entries.firstOrNull { it.name == name }
    }
}

internal val BEFORE_GOING_KEYS = listOf(
    "age_groups", "stroller", "changing_table", "kids_area", "membership", "accessibility",
    "membership_notes", "accessibility_notes", "feature_ids", "practical_custom", "food_notes", "start_notes",
)
internal val EDITORIAL_KEYS = listOf("introduction", "parking_type", "parking_notes", "transit_notes", "entrance_notes", "faqs")

@Serializable internal data class HourDraft(val day: String = "mon", val open: String = "", val close: String = "")
@Serializable internal data class ClosureDraft(val from: String = "", val to: String = "", val note: String = "")
@Serializable internal data class SocialDraft(val name: String = "", val url: String = "")
@Serializable internal data class TransitDraft(val mode: String = "bus", val text: String = "")
@Serializable internal data class FactDraft(val label: String = "", val value: String = "")
@Serializable internal data class PracticalDraft(val label: String = "", val icon: String = "check-circle", val text: String = "")
@Serializable internal data class FaqDraft(val question: String = "", val answer: String = "")

@Serializable
internal data class VenueProfileDraft(
    val shortDescription: String = "",
    val description: String = "",
    val address: String = "",
    val addressExtra: String = "",
    val postalCode: String = "",
    val municipality: String = "",
    val zone: String = "",
    val lat: Double? = null,
    val lng: Double? = null,
    val hours: List<HourDraft> = emptyList(),
    val closures: List<ClosureDraft> = emptyList(),
    val capacity: String = "",
    val phone: String = "",
    val email: String = "",
    val website: String = "",
    val socials: List<SocialDraft> = emptyList(),
    val transit: List<TransitDraft> = emptyList(),
    /* Solo le voci dichiarate: assente = «non dichiarato». */
    val accessibility: Map<String, Boolean> = emptyMap(),
    val info: List<FactDraft> = emptyList(),
    val requiresMembership: Boolean = false,
    val membershipNotes: String = "",
    // Prima di andare
    val ageGroups: List<String> = emptyList(),
    val stroller: String = "",
    val changingTable: String = "",
    val kidsArea: String = "",
    val membership: String = "",
    val wheelchair: String = "",
    val beforeMembershipNotes: String = "",
    val accessibilityNotes: String = "",
    val featureIds: List<Long> = emptyList(),
    val practical: List<PracticalDraft> = emptyList(),
    val foodNotes: String = "",
    val startNotes: String = "",
    // Informazioni editoriali
    val introduction: String = "",
    val parkingType: String = "",
    val parkingNotes: String = "",
    val transitNotes: String = "",
    val entranceNotes: String = "",
    val faqs: List<FaqDraft> = emptyList(),
    // SEO
    val seoTitle: String = "",
    val seoDescription: String = "",
    val seoImage: String = "",
    // Modulo di contatto
    val contactMode: String = "disabled",
    val contactEmail: String = "",
) {
    companion object {
        fun from(profile: VenueProfile): VenueProfileDraft {
            val details = profile.contentDetails
            return VenueProfileDraft(
                shortDescription = profile.shortDescription.orEmpty(),
                description = RichMarkup.fromHtml(profile.description),
                address = profile.address.orEmpty(),
                addressExtra = profile.addressExtra.orEmpty(),
                postalCode = profile.postalCode.orEmpty(),
                municipality = profile.municipality.orEmpty(),
                zone = profile.zone.orEmpty(),
                lat = profile.lat,
                lng = profile.lng,
                hours = profile.openingHours.map { HourDraft(it.day, it.open.orEmpty(), it.close.orEmpty()) },
                closures = profile.closures.map { ClosureDraft(it.from, it.to, it.note.orEmpty()) },
                capacity = profile.capacity?.toString().orEmpty(),
                phone = profile.phone.orEmpty(),
                email = profile.email.orEmpty(),
                website = profile.website.orEmpty(),
                socials = profile.socials.map { SocialDraft(it.name, it.url.orEmpty()) },
                transit = profile.transit.map { TransitDraft(it.mode, it.text) },
                accessibility = profile.accessibility.mapNotNull { (key, value) -> value?.let { key to it } }.toMap(),
                info = profile.info.map { FactDraft(it.label, it.value) },
                requiresMembership = profile.requiresMembership,
                membershipNotes = RichMarkup.fromHtml(profile.membershipNotes),
                ageGroups = details.strings("age_groups"),
                stroller = details.text("stroller"),
                changingTable = details.text("changing_table"),
                kidsArea = details.text("kids_area"),
                membership = details.text("membership"),
                wheelchair = details.text("accessibility"),
                beforeMembershipNotes = RichMarkup.fromHtml(details.text("membership_notes")),
                accessibilityNotes = RichMarkup.fromHtml(details.text("accessibility_notes")),
                featureIds = details.ids("feature_ids"),
                practical = details.rows("practical_custom").map { PracticalDraft(it.text("label"), it.text("icon").ifBlank { "check-circle" }, RichMarkup.fromHtml(it.text("text"))) },
                foodNotes = details.text("food_notes"),
                startNotes = details.text("start_notes"),
                introduction = RichMarkup.fromHtml(details.text("introduction")),
                parkingType = details.text("parking_type"),
                parkingNotes = RichMarkup.fromHtml(details.text("parking_notes")),
                transitNotes = RichMarkup.fromHtml(details.text("transit_notes")),
                entranceNotes = RichMarkup.fromHtml(details.text("entrance_notes")),
                faqs = details.rows("faqs").map { FaqDraft(it.text("question"), RichMarkup.fromHtml(it.text("answer"))) },
                seoTitle = profile.seo.title.orEmpty(),
                seoDescription = profile.seo.description.orEmpty(),
                seoImage = profile.seo.image.orEmpty(),
                contactMode = profile.contact.mode,
                contactEmail = profile.contact.email.orEmpty(),
            )
        }
    }

    /** Il quartiere vale solo per il comune che li ha (Padova). */
    fun needsDistrict(districtMunicipality: String): Boolean = municipality == districtMunicipality

    /** Il corpo del `PATCH` di una sezione: solo le sue chiavi. Un campo vuoto diventa `null`. */
    fun payload(section: VenueSection, districtMunicipality: String = "Padova"): JsonObject = buildJsonObject {
        when (section) {
            VenueSection.Description -> {
                putText("short_description", shortDescription)
                putRich("description", description)
            }
            VenueSection.Where -> {
                putText("address", address)
                putText("address_extra", addressExtra)
                putText("postal_code", postalCode)
                putText("municipality", municipality)
                putText("zone", if (needsDistrict(districtMunicipality)) zone else "")
                put("lat", lat)
                put("lng", lng)
            }
            VenueSection.Hours -> {
                putJsonArray("opening_hours") { hours.forEach { row -> addJsonObject { put("day", row.day); putText("open", row.open); putText("close", row.close) } } }
                putJsonArray("closures") { closures.forEach { row -> addJsonObject { putText("from", row.from); putText("to", row.to); putText("note", row.note) } } }
                val number = capacity.trim()
                if (number.isEmpty()) put("capacity", JsonNull) else put("capacity", number.toLongOrNull()?.let(::JsonPrimitive) ?: JsonPrimitive(number))
            }
            VenueSection.Contacts -> {
                putText("phone", phone)
                putText("email", email)
                putText("website", website)
                putJsonArray("socials") { socials.forEach { row -> addJsonObject { put("name", row.name.trim()); putText("url", row.url) } } }
            }
            VenueSection.Transit -> putJsonArray("transit") { transit.forEach { row -> addJsonObject { put("mode", row.mode); put("text", row.text.trim()) } } }
            VenueSection.Accessibility -> putJsonObject("accessibility") { accessibility.forEach { (key, value) -> put(key, value) } }
            VenueSection.Card -> {
                putJsonArray("info") { info.forEach { row -> addJsonObject { put("label", row.label.trim()); put("value", row.value.trim()) } } }
                put("requires_membership", requiresMembership)
                putRich("membership_notes", membershipNotes)
            }
            VenueSection.BeforeGoing -> putJsonObject("content_details") {
                putJsonArray("age_groups") { ageGroups.forEach { add(JsonPrimitive(it)) } }
                putText("stroller", stroller)
                putText("changing_table", changingTable)
                putText("kids_area", kidsArea)
                putText("membership", membership)
                putText("accessibility", wheelchair)
                putRich("membership_notes", beforeMembershipNotes)
                putRich("accessibility_notes", accessibilityNotes)
                putJsonArray("feature_ids") { featureIds.forEach { add(JsonPrimitive(it)) } }
                putJsonArray("practical_custom") { practical.forEach { row -> addJsonObject { put("label", row.label.trim()); put("icon", row.icon); putRich("text", row.text) } } }
                putText("food_notes", foodNotes)
                putText("start_notes", startNotes)
            }
            VenueSection.Editorial -> putJsonObject("content_details") {
                putRich("introduction", introduction)
                putText("parking_type", parkingType)
                putRich("parking_notes", parkingNotes)
                putRich("transit_notes", transitNotes)
                putRich("entrance_notes", entranceNotes)
                putJsonArray("faqs") { faqs.forEach { row -> addJsonObject { put("question", row.question.trim()); putRich("answer", row.answer) } } }
            }
            VenueSection.Seo -> putJsonObject("seo") {
                putText("title", seoTitle)
                putText("description", seoDescription)
                putText("image", seoImage)
            }
            VenueSection.ContactForm -> {
                put("contact_mode", contactMode)
                putText("contact_email", if (contactMode == "disabled" && contactEmail.isBlank()) "" else contactEmail)
            }
        }
    }
}

/** Il modulo del profilo dell'organizzazione. */
@Serializable
internal data class OrganizerProfileDraft(
    val description: String = "",
    val website: String = "",
    val email: String = "",
    val contactMode: String = "disabled",
    val contactEmail: String = "",
) {
    fun payload(): JsonObject = buildJsonObject {
        putRich("description", description)
        putText("website", website)
        putText("email", email)
        put("contact_mode", contactMode)
        putText("contact_email", contactEmail)
    }

    companion object {
        fun from(profile: OrganizerProfile) = OrganizerProfileDraft(
            description = RichMarkup.fromHtml(profile.description),
            website = profile.website.orEmpty(),
            email = profile.email.orEmpty(),
            contactMode = profile.contact.mode,
            contactEmail = profile.contact.email.orEmpty(),
        )
    }
}

/* Un testo vuoto o fatto di soli spazi diventa `null`, come un campo lasciato vuoto nel pannello. */
private fun JsonObjectBuilder.putText(key: String, value: String) {
    val clean = value.trim()
    put(key, if (clean.isEmpty()) JsonNull else JsonPrimitive(clean))
}

private fun JsonObjectBuilder.putRich(key: String, markup: String) {
    val html = RichMarkup.toHtml(markup)
    put(key, if (html.isEmpty()) JsonNull else JsonPrimitive(html))
}

/* Letture tolleranti di `content_details`: un tipo inatteso diventa vuoto, non un errore. */
private fun JsonObject.text(key: String): String = when (val value = this[key]) {
    is JsonPrimitive -> if (value is JsonNull) "" else value.contentOrNull.orEmpty()
    else -> ""
}

private fun JsonObject.strings(key: String): List<String> =
    (this[key] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.takeIf { p -> p !is JsonNull }?.contentOrNull } ?: emptyList()

private fun JsonObject.ids(key: String): List<Long> =
    (this[key] as? JsonArray)?.mapNotNull { item -> (item as? JsonPrimitive)?.let { it.longOrNull ?: it.contentOrNull?.toLongOrNull() } } ?: emptyList()

private fun JsonObject.rows(key: String): List<JsonObject> = when (val value = this[key]) {
    is JsonArray -> value.filterIsInstance<JsonObject>()
    /* PHP può aver salvato un elenco come mappa con chiavi numeriche. */
    is JsonObject -> value.values.filterIsInstance<JsonObject>()
    else -> emptyList()
}

/* Ore e giorni come li vuole il server. */
private val HOUR = DateTimeFormatter.ofPattern("HH:mm")
internal fun formatHour(time: LocalTime): String = time.format(HOUR)
internal fun parseHour(value: String): LocalTime? = runCatching { LocalTime.parse(value.trim(), HOUR) }.getOrNull()
internal fun parseDay(value: String): LocalDate? = runCatching { LocalDate.parse(value.trim()) }.getOrNull()
