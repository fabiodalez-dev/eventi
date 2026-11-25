package it.fabiodalez.incitta.ui.management.dates

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.managementViewModel
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateRoute
import kotlinx.serialization.Serializable

/* Le rotte delle date: sempre sotto un evento. `dateId` 0 è «nuova data». */
@Serializable internal data class EventDatesRoute(val eventId: Long)
@Serializable internal data class DateEditorRoute(val eventId: Long, val dateId: Long = 0L)

/**
 * P2 — Date e ricorrenze (W-DT-*, W-EV-17): l'elenco delle date di un
 * evento e il modulo della data, nelle due varianti dei pannelli. Si arriva
 * dalla scheda dell'evento, come nei pannelli, dove le date stanno dentro
 * l'evento: l'area non ha una voce sua nel menu.
 */
internal fun NavGraphBuilder.datesGraph(context: ManagementGraphContext) {
    composable<EventDatesRoute> {
        val viewModel = managementViewModel { EventDatesViewModel(context.repository, it) }
        EventDatesScreen(viewModel, context,
            onAdd = { context.navController.navigate(DateEditorRoute(viewModel.eventId)) },
            onEdit = { id -> context.navController.navigate(DateEditorRoute(viewModel.eventId, id)) },
            onTickets = { id -> context.navController.navigate(TicketingDateRoute(id)) })
    }
    composable<DateEditorRoute> {
        val viewModel = managementViewModel { DateEditorViewModel(context.repository, it) }
        DateEditorScreen(viewModel, context, onDone = { message ->
            (message as? UiText.Res)?.let { context.navController.previousBackStackEntry?.savedStateHandle?.set(EventDatesViewModel.KEY_MESSAGE, it.id) }
            context.back()
        })
    }
}
