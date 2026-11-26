package it.fabiodalez.incitta.ui.editorial.settings

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.editorial.settingsApi
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.editorialViewModel
import java.time.ZoneId
import kotlinx.serialization.Serializable

/*
 * R13 — Configurazione tecnica (R-SY-08…14).
 *
 * Il menu dell'area con le pagine del gruppo «Sistema» del pannello che
 * riguardano la configurazione (testi delle email, posta, script di
 * consenso, cookie, indirizzi cambiati, cache) più la Newsletter, che nel
 * pannello sta in «Promozione» ma è un'impostazione del super admin come la
 * posta. Ogni voce si vede con lo stesso permesso del pannello.
 */
@Serializable
internal data object SettingsRoute

@Serializable
internal data object SettingsTextsRoute

@Serializable
internal data class SettingsTextRoute(val key: String)

@Serializable
internal data object SettingsMailRoute

@Serializable
internal data object SettingsNewsletterRoute

@Serializable
internal data object SettingsScriptsRoute

@Serializable
internal data class SettingsScriptRoute(val id: Long)

@Serializable
internal data object SettingsCookiesRoute

@Serializable
internal data object SettingsRedirectsRoute

@Serializable
internal data object SettingsRedirectNewRoute

@Serializable
internal data object SettingsCacheRoute

/* Il dettaglio scrive nella voce dell'elenco quando salva: l'elenco, tornando, si ricarica. */
private const val KEY_CHANGED = "settings_changed"

internal fun NavGraphBuilder.settingsGraph(context: EditorialGraphContext) {
    composable<SettingsRoute> {
        val viewModel = editorialViewModel { SettingsHubViewModel(context.repository) }
        val desk by viewModel.desk.collectAsStateWithLifecycle()
        SettingsHub(desk, stringResource(EditorialArea.Settings.title), context.padding, onBack = context::back, onRetry = viewModel::refresh, onOpen = { section ->
            context.navController.navigate(
                when (section) {
                    SettingsSection.Texts -> SettingsTextsRoute
                    SettingsSection.Mail -> SettingsMailRoute
                    SettingsSection.Newsletter -> SettingsNewsletterRoute
                    SettingsSection.Scripts -> SettingsScriptsRoute
                    SettingsSection.Cookies -> SettingsCookiesRoute
                    SettingsSection.Redirects -> SettingsRedirectsRoute
                    SettingsSection.Cache -> SettingsCacheRoute
                },
            )
        })
    }
    composable<SettingsTextsRoute> { entry ->
        val viewModel = editorialViewModel { EmailTextsViewModel(context.repository, context.repository.settingsApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        RefreshWhenChanged(entry) { viewModel.load() }
        EmailTextsContent(state, context.padding, onBack = context::back, onRetry = viewModel::load, onGroup = viewModel::selectGroup,
            onOpen = { key -> context.navController.navigate(SettingsTextRoute(key)) },
            onAskReset = viewModel::askReset, onDismissReset = viewModel::dismissReset, onReset = viewModel::reset)
    }
    composable<SettingsTextRoute> {
        val viewModel = editorialViewModel { EmailTextEditViewModel(context.repository, context.repository.settingsApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        LaunchedEffect(state.changed) {
            if (state.changed) context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CHANGED, true)
        }
        EmailTextEditor(state, context.padding, onBack = context::back, onRetry = viewModel::load, onValue = viewModel::setValue,
            onInsert = viewModel::insert, onRestore = viewModel::restoreDefault, onSave = viewModel::save)
    }
    composable<SettingsMailRoute> {
        val viewModel = editorialViewModel { MailSettingsViewModel(context.repository, context.repository.settingsApi()) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        MailSettingsContent(state, context.padding, onBack = context::back, onRetry = viewModel::load, onAskTest = viewModel::askTest,
            onDismissTest = viewModel::dismissTest, onSendTest = viewModel::sendTest, onWeb = { context.openWeb(SettingsSection.Mail.webPath) })
    }
    composable<SettingsNewsletterRoute> {
        val viewModel = editorialViewModel { NewsletterViewModel(context.repository, context.repository.settingsApi()) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        NewsletterContent(state, context.padding, onBack = context::back, onRetry = viewModel::load, onEdit = viewModel::edit,
            onText = viewModel::setText, onInsert = viewModel::insert, onSave = viewModel::save)
    }
    composable<SettingsScriptsRoute> {
        val viewModel = editorialViewModel { ScriptsViewModel(context.repository, context.repository.settingsApi(), it) }
        val state by viewModel.list.current.collectAsStateWithLifecycle()
        ScriptsList(state, context.padding, onBack = context::back, onSearch = viewModel::search, onOpen = { id -> context.navController.navigate(SettingsScriptRoute(id)) },
            onLoadMore = viewModel.list::loadMore, onRetry = viewModel.list::retry, onWeb = { context.openWeb(SettingsSection.Scripts.webPath) })
    }
    composable<SettingsScriptRoute> {
        val viewModel = editorialViewModel { ScriptDetailViewModel(context.repository, context.repository.settingsApi(), it) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        ScriptDetail(state, context.padding, onBack = context::back, onRetry = viewModel::load, onWeb = { context.openWeb(SettingsSection.Scripts.webPath) })
    }
    composable<SettingsCookiesRoute> {
        val viewModel = editorialViewModel { CookiesViewModel(context.repository, context.repository.settingsApi(), it) }
        val list by viewModel.list.current.collectAsStateWithLifecycle()
        val ui by viewModel.uiState.collectAsStateWithLifecycle()
        CookiesList(list, ui, context.padding, onBack = context::back, onSearch = viewModel::search, onTab = viewModel::selectTab,
            onLoadMore = viewModel.list::loadMore, onRetry = viewModel.list::retry, onAdd = viewModel::openAdd, onMove = viewModel::move)
        CookieAddDialog(ui, onEdit = viewModel::editDraft, onSave = viewModel::add, onDismiss = viewModel::dismissAdd)
    }
    composable<SettingsRedirectsRoute> { entry ->
        val viewModel = editorialViewModel { RedirectsViewModel(context.repository, context.repository.settingsApi(), it) }
        val state by viewModel.list.current.collectAsStateWithLifecycle()
        val desk by context.repository.current.collectAsStateWithLifecycle()
        RefreshWhenChanged(entry) { viewModel.list.refresh() }
        RedirectsList(state, context.padding, zone(context), canCreate = desk?.can?.pagesManage == true, onBack = context::back, onSearch = viewModel::search,
            onSort = viewModel::sortBy, onLoadMore = viewModel.list::loadMore, onRetry = viewModel.list::retry,
            onAdd = { context.navController.navigate(SettingsRedirectNewRoute) })
    }
    composable<SettingsRedirectNewRoute> {
        val viewModel = editorialViewModel { RedirectNewViewModel(context.repository, context.repository.settingsApi()) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        LaunchedEffect(state.created) {
            if (state.created != null) {
                context.navController.previousBackStackEntry?.savedStateHandle?.set(KEY_CHANGED, true)
                context.back()
            }
        }
        RedirectNewForm(state, context.padding, onBack = context::back, onRetry = viewModel::load, onEdit = viewModel::edit, onSave = viewModel::save)
    }
    composable<SettingsCacheRoute> {
        val viewModel = editorialViewModel { CacheViewModel(context.repository, context.repository.settingsApi()) }
        val state by viewModel.uiState.collectAsStateWithLifecycle()
        CacheContent(state, context.padding, onBack = context::back, onRetry = viewModel::load, onAskClear = viewModel::askClear, onDismissClear = viewModel::dismissClear,
            onClear = viewModel::clear, onVerify = viewModel::verify, onWeb = { context.openWeb(SettingsSection.Cache.webPath) })
    }
}

/** Le date nel fuso della città di lavoro, come il pannello. */
private fun zone(context: EditorialGraphContext): ZoneId = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")

@Composable
private fun RefreshWhenChanged(entry: NavBackStackEntry, refresh: () -> Unit) {
    val changed by entry.savedStateHandle.getStateFlow(KEY_CHANGED, false).collectAsStateWithLifecycle()
    LaunchedEffect(changed) {
        if (changed) {
            entry.savedStateHandle[KEY_CHANGED] = false
            refresh()
        }
    }
}
