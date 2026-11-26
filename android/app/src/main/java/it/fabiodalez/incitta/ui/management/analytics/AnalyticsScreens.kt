package it.fabiodalez.incitta.ui.management.analytics

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.AnalyticsOption
import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsSummary
import it.fabiodalez.incitta.data.management.AnalyticsTable
import it.fabiodalez.incitta.data.management.ChartRow
import it.fabiodalez.incitta.data.management.DateAnalyticsRow
import it.fabiodalez.incitta.data.management.EventShareLinks
import it.fabiodalez.incitta.data.management.OccurrenceAnalyticsReport
import it.fabiodalez.incitta.data.management.ReportChart
import it.fabiodalez.incitta.data.management.ReportQuery
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.SubjectLink
import it.fabiodalez.incitta.data.management.analyticsCellText
import it.fabiodalez.incitta.data.management.formatCount
import it.fabiodalez.incitta.data.management.formatMetric
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.EventChoiceRow
import it.fabiodalez.incitta.ui.management.common.EventPickerSheet
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

// ------------------------------------------------------------------- menu

/** Il menu dell'area: le pagine del gruppo «Statistiche» del pannello, i canali solo per il referente del locale. */
@Composable
internal fun AnalyticsHubScreen(viewModel: AnalyticsHubViewModel, context: ManagementGraphContext, navigate: (Any) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(title = stringResource(R.string.analytics_hub_title), padding = context.padding, onBack = context::back,
        subtitle = (state.subject as? Loadable.Ready)?.value?.name) {
        LoadState(state.subject, onRetry = viewModel::load, emptyText = stringResource(R.string.analytics_subject_missing)) { subject ->
            ManagementRow(stringResource(R.string.analytics_summary_title), stringResource(R.string.analytics_summary_help), onClick = { navigate(StatisticsRoute(subject.key)) })
            ManagementRow(stringResource(R.string.analytics_report_title), stringResource(R.string.analytics_report_help), onClick = { navigate(ShareAnalyticsRoute(subject.key)) })
            ManagementRow(
                stringResource(if (subject.kind == SubjectKind.Venue) R.string.analytics_profile_venue else R.string.analytics_profile_organizer),
                stringResource(R.string.analytics_profile_help),
                onClick = { navigate(SubjectAnalyticsRoute(subject = subject.key, type = if (subject.kind == SubjectKind.Venue) "venue" else "organizer", id = subject.id)) },
            )
            if (subject.kind == SubjectKind.Venue && subject.permissions.analyticsChannels) {
                ManagementRow(stringResource(R.string.analytics_channels_title), stringResource(R.string.analytics_channels_help), onClick = { navigate(AnalyticsChannelsRoute(subject.key)) })
            }
        }
    }
}

// ---------------------------------------------------------------- comuni

/** I pulsanti del periodo, come i pulsanti in cima alle pagine del pannello. */
@Composable
internal fun PeriodChips(periods: List<AnalyticsOption>, selected: String, enabled: Boolean, onSelect: (String) -> Unit) {
    Text(stringResource(R.string.analytics_period), style = MaterialTheme.typography.titleSmall)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        periods.forEach { option ->
            FilterChip(
                selected = option.value == selected,
                onClick = { onSelect(option.value) },
                enabled = enabled,
                label = { Text(option.label) },
                modifier = Modifier.heightIn(min = 48.dp),
            )
        }
    }
}

/** Il file pronto: «Apri» e «Condividi», come la biglietteria. */
@Composable
internal fun ExportResult(export: ExportState) {
    val context = LocalContext.current
    val chooser = stringResource(R.string.analytics_export_chooser)
    val noApp = stringResource(R.string.analytics_export_no_app)
    var failure by remember(export.file) { mutableStateOf<String?>(null) }
    export.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    failure?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    export.file?.let { file ->
        Text(stringResource(R.string.analytics_export_ready, file.file.name), color = MaterialTheme.colorScheme.primary)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = { if (!SharedFiles.open(context, file.file, file.mime, chooser)) failure = noApp }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_export_open)) }
            OutlinedButton(onClick = { if (!SharedFiles.share(context, file.file, file.mime, chooser)) failure = noApp }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_export_share)) }
        }
    }
}

@Composable
internal fun ExportButtons(export: ExportState, csv: Boolean, onExport: (String) -> Unit) {
    val busy = stringResource(R.string.analytics_export_busy)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (csv) OutlinedButton(enabled = export.busy == null, onClick = { onExport("csv") }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(if (export.busy == "csv") busy else stringResource(R.string.analytics_export_csv))
        }
        Button(enabled = export.busy == null, onClick = { onExport("xlsx") }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(if (export.busy == "xlsx") busy else stringResource(R.string.analytics_export_excel))
        }
    }
    Text(stringResource(R.string.analytics_export_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
    ExportResult(export)
}

/** Il vuoto del pannello: titolo e frase. */
@Composable
internal fun EmptyAnalytics() {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(stringResource(R.string.analytics_empty_title), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.analytics_empty_body), color = Muted)
    }
}

/** Avanti e indietro fra le pagine di un elenco del server. */
@Composable
internal fun Pager(page: Int, nextPage: Int?, enabled: Boolean, onPage: (Int) -> Unit) {
    if (page <= 1 && nextPage == null) return
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(enabled = enabled && page > 1, onClick = { onPage(page - 1) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_table_previous)) }
        OutlinedButton(enabled = enabled && nextPage != null, onClick = { nextPage?.let(onPage) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_table_next)) }
    }
}

// ---------------------------------------------------------------- «Statistiche»

/** La pagina «Statistiche» del pannello (W-ST-01). */
@Composable
internal fun StatisticsScreen(viewModel: StatisticsViewModel, context: ManagementGraphContext, navigate: (Any) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val summary = (state.summary as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.analytics_summary_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = summary?.let { stringResource(R.string.analytics_period_range, longDate(it.period.from) ?: it.period.from, longDate(it.period.until) ?: it.period.until) },
        busy = state.refreshing,
    ) {
        summary?.periods?.takeIf { it.isNotEmpty() }?.let { PeriodChips(it, state.period, enabled = !state.refreshing, onSelect = viewModel::setPeriod) }
        LoadState(state.summary, onRetry = viewModel::load, emptyText = stringResource(R.string.analytics_subject_missing)) { data ->
            SummaryContent(data, state, viewModel, onEvent = { id -> state.subject?.let { navigate(subjectRoute(it.key, "event", id, ReportQuery())) } },
                onShares = { state.subject?.let { navigate(ShareAnalyticsRoute(it.key)) } })
        }
    }
}

@Composable
private fun SummaryContent(data: AnalyticsSummary, state: StatisticsUiState, viewModel: StatisticsViewModel, onEvent: (Long) -> Unit, onShares: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
        AnalyticsSectionTitle(stringResource(R.string.analytics_in_short))
        StatCards(data.cards)
        ConsentCard()

        AnalyticsSectionTitle(stringResource(R.string.analytics_daily), stringResource(R.string.analytics_visits_hint))
        if (data.empty) EmptyAnalytics() else LineChart(
            key = "summary-daily",
            title = stringResource(R.string.analytics_daily),
            dates = data.series.map { it.date },
            series = listOf(
                LineSeries(stringResource(R.string.analytics_event_views), data.series.map { it.views }, data.cards.firstOrNull { it.key == "event_views" }?.value),
                LineSeries(stringResource(R.string.analytics_profile_views), data.series.map { it.profileViews }, data.cards.firstOrNull { it.key == "profile_views" }?.value),
            ),
        )
        if (data.empty) NumbersToggle("summary-daily-empty", listOf(stringResource(R.string.analytics_day), stringResource(R.string.analytics_profile_views), stringResource(R.string.analytics_event_views), stringResource(R.string.analytics_clicks)),
            data.series.map { listOf(axisDate(it.date), formatCount(it.profileViews), formatCount(it.views), formatCount(it.clicks)) })

        AnalyticsSectionTitle(stringResource(R.string.analytics_interactions), stringResource(R.string.analytics_interactions_hint))
        BarList(valuesAsRows(data.interactions))

        AnalyticsSectionTitle(stringResource(R.string.analytics_profile), stringResource(R.string.analytics_profile_hint))
        ValueList(data.profile)

        CollapsibleSection("summary-details", stringResource(R.string.analytics_details), stringResource(R.string.analytics_details_hint), initiallyOpen = false) {
            ValueList(data.details)
        }

        CollapsibleSection("summary-events", stringResource(R.string.analytics_per_event), stringResource(R.string.analytics_per_event_hint), initiallyOpen = data.events.meta.total > 0) {
            if (data.events.data.isEmpty()) Text(stringResource(R.string.analytics_per_event_empty), color = Muted)
            data.events.data.forEach { row ->
                Column(Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.Button) { onEvent(row.id) }.padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(row.title, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary)
                    Text(data.events.columns.joinToString(" · ") { column -> "${column.label}: ${formatCount(row.values[column.key] ?: 0)}" }, color = Muted, style = MaterialTheme.typography.bodySmall)
                }
                HorizontalDivider()
            }
            Pager(data.events.meta.page, data.events.meta.nextPage, enabled = !state.refreshing, onPage = viewModel::eventsPage)
        }

        CollapsibleSection("summary-paid", stringResource(R.string.analytics_paid), stringResource(R.string.analytics_paid_hint), initiallyOpen = data.paid.impressions > 0) {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                ValueLine(stringResource(R.string.analytics_impressions), formatCount(data.paid.impressions))
                ValueLine(stringResource(R.string.analytics_paid_clicks), formatCount(data.paid.clicks))
                ValueLine(stringResource(R.string.analytics_ctr), data.paid.ctr ?: "—")
            }
            if (data.paid.channels.isNotEmpty()) {
                BarList(data.paid.channels.map { ChartRow(name = "${it.label} · ${it.share}", value = it.clicks) })
                data.paid.breakdown.forEach { row ->
                    ValueLine(stringResource(R.string.analytics_paid_breakdown, row.channel, row.placement, row.page), formatCount(row.clicks))
                }
            }
        }

        AnalyticsSectionTitle(stringResource(R.string.analytics_shares_title), stringResource(R.string.analytics_shares_hint))
        OutlinedButton(onClick = onShares, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_shares_open)) }
        if (data.shares.data.isEmpty()) Text(stringResource(R.string.analytics_shares_empty), color = Muted)
        data.shares.data.forEach { row ->
            Column(Modifier.fillMaxWidth().semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(row.title, style = MaterialTheme.typography.titleSmall)
                row.occurrence?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
                Text("${row.channel} · ${row.url}", style = MaterialTheme.typography.bodySmall)
                Text(stringResource(R.string.analytics_share_counts, formatCount(row.shares), formatCount(row.clicks)), style = MaterialTheme.typography.bodySmall)
            }
            HorizontalDivider()
        }
        Pager(data.shares.meta.page, data.shares.meta.nextPage, enabled = !state.refreshing, onPage = viewModel::sharesPage)
        Text(stringResource(R.string.analytics_share_notes), color = Muted, style = MaterialTheme.typography.bodySmall)

        CollapsibleSection("summary-about", stringResource(R.string.analytics_about), initiallyOpen = false) {
            Text(stringResource(R.string.analytics_coverage))
            Text(stringResource(R.string.analytics_definitions_short))
        }
    }
}

@Composable
internal fun ValueLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth().semantics(mergeDescendants = true) {}, horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, modifier = Modifier.weight(1f))
        Text(value, fontWeight = FontWeight.SemiBold)
    }
}

// --------------------------------------- «Statistiche in dettaglio» e soggetto

/**
 * «Statistiche in dettaglio» (W-ST-02…04) e la pagina di un soggetto
 * (W-ST-05): le stesse sezioni della vista del pannello, nello stesso
 * ordine. `replace` apre un altro soggetto al posto di questo.
 */
@Composable
internal fun ReportScreen(viewModel: ReportViewModel, context: ManagementGraphContext, navigate: (Any) -> Unit, replace: (Any) -> Unit = navigate) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val report = (state.report as? Loadable.Ready)?.value
    val detail = state.target != null
    var filtersOpen by rememberSaveable { mutableStateOf(false) }
    var pickOther by rememberSaveable { mutableStateOf(false) }
    val key = state.subject?.key.orEmpty()
    val androidContext = LocalContext.current
    val openSubject: (SubjectLink) -> Unit = { link -> navigate(subjectRoute(key, link.type, link.id, state.query)) }
    ManagementScaffold(
        title = report?.subject?.title ?: stringResource(R.string.analytics_report_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = report?.let { periodText(it) },
        busy = state.refreshing,
    ) {
        LoadState(state.report, onRetry = viewModel::load, emptyText = stringResource(R.string.analytics_subject_missing)) { data ->
            ReportContent(
                data = data, state = state, detail = detail,
                onPeriod = viewModel::setPeriod,
                onFilters = { filtersOpen = true },
                onReset = viewModel::reset,
                onOpen = openSubject,
                onOverview = { navigate(overviewRoute(key, state.query)) },
                onOtherEvent = { pickOther = true },
                onTable = { dataset -> navigate(tableRoute(key, state.target?.type, state.target?.id, dataset, state.query)) },
                onExport = { viewModel.export("events", it, androidContext.cacheDir) },
                onChannels = { navigate(AnalyticsChannelsRoute(key)) },
                onDate = viewModel::openOccurrence,
                onRetryExtras = viewModel::loadEventExtras,
            )
        }
    }
    if (filtersOpen && report != null) {
        ReportFiltersSheet(
            report = report, query = state.query, eventTitle = state.eventTitle, detail = detail,
            search = viewModel::searchEvents,
            onApply = { query, title -> filtersOpen = false; viewModel.apply(query, title) },
            onDismiss = { filtersOpen = false },
        )
    }
    if (pickOther) {
        EventPickerSheet(
            search = viewModel::searchEvents,
            title = stringResource(R.string.analytics_other_event),
            hint = stringResource(R.string.analytics_other_event_hint),
            excludeId = state.target?.id,
            onPick = { choice -> pickOther = false; replace(subjectRoute(key, "event", choice.id, state.query)) },
            onDismiss = { pickOther = false },
        )
    }
    state.occurrence?.let { OccurrenceSheet(it, onRetry = viewModel::retryOccurrence, onDismiss = viewModel::closeOccurrence) }
}

@Composable
internal fun periodText(report: AnalyticsReport): String = when {
    report.from == null -> stringResource(R.string.analytics_period_until, longDate(report.until) ?: report.until)
    else -> stringResource(R.string.analytics_period_range, longDate(report.from) ?: report.from, longDate(report.until) ?: report.until)
}

@Composable
private fun ReportContent(
    data: AnalyticsReport,
    state: ReportUiState,
    detail: Boolean,
    onPeriod: (String) -> Unit,
    onFilters: () -> Unit,
    onReset: () -> Unit,
    onOpen: (SubjectLink) -> Unit,
    onOverview: () -> Unit,
    onOtherEvent: () -> Unit,
    onTable: (String) -> Unit,
    onExport: (String) -> Unit,
    onChannels: () -> Unit,
    onDate: (Long) -> Unit,
    onRetryExtras: () -> Unit,
) {
    val header = data.subject
    Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
        if (detail) OutlinedButton(onClick = onOverview, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_back_overview)) }

        // In testa si vede subito di quale evento si parla: locandina, titolo, data leggibile e locale.
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
            CollapsibleSection("subject-info-${subject.type}-${subject.id}", stringResource(R.string.analytics_information), initiallyOpen = subject.type != "event") {
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

        AnalyticsSectionTitle(stringResource(R.string.analytics_filters), stringResource(R.string.analytics_filters_hint))
        Button(onClick = onFilters, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_filters)) }
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
        LineChart("report-visits", stringResource(R.string.analytics_visits_chart), points.map { it.date },
            listOf(LineSeries(data.seriesLabels["views"] ?: stringResource(R.string.analytics_event_views), points.map { it.views })),
            dateHeader = stringResource(if (monthly) R.string.analytics_month else R.string.analytics_day))
        AnalyticsSectionTitle(stringResource(R.string.analytics_shares_chart), stringResource(R.string.analytics_shares_chart_hint))
        LineChart("report-shares", stringResource(R.string.analytics_shares_chart), points.map { it.date },
            listOf(
                LineSeries(data.seriesLabels["short_shares"].orEmpty(), points.map { it.shortShares }),
                LineSeries(data.seriesLabels["short_clicks"].orEmpty(), points.map { it.shortClicks }),
            ),
            dateHeader = stringResource(if (monthly) R.string.analytics_month else R.string.analytics_day))

        data.charts.forEach { chart -> ChartSection(chart, onOpen) }

        if (header?.type == "event") {
            EventDates(state.dates, onDate, onRetryExtras)
            EventShareLinksSection(state.shareLinks, onRetryExtras)
        }

        if (!detail && state.subject?.let { it.kind == SubjectKind.Venue && it.permissions.analyticsChannels } == true) {
            AnalyticsSectionTitle(stringResource(R.string.analytics_channels_title), stringResource(R.string.analytics_channels_lead))
            OutlinedButton(onClick = onChannels, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_channels_title)) }
        }

        AnalyticsSectionTitle(stringResource(R.string.analytics_tables), stringResource(R.string.analytics_tables_hint))
        data.datasets.forEach { dataset ->
            ManagementRow(stringResource(R.string.analytics_dataset_count, dataset.label, dataset.count), null, onClick = { onTable(dataset.key) })
        }
        ExportButtons(state.export, csv = false, onExport = onExport)

        CollapsibleSection("report-about", stringResource(R.string.analytics_about), initiallyOpen = false) {
            Text(stringResource(R.string.analytics_about_data_definitions))
            Text(stringResource(R.string.analytics_not_collected))
            Text(stringResource(R.string.analytics_share_notes))
        }
    }
}

/** Un grafico a barre con il suo titolo e la sua frase; richiudibile dove il pannello lo tiene chiuso. */
@Composable
internal fun ChartSection(chart: ReportChart, onOpen: (SubjectLink) -> Unit) {
    val (title, hint, open) = when (chart.key) {
        "events" -> Triple(R.string.analytics_chart_events, R.string.analytics_chart_events_hint, true)
        "channels" -> Triple(R.string.analytics_chart_channels, R.string.analytics_chart_channels_hint, false)
        "actions" -> Triple(R.string.analytics_chart_actions, R.string.analytics_chart_actions_hint, false)
        "venues" -> Triple(R.string.analytics_chart_venues, R.string.analytics_chart_venues_hint, true)
        "organizers" -> Triple(R.string.analytics_chart_organizers, R.string.analytics_chart_events_hint, true)
        "community" -> Triple(R.string.analytics_chart_community, R.string.analytics_chart_community_hint, false)
        "bookings" -> Triple(R.string.analytics_chart_bookings, R.string.analytics_chart_bookings_hint, false)
        "paid" -> Triple(R.string.analytics_chart_paid, R.string.analytics_chart_paid_hint, false)
        else -> return
    }
    CollapsibleSection("chart-${chart.key}", stringResource(title), stringResource(hint), initiallyOpen = open) {
        if (chart.totals.isNotEmpty()) ValueList(chart.totals)
        BarList(chart.rows, chart.secondaryLabel, onOpen = chart.link?.let { type -> { row: ChartRow -> row.id?.let { onOpen(SubjectLink(type, it)) } } })
    }
}

/** Le date dell'evento con i loro numeri (W-ST-07); toccandone una si apre il foglio della data (W-DT-08). */
@Composable
private fun EventDates(dates: Loadable<List<DateAnalyticsRow>>?, onDate: (Long) -> Unit, onRetry: () -> Unit) {
    dates ?: return
    AnalyticsSectionTitle(stringResource(R.string.analytics_dates_title), stringResource(R.string.analytics_dates_hint))
    LoadState(dates, onRetry = onRetry, emptyText = stringResource(R.string.analytics_dates_empty)) { rows ->
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            rows.forEach { row ->
                Column(
                    Modifier.fillMaxWidth()
                        .then(if (row.canViewReport) Modifier.heightIn(min = 48.dp).clickable(role = Role.Button, onClickLabel = stringResource(R.string.analytics_date_open)) { onDate(row.id) } else Modifier)
                        .padding(vertical = 6.dp)
                        .semantics(mergeDescendants = true) {},
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Text(dateTime(row.startsAt, row.timezone), style = MaterialTheme.typography.titleSmall, color = if (row.canViewReport) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                    Text(row.status.label, color = Muted, style = MaterialTheme.typography.bodySmall)
                    Text(stringResource(R.string.analytics_date_counts, formatCount(row.views), formatCount(row.clicks), formatCount(row.saves)), style = MaterialTheme.typography.bodySmall)
                }
                HorizontalDivider()
            }
        }
    }
}

/** I link brevi tracciati dell'evento, con copia, condivisione e QR (W-PB-05). */
@Composable
private fun EventShareLinksSection(links: Loadable<EventShareLinks>?, onRetry: () -> Unit) {
    links ?: return
    AnalyticsSectionTitle(stringResource(R.string.analytics_share_links_title), stringResource(R.string.analytics_share_links_hint))
    LoadState(links, onRetry = onRetry) { data ->
        if (!data.available) {
            Text(stringResource(R.string.analytics_share_links_unavailable), color = Muted)
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                data.links.forEach { link -> ShortLinkRow(link.label, link.url) }
            }
        }
    }
}

private val dateTimeFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)

/** «sab 22 nov 2026, 21:00» nel fuso della città. */
internal fun dateTime(iso: String, timezone: String?): String {
    val zone = timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: ZoneId.of("Europe/Rome")
    return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(dateTimeFormat) }.getOrDefault(iso)
}

/** Il foglio «Statistiche» di una data: tre numeri e gli ultimi trenta giorni, come il pannello laterale. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OccurrenceSheet(report: Loadable<OccurrenceAnalyticsReport>, onRetry: () -> Unit, onDismiss: () -> Unit) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            LoadState(report, onRetry = onRetry) { data ->
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(stringResource(R.string.analytics_date_heading, dateTime(data.startsAt, data.timezone)), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                    Text(data.event.title, color = Muted)
                    Text(data.note, style = MaterialTheme.typography.bodySmall)
                    StatCards(data.cards)
                    AnalyticsSectionTitle(stringResource(R.string.analytics_date_chart))
                    LineChart("occurrence-${data.id}", stringResource(R.string.analytics_date_chart), data.series.map { it.date }, listOf(
                        LineSeries(stringResource(R.string.analytics_date_views), data.series.map { it.views }),
                        LineSeries(stringResource(R.string.analytics_date_clicks), data.series.map { it.clicks }),
                    ))
                }
            }
            TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp).align(Alignment.End)) { Text(stringResource(R.string.analytics_close)) }
        }
    }
}

/**
 * «Filtra i dati»: evento (solo nella pagina d'insieme), canale e due date.
 * Toccare una data passa alle date scelte a mano, come nel pannello.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ReportFiltersSheet(
    report: AnalyticsReport,
    query: ReportQuery,
    eventTitle: String?,
    detail: Boolean,
    search: suspend (String) -> it.fabiodalez.incitta.data.management.EventChoiceGroups,
    onApply: (ReportQuery, String?) -> Unit,
    onDismiss: () -> Unit,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var event by remember { mutableStateOf(query.event) }
    var title by remember { mutableStateOf(eventTitle ?: report.selectedEvent?.title) }
    var channel by remember { mutableStateOf(query.channel) }
    var from by remember { mutableStateOf(if (query.custom) query.from else report.from) }
    var until by remember { mutableStateOf(if (query.custom) query.until else report.until) }
    var datesTouched by remember { mutableStateOf(query.custom) }
    var picking by remember { mutableStateOf(false) }
    val invalid = datesTouched && from != null && until != null && from!! > until!!
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.analytics_filters), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            if (!detail) {
                Text(stringResource(R.string.analytics_filter_event), style = MaterialTheme.typography.titleSmall)
                Text(if (event == null) stringResource(R.string.analytics_filter_event_all) else title ?: stringResource(R.string.analytics_filter_event))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { picking = true }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_filter_event_choose)) }
                    if (event != null) TextButton(onClick = { event = null; title = null }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_filter_event_all)) }
                }
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
                    val next = if (datesTouched) query.copy(period = "custom", from = from, until = until) else query
                    onApply(next.copy(event = if (detail) null else event, channel = channel), title)
                },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.analytics_filter_apply)) }
        }
    }
    if (picking) {
        EventPickerSheet(search = search, onPick = { choice -> event = choice.id; title = choice.title; picking = false }, onDismiss = { picking = false })
    }
}

@Composable
internal fun ChannelOption(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = selected, role = Role.RadioButton, onClick = onClick),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = null)
        Spacer(Modifier.width(8.dp))
        Text(label)
    }
}

/** Una data `AAAA-MM-GG` scelta dal calendario del sistema. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DateField(label: String, value: String?, allowEmpty: Boolean, onChange: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedButton(onClick = { open = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(longDate(value) ?: stringResource(R.string.analytics_filter_from_none))
            }
            if (allowEmpty && value != null) TextButton(onClick = { onChange(null) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_filter_from_none)) }
        }
    }
    if (open) {
        val initial = value?.let { runCatching { java.time.LocalDate.parse(it) }.getOrNull() } ?: java.time.LocalDate.now()
        val picker = rememberDatePickerState(initialSelectedDateMillis = initial.atStartOfDay(java.time.ZoneOffset.UTC).toInstant().toEpochMilli())
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = {
                    picker.selectedDateMillis?.let { onChange(java.time.Instant.ofEpochMilli(it).atZone(java.time.ZoneOffset.UTC).toLocalDate().toString()) }
                    open = false
                }) { Text(stringResource(android.R.string.ok)) }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(android.R.string.cancel)) } },
        ) { DatePicker(state = picker) }
    }
}

// ------------------------------------------------------------------ tabelle

/** Una tabella di «Tutti i dati»: ogni riga è una scheda con le colonne del pannello, venti per pagina. */
@Composable
internal fun AnalyticsTableScreen(viewModel: AnalyticsTableViewModel, context: ManagementGraphContext, navigate: (Any) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val table = (state.table as? Loadable.Ready)?.value
    val androidContext = LocalContext.current
    ManagementScaffold(
        title = table?.label ?: stringResource(R.string.analytics_tables),
        padding = context.padding,
        onBack = context::back,
        subtitle = table?.takeIf { it.meta.total > 0 }?.let {
            val first = (it.meta.page - 1) * 20 + 1
            stringResource(R.string.analytics_table_page, first, first + it.rows.size - 1, it.meta.total)
        },
    ) {
        LoadState(state.table, onRetry = viewModel::load, emptyText = stringResource(R.string.analytics_subject_missing)) { data ->
            TableContent(data, onOpen = { link -> state.subject?.let { navigate(subjectRoute(it.key, link.type, link.id, viewModel.reportQuery)) } })
            Pager(data.meta.page, data.meta.nextPage, enabled = true, onPage = viewModel::page)
        }
        ExportButtons(state.export, csv = true, onExport = { viewModel.export(it, androidContext.cacheDir) })
    }
}

@Composable
internal fun TableContent(table: AnalyticsTable, onOpen: (SubjectLink) -> Unit) {
    if (table.rows.isEmpty()) {
        Text(stringResource(R.string.analytics_table_empty), color = Muted)
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        table.rows.forEach { row ->
            Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    table.columns.forEachIndexed { index, column ->
                        val text = row.cells.getOrNull(index)?.let { analyticsCellText(it, table.unavailable) } ?: table.unavailable
                        val link = row.links[column.key]
                        Row(
                            Modifier.fillMaxWidth()
                                .then(if (link != null) Modifier.heightIn(min = 48.dp).clickable(role = Role.Button) { onOpen(link) } else Modifier)
                                .semantics(mergeDescendants = true) {},
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(column.label, color = Muted, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                            Text(
                                text,
                                modifier = Modifier.weight(1.3f),
                                fontWeight = if (index == 0 || column.numeric) FontWeight.SemiBold else FontWeight.Normal,
                                color = if (link != null) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
                            )
                        }
                    }
                }
            }
        }
    }
}
