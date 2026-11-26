package it.fabiodalez.incitta.ui.editorial.moderation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialComingSoonScreen
import kotlinx.serialization.Serializable

/*
 * R6 — Moderazione dei contenuti.
 *
 * Il grafo del pacchetto, già registrato in `EditorialNavHost`: chi porta
 * l'area sostituisce la schermata «in arrivo» con le sue destinazioni qui
 * dentro, e mette `native = true` sulla sua riga di `EditorialArea`.
 */
@Serializable
internal data object ModerationRoute

internal fun NavGraphBuilder.moderationGraph(context: EditorialGraphContext) {
    composable<ModerationRoute> { EditorialComingSoonScreen(EditorialArea.Moderation, context) }
}
