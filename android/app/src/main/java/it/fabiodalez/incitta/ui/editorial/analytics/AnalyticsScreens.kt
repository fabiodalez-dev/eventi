package it.fabiodalez.incitta.ui.editorial.analytics

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.AnalyticsProfileChoice
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialReportQuery
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.EventChoiceGroups
import it.fabiodalez.incitta.data.management.SubjectLink
import it.fabiodalez.incitta.data.management.formatMetric
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.analytics.AnalyticsSectionTitle
import it.fabiodalez.incitta.ui.management.analytics.ChannelOption
import it.fabiodalez.incitta.ui.management.analytics.ChartSection
import it.fabiodalez.incitta.ui.management.analytics.CollapsibleSection
import it.fabiodalez.incitta.ui.management.analytics.ConsentCard
import it.fabiodalez.incitta.ui.management.analytics.DateField
import it.fabiodalez.incitta.ui.management.analytics.EmptyAnalytics
import it.fabiodalez.incitta.ui.management.analytics.ExportButtons
import it.fabiodalez.incitta.ui.management.analytics.LineChart
import it.fabiodalez.incitta.ui.management.analytics.LineSeries
import it.fabiodalez.incitta.ui.management.analytics.Pager
import it.fabiodalez.incitta.ui.management.analytics.PeriodChips
import it.fabiodalez.incitta.ui.management.analytics.StatCards
import it.fabiodalez.incitta.ui.management.analytics.TableContent
import it.fabiodalez.incitta.ui.management.analytics.ValueLine
import it.fabiodalez.incitta.ui.management.analytics.longDate
import it.fabiodalez.incitta.ui.management.analytics.periodText
import it.fabiodalez.incitta.ui.management.common.EventChoiceRow
import it.fabiodalez.incitta.ui.management.common.EventPickerSheet
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay

/*
 * Le schermate di R10. Grafici, riquadri, tabelle e file sono quelli delle
 * statistiche dell'app di gestione (`ui/management/analytics`): la forma dei
 * dati è la stessa, cambia chi la chiede. Le schermate sono divise in una
 * parte con lo stato (che legge il ViewModel) e una senza, che i test
 * strumentati disegnano da sole.
 */

// --------------------------------------------------------------------- menu

/** La prima schermata dell'area: le voci del gruppo «Analisi» del pannello che il ruolo vede. */
@Composable
internal fun AnalyticsHubScreen(viewModel: AnalyticsHubViewModel, title: String, padding: PaddingValues, onBack: () -> Unit, onOpen: (AnalyticsSection) -> Unit) {
    val desk by viewModel.desk.collectAsStateWithLifecycle()
    AnalyticsHub(desk, title, padding, onBack, onOpen)
}

@Composable
internal fun AnalyticsHub(desk: EditorialDesk?, title: String, padding: PaddingValues, onBack: () -> Unit, onOpen: (AnalyticsSection) -> Unit) {
    ManagementScaffold(title = title, padding = padding, onBack = onBack, subtitle = stringResource(R.string.editorial_analytics_hub_intro)) {
        if (desk == null) {
            LoadState<Unit>(Loadable.Loading, onRetry = {}) {}
        } else {
            val sections = analyticsSections(desk.can)
            sections.forEach { section -> ManagementRow(stringResource(section.title), stringResource(section.help), onClick = { onOpen(section) }) }
            if (sections.isEmpty()) Text(stringResource(R.string.editorial_analytics_nothing), color = Muted)
        }
    }
}

// ------------------------------------------------------ «Statistiche in dettaglio»

/**
 * «Statistiche in dettaglio» del pannello della redazione e la pagina di un
 * soggetto: le stesse sezioni della vista del pannello, nello stesso ordine.
 * `onOpen` apre un evento, un locale o un organizzatore; `onReplace` apre un
 * altro evento al posto di questo («Guarda un altro evento»).
 */
@Composable
internal fun EditorialReportScreen(
    viewModel: EditorialReportViewModel,
    padding: PaddingValues,
    onBack: () -> Unit,
    onOpen: (SubjectLink, EditorialReportQuery) -> Unit,
    onReplace: (Long, EditorialReportQuery) -> Unit,
    onOverview: (EditorialReportQuery) -> Unit,
    onTable: (String, EditorialReportQuery) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val cacheDir = LocalContext.current.cacheDir
    var filtersOpen by rememberSaveable { mutableStateOf(false) }
    var pickOther by rememberSaveable { mutableStateOf(false) }
    EditorialReport(
        state = state,
        padding = padding,
        onBack = onBack,
        onRetry = viewModel::load,
        onPeriod = viewModel::setPeriod,
        onFilters = { filtersOpen = true },
        onReset = viewModel::reset,
        onOpen = { onOpen(it, state.query.periodAndChannel()) },
        onOverview = { onOverview(state.query.periodAndChannel()) },
        onOtherEvent = { pickOther = true },
        onTable = { onTable(it, state.query) },
        onExport = { viewModel.export("events", it, cacheDir) },
    )
    val report = (state.report as? Loadable.Ready)?.value
    if (filtersOpen && report != null) {
        EditorialFiltersSheet(
            report = report,
            query = state.query,
            labels = state.labels,
            detail = state.target != null,
            searchEvents = viewModel::searchEvents,
            searchProfiles = viewModel::searchProfiles,
            onApply = { query, labels -> filtersOpen = false; viewModel.apply(query, labels) },
            onDismiss = { filtersOpen = false },
        )
    }
    if (pickOther) {
        EventPickerSheet(
            search = { viewModel.searchEvents(it, null, null) },
            title = stringResource(R.string.analytics_other_event),
            hint = stringResource(R.string.analytics_other_event_hint),
            excludeId = state.target?.id,
            onPick = { choice -> pickOther = false; onReplace(choice.id, state.query.periodAndChannel()) },
            onDismiss = { pickOther = false },
        )
    }
}

@Composable
internal fun EditorialReport(
    state: EditorialReportUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onPeriod: (String) -> Unit,
    onFilters: () -> Unit,
    onReset: () -> Unit,
    onOpen: (SubjectLink) -> Unit,
    onOverview: () -> Unit,
    onOtherEvent: () -> Unit,
    onTable: (String) -> Unit,
    onExport: (String) -> Unit,
) {
    val report = (state.report as? Loadable.Ready)?.value
    ManagementScaffold(
        title = report?.subject?.title ?: stringResource(R.string.editorial_analytics_report_title),
        padding = padding,
        onBack = onBack,
        subtitle = report?.let { periodText(it) },
        busy = state.refreshing,
    ) {
        LoadState(state.report, onRetry = onRetry) { data ->
            ReportBody(data, state, onPeriod, onFilters, onReset, onOpen, onOverview, onOtherEvent, onTable, onExport)
        }
    }
}

@Composable
private fun ReportBody(
    data: AnalyticsReport,
    state: EditorialReportUiState,
    onPeriod: (String) -> Unit,
    onFilters: () -> Unit,
    onReset: () -> Unit,
    onOpen: (SubjectLink) -> Unit,
    onOverview: () -> Unit,
    onOtherEvent: () -> Unit,
    onTable: (String) -> Unit,
    onExport: (String) -> Unit,
) {
    val detail = state.target != null
    val header = data.subject
    Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
        if (detail) OutlinedButton(onClick = onOverview, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_back_overview)) }

        // La testata del soggetto, come `analytics-subject.blade.php`.
        val watched = header?.event
        if (header != null && watched != null) {
            AnalyticsSectionTitle(stringResource(R.string.analytics_watching))
            EventChoiceRow(watched)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                header.related.forEach { link -> OutlinedButton(onClick = { onOpen(link) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(link.label) } }
            }
            Button(onClick = onOtherEvent, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_other_event)) }
        }
        header?.let { subject ->
            CollapsibleSection("editorial-subject-info-${subject.type}-${subject.id}", stringResource(R.string.analytics_information), initiallyOpen = subject.type != "event") {
                subject.information.forEach { info ->
                    Column(
                        Modifier.fillMaxWidth().then(info.link?.let { link -> Modifier.heightIn(min = 48.dp).clickable(role = Role.Button) { onOpen(link) } } ?: Modifier)
                            .semantics(mergeDescendants = true) {},
                    ) {
                        Text(info.label, color = Muted, style = MaterialTheme.typography.labelLarge)
                        Text(info.value, color = if (info.link != null) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                    }
                }
                subject.description?.takeIf { it.isNotBlank() }?.let {
                    Text(stringResource(R.string.analytics_description), style = MaterialTheme.typography.titleSmall)
                    Text(it)
                }
            }
            if (subject.type != "event" && subject.profileReport.isNotEmpty()) {
                AnalyticsSectionTitle(stringResource(R.string.analytics_profile_report), stringResource(R.string.analytics_profile_report_hint))
                val unavailable = stringResource(R.string.analytics_unavailable)
                subject.profileReport.forEach { metric -> ValueLine(metric.label, formatMetric(metric.value, unavailable)) }
            }
        }

        // Filtri attivi e «Azzera filtri».
        Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(stringResource(R.string.analytics_active_filters), style = MaterialTheme.typography.titleSmall, modifier = Modifier.semantics { heading() })
                data.activeFilters.forEach { filter ->
                    ValueLine(filter.label, if (filter.key == "from" || filter.key == "until") longDate(filter.value) ?: filter.value else filter.value)
                }
                OutlinedButton(onClick = onReset, modifier = Modifier.heightIn(min = 48.dp)) {
                    Icon(Icons.Outlined.Close, contentDescription = null)
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(if (detail) R.string.analytics_reset_detail else R.string.analytics_reset))
                }
            }
        }

        if (!detail) Text(stringResource(R.string.analytics_report_intro))
        PeriodChips(data.periods, data.period, enabled = !state.refreshing, onSelect = onPeriod)
        ConsentCard()

        AnalyticsSectionTitle(stringResource(R.string.analytics_filters), stringResource(if (detail) R.string.analytics_filters_hint else R.string.editorial_analytics_filters_hint))
        val active = state.query.activeFilters
        Button(onClick = onFilters, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(if (active > 0) stringResource(R.string.editorial_analytics_filters_count, active) else stringResource(R.string.analytics_filters))
        }
        Text(stringResource(R.string.analytics_channel_scope), color = Muted, style = MaterialTheme.typography.bodySmall)
        if (state.refreshing) Text(stringResource(R.string.analytics_loading), color = Muted)

        AnalyticsSectionTitle(stringResource(R.string.analytics_in_short), data.context)
        StatCards(data.cards)
        if (data.empty) EmptyAnalytics()

        data.selectedEvent?.let { selected ->
            AnalyticsSectionTitle(selected.title, selected.subtitle)
            selected.facts.forEach { ValueLine(it.label, it.value) }
        }

        val points = data.series.points
        val monthly = data.series.granularity == "month"
        AnalyticsSectionTitle(stringResource(R.string.analytics_visits_chart), stringResource(if (monthly) R.string.analytics_chart_month else R.string.analytics_chart_day))
        LineChart("editorial-report-visits", stringResource(R.string.analytics_visits_chart), points.map { it.date },
            listOf(LineSeries(data.seriesLabels["views"] ?: stringResource(R.string.analytics_event_views), points.map { it.views })),
            dateHeader = stringResource(if (monthly) R.string.analytics_month else R.string.analytics_day))
        AnalyticsSectionTitle(stringResource(R.string.analytics_shares_chart), stringResource(R.string.analytics_shares_chart_hint))
        LineChart("editorial-report-shares", stringResource(R.string.analytics_shares_chart), points.map { it.date },
            listOf(
                LineSeries(data.seriesLabels["short_shares"].orEmpty(), points.map { it.shortShares }),
                LineSeries(data.seriesLabels["short_clicks"].orEmpty(), points.map { it.shortClicks }),
            ),
            dateHeader = stringResource(if (monthly) R.string.analytics_month else R.string.analytics_day))

        data.charts.forEach { chart -> ChartSection(chart, onOpen) }

        AnalyticsSectionTitle(stringResource(R.string.analytics_tables), stringResource(R.string.analytics_tables_hint))
        data.datasets.forEach { dataset ->
            ManagementRow(stringResource(R.string.analytics_dataset_count, dataset.label, dataset.count), null, onClick = { onTable(dataset.key) })
        }
        ExportButtons(state.export, csv = false, onExport = onExport)

        CollapsibleSection("editorial-report-about", stringResource(R.string.analytics_about), initiallyOpen = false) {
            Text(stringResource(R.string.analytics_about_data_definitions))
            Text(stringResource(R.string.analytics_not_collected))
            Text(stringResource(R.string.analytics_share_notes))
        }
    }
}

/**
 * «Filtri» del pannello della redazione: locale, organizzatore, evento (solo
 * nella pagina d'insieme), canale e due date. Cambiare locale o
 * organizzatore toglie l'evento scelto, come `updatedFilters` del pannello;
 * toccare una data passa alle date scelte a mano.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun EditorialFiltersSheet(
    report: AnalyticsReport,
    query: EditorialReportQuery,
    labels: FilterLabels,
    detail: Boolean,
    searchEvents: suspend (String, Long?, Long?) -> EventChoiceGroups,
    searchProfiles: suspend (String, String) -> List<AnalyticsProfileChoice>,
    onApply: (EditorialReportQuery, FilterLabels) -> Unit,
    onDismiss: () -> Unit,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var venue by remember { mutableStateOf(query.venue?.let { AnalyticsProfileChoice(it, labels.venue.orEmpty()) }) }
    var organizer by remember { mutableStateOf(query.organizer?.let { AnalyticsProfileChoice(it, labels.organizer.orEmpty()) }) }
    var event by remember { mutableStateOf(query.event) }
    var eventTitle by remember { mutableStateOf(labels.event ?: report.selectedEvent?.title) }
    var channel by remember { mutableStateOf(query.channel) }
    var from by remember { mutableStateOf(if (query.custom) query.from else report.from) }
    var until by remember { mutableStateOf(if (query.custom) query.until else report.until) }
    var datesTouched by remember { mutableStateOf(query.custom) }
    var picking by remember { mutableStateOf<String?>(null) }
    val invalid = datesTouched && from != null && until != null && from!! > until!!
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.analytics_filters), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            if (!detail) {
                FilterChoice(
                    label = stringResource(R.string.editorial_analytics_filter_venue),
                    value = venue?.name?.ifBlank { null } ?: stringResource(R.string.editorial_analytics_filter_venue_all),
                    chosen = venue != null,
                    onChoose = { picking = "venues" },
                    onClear = { venue = null; event = null; eventTitle = null },
                )
                FilterChoice(
                    label = stringResource(R.string.editorial_analytics_filter_organizer),
                    value = organizer?.name?.ifBlank { null } ?: stringResource(R.string.editorial_analytics_filter_organizer_all),
                    chosen = organizer != null,
                    onChoose = { picking = "organizers" },
                    onClear = { organizer = null; event = null; eventTitle = null },
                )
                FilterChoice(
                    label = stringResource(R.string.analytics_filter_event),
                    value = if (event == null) stringResource(R.string.analytics_filter_event_all) else eventTitle ?: stringResource(R.string.analytics_filter_event),
                    chosen = event != null,
                    onChoose = { picking = "event" },
                    onClear = { event = null; eventTitle = null },
                )
            }
            Text(stringResource(R.string.analytics_filter_channel), style = MaterialTheme.typography.titleSmall)
            Column {
                ChannelOption(stringResource(R.string.analytics_filter_channel_all), channel == null) { channel = null }
                report.channels.forEach { option -> ChannelOption(option.label, channel == option.value) { channel = option.value } }
            }
            DateField(stringResource(R.string.analytics_filter_from), from, allowEmpty = true) { from = it; datesTouched = true }
            DateField(stringResource(R.string.analytics_filter_until), until, allowEmpty = false) { until = it; datesTouched = true }
            if (invalid) Text(stringResource(R.string.analytics_filter_dates_invalid), color = MaterialTheme.colorScheme.error)
            Text(stringResource(R.string.analytics_channel_scope), color = Muted, style = MaterialTheme.typography.bodySmall)
            Button(
                enabled = !invalid && (!datesTouched || until != null),
                onClick = {
                    val dated = if (datesTouched) query.copy(period = "custom", from = from, until = until) else query
                    onApply(
                        dated.copy(venue = venue?.id, organizer = organizer?.id, event = event, channel = channel),
                        FilterLabels(venue = venue?.name, organizer = organizer?.name, event = eventTitle),
                    )
                },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.analytics_filter_apply)) }
        }
    }
    when (val type = picking) {
        "event" -> EventPickerSheet(
            search = { searchEvents(it, venue?.id, organizer?.id) },
            onPick = { choice -> event = choice.id; eventTitle = choice.title; picking = null },
            onDismiss = { picking = null },
        )
        "venues", "organizers" -> ProfilePickerSheet(
            title = stringResource(if (type == "venues") R.string.editorial_analytics_filter_venue else R.string.editorial_analytics_filter_organizer),
            search = { searchProfiles(type.orEmpty(), it) },
            onPick = { choice ->
                if (type == "venues") venue = choice else organizer = choice
                // Come nel pannello: cambiando locale o organizzatore l'evento scelto si toglie.
                event = null; eventTitle = null; picking = null
            },
            onDismiss = { picking = null },
        )
        else -> Unit
    }
}

@Composable
private fun FilterChoice(label: String, value: String, chosen: Boolean, onChoose: () -> Unit, onClear: () -> Unit) {
    Text(label, style = MaterialTheme.typography.titleSmall)
    Text(value)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(onClick = onChoose, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_analytics_filter_choose)) }
        if (chosen) TextButton(onClick = onClear, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_analytics_filter_clear)) }
    }
}

/** La scelta di un locale o di un organizzatore: al massimo 50 per nome, con la ricerca, come la tendina del pannello. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ProfilePickerSheet(
    title: String,
    search: suspend (String) -> List<AnalyticsProfileChoice>,
    onPick: (AnalyticsProfileChoice) -> Unit,
    onDismiss: () -> Unit,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var text by rememberSaveable { mutableStateOf("") }
    var result by remember { mutableStateOf<Loadable<List<AnalyticsProfileChoice>>>(Loadable.Loading) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(text, attempt) {
        if (text.isNotEmpty()) delay(300)
        result = Loadable.Loading
        result = try {
            search(text.trim()).let { if (it.isEmpty()) Loadable.Empty else Loadable.Ready(it) }
        } catch (e: CancellationException) { throw e } catch (e: Exception) { Loadable.Failed(requestFailureMessage(e)) }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text(stringResource(R.string.editorial_analytics_profile_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            LoadState(result, onRetry = { attempt++ }, emptyText = stringResource(R.string.editorial_analytics_profile_empty)) { choices ->
                LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    items(choices, key = { it.id }) { choice ->
                        Text(
                            choice.name,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.Button) { onPick(choice) }.padding(vertical = 12.dp),
                            style = MaterialTheme.typography.bodyLarge,
                        )
                        HorizontalDivider()
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ tabelle

/** Una tabella: ogni riga è una scheda con le colonne del pannello, venti per pagina, CSV ed Excel. */
@Composable
internal fun EditorialTableScreen(viewModel: EditorialTableViewModel, padding: PaddingValues, onBack: () -> Unit, onOpen: (SubjectLink, EditorialReportQuery) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val cacheDir = LocalContext.current.cacheDir
    EditorialTable(state, padding, onBack, onRetry = viewModel::load, onPage = viewModel::page,
        onOpen = { onOpen(it, viewModel.query.periodAndChannel()) }, onExport = { viewModel.export(it, cacheDir) })
}

@Composable
internal fun EditorialTable(
    state: EditorialTableUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onPage: (Int) -> Unit,
    onOpen: (SubjectLink) -> Unit,
    onExport: (String) -> Unit,
) {
    val table: AnalyticsTable? = (state.table as? Loadable.Ready)?.value
    ManagementScaffold(
        title = table?.label ?: stringResource(R.string.analytics_tables),
        padding = padding,
        onBack = onBack,
        subtitle = table?.takeIf { it.meta.total > 0 }?.let {
            val first = (it.meta.page - 1) * 20 + 1
            stringResource(R.string.analytics_table_page, first, first + it.rows.size - 1, it.meta.total)
        },
    ) {
        LoadState(state.table, onRetry = onRetry) { data ->
            TableContent(data, onOpen = onOpen)
            Pager(data.meta.page, data.meta.nextPage, enabled = true, onPage = onPage)
        }
        ExportButtons(state.export, csv = true, onExport = onExport)
    }
}
