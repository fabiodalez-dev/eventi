package it.fabiodalez.incitta.ui.management.notifications

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P12 — Notifiche per chi gestisce e chiusura del giro dei collegamenti
 * (W-NT-01, 03, 04, 05, W-CM-05, W-ST-10).
 *
 * `ManagementNoticesRoute` è l'area «Notifiche» del soggetto: gli avvisi
 * ricevuti per quel locale o quell'organizzazione, che si aprono nella
 * schermata nativa, e l'interruttore del rapporto mensile. La traduzione degli
 * indirizzi del pannello e degli avvisi sta in `ManagementLinks.kt`. La
 * rotta porta il soggetto per chiave (`venue:12`); vuota vale quello scelto.
 */
@Serializable internal data class ManagementNoticesRoute(val subject: String = "")

internal fun NavGraphBuilder.notificationsGraph(context: ManagementGraphContext) {
    composable<ManagementNoticesRoute> {
        val viewModel = managementViewModel { ManagementNoticesViewModel(context.repository, it) }
        ManagementNoticesScreen(viewModel, context)
    }
}
