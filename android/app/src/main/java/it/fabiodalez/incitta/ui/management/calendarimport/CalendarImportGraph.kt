package it.fabiodalez.incitta.ui.management.calendarimport

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P9 — Import del calendario (W-IM-01…07). Il pacchetto si chiama
 * `calendarimport` perché `import` è una parola riservata di Kotlin.
 *
 * Una schermata sola, come la pagina «Calendario collegato» del pannello:
 * stato, modulo, anteprima e azioni. Solo il locale, e solo il referente
 * (`import_manage`), come dice `ManagementArea.Import.allowedFor`.
 */
@Serializable internal data object CalendarImportRoute

internal fun NavGraphBuilder.calendarImportGraph(context: ManagementGraphContext) {
    composable<CalendarImportRoute> {
        val viewModel = managementViewModel { CalendarImportViewModel(context.repository, it) }
        CalendarImportScreen(viewModel, context)
    }
}
