package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialComingSoonScreen
import kotlinx.serialization.Serializable

/*
 * R3 — Modulo evento e date.
 *
 * Il grafo del pacchetto, già registrato in `EditorialNavHost`: chi porta
 * l'area sostituisce la schermata «in arrivo» con le sue destinazioni qui
 * dentro, e mette `native = true` sulla sua riga di `EditorialArea`.
 */
@Serializable
internal data object EventEditorRoute

internal fun NavGraphBuilder.eventEditorGraph(context: EditorialGraphContext) {
    composable<EventEditorRoute> { EditorialComingSoonScreen(EditorialArea.EventEditor, context) }
}
