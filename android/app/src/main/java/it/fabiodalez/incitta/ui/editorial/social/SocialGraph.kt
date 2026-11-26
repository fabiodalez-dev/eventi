package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.socialApi
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R9 — Social della redazione (R-SO-01…07, R-DB-10, R-WB-03, R-WB-04).
 *
 * - `SocialRoute`, la voce del menu: lo studio della pagina «Social», con le
 *   porte verso «Social di oggi», lo storico e le impostazioni;
 * - `EditorialSocialStudioRoute(event)`: lo studio ristretto a un evento,
 *   come la pagina aperta dalla scheda dell'evento (`admin/social?event=`);
 * - un pacchetto, lo storico delle pubblicazioni, le impostazioni, «Social
 *   di oggi».
 *
 * Tutte per admin e super admin (`social_use`); il server rifà il
 * controllo su ogni chiamata.
 */
@Serializable
internal data object SocialRoute

@Serializable
internal data class EditorialSocialStudioRoute(val event: Long = 0)

@Serializable
internal data class EditorialSocialBatchRoute(val id: String)

@Serializable
internal data object EditorialSocialPublicationsRoute

@Serializable
internal data object EditorialSocialSettingsRoute

@Serializable
internal data object EditorialSocialTodayRoute

internal fun NavGraphBuilder.socialGraph(context: EditorialGraphContext) {
    composable<SocialRoute> { StudioDestination(context) }
    composable<EditorialSocialStudioRoute> { StudioDestination(context) }
    composable<EditorialSocialBatchRoute> {
        val viewModel = editorialViewModel { EditorialSocialBatchViewModel(context.repository, context.repository.socialApi(), it) }
        EditorialSocialBatchScreen(viewModel, context.padding, onBack = context::back, onOpenHistory = { context.navController.navigate(EditorialSocialPublicationsRoute) })
    }
    composable<EditorialSocialPublicationsRoute> {
        val viewModel = editorialViewModel { SocialPublicationsViewModel(context.repository, context.repository.socialApi(), it) }
        OnReturn { viewModel.list.refresh() }
        SocialPublicationsScreen(viewModel, context.padding, zone(context), onBack = context::back,
            onOpenBatch = { id -> context.navController.navigate(EditorialSocialBatchRoute(id)) })
    }
    composable<EditorialSocialSettingsRoute> {
        val viewModel = editorialViewModel { SocialSettingsViewModel(context.repository, context.repository.socialApi()) }
        /* Di ritorno dal browser (collegamento con Meta): lo stato si rilegge. */
        OnReturn { viewModel.load() }
        val uriHandler = LocalUriHandler.current
        var browserFailed by remember { mutableStateOf(false) }
        SocialSettingsScreen(
            viewModel,
            context.padding,
            onBack = context::back,
            onOpenWeb = context::openWeb,
            onOpenUrl = { url -> browserFailed = runCatching { uriHandler.openUri(url) }.isFailure },
        )
        if (browserFailed) {
            AlertDialog(
                onDismissRequest = { browserFailed = false },
                text = { Text(stringResource(R.string.management_export_no_app)) },
                confirmButton = { TextButton(onClick = { browserFailed = false }) { Text(stringResource(R.string.social_close)) } },
            )
        }
    }
    composable<EditorialSocialTodayRoute> {
        val viewModel = editorialViewModel { SocialTodayViewModel(context.repository, context.repository.socialApi()) }
        SocialTodayScreen(
            viewModel,
            context.padding,
            token = context.repository.token,
            onBack = context::back,
            onOpenBatch = { id -> context.navController.navigate(EditorialSocialBatchRoute(id)) },
            onOpenStudio = { context.navController.navigate(EditorialSocialStudioRoute()) },
        )
    }
}

@androidx.compose.runtime.Composable
private fun StudioDestination(context: EditorialGraphContext) {
    val viewModel = editorialViewModel { EditorialSocialStudioViewModel(context.repository, context.repository.socialApi(), it) }
    EditorialSocialStudioScreen(
        viewModel,
        context.padding,
        token = context.repository.token,
        onBack = context::back,
        onOpenBatch = { id -> context.navController.navigate(EditorialSocialBatchRoute(id)) },
        onOpenToday = { context.navController.navigate(EditorialSocialTodayRoute) },
        onOpenHistory = { context.navController.navigate(EditorialSocialPublicationsRoute) },
        onOpenSettings = { context.navController.navigate(EditorialSocialSettingsRoute) },
    )
}

/**
 * Ogni ritorno sulla schermata (dal browser, da un'altra destinazione), ma
 * non la prima apertura: lì il ViewModel ha appena letto i dati.
 */
@androidx.compose.runtime.Composable
private fun OnReturn(action: () -> Unit) {
    var first by rememberSaveable { mutableStateOf(true) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
        if (first) first = false else action()
    }
}

/** Le date nel fuso della città di lavoro. */
private fun zone(context: EditorialGraphContext): ZoneId = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
