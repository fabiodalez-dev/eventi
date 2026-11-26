package it.fabiodalez.incitta.ui.editorial.events

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialComingSoonScreen
import kotlinx.serialization.Serializable

/*
 * R2 — Eventi: elenco, moderazione, proposte.
 *
 * Il grafo del pacchetto, già registrato in `EditorialNavHost`: chi porta
 * l'area sostituisce la schermata «in arrivo» con le sue destinazioni qui
 * dentro, e mette `native = true` sulla sua riga di `EditorialArea`.
 */
@Serializable
internal data object EventsRoute

internal fun NavGraphBuilder.eventsGraph(context: EditorialGraphContext) {
    composable<EventsRoute> { EditorialComingSoonScreen(EditorialArea.Events, context) }
}
