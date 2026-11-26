package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.Poster
import it.fabiodalez.incitta.data.management.EventCity
import it.fabiodalez.incitta.data.management.LabeledValue
import it.fabiodalez.incitta.data.management.NamedRef
import java.time.ZoneId
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/*
 * R3 — il modulo evento della redazione (`GET E/events/form`,
 * `GET E/events/{id}/form`, `POST`/`PATCH E/events`) e le date dell'evento
 * (`E/events/{id}/dates`). Ogni campo ha un valore predefinito: un server
 * diverso non deve far fallire la lettura.
 *
 * I valori della scheda hanno la forma di `ManagedEventDetail` per i campi
 * comuni con il modulo della gestione (l'app ne riusa il modulo); qui ci
 * sono i campi in più della redazione.
 */

/** Una scelta di un ambito di modifica di una data: «Solo questa» o «Tutta la serie», con la spiegazione. */
@Serializable
internal data class EditorScope(val value: String = "", val label: String = "", val help: String = "")

/** I valori di un evento nuovo, come i predefiniti del modulo del pannello. */
@Serializable
internal data class EditorDefaults(
    val status: String = "draft",
    @SerialName("city_id") val cityId: Long? = null,
    @SerialName("price_type") val priceType: String = "unknown",
    val currency: String = "EUR",
    val source: String = "manual",
    @SerialName("verification_status") val verificationStatus: String = "unverified",
    @SerialName("editorial_score") val editorialScore: Int = 0,
)

/** I tetti dei ripetitori e dei testi. */
@Serializable
internal data class EditorLimits(
    @SerialName("ticket_tiers") val ticketTiers: Int = 12,
    val tags: Int = 50,
    val agenda: Int = 50,
    val faqs: Int = 30,
    val facts: Int = 12,
    @SerialName("external_links") val externalLinks: Int = 8,
    @SerialName("new_dates") val newDates: Int = 100,
    @SerialName("short_description") val shortDescription: Int = 500,
)

/** Che cosa può scrivere chi compila: l'evidenza (`EventPolicy::feature`) e i campi da admin (indicizzazione, dimostrativo). */
@Serializable
internal data class EditorCan(
    val feature: Boolean = false,
    val admin: Boolean = false,
    /* «Crea caratteristica» (`EventFeaturePolicy::create`): l'app apre il modulo della caratteristica del catalogo (R11). */
    @SerialName("create_feature") val createFeature: Boolean = false,
)

/** Le scelte del modulo. `catalog` è il catalogo di «Prima di andare» (`GET /management/options`). */
@Serializable
internal data class EditorEventOptions(
    val categories: List<NamedRef> = emptyList(),
    val cities: List<EventCity> = emptyList(),
    val statuses: List<LabeledValue> = emptyList(),
    val sources: List<LabeledValue> = emptyList(),
    @SerialName("verification_statuses") val verificationStatuses: List<LabeledValue> = emptyList(),
    @SerialName("price_types") val priceTypes: List<LabeledValue> = emptyList(),
    @SerialName("ticket_tier_statuses") val ticketTierStatuses: List<LabeledValue> = emptyList(),
    @SerialName("occurrence_statuses") val occurrenceStatuses: List<LabeledValue> = emptyList(),
    @SerialName("lineup_roles") val lineupRoles: List<LabeledValue> = emptyList(),
    val scopes: List<EditorScope> = emptyList(),
    @SerialName("attendance_modes") val attendanceModes: List<LabeledValue> = emptyList(),
    @SerialName("parking_types") val parkingTypes: List<LabeledValue> = emptyList(),
    @SerialName("organizer_types") val organizerTypes: List<LabeledValue> = emptyList(),
    @SerialName("seo_indexing") val seoIndexing: List<LabeledValue> = emptyList(),
    val defaults: EditorDefaults = EditorDefaults(),
    val limits: EditorLimits = EditorLimits(),
    val can: EditorCan = EditorCan(),
    val catalog: JsonElement? = null,
) {
    fun city(id: Long?): EventCity? = cities.firstOrNull { it.id == id }
    fun zone(cityId: Long?): ZoneId = city(cityId)?.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: ZoneId.of("Europe/Rome")
}

/** «SEO» con l'indicizzazione, che nella gestione non c'è. */
@Serializable
internal data class EditorSeo(val title: String? = null, val description: String? = null, val image: String? = null, val indexing: String = "automatic")

/** Una regola di ricorrenza: RRULE, «Fino al», date escluse e fin dove sono già state generate. */
@Serializable
internal data class EditorRecurrence(
    val id: Long = 0,
    val rrule: String = "",
    val until: String? = null,
    val exdates: List<String> = emptyList(),
    @SerialName("generated_until") val generatedUntil: String? = null,
)

/** Una foto della galleria. */
@Serializable
internal data class EditorGalleryItem(val id: Long = 0, val thumb: String? = null, val card: String? = null, val full: String? = null)

/** Una data dell'evento in breve: per il programma e per «Gestisci biglietti» (percorso del sito, nativo nell'app). */
@Serializable
internal data class EditorDateRef(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ticketing_path") val ticketingPath: String? = null,
)

/** Il tetto di `date_refs`: il server manda solo le date non finite, al massimo `limit`. */
@Serializable
internal data class EditorDateRefsMeta(
    val truncated: Boolean = false,
    val limit: Int = 0,
)

/** Le azioni della testata della pagina «Modifica». */
@Serializable
internal data class EditorAbilities(
    val update: Boolean = false,
    val feature: Boolean = false,
    val admin: Boolean = false,
    val schedule: Boolean = false,
    val unschedule: Boolean = false,
    val generate: Boolean = false,
    val dates: Boolean = false,
)

/** I campi che la redazione ha in più rispetto al modulo della gestione. */
@Serializable
internal data class EditorEventValues(
    val id: Long = 0,
    val slug: String = "",
    val status: LabeledValue = LabeledValue(),
    val title: String = "",
    val subtitle: String? = null,
    @SerialName("short_description") val shortDescription: String? = null,
    @SerialName("age_restriction") val ageRestriction: String? = null,
    val language: String? = null,
    @SerialName("video_url") val videoUrl: String? = null,
    val city: EventCity = EventCity(),
    val venue: NamedRef? = null,
    @SerialName("is_outdoor") val isOutdoor: Boolean = false,
    val organizer: NamedRef? = null,
    val organizers: List<NamedRef> = emptyList(),
    @SerialName("price_notes") val priceNotes: String? = null,
    val currency: String = "EUR",
    @SerialName("booking_required") val bookingRequired: Boolean = false,
    @SerialName("booking_phone") val bookingPhone: String? = null,
    val seo: EditorSeo = EditorSeo(),
    @SerialName("is_demo") val isDemo: Boolean = false,
    val source: LabeledValue = LabeledValue(),
    @SerialName("verification_status") val verificationStatus: String = "unverified",
    @SerialName("editorial_score") val editorialScore: Int = 0,
    @SerialName("is_featured") val isFeatured: Boolean = false,
    @SerialName("featured_until") val featuredUntil: String? = null,
    @SerialName("rejection_reason") val rejectionReason: String? = null,
    @SerialName("scheduled_publish_at") val scheduledPublishAt: String? = null,
    @SerialName("published_at") val publishedAt: String? = null,
    val recurrences: List<EditorRecurrence> = emptyList(),
    val poster: Poster? = null,
    val gallery: List<EditorGalleryItem> = emptyList(),
    @SerialName("date_refs") val dateRefs: List<EditorDateRef> = emptyList(),
    @SerialName("date_refs_meta") val dateRefsMeta: EditorDateRefsMeta = EditorDateRefsMeta(),
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
    val abilities: EditorAbilities = EditorAbilities(),
)

/** La risposta di `GET E/events/form` e `GET E/events/{id}/form`: scelte e (in modifica) la scheda grezza. */
@Serializable
internal data class EditorFormEnvelope(val options: EditorEventOptions = EditorEventOptions(), val event: JsonObject? = null)

/** La risposta di un salvataggio o di un gesto della testata: la scheda aggiornata e il messaggio del pannello. */
@Serializable
internal data class EditorEventResult(val data: JsonObject = JsonObject(emptyMap()), val message: String? = null, val meta: JsonObject? = null)

/** Locandina e galleria dopo un caricamento. */
@Serializable
internal data class EditorImages(val poster: Poster? = null, val gallery: List<EditorGalleryItem> = emptyList())

/** Un locale delle ricerche dei campi «Locale» e «Locale di questa data». */
@Serializable
internal data class EditorVenueChoice(
    val id: Long = 0,
    val name: String = "",
    val city: String? = null,
    val address: String? = null,
    @SerialName("ticketing_enabled") val ticketingEnabled: Boolean = false,
)

/** I tag trovati, e se il nome scritto si può creare. */
@Serializable
internal data class EditorTagResults(val data: List<NamedRef> = emptyList(), val meta: EditorTagMeta = EditorTagMeta())

@Serializable
internal data class EditorTagMeta(@SerialName("can_create") val canCreate: Boolean = false)

/* ——— Le date ——— */

/** Un artista della lineup di una data. */
@Serializable
internal data class EditorLineupItem(
    val id: Long? = null,
    val name: String = "",
    val role: String = "live",
    @SerialName("starts_at") val startsAt: String? = null,
    val url: String? = null,
)

/** Una voce del listino della sola data. */
@Serializable
internal data class EditorDateTier(
    val id: Long? = null,
    val name: String = "",
    val price: Double? = null,
    val status: String = "available",
    val url: String? = null,
    val note: String? = null,
)

/** I gesti di una riga delle date. */
@Serializable
internal data class EditorDateAbilities(
    val update: Boolean = false,
    val shift: Boolean = false,
    val cancel: Boolean = false,
    @SerialName("sold_out") val soldOut: Boolean = false,
    val destroy: Boolean = false,
    val ticketing: Boolean = false,
)

/** Una data con i campi del modulo della redazione. */
@Serializable
internal data class EditorDate(
    val id: Long = 0,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("doors_at") val doorsAt: String? = null,
    @SerialName("effective_ends_at") val effectiveEndsAt: String? = null,
    @SerialName("business_date") val businessDate: String? = null,
    val timezone: String = "Europe/Rome",
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    val status: LabeledValue = LabeledValue(),
    @SerialName("status_note") val statusNote: String? = null,
    val highlight: String? = null,
    val capacity: Int? = null,
    @SerialName("capacity_left") val capacityLeft: Int? = null,
    val venue: NamedRef? = null,
    @SerialName("practical_details") val practicalDetails: JsonElement? = null,
    @SerialName("cost_breakdown") val costBreakdown: JsonElement? = null,
    val currency: String = "EUR",
    val series: Boolean = false,
    @SerialName("is_exception") val isException: Boolean = false,
    @SerialName("lineups_count") val lineupsCount: Int = 0,
    val lineup: List<EditorLineupItem> = emptyList(),
    @SerialName("ticket_tiers") val ticketTiers: List<EditorDateTier> = emptyList(),
    @SerialName("ticketing_path") val ticketingPath: String? = null,
    val abilities: EditorDateAbilities = EditorDateAbilities(),
) {
    val zone: ZoneId get() = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
}

/** L'evento in breve sopra l'elenco delle date. */
@Serializable
internal data class EditorDatesEvent(
    val id: Long = 0,
    val title: String = "",
    val status: LabeledValue = LabeledValue(),
    val city: String = "",
    val timezone: String = "Europe/Rome",
    val currency: String = "EUR",
)

@Serializable
internal data class EditorDatesAbilities(val create: Boolean = false, val generate: Boolean = false)

/** Le scelte del modulo della data e dei gesti, con le etichette del pannello. */
@Serializable
internal data class EditorDateChoices(
    @SerialName("occurrence_statuses") val occurrenceStatuses: List<LabeledValue> = emptyList(),
    @SerialName("lineup_roles") val lineupRoles: List<LabeledValue> = emptyList(),
    @SerialName("ticket_tier_statuses") val ticketTierStatuses: List<LabeledValue> = emptyList(),
    val memberships: List<LabeledValue> = emptyList(),
    val scopes: List<EditorScope> = emptyList(),
)

/** La `meta` dell'elenco delle date. */
@Serializable
internal data class EditorDatesMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    val total: Int = 0,
    val event: EditorDatesEvent = EditorDatesEvent(),
    val abilities: EditorDatesAbilities = EditorDatesAbilities(),
    val choices: EditorDateChoices = EditorDateChoices(),
) {
    val zone: ZoneId get() = runCatching { ZoneId.of(event.timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
}

@Serializable
internal data class EditorDatesPage(val data: List<EditorDate> = emptyList(), val meta: EditorDatesMeta = EditorDatesMeta())

/** Una data dopo un salvataggio o un gesto: quante ne sono cambiate e il messaggio del pannello. */
@Serializable
internal data class EditorDateResult(val data: EditorDate = EditorDate(), val message: String? = null, val meta: EditorChanged? = null)

@Serializable
internal data class EditorChanged(val changed: Int = 0, val created: Int = 0)
