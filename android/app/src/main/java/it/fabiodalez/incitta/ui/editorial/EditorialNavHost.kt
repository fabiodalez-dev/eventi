package it.fabiodalez.incitta.ui.editorial

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.Composable
import androidx.compose.runtime.State
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.LaunchedEffect
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
import it.fabiodalez.incitta.data.editorial.EditorialDestination
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.calendarApi
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
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.filterNotNull
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

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
    /* Per tradurre lo slug di un indirizzo del pannello nell'id (R14); `null` nei test senza server. */
    private val scope: CoroutineScope? = null,
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

    /**
     * Un indirizzo del pannello (`admin/social?event=41`, `/admin/users/7/edit`,
     * `https://<sito>/admin/sponsorships`) nella schermata nativa che fa la
     * stessa cosa (`EditorialDestination`), con i permessi del banco; se
     * l'app non ce l'ha, il browser come `openWeb`. Vero se l'ha aperto
     * nell'app (o lo sta aprendo, dopo aver chiesto l'id al server).
     *
     * Non sostituisce `openWeb` dove la pagina del sito serve proprio
     * (le scritture che restano solo sul web, come i segreti): lì resta
     * `openWeb`.
     */
    fun openPanel(path: String): Boolean {
        val destination = EditorialDestination.fromPath(editorialPanelPath(path))
        val can = repository.current.value?.can
        val target = if (destination != null && can != null) editorialRouteFor(destination, can) else null
        if (target == null) { openWeb(path); return false }
        navigateTo(target, fallback = { openWeb(path) })
        return true
    }

    /** Apre la schermata di un indirizzo già tradotto; `fallback` se lo slug non porta più a niente. */
    fun navigateTo(target: EditorialRouteTarget, fallback: () -> Unit = {}) {
        when (target) {
            EditorialRouteTarget.Home -> navController.popBackStack(EditorialHomeRoute, inclusive = false)
            is EditorialRouteTarget.Native -> navController.navigate(target.route)
            is EditorialRouteTarget.Resolve -> {
                val launcher = scope ?: return fallback()
                launcher.launch {
                    val found = try {
                        repository.call { repository.calendarApi().resolve(target.type, target.slug) }
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        null
                    }
                    if (found != null && found.id > 0) navController.navigate(target.route(found.id)) else fallback()
                }
            }
        }
    }
}

/** `https://<sito>/admin/…` e `admin/…` diventano `/admin/…`; un indirizzo di un altro sito resta com'è (e non si traduce). */
internal fun editorialPanelPath(path: String, apiBase: String = BuildConfig.API_BASE_URL): String {
    val origin = apiBase.substringBefore("/api/").trimEnd('/')
    return when {
        path.startsWith("$origin/") -> path.removePrefix(origin)
        path.startsWith("http://") || path.startsWith("https://") -> path
        else -> "/" + path.trimStart('/')
    }
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
    /* Un indirizzo `/admin/…` da aprire appena l'area è pronta (un avviso, un collegamento dell'app); poi `onDestinationOpened`. */
    destination: EditorialDestination? = null,
    onDestinationOpened: () -> Unit = {},
) {
    val navController = rememberNavController()
    val scope = rememberCoroutineScope()
    val uriHandler = LocalUriHandler.current
    val paddingState = rememberUpdatedState(padding)
    val exitState = rememberUpdatedState(onExit)
    /* Un percorso del pannello (`admin/…`) sul sito; un indirizzo intero (una notifica della campanella) così com'è. */
    val openWeb: (String) -> Unit = { path ->
        runCatching { uriHandler.openUri(if (path.startsWith("https://") || path.startsWith("http://")) path else editorialWebUrl(path)) }
    }
    val webState = rememberUpdatedState(openWeb)
    val inAppState = rememberUpdatedState(onOpenInApp)
    val context = remember(navController, repository) { EditorialGraphContext(navController, repository, session, paddingState, exitState, webState, inAppState, scope) }
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
    /*
     * Il banco serve per i permessi: si aspetta che la prima pagina lo
     * carichi. Un indirizzo che l'app non apre lascia l'area sulla prima
     * pagina (il pannello direbbe di no allo stesso modo).
     */
    LaunchedEffect(destination) {
        val wanted = destination ?: return@LaunchedEffect
        val can = repository.current.filterNotNull().first().can
        editorialRouteFor(wanted, can)?.let { context.navigateTo(it) }
        onDestinationOpened()
    }
}

/** Il ViewModel di una destinazione della redazione, con lo stato salvato della voce di navigazione. */
@Composable
internal inline fun <reified VM : ViewModel> editorialViewModel(crossinline create: (SavedStateHandle) -> VM): VM =
    viewModel(factory = viewModelFactory { initializer { create(createSavedStateHandle()) } })
