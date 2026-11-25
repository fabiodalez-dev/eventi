package it.fabiodalez.incitta.ui.management.calendarimport

import androidx.navigation.NavGraphBuilder
import it.fabiodalez.incitta.ui.management.ManagementGraphContext

/**
 * P9 — Import del calendario (W-IM-*). Il pacchetto si chiama `calendarimport` perché `import` è una parola riservata di Kotlin.
 *
 * Vuoto finché il pacchetto non arriva: le destinazioni si dichiarano qui
 * (`composable<Rotta> { … }`), e `ManagementArea.Import.implemented`
 * diventa vero, così l'area compare nel menu. `ManagementNavHost` registra
 * già questa funzione e non va toccato.
 */
@Suppress("UNUSED_PARAMETER", "UnusedReceiverParameter")
internal fun NavGraphBuilder.calendarImportGraph(context: ManagementGraphContext) {
}
