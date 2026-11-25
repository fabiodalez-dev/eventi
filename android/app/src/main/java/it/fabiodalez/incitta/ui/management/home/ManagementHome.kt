package it.fabiodalez.incitta.ui.management.home

import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.DashboardSummary
import it.fabiodalez.incitta.data.management.ManagementDashboardSource
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.managementCounts
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.analytics.ExportState
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.subjectRoleLabel
import it.fabiodalez.incitta.ui.management.managementViewModel
import it.fabiodalez.incitta.ui.management.overview.OverviewContent
import it.fabiodalez.incitta.ui.management.overview.OverviewNavigation
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable

/*
 * La pagina del locale o dell'organizzazione aperta: il riepilogo (P11), come
 * la prima pagina dei pannelli. In cima il nome e il selettore del soggetto;
 * sotto i riquadri, le scorciatoie «Vai a» e il collegamento al rapporto.
 * Il menu completo delle aree resta in «Tutte le aree».
 */
@Serializable internal data object ManagementHomeRoute

internal data class ManagementHomeUiState(
    val subjects: Loadable<ManagementSubjects> = Loadable.Loading,
    val selectedKey: String? = null,
    /* Il riepilogo del soggetto scelto; `Empty` senza la fonte (test che non la usano). */
    val summary: Loadable<DashboardSummary> = Loadable.Loading,
    /* Una rilettura con il riepilogo di prima ancora a schermo. */
    val refreshing: Boolean = false,
    /* Il programma in PDF: quale settimana si sta scaricando, il file pronto o l'errore. */
    val programme: ExportState = ExportState(),
) {
    val selected: ManagementSubject? get() = (subjects as? Loadable.Ready)?.value?.let { it.find(selectedKey) ?: it.all().firstOrNull() }
    val all: List<ManagementSubject> get() = (subjects as? Loadable.Ready)?.value?.all().orEmpty()
}

internal class ManagementHomeViewModel(
    private val repository: ManagementRepository,
    private val saved: SavedStateHandle,
    private val now: () -> Long = System::currentTimeMillis,
) : ViewModel() {
    private val state = MutableStateFlow(ManagementHomeUiState(selectedKey = saved[KEY_SUBJECT] ?: repository.selectedSubjectKey))
    val uiState: StateFlow<ManagementHomeUiState> = state.asStateFlow()
    private var summaryJob: Job? = null
    private var loadedAt = 0L

    init {
        val cached = repository.subjects.value
        if (cached != null) show(cached) else load()
    }

    fun load() {
        state.update { it.copy(subjects = Loadable.Loading) }
        viewModelScope.launch {
            try { show(repository.loadSubjects()) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(subjects = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun select(subject: ManagementSubject) {
        if (subject.key == state.value.selectedKey) return
        saved[KEY_SUBJECT] = subject.key
        repository.selectedSubjectKey = subject.key
        state.update { it.copy(selectedKey = subject.key, summary = Loadable.Loading, programme = ExportState()) }
        loadSummary()
    }

    /**
     * Si torna sulla pagina (da una scheda, dal modulo di un evento): i
     * numeri possono essere cambiati, si rilegge senza togliere quelli di
     * prima. Non subito dopo una lettura (una rotazione non rilegge).
     */
    fun onResume() {
        if (state.value.summary !is Loadable.Ready || now() - loadedAt < REFRESH_AFTER_MILLIS) return
        loadSummary()
    }

    /** Rilegge il riepilogo del soggetto scelto; con un riepilogo già a schermo lo lascia lì mentre carica. */
    fun loadSummary() {
        val subject = state.value.selected ?: return
        val source: ManagementDashboardSource = repository.dashboard ?: run { state.update { it.copy(summary = Loadable.Empty) }; return }
        summaryJob?.cancel()
        state.update { it.copy(refreshing = it.summary is Loadable.Ready, summary = if (it.summary is Loadable.Ready) it.summary else Loadable.Loading) }
        summaryJob = viewModelScope.launch {
            val result: Loadable<DashboardSummary> = try { Loadable.Ready(source.summary(subject)) }
                catch (e: CancellationException) { throw e }
                catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
            loadedAt = now()
            state.update { if (it.selected?.key == subject.key) it.copy(summary = result, refreshing = false) else it }
        }
    }

    /** «Stampa il programma della settimana» / «Settimana prossima»: il PDF del server nella cartella della gestione. */
    fun programme(week: String, cacheDir: File) {
        val subject = state.value.selected ?: return
        val source = repository.dashboard ?: return
        if (state.value.programme.busy != null) return
        state.update { it.copy(programme = ExportState(busy = week)) }
        viewModelScope.launch {
            val next = try {
                runCatching { ManagementFiles.purge(cacheDir) }
                ExportState(file = source.programme(subject, week, ManagementFiles.directory(cacheDir)))
            } catch (e: CancellationException) { throw e } catch (e: Exception) { ExportState(error = requestFailureMessage(e)) }
            state.update { it.copy(programme = next) }
        }
    }

    private fun show(subjects: ManagementSubjects) {
        val key = subjects.find(state.value.selectedKey)?.key ?: subjects.all().firstOrNull()?.key
        repository.selectedSubjectKey = key
        state.update { it.copy(subjects = if (subjects.all().isEmpty()) Loadable.Empty else Loadable.Ready(subjects), selectedKey = key) }
        loadSummary()
    }

    companion object {
        const val KEY_SUBJECT = "subject"
        const val REFRESH_AFTER_MILLIS = 5_000L
    }
}

internal fun NavGraphBuilder.homeGraph(context: ManagementGraphContext) {
    composable<ManagementHomeRoute> {
        val viewModel = managementViewModel { ManagementHomeViewModel(context.repository, it) }
        ManagementHomeScreen(viewModel, context)
    }
}

@Composable
internal fun ManagementHomeScreen(viewModel: ManagementHomeViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val cacheDir = LocalContext.current.cacheDir
    val subject = state.selected
    val hasCheckinDates = (context.session.user.managementCounts()?.checkin ?: 0) > 0
    val subtitle = subject?.let { current ->
        listOfNotNull(subjectRoleLabel(current)?.let { stringResource(it) }, current.cityName).joinToString(" · ")
    }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { viewModel.onResume() }
    ManagementScaffold(
        title = subject?.name ?: stringResource(R.string.management_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subtitle,
        subjects = state.all,
        selectedKey = state.selectedKey,
        onSelectSubject = viewModel::select,
        busy = state.refreshing,
    ) {
        LoadState(state.subjects, onRetry = viewModel::load, emptyText = stringResource(R.string.management_empty)) {
            val current = subject ?: return@LoadState
            OverviewContent(
                subject = current,
                summary = state.summary,
                programme = state.programme,
                hasCheckinDates = hasCheckinDates,
                navigation = remember(current.key) { OverviewNavigation(context.navController, current) },
                onRetry = viewModel::loadSummary,
                onProgramme = { week -> viewModel.programme(week, cacheDir) },
            )
        }
    }
}
