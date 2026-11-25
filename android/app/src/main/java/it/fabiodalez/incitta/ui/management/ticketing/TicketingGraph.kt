package it.fabiodalez.incitta.ui.management.ticketing

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/* Le rotte della biglietteria. L'id della data e della campagna finisce nello stato salvato della voce (`id`). */
@Serializable internal data object TicketingDatesRoute
@Serializable internal data class TicketingDateRoute(val id: Long)
@Serializable internal data class CampaignRoute(val id: Long)

/** Biglietteria: elenco delle date e delle campagne, scheda della data, scheda della campagna. */
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
}
