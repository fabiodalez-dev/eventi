package it.fabiodalez.incitta.ui.editorial.home

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.CityPicker
import it.fabiodalez.incitta.ui.editorial.editorialMenu
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable

/** La prima pagina della redazione: ruolo, città e il menu delle aree permesse (R-DB-12). */
@Serializable
internal data object EditorialHomeRoute

internal fun NavGraphBuilder.homeGraph(context: EditorialGraphContext) {
    composable<EditorialHomeRoute> {
        val model = editorialViewModel { EditorialHomeViewModel(context.repository) }
        val state by model.state.collectAsStateWithLifecycle()
        EditorialHomeScreen(state, context, onRetry = model::load, onCity = model::selectCity)
    }
}

/** Il banco della città scelta; cambiare città rilegge permessi e contatori. */
internal class EditorialHomeViewModel(private val repository: EditorialRepository) : ViewModel() {
    private val mutable = MutableStateFlow<Loadable<EditorialDesk>>(repository.current.value?.let { Loadable.Ready(it) } ?: Loadable.Loading)
    val state: StateFlow<Loadable<EditorialDesk>> = mutable.asStateFlow()
    private val busyState = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = busyState.asStateFlow()

    init { load() }

    fun load() = fetch { repository.loadDesk() }

    fun selectCity(slug: String) = fetch { repository.selectCity(slug) }

    private fun fetch(block: suspend () -> EditorialDesk) {
        if (mutable.value !is Loadable.Ready) mutable.value = Loadable.Loading
        busyState.value = true
        viewModelScope.launch {
            try {
                mutable.value = Loadable.Ready(block())
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                mutable.value = Loadable.Failed(requestFailureMessage(e))
            } finally {
                busyState.value = false
            }
        }
    }
}

/** «Moderatore · Padova», con l'etichetta del ruolo nella lingua dell'app. */
internal fun editorialRoleLabel(role: String): Int? = when (role) {
    EditorialDesk.ROLE_MODERATOR -> R.string.editorial_role_moderator
    EditorialDesk.ROLE_ADMIN -> R.string.editorial_role_admin
    EditorialDesk.ROLE_SUPER_ADMIN -> R.string.editorial_role_super_admin
    else -> null
}

@Composable
internal fun EditorialHomeScreen(state: Loadable<EditorialDesk>, context: EditorialGraphContext, onRetry: () -> Unit, onCity: (String) -> Unit) {
    val desk = (state as? Loadable.Ready)?.value
    val role = desk?.let { editorialRoleLabel(it.role) }?.let { stringResource(it) }
    val city = desk?.city
    val subtitle = if (role != null && city != null) stringResource(R.string.editorial_role_city, role, city.name) else role
    ManagementScaffold(title = stringResource(R.string.editorial_title), padding = context.padding, onBack = context::exit, subtitle = subtitle) {
        LoadState(state, onRetry = onRetry) { loaded ->
            CityPicker(loaded.cities, loaded.city?.slug, onSelect = { onCity(it.slug) })
            editorialMenu(loaded.can).forEach { area -> AreaRow(area, loaded, onClick = { context.open(area) }) }
            ManagementRow(stringResource(R.string.editorial_open_web), stringResource(R.string.editorial_open_web_help), onClick = { context.openWeb("admin") })
        }
    }
}

@Composable
private fun AreaRow(area: EditorialArea, desk: EditorialDesk, onClick: () -> Unit) {
    val badge = area.badgeFor(desk)?.takeIf { it > 0 }
    val help = stringResource(area.help)
    val description = if (area.native) help else help + " · " + stringResource(R.string.editorial_area_web_mark)
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.weight(1f)) { ManagementRow(stringResource(area.title), description, onClick) }
        if (badge != null) {
            Badge(Modifier.padding(start = 8.dp)) { Text(stringResource(R.string.editorial_badge, badge)) }
        }
    }
}
