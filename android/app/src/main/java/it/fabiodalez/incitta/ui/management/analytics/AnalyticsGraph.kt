package it.fabiodalez.incitta.ui.management.analytics

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.management.ReportQuery
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P5 — Statistiche del soggetto e dei singoli eventi (W-ST-01…07, W-DT-08,
 * W-PB-05), come il gruppo «Statistiche» dei pannelli:
 *
 * - `AnalyticsRoute`: il menu dell'area;
 * - `StatisticsRoute`: «Statistiche»;
 * - `ShareAnalyticsRoute`: «Statistiche in dettaglio», con i filtri;
 * - `SubjectAnalyticsRoute`: la pagina di un evento, un locale o un
 *   organizzatore (per il proprio locale è la prima pagina del pannello);
 * - `AnalyticsTableRoute`: una tabella di «Tutti i dati»;
 * - `AnalyticsChannelsRoute`: i canali propri del locale e i loro QR.
 *
 * Le rotte portano il soggetto per chiave (`venue:12`) e i filtri: finiscono
 * nello stato salvato della voce, così la schermata rinasce uguale. Le date
 * sono `AAAA-MM-GG`; `event` 0 vuol dire «tutti gli eventi».
 */
@Serializable internal data class AnalyticsRoute(val subject: String = "")
@Serializable internal data class StatisticsRoute(val subject: String = "")
@Serializable internal data class ShareAnalyticsRoute(
    val subject: String = "",
    val period: String = "month",
    val from: String? = null,
    val until: String? = null,
    val event: Long = 0,
    val channel: String? = null,
)
@Serializable internal data class SubjectAnalyticsRoute(
    val subject: String = "",
    val type: String = "event",
    val id: Long = 0,
    val period: String = "month",
    val from: String? = null,
    val until: String? = null,
    val channel: String? = null,
)
@Serializable internal data class AnalyticsTableRoute(
    val subject: String = "",
    val type: String = "",
    val id: Long = 0,
    val dataset: String = "events",
    val period: String = "month",
    val from: String? = null,
    val until: String? = null,
    val event: Long = 0,
    val channel: String? = null,
)
@Serializable internal data class AnalyticsChannelsRoute(val subject: String = "")

/** La pagina di un soggetto con il periodo e il canale di adesso, come «Guarda un altro evento» e i link del pannello. */
internal fun subjectRoute(subject: String, type: String, id: Long, query: ReportQuery) = SubjectAnalyticsRoute(
    subject = subject, type = type, id = id, period = query.period, from = query.from, until = query.until, channel = query.channel,
)

/** «Torna alle statistiche di tutti gli eventi»: periodo e canale restano, il soggetto no. */
internal fun overviewRoute(subject: String, query: ReportQuery) = ShareAnalyticsRoute(
    subject = subject, period = query.period, from = query.from, until = query.until, channel = query.channel,
)

internal fun tableRoute(subject: String, type: String?, id: Long?, dataset: String, query: ReportQuery) = AnalyticsTableRoute(
    subject = subject, type = type.orEmpty(), id = id ?: 0, dataset = dataset,
    period = query.period, from = query.from, until = query.until, event = query.event ?: 0, channel = query.channel,
)

internal fun NavGraphBuilder.analyticsGraph(context: ManagementGraphContext) {
    val navigate: (Any) -> Unit = { route -> context.navController.navigate(route) }
    composable<AnalyticsRoute> {
        val viewModel = managementViewModel { AnalyticsHubViewModel(context.repository, it) }
        AnalyticsHubScreen(viewModel, context, navigate)
    }
    composable<StatisticsRoute> {
        val viewModel = managementViewModel { StatisticsViewModel(context.repository, it) }
        StatisticsScreen(viewModel, context, navigate)
    }
    composable<ShareAnalyticsRoute> {
        val viewModel = managementViewModel { ReportViewModel(context.repository, it) }
        ReportScreen(viewModel, context, navigate)
    }
    composable<SubjectAnalyticsRoute> {
        val viewModel = managementViewModel { ReportViewModel(context.repository, it) }
        ReportScreen(viewModel, context, navigate, replace = { route ->
            // «Guarda un altro evento» prende il posto della pagina: indietro si torna da dove si era partiti.
            context.navController.navigate(route) { popUpTo<SubjectAnalyticsRoute> { inclusive = true } }
        })
    }
    composable<AnalyticsTableRoute> {
        val viewModel = managementViewModel { AnalyticsTableViewModel(context.repository, it) }
        AnalyticsTableScreen(viewModel, context, navigate)
    }
    composable<AnalyticsChannelsRoute> {
        val viewModel = managementViewModel { ChannelsViewModel(context.repository, it) }
        ChannelsScreen(viewModel, context)
    }
}
