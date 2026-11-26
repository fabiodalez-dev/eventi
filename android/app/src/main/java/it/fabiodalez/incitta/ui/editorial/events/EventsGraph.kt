package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R2 — Eventi: elenco, moderazione, proposte (R-EV-01…04, 07…17, 25,
 * R-SB-01…05, R-WB-01).
 *
 * `EventsRoute` è l'ingresso dell'area (dal menu della redazione).
 * `EditorialEventsListRoute` apre lo stesso elenco già filtrato: `tab` e
 * `filter` (`chiave:valore,chiave:valore`, per esempio
 * `missing_poster:1`), per i riquadri del riepilogo. La scheda, la
 * cronologia, l'anteprima e le proposte hanno le loro rotte; l'id finisce
 * nello stato salvato (`id`).
 */
@Serializable
internal data object EventsRoute

@Serializable internal data class EditorialEventsListRoute(val tab: String = "", val filter: String = "")

@Serializable internal data class EditorialEventRoute(val id: Long, val merge: Boolean = false)
@Serializable internal data class EditorialEventActivityRoute(val id: Long, val timezone: String = "Europe/Rome")
@Serializable internal data class EditorialEventPreviewRoute(val id: Long)
@Serializable internal data class EditorialSubmissionsRoute(val status: String = "")
@Serializable internal data class EditorialSubmissionRoute(val id: Long)

internal fun NavGraphBuilder.eventsGraph(context: EditorialGraphContext) {
    val nav = context.navController
    composable<EventsRoute> { EventsQueueDestination(context) }
    composable<EditorialEventsListRoute> { EventsQueueDestination(context) }
    composable<EditorialEventRoute> { entry ->
        val route = entry.toRoute<EditorialEventRoute>()
        val viewModel = editorialViewModel { EventRecordViewModel(context.repository, it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        val zone = ((state as? Loadable.Ready)?.value?.city?.timezone) ?: "Europe/Rome"
        EventRecordScreen(
            viewModel = viewModel,
            padding = context.padding,
            startWithMerge = route.merge,
            onBack = context::back,
            onOpenEvent = { id -> nav.navigate(EditorialEventRoute(id)) },
            onPreview = { id -> nav.navigate(EditorialEventPreviewRoute(id)) },
            onActivity = { id -> nav.navigate(EditorialEventActivityRoute(id, zone)) },
            onOpenWeb = context::openWeb,
        )
    }
    composable<EditorialEventActivityRoute> { entry ->
        val route = entry.toRoute<EditorialEventActivityRoute>()
        val viewModel = editorialViewModel { EventActivityViewModel(context.repository, it) }
        ActivityScreen(viewModel, context.padding, runCatching { ZoneId.of(route.timezone) }.getOrDefault(ZoneId.of("Europe/Rome")), context::back)
    }
    composable<EditorialEventPreviewRoute> {
        val viewModel = editorialViewModel { EventPreviewViewModel(context.repository, it) }
        EventPreviewScreen(viewModel, context.padding, context::back)
    }
    composable<EditorialSubmissionsRoute> {
        val viewModel = editorialViewModel { SubmissionsViewModel(context.repository, it) }
        val statuses by produceState(emptyList<OptionChoice>()) { value = context.repository.options()?.choices("submission_statuses").orEmpty() }
        SubmissionsScreen(viewModel, context.padding, statuses, context::back) { id -> nav.navigate(EditorialSubmissionRoute(id)) }
    }
    composable<EditorialSubmissionRoute> {
        val viewModel = editorialViewModel { SubmissionReviewViewModel(context.repository, it) }
        SubmissionReviewScreen(viewModel, context.padding, context::back) { id -> nav.navigate(EditorialEventRoute(id)) }
    }
}

@androidx.compose.runtime.Composable
private fun EventsQueueDestination(context: EditorialGraphContext) {
    val nav = context.navController
    val viewModel = editorialViewModel { EventsQueueViewModel(context.repository, it) }
    val desk by context.repository.current.collectAsStateWithLifecycle()
    EventsQueueScreen(
        viewModel = viewModel,
        padding = context.padding,
        // Le proposte le vede chi modera gli eventi (`EventSubmissionPolicy`).
        showSubmissions = desk?.can?.eventsModerate == true,
        onBack = context::back,
        onOpen = { id -> nav.navigate(EditorialEventRoute(id)) },
        onMerge = { id -> nav.navigate(EditorialEventRoute(id, merge = true)) },
        onSubmissions = { nav.navigate(EditorialSubmissionsRoute()) },
    )
}
