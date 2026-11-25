package it.fabiodalez.incitta.ui.management.events

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import it.fabiodalez.incitta.ui.management.ticketing.EventTicketingRoute
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateRoute
import kotlinx.serialization.Serializable

/* Le rotte degli eventi. L'id dell'evento finisce nello stato salvato della voce (`id`); 0 è «nuovo evento». */
@Serializable internal data object EventsListRoute
@Serializable internal data class EventOverviewRoute(val id: Long)
@Serializable internal data class EventEditorRoute(val id: Long = 0L)
@Serializable internal data class EventPreviewRoute(val id: Long)

/**
 * P1 — Eventi: elenco con le schede Tutti, Prossimi, Passati e Bozze, scheda
 * in lettura con le azioni, modulo di creazione e modifica (locale e
 * organizzazione), anteprima della pagina pubblica (W-EV-*).
 */
internal fun NavGraphBuilder.eventsGraph(context: ManagementGraphContext) {
    composable<EventsListRoute> {
        val viewModel = managementViewModel { EventsListViewModel(context.repository, it) }
        EventsListScreen(viewModel, context,
            onOpen = { id -> context.navController.navigate(EventOverviewRoute(id)) },
            onCreate = { context.navController.navigate(EventEditorRoute()) })
    }
    composable<EventOverviewRoute> {
        val viewModel = managementViewModel { EventOverviewViewModel(context.repository, it) }
        EventOverviewScreen(viewModel, context,
            onEdit = { id -> context.navController.navigate(EventEditorRoute(id)) },
            onPreview = { id -> context.navController.navigate(EventPreviewRoute(id)) },
            onTickets = { occurrenceId -> context.navController.navigate(TicketingDateRoute(occurrenceId)) },
            onEventTicketing = { venueId, eventId -> context.navController.navigate(EventTicketingRoute(venueId, eventId)) },
            onOpenCopy = { id ->
                context.navController.navigate(EventEditorRoute(id)) { popUpTo<EventsListRoute>() }
            })
    }
    composable<EventEditorRoute> {
        val viewModel = managementViewModel { EventEditorViewModel(context.repository, it) }
        EventEditorScreen(viewModel, context, onFinished = { id ->
            context.navController.navigate(EventOverviewRoute(id)) { popUpTo<EventsListRoute>() }
        })
    }
    composable<EventPreviewRoute> {
        val viewModel = managementViewModel { EventPreviewViewModel(context.repository, it) }
        EventPreviewScreen(viewModel, context)
    }
}
