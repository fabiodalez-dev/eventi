package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenuesApi
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.EditorialListHandoff
import it.fabiodalez.incitta.ui.editorial.common.EditorialListTarget
import it.fabiodalez.incitta.ui.editorial.common.encodeEditorialFilterParam
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationDetailScreen
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationDetailViewModel
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationListScreen
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationListViewModel
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerFormScreen
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerFormViewModel
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerListScreen
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerListViewModel
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.profile.VenueImagesScreen
import it.fabiodalez.incitta.ui.management.profile.VenueImagesViewModel
import kotlinx.serialization.Serializable

/*
 * R4 — Locali, richieste, organizzatori (R-VN-01…16, R-AP-01…06,
 * R-OR-01…02). `VenuesRoute` è la voce del menu della redazione: porta alle
 * tre parti dell'area, ciascuna mostrata solo a chi la può aprire. Gli id
 * finiscono nello stato salvato della voce (`venueId`, `applicationId`,
 * `organizerId`, `section`), così ogni schermata si ricostruisce anche dopo
 * la chiusura dell'app da parte del sistema.
 */
@Serializable internal data object VenuesRoute
/* `tab` e `filter` (`chiave:valore,…`): l'elenco aperto già filtrato da un riquadro del riepilogo. */
@Serializable internal data class VenueListRoute(val tab: String = "", val filter: String = "")
@Serializable internal data object VenueCreateRoute
@Serializable internal data class VenueDetailRoute(val venueId: Long)
@Serializable internal data class VenueEditRoute(val venueId: Long)
@Serializable internal data class VenueEditorialSectionRoute(val venueId: Long, val section: String)
@Serializable internal data class VenueProfileSectionRoute(val venueId: Long, val section: String)
@Serializable internal data class VenueImagesEditorialRoute(val venueId: Long)
@Serializable internal data class VenueMembersRoute(val venueId: Long)
@Serializable internal data class VenueEventsRoute(val venueId: Long)
@Serializable internal data class VenueActivityRoute(val venueId: Long)
@Serializable internal data class ApplicationListRoute(val tab: String = "", val filter: String = "")
@Serializable internal data class ApplicationDetailRoute(val applicationId: Long)
@Serializable internal data object OrganizerListRoute
/* `organizerId` 0 = nuovo organizzatore. */
@Serializable internal data class OrganizerFormRoute(val organizerId: Long)

/** Il client di R4 per la sessione della redazione. */
internal fun EditorialRepository.venuesApi(): EditorialVenuesSource =
    EditorialVenuesApi(client ?: throw IllegalStateException("Redazione non disponibile"), token)

/**
 * Gli elenchi dell'area che i riquadri del riepilogo aprono già filtrati
 * (`ListHandoff`): locali in attesa e senza eventi recenti, richieste in
 * attesa.
 */
internal fun acceptVenuesLists() {
    EditorialListHandoff.accept(VenueListViewModel.LIST, { it.venuesView || it.venuesEdit }) { target -> VenueListRoute(target.tab.orEmpty(), target.filterParam) }
    EditorialListHandoff.accept(ApplicationListViewModel.LIST, { it.applicationsReview }, ::applicationListRoute)
}

/**
 * Le richieste nel pannello non hanno un filtro di stato: lo stato è la
 * scheda («In attesa», «Approvate», «Rifiutate», «Tutte»). Il riquadro
 * «Richieste in attesa» porta `filter[status]=pending`, che il pannello
 * ignora aprendo la sua scheda predefinita, «In attesa»: qui lo stato
 * diventa la scheda, e gli altri filtri (`kind`, `type`) restano filtri.
 */
internal fun applicationListRoute(target: EditorialListTarget): ApplicationListRoute =
    ApplicationListRoute(
        tab = (target.tab ?: target.filters["status"]).orEmpty(),
        filter = encodeEditorialFilterParam(target.filters - "status"),
    )

internal fun NavGraphBuilder.venuesGraph(context: EditorialGraphContext) {
    acceptVenuesLists()
    composable<VenuesRoute> { VenuesHubScreen(context) }
    composable<VenueListRoute> {
        val model = editorialViewModel { VenueListViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueListScreen(model, context)
    }
    composable<VenueCreateRoute> {
        val model = editorialViewModel { VenueCreateViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueCreateScreen(model, context)
    }
    composable<VenueDetailRoute> {
        val model = editorialViewModel { VenueDetailViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueDetailScreen(model, context)
    }
    composable<VenueEditRoute> {
        val model = editorialViewModel { VenueFormViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueEditScreen(model, context)
    }
    composable<VenueEditorialSectionRoute> {
        val model = editorialViewModel { VenueEditorialSectionViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueEditorialSectionScreen(model, context)
    }
    composable<VenueProfileSectionRoute> {
        val model = editorialViewModel { VenueProfileSectionViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueProfileSectionScreen(model, context)
    }
    composable<VenueImagesEditorialRoute> {
        val repository = context.repository
        val model = editorialViewModel { saved ->
            val media = repository.venuesApi().media()
            VenueImagesViewModel(
                mediaSource = { media },
                galleryLimit = { repository.options()?.limit("gallery_images") ?: GALLERY_MAX },
                saved = saved,
                guard = { block -> repository.call { block() } },
            )
        }
        VenueImagesScreen(model, context.padding, context::back)
    }
    composable<VenueMembersRoute> {
        val model = editorialViewModel { VenueMembersViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueMembersScreen(model, context)
    }
    composable<VenueEventsRoute> {
        val model = editorialViewModel { VenueEventsViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueEventsScreen(model, context)
    }
    composable<VenueActivityRoute> {
        val model = editorialViewModel { VenueActivityViewModel(context.repository, context.repository.venuesApi(), it) }
        VenueActivityScreen(model, context)
    }
    composable<ApplicationListRoute> {
        val model = editorialViewModel { ApplicationListViewModel(context.repository, context.repository.venuesApi(), it) }
        ApplicationListScreen(model, context)
    }
    composable<ApplicationDetailRoute> {
        val model = editorialViewModel { ApplicationDetailViewModel(context.repository, context.repository.venuesApi(), it) }
        ApplicationDetailScreen(model, context)
    }
    composable<OrganizerListRoute> {
        val model = editorialViewModel { OrganizerListViewModel(context.repository, context.repository.venuesApi(), it) }
        OrganizerListScreen(model, context)
    }
    composable<OrganizerFormRoute> {
        val model = editorialViewModel { OrganizerFormViewModel(context.repository, context.repository.venuesApi(), it) }
        OrganizerFormScreen(model, context)
    }
}

/* Il tetto della galleria del sito (`ManagementOptions::GALLERY_MAX`), se il server non lo manda. */
private const val GALLERY_MAX = 30L

/**
 * La voce «Locali» del menu: elenco dei locali, richieste di iscrizione,
 * organizzatori, nuovo locale. Ogni riga c'è solo se i permessi del banco la
 * aprono, come le voci del gruppo «Luoghi» del pannello.
 */
@Composable
internal fun VenuesHubScreen(context: EditorialGraphContext) {
    val desk by context.repository.current.collectAsState()
    val can = desk?.can
    ManagementScaffold(title = stringResource(R.string.editorial_area_venues), padding = context.padding, onBack = context::back, subtitle = stringResource(R.string.editorial_venues_hub_lead)) {
        if (can == null) {
            Text(stringResource(R.string.editorial_venues_loading_desk), color = Muted)
            return@ManagementScaffold
        }
        if (can.venuesView || can.venuesEdit) {
            ManagementRow(stringResource(R.string.editorial_venues_list_title), badgeText(desk?.badge("venues_pending"), R.string.editorial_venues_list_help), { context.navController.navigate(VenueListRoute()) })
        }
        if (can.applicationsReview) {
            ManagementRow(stringResource(R.string.editorial_applications_title), badgeText(desk?.badge("venue_applications_pending"), R.string.editorial_applications_help), { context.navController.navigate(ApplicationListRoute()) })
        }
        if (can.organizersManage) {
            ManagementRow(stringResource(R.string.editorial_organizers_title), stringResource(R.string.editorial_organizers_help), { context.navController.navigate(OrganizerListRoute) })
        }
        if (can.venuesEdit) {
            ManagementRow(stringResource(R.string.editorial_venue_create_title), stringResource(R.string.editorial_venue_create_help), { context.navController.navigate(VenueCreateRoute) })
        }
    }
}

@Composable
private fun badgeText(count: Int?, help: Int): String =
    if (count != null && count > 0) stringResource(R.string.editorial_venues_pending_count, stringResource(help), count) else stringResource(help)
