package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P6 — Sponsorizzazioni: la pagina con abilitazioni, ordini e campagne, la
 * scelta in quattro passi con il pagamento nel browser, la scheda di una
 * campagna e le statistiche (W-SP-01…13, W-ST-08, W-ST-09).
 *
 * Le rotte portano il soggetto per chiave (`venue:12`); vuota vale il
 * soggetto scelto nella gestione. `event` arriva dalla scheda di un evento
 * (l'evento già scelto, come `?event=` del pannello); `order` e `cancelled`
 * dal ritorno di Stripe o dall'invio appena fatto, e chiedono al server com'è
 * andato il pagamento; `sent` dice che una richiesta è appena partita.
 */
@Serializable internal data class SponsorshipsRoute(
    val subject: String = "",
    val event: Long = 0,
    val order: Long = 0,
    val cancelled: Boolean = false,
    val sent: Boolean = false,
)
@Serializable internal data class SponsorshipWizardRoute(val subject: String = "", val event: Long = 0)
@Serializable internal data class SponsorshipCampaignRoute(val subject: String = "", val id: Long = 0)
@Serializable internal data class SponsorshipAnalyticsRoute(val subject: String = "", val campaign: Long = 0)

/** La pagina delle sponsorizzazioni per il ritorno da Stripe. */
internal fun sponsorshipsRouteFor(destination: ManagementDestination.SponsorshipCheckout): SponsorshipsRoute =
    SponsorshipsRoute(subject = destination.result.subjectKey, order = destination.result.orderId, cancelled = destination.result.cancelled)

internal fun NavGraphBuilder.sponsorshipsGraph(context: ManagementGraphContext) {
    composable<SponsorshipsRoute> {
        val viewModel = managementViewModel { SponsorshipsViewModel(context.repository, it) }
        SponsorshipsScreen(viewModel, context,
            onWizard = { key, event -> context.navController.navigate(SponsorshipWizardRoute(subject = key, event = event)) },
            onCampaign = { key, id -> context.navController.navigate(SponsorshipCampaignRoute(subject = key, id = id)) },
            onAnalytics = { key -> context.navController.navigate(SponsorshipAnalyticsRoute(subject = key)) })
    }
    composable<SponsorshipWizardRoute> {
        val viewModel = managementViewModel { SponsorshipWizardViewModel(context.repository, it) }
        SponsorshipWizardScreen(viewModel, context, onDone = { key, order, sent ->
            /* Si torna alla pagina, nuova: con l'ordine da controllare al ritorno dal browser, o con «Richiesta inviata». */
            context.navController.navigate(SponsorshipsRoute(subject = key, order = order, sent = sent)) {
                popUpTo<SponsorshipsRoute> { inclusive = true }
            }
        })
    }
    composable<SponsorshipCampaignRoute> {
        val viewModel = managementViewModel { SponsorshipCampaignViewModel(context.repository, it) }
        SponsorshipCampaignScreen(viewModel, context, onAnalytics = { key, id -> context.navController.navigate(SponsorshipAnalyticsRoute(subject = key, campaign = id)) })
    }
    composable<SponsorshipAnalyticsRoute> {
        val viewModel = managementViewModel { SponsorshipAnalyticsViewModel(context.repository, it) }
        SponsorshipAnalyticsScreen(viewModel, context)
    }
}
