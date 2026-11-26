package it.fabiodalez.incitta.ui.editorial.users

import android.widget.Toast
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R5 — Utenti (R-US-01, 02, 04…06, 08…12). L'elenco, la scheda della
 * persona e «Nuovo utente». Restano sul sito (decisioni 4, 8, 9):
 * esportazione (R-US-03), impersonificazione (R-US-07, R-WB-02),
 * eliminazione definitiva, conferimento di admin e super admin, email e
 * password di un'altra persona.
 */
@Serializable
internal data object UsersRoute

@Serializable
internal data class UserDetailRoute(val id: Long)

@Serializable
internal data object UserCreateRoute

internal fun NavGraphBuilder.usersGraph(context: EditorialGraphContext) {
    composable<UsersRoute> { entry ->
        val repository = context.repository
        val viewModel = editorialViewModel { saved -> UsersListViewModel(repository, usersSource(repository), saved) }
        val desk by repository.current.collectAsState()
        /* Tornando dalla scheda o dalla creazione l'elenco si rilegge con gli stessi criteri. */
        val changed by entry.savedStateHandle.getStateFlow(KEY_CHANGED, false).collectAsState()
        LaunchedEffect(changed) {
            if (changed) {
                entry.savedStateHandle[KEY_CHANGED] = false
                viewModel.refresh()
            }
        }
        UsersListScreen(
            viewModel = viewModel,
            padding = context.padding,
            canCreate = desk?.can?.usersManage == true,
            zone = desk?.city?.zone ?: DEFAULT_ZONE,
            onBack = context::back,
            onOpen = { user -> context.navController.navigate(UserDetailRoute(user.id)) },
            onCreate = { context.navController.navigate(UserCreateRoute) },
        )
    }
    composable<UserDetailRoute> { entry ->
        val route = entry.toRoute<UserDetailRoute>()
        val repository = context.repository
        val viewModel = editorialViewModel { saved -> UserDetailViewModel(repository, usersSource(repository), saved, route.id) }
        val desk by repository.current.collectAsState()
        val android = LocalContext.current
        val signedOut = android.getString(R.string.editorial_users_signed_out)
        UserDetailScreen(
            viewModel = viewModel,
            padding = context.padding,
            zone = desk?.city?.zone ?: DEFAULT_ZONE,
            onBack = {
                context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CHANGED, true)
                context.back()
            },
            /* Le proprie credenziali cambiate: il server ha chiuso tutte le sessioni, si esce dall'area. */
            onSignedOut = {
                Toast.makeText(android, signedOut, Toast.LENGTH_LONG).show()
                context.exit()
            },
            onOpenWeb = { context.openWeb("admin/users/${route.id}/edit") },
        )
    }
    composable<UserCreateRoute> {
        val repository = context.repository
        val viewModel = editorialViewModel { saved -> UserCreateViewModel(repository, usersSource(repository), saved) }
        UserCreateScreen(
            viewModel = viewModel,
            padding = context.padding,
            onBack = context::back,
            onCreated = { id ->
                context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CHANGED, true)
                context.navController.navigate(UserDetailRoute(id)) { popUpTo(UserCreateRoute) { inclusive = true } }
            },
        )
    }
}

private const val KEY_CHANGED = "users_changed"
private val DEFAULT_ZONE: ZoneId = ZoneId.of("Europe/Rome")
