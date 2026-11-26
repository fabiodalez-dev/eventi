package it.fabiodalez.incitta.ui.editorial.system

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialImportSource
import it.fabiodalez.incitta.data.editorial.ImportPreview
import it.fabiodalez.incitta.data.editorial.ImportRunRow
import it.fabiodalez.incitta.data.editorial.ImportRunStatusValue
import it.fabiodalez.incitta.data.editorial.ImportSourceFormOptions
import it.fabiodalez.incitta.data.editorial.MappingEntry
import it.fabiodalez.incitta.data.editorial.NotificationStatusValue
import it.fabiodalez.incitta.data.editorial.ScheduledNotificationRow
import it.fabiodalez.incitta.data.editorial.SchedulerOverviewData
import it.fabiodalez.incitta.data.editorial.ImportVenueChoice
import it.fabiodalez.incitta.data.editorial.mappingDraft
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.feedback.feedbackDate
import java.time.ZoneId
import kotlinx.coroutines.delay

/* Le schermate del gruppo «Sistema» (R12). Le azioni di ogni riga sono solo quelle che le `abilities` del server permettono. */

// --------------------------------------------------------------------- menu

@Composable
internal fun SystemHubScreen(viewModel: SystemHubViewModel, title: String, padding: PaddingValues, onBack: () -> Unit, onOpen: (SystemSection) -> Unit) {
    val desk by viewModel.desk.collectAsStateWithLifecycle()
    ManagementScaffold(title = title, padding = padding, onBack = onBack, subtitle = stringResource(R.string.system_hub_intro)) {
        val current = desk
        if (current == null) {
            LoadState<Unit>(Loadable.Loading, onRetry = viewModel::refresh) {}
        } else {
            val sections = systemSections(current.can)
            sections.forEach { section ->
                val badge = section.badge?.let(current::badge)?.takeIf { it > 0 }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { ManagementRow(stringResource(section.title), stringResource(section.help), onClick = { onOpen(section) }) }
                    if (badge != null) Badge(Modifier.padding(start = 8.dp)) { Text(stringResource(R.string.editorial_badge, badge)) }
                }
            }
            if (sections.isEmpty()) Text(stringResource(R.string.system_nothing_allowed), color = Muted)
        }
    }
}

// ----------------------------------------------------------------- in comune

/** Un ordinamento proposto in cima all'elenco: `value` è il `sort` del server (`null` = quello del pannello). */
internal data class SystemSort(val value: String?, val label: String)

@Composable
private fun SortChips(choices: List<SystemSort>, selected: String?, onSelect: (String?) -> Unit) {
    val label = stringResource(R.string.system_sort)
    LazyRow(Modifier.semantics { contentDescription = label }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(choices, key = { it.value ?: "default" }) { choice ->
            FilterChip(selected = selected == choice.value, onClick = { onSelect(choice.value) }, label = { Text(choice.label) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
}

@Composable
private fun Message(text: UiText?) {
    text?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
}

/** Le scelte di un elenco chiuso del server, o i valori grezzi se `options` non è arrivato. */
private fun choicesOf(options: ManagementOptions?, key: String, fallback: List<String>): List<OptionChoice> =
    options?.choices(key)?.takeIf { it.isNotEmpty() } ?: fallback.map { OptionChoice(it, it) }

@Composable
private fun FieldLabel(text: String) = Text(text, style = MaterialTheme.typography.labelLarge, color = Muted)

@Composable
private fun RowCard(onClick: (() -> Unit)? = null, clickLabel: String? = null, content: @Composable ColumnScope.() -> Unit) {
    val base = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant)
    val clickable = if (onClick != null) base.clickable(onClickLabel = clickLabel, role = Role.Button, onClick = onClick).heightIn(min = 48.dp) else base
    Column(clickable.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp), content = content)
}

/** Il distintivo dell'esito di un'esecuzione, con i colori del pannello: riuscita, parziale, non riuscita. */
@Composable
private fun RunStatus(status: String?, label: String?) {
    if (status.isNullOrBlank()) return
    val color = when (status) {
        ImportRunStatusValue.SUCCESS -> MaterialTheme.colorScheme.primary
        ImportRunStatusValue.FAILED -> MaterialTheme.colorScheme.error
        else -> MaterialTheme.colorScheme.tertiary
    }
    Text(label?.takeIf { it.isNotBlank() } ?: status, color = color, style = MaterialTheme.typography.labelLarge)
}

// ------------------------------------------------------------ sorgenti: elenco

@Composable
internal fun ImportSourcesScreen(viewModel: ImportSourcesViewModel, padding: PaddingValues, zone: ZoneId, canCreate: Boolean, onBack: () -> Unit, onOpen: (Long) -> Unit, onCreate: () -> Unit) {
    val state by viewModel.list.current.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ImportSourcesList(state, options, padding, zone, canCreate, onBack, onOpen, onCreate, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry)
}

@Composable
internal fun ImportSourcesList(
    state: PagedState<EditorialImportSource>,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    canCreate: Boolean,
    onBack: () -> Unit,
    onOpen: (Long) -> Unit,
    onCreate: () -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
) {
    val filters = listOf(
        FilterDefinition(ImportSourcesViewModel.FILTER_TYPE, stringResource(R.string.system_filter_type), choicesOf(options, "import_source_types", listOf("ics", "csv", "json", "rss", "api", "manual"))),
        FilterDefinition(ImportSourcesViewModel.FILTER_ACTIVE, stringResource(R.string.system_filter_active), listOf(OptionChoice("1", stringResource(R.string.system_yes)), OptionChoice("0", stringResource(R.string.system_no)))),
        FilterDefinition(ImportSourcesViewModel.FILTER_FAILED, stringResource(R.string.system_filter_failed), listOf(OptionChoice("1", stringResource(R.string.system_yes)))),
    )
    val sorts = listOf(SystemSort(null, stringResource(R.string.system_sort_last_run_recent)), SystemSort(ImportSourcesViewModel.SORT_OLDEST, stringResource(R.string.system_sort_last_run_oldest)))
    PagedListScreen(
        title = stringResource(R.string.system_imports_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = {},
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchable = false,
        emptyText = stringResource(R.string.system_imports_empty),
        header = {
            if (canCreate) Button(onClick = onCreate, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.Outlined.Add, contentDescription = null)
                Text(stringResource(R.string.system_imports_new), Modifier.padding(start = 8.dp))
            }
            SortChips(sorts, state.query.sort, onSort)
        },
    ) { source -> ImportSourceRow(source, zone, onClick = { onOpen(source.id) }) }
}

@Composable
private fun ImportSourceRow(source: EditorialImportSource, zone: ZoneId, onClick: () -> Unit) {
    RowCard(onClick = onClick, clickLabel = stringResource(R.string.system_import_open, source.label)) {
        Text(source.venue?.name ?: source.city?.name.orEmpty(), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        Text(listOfNotNull(source.typeLabel.ifBlank { source.type }, source.city?.name?.takeIf { source.venue != null }).joinToString(" · "), style = MaterialTheme.typography.labelLarge)
        source.url?.takeIf { it.isNotBlank() }?.let { Text(it, maxLines = 1, style = MaterialTheme.typography.bodySmall, color = Muted) }
        Text(feedbackDate(source.lastRunAt, zone)?.let { stringResource(R.string.system_import_last_run, it) } ?: stringResource(R.string.system_import_never_run), style = MaterialTheme.typography.bodySmall)
        RunStatus(source.lastStatus, source.lastStatusLabel)
        source.lastError?.takeIf { it.isNotBlank() }?.let { Text(it, maxLines = 2, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        if (!source.isActive) Text(stringResource(R.string.system_import_inactive), color = Muted, style = MaterialTheme.typography.labelSmall)
    }
}

// ----------------------------------------------------------- sorgenti: scheda

@Composable
internal fun ImportSourceDetailScreen(
    viewModel: ImportSourceDetailViewModel,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onEdit: () -> Unit,
    onPreview: () -> Unit,
    onRuns: () -> Unit,
    onWeb: (String) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ImportSourceDetail(state, padding, zone, onBack, viewModel::load, onEdit, onPreview, onRuns, onWeb, viewModel::toggleActive, viewModel::askRun, viewModel::askDelete)
    val current = (state.source as? Loadable.Ready)?.value
    when (state.confirm) {
        ImportConfirm.Run -> ConfirmDialog(
            title = stringResource(R.string.system_import_run_title),
            text = stringResource(R.string.system_import_run_text)
                + (if (current?.isActive == false) "\n\n" + stringResource(R.string.system_import_run_inactive) else "")
                + (state.confirmError?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.system_import_run),
            onConfirm = viewModel::confirm,
            onDismiss = viewModel::dismiss,
            enabled = !state.busy,
        )
        ImportConfirm.Delete -> ConfirmDialog(
            title = stringResource(R.string.system_import_delete_title),
            text = stringResource(R.string.system_import_delete_text) + (state.confirmError?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.system_import_delete),
            onConfirm = viewModel::confirm,
            onDismiss = viewModel::dismiss,
            destructive = true,
            enabled = !state.busy,
        )
        null -> Unit
    }
}

@Composable
internal fun ImportSourceDetail(
    state: ImportDetailUiState,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onEdit: () -> Unit,
    onPreview: () -> Unit,
    onRuns: () -> Unit,
    onWeb: (String) -> Unit,
    onToggleActive: () -> Unit,
    onRun: () -> Unit,
    onDelete: () -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.system_import_title), padding = padding, onBack = onBack, busy = state.busy) {
        LoadState(state.source, onRetry = onRetry, emptyText = stringResource(R.string.system_import_missing)) { source ->
            val can = abilitiesOf(source.abilities)
            Message(state.message)
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Text(source.venue?.name ?: source.city?.name.orEmpty(), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            Text(stringResource(if (source.isActive) R.string.system_import_active else R.string.system_import_inactive), color = if (source.isActive) MaterialTheme.colorScheme.primary else Muted)

            FieldLabel(stringResource(R.string.system_import_city)); Text(source.city?.name.orEmpty())
            FieldLabel(stringResource(R.string.system_import_venue)); Text(source.venue?.name ?: stringResource(R.string.system_import_no_venue))
            FieldLabel(stringResource(R.string.system_import_type)); Text(source.typeLabel.ifBlank { source.type })
            FieldLabel(stringResource(R.string.system_import_category)); Text(source.defaultCategory?.name ?: stringResource(R.string.system_import_no_category))
            FieldLabel(stringResource(R.string.system_import_url)); SelectionContainer { Text(source.url?.takeIf { it.isNotBlank() } ?: stringResource(R.string.system_import_none)) }
            FieldLabel(stringResource(R.string.system_import_mapping))
            val mapping = mappingDraft(source.mapping)
            if (mapping.entries.isEmpty() && mapping.preserved.isEmpty()) Text(stringResource(R.string.system_import_no_mapping), color = Muted)
            mapping.entries.forEach { Text("${it.key} = ${it.value}", fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall) }
            mapping.preserved.keys.forEach { Text("$it = …", fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall) }

            HorizontalDivider()
            FieldLabel(stringResource(R.string.system_import_last_status))
            Text(feedbackDate(source.lastRunAt, zone)?.let { stringResource(R.string.system_import_last_run, it) } ?: stringResource(R.string.system_import_never_run))
            RunStatus(source.lastStatus, source.lastStatusLabel)
            FieldLabel(stringResource(R.string.system_import_last_error))
            Text(source.lastError?.takeIf { it.isNotBlank() } ?: stringResource(R.string.system_import_none), color = if (source.lastError.isNullOrBlank()) Muted else MaterialTheme.colorScheme.error)

            HorizontalDivider()
            FieldLabel(stringResource(R.string.system_import_credentials))
            Text(stringResource(if (source.hasCredentials) R.string.system_import_credentials_set else R.string.system_import_credentials_unset))
            if (source.webPath.isNotBlank()) OutlinedButton(onClick = { onWeb(source.webPath) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
                Text(stringResource(R.string.system_import_open_web), Modifier.padding(start = 8.dp))
            }

            HorizontalDivider()
            if (!source.runnable) Text(stringResource(R.string.system_import_not_runnable), color = Muted)
            if (can["preview"] == true) OutlinedButton(onClick = onPreview, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.system_import_preview))
            }
            if (can["run"] == true) Button(onClick = onRun, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.system_import_run))
            }
            if (can["runs"] == true) OutlinedButton(onClick = onRuns, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.system_import_history))
            }
            if (can["update"] == true) {
                OutlinedButton(onClick = onEdit, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.system_import_edit))
                }
                OutlinedButton(onClick = onToggleActive, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(if (source.isActive) R.string.system_import_deactivate else R.string.system_import_activate))
                }
            }
            if (can["destroy"] == true) OutlinedButton(onClick = onDelete, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.system_import_delete), color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

// ----------------------------------------------------------- sorgenti: modulo

@Composable
internal fun ImportSourceFormScreen(viewModel: ImportSourceFormViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ImportSourceForm(
        state, padding, onBack,
        callbacks = ImportFormCallbacks(
            onRetry = viewModel::load,
            onCity = viewModel::setCity,
            onType = viewModel::setType,
            onCategory = viewModel::setCategory,
            onUrl = viewModel::setUrl,
            onActive = viewModel::setActive,
            onVenueQuery = viewModel::setVenueQuery,
            onVenueSearch = viewModel::searchVenues,
            onVenueChoose = viewModel::chooseVenue,
            onVenueClear = viewModel::clearVenue,
            onMappingAdd = viewModel::addMapping,
            onMappingChange = viewModel::setMapping,
            onMappingRemove = viewModel::removeMapping,
            onSave = viewModel::save,
        ),
    )
}

/** I gesti del modulo, in un oggetto solo: la schermata ne ha tanti. */
internal data class ImportFormCallbacks(
    val onRetry: () -> Unit = {},
    val onCity: (Long) -> Unit = {},
    val onType: (String) -> Unit = {},
    val onCategory: (Long?) -> Unit = {},
    val onUrl: (String) -> Unit = {},
    val onActive: (Boolean) -> Unit = {},
    val onVenueQuery: (String) -> Unit = {},
    val onVenueSearch: (String) -> Unit = {},
    val onVenueChoose: (ImportVenueChoice) -> Unit = {},
    val onVenueClear: () -> Unit = {},
    val onMappingAdd: () -> Unit = {},
    val onMappingChange: (Int, MappingEntry) -> Unit = { _, _ -> },
    val onMappingRemove: (Int) -> Unit = {},
    val onSave: () -> Unit = {},
)

@Composable
internal fun ImportSourceForm(state: ImportFormUiState, padding: PaddingValues, onBack: () -> Unit, callbacks: ImportFormCallbacks) {
    val title = stringResource(if (state.editing) R.string.system_import_edit_title else R.string.system_import_new_title)
    ManagementScaffold(title = title, padding = padding, onBack = onBack, busy = state.saving) {
        LoadState(state.options, onRetry = callbacks.onRetry) { options ->
            ImportFormFields(state, options, callbacks)
        }
    }
}

@Composable
private fun problemText(state: ImportFormUiState, field: String): String? =
    state.errors.field(field) ?: state.problems[field]?.let { stringResource(it) }

@Composable
private fun ImportFormFields(state: ImportFormUiState, options: ImportSourceFormOptions, callbacks: ImportFormCallbacks) {
    val draft = state.draft
    val visibleErrors = state.errors
    FormScaffold(errors = visibleErrors, busy = state.saving, submitLabel = stringResource(R.string.system_import_save), onSubmit = callbacks.onSave, submitEnabled = state.canSave) {
        if (state.saved != null) Text(stringResource(R.string.system_import_saved), color = MaterialTheme.colorScheme.primary)
        if (state.unchanged && state.saved == null) Text(stringResource(R.string.system_import_unchanged), color = Muted)

        // Città
        FieldLabel(stringResource(R.string.system_import_city))
        ChoiceButton(
            label = options.cities.firstOrNull { it.id == draft.cityId }?.name ?: stringResource(R.string.system_import_choose_city),
            choices = options.cities.map { it.id to it.name },
            onChoose = { it?.let(callbacks.onCity) },
            allowNone = false,
        )
        problemText(state, ImportField.CITY)?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        // Locale (facoltativo)
        FieldLabel(stringResource(R.string.system_import_venue))
        VenueField(state, callbacks)
        Text(stringResource(R.string.system_import_venue_hint), style = MaterialTheme.typography.bodySmall, color = Muted)
        state.errors.field(ImportField.VENUE)?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        // Tipo
        FieldLabel(stringResource(R.string.system_import_type))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            options.types.forEach { type ->
                FilterChip(selected = draft.type == type.value, onClick = { callbacks.onType(type.value) }, enabled = !state.saving,
                    label = { Text(type.label.ifBlank { type.value }) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
        problemText(state, ImportField.TYPE)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (options.types.firstOrNull { it.value == draft.type }?.runnable == false) Text(stringResource(R.string.system_import_not_runnable), style = MaterialTheme.typography.bodySmall, color = Muted)

        // Categoria predefinita: obbligatoria senza locale
        FieldLabel(stringResource(R.string.system_import_category))
        ChoiceButton(
            label = options.categories.firstOrNull { it.id == draft.categoryId }?.name ?: stringResource(R.string.system_import_choose_category),
            choices = options.categories.map { it.id to it.name },
            onChoose = callbacks.onCategory,
            allowNone = true,
        )
        problemText(state, ImportField.CATEGORY)?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        // Indirizzo
        val urlError = problemText(state, ImportField.URL)
        val urlEditable = importUrlEditable(state.original)
        OutlinedTextField(
            value = draft.url,
            onValueChange = callbacks.onUrl,
            label = { Text(stringResource(R.string.system_import_url)) },
            singleLine = true,
            enabled = !state.saving && urlEditable,
            readOnly = !urlEditable,
            isError = urlError != null,
            supportingText = { Text(urlError ?: stringResource(if (urlEditable) R.string.system_import_url_help else R.string.system_import_url_masked)) },
            modifier = Modifier.fillMaxWidth(),
        )

        // Mappatura
        FieldLabel(stringResource(R.string.system_import_mapping))
        Text(stringResource(R.string.system_import_mapping_help), style = MaterialTheme.typography.bodySmall, color = Muted)
        draft.mapping.entries.forEachIndexed { index, entry -> MappingRow(index, entry, !state.saving, callbacks) }
        if (draft.mapping.preserved.isNotEmpty()) Text(stringResource(R.string.system_import_mapping_preserved, draft.mapping.preserved.keys.joinToString(", ")), style = MaterialTheme.typography.bodySmall, color = Muted)
        OutlinedButton(onClick = callbacks.onMappingAdd, enabled = !state.saving, modifier = Modifier.heightIn(min = 48.dp)) {
            Icon(Icons.Outlined.Add, contentDescription = null)
            Text(stringResource(R.string.system_import_mapping_add), Modifier.padding(start = 8.dp))
        }
        problemText(state, ImportField.MAPPING)?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        // Attiva
        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.system_import_active_label), Modifier.weight(1f))
            Switch(checked = draft.active, onCheckedChange = callbacks.onActive, enabled = !state.saving)
        }

        // Credenziali: solo dal sito
        Text(stringResource(R.string.system_import_credentials_web), style = MaterialTheme.typography.bodySmall, color = Muted)
        state.errors.field("credentials")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Una scelta da un elenco (città, categoria): un pulsante con il valore e il menu delle voci. */
@Composable
private fun ChoiceButton(label: String, choices: List<Pair<Long, String>>, onChoose: (Long?) -> Unit, allowNone: Boolean) {
    var open by remember { mutableStateOf(false) }
    Box {
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(label, Modifier.fillMaxWidth()) }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            if (allowNone) DropdownMenuItem(text = { Text(stringResource(R.string.system_import_no_category)) }, onClick = { open = false; onChoose(null) }, modifier = Modifier.heightIn(min = 48.dp))
            choices.forEach { (id, name) ->
                DropdownMenuItem(text = { Text(name) }, onClick = { open = false; onChoose(id) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
}

@Composable
private fun VenueField(state: ImportFormUiState, callbacks: ImportFormCallbacks) {
    val chosen = state.draft.venue
    if (chosen != null) {
        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(chosen.name, Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge)
            IconButton(onClick = callbacks.onVenueClear, enabled = !state.saving, modifier = Modifier.size(48.dp)) {
                Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.system_import_venue_remove, chosen.name))
            }
        }
        return
    }
    val query = state.venueQuery
    val latestSearch by rememberUpdatedState(callbacks.onVenueSearch)
    LaunchedEffect(query) {
        delay(VENUE_SEARCH_PAUSE_MILLIS)
        latestSearch(query)
    }
    OutlinedTextField(
        value = query,
        onValueChange = callbacks.onVenueQuery,
        label = { Text(stringResource(R.string.system_import_venue_search)) },
        singleLine = true,
        enabled = !state.saving,
        modifier = Modifier.fillMaxWidth(),
    )
    if (state.venueSearching) Text(stringResource(R.string.system_import_venue_searching), color = Muted, style = MaterialTheme.typography.bodySmall)
    else if (query.isNotBlank() && state.venueResults.isEmpty()) Text(stringResource(R.string.system_import_venue_nothing), color = Muted, style = MaterialTheme.typography.bodySmall)
    state.venueResults.forEach { venue ->
        val label = listOfNotNull(venue.name, venue.city).joinToString(" · ")
        val choose = stringResource(R.string.system_import_venue_choose, venue.name)
        Text(label, Modifier.fillMaxWidth().clickable(onClickLabel = choose, role = Role.Button) { callbacks.onVenueChoose(venue) }.heightIn(min = 48.dp).padding(vertical = 12.dp))
    }
}

private const val VENUE_SEARCH_PAUSE_MILLIS = 350L

@Composable
private fun MappingRow(index: Int, entry: MappingEntry, enabled: Boolean, callbacks: ImportFormCallbacks) {
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(value = entry.key, onValueChange = { callbacks.onMappingChange(index, entry.copy(key = it)) }, label = { Text(stringResource(R.string.system_import_mapping_key)) },
                singleLine = true, enabled = enabled, modifier = Modifier.weight(1f))
            IconButton(onClick = { callbacks.onMappingRemove(index) }, enabled = enabled, modifier = Modifier.size(48.dp)) {
                Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.system_import_mapping_remove, entry.key.ifBlank { (index + 1).toString() }))
            }
        }
        OutlinedTextField(value = entry.value, onValueChange = { callbacks.onMappingChange(index, entry.copy(value = it)) }, label = { Text(stringResource(R.string.system_import_mapping_value)) },
            enabled = enabled, modifier = Modifier.fillMaxWidth())
        Row(Modifier.heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Checkbox(checked = entry.list, onCheckedChange = { callbacks.onMappingChange(index, entry.copy(list = it)) }, enabled = enabled)
            Text(stringResource(R.string.system_import_mapping_list))
        }
    }
}

// --------------------------------------------------------- sorgenti: anteprima

@Composable
internal fun ImportPreviewScreen(viewModel: ImportPreviewViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ImportPreviewContent(state, padding, onBack, viewModel::load)
}

@Composable
internal fun ImportPreviewContent(state: Loadable<ImportPreview>, padding: PaddingValues, onBack: () -> Unit, onRetry: () -> Unit) {
    ManagementScaffold(title = stringResource(R.string.system_preview_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.system_preview_intro)) {
        LoadState(state, onRetry = onRetry) { preview ->
            preview.timezone?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.system_preview_timezone, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
            when {
                preview.error != null -> Text(preview.error, color = MaterialTheme.colorScheme.error)
                preview.rows.isEmpty() -> Text(stringResource(R.string.system_preview_empty), color = Muted)
                else -> preview.rows.forEach { row ->
                    RowCard {
                        Text(row.whenText, style = MaterialTheme.typography.labelLarge)
                        Text(row.title, style = MaterialTheme.typography.titleMedium)
                        val notes = row.notes + if (row.cancelled) listOf(stringResource(R.string.system_preview_cancelled)) else emptyList()
                        if (notes.isNotEmpty()) Text(notes.joinToString(" · "), color = if (row.cancelled) MaterialTheme.colorScheme.error else Muted, style = MaterialTheme.typography.bodySmall)
                        Text(row.where ?: "—", color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------- sorgenti: storico

@Composable
internal fun ImportRunsScreen(viewModel: ImportRunsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val state by viewModel.list.current.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ImportRunsList(state, options, padding, zone, onBack, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry)
}

@Composable
internal fun ImportRunsList(
    state: PagedState<ImportRunRow>,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
) {
    val filters = listOf(FilterDefinition(ImportRunsViewModel.FILTER_STATUS, stringResource(R.string.system_runs_filter_status), choicesOf(options, "import_run_statuses", ImportRunStatusValue.all)))
    val sorts = listOf(SystemSort(null, stringResource(R.string.system_runs_sort_recent)), SystemSort(ImportRunsViewModel.SORT_OLDEST, stringResource(R.string.system_runs_sort_oldest)))
    PagedListScreen(
        title = stringResource(R.string.system_import_history),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = {},
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchable = false,
        emptyText = stringResource(R.string.system_runs_empty),
        header = {
            Text(stringResource(R.string.system_runs_intro), color = Muted, style = MaterialTheme.typography.bodySmall)
            SortChips(sorts, state.query.sort, onSort)
        },
    ) { run ->
        RowCard {
            feedbackDate(run.createdAt, zone)?.let { Text(it, style = MaterialTheme.typography.titleSmall) }
            RunStatus(run.status, run.statusLabel)
            Text(stringResource(R.string.system_runs_counts, run.createdCount, run.updatedCount, run.unchangedCount, run.excludedCount, run.cancelledCount), style = MaterialTheme.typography.bodySmall)
            if (run.errorCount > 0) Text(stringResource(R.string.system_runs_errors, run.errorCount), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
            run.message?.takeIf { it.isNotBlank() }?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        }
    }
}

// ------------------------------------------------------------ invii previsti

@Composable
internal fun ScheduledNotificationsScreen(viewModel: ScheduledNotificationsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    ScheduledNotificationsList(list, state, options, padding, zone, onBack, viewModel::search, viewModel::applyFilters, viewModel::sortBy, viewModel.list::loadMore, viewModel.list::retry, viewModel::askCancel)
    state.confirming?.let {
        ConfirmDialog(
            title = stringResource(R.string.system_notification_cancel_title),
            text = stringResource(R.string.system_notification_cancel_text) + (state.error?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.system_notification_cancel),
            onConfirm = viewModel::cancel,
            onDismiss = viewModel::dismiss,
            destructive = true,
            enabled = !state.busy,
        )
    }
}

@Composable
internal fun ScheduledNotificationsList(
    list: PagedState<ScheduledNotificationRow>,
    state: NotificationsUiState,
    options: ManagementOptions?,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onCancel: (ScheduledNotificationRow) -> Unit,
) {
    val filters = listOf(
        FilterDefinition(ScheduledNotificationsViewModel.FILTER_STATUS, stringResource(R.string.system_filter_status), choicesOf(options, "notification_statuses", NotificationStatusValue.all)),
        FilterDefinition(ScheduledNotificationsViewModel.FILTER_TYPE, stringResource(R.string.system_filter_type), choicesOf(options, "notification_types", emptyList()), searchable = true),
        FilterDefinition(ScheduledNotificationsViewModel.FILTER_DUE, stringResource(R.string.system_filter_due), listOf(OptionChoice("1", stringResource(R.string.system_yes)))),
    ).filter { it.options.isNotEmpty() }
    val sorts = listOf(SystemSort(null, stringResource(R.string.system_sort_send_latest)), SystemSort(ScheduledNotificationsViewModel.SORT_EARLIEST, stringResource(R.string.system_sort_send_earliest)))
    val cancelAction = listOf(RowAction("cancel", stringResource(R.string.system_notification_cancel), destructive = true))
    PagedListScreen(
        title = stringResource(R.string.system_notifications_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = filters,
        onFilters = onFilters,
        searchLabel = stringResource(R.string.system_notifications_search),
        emptyText = stringResource(R.string.system_notifications_empty),
        header = {
            Text(stringResource(R.string.system_notifications_lead), color = Muted, style = MaterialTheme.typography.bodySmall)
            Message(state.message)
            SortChips(sorts, list.query.sort, onSort)
        },
    ) { row ->
        RowCard {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(row.typeLabel.ifBlank { row.type }, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    feedbackDate(row.sendAt, zone)?.let { Text(stringResource(R.string.system_notification_send_at, it), style = MaterialTheme.typography.labelLarge) }
                }
                ActionMenu(cancelAction, abilitiesOf(row.abilities), onAction = { onCancel(row) }, subject = row.typeLabel.ifBlank { row.type })
            }
            Text(row.recipient?.let { stringResource(R.string.system_notification_recipient, it) } ?: stringResource(R.string.system_notification_no_recipient), style = MaterialTheme.typography.bodySmall)
            row.subject.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
            val statusColor = when (row.status) {
                NotificationStatusValue.PENDING -> MaterialTheme.colorScheme.tertiary
                NotificationStatusValue.SENT -> MaterialTheme.colorScheme.primary
                NotificationStatusValue.FAILED -> MaterialTheme.colorScheme.error
                else -> Muted
            }
            Text(row.statusLabel.ifBlank { row.status }, color = statusColor, style = MaterialTheme.typography.labelLarge)
            Text(stringResource(R.string.system_notification_channel, row.channelLabel.ifBlank { row.channel }, row.attempts), style = MaterialTheme.typography.bodySmall, color = Muted)
            feedbackDate(row.sentAt, zone)?.let { Text(stringResource(R.string.system_notification_sent_at, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
            row.reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.system_notification_reason, it), style = MaterialTheme.typography.bodySmall) }
            row.dedupeKey?.takeIf { it.isNotBlank() }?.let { SelectionContainer { Text(stringResource(R.string.system_notification_dedupe, it), style = MaterialTheme.typography.labelSmall, color = Muted) } }
        }
    }
}

// ---------------------------------------------------------------------- cron

@Composable
internal fun ScheduledOperationsScreen(viewModel: ScheduledOperationsViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onSocial: (() -> Unit)?) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ScheduledOperationsContent(state, padding, zone, onBack, viewModel::load, onSocial)
}

@Composable
internal fun ScheduledOperationsContent(state: Loadable<SchedulerOverviewData>, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onRetry: () -> Unit, onSocial: (() -> Unit)?) {
    ManagementScaffold(title = stringResource(R.string.system_operations_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.system_operations_intro)) {
        LoadState(state, onRetry = onRetry) { data ->
            Text(stringResource(R.string.system_operations_cron), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            if (data.installed.readable) {
                fun present(found: Boolean) = if (found) R.string.system_operations_in_crontab else R.string.system_operations_not_in_crontab
                Text(stringResource(R.string.system_operations_installed, stringResource(present(data.installed.scheduler)), stringResource(present(data.installed.worker))))
            } else Text(stringResource(R.string.system_operations_unreadable), color = MaterialTheme.colorScheme.error)
            FieldLabel(stringResource(R.string.system_operations_scheduler))
            CodeBlock(data.cron.scheduler)
            FieldLabel(stringResource(R.string.system_operations_worker))
            CodeBlock(data.cron.worker)
            if (data.queue.isNotBlank()) Text(stringResource(R.string.system_operations_queue, data.queue), style = MaterialTheme.typography.bodySmall)

            HorizontalDivider()
            Text(stringResource(R.string.system_operations_social), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.system_operations_social_intro), style = MaterialTheme.typography.bodySmall, color = Muted)
            if (onSocial != null) OutlinedButton(onClick = onSocial, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.system_operations_open_social)) }
            if (data.social.isEmpty()) Text(stringResource(R.string.system_operations_no_social), color = MaterialTheme.colorScheme.error)
            data.social.forEach { connection ->
                RowCard {
                    Text(stringResource(R.string.system_operations_social_city, connection.city ?: connection.cityId?.toString().orEmpty()), style = MaterialTheme.typography.titleSmall)
                    val verified = feedbackDate(connection.verifiedAt, zone)
                    Text(verified?.let { stringResource(R.string.system_operations_verified_at, it) } ?: stringResource(R.string.system_operations_to_verify), style = MaterialTheme.typography.bodySmall)
                    Text(stringResource(R.string.system_operations_telegram, stringResource(if (connection.telegramVerified) R.string.system_operations_verified else R.string.system_operations_to_verify)), style = MaterialTheme.typography.bodySmall)
                    Text(stringResource(if (connection.instagramEnabled) R.string.system_operations_instagram_on else R.string.system_operations_instagram_off), style = MaterialTheme.typography.bodySmall)
                    Text(if (connection.automatic) stringResource(R.string.system_operations_autopost_on, connection.publishTime.orEmpty()) else stringResource(R.string.system_operations_autopost_off), style = MaterialTheme.typography.bodySmall)
                }
            }

            HorizontalDivider()
            Text(stringResource(R.string.system_operations_tasks), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.system_operations_tasks_intro), style = MaterialTheme.typography.bodySmall, color = Muted)
            data.tasks.forEach { task ->
                RowCard {
                    Text(task.name, style = MaterialTheme.typography.titleSmall)
                    Text(task.explanation, style = MaterialTheme.typography.bodySmall)
                    Text(task.command, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.labelSmall, color = Muted)
                    Text(stringResource(R.string.system_operations_expression, task.expression), style = MaterialTheme.typography.bodySmall)
                    task.nextDueDate?.let { Text(stringResource(R.string.system_operations_next, it), style = MaterialTheme.typography.bodySmall) }
                    Text(stringResource(R.string.system_operations_last, task.lastFinished ?: stringResource(R.string.system_operations_never_finished)), style = MaterialTheme.typography.bodySmall)
                    Text(stringResource(R.string.system_operations_failed, task.lastFailed ?: stringResource(R.string.system_operations_never_failed)), style = MaterialTheme.typography.bodySmall,
                        color = if (task.lastFailed != null) MaterialTheme.colorScheme.error else Muted)
                    if (!task.monitored) Text(stringResource(R.string.system_operations_unmonitored), style = MaterialTheme.typography.labelSmall, color = Muted)
                }
            }
        }
    }
}

@Composable
private fun CodeBlock(text: String) {
    SelectionContainer {
        Text(text, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall,
            modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(12.dp))
    }
}
