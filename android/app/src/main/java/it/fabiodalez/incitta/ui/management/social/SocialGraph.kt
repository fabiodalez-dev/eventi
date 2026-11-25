package it.fabiodalez.incitta.ui.management.social

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P10 — Grafiche social (W-SO-01…09).
 *
 * Lo studio, come la pagina «Social» del pannello del locale, e un pacchetto
 * preparato. Le rotte portano il locale per chiave (`venue:12`); vuota vale
 * il locale scelto nella gestione. `event` arriva dalla scheda di un evento
 * (`?event=` del pannello): lo studio si restringe a quell'evento e si apre
 * sulla sua prossima data.
 */
@Serializable internal data class SocialStudioRoute(val subject: String = "", val event: Long = 0)
@Serializable internal data class SocialBatchRoute(val subject: String = "", val id: String = "")

internal fun NavGraphBuilder.socialGraph(context: ManagementGraphContext) {
    composable<SocialStudioRoute> {
        val viewModel = managementViewModel { SocialStudioViewModel(context.repository, it) }
        SocialStudioScreen(viewModel, context, onOpenBatch = { key, id -> context.navController.navigate(SocialBatchRoute(subject = key, id = id)) })
    }
    composable<SocialBatchRoute> {
        val viewModel = managementViewModel { SocialBatchViewModel(context.repository, it) }
        SocialBatchScreen(viewModel, context)
    }
}
