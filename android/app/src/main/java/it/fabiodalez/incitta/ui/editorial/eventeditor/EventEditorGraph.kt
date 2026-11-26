package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormRoute
import it.fabiodalez.incitta.ui.editorial.catalog.catalogPickedKey
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import kotlinx.serialization.Serializable

/*
 * R3 — Modulo evento e date (R-EV-05, 06, 18…24, 26).
 *
 * `EventEditorRoute` è l'ingresso dell'area («Nuovo evento»); la modifica,
 * le date e il modulo di una data hanno le loro rotte. Gli id finiscono
 * nello stato salvato della voce (`id`, `eventId`, `dateId`).
 */
@Serializable
internal data object EventEditorRoute

/** Il modulo evento: `id` 0 per un evento nuovo. */
@Serializable internal data class EditorialEventEditorRoute(val id: Long = 0)
@Serializable internal data class EditorialEventDatesRoute(val eventId: Long)
/** Il modulo di una data: `dateId` 0 per una data nuova. */
@Serializable internal data class EditorialDateEditorRoute(val eventId: Long, val dateId: Long = 0)

internal fun NavGraphBuilder.eventEditorGraph(context: EditorialGraphContext) {
    val nav = context.navController
    composable<EventEditorRoute> { entry -> EditorDestination(context, entry) }
    composable<EditorialEventEditorRoute> { entry -> EditorDestination(context, entry) }
    composable<EditorialEventDatesRoute> { entry ->
        val viewModel = editorialViewModel { EventDatesViewModel(context.repository, it) }
        EventDatesScreen(
            viewModel = viewModel,
            padding = context.padding,
            onBack = context::back,
            onAdd = { nav.navigate(EditorialDateEditorRoute(viewModel.eventId)) },
            onEdit = { id -> nav.navigate(EditorialDateEditorRoute(viewModel.eventId, id)) },
            onOpenInApp = context::openInApp,
        )
        // Tornando dal modulo di una data l'elenco si rilegge.
        val dateSaved by entry.savedStateHandle.getStateFlow(DATE_SAVED, false).collectAsStateWithLifecycle()
        LaunchedEffect(dateSaved) {
            if (dateSaved) { entry.savedStateHandle[DATE_SAVED] = false; viewModel.load() }
        }
    }
    composable<EditorialDateEditorRoute> {
        val viewModel = editorialViewModel { DateEditorViewModel(context.repository, it) }
        DateEditorScreen(viewModel, context.padding, context::back) {
            nav.previousBackStackEntry?.savedStateHandle?.set(DATE_SAVED, true)
            context.back()
        }
    }
}

private const val DATE_SAVED = "editorial_date_saved"

@androidx.compose.runtime.Composable
private fun EditorDestination(context: EditorialGraphContext, entry: NavBackStackEntry) {
    val viewModel = editorialViewModel { EventEditorViewModel(context.repository, it) }
    val desk by context.repository.current.collectAsStateWithLifecycle()
    // Tornando da «Crea caratteristica» la voce nuova si sceglie subito, come nel modale del pannello.
    val pickedKey = catalogPickedKey(CatalogKind.Features)
    val picked by entry.savedStateHandle.getStateFlow(pickedKey, 0L).collectAsStateWithLifecycle()
    LaunchedEffect(picked) {
        if (picked > 0) {
            entry.savedStateHandle[pickedKey] = 0L
            viewModel.featureCreated(picked)
        }
    }
    EventEditorScreen(
        viewModel = viewModel,
        padding = context.padding,
        onBack = context::back,
        onCreated = {},
        links = EditorLinks(
            onDates = { id -> context.navController.navigate(EditorialEventDatesRoute(id)) },
            onOpenInApp = context::openInApp,
            // «Anteprima grafica social» resta sul sito finché lo studio social (R9) non è nell'app.
            onSocial = if (desk?.can?.socialUse == true) { { id: Long -> context.openWeb("admin/social?event=$id") } } else null,
            onCreateFeature = if (EditorialArea.Catalog.native) { { context.navController.navigate(CatalogFormRoute(CatalogKind.Features.key, pick = true)) } } else null,
        ),
    )
}
