package it.fabiodalez.incitta.ui.management.home

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.managementCounts
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementArea
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.availableAreas
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.subjectRoleLabel
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable

/* La pagina del locale o dell'organizzazione aperta: finché non c'è il riepilogo (P11) è il menu delle aree. */
@Serializable internal data object ManagementHomeRoute

internal data class ManagementHomeUiState(
    val subjects: Loadable<ManagementSubjects> = Loadable.Loading,
    val selectedKey: String? = null,
) {
    val selected: ManagementSubject? get() = (subjects as? Loadable.Ready)?.value?.let { it.find(selectedKey) ?: it.all().firstOrNull() }
    val all: List<ManagementSubject> get() = (subjects as? Loadable.Ready)?.value?.all().orEmpty()
}

internal class ManagementHomeViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val state = MutableStateFlow(ManagementHomeUiState(selectedKey = saved[KEY_SUBJECT] ?: repository.selectedSubjectKey))
    val uiState: StateFlow<ManagementHomeUiState> = state.asStateFlow()

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
        saved[KEY_SUBJECT] = subject.key
        repository.selectedSubjectKey = subject.key
        state.update { it.copy(selectedKey = subject.key) }
    }

    private fun show(subjects: ManagementSubjects) {
        val key = subjects.find(state.value.selectedKey)?.key ?: subjects.all().firstOrNull()?.key
        repository.selectedSubjectKey = key
        state.update { it.copy(subjects = if (subjects.all().isEmpty()) Loadable.Empty else Loadable.Ready(subjects), selectedKey = key) }
    }

    companion object { const val KEY_SUBJECT = "subject" }
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
    val uriHandler = LocalUriHandler.current
    val subject = state.selected
    val hasCheckinDates = (context.session.user.managementCounts()?.checkin ?: 0) > 0
    val subtitle = subject?.let { current ->
        listOfNotNull(subjectRoleLabel(current)?.let { stringResource(it) }, current.cityName).joinToString(" · ")
    }
    ManagementScaffold(
        title = subject?.name ?: stringResource(R.string.management_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subtitle,
        subjects = state.all,
        selectedKey = state.selectedKey,
        onSelectSubject = viewModel::select,
    ) {
        LoadState(state.subjects, onRetry = viewModel::load, emptyText = stringResource(R.string.management_empty)) {
            val current = subject ?: return@LoadState
            val areas = availableAreas(current, hasCheckinDates)
            Text(stringResource(R.string.management_home_areas), style = MaterialTheme.typography.titleLarge)
            Column {
                areas.forEach { area ->
                    ManagementRow(stringResource(area.title), stringResource(area.help), onClick = { open(context, area) })
                }
            }
            if (areas.isEmpty()) Text(stringResource(R.string.management_home_no_areas), color = Muted)
            Spacer(Modifier.height(4.dp))
            Text(stringResource(R.string.management_home_more_web), color = Muted)
            OutlinedButton(
                onClick = { uriHandler.openUri(BuildConfig.API_BASE_URL.substringBefore("/api/") + "/" + current.webPath) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.management_home_open_web)) }
        }
    }
}

/** Ogni area porta alla sua prima schermata (`ManagementArea.entry`). */
private fun open(context: ManagementGraphContext, area: ManagementArea) {
    area.entry?.let { context.navController.navigate(it) }
}
