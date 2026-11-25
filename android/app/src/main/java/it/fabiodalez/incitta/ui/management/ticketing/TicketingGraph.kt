package it.fabiodalez.incitta.ui.management.ticketing

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/* Le rotte della biglietteria. Gli id finiscono nello stato salvato della voce (`id`, `venueId`, `eventId`). */
@Serializable internal data object TicketingDatesRoute
/** La scheda di una data; `tab` apre una scheda precisa (`settings` per «Configura ticketing» dall'evento). */
@Serializable internal data class TicketingDateRoute(val id: Long, val tab: String? = null)
@Serializable internal data class CampaignRoute(val id: Long)
/** Il riquadro «Ticketing inCittà» di un evento del locale: lo apre la modifica dell'evento (P1). */
@Serializable internal data class EventTicketingRoute(val venueId: Long, val eventId: Long)

/** Biglietteria: elenco delle date e delle campagne, scheda della data, scheda della campagna, riquadro dell'evento. */
internal fun NavGraphBuilder.ticketingGraph(context: ManagementGraphContext) {
    composable<TicketingDatesRoute> {
        val viewModel = managementViewModel { TicketingDatesViewModel(context.repository, it) }
        TicketingDatesScreen(viewModel, context,
            onOpenDate = { id -> context.navController.navigate(TicketingDateRoute(id)) },
            onOpenCampaign = { id -> context.navController.navigate(CampaignRoute(id)) })
    }
    composable<TicketingDateRoute> {
        val viewModel = managementViewModel { TicketingDateViewModel(context.repository, it) }
        TicketingDateScreen(viewModel, context)
    }
    composable<CampaignRoute> {
        val viewModel = managementViewModel { CampaignViewModel(context.repository, it) }
        CampaignScreen(viewModel, context)
    }
    composable<EventTicketingRoute> {
        val viewModel = managementViewModel { EventTicketingViewModel(context.repository, it) }
        EventTicketingScreen(viewModel, context, onOpenDate = { id -> context.navController.navigate(TicketingDateRoute(id, DateTab.Settings.key)) })
    }
}
