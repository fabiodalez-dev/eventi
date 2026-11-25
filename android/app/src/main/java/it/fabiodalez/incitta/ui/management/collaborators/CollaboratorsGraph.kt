package it.fabiodalez.incitta.ui.management.collaborators

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import it.fabiodalez.incitta.ui.management.managementViewModel
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateRoute
import kotlinx.serialization.Serializable

/*
 * P8 — Collaboratori, accesso e inviti (W-CO-01…07, W-TK-15).
 *
 * `CollaboratorsRoute` è l'ingresso dell'area: il referente vi trova
 * l'elenco, l'invito e le azioni sulle righe; ogni persona del locale la voce
 * «Il tuo accesso». `InvitationsRoute` è degli inviti ricevuti e non dipende
 * dal locale aperto: ci si arriva dal profilo e dai collegamenti d'invito
 * aperti nell'app (`ManagementDestination.Invitations`), e allora «indietro»
 * esce dalla gestione.
 */
@Serializable internal data object CollaboratorsRoute
@Serializable internal data object MyAccessRoute
@Serializable internal data class InvitationsRoute(val fromOutside: Boolean = false)

internal fun NavGraphBuilder.collaboratorsGraph(context: ManagementGraphContext) {
    composable<CollaboratorsRoute> {
        val viewModel = managementViewModel { CollaboratorsViewModel(context.repository, it) }
        CollaboratorsScreen(viewModel, context, onOpenAccess = { context.navController.navigate(MyAccessRoute) })
    }
    composable<MyAccessRoute> {
        val viewModel = managementViewModel { MyAccessViewModel(context.repository, it) }
        MyAccessScreen(
            viewModel,
            context,
            onOpenCollaborators = { if (!context.navController.popBackStack(CollaboratorsRoute, inclusive = false)) context.navController.navigate(CollaboratorsRoute) },
            onLeft = { remaining -> if (remaining > 0) restartAtHome(context) else context.exit() },
        )
    }
    composable<InvitationsRoute> { entry ->
        val fromOutside = entry.toRoute<InvitationsRoute>().fromOutside
        val viewModel = managementViewModel { InvitationsViewModel(context.repository) }
        InvitationsScreen(
            viewModel,
            context,
            onBack = { if (fromOutside) context.exit() else context.back() },
            onOutcome = { outcome ->
                when (outcome) {
                    is InvitationOutcome.Venue -> restartAtHome(context)
                    is InvitationOutcome.CheckinDate -> context.navController.navigate(TicketingDateRoute(outcome.occurrenceId)) {
                        popUpTo<InvitationsRoute> { inclusive = true }
                    }
                }
            },
        )
    }
}

/**
 * La pagina del soggetto, da capo: dopo aver lasciato un locale o essere
 * entrati in uno nuovo, la pila di prima parla di un locale che non è più
 * quello giusto. Il repository ha già l'elenco riletto e la scelta.
 */
private fun restartAtHome(context: ManagementGraphContext) {
    val navController = context.navController
    navController.navigate(ManagementHomeRoute) {
        popUpTo(navController.graph.id) { inclusive = true }
    }
}
