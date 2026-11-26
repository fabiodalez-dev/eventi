package it.fabiodalez.incitta.ui.editorial.community

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.CommunitySection
import it.fabiodalez.incitta.data.editorial.communityDeskApi
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R7 — Community e passaggi (R-CM-01…05, R-CP-01…10, R-CP-12).
 *
 * Il menu dell'area con le due pagine del pannello («Profili e post»,
 * «Passaggi in auto»), i due elenchi a schede e il caso aperto, che nel
 * pannello è la sezione in cima alla pagina dei passaggi. Esportare le
 * evidenze di un caso (R-CP-11) resta sul sito (decisione 8): il caso apre il
 * pannello nel browser.
 */
@Serializable
internal data object CommunityRoute

/**
 * «Profili e post». `section` (`profiles`, `posts`, `comments`,
 * `restrictions`) è la scheda di partenza: finisce nello stato salvato con
 * la stessa chiave che la scheda scelta dalla persona riscrive. Vuota: la
 * prima scheda, come il menu.
 */
@Serializable
internal data class CommunityModerationRoute(val section: String = "")

/**
 * «Modera il soggetto» di una segnalazione (R6): la scheda della community
 * con il tipo di contenuto segnalato. Il pannello apre la pagina Community e
 * basta; l'app la apre già sulla voce giusta.
 */
internal fun communityRouteForReport(subjectType: String): CommunityModerationRoute = CommunityModerationRoute(
    when (subjectType) {
        "community_post" -> CommunitySection.POSTS
        "community_comment" -> CommunitySection.COMMENTS
        else -> CommunitySection.PROFILES
    },
)

@Serializable
internal data object CarpoolDeskRoute

@Serializable
internal data class CarpoolCaseRoute(val id: Long)

/* Il caso scrive nella voce dell'elenco quando cambia: l'elenco, tornando indietro, si rilegge. */
private const val KEY_CASES_CHANGED = "carpool_cases_changed"

internal fun NavGraphBuilder.communityGraph(context: EditorialGraphContext) {
    composable<CommunityRoute> {
        val viewModel = editorialViewModel { CommunityHubViewModel(context.repository) }
        CommunityHubScreen(viewModel, stringResource(EditorialArea.Community.title), context.padding, onBack = context::back, onOpen = { page ->
            context.navController.navigate(
                when (page) {
                    CommunityPage.Community -> CommunityModerationRoute()
                    CommunityPage.Carpool -> CarpoolDeskRoute
                },
            )
        })
    }
    composable<CommunityModerationRoute> {
        val viewModel = editorialViewModel { CommunityModerationViewModel(context.repository, context.repository.communityDeskApi(), it) }
        CommunityModerationScreen(viewModel, context.padding, zone(context), onBack = context::back)
    }
    composable<CarpoolDeskRoute> { entry ->
        val viewModel = editorialViewModel { CarpoolViewModel(context.repository, context.repository.communityDeskApi(), it) }
        RefreshWhenChanged(entry) { viewModel.list.refresh() }
        CarpoolScreen(viewModel, context.padding, zone(context), onBack = context::back, onOpenCase = { id -> context.navController.navigate(CarpoolCaseRoute(id)) })
    }
    composable<CarpoolCaseRoute> {
        val viewModel = editorialViewModel { CarpoolCaseViewModel(context.repository, context.repository.communityDeskApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        LaunchedEffect(state.changed) {
            if (state.changed) context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CASES_CHANGED, true)
        }
        CarpoolCaseScreen(viewModel, context.padding, zone(context), onBack = context::back, onOpenWeb = context::openWeb)
    }
}

/** Le date nel fuso della città di lavoro, come il pannello. */
private fun zone(context: EditorialGraphContext): ZoneId = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")

@Composable
private fun RefreshWhenChanged(entry: NavBackStackEntry, refresh: () -> Unit) {
    val changed by entry.savedStateHandle.getStateFlow(KEY_CASES_CHANGED, false).collectAsStateWithLifecycle()
    LaunchedEffect(changed) {
        if (changed) {
            entry.savedStateHandle[KEY_CASES_CHANGED] = false
            refresh()
        }
    }
}
