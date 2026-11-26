package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.prepareImage
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.imageErrorText
import it.fabiodalez.incitta.ui.management.common.rememberImagePicker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/*
 * «Nuovo evento» e «Modifica evento» della redazione (R-EV-05, 06): le otto
 * schede del pannello (Cosa, Quando, Dove, Biglietti, Prima di andare,
 * Contenuti e SEO, Extra, Pubblicazione) e un pulsante solo che salva il
 * modulo intero, come «Crea» e «Salva» del pannello.
 */

/** Le etichette delle schede, nell'ordine di `EditorTab`. */
internal val EDITOR_TAB_LABELS = mapOf(
    EditorTab.What to R.string.editorial_ee_tab_what,
    EditorTab.When to R.string.editorial_ee_tab_when,
    EditorTab.Where to R.string.editorial_ee_tab_where,
    EditorTab.Tickets to R.string.editorial_ee_tab_tickets,
    EditorTab.BeforeGoing to R.string.editorial_ee_tab_before_going,
    EditorTab.Content to R.string.editorial_ee_tab_content,
    EditorTab.Extra to R.string.editorial_ee_tab_extra,
    EditorTab.Publish to R.string.editorial_ee_tab_publish,
)

/** Le azioni della schermata che escono dal modulo: le date, la biglietteria di una data. */
internal class EditorLinks(
    val onDates: (Long) -> Unit = {},
    val onOpenInApp: (String) -> Unit = {},
    /* «Anteprima grafica social» della testata (R-EV-26): lo studio social della redazione, sul sito finché l'app non lo ha (R9). `null` a chi non lo usa. */
    val onSocial: ((Long) -> Unit)? = null,
    /* «Crea caratteristica» di «Prima di andare»: il modulo della caratteristica nel catalogo (R11), che torna qui con la voce nuova già scelta. */
    val onCreateFeature: (() -> Unit)? = null,
)

@Composable
internal fun EventEditorScreen(
    viewModel: EventEditorViewModel,
    padding: androidx.compose.foundation.layout.PaddingValues,
    onBack: () -> Unit,
    onCreated: (Long) -> Unit,
    links: EditorLinks,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val appContext = LocalContext.current
    val scope = rememberCoroutineScope()
    val imageError = { e: ImageUploadException -> viewModel.imageError(appContext.getString(imageErrorText(e.reason))) }
    fun pick(onPicked: (PreparedImage) -> Unit): (android.net.Uri) -> Unit = { uri ->
        scope.launch {
            try { onPicked(withContext(Dispatchers.IO) { prepareImage(appContext, uri) }) } catch (e: ImageUploadException) { imageError(e) }
        }
    }
    val pickPoster = rememberImagePicker(pick(viewModel::uploadPoster))
    val pickGallery = rememberImagePicker(pick(viewModel::addGalleryPhoto))
    LaunchedEffect(state.createdId) { state.createdId?.let { viewModel.consumeCreated(); onCreated(it) } }

    ManagementScaffold(
        title = state.values?.title?.takeIf { it.isNotBlank() } ?: stringResource(if (state.creating) R.string.editorial_ee_new_title else R.string.editorial_ee_edit_title),
        padding = padding,
        onBack = onBack,
        subtitle = state.values?.status?.label,
        busy = state.busy || state.loading,
    ) {
        when {
            state.loading && state.options == null -> Unit
            state.options == null -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(state.loadError ?: "", color = MaterialTheme.colorScheme.error)
                OutlinedButton(onClick = viewModel::load, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
            }
            else -> EditorBody(state, viewModel, pickPoster, pickGallery, links)
        }
    }
}

@Composable
private fun EditorBody(state: EventEditorUiState, viewModel: EventEditorViewModel, pickPoster: () -> Unit, pickGallery: () -> Unit, links: EditorLinks) {
    val options = state.options ?: return
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        val general = errors.general ?: if (errors.fields.isNotEmpty()) stringResource(R.string.editorial_ee_fix) else null
        general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        if (!state.creating && state.values?.abilities?.dates == true) {
            OutlinedButton(onClick = { links.onDates(state.values.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_ee_open_dates))
            }
        }
        val values = state.values
        if (values != null && links.onSocial != null) {
            OutlinedButton(onClick = { links.onSocial.invoke(values.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_ee_social))
            }
        }
        ScrollableTabRow(selectedTabIndex = state.tab.ordinal, edgePadding = 0.dp) {
            EditorTab.entries.forEach { tab ->
                val label = stringResource(EDITOR_TAB_LABELS.getValue(tab))
                val marked = tabHasError(tab, errors.fields.keys)
                Tab(
                    selected = state.tab == tab,
                    onClick = { viewModel.selectTab(tab) },
                    modifier = Modifier.heightIn(min = 48.dp).testTag("editor-tab-${tab.name}"),
                    text = { Text(if (marked) "$label •" else label, color = if (marked) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface) },
                )
            }
        }
        when (state.tab) {
            EditorTab.What -> WhatTab(state, options, viewModel, pickPoster, pickGallery)
            EditorTab.When -> WhenTab(state, options, viewModel, links)
            EditorTab.Where -> WhereTab(state, options, viewModel)
            EditorTab.Tickets -> TicketsTab(state, options, viewModel, links)
            EditorTab.BeforeGoing -> {
                it.fabiodalez.incitta.ui.management.events.BeforeGoingSection(state.draft.base, state.catalog) { change -> viewModel.editBase(change) }
                val createFeature = links.onCreateFeature
                if (options.can.createFeature && createFeature != null) {
                    OutlinedButton(onClick = createFeature, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("editor-create-feature")) {
                        Text(stringResource(R.string.editorial_ee_create_feature))
                    }
                    Text(stringResource(R.string.editorial_ee_create_feature_help), style = MaterialTheme.typography.bodySmall)
                }
            }
            EditorTab.Content -> ContentTab(state, options, viewModel)
            EditorTab.Extra -> ExtraTab(state, options, viewModel)
            EditorTab.Publish -> PublishTab(state, options, viewModel)
        }
        Button(enabled = !state.busy, onClick = viewModel::save, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("editor-save")) {
            Text(stringResource(if (state.creating) R.string.editorial_ee_create else R.string.editorial_ee_save))
        }
    }
}

/** Vero se un errore del server cade in un campo della scheda: l'etichetta lo segnala. */
internal fun tabHasError(tab: EditorTab, keys: Collection<String>): Boolean =
    keys.any { key -> EventEditorViewModel.tabFor(it.fabiodalez.incitta.ui.management.common.FormErrors(fields = mapOf(key to ""))) == tab }
