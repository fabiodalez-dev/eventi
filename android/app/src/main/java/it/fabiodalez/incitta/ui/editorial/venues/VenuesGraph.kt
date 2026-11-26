package it.fabiodalez.incitta.ui.editorial.venues

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialComingSoonScreen
import kotlinx.serialization.Serializable

/*
 * R4 — Locali, richieste, organizzatori.
 *
 * Il grafo del pacchetto, già registrato in `EditorialNavHost`: chi porta
 * l'area sostituisce la schermata «in arrivo» con le sue destinazioni qui
 * dentro, e mette `native = true` sulla sua riga di `EditorialArea`.
 */
@Serializable
internal data object VenuesRoute

internal fun NavGraphBuilder.venuesGraph(context: EditorialGraphContext) {
    composable<VenuesRoute> { EditorialComingSoonScreen(EditorialArea.Venues, context) }
}
