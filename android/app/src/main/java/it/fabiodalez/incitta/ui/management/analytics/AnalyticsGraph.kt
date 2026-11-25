package it.fabiodalez.incitta.ui.management.analytics

import androidx.navigation.NavGraphBuilder
import it.fabiodalez.incitta.ui.management.ManagementGraphContext

/**
 * P5 — Statistiche del soggetto e dei singoli eventi (W-ST-*).
 *
 * Vuoto finché il pacchetto non arriva: le destinazioni si dichiarano qui
 * (`composable<Rotta> { … }`), e `ManagementArea.Analytics.implemented`
 * diventa vero, così l'area compare nel menu. `ManagementNavHost` registra
 * già questa funzione e non va toccato.
 */
@Suppress("UNUSED_PARAMETER", "UnusedReceiverParameter")
internal fun NavGraphBuilder.analyticsGraph(context: ManagementGraphContext) {
}
