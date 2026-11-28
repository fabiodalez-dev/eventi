package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.editorial.EditorialPromotionApi
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.editorialPath
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SponsorshipAnalytics
import it.fabiodalez.incitta.data.management.SponsorshipCampaignOption
import it.fabiodalez.incitta.data.management.SponsorshipCampaignOptions
import it.fabiodalez.incitta.data.management.SponsorshipClickPage
import it.fabiodalez.incitta.data.management.SponsorshipFilters
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.SubjectPermissions
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipAnalyticsScreen
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipAnalyticsViewModel
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipReportLoader
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R8 — Promozione (R-SP-01…15), solo admin e super admin come il gruppo
 * «Promozione» del pannello: campagne (elenco, esportazione, scheda,
 * modifica, pausa, eliminazione anche in gruppo), «Nuova sponsorizzazione»
 * in quattro passi, ordini (approva, rifiuta), abilitazioni (nuova,
 * modifica), listino, banner degli sponsor, statistiche (la stessa pagina
 * della gestione, con tutte le campagne). Gli id finiscono nello stato
 * salvato della voce, così ogni schermata si ricostruisce anche dopo la
 * chiusura dell'app da parte del sistema.
 */
@Serializable internal data object PromotionRoute
@Serializable internal data object CampaignListRoute
@Serializable internal data class CampaignDetailRoute(val campaignId: Long)
/* `event` > 0: l'arrivo da «Sponsorizza questo evento». */
@Serializable internal data class CampaignWizardRoute(val event: Long = 0)
@Serializable internal data object OrderListRoute
@Serializable internal data object GrantListRoute
/* `grantId` 0 = nuova abilitazione. */
@Serializable internal data class GrantFormRoute(val grantId: Long)
@Serializable internal data object PriceListRoute
@Serializable internal data object BannersRoute
@Serializable internal data class PromotionAnalyticsRoute(val campaign: Long = 0)

/** Il client di R8 per la sessione della redazione. */
internal fun EditorialRepository.promotionApi(): EditorialPromotionSource =
    EditorialPromotionApi(client ?: throw IllegalStateException("Redazione non disponibile"), token)

internal fun NavGraphBuilder.promotionGraph(context: EditorialGraphContext) {
    composable<PromotionRoute> { PromotionHubScreen(context) }
    composable<CampaignListRoute> {
        val model = editorialViewModel { CampaignListViewModel(context.repository, context.repository.promotionApi(), it) }
        CampaignListScreen(model, context)
    }
    composable<CampaignDetailRoute> {
        val model = editorialViewModel { CampaignDetailViewModel(context.repository, context.repository.promotionApi(), it) }
        CampaignDetailScreen(model, context)
    }
    composable<CampaignWizardRoute> {
        val model = editorialViewModel { CampaignWizardViewModel(context.repository, context.repository.promotionApi(), it) }
        CampaignWizardScreen(model, context)
    }
    composable<OrderListRoute> {
        val model = editorialViewModel { OrderListViewModel(context.repository, context.repository.promotionApi(), it) }
        OrderListScreen(model, context)
    }
    composable<GrantListRoute> {
        val model = editorialViewModel { GrantListViewModel(context.repository, context.repository.promotionApi(), it) }
        GrantListScreen(model, context)
    }
    composable<GrantFormRoute> {
        val model = editorialViewModel { GrantFormViewModel(context.repository, context.repository.promotionApi(), it) }
        GrantFormScreen(model, context)
    }
    composable<PriceListRoute> {
        val model = editorialViewModel { PriceListViewModel(context.repository, context.repository.promotionApi(), it) }
        PriceListScreen(model, context)
    }
    composable<BannersRoute> {
        val model = editorialViewModel { BannersViewModel(context.repository, context.repository.promotionApi(), it) }
        BannersScreen(model, context)
    }
    composable<PromotionAnalyticsRoute> {
        val title = stringResource(R.string.editorial_promo_analytics_subject)
        /* `campaign` della rotta è la stessa chiave dello stato salvato del ViewModel della gestione. */
        val model = editorialViewModel { saved -> SponsorshipAnalyticsViewModel(EditorialSponsorshipReports(context.repository, title), saved) }
        SponsorshipAnalyticsScreen(model, context.padding, context::back)
    }
}

/**
 * Le statistiche della redazione: le rotte `E/sponsorships/analytics…`, che
 * rispondono come quelle della gestione. Il «soggetto» è solo un nome e il
 * fuso con cui il server legge i giorni (quello dell'applicazione).
 */
internal class EditorialSponsorshipReports(private val repository: EditorialRepository, private val title: String) : SponsorshipReportLoader {
    private val client get() = repository.client ?: throw IllegalStateException("Redazione non disponibile")

    override suspend fun subject(key: String?): ManagementSubject = editorialReportSubject(title)
    override suspend fun analytics(subject: ManagementSubject, filters: SponsorshipFilters): SponsorshipAnalytics =
        repository.call { client.get<ApiEnvelope<SponsorshipAnalytics>>("editorial/sponsorships/analytics?" + filters.query(), repository.token).data }
    override suspend fun clicks(subject: ManagementSubject, filters: SponsorshipFilters, page: Int): SponsorshipClickPage =
        repository.call { client.get("editorial/sponsorships/analytics/clicks?" + filters.query(page), repository.token) }
    override suspend fun campaignOptions(subject: ManagementSubject, query: String): List<SponsorshipCampaignOption> {
        val q = query.trim().take(120)
        return repository.call {
            client.get<SponsorshipCampaignOptions>(editorialPath("sponsorships/analytics/campaigns", null, if (q.isEmpty()) emptyList() else listOf("q" to q)), repository.token).data
        }
    }
}

/** Il «soggetto» delle statistiche della redazione: tutte le campagne, i giorni nel fuso dell'applicazione. */
internal fun editorialReportSubject(title: String) = ManagementSubject(
    kind = SubjectKind.Venue, id = 0, slug = "", name = title, cityName = null, timezone = ZoneId.of(EDITORIAL_REPORT_ZONE),
    role = "", status = "", logo = null, ticketingEnabled = false, autoPublish = false, permissions = SubjectPermissions(),
)

/* Il fuso in cui `SponsorshipReport` legge i giorni della redazione. */
internal const val EDITORIAL_REPORT_ZONE = "Europe/Rome"

/**
 * La voce «Promozione» del menu: le stesse pagine del gruppo del pannello,
 * nello stesso ordine. Tutta l'area chiede `sponsorships_manage` (admin e
 * super admin); il contatore degli ordini da decidere sta accanto a «Ordini».
 */
@Composable
internal fun PromotionHubScreen(context: EditorialGraphContext) {
    val desk by context.repository.current.collectAsState()
    val can = desk?.can
    ManagementScaffold(title = stringResource(R.string.editorial_area_promotion), padding = context.padding, onBack = context::back, subtitle = stringResource(R.string.editorial_promo_hub_lead)) {
        if (can == null) {
            Text(stringResource(R.string.editorial_promo_loading_desk), color = Muted)
            return@ManagementScaffold
        }
        if (!can.sponsorshipsManage) {
            Text(stringResource(R.string.editorial_promo_not_allowed), color = Muted)
            return@ManagementScaffold
        }
        val orders = desk?.badge("sponsorship_orders_awaiting")
        ManagementRow(stringResource(R.string.editorial_promo_campaigns_title), stringResource(R.string.editorial_promo_campaigns_help), { context.navController.navigate(CampaignListRoute) })
        ManagementRow(stringResource(R.string.editorial_promo_wizard_title), stringResource(R.string.editorial_promo_wizard_help), { context.navController.navigate(CampaignWizardRoute()) })
        ManagementRow(stringResource(R.string.editorial_promo_grants_title), stringResource(R.string.editorial_promo_grants_help), { context.navController.navigate(GrantListRoute) })
        ManagementRow(
            stringResource(R.string.editorial_promo_orders_title),
            if (orders != null && orders > 0) stringResource(R.string.editorial_promo_orders_waiting, orders) else stringResource(R.string.editorial_promo_orders_help),
            { context.navController.navigate(OrderListRoute) },
        )
        ManagementRow(stringResource(R.string.editorial_promo_prices_title), stringResource(R.string.editorial_promo_prices_help), { context.navController.navigate(PriceListRoute) })
        ManagementRow(stringResource(R.string.editorial_promo_banners_title), stringResource(R.string.editorial_promo_banners_help), { context.navController.navigate(BannersRoute) })
        ManagementRow(stringResource(R.string.editorial_promo_analytics_title), stringResource(R.string.editorial_promo_analytics_help), { context.navController.navigate(PromotionAnalyticsRoute()) })
    }
}
