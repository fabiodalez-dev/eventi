package it.fabiodalez.incitta.ui.editorial.system

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.data.editorial.systemApi
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialListHandoff
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R12 — Sistema: import, invii, cron (R-SY-01…07).
 *
 * Il menu dell'area con le tre voci del gruppo «Sistema» del pannello che
 * questo pacchetto porta (sorgenti di import, notifiche programmate, cron e
 * programmazioni); le altre voci del gruppo (testi delle email, posta,
 * newsletter, script di consenso, cookie, redirect, cache) sono di R13.
 * Le sorgenti hanno elenco, scheda, modulo (nuova e modifica), anteprima e
 * storico; gli invii si fermano dall'elenco, come nel pannello.
 */
@Serializable
internal data object SystemRoute

@Serializable
internal data class SystemImportsRoute(val tab: String = "", val filter: String = "")

@Serializable
internal data class SystemImportRoute(val id: Long)

/* `id` 0: una sorgente nuova. */
@Serializable
internal data class SystemImportFormRoute(val id: Long = 0)

@Serializable
internal data class SystemImportPreviewRoute(val id: Long)

@Serializable
internal data class SystemImportRunsRoute(val id: Long)

@Serializable
internal data class SystemNotificationsRoute(val tab: String = "", val filter: String = "")

@Serializable
internal data object SystemOperationsRoute

/* Chi cambia una sorgente lo scrive nella voce di chi l'ha aperto: l'elenco o la scheda si ricaricano. */
private const val KEY_IMPORTS_CHANGED = "system_imports_changed"

/**
 * Gli elenchi che un riquadro del riepilogo apre già filtrati
 * (`ListHandoff`): oggi «Import falliti» (`import_sources`, `failed:1`).
 */
internal fun acceptSystemLists() {
    EditorialListHandoff.accept(ImportSourcesViewModel.LIST, { it.importsManage }) { target -> SystemImportsRoute(filter = target.filterParam) }
    EditorialListHandoff.accept(ScheduledNotificationsViewModel.LIST, { it.notificationsView }) { target -> SystemNotificationsRoute(filter = target.filterParam) }
}

internal fun NavGraphBuilder.systemGraph(context: EditorialGraphContext) {
    acceptSystemLists()
    composable<SystemRoute> {
        val viewModel = editorialViewModel { SystemHubViewModel(context.repository) }
        SystemHubScreen(viewModel, stringResource(EditorialArea.System.title), context.padding, onBack = context::back, onOpen = { section ->
            context.navController.navigate(
                when (section) {
                    SystemSection.Imports -> SystemImportsRoute()
                    SystemSection.Notifications -> SystemNotificationsRoute()
                    SystemSection.Operations -> SystemOperationsRoute
                },
            )
        })
    }
    composable<SystemImportsRoute> { entry ->
        val viewModel = editorialViewModel { ImportSourcesViewModel(context.repository, context.repository.systemApi(), it) }
        val desk by context.repository.current.collectAsStateWithLifecycle()
        RefreshWhenChanged(entry) { viewModel.list.refresh() }
        ImportSourcesScreen(viewModel, context.padding, zone(context), canCreate = desk?.can?.importsManage == true, onBack = context::back,
            onOpen = { id -> context.navController.navigate(SystemImportRoute(id)) },
            onCreate = { context.navController.navigate(SystemImportFormRoute()) })
    }
    composable<SystemImportRoute> { entry ->
        val viewModel = editorialViewModel { ImportSourceDetailViewModel(context.repository, context.repository.systemApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        RefreshWhenChanged(entry) { viewModel.reloadAfterEdit() }
        LaunchedEffect(state.changed, state.deleted) {
            if (state.changed) context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_IMPORTS_CHANGED, true)
            if (state.deleted) context.back()
        }
        val id = entry.toRoute<SystemImportRoute>().id
        ImportSourceDetailScreen(viewModel, context.padding, zone(context), onBack = context::back,
            onEdit = { context.navController.navigate(SystemImportFormRoute(id)) },
            onPreview = { context.navController.navigate(SystemImportPreviewRoute(id)) },
            onRuns = { context.navController.navigate(SystemImportRunsRoute(id)) },
            onWeb = context::openWeb)
    }
    composable<SystemImportFormRoute> { entry ->
        val editing = entry.toRoute<SystemImportFormRoute>().id > 0
        val viewModel = editorialViewModel { ImportSourceFormViewModel(context.repository, context.repository.systemApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        LaunchedEffect(state.saved) {
            val saved = state.saved ?: return@LaunchedEffect
            context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_IMPORTS_CHANGED, true)
            if (editing) {
                /* Dalla scheda: si torna lì, che si ricarica. */
                context.back()
            } else {
                /* Una sorgente nuova: dal modulo alla sua scheda, senza tornare al modulo con «indietro». */
                context.navController.navigate(SystemImportRoute(saved.id)) { popUpTo<SystemImportFormRoute> { inclusive = true } }
            }
        }
        ImportSourceFormScreen(viewModel, context.padding, onBack = context::back)
    }
    composable<SystemImportPreviewRoute> {
        val viewModel = editorialViewModel { ImportPreviewViewModel(context.repository, context.repository.systemApi(), it) }
        ImportPreviewScreen(viewModel, context.padding, onBack = context::back)
    }
    composable<SystemImportRunsRoute> {
        val viewModel = editorialViewModel { ImportRunsViewModel(context.repository, context.repository.systemApi(), it) }
        ImportRunsScreen(viewModel, context.padding, zone(context), onBack = context::back)
    }
    composable<SystemNotificationsRoute> {
        val viewModel = editorialViewModel { ScheduledNotificationsViewModel(context.repository, context.repository.systemApi(), it) }
        ScheduledNotificationsScreen(viewModel, context.padding, zone(context), onBack = context::back)
    }
    composable<SystemOperationsRoute> {
        val viewModel = editorialViewModel { ScheduledOperationsViewModel(context.repository, context.repository.systemApi()) }
        val desk by context.repository.current.collectAsStateWithLifecycle()
        /* «Social → Impostazioni» del pannello: l'area Social, se la persona la apre. */
        val social = desk?.can?.let { EditorialArea.Social.allowedFor(it) } == true
        ScheduledOperationsScreen(viewModel, context.padding, zone(context), onBack = context::back,
            onSocial = if (social) ({ context.open(EditorialArea.Social) }) else null)
    }
}

/** Le date nel fuso della città di lavoro, come le colonne del pannello. */
private fun zone(context: EditorialGraphContext): ZoneId = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")

@Composable
private fun RefreshWhenChanged(entry: NavBackStackEntry, refresh: () -> Unit) {
    val changed by entry.savedStateHandle.getStateFlow(KEY_IMPORTS_CHANGED, false).collectAsStateWithLifecycle()
    LaunchedEffect(changed) {
        if (changed) {
            entry.savedStateHandle[KEY_IMPORTS_CHANGED] = false
            refresh()
        }
    }
}
