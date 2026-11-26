package it.fabiodalez.incitta.ui.management.registration

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.toRoute
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.management.ApplicationDrafts
import it.fabiodalez.incitta.data.management.ManagementApplicationsApi
import it.fabiodalez.incitta.data.management.ManagementApplicationsSource
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.RegistrationForm
import it.fabiodalez.incitta.ui.management.managementViewModel

/*
 * Q2 (W-AP-02) — i tre moduli per chi non ha ancora una sessione.
 *
 * Sono le stesse schermate e gli stessi ViewModel della gestione, con
 * l'API senza token (solo le letture pubbliche del modulo) e
 * `GuestSignUp`. Da qui non parte nessuna richiesta: l'ospite crea
 * l'account o sceglie «Ho già un account», e quando arriva la sessione
 * `InCittaApp` riapre il modulo nella gestione, con la bozza del telefono.
 * «Le mie richieste» non si apre da ospite: chi la chiede prima accede.
 */

/** La prima schermata dell'ospite per un collegamento o un pulsante; `null` per «Le mie richieste», che vuole l'accesso. */
internal fun guestRouteFor(destination: ManagementDestination.Registration): Any? = when (destination.form) {
    RegistrationForm.Applications -> null
    else -> registrationRouteFor(destination)
}

@Composable
internal fun GuestRegistrationScreen(
    destination: ManagementDestination.Registration,
    padding: PaddingValues,
    signUp: GuestSignUp,
    onSignIn: (ManagementDestination.Registration) -> Unit,
    onBack: () -> Unit,
    client: ApiClient? = null,
) {
    val start = remember(destination) { guestRouteFor(destination) }
    if (start == null) { LaunchedEffect(destination) { onSignIn(destination) }; return }
    val context = LocalContext.current
    val api: ManagementApplicationsSource = remember(client) { ManagementApplicationsApi(client ?: ApiClient(LocalStore(context.applicationContext).installationId), null) }
    val drafts = remember { ApplicationDrafts.of(context) }
    val navController = rememberNavController()
    val exit by rememberUpdatedState(onBack)
    val signIn by rememberUpdatedState(onSignIn)
    val entry by navController.currentBackStackEntryAsState()
    BackHandler(enabled = entry != null && navController.previousBackStackEntry == null) { exit() }
    val back = { if (!navController.popBackStack()) exit() }
    NavHost(navController = navController, startDestination = start) {
        composable<RegisterVenueRoute> {
            val viewModel = managementViewModel { RegisterVenueViewModel(api, it, "", drafts, signUp) }
            val chrome = RegistrationChrome(padding, null, exit) { signIn(ManagementDestination.Registration(RegistrationForm.Venue)) }
            RegisterVenueScreen(viewModel, chrome, onBack = back, onOpen = { guestOpen(navController, it, signIn) })
        }
        composable<ClaimVenueRoute> { backStack ->
            val slug = backStack.toRoute<ClaimVenueRoute>().slug
            val viewModel = managementViewModel { ClaimVenueViewModel(api, it, slug, "", drafts, signUp) }
            val chrome = RegistrationChrome(padding, null, exit) { signIn(ManagementDestination.Registration(RegistrationForm.Claim, slug)) }
            ClaimVenueScreen(viewModel, chrome, onBack = back, onOpen = { guestOpen(navController, it, signIn) })
        }
        composable<RegisterOrganizerRoute> {
            val viewModel = managementViewModel { RegisterOrganizerViewModel(api, it, "", drafts, signUp) }
            val chrome = RegistrationChrome(padding, null, exit) { signIn(ManagementDestination.Registration(RegistrationForm.Organizer)) }
            RegisterOrganizerScreen(viewModel, chrome, onBack = back, onOpen = { guestOpen(navController, it, signIn) })
        }
    }
}

/* Da ospite «Rivendica» dal catalogo apre il modulo della rivendicazione; «Le mie richieste» vuole l'accesso. */
private fun guestOpen(navController: NavHostController, link: RegistrationLink, signIn: (ManagementDestination.Registration) -> Unit) {
    when (link) {
        RegistrationLink.NewVenue -> navController.navigate(RegisterVenueRoute())
        RegistrationLink.NewOrganizer -> navController.navigate(RegisterOrganizerRoute())
        is RegistrationLink.Claim -> navController.navigate(ClaimVenueRoute(link.slug))
        RegistrationLink.Applications, RegistrationLink.SentFromDraft -> signIn(ManagementDestination.Registration(RegistrationForm.Applications))
    }
}
