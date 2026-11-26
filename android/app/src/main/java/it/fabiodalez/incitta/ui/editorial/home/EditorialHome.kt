package it.fabiodalez.incitta.ui.editorial.home

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialCalendarSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.calendarApi
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.analytics.AnalyticsReportRoute
import it.fabiodalez.incitta.ui.editorial.calendar.CalendarRoute
import it.fabiodalez.incitta.ui.editorial.calendar.EditorialBellRoute
import it.fabiodalez.incitta.ui.editorial.common.CityPicker
import it.fabiodalez.incitta.ui.editorial.eventeditor.EditorialEventEditorRoute
import it.fabiodalez.incitta.ui.editorial.moderation.ModerationCommentsRoute
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialTodayRoute
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

/**
 * La prima pagina della redazione: ruolo, città, la campanella (R-DB-11), le
 * scorciatoie della barra in alto del pannello (R-DB-12), «Social di oggi»
 * (R-DB-10) e il menu delle aree permesse con i contatori (R-DB-13).
 */
@Serializable
internal data object EditorialHomeRoute

internal fun NavGraphBuilder.homeGraph(context: EditorialGraphContext) {
    composable<EditorialHomeRoute> {
        val model = editorialViewModel { EditorialHomeViewModel(context.repository, runCatching { context.repository.calendarApi() }.getOrNull()) }
        val state by model.state.collectAsStateWithLifecycle()
        val unread by model.unread.collectAsStateWithLifecycle()
        /* Di ritorno dalla campanella il numero si rilegge. */
        androidx.lifecycle.compose.LifecycleEventEffect(androidx.lifecycle.Lifecycle.Event.ON_RESUME) { model.loadUnread() }
        EditorialHomeScreen(state, context, onRetry = model::load, onCity = model::selectCity, unread = unread)
    }
}

/** Il banco della città scelta; cambiare città rilegge permessi e contatori. */
internal class EditorialHomeViewModel(
    private val repository: EditorialRepository,
    /* La campanella (R14); `null` nei test senza client: niente numero. */
    private val bell: EditorialCalendarSource? = null,
) : ViewModel() {
    private val mutable = MutableStateFlow<Loadable<EditorialDesk>>(repository.current.value?.let { Loadable.Ready(it) } ?: Loadable.Loading)
    val state: StateFlow<Loadable<EditorialDesk>> = mutable.asStateFlow()
    private val busyState = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = busyState.asStateFlow()

    private val unreadState = MutableStateFlow<Int?>(null)
    /** Le notifiche da leggere della campanella; `null` finché non si sa (o se la lettura non riesce). */
    val unread: StateFlow<Int?> = unreadState.asStateFlow()

    init { load() }

    fun load() = fetch { repository.loadDesk() }

    /**
     * Il numero accanto alla campanella: un errore non disturba la prima
     * pagina, resta senza numero. Fuori da `repository.call`: per un numero
     * non si chiede la password (la chiede la campanella, se serve).
     */
    fun loadUnread() {
        val source = bell ?: return
        viewModelScope.launch {
            unreadState.value = try {
                source.notifications().meta.unread
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                null
            }
        }
    }

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
internal fun EditorialHomeScreen(state: Loadable<EditorialDesk>, context: EditorialGraphContext, onRetry: () -> Unit, onCity: (String) -> Unit, unread: Int? = null) {
    val desk = (state as? Loadable.Ready)?.value
    val role = desk?.let { editorialRoleLabel(it.role) }?.let { stringResource(it) }
    val city = desk?.city
    val subtitle = if (role != null && city != null) stringResource(R.string.editorial_role_city, role, city.name) else role
    ManagementScaffold(title = stringResource(R.string.editorial_title), padding = context.padding, onBack = context::exit, subtitle = subtitle) {
        LoadState(state, onRetry = onRetry) { loaded ->
            CityPicker(loaded.cities, loaded.city?.slug, onSelect = { onCity(it.slug) })
            BellButton(unread, onClick = { context.navController.navigate(EditorialBellRoute) })
            Shortcuts(editorialShortcuts(loaded), onOpen = { shortcut -> openShortcut(shortcut, context) })
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

/**
 * Le scorciatoie della barra in alto del pannello (`TopbarActions`), nello
 * stesso ordine e con gli stessi permessi: «Nuovo evento», «Eventi»,
 * «Statistiche», poi «Commenti agli eventi», «Calendario», «Biglietti e
 * partecipanti» (admin e super admin), il sito. In più «Social di oggi»
 * (R-DB-10), che nel pannello è un riquadro della pagina iniziale.
 */
internal enum class EditorialShortcut(val label: Int) {
    Create(R.string.editorial_shortcut_create),
    Events(R.string.editorial_shortcut_events),
    Analytics(R.string.editorial_shortcut_analytics),
    Comments(R.string.editorial_shortcut_comments),
    Calendar(R.string.editorial_shortcut_calendar),
    Tickets(R.string.editorial_shortcut_tickets),
    SocialToday(R.string.editorial_shortcut_social_today),
    Site(R.string.editorial_shortcut_site),
}

/** Le scorciatoie di chi guarda: le stesse domande che il pannello fa per mostrarle. */
internal fun editorialShortcuts(desk: EditorialDesk): List<EditorialShortcut> {
    val can = desk.can
    return EditorialShortcut.entries.filter { shortcut ->
        when (shortcut) {
            EditorialShortcut.Create -> can.eventsEdit
            EditorialShortcut.Events, EditorialShortcut.Calendar -> can.eventsView
            EditorialShortcut.Analytics -> can.analyticsView
            EditorialShortcut.Comments -> can.commentsModerate
            /* Nel pannello la voce c'è per ruolo (`hasAnyRole(['admin', 'super_admin'])`). */
            EditorialShortcut.Tickets -> desk.isAdmin
            EditorialShortcut.SocialToday -> can.socialUse
            EditorialShortcut.Site -> true
        }
    }
}

private fun openShortcut(shortcut: EditorialShortcut, context: EditorialGraphContext) {
    val nav = context.navController
    when (shortcut) {
        EditorialShortcut.Create -> nav.navigate(EditorialEventEditorRoute())
        EditorialShortcut.Events -> context.open(EditorialArea.Events)
        EditorialShortcut.Analytics -> nav.navigate(AnalyticsReportRoute())
        EditorialShortcut.Comments -> nav.navigate(ModerationCommentsRoute())
        EditorialShortcut.Calendar -> nav.navigate(CalendarRoute)
        /* La biglietteria di tutte le date è già nativa (A-RED-03): `gestione-biglietti` si apre nell'app. */
        EditorialShortcut.Tickets -> context.openInApp("gestione-biglietti")
        EditorialShortcut.SocialToday -> nav.navigate(EditorialSocialTodayRoute)
        /* Il sito, per chi è nell'app, è l'app stessa: si esce dalla redazione e si torna alla parte pubblica. */
        EditorialShortcut.Site -> context.exit()
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Shortcuts(shortcuts: List<EditorialShortcut>, onOpen: (EditorialShortcut) -> Unit) {
    if (shortcuts.isEmpty()) return
    val label = stringResource(R.string.editorial_shortcuts)
    FlowRow(
        Modifier.fillMaxWidth().semantics { contentDescription = label },
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        shortcuts.forEach { shortcut ->
            AssistChip(onClick = { onOpen(shortcut) }, label = { Text(stringResource(shortcut.label)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
}

@Composable
private fun BellButton(unread: Int?, onClick: () -> Unit) {
    val count = unread?.takeIf { it > 0 }
    val spoken = if (count == null) stringResource(R.string.editorial_bell_open) else pluralStringResource(R.plurals.editorial_bell_unread, count, count)
    OutlinedButton(onClick = onClick, modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = spoken }) {
        BadgedBox(badge = { if (count != null) Badge { Text(count.toString()) } }) {
            Icon(Icons.Outlined.Notifications, contentDescription = null)
        }
        Spacer(Modifier.width(12.dp))
        Text(stringResource(R.string.editorial_bell_title))
    }
}
