package it.fabiodalez.incitta.ui.editorial

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.Composable
import androidx.compose.runtime.State
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.LocalUriHandler
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.ui.editorial.analytics.analyticsGraph
import it.fabiodalez.incitta.ui.editorial.calendar.calendarGraph
import it.fabiodalez.incitta.ui.editorial.catalog.catalogGraph
import it.fabiodalez.incitta.ui.editorial.community.communityGraph
import it.fabiodalez.incitta.ui.editorial.dashboard.dashboardGraph
import it.fabiodalez.incitta.ui.editorial.eventeditor.eventEditorGraph
import it.fabiodalez.incitta.ui.editorial.events.eventsGraph
import it.fabiodalez.incitta.ui.editorial.home.EditorialHomeRoute
import it.fabiodalez.incitta.ui.editorial.home.homeGraph
import it.fabiodalez.incitta.ui.editorial.moderation.moderationGraph
import it.fabiodalez.incitta.ui.editorial.promotion.promotionGraph
import it.fabiodalez.incitta.ui.editorial.settings.settingsGraph
import it.fabiodalez.incitta.ui.editorial.social.socialGraph
import it.fabiodalez.incitta.ui.editorial.system.systemGraph
import it.fabiodalez.incitta.ui.editorial.users.usersGraph
import it.fabiodalez.incitta.ui.editorial.venues.venuesGraph

/**
 * Quello che ogni area della redazione riceve per dichiarare le sue
 * destinazioni: la navigazione, il repository della sessione (con il banco,
 * la città e `call { }` per i 423), il margine dello `Scaffold` dell'app,
 * l'uscita dall'area e l'apertura di una pagina del pannello web.
 */
internal class EditorialGraphContext(
    val navController: NavHostController,
    val repository: EditorialRepository,
    val session: Session,
    private val paddingState: State<PaddingValues>,
    private val exitState: State<() -> Unit>,
    private val webState: State<(String) -> Unit>,
    /* L'apertura nell'app di un indirizzo del sito (R3, la biglietteria di una data); `null`: il browser. */
    private val inAppState: State<((String) -> Unit)?> = mutableStateOf<((String) -> Unit)?>(null),
) {
    val padding: PaddingValues get() = paddingState.value
    fun exit() = exitState.value()
    /** Indietro dentro l'area; dalla prima schermata si esce dalla redazione. */
    fun back() { if (!navController.popBackStack()) exit() }
    /** Apre nel browser una pagina del pannello (`admin/events`): il ripiego per quello che l'app non fa ancora. */
    fun openWeb(path: String) = webState.value(path)
    /**
     * Apre una pagina del sito che l'app sa mostrare da sé (per esempio
     * `gestione-biglietti/{data}`, la biglietteria nativa); se l'app non la
     * conosce, il browser come `openWeb`.
     */
    fun openInApp(path: String) {
        val open = inAppState.value
        if (open != null) open(editorialWebUrl(path)) else openWeb(path)
    }
    /** Apre un'area dal menu. */
    fun open(area: EditorialArea) = navController.navigate(area.entry)
}

/** L'indirizzo web di una pagina del pannello: `https://<sito>/admin/...`. */
internal fun editorialWebUrl(path: String, apiBase: String = BuildConfig.API_BASE_URL): String =
    apiBase.substringBefore("/api/").trimEnd('/') + "/" + path.trimStart('/')

/**
 * La navigazione della sola area della redazione. Ogni pacchetto dichiara le
 * sue destinazioni nel suo file (`fun NavGraphBuilder.<area>Graph`): sono già
 * tutte registrate qui, così chi lavora su un'area non tocca questo file.
 */
@Composable
internal fun EditorialNavHost(
    repository: EditorialRepository,
    session: Session,
    padding: PaddingValues,
    onExit: () -> Unit,
    onOpenInApp: ((String) -> Unit)? = null,
) {
    val navController = rememberNavController()
    val uriHandler = LocalUriHandler.current
    val paddingState = rememberUpdatedState(padding)
    val exitState = rememberUpdatedState(onExit)
    val openWeb: (String) -> Unit = { path -> runCatching { uriHandler.openUri(editorialWebUrl(path)) } }
    val webState = rememberUpdatedState(openWeb)
    val inAppState = rememberUpdatedState(onOpenInApp)
    val context = remember(navController, repository) { EditorialGraphContext(navController, repository, session, paddingState, exitState, webState, inAppState) }
    val entry by navController.currentBackStackEntryAsState()
    BackHandler(enabled = entry != null && navController.previousBackStackEntry == null) { onExit() }
    NavHost(navController = navController, startDestination = EditorialHomeRoute) {
        homeGraph(context)
        dashboardGraph(context)
        eventsGraph(context)
        eventEditorGraph(context)
        venuesGraph(context)
        usersGraph(context)
        moderationGraph(context)
        communityGraph(context)
        promotionGraph(context)
        socialGraph(context)
        analyticsGraph(context)
        catalogGraph(context)
        systemGraph(context)
        settingsGraph(context)
        calendarGraph(context)
    }
}

/** Il ViewModel di una destinazione della redazione, con lo stato salvato della voce di navigazione. */
@Composable
internal inline fun <reified VM : ViewModel> editorialViewModel(crossinline create: (SavedStateHandle) -> VM): VM =
    viewModel(factory = viewModelFactory { initializer { create(createSavedStateHandle()) } })
