package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.Poster
import java.net.URI
import java.net.URLEncoder
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * Sponsorizzazioni (P6): le pagine «Sponsorizzazioni» e «Statistiche
 * sponsorizzazioni» dei pannelli di locale e organizzazione. I testi che il
 * sito scrive dal server (stato di un ordine, frase sotto la barra, prezzo
 * da concordare) arrivano già scritti e si mostrano così come sono. Ogni
 * campo ha un valore predefinito: un server diverso non deve far fallire
 * la lettura.
 */

@Serializable
internal data class SponsorshipLabeled(val value: String = "", val label: String = "", val color: String? = null)

@Serializable
internal data class SponsorshipEvent(
    val id: Long = 0,
    val title: String = "",
    val slug: String = "",
    val poster: Poster? = null,
    val venue: String? = null,
    /* La data da mostrare: la prossima, o l'ultima se sono tutte passate (ISO con il fuso della città). */
    val date: String? = null,
    @SerialName("already_sponsored") val alreadySponsored: Boolean = false,
    @SerialName("active_on_grant") val activeOnGrant: Boolean = false,
)

/** L'avanzamento di un'unità di consegna, come il riquadro del pannello. */
@Serializable
internal data class SponsorshipProgress(
    val key: String = "",
    val goal: Long? = null,
    val delivered: Long = 0,
    val clicks: Long = 0,
    val percent: Double = 0.0,
    @SerialName("expected_percent") val expectedPercent: Double? = null,
    val color: String = "primary",
    val state: String? = null,
    val badge: String = "",
    @SerialName("show_badge") val showBadge: Boolean = false,
    val numbers: String = "",
    val phrase: String = "",
    @SerialName("days_left") val daysLeft: Int? = null,
    @SerialName("days_left_label") val daysLeftLabel: String? = null,
    val notes: List<String> = emptyList(),
    val started: Boolean = false,
    val ended: Boolean = false,
    val completed: Boolean = false,
    val halted: String? = null,
) {
    /** La barra da 0 a 1. */
    val fraction: Float get() = (percent / 100.0).coerceIn(0.0, 1.0).toFloat()
    /** La tacca dell'atteso da 0 a 1, solo mentre una consegna a visualizzazioni è in corso. */
    val expectedFraction: Float? get() = expectedPercent?.let { (it / 100.0).coerceIn(0.0, 1.0).toFloat() }
}

@Serializable
internal data class SponsorshipOrder(
    val id: Long = 0,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    val months: Int = 0,
    @SerialName("months_label") val monthsLabel: String = "",
    val mode: String = "",
    val event: SponsorshipEvent? = null,
    val what: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("amount_cents") val amountCents: Long = 0,
    val currency: String = "EUR",
    @SerialName("amount_label") val amountLabel: String = "",
    val status: SponsorshipLabeled = SponsorshipLabeled(),
    val reason: String? = null,
    val hint: String? = null,
    @SerialName("request_note") val requestNote: String? = null,
    @SerialName("can_retry") val canRetry: Boolean = false,
    @SerialName("can_cancel") val canCancel: Boolean = false,
    @SerialName("can_withdraw") val canWithdraw: Boolean = false,
)

@Serializable
internal data class SponsorshipGrantEvent(
    val id: Long = 0,
    val event: SponsorshipEvent? = null,
    val impressions: Long = 0,
    val clicks: Long = 0,
    val numbers: String = "",
    val share: Int? = null,
    @SerialName("share_label") val shareLabel: String? = null,
    val active: Boolean = true,
    @SerialName("stopped_label") val stoppedLabel: String? = null,
    @SerialName("can_stop") val canStop: Boolean = false,
)

@Serializable
internal data class SponsorshipGrant(
    val id: Long = 0,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    val mode: String = "",
    @SerialName("mode_label") val modeLabel: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val complimentary: Boolean = false,
    @SerialName("paid_at") val paidAt: String? = null,
    @SerialName("payment_label") val paymentLabel: String = "",
    val progress: SponsorshipProgress = SponsorshipProgress(),
    val events: List<SponsorshipGrantEvent> = emptyList(),
)

@Serializable
internal data class SponsorshipCampaign(
    val id: Long = 0,
    val event: SponsorshipEvent? = null,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    val phase: SponsorshipLabeled = SponsorshipLabeled(),
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val progress: SponsorshipProgress? = null,
)

/** Un periodo già attivo «a scelta»: con questo si mettono in evidenza eventi subito. */
@Serializable
internal data class SelectableGrant(
    val id: Long = 0,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("active_event_ids") val activeEventIds: List<Long> = emptyList(),
)

@Serializable
internal data class SponsorshipHow(val title: String = "", val steps: List<String> = emptyList(), val disclosure: String = "")

@Serializable
internal data class SponsorshipNotOwner(val title: String = "", val body: String = "")

@Serializable
internal data class SponsorshipLimits(
    @SerialName("requests_per_hour") val requestsPerHour: Int = 5,
    @SerialName("max_open_requests") val maxOpenRequests: Int = 3,
    @SerialName("note_max") val noteMax: Int = SPONSORSHIP_NOTE_MAX,
    @SerialName("events_max") val eventsMax: Int = SPONSORSHIP_EVENTS_MAX,
)

@Serializable
internal data class SponsorshipTotals(val impressions: Long = 0, val clicks: Long = 0)

/** `GET S/sponsorships`: la pagina intera. */
@Serializable
internal data class SponsorshipDesk(
    @SerialName("can_purchase") val canPurchase: Boolean = false,
    @SerialName("pays_online") val paysOnline: Boolean = false,
    val how: SponsorshipHow = SponsorshipHow(),
    @SerialName("not_owner") val notOwner: SponsorshipNotOwner? = null,
    val limits: SponsorshipLimits = SponsorshipLimits(),
    @SerialName("sponsorable_count") val sponsorableCount: Int = 0,
    @SerialName("selectable_grants") val selectableGrants: List<SelectableGrant> = emptyList(),
    val orders: List<SponsorshipOrder> = emptyList(),
    val measures: String = "",
    val totals: SponsorshipTotals = SponsorshipTotals(),
    val grants: List<SponsorshipGrant> = emptyList(),
    val campaigns: List<SponsorshipCampaign> = emptyList(),
    @SerialName("campaigns_next_page") val campaignsNextPage: Int? = null,
)

@Serializable internal data class SponsorshipDeskEnvelope(val data: SponsorshipDesk = SponsorshipDesk())

@Serializable
internal data class SponsorshipListMeta(
    @SerialName("next_page") val nextPage: Int? = null,
    val total: Int = 0,
    @SerialName("has_more") val hasMore: Boolean = false,
)

@Serializable internal data class SponsorshipCampaignPage(val data: List<SponsorshipCampaign> = emptyList(), val meta: SponsorshipListMeta = SponsorshipListMeta())
@Serializable internal data class SponsorshipEventPage(val data: List<SponsorshipEvent> = emptyList(), val meta: SponsorshipListMeta = SponsorshipListMeta())

@Serializable
internal data class SponsorshipDuration(
    val key: String = "",
    val months: Int = 0,
    @SerialName("months_label") val monthsLabel: String = "",
    @SerialName("amount_cents") val amountCents: Long? = null,
    @SerialName("amount_label") val amountLabel: String = "",
    @SerialName("pays_online") val paysOnline: Boolean = false,
)

@Serializable
internal data class SponsorshipFormat(
    val value: String = "",
    val label: String = "",
    val description: String = "",
    val rotation: String = "",
    @SerialName("sells_views") val sellsViews: Boolean = false,
    @SerialName("from_label") val fromLabel: String? = null,
    val priced: Boolean = false,
    val durations: List<SponsorshipDuration> = emptyList(),
)

@Serializable
internal data class SponsorshipCatalog(
    @SerialName("pays_online") val paysOnline: Boolean = false,
    @SerialName("price_vat") val priceVat: String = "",
    val placements: List<SponsorshipFormat> = emptyList(),
)

@Serializable internal data class SponsorshipCatalogEnvelope(val data: SponsorshipCatalog = SponsorshipCatalog())

/** L'esito di invio, riprova, annulla, ritira e ritorno dal checkout. */
@Serializable
internal data class SponsorshipOrderResult(
    /* `checkout`, `request`, `paid`, `cancelled`, `withdrawn`, `returned_pending`, `returned_cancelled`. */
    val mode: String = "",
    @SerialName("checkout_url") val checkoutUrl: String? = null,
    val order: SponsorshipOrder? = null,
)

@Serializable
internal data class SponsorshipOrderEnvelope(val data: SponsorshipOrderResult = SponsorshipOrderResult(), val message: String? = null)

@Serializable
internal data class SponsorshipActionData(@SerialName("campaign_id") val campaignId: Long = 0, val status: String? = null)

@Serializable
internal data class SponsorshipActionEnvelope(val data: SponsorshipActionData = SponsorshipActionData(), val message: String? = null)

/* ----------------------------------------------------------- statistiche */

@Serializable
internal data class SponsorshipPeriod(val value: String = "", val label: String = "")

@Serializable
internal data class SponsorshipAnalyticsTotals(
    val impressions: Long = 0,
    val clicks: Long = 0,
    val rate: Double? = null,
    @SerialName("rate_label") val rateLabel: String = "",
    @SerialName("min_ctr") val minCtr: Int = 100,
    @SerialName("rate_threshold") val rateThreshold: String? = null,
    val explain: String = "",
)

@Serializable
internal data class SponsorshipDay(val date: String = "", val impressions: Long = 0, val clicks: Long = 0)

@Serializable
internal data class SponsorshipDeliveryDay(val date: String = "", val delivered: Long = 0, val expected: Long = 0)

@Serializable
internal data class SponsorshipDelivery(val days: List<SponsorshipDeliveryDay> = emptyList(), val delivered: Long = 0, val expected: Long = 0)

@Serializable
internal data class SponsorshipEconomics(
    @SerialName("spend_cents") val spendCents: Long? = null,
    val currency: String = "EUR",
    val bookings: Int? = null,
    val attendances: Int? = null,
    @SerialName("per_booking_cents") val perBookingCents: Long? = null,
    @SerialName("per_attendance_cents") val perAttendanceCents: Long? = null,
)

@Serializable
internal data class SponsorshipCampaignOption(
    val id: Long = 0,
    val event: SponsorshipEvent? = null,
    @SerialName("event_gone") val eventGone: String? = null,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
)

@Serializable internal data class SponsorshipCampaignOptions(val data: List<SponsorshipCampaignOption> = emptyList())

@Serializable
internal data class SponsorshipWindow(val from: String = "", val to: String = "")

@Serializable
internal data class SponsorshipAnalytics(
    val timezone: String? = null,
    val periods: List<SponsorshipPeriod> = emptyList(),
    val window: SponsorshipWindow = SponsorshipWindow(),
    val totals: SponsorshipAnalyticsTotals = SponsorshipAnalyticsTotals(),
    val series: List<SponsorshipDay> = emptyList(),
    val campaign: SponsorshipCampaignOption? = null,
    val progress: SponsorshipProgress? = null,
    @SerialName("progress_lead") val progressLead: String? = null,
    val delivery: SponsorshipDelivery? = null,
    val economics: SponsorshipEconomics? = null,
)

@Serializable internal data class SponsorshipAnalyticsEnvelope(val data: SponsorshipAnalytics = SponsorshipAnalytics())

@Serializable
internal data class SponsorshipClick(
    val id: Long = 0,
    @SerialName("clicked_at") val clickedAt: String? = null,
    val event: SponsorshipEvent? = null,
    val title: String = "",
    val venue: String? = null,
    val channel: String = "",
    val placement: String = "",
    val page: String = "",
)

@Serializable internal data class SponsorshipClickPage(val data: List<SponsorshipClick> = emptyList(), val meta: SponsorshipListMeta = SponsorshipListMeta())

/** `GET S/sponsorships/campaigns/{id}`: la scheda di una campagna. */
@Serializable
internal data class SponsorshipCampaignDetail(
    val id: Long = 0,
    val event: SponsorshipEvent? = null,
    @SerialName("event_gone") val eventGone: String? = null,
    val placement: SponsorshipLabeled = SponsorshipLabeled(),
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    val phase: SponsorshipLabeled = SponsorshipLabeled(),
    @SerialName("grant_unit") val grantUnit: Boolean = false,
    @SerialName("progress_lead") val progressLead: String? = null,
    val impressions: Long = 0,
    val clicks: Long = 0,
    val progress: SponsorshipProgress? = null,
    val delivery: SponsorshipDelivery? = null,
    val economics: SponsorshipEconomics? = null,
    @SerialName("economics_help") val economicsHelp: String? = null,
)

@Serializable internal data class SponsorshipCampaignEnvelope(val data: SponsorshipCampaignDetail = SponsorshipCampaignDetail())

/** I filtri delle statistiche: periodo (`7`, `30`, `90`, `365`, `all`, `custom`), date per `custom`, campagna. */
internal data class SponsorshipFilters(val days: String = "30", val from: String? = null, val to: String? = null, val campaignId: Long? = null) {
    fun query(page: Int? = null): String = buildList {
        add("days=" + encodeSponsorship(days))
        if (days == "custom") {
            from?.let { add("from=" + encodeSponsorship(it)) }
            to?.let { add("to=" + encodeSponsorship(it)) }
        }
        campaignId?.takeIf { it > 0 }?.let { add("campaign=$it") }
        page?.let { add("page=$it") }
    }.joinToString("&")
}

/** Quello che si manda per una sponsorizzazione nuova. */
internal data class SponsorshipChoice(val scope: String, val eventId: Long?, val placement: String, val duration: String, val note: String?)

/** Le chiamate dell'area; un'interfaccia perché i test dei ViewModel usino un server finto. */
internal interface ManagementSponsorshipsSource {
    suspend fun desk(subject: ManagementSubject, campaignsPage: Int = 1): SponsorshipDesk
    suspend fun campaigns(subject: ManagementSubject, page: Int): SponsorshipCampaignPage
    suspend fun events(subject: ManagementSubject, query: String, limit: Int, grantId: Long?): SponsorshipEventPage
    suspend fun catalog(subject: ManagementSubject): SponsorshipCatalog
    suspend fun submit(subject: ManagementSubject, choice: SponsorshipChoice): SponsorshipOrderEnvelope
    suspend fun retry(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope
    suspend fun cancel(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope
    suspend fun withdraw(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope
    suspend fun reconcile(subject: ManagementSubject, orderId: Long, cancelled: Boolean): SponsorshipOrderEnvelope
    suspend fun activate(subject: ManagementSubject, grantId: Long, eventId: Long): SponsorshipActionEnvelope
    suspend fun stop(subject: ManagementSubject, campaignId: Long): SponsorshipActionEnvelope
    suspend fun campaign(subject: ManagementSubject, campaignId: Long): SponsorshipCampaignDetail
    suspend fun analytics(subject: ManagementSubject, filters: SponsorshipFilters): SponsorshipAnalytics
    suspend fun clicks(subject: ManagementSubject, filters: SponsorshipFilters, page: Int): SponsorshipClickPage
    suspend fun campaignOptions(subject: ManagementSubject, query: String): List<SponsorshipCampaignOption>
}

internal class ManagementSponsorshipsApi(private val api: ApiClient, private val token: String) : ManagementSponsorshipsSource {
    private fun base(subject: ManagementSubject) = "${subject.apiPath}/sponsorships"

    override suspend fun desk(subject: ManagementSubject, campaignsPage: Int): SponsorshipDesk =
        api.get<SponsorshipDeskEnvelope>(base(subject) + "?campaigns_page=$campaignsPage", token).data

    override suspend fun campaigns(subject: ManagementSubject, page: Int): SponsorshipCampaignPage =
        api.get(base(subject) + "/campaigns?page=$page", token)

    override suspend fun events(subject: ManagementSubject, query: String, limit: Int, grantId: Long?): SponsorshipEventPage =
        api.get(sponsorshipEventsPath(subject, query, limit, grantId), token)

    override suspend fun catalog(subject: ManagementSubject): SponsorshipCatalog =
        api.get<SponsorshipCatalogEnvelope>(base(subject) + "/catalog", token).data

    override suspend fun submit(subject: ManagementSubject, choice: SponsorshipChoice): SponsorshipOrderEnvelope {
        val body = buildJsonObject {
            put("scope", choice.scope)
            if (choice.scope == "event") choice.eventId?.let { put("event_id", it) }
            put("placement", choice.placement)
            put("duration", choice.duration)
            choice.note?.trim()?.takeIf { it.isNotEmpty() }?.let { put("note", JsonPrimitive(it)) }
        }
        return api.execute(base(subject) + "/orders", "POST", body.toString(), token)
    }

    override suspend fun retry(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope =
        api.execute(base(subject) + "/orders/$orderId/retry", "POST", "{}", token)

    override suspend fun cancel(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope =
        api.execute(base(subject) + "/orders/$orderId/cancel", "POST", "{}", token)

    override suspend fun withdraw(subject: ManagementSubject, orderId: Long): SponsorshipOrderEnvelope =
        api.delete(base(subject) + "/orders/$orderId", token)

    override suspend fun reconcile(subject: ManagementSubject, orderId: Long, cancelled: Boolean): SponsorshipOrderEnvelope =
        api.execute(base(subject) + "/orders/$orderId/reconcile", "POST", buildJsonObject { put("cancelled", cancelled) }.toString(), token)

    override suspend fun activate(subject: ManagementSubject, grantId: Long, eventId: Long): SponsorshipActionEnvelope =
        api.execute(base(subject) + "/grants/$grantId/events", "POST", buildJsonObject { put("event_id", eventId) }.toString(), token)

    override suspend fun stop(subject: ManagementSubject, campaignId: Long): SponsorshipActionEnvelope =
        api.execute(base(subject) + "/campaigns/$campaignId/stop", "POST", "{}", token)

    override suspend fun campaign(subject: ManagementSubject, campaignId: Long): SponsorshipCampaignDetail =
        api.get<SponsorshipCampaignEnvelope>(base(subject) + "/campaigns/$campaignId", token).data

    override suspend fun analytics(subject: ManagementSubject, filters: SponsorshipFilters): SponsorshipAnalytics =
        api.get<SponsorshipAnalyticsEnvelope>(base(subject) + "/analytics?" + filters.query(), token).data

    override suspend fun clicks(subject: ManagementSubject, filters: SponsorshipFilters, page: Int): SponsorshipClickPage =
        api.get(base(subject) + "/analytics/clicks?" + filters.query(page), token)

    override suspend fun campaignOptions(subject: ManagementSubject, query: String): List<SponsorshipCampaignOption> {
        val q = query.trim().take(120)
        return api.get<SponsorshipCampaignOptions>(base(subject) + "/analytics/campaigns" + (if (q.isEmpty()) "" else "?q=" + encodeSponsorship(q)), token).data
    }
}

/** `S/sponsorships/events` con la ricerca (al massimo 80 caratteri) e il periodo attivo da confrontare. */
internal fun sponsorshipEventsPath(subject: ManagementSubject, query: String, limit: Int, grantId: Long?): String {
    val params = buildList {
        query.trim().take(SPONSORSHIP_SEARCH_MAX).takeIf { it.isNotEmpty() }?.let { add("q=" + encodeSponsorship(it)) }
        add("limit=" + limit.coerceIn(1, SPONSORSHIP_EVENTS_MAX))
        grantId?.takeIf { it > 0 }?.let { add("grant=$it") }
    }
    return "${subject.apiPath}/sponsorships/events?" + params.joinToString("&")
}

private fun encodeSponsorship(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name()).replace("+", "%20")

/**
 * Il ritorno da Stripe (`/app/sponsorizzazioni/ritorno?subject=venue&id=12&order=34`):
 * il soggetto, l'ordine e se la pagina di pagamento è stata chiusa senza pagare.
 */
internal data class SponsorshipReturn(val kind: SubjectKind, val subjectId: Long, val orderId: Long, val cancelled: Boolean) {
    val subjectKey: String get() = "${kind.name.lowercase()}:$subjectId"

    companion object {
        const val PATH = "/app/sponsorizzazioni/ritorno"

        fun parse(uri: URI): SponsorshipReturn? {
            if (uri.path.orEmpty().trimEnd('/') != PATH) return null
            val params = uri.rawQuery.orEmpty().split('&').mapNotNull { part ->
                part.substringBefore('=').takeIf { it.isNotEmpty() }?.let { it to part.substringAfter('=', "") }
            }.toMap()
            val kind = when (params["subject"]) { "venue" -> SubjectKind.Venue; "organizer" -> SubjectKind.Organizer; else -> return null }
            val id = params["id"]?.takeIf { ID.matches(it) }?.toLongOrNull() ?: return null
            val order = params["order"]?.takeIf { ID.matches(it) }?.toLongOrNull() ?: return null
            return SponsorshipReturn(kind, id, order, params["cancelled"] == "1")
        }

        private val ID = Regex("^[1-9][0-9]{0,17}$")
    }
}

/* I limiti del server, controllati anche qui per non mandare richieste già sbagliate. */
internal const val SPONSORSHIP_NOTE_MAX = 1000
internal const val SPONSORSHIP_SEARCH_MAX = 80
internal const val SPONSORSHIP_EVENTS_STEP = 6
internal const val SPONSORSHIP_EVENTS_MAX = 60
