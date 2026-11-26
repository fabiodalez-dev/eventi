package it.fabiodalez.incitta.ui.editorial.calendar

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.calendarApi
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.editorial.events.EditorialEventRoute
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R14 — Calendario e campanella (R-EV-27, R-DB-11).
 *
 * - `CalendarRoute`, la voce del menu (e la scorciatoia «Calendario» della
 *   prima pagina): il mese con le date di tutte le città, come la pagina
 *   «Calendario» del pannello; toccare una data apre la scheda nativa
 *   dell'evento (R2), dove il pannello apre la modifica;
 * - `EditorialBellRoute`: la campanella, dall'icona della prima pagina.
 */
@Serializable
internal data object CalendarRoute

@Serializable
internal data object EditorialBellRoute

internal fun NavGraphBuilder.calendarGraph(context: EditorialGraphContext) {
    composable<CalendarRoute> {
        val viewModel = editorialViewModel { EditorialCalendarViewModel(context.repository, context.repository.calendarApi(), it) }
        EditorialCalendarScreen(viewModel, context.padding, onBack = context::back, onOpenEvent = { id -> context.navController.navigate(EditorialEventRoute(id)) })
    }
    composable<EditorialBellRoute> {
        val viewModel = editorialViewModel { EditorialBellViewModel(context.repository, context.repository.calendarApi()) }
        val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
        EditorialBellScreen(viewModel, context.padding, zone, onBack = context::back, onOpenUrl = { url -> context.openPanel(url) })
    }
}
