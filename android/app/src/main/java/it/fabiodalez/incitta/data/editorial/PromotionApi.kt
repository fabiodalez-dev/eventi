package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.Poster
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.SponsorshipEvent
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import java.io.File
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * R8 — Promozione della redazione (`E/sponsorships`, `E/sponsorship-orders`,
 * `E/sponsorship-grants`, `E/sponsorship-prices`, `E/sponsorship-banners`).
 * Ogni campo ha un valore predefinito: un server diverso non deve impedire di
 * aprire l'elenco. Le azioni permesse di ogni riga arrivano in `abilities`;
 * i testi (stati, frasi dell'avanzamento, prezzo dal listino) arrivano già
 * scritti dal server, con le parole del pannello.
 */

/** Una scelta di una tendina o di un gruppo di pulsanti, con la riga di aiuto se c'è. */
@Serializable
internal data class PromoChoice(val value: String = "", val label: String = "", val help: String? = null) {
    fun option() = OptionChoice(value, label)
}

internal fun List<PromoChoice>.options(): List<OptionChoice> = map(PromoChoice::option)

/** Un evento in una riga: locandina, titolo, locale, prossima data, se è pubblicato. */
@Serializable
internal data class PromoEvent(
    val id: Long = 0,
    val title: String = "",
    val slug: String = "",
    val poster: Poster? = null,
    val venue: String? = null,
    val date: String? = null,
    val published: Boolean = true,
    @SerialName("city_id") val cityId: Long? = null,
    val timezone: String? = null,
    @SerialName("more_dates") val moreDates: Int = 0,
    @SerialName("already_sponsored") val alreadySponsored: Boolean = false,
) {
    /** La stessa riga nella forma dei componenti delle sponsorizzazioni della gestione. */
    fun asSponsorshipEvent() = SponsorshipEvent(id = id, title = title, slug = slug, poster = poster, venue = venue, date = date, alreadySponsored = alreadySponsored)
}

/** L'avanzamento nella colonna degli elenchi. */
@Serializable
internal data class PromoProgress(
    val state: String = "period",
    @SerialName("state_label") val stateLabel: String = "",
    val color: String = "gray",
    val goal: Long? = null,
    val delivered: Long = 0,
    val percent: Double? = null,
    @SerialName("expected_percent") val expectedPercent: Double? = null,
    val summary: String = "",
)

@Serializable
internal data class PromoCard(val key: String = "", val label: String = "", val value: String = "", val hint: String? = null)

@Serializable
internal data class PromoDaily(val date: String = "", val label: String = "", val delivered: Long = 0, val expected: Long = 0, val text: String = "")

@Serializable
internal data class PromoUnitEvent(
    val id: Long = 0,
    val event: PromoEvent? = null,
    @SerialName("event_gone") val eventGone: String? = null,
    val numbers: String = "",
    val share: String = "",
)

/** La scheda «Avanzamento» di una campagna o di un'abilitazione. */
@Serializable
internal data class PromoProgressDetail(
    val progress: PromoProgress? = null,
    val empty: String? = null,
    @SerialName("true_end") val trueEnd: String? = null,
    val sentence: String? = null,
    val legend: String? = null,
    val cards: List<PromoCard> = emptyList(),
    val provisional: String? = null,
    val daily: List<PromoDaily> = emptyList(),
    @SerialName("daily_empty") val dailyEmpty: String? = null,
    val events: List<PromoUnitEvent> = emptyList(),
    @SerialName("events_lead") val eventsLead: String? = null,
)

@Serializable
internal data class PromoPhase(val value: String = "", val label: String = "", val color: String? = null)

/** Una riga dell'elenco delle campagne. */
@Serializable
internal data class CampaignRow(
    val id: Long = 0,
    val event: PromoEvent? = null,
    @SerialName("event_gone") val eventGone: String? = null,
    val placement: ValueLabel = ValueLabel(),
    val status: ValueLabel = ValueLabel(),
    val phase: PromoPhase = PromoPhase(),
    val progress: PromoProgress? = null,
    val timezone: String = "Europe/Rome",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("advertiser_name") val advertiserName: String = "",
    val impressions: Long = 0,
    val clicks: Long = 0,
    @SerialName("click_rate") val clickRate: String = "—",
    @SerialName("grant_id") val grantId: Long? = null,
    @SerialName("toggle_label") val toggleLabel: String = "",
    val abilities: JsonElement? = null,
) {
    val title: String get() = event?.title ?: eventGone.orEmpty()
}

/** I valori del modulo di modifica di una campagna. */
@Serializable
internal data class CampaignForm(
    @SerialName("event_id") val eventId: Long? = null,
    val placement: String = "",
    val status: String = "",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val priority: Long = 0,
    val weight: Long = 1,
    @SerialName("impressions_cap") val impressionsCap: Long? = null,
    @SerialName("clicks_cap") val clicksCap: Long? = null,
    @SerialName("impressions_goal") val impressionsGoal: Long? = null,
    val pacing: String = "even",
    @SerialName("advertiser_name") val advertiserName: String = "",
    @SerialName("advertiser_email") val advertiserEmail: String? = null,
    @SerialName("advertiser_url") val advertiserUrl: String? = null,
    val amount: String? = null,
    val currency: String = "EUR",
    @SerialName("invoice_reference") val invoiceReference: String? = null,
    val notes: String? = null,
)

@Serializable
internal data class PromoChoices(
    val placements: List<PromoChoice> = emptyList(),
    val statuses: List<PromoChoice> = emptyList(),
    val pacings: List<PromoChoice> = emptyList(),
    @SerialName("pacing_states") val pacingStates: List<PromoChoice> = emptyList(),
    val months: List<PromoChoice> = emptyList(),
    @SerialName("sponsor_types") val sponsorTypes: List<PromoChoice> = emptyList(),
    val scopes: List<PromoChoice> = emptyList(),
    val products: List<PromoChoice> = emptyList(),
    val modes: List<PromoChoice> = emptyList(),
    val methods: List<PromoChoice> = emptyList(),
    val settlements: List<PromoChoice> = emptyList(),
)

/** La scheda di una campagna (`GET E/sponsorships/{id}`). */
@Serializable
internal data class CampaignDetail(
    val id: Long = 0,
    val event: PromoEvent? = null,
    @SerialName("event_gone") val eventGone: String? = null,
    val placement: ValueLabel = ValueLabel(),
    val status: ValueLabel = ValueLabel(),
    val phase: PromoPhase = PromoPhase(),
    val timezone: String = "Europe/Rome",
    @SerialName("advertiser_name") val advertiserName: String = "",
    val impressions: Long = 0,
    val clicks: Long = 0,
    @SerialName("click_rate") val clickRate: String = "—",
    @SerialName("toggle_label") val toggleLabel: String = "",
    val abilities: JsonElement? = null,
    val form: CampaignForm = CampaignForm(),
    @SerialName("on_grant") val onGrant: Boolean = false,
    @SerialName("grant_help") val grantHelp: String? = null,
    @SerialName("event_warning") val eventWarning: String? = null,
    @SerialName("period_only") val periodOnly: String? = null,
    @SerialName("progress_detail") val progressDetail: PromoProgressDetail = PromoProgressDetail(),
    val choices: PromoChoices = PromoChoices(),
)

@Serializable
internal data class CampaignToggle(val data: CampaignRow = CampaignRow(), val message: String = "")

@Serializable
internal data class CampaignList(val data: List<CampaignRow> = emptyList(), val meta: it.fabiodalez.incitta.ui.editorial.common.PageMeta = it.fabiodalez.incitta.ui.editorial.common.PageMeta(), val choices: PromoChoices = PromoChoices()) {
    fun page() = EditorialPage(data, meta)
}

@Serializable
internal data class DeletedIds(val deleted: List<Long> = emptyList())

// ----------------------------------------------------- «Nuova sponsorizzazione»

/** Un committente da scegliere (locale o organizzatore) con i contatti che compilano chi paga. */
@Serializable
internal data class SponsorChoice(
    val id: Long = 0,
    val label: String = "",
    val name: String = "",
    @SerialName("city_id") val cityId: Long? = null,
    val email: String? = null,
    val website: String? = null,
)

@Serializable
internal data class EventChoicesMeta(val count: Int = 0, val ready: Boolean = false)

@Serializable
internal data class EventChoices(val data: List<PromoEvent> = emptyList(), val meta: EventChoicesMeta = EventChoicesMeta())

/** Lo stato iniziale del percorso (`GET E/sponsorships/prefill`). */
@Serializable
internal data class WizardPrefill(
    @SerialName("sponsor_type") val sponsorType: String = "venue",
    @SerialName("venue_id") val venueId: Long? = null,
    @SerialName("organizer_id") val organizerId: Long? = null,
    @SerialName("sponsor_label") val sponsorLabel: String? = null,
    val scope: String = "event",
    @SerialName("event_id") val eventId: Long? = null,
    val event: PromoEvent? = null,
    @SerialName("advertiser_name") val advertiserName: String? = null,
    @SerialName("advertiser_email") val advertiserEmail: String? = null,
    @SerialName("advertiser_url") val advertiserUrl: String? = null,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val months: Int? = 1,
    val choices: PromoChoices = PromoChoices(),
)

@Serializable
internal data class WizardEstimate(
    val views: Long = 0,
    val days: Int = 0,
    val provisional: Boolean = false,
    val heading: String = "",
    val description: String = "",
    val status: String = "info",
)

@Serializable
internal data class WizardOccupant(
    val key: String = "",
    val name: String = "",
    val kind: String = "single",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val goal: Long? = null,
    val events: Int = 1,
)

@Serializable
internal data class WizardPrice(val amount: String = "", val label: String = "")

@Serializable
internal data class WizardAllEvents(val sponsor: String? = null, val count: Int = 0, val events: List<PromoEvent> = emptyList())

/** Quello che il pannello calcola mentre si compila (`POST E/sponsorships/estimate`). */
@Serializable
internal data class WizardPreview(
    val scope: String = "event",
    @SerialName("city_id") val cityId: Long? = null,
    val timezone: String = "Europe/Rome",
    @SerialName("period_lead") val periodLead: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("period_only") val periodOnly: String? = null,
    val price: WizardPrice? = null,
    @SerialName("price_hint") val priceHint: String? = null,
    val estimate: WizardEstimate? = null,
    val occupants: List<WizardOccupant> = emptyList(),
    @SerialName("all_events") val allEvents: WizardAllEvents? = null,
    @SerialName("placement_lead") val placementLead: String? = null,
)

@Serializable
internal data class WizardCreated(val kind: String = "sponsorship", val id: Long = 0, val message: String = "")

// --------------------------------------------------------------- ordini

@Serializable
internal data class OrderRow(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val sponsor: String = "",
    @SerialName("sponsor_kind") val sponsorKind: String = "",
    val event: PromoEvent? = null,
    @SerialName("event_label") val eventLabel: String = "",
    val placement: ValueLabel = ValueLabel(),
    val months: Int = 1,
    @SerialName("months_label") val monthsLabel: String = "",
    val mode: ValueLabel = ValueLabel(),
    @SerialName("amount_label") val amountLabel: String = "",
    val status: PromoPhase = PromoPhase(),
    val buyer: String? = null,
    @SerialName("paid_at") val paidAt: String? = null,
    @SerialName("decided_at") val decidedAt: String? = null,
    @SerialName("decided_by") val decidedBy: String? = null,
    @SerialName("request_note") val requestNote: String? = null,
    @SerialName("decision_note") val decisionNote: String? = null,
    @SerialName("payment_reference") val paymentReference: String? = null,
    @SerialName("approve_lead") val approveLead: String = "",
    @SerialName("reject_lead") val rejectLead: String = "",
    @SerialName("suggested_amount") val suggestedAmount: String? = null,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class OrderList(val data: List<OrderRow> = emptyList(), val meta: it.fabiodalez.incitta.ui.editorial.common.PageMeta = it.fabiodalez.incitta.ui.editorial.common.PageMeta(), val choices: PromoChoices = PromoChoices()) {
    fun page() = EditorialPage(data, meta)
}

@Serializable
internal data class OrderDecision(val data: OrderRow = OrderRow(), val message: String = "")

/** Il saldo di una richiesta approvata: pagata (importo, metodo, riferimento) o gratuita (motivo). */
internal data class Settlement(
    val free: Boolean = false,
    val amount: String = "",
    val method: String = "bank_transfer",
    val reference: String = "",
    val reason: String = "",
) {
    fun body(): JsonObject = buildJsonObject {
        if (free) {
            put("settlement", "free")
            put("reason", reason.trim())
        } else {
            put("settlement", "paid")
            put("amount", amount.trim().replace(',', '.'))
            put("payment_method", method)
            reference.trim().takeIf { it.isNotEmpty() }?.let { put("payment_reference", it) }
        }
    }
}

// ----------------------------------------------------------- abilitazioni

@Serializable
internal data class GrantRow(
    val id: Long = 0,
    val sponsor: String = "",
    @SerialName("sponsor_kind") val sponsorKind: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    val mode: ValueLabel = ValueLabel(),
    val placement: ValueLabel = ValueLabel(),
    val enabled: Boolean = true,
    val complimentary: Boolean = false,
    val timezone: String = "Europe/Rome",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("paid_at") val paidAt: String? = null,
    @SerialName("amount_label") val amountLabel: String? = null,
    val progress: PromoProgress? = null,
    val impressions: Long = 0,
    val clicks: Long = 0,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class GrantForm(
    @SerialName("venue_id") val venueId: Long? = null,
    @SerialName("venue_label") val venueLabel: String? = null,
    @SerialName("organizer_id") val organizerId: Long? = null,
    @SerialName("organizer_label") val organizerLabel: String? = null,
    val mode: String = "selected",
    val placement: String = "list_top",
    val enabled: Boolean = true,
    @SerialName("impressions_goal") val impressionsGoal: Long? = null,
    val pacing: String = "even",
    val priority: Long = 0,
    val weight: Long = 1,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val complimentary: Boolean = false,
    val amount: String? = null,
    @SerialName("paid_at") val paidAt: String? = null,
    @SerialName("payment_method") val paymentMethod: String? = null,
    @SerialName("payment_reference") val paymentReference: String? = null,
    val notes: String? = null,
)

@Serializable
internal data class GrantDetail(
    val id: Long = 0,
    val sponsor: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    val impressions: Long = 0,
    val clicks: Long = 0,
    val abilities: JsonElement? = null,
    val form: GrantForm = GrantForm(),
    @SerialName("period_only") val periodOnly: String? = null,
    @SerialName("progress_detail") val progressDetail: PromoProgressDetail = PromoProgressDetail(),
    val choices: PromoChoices = PromoChoices(),
)

@Serializable
internal data class GrantList(val data: List<GrantRow> = emptyList(), val meta: it.fabiodalez.incitta.ui.editorial.common.PageMeta = it.fabiodalez.incitta.ui.editorial.common.PageMeta(), val choices: PromoChoices = PromoChoices()) {
    fun page() = EditorialPage(data, meta)
}

// ---------------------------------------------------------------- listino

@Serializable
internal data class PriceRow(
    val id: Long = 0,
    val placement: ValueLabel = ValueLabel(),
    val months: Int = 1,
    val amount: String = "",
    @SerialName("amount_label") val amountLabel: String = "",
    @SerialName("is_active") val isActive: Boolean = true,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class PriceList(val data: List<PriceRow> = emptyList(), val meta: it.fabiodalez.incitta.ui.editorial.common.PageMeta = it.fabiodalez.incitta.ui.editorial.common.PageMeta(), val choices: PromoChoices = PromoChoices()) {
    fun page() = EditorialPage(data, meta)
}

/** Una voce del listino da salvare. */
internal data class PriceDraft(val id: Long = 0, val placement: String = "", val months: String = "", val amount: String = "", val active: Boolean = true) {
    fun body(): JsonObject = buildJsonObject {
        put("placement", placement)
        put("months", months.toIntOrNull()?.let { JsonPrimitive(it) } ?: JsonPrimitive(months))
        put("amount", amount.trim().replace(',', '.'))
        put("is_active", active)
    }

    companion object {
        fun from(row: PriceRow) = PriceDraft(row.id, row.placement.value, row.months.toString(), row.amount, row.isActive)
    }
}

// ----------------------------------------------------------------- banner

@Serializable
internal data class BannerSettings(
    @SerialName("web_enabled") val webEnabled: Boolean = false,
    @SerialName("android_enabled") val androidEnabled: Boolean = false,
    val lead: String = "",
    val rules: String = "",
    @SerialName("switch_off") val switchOff: String = "",
)

@Serializable
internal data class BannerSaved(val data: BannerSettings = BannerSettings(), val message: String = "")

// ---------------------------------------------------------------- sorgente

/** Le chiamate del pacchetto: un'interfaccia, così i ViewModel si provano senza rete. */
internal interface EditorialPromotionSource {
    suspend fun campaigns(params: List<Pair<String, String>>): CampaignList
    suspend fun exportCampaigns(params: List<Pair<String, String>>, target: File): DownloadedFile
    suspend fun campaign(id: Long): CampaignDetail
    suspend fun updateCampaign(id: Long, body: JsonObject): CampaignDetail
    suspend fun toggleCampaign(id: Long): CampaignToggle
    suspend fun deleteCampaign(id: Long)
    suspend fun deleteCampaigns(ids: List<Long>): DeletedIds
    suspend fun sponsors(type: String, q: String, all: Boolean = false): List<SponsorChoice>
    suspend fun events(params: List<Pair<String, String>>): EventChoices
    suspend fun prefill(eventId: Long): WizardPrefill
    suspend fun estimate(body: JsonObject): WizardPreview
    suspend fun createCampaign(body: JsonObject): WizardCreated
    suspend fun orders(params: List<Pair<String, String>>): OrderList
    suspend fun approveOrder(id: Long, body: JsonObject): OrderDecision
    suspend fun rejectOrder(id: Long, reason: String): OrderDecision
    suspend fun grants(params: List<Pair<String, String>>): GrantList
    suspend fun grant(id: Long): GrantDetail
    suspend fun createGrant(body: JsonObject): GrantDetail
    suspend fun updateGrant(id: Long, body: JsonObject): GrantDetail
    suspend fun prices(params: List<Pair<String, String>>): PriceList
    suspend fun createPrice(body: JsonObject): PriceRow
    suspend fun updatePrice(id: Long, body: JsonObject): PriceRow
    suspend fun deletePrice(id: Long)
    suspend fun banners(): BannerSettings
    suspend fun saveBanners(web: Boolean, android: Boolean): BannerSaved
}

internal class EditorialPromotionApi(private val api: ApiClient, private val token: String) : EditorialPromotionSource {
    override suspend fun campaigns(params: List<Pair<String, String>>): CampaignList = api.get(editorialPath("sponsorships", null, params), token)
    override suspend fun exportCampaigns(params: List<Pair<String, String>>, target: File): DownloadedFile = api.download(editorialPath("sponsorships/export", null, params), token, target)
    override suspend fun campaign(id: Long): CampaignDetail = api.get<ApiEnvelope<CampaignDetail>>("editorial/sponsorships/$id", token).data
    override suspend fun updateCampaign(id: Long, body: JsonObject): CampaignDetail =
        api.execute<ApiEnvelope<CampaignDetail>>("editorial/sponsorships/$id", "PATCH", body.toString(), token).data
    override suspend fun toggleCampaign(id: Long): CampaignToggle = api.post("editorial/sponsorships/$id/toggle", JsonObject(emptyMap()), token)
    override suspend fun deleteCampaign(id: Long) = api.send("editorial/sponsorships/$id", "DELETE", token)
    override suspend fun deleteCampaigns(ids: List<Long>): DeletedIds =
        api.post<ApiEnvelope<DeletedIds>, JsonObject>("editorial/sponsorships/bulk-destroy", bulkBody(ids), token).data
    override suspend fun sponsors(type: String, q: String, all: Boolean): List<SponsorChoice> =
        api.get<ApiEnvelope<List<SponsorChoice>>>(editorialPath("sponsorships/sponsors", null, buildList {
            add("type" to type)
            q.trim().takeIf { it.isNotEmpty() }?.let { add("q" to it.take(120)) }
            if (all) add("all" to "1")
        }), token).data
    override suspend fun events(params: List<Pair<String, String>>): EventChoices = api.get(editorialPath("sponsorships/events", null, params), token)
    override suspend fun prefill(eventId: Long): WizardPrefill =
        api.get<ApiEnvelope<WizardPrefill>>(editorialPath("sponsorships/prefill", null, if (eventId > 0) listOf("event" to eventId.toString()) else emptyList()), token).data
    override suspend fun estimate(body: JsonObject): WizardPreview = api.post<ApiEnvelope<WizardPreview>, JsonObject>("editorial/sponsorships/estimate", body, token).data
    override suspend fun createCampaign(body: JsonObject): WizardCreated = api.post<ApiEnvelope<WizardCreated>, JsonObject>("editorial/sponsorships", body, token).data
    override suspend fun orders(params: List<Pair<String, String>>): OrderList = api.get(editorialPath("sponsorship-orders", null, params), token)
    override suspend fun approveOrder(id: Long, body: JsonObject): OrderDecision = api.post("editorial/sponsorship-orders/$id/approve", body, token)
    override suspend fun rejectOrder(id: Long, reason: String): OrderDecision =
        api.post("editorial/sponsorship-orders/$id/reject", buildJsonObject { put("reason", reason.trim()) }, token)
    override suspend fun grants(params: List<Pair<String, String>>): GrantList = api.get(editorialPath("sponsorship-grants", null, params), token)
    override suspend fun grant(id: Long): GrantDetail = api.get<ApiEnvelope<GrantDetail>>("editorial/sponsorship-grants/$id", token).data
    override suspend fun createGrant(body: JsonObject): GrantDetail = api.post<ApiEnvelope<GrantDetail>, JsonObject>("editorial/sponsorship-grants", body, token).data
    override suspend fun updateGrant(id: Long, body: JsonObject): GrantDetail =
        api.execute<ApiEnvelope<GrantDetail>>("editorial/sponsorship-grants/$id", "PATCH", body.toString(), token).data
    override suspend fun prices(params: List<Pair<String, String>>): PriceList = api.get(editorialPath("sponsorship-prices", null, params), token)
    override suspend fun createPrice(body: JsonObject): PriceRow = api.post<ApiEnvelope<PriceRow>, JsonObject>("editorial/sponsorship-prices", body, token).data
    override suspend fun updatePrice(id: Long, body: JsonObject): PriceRow =
        api.execute<ApiEnvelope<PriceRow>>("editorial/sponsorship-prices/$id", "PATCH", body.toString(), token).data
    override suspend fun deletePrice(id: Long) = api.send("editorial/sponsorship-prices/$id", "DELETE", token)
    override suspend fun banners(): BannerSettings = api.get<ApiEnvelope<BannerSettings>>("editorial/sponsorship-banners", token).data
    override suspend fun saveBanners(web: Boolean, android: Boolean): BannerSaved =
        api.execute("editorial/sponsorship-banners", "PUT", buildJsonObject { put("web_enabled", web); put("android_enabled", android) }.toString(), token)
}

/** Il corpo di «Elimina selezionate». */
internal fun bulkBody(ids: List<Long>): JsonObject = buildJsonObject { put("ids", buildJsonArray { ids.distinct().forEach { add(JsonPrimitive(it)) } }) }

/** Un numero scritto nel modulo: vuoto diventa `null`, altrimenti il numero (o il testo, che il server rifiuta con il suo messaggio). */
internal fun numberOrNull(value: String): JsonElement {
    val text = value.trim()
    if (text.isEmpty()) return JsonNull
    return text.toLongOrNull()?.let { JsonPrimitive(it) } ?: JsonPrimitive(text)
}

/** Un importo in euro scritto con la virgola o con il punto; vuoto diventa `null`. */
internal fun euroOrNull(value: String): JsonElement = value.trim().replace(',', '.').takeIf { it.isNotEmpty() }?.let { JsonPrimitive(it) } ?: JsonNull
