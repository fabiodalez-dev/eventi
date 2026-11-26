package it.fabiodalez.incitta.ui.editorial.moderation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.moderationApi
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R6 — Moderazione dei contenuti (R-MD-01…12).
 *
 * Il menu dell'area con le tre voci del gruppo «Moderazione» del pannello
 * (segnalazioni, recensioni, commenti agli eventi), gli elenchi e il
 * dettaglio di una segnalazione, che nel pannello è la pagina «Modifica».
 * Recensioni e commenti si decidono dall'elenco, come nel pannello, dove le
 * azioni sono riquadri sopra la tabella.
 */
@Serializable
internal data object ModerationRoute

@Serializable
internal data object ModerationReportsRoute

@Serializable
internal data class ModerationReportRoute(val id: Long)

@Serializable
internal data object ModerationReviewsRoute

@Serializable
internal data object ModerationCommentsRoute

/* Il dettaglio di una segnalazione lo scrive nella voce dell'elenco quando salva o elimina: l'elenco si ricarica. */
private const val KEY_REPORTS_CHANGED = "moderation_reports_changed"

internal fun NavGraphBuilder.moderationGraph(context: EditorialGraphContext) {
    composable<ModerationRoute> {
        val viewModel = editorialViewModel { ModerationHubViewModel(context.repository) }
        ModerationHubScreen(viewModel, stringResource(EditorialArea.Moderation.title), context.padding, onBack = context::back, onOpen = { section ->
            context.navController.navigate(
                when (section) {
                    ModerationSection.Reports -> ModerationReportsRoute
                    ModerationSection.Reviews -> ModerationReviewsRoute
                    ModerationSection.Comments -> ModerationCommentsRoute
                },
            )
        })
    }
    composable<ModerationReportsRoute> { entry ->
        val viewModel = editorialViewModel { ReportsViewModel(context.repository, context.repository.moderationApi(), it) }
        RefreshWhenChanged(entry) { viewModel.list.refresh() }
        ReportsScreen(viewModel, context.padding, zone(context), onBack = context::back, onOpen = { id -> context.navController.navigate(ModerationReportRoute(id)) })
    }
    composable<ModerationReportRoute> {
        val viewModel = editorialViewModel { ReportDetailViewModel(context.repository, context.repository.moderationApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        LaunchedEffect(state.changed, state.deleted) {
            if (state.changed) context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_REPORTS_CHANGED, true)
            if (state.deleted) context.back()
        }
        ReportDetailScreen(viewModel, context.padding, zone(context), onBack = context::back, onCommunity = { context.open(EditorialArea.Community) })
    }
    composable<ModerationReviewsRoute> {
        val viewModel = editorialViewModel { ReviewsViewModel(context.repository, context.repository.moderationApi(), it) }
        ReviewsScreen(viewModel, context.padding, zone(context), onBack = context::back)
    }
    composable<ModerationCommentsRoute> {
        val viewModel = editorialViewModel { CommentsViewModel(context.repository, context.repository.moderationApi(), it) }
        CommentsScreen(viewModel, context.padding, zone(context), onBack = context::back)
    }
}

/** Le date nel fuso della città di lavoro, come `panelMoment()` del pannello. */
private fun zone(context: EditorialGraphContext): ZoneId = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")

@Composable
private fun RefreshWhenChanged(entry: NavBackStackEntry, refresh: () -> Unit) {
    val changed by entry.savedStateHandle.getStateFlow(KEY_REPORTS_CHANGED, false).collectAsStateWithLifecycle()
    LaunchedEffect(changed) {
        if (changed) {
            entry.savedStateHandle[KEY_REPORTS_CHANGED] = false
            refresh()
        }
    }
}
