package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.DashboardApi
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialTodayRoute
import kotlinx.serialization.Serializable

/*
 * R1 — Riepilogo e code (R-DB-01…09; i contatori del menu, R-DB-13, li dà
 * già il banco nella prima pagina).
 *
 * Una destinazione sola: il riepilogo della città di lavoro, sezione per
 * sezione come la pagina iniziale del pannello. Un riquadro apre l'elenco
 * già filtrato: nativo se l'area lo sa ricevere (`EditorialListHandoff`),
 * altrimenti la stessa pagina del pannello nel browser.
 */
@Serializable
internal data object DashboardRoute

internal fun NavGraphBuilder.dashboardGraph(context: EditorialGraphContext) {
    composable<DashboardRoute> {
        val repository = context.repository
        val model = editorialViewModel {
            DashboardViewModel(repository, DashboardApi(requireNotNull(repository.client) { "client della sessione mancante" }, repository.token))
        }
        val state by model.state.collectAsStateWithLifecycle()
        val busy by model.busy.collectAsStateWithLifecycle()
        val desk by repository.current.collectAsStateWithLifecycle()
        DashboardScreen(
            state = state,
            desk = desk,
            busy = busy,
            padding = context.padding,
            onBack = context::back,
            onRetry = model::load,
            onRefresh = model::load,
            onCity = model::selectCity,
            onTarget = { target ->
                when (val destination = dashboardDestination(target, desk?.can)) {
                    is DashboardDestination.Native -> context.navController.navigate(destination.route)
                    /* Un elenco che l'area non sa ricevere filtrato: la pagina del pannello, nell'app se c'è (R14). */
                    is DashboardDestination.Web -> context.openPanel(destination.path)
                    null -> Unit
                }
            },
            /* `admin/users/{id}/edit`: la scheda nativa della persona (R5). */
            onRegistration = { row -> row.webPath?.let { context.openPanel(it) } },
            onSocialToday = if (desk?.can?.socialUse == true) { { context.navController.navigate(EditorialSocialTodayRoute) } } else null,
        )
    }
}
