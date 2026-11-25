package it.fabiodalez.incitta.ui.management.overview

import androidx.navigation.NavGraphBuilder
import it.fabiodalez.incitta.ui.management.ManagementGraphContext

/**
 * P11 — Riepilogo: oggi, prossimi eventi, ultime visite e scorciatoie (W-DB-*).
 *
 * Vuoto finché il pacchetto non arriva: le destinazioni si dichiarano qui
 * (`composable<Rotta> { … }`), e `ManagementArea.Overview.implemented`
 * diventa vero, così l'area compare nel menu. `ManagementNavHost` registra
 * già questa funzione e non va toccato.
 */
@Suppress("UNUSED_PARAMETER", "UnusedReceiverParameter")
internal fun NavGraphBuilder.overviewGraph(context: ManagementGraphContext) {
}
