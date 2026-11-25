package it.fabiodalez.incitta.ui.management

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.*
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementLanding
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.ui.management.analytics.analyticsGraph
import it.fabiodalez.incitta.ui.management.calendarimport.calendarImportGraph
import it.fabiodalez.incitta.ui.management.collaborators.InvitationsRoute
import it.fabiodalez.incitta.ui.management.collaborators.collaboratorsGraph
import it.fabiodalez.incitta.ui.management.dates.datesGraph
import it.fabiodalez.incitta.ui.management.events.eventsGraph
import it.fabiodalez.incitta.ui.management.feedback.feedbackGraph
import it.fabiodalez.incitta.ui.management.feedback.feedbackRouteFor
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import it.fabiodalez.incitta.ui.management.home.homeGraph
import it.fabiodalez.incitta.ui.management.notifications.notificationsGraph
import it.fabiodalez.incitta.ui.management.overview.overviewGraph
import it.fabiodalez.incitta.ui.management.profile.profileGraph
import it.fabiodalez.incitta.ui.management.registration.registrationGraph
import it.fabiodalez.incitta.ui.management.social.socialGraph
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipsGraph
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipsRouteFor
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateRoute
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDatesRoute
import it.fabiodalez.incitta.ui.management.ticketing.ticketingGraph

/**
 * Quello che ogni area riceve per dichiarare le sue destinazioni: la
 * navigazione, il repository della sessione, il margine dello `Scaffold`
 * dell'app e l'uscita dall'area. È lo stesso oggetto per tutta la vita del
 * `NavHost`; il margine e l'uscita si leggono sempre aggiornati.
 */
internal class ManagementGraphContext(
    val navController: NavHostController,
    val repository: ManagementRepository,
    val session: Session,
    private val paddingState: State<PaddingValues>,
    private val exitState: State<() -> Unit>,
) {
    val padding: PaddingValues get() = paddingState.value
    fun exit() = exitState.value()
    /** Indietro dentro l'area; dalla prima schermata si esce dalla gestione. */
    fun back() { if (!navController.popBackStack()) exit() }
    /** Vero sulla prima schermata della pila: il titolo resta «Gestione eventi». */
    val atRoot: Boolean get() = navController.previousBackStackEntry == null
}

/**
 * La navigazione della sola area di gestione. Ogni pacchetto dichiara le sue
 * destinazioni nel suo file (`fun NavGraphBuilder.<area>Graph`): sono già
 * tutte registrate qui, così chi lavora su un'area non tocca questo file.
 */
@Composable
internal fun ManagementNavHost(
    repository: ManagementRepository,
    session: Session,
    padding: PaddingValues,
    landing: ManagementLanding,
    destination: ManagementDestination?,
    onExit: () -> Unit,
) {
    val navController = rememberNavController()
    val paddingState = rememberUpdatedState(padding)
    val exitState = rememberUpdatedState(onExit)
    val context = remember(navController, repository) { ManagementGraphContext(navController, repository, session, paddingState, exitState) }
    val start: Any = when (landing) {
        is ManagementLanding.Subject -> ManagementHomeRoute
        ManagementLanding.TicketingDates -> TicketingDatesRoute
    }
    val entry by navController.currentBackStackEntryAsState()
    BackHandler(enabled = entry != null && navController.previousBackStackEntry == null) { onExit() }
    NavHost(navController = navController, startDestination = start) {
        homeGraph(context)
        overviewGraph(context)
        eventsGraph(context)
        datesGraph(context)
        ticketingGraph(context)
        profileGraph(context)
        analyticsGraph(context)
        sponsorshipsGraph(context)
        feedbackGraph(context)
        collaboratorsGraph(context)
        calendarImportGraph(context)
        socialGraph(context)
        notificationsGraph(context)
        registrationGraph(context)
    }
    /*
     * Un avviso o un collegamento che porta a una schermata precisa: una volta
     * sola, anche dopo una rotazione. La chiave è la destinazione, così un
     * secondo collegamento con la gestione già aperta (il ritorno da Stripe)
     * porta anche lui alla sua schermata.
     */
    var handled by androidx.compose.runtime.saveable.rememberSaveable(destination) { mutableStateOf(false) }
    LaunchedEffect(destination) {
        if (handled || destination == null) return@LaunchedEffect
        handled = true
        when (destination) {
            is ManagementDestination.TicketingDate -> navController.navigate(TicketingDateRoute(destination.occurrenceId))
            ManagementDestination.TicketingDates -> if (start != TicketingDatesRoute) navController.navigate(TicketingDatesRoute)
            is ManagementDestination.Feedback -> navController.navigate(feedbackRouteFor(destination))
            ManagementDestination.Invitations -> navController.navigate(InvitationsRoute(fromOutside = true))
            is ManagementDestination.SponsorshipCheckout -> navController.navigate(sponsorshipsRouteFor(destination)) {
                popUpTo<SponsorshipsRoute> { inclusive = true }
            }
        }
    }
}

/** Il ViewModel di una destinazione, con lo stato salvato della voce di navigazione (id, filtri). */
@Composable
internal inline fun <reified VM : ViewModel> managementViewModel(crossinline create: (SavedStateHandle) -> VM): VM =
    viewModel(factory = viewModelFactory { initializer { create(createSavedStateHandle()) } })
