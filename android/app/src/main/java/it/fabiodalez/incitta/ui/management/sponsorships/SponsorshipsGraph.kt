package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.navigation.NavGraphBuilder
import it.fabiodalez.incitta.ui.management.ManagementGraphContext

/**
 * P6 — Sponsorizzazioni: richiesta, stato, rapporti (W-SP-*).
 *
 * Vuoto finché il pacchetto non arriva: le destinazioni si dichiarano qui
 * (`composable<Rotta> { … }`), e `ManagementArea.Sponsorships.implemented`
 * diventa vero, così l'area compare nel menu. `ManagementNavHost` registra
 * già questa funzione e non va toccato.
 */
@Suppress("UNUSED_PARAMETER", "UnusedReceiverParameter")
internal fun NavGraphBuilder.sponsorshipsGraph(context: ManagementGraphContext) {
}
