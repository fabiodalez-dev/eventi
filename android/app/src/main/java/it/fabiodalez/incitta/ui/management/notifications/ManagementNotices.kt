package it.fabiodalez.incitta.ui.management.notifications

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagedNotice
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementNoticesSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagerPreferences
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * L'area «Notifiche» del soggetto (P12). Gli avvisi che il server manda a chi
 * gestisce sono quelli del sito, con le stesse regole: non si spengono, salvo
 * il rapporto mensile (W-NT-05, W-ST-10). Qui si ritrovano quelli di questo
 * locale o di questa organizzazione, e ognuno apre la sua schermata nativa.
 */

internal data class NoticesUiState(
    val subject: ManagementSubject? = null,
    val notices: Loadable<List<ManagedNotice>> = Loadable.Loading,
    val nextCursor: String? = null,
    val loadingMore: Boolean = false,
    /* Il rapporto mensile: solo per i locali, e solo se il server dice che l'interruttore va mostrato. */
    val preferences: ManagerPreferences? = null,
    val savingReport: Boolean = false,
    val message: UiText? = null,
    /* La persona non gestisce (più) il soggetto della rotta. */
    val missingSubject: Boolean = false,
)

internal class ManagementNoticesViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val key: String? = saved.get<String>(KEY_SUBJECT)?.takeIf { it.isNotBlank() }
    private val state = MutableStateFlow(NoticesUiState())
    val uiState: StateFlow<NoticesUiState> = state.asStateFlow()

    init { load() }

    private fun api(): ManagementNoticesSource = repository.notices ?: error("Notifiche non disponibili")

    fun load() {
        state.update { it.copy(notices = Loadable.Loading, message = null) }
        viewModelScope.launch {
            try {
                val subject = resolveNoticesSubject(repository, key)
                if (subject == null) {
                    state.update { it.copy(notices = Loadable.Empty, subject = null, missingSubject = true) }
                    return@launch
                }
                val page = api().notices(subject, null)
                val preferences = if (subject.kind == SubjectKind.Venue) loadPreferences() else null
                state.update {
                    it.copy(subject = subject, missingSubject = false, notices = if (page.data.isEmpty()) Loadable.Empty else Loadable.Ready(page.data),
                        nextCursor = page.meta.nextCursor?.takeIf { page.meta.hasMore }, preferences = preferences)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(notices = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /* Le preferenze non bloccano l'elenco: se non arrivano, l'interruttore non compare. */
    private suspend fun loadPreferences(): ManagerPreferences? =
        try { api().preferences() } catch (e: CancellationException) { throw e } catch (_: Exception) { null }

    fun more() {
        val current = state.value
        val subject = current.subject ?: return
        val cursor = current.nextCursor ?: return
        if (current.loadingMore) return
        state.update { it.copy(loadingMore = true) }
        viewModelScope.launch {
            try {
                val page = api().notices(subject, cursor)
                state.update {
                    val before = (it.notices as? Loadable.Ready)?.value.orEmpty()
                    it.copy(notices = Loadable.Ready(before + page.data), nextCursor = page.meta.nextCursor?.takeIf { page.meta.hasMore }, loadingMore = false)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(loadingMore = false, message = UiText.Raw(requestFailureMessage(e))) } }
        }
    }

    fun setVenueReport(enabled: Boolean) {
        if (state.value.savingReport) return
        state.update { it.copy(savingReport = true, message = null) }
        viewModelScope.launch {
            try {
                val saved = api().setVenueReport(enabled)
                state.update {
                    it.copy(preferences = saved, savingReport = false,
                        message = UiText.Res(if (saved.venueReport) R.string.management_notices_report_on else R.string.management_notices_report_off))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(savingReport = false, message = UiText.Raw(requestFailureMessage(e))) } }
        }
    }

    /** Segna come letto, come il tocco nella casella dell'account; se non riesce resta da leggere. */
    fun markRead(notice: ManagedNotice) {
        if (notice.read) return
        state.update { current ->
            val list = (current.notices as? Loadable.Ready)?.value ?: return@update current
            current.copy(notices = Loadable.Ready(list.map { if (it.id == notice.id) it.copy(read = true) else it }))
        }
        viewModelScope.launch {
            try { api().markRead(notice.id) } catch (e: CancellationException) { throw e } catch (_: Exception) { }
        }
    }

    fun showMessage(message: UiText?) { state.update { it.copy(message = message) } }

    companion object { const val KEY_SUBJECT = "subject" }
}

/** Il soggetto della schermata: quello della rotta, o quello scelto, o il primo; `null` se la persona non lo gestisce (più). */
internal suspend fun resolveNoticesSubject(repository: ManagementRepository, key: String?): ManagementSubject? {
    val pick: (it.fabiodalez.incitta.data.management.ManagementSubjects) -> ManagementSubject? = { list ->
        if (key != null) list.find(key) else list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull()
    }
    val found = repository.subjects.value?.let(pick) ?: pick(repository.loadSubjects())
    found?.let { repository.selectedSubjectKey = it.key }
    return found
}

private val noticeDateFormat = DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)

internal fun noticeDate(iso: String?, zone: ZoneId): String? = cityLocalDateTime(iso, zone)?.format(noticeDateFormat)

@Composable
internal fun ManagementNoticesScreen(viewModel: ManagementNoticesViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    val uriHandler = androidx.compose.ui.platform.LocalUriHandler.current
    val failed = UiText.Res(R.string.management_notices_open_failed)
    val subject = state.subject
    fun open(notice: ManagedNotice) {
        viewModel.markRead(notice)
        val destination = ManagementDestination.parse(notice.target, BuildConfig.API_BASE_URL)
        scope.launch {
            when (destination) {
                is ManagementDestination.Panel -> when (val resolved = resolvePanel(context.repository, destination, enabled = true)) {
                    /* Lo stesso soggetto: la schermata si apre sopra l'elenco, e «indietro» torna qui. */
                    is PanelResolution.Native -> if (resolved.subject.key == subject?.key && resolved.route != null) context.navController.navigate(resolved.route)
                        else openNative(context.navController, context.repository, resolved)
                    is PanelResolution.Web -> runCatching { uriHandler.openUri(resolved.url) }
                    PanelResolution.Nothing -> viewModel.showMessage(failed)
                }
                null -> notice.data.url.takeIf { it.isNotBlank() }?.let { runCatching { uriHandler.openUri(it) } } ?: viewModel.showMessage(failed)
                else -> viewModel.showMessage(failed)
            }
        }
    }
    ManagementScaffold(
        title = stringResource(R.string.management_notices_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.loadingMore || state.savingReport,
    ) {
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
        if (state.missingSubject) {
            Text(stringResource(R.string.management_notices_no_subject), color = Muted)
            return@ManagementScaffold
        }
        Text(stringResource(R.string.management_notices_always), color = Muted)
        state.preferences?.takeIf { it.venueReportAvailable }?.let { preferences ->
            val on = stringResource(R.string.management_notices_report)
            Row(
                Modifier.fillMaxWidth().heightIn(min = 56.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Column(Modifier.weight(1f)) {
                    Text(on, style = MaterialTheme.typography.titleMedium)
                    Text(stringResource(R.string.management_notices_report_help), color = Muted)
                }
                Switch(
                    checked = preferences.venueReport,
                    enabled = !state.savingReport,
                    onCheckedChange = viewModel::setVenueReport,
                    modifier = Modifier.semantics { stateDescription = on },
                )
            }
            HorizontalDivider()
        }
        Text(stringResource(R.string.management_notices_list), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        LoadState(state.notices, onRetry = viewModel::load, emptyText = stringResource(R.string.management_notices_empty)) { notices ->
            val zone = subject?.timezone ?: ZoneId.systemDefault()
            val unread = stringResource(R.string.management_notices_unread)
            Column {
                notices.forEach { notice ->
                    TextButton(onClick = { open(notice) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.Start) {
                            Text(notice.data.title, style = MaterialTheme.typography.titleMedium,
                                color = if (notice.read) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.primary)
                            if (notice.data.body.isNotBlank()) Text(notice.data.body, color = MaterialTheme.colorScheme.onSurface)
                            val meta = listOfNotNull(noticeDate(notice.createdAt, zone), unread.takeIf { !notice.read }).joinToString(" · ")
                            if (meta.isNotEmpty()) Text(meta, color = Muted, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    HorizontalDivider()
                }
            }
            if (state.nextCursor != null) OutlinedButton(onClick = viewModel::more, enabled = !state.loadingMore, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_notices_more))
            }
        }
    }
}
