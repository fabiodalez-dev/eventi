package it.fabiodalez.incitta.ui.management.registration

import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.data.management.ApplicationDrafts
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.RegistrationForm
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P13 — Registrare o rivendicare un locale o un'organizzazione (W-AP-01…06).
 *
 * `ApplicationsRoute` («Le mie richieste») è l'ingresso dell'area; i tre
 * moduli si aprono da lì, dalla home dell'app («Organizzi eventi?»), dalla
 * scheda di un locale rivendicabile e dai collegamenti del sito aperti
 * nell'app (`ManagementDestination.Registration`). Da fuori, «indietro»
 * esce dalla gestione e torna dove si era.
 *
 * Qui da collegati. L'ospite apre gli stessi tre moduli fuori dalla
 * gestione (`GuestRegistrationScreen`, Q2); quando arriva la sessione l'app
 * riapre qui il modulo, con la bozza del telefono, e se la sessione nasce
 * dalla registrazione chiesta dal modulo la richiesta parte da sola.
 */
@Serializable internal data class ApplicationsRoute(val fromOutside: Boolean = false)
@Serializable internal data class RegisterVenueRoute(val fromOutside: Boolean = false)
@Serializable internal data class ClaimVenueRoute(val slug: String, val fromOutside: Boolean = false)
@Serializable internal data class RegisterOrganizerRoute(val fromOutside: Boolean = false)

/** La schermata per un collegamento o una voce fuori dalla gestione. */
internal fun registrationRouteFor(destination: ManagementDestination.Registration): Any = when (destination.form) {
    RegistrationForm.Applications -> ApplicationsRoute(fromOutside = true)
    RegistrationForm.Venue -> RegisterVenueRoute(fromOutside = true)
    RegistrationForm.Organizer -> RegisterOrganizerRoute(fromOutside = true)
    RegistrationForm.Claim -> destination.slug?.takeIf { it.isNotBlank() }?.let { ClaimVenueRoute(it, fromOutside = true) } ?: RegisterVenueRoute(fromOutside = true)
}

internal fun NavGraphBuilder.registrationGraph(context: ManagementGraphContext) {
    val contactName = { context.session.user.name.orEmpty() }
    val chrome = { RegistrationChrome(context.padding, context.session.user.email, context::exit) }
    composable<ApplicationsRoute> { entry ->
        val fromOutside = entry.toRoute<ApplicationsRoute>().fromOutside
        val viewModel = managementViewModel { ApplicationsViewModel(context.repository) }
        ApplicationsScreen(
            viewModel,
            chrome(),
            onBack = { if (fromOutside) context.exit() else context.back() },
            onOpen = { open(context, it) },
            onOpenSubject = { openSubject(context) },
        )
    }
    composable<RegisterVenueRoute> { entry ->
        val fromOutside = entry.toRoute<RegisterVenueRoute>().fromOutside
        val drafts = ApplicationDrafts.of(LocalContext.current)
        val viewModel = managementViewModel { RegisterVenueViewModel(context.repository.applications, it, contactName(), drafts) }
        RegisterVenueScreen(viewModel, chrome(), onBack = { if (fromOutside) context.exit() else context.back() }, onOpen = { open(context, it) })
    }
    composable<ClaimVenueRoute> { entry ->
        val route = entry.toRoute<ClaimVenueRoute>()
        val drafts = ApplicationDrafts.of(LocalContext.current)
        val viewModel = managementViewModel { ClaimVenueViewModel(context.repository.applications, it, route.slug, contactName(), drafts) }
        ClaimVenueScreen(viewModel, chrome(), onBack = { if (route.fromOutside) context.exit() else context.back() }, onOpen = { open(context, it) })
    }
    composable<RegisterOrganizerRoute> { entry ->
        val fromOutside = entry.toRoute<RegisterOrganizerRoute>().fromOutside
        val drafts = ApplicationDrafts.of(LocalContext.current)
        val viewModel = managementViewModel { RegisterOrganizerViewModel(context.repository.applications, it, contactName(), drafts) }
        RegisterOrganizerScreen(viewModel, chrome(), onBack = { if (fromOutside) context.exit() else context.back() }, onOpen = { open(context, it) })
    }
}

/* «Le mie richieste» dalla fine di un modulo: se è già nella pila ci si torna, altrimenti si apre. */
private fun open(context: ManagementGraphContext, link: RegistrationLink) {
    val navController = context.navController
    when (link) {
        RegistrationLink.NewVenue -> navController.navigate(RegisterVenueRoute())
        RegistrationLink.NewOrganizer -> navController.navigate(RegisterOrganizerRoute())
        is RegistrationLink.Claim -> navController.navigate(ClaimVenueRoute(link.slug))
        RegistrationLink.Applications -> if (!navController.popBackStack<ApplicationsRoute>(inclusive = false)) navController.navigate(ApplicationsRoute())
        /* La bozza è partita da sola: «Le mie richieste» prende il posto del modulo, e «indietro» esce dalla gestione. */
        RegistrationLink.SentFromDraft -> navController.navigate(ApplicationsRoute(fromOutside = true)) {
            navController.currentDestination?.id?.let { current -> popUpTo(current) { inclusive = true } }
        }
    }
}

/**
 * «Apri il pannello» di una richiesta accolta: la pagina del soggetto, da
 * capo. Il repository ha già la scelta e l'elenco riletto.
 */
private fun openSubject(context: ManagementGraphContext) {
    val navController = context.navController
    navController.navigate(ManagementHomeRoute) {
        popUpTo(navController.graph.id) { inclusive = true }
    }
}
