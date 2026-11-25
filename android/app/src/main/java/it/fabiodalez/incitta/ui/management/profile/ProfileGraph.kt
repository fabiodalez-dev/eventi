package it.fabiodalez.incitta.ui.management.profile

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P4 — Profilo del locale e dell'organizzazione (W-PR-01…12, W-PB-03, W-PB-04).
 *
 * `ProfileRoute` è la voce del menu: porta alla scheda del locale o al
 * profilo dell'organizzazione aperti. Gli id finiscono nello stato salvato
 * della voce (`venueId`, `organizerId`, `section`), così ogni schermata si
 * ricostruisce anche dopo la chiusura dell'app da parte del sistema.
 */
@Serializable internal data object ProfileRoute
@Serializable internal data class VenueProfileRoute(val venueId: Long)
@Serializable internal data class VenueSectionRoute(val venueId: Long, val section: String)
@Serializable internal data class VenueImagesRoute(val venueId: Long)
@Serializable internal data class VenueNoticeRoute(val venueId: Long)
@Serializable internal data class VenueToolsRoute(val venueId: Long)
@Serializable internal data class OrganizerProfileRoute(val organizerId: Long)

internal fun NavGraphBuilder.profileGraph(context: ManagementGraphContext) {
    composable<ProfileRoute> { ProfileEntryScreen(context) }
    composable<VenueProfileRoute> {
        val viewModel = managementViewModel { VenueProfileViewModel(context.repository, it) }
        VenueProfileIndexScreen(viewModel, context)
    }
    composable<VenueSectionRoute> {
        val viewModel = managementViewModel { VenueSectionViewModel(context.repository, it) }
        VenueSectionScreen(viewModel, context)
    }
    composable<VenueImagesRoute> {
        val viewModel = managementViewModel { VenueImagesViewModel(context.repository, it) }
        VenueImagesScreen(viewModel, context)
    }
    composable<VenueNoticeRoute> {
        val viewModel = managementViewModel { VenueNoticeViewModel(context.repository, it) }
        VenueNoticeScreen(viewModel, context)
    }
    composable<VenueToolsRoute> {
        val viewModel = managementViewModel { VenueProfileViewModel(context.repository, it) }
        VenueToolsScreen(viewModel, context)
    }
    composable<OrganizerProfileRoute> {
        val viewModel = managementViewModel { OrganizerProfileViewModel(context.repository, it) }
        OrganizerProfileScreen(viewModel, context)
    }
}
