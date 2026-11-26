package it.fabiodalez.incitta.ui.editorial.analytics

import androidx.compose.ui.res.stringResource
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.EditorialReportQuery
import it.fabiodalez.incitta.data.editorial.analyticsApi
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import kotlinx.serialization.Serializable

/*
 * R10 — Statistiche, SEO, ricerche (R-ST-01…06), le voci del gruppo
 * «Analisi» del pannello della redazione:
 *
 * - `AnalyticsRoute`: il menu dell'area, con le voci che il ruolo vede;
 * - `AnalyticsReportRoute`: «Statistiche in dettaglio» di tutti gli eventi
 *   (senza `type`) oppure la pagina di un evento, un locale o un
 *   organizzatore;
 * - `AnalyticsTableRoute`: una tabella, con CSV ed Excel;
 * - `SeoRoute`: «SEO e qualità del catalogo»;
 * - `SearchMissesRoute`: «Ricerche senza risultati».
 *
 * Le rotte portano i filtri: finiscono nello stato salvato della voce, così
 * la schermata rinasce uguale. Le date sono `AAAA-MM-GG`; 0 vuol dire «tutti».
 */
@Serializable
internal data object AnalyticsRoute

@Serializable
internal data class AnalyticsReportRoute(
    val type: String? = null,
    val id: Long = 0,
    val period: String = "month",
    val from: String? = null,
    val until: String? = null,
    val venue: Long = 0,
    val organizer: Long = 0,
    val event: Long = 0,
    val channel: String? = null,
    val venueLabel: String? = null,
    val organizerLabel: String? = null,
    val eventLabel: String? = null,
)

@Serializable
internal data class AnalyticsTableRoute(
    val type: String? = null,
    val id: Long = 0,
    val dataset: String = "events",
    val period: String = "month",
    val from: String? = null,
    val until: String? = null,
    val venue: Long = 0,
    val organizer: Long = 0,
    val event: Long = 0,
    val channel: String? = null,
)

@Serializable
internal data object SeoRoute

@Serializable
internal data object SearchMissesRoute

/** La pagina di un soggetto con periodo e canale di adesso, come `detailUrl` del pannello. */
internal fun subjectReportRoute(type: String, id: Long, query: EditorialReportQuery) = AnalyticsReportRoute(
    type = type, id = id, period = query.period, from = query.from, until = query.until, channel = query.channel,
)

/** «Torna alle statistiche di tutti gli eventi»: periodo e canale restano, il soggetto no (`overviewUrl`). */
internal fun overviewReportRoute(query: EditorialReportQuery) = AnalyticsReportRoute(period = query.period, from = query.from, until = query.until, channel = query.channel)

internal fun tableReportRoute(type: String?, id: Long?, dataset: String, query: EditorialReportQuery) = AnalyticsTableRoute(
    type = type, id = id ?: 0, dataset = dataset, period = query.period, from = query.from, until = query.until,
    venue = query.venue ?: 0, organizer = query.organizer ?: 0, event = query.event ?: 0, channel = query.channel,
)

internal fun NavGraphBuilder.analyticsGraph(context: EditorialGraphContext) {
    val navigate: (Any) -> Unit = { route -> context.navController.navigate(route) }
    composable<AnalyticsRoute> {
        val viewModel = editorialViewModel { AnalyticsHubViewModel(context.repository) }
        AnalyticsHubScreen(viewModel, stringResource(EditorialArea.Analytics.title), context.padding, onBack = context::back, onOpen = { section ->
            navigate(
                when (section) {
                    AnalyticsSection.Report -> AnalyticsReportRoute()
                    AnalyticsSection.Seo -> SeoRoute
                    AnalyticsSection.SearchMisses -> SearchMissesRoute
                },
            )
        })
    }
    composable<AnalyticsReportRoute> {
        val viewModel = editorialViewModel { EditorialReportViewModel(context.repository, context.repository.analyticsApi(), it) }
        EditorialReportScreen(
            viewModel = viewModel,
            padding = context.padding,
            onBack = context::back,
            onOpen = { link, query -> navigate(subjectReportRoute(link.type, link.id, query)) },
            onReplace = { id, query ->
                // «Guarda un altro evento» prende il posto della pagina: indietro si torna da dove si era partiti.
                context.navController.navigate(subjectReportRoute("event", id, query)) { popUpTo<AnalyticsReportRoute> { inclusive = true } }
            },
            onOverview = { query -> navigate(overviewReportRoute(query)) },
            onTable = { dataset, query ->
                val state = viewModel.uiState.value
                navigate(tableReportRoute(state.target?.type, state.target?.id, dataset, query))
            },
        )
    }
    composable<AnalyticsTableRoute> {
        val viewModel = editorialViewModel { EditorialTableViewModel(context.repository, context.repository.analyticsApi(), it) }
        EditorialTableScreen(viewModel, context.padding, onBack = context::back, onOpen = { link, query -> navigate(subjectReportRoute(link.type, link.id, query)) })
    }
    composable<SeoRoute> {
        val viewModel = editorialViewModel { SeoViewModel(context.repository, context.repository.analyticsApi(), it) }
        SeoScreen(viewModel, context.padding, onBack = context::back)
    }
    composable<SearchMissesRoute> {
        val viewModel = editorialViewModel { SearchMissesViewModel(context.repository, context.repository.analyticsApi(), it) }
        SearchMissesScreen(viewModel, context.padding, onBack = context::back)
    }
}
