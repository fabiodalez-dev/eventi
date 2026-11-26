package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialComingSoonScreen
import kotlinx.serialization.Serializable

/*
 * R1 — Riepilogo e code (R-DB-01…09, 13).
 *
 * Il grafo del pacchetto, già registrato in `EditorialNavHost`: chi porta
 * l'area sostituisce la schermata «in arrivo» con le sue destinazioni qui
 * dentro, e mette `native = true` sulla sua riga di `EditorialArea`.
 */
@Serializable
internal data object DashboardRoute

internal fun NavGraphBuilder.dashboardGraph(context: EditorialGraphContext) {
    composable<DashboardRoute> { EditorialComingSoonScreen(EditorialArea.Dashboard, context) }
}
