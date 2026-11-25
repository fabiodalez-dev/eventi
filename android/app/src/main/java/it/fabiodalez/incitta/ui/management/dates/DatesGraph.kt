package it.fabiodalez.incitta.ui.management.dates

import androidx.navigation.NavGraphBuilder
import it.fabiodalez.incitta.ui.management.ManagementGraphContext

/**
 * P2 — Date e ricorrenze: orari, stati, note di stato, date ripetute (W-DT-*).
 *
 * Vuoto finché il pacchetto non arriva: le destinazioni si dichiarano qui
 * (`composable<Rotta> { … }`), e `ManagementArea.Dates.implemented`
 * diventa vero, così l'area compare nel menu. `ManagementNavHost` registra
 * già questa funzione e non va toccato.
 */
@Suppress("UNUSED_PARAMETER", "UnusedReceiverParameter")
internal fun NavGraphBuilder.datesGraph(context: ManagementGraphContext) {
}
