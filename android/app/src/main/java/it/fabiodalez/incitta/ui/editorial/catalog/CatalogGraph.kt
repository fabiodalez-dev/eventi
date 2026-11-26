package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import kotlinx.serialization.Serializable

/*
 * R11 — Catalogo (R-CT-01…07): l'ingresso con le voci del gruppo
 * «Contenuti» del pannello, l'elenco di ogni voce e il modulo (nuovo o
 * salvato). Tutto nativo: nessuna funzione del catalogo resta solo sul sito.
 */
@Serializable
internal data object CatalogRoute

/** L'elenco di una voce: `kind` è la chiave del server (`categories`, `event_features`…); `canCreate` arriva dall'ingresso. */
@Serializable
internal data class CatalogListRoute(val kind: String, val canCreate: Boolean = false)

/** Il modulo di una voce: `id` nullo per una voce nuova. */
@Serializable
internal data class CatalogFormRoute(val kind: String, val id: Long? = null)

internal fun NavGraphBuilder.catalogGraph(context: EditorialGraphContext) {
    composable<CatalogRoute> {
        val repository = context.repository
        val viewModel = editorialViewModel { _ -> CatalogHomeViewModel(repository, catalogSource(repository)) }
        CatalogHomeScreen(
            viewModel = viewModel,
            padding = context.padding,
            onBack = context::back,
            onOpen = { section -> section.kind?.let { kind -> context.navController.navigate(CatalogListRoute(kind.key, section.canCreate)) } },
        )
    }
    composable<CatalogListRoute> { entry ->
        val route = entry.toRoute<CatalogListRoute>()
        val kind = CatalogKind.of(route.kind) ?: CatalogKind.Categories
        val repository = context.repository
        val viewModel = editorialViewModel { saved -> CatalogListViewModel(repository, catalogSource(repository), saved, kind) }
        // Tornando dal modulo l'elenco si rilegge con gli stessi criteri.
        val changed by entry.savedStateHandle.getStateFlow(KEY_CHANGED, false).collectAsState()
        LaunchedEffect(changed) {
            if (changed) {
                entry.savedStateHandle[KEY_CHANGED] = false
                viewModel.refresh()
            }
        }
        CatalogListScreen(
            viewModel = viewModel,
            padding = context.padding,
            canCreate = route.canCreate,
            onBack = context::back,
            onOpen = { record -> context.navController.navigate(CatalogFormRoute(kind.key, record.id)) },
            onCreate = { context.navController.navigate(CatalogFormRoute(kind.key)) },
        )
    }
    composable<CatalogFormRoute> { entry ->
        val route = entry.toRoute<CatalogFormRoute>()
        val kind = CatalogKind.of(route.kind) ?: CatalogKind.Categories
        val repository = context.repository
        val viewModel = editorialViewModel { saved -> CatalogFormViewModel(repository, catalogSource(repository), saved, kind, route.id) }
        val desk by repository.current.collectAsState()
        val state by viewModel.state.collectAsState()
        fun markChanged() = context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CHANGED, true)
        LaunchedEffect(state.done) {
            when (val done = state.done) {
                is CatalogDone.Created -> {
                    viewModel.consumeDone()
                    markChanged()
                    context.navController.navigate(CatalogFormRoute(kind.key, done.id)) { popUpTo<CatalogFormRoute> { inclusive = true } }
                }
                CatalogDone.Deleted -> {
                    viewModel.consumeDone()
                    markChanged()
                    context.back()
                }
                null -> Unit
            }
        }
        CatalogFormScreen(
            viewModel = viewModel,
            padding = context.padding,
            admin = desk?.isAdmin == true,
            onBack = {
                markChanged()
                context.back()
            },
            onOpenWeb = { url -> context.openWeb(url) },
        )
    }
}

private const val KEY_CHANGED = "catalog_changed"
