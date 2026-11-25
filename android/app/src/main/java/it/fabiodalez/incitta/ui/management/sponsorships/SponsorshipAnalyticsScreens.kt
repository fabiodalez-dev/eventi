package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.SponsorshipAnalytics
import it.fabiodalez.incitta.data.management.SponsorshipCampaignDetail
import it.fabiodalez.incitta.data.management.SponsorshipDelivery
import it.fabiodalez.incitta.data.management.SponsorshipEconomics
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Le statistiche delle sponsorizzazioni (W-ST-08, W-ST-09): il periodo
 * (sette giorni, trenta, novanta, un anno, tutto, fra due date) e la
 * campagna; i totali con la frase che li spiega; visualizzazioni e clic
 * giorno per giorno; con una campagna scelta, avanzamento, consegnate contro
 * attese e conti economici; il registro dei clic.
 */
@Composable
internal fun SponsorshipAnalyticsScreen(viewModel: SponsorshipAnalyticsViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = state.subject.valueOrNull
    ManagementScaffold(
        title = stringResource(R.string.sponsorships_analytics_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.clicksBusy && state.report is Loadable.Ready,
    ) {
        LoadState(state.subject, onRetry = viewModel::start, emptyText = stringResource(R.string.sponsorships_subject_missing)) { current ->
            val zone = state.report.valueOrNull?.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: current.timezone
            Text(stringResource(R.string.sponsorships_analytics_lead), color = Muted)
            Filters(state, zone, viewModel)
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (state.filters.days == "custom" && (state.filters.from == null || state.filters.to == null)) {
                Text(stringResource(R.string.sponsorships_dates_needed), color = Muted)
            } else {
                LoadState(state.report, onRetry = viewModel::load) { report -> Report(report, state, zone, viewModel) }
            }
        }
    }

    state.options?.let { options ->
        CampaignOptionsSheet(state.optionsQuery, options, state.optionsBusy, subject?.timezone ?: ZoneId.of("Europe/Rome"), viewModel)
    }
}

@Composable
private fun Filters(state: SponsorshipAnalyticsUiState, zone: ZoneId, viewModel: SponsorshipAnalyticsViewModel) {
    val periods = state.report.valueOrNull?.periods?.takeIf { it.isNotEmpty() }
    Text(stringResource(R.string.sponsorships_period), style = MaterialTheme.typography.titleMedium)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        (periods?.map { it.value to it.label } ?: SponsorshipAnalyticsViewModel.PERIODS.map { it to it }).forEach { (value, label) ->
            FilterChip(selected = state.filters.days == value, onClick = { viewModel.choosePeriod(value) }, label = { Text(label) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
    if (state.filters.days == "custom") {
        val from = state.filters.from?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
        val to = state.filters.to?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.weight(1f)) { DateButton(stringResource(R.string.sponsorships_from), from, zone) { viewModel.chooseDates(it, to) } }
            Box(Modifier.weight(1f)) { DateButton(stringResource(R.string.sponsorships_to), to, zone) { viewModel.chooseDates(from, it) } }
        }
    }
    Text(stringResource(R.string.sponsorships_campaign_filter), style = MaterialTheme.typography.titleMedium)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        OutlinedButton(onClick = viewModel::openOptions, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
            Text(if (state.filters.campaignId != null) state.campaignTitle ?: stringResource(R.string.sponsorships_campaign_title) else stringResource(R.string.sponsorships_all_campaigns))
        }
        if (state.filters.campaignId != null) TextButton(onClick = { viewModel.chooseCampaign(null) }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.sponsorships_all_campaigns))
        }
    }
}

private val dateButtonFormat = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale.ITALIAN)

/** Una data sola (nel fuso della città), con il calendario di sistema; non oltre oggi. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DateButton(label: String, value: LocalDate?, zone: ZoneId, onPick: (LocalDate) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, color = Muted)
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(value?.format(dateButtonFormat) ?: stringResource(R.string.sponsorships_pick_date))
        }
    }
    if (open) {
        val today = LocalDate.now(zone)
        val state = rememberDatePickerState(
            initialSelectedDateMillis = (value ?: today).atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli(),
            selectableDates = object : SelectableDates {
                override fun isSelectableDate(utcTimeMillis: Long): Boolean = !Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneOffset.UTC).toLocalDate().isAfter(today)
            },
        )
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = {
                    open = false
                    state.selectedDateMillis?.let { onPick(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate()) }
                }) { Text(stringResource(R.string.management_datetime_done)) }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = state) }
    }
}

@Composable
private fun Report(report: SponsorshipAnalytics, state: SponsorshipAnalyticsUiState, zone: ZoneId, viewModel: SponsorshipAnalyticsViewModel) {
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text(stringResource(R.string.sponsorships_window, sponsorshipCalendarDay(report.window.from, zone), sponsorshipCalendarDay(report.window.to, zone)), color = Muted)

        report.economics?.let { EconomicsBlock(it, null) }
        report.progress?.let { progress ->
            SponsorshipSection(stringResource(R.string.sponsorships_progress_title), report.progressLead)
            SponsorshipProgressView(progress, forceBadge = true)
            report.delivery?.let { DeliveryBlock(it, zone) }
        }

        SponsorshipSection(stringResource(R.string.sponsorships_totals))
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_impressions), sponsorshipCount(report.totals.impressions)) }
            Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_clicks), sponsorshipCount(report.totals.clicks)) }
        }
        SponsorshipMetric(stringResource(R.string.sponsorships_rate), report.totals.rateLabel)
        if (report.totals.explain.isNotBlank()) Text(report.totals.explain)
        report.totals.rateThreshold?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }

        DailyChart(stringResource(R.string.sponsorships_chart_impressions), report.series.map { it.impressions }, report.series.firstOrNull()?.date, report.series.lastOrNull()?.date, zone)
        DailyChart(stringResource(R.string.sponsorships_chart_clicks), report.series.map { it.clicks }, report.series.firstOrNull()?.date, report.series.lastOrNull()?.date, zone)

        var daily by rememberSaveable { mutableStateOf(false) }
        OutlinedButton(onClick = { daily = !daily }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(if (daily) R.string.sponsorships_daily_hide else R.string.sponsorships_daily_show))
        }
        if (daily) {
            report.series.asReversed().forEach { row ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(sponsorshipCalendarDay(row.date, zone))
                    Text(stringResource(R.string.sponsorships_daily_row, sponsorshipCount(row.impressions), sponsorshipCount(row.clicks)), color = Muted)
                }
                HorizontalDivider()
            }
        }

        SponsorshipSection(stringResource(R.string.sponsorships_register), stringResource(R.string.sponsorships_register_lead))
        if (state.clicks.isEmpty() && !state.clicksBusy) Text(stringResource(R.string.sponsorships_register_empty), color = Muted)
        state.clicks.forEach { click ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(click.title, style = MaterialTheme.typography.titleSmall)
                    Text(listOfNotNull(sponsorshipMoment(click.clickedAt, zone), click.venue).joinToString(" · "), color = Muted)
                    Text(stringResource(R.string.sponsorships_click_meta, click.channel, click.placement, click.page), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
            HorizontalDivider()
        }
        if (state.clicksNextPage != null) OutlinedButton(enabled = !state.clicksBusy, onClick = viewModel::moreClicks, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.sponsorships_register_more))
        }
    }
}

@Composable
private fun DailyChart(title: String, values: List<Long>, first: String?, last: String?, zone: ZoneId) {
    SponsorshipSection(title)
    val peak = values.maxOrNull() ?: 0L
    if (peak == 0L) {
        Text(stringResource(R.string.sponsorships_chart_empty), color = Muted)
        return
    }
    SponsorshipLineChart(values, stringResource(R.string.sponsorships_chart_label, title, sponsorshipCount(peak)))
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(first?.let { sponsorshipCalendarDay(it, zone) }.orEmpty(), color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(stringResource(R.string.sponsorships_peak, sponsorshipCount(peak)), color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(last?.let { sponsorshipCalendarDay(it, zone) }.orEmpty(), color = Muted, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun DeliveryBlock(delivery: SponsorshipDelivery, zone: ZoneId) {
    if (delivery.days.isEmpty()) return
    Text(stringResource(R.string.sponsorships_delivery_title), style = MaterialTheme.typography.titleSmall)
    Text(stringResource(R.string.sponsorships_delivery_lead), color = Muted, style = MaterialTheme.typography.bodySmall)
    val summary = stringResource(R.string.sponsorships_delivery_summary, sponsorshipCount(delivery.delivered), sponsorshipCount(delivery.expected))
    SponsorshipLineChart(delivery.days.map { it.delivered }, summary, dashed = delivery.days.map { it.expected })
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(sponsorshipCalendarDay(delivery.days.first().date, zone), color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(summary, color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(sponsorshipCalendarDay(delivery.days.last().date, zone), color = Muted, style = MaterialTheme.typography.bodySmall)
    }
}

/** Spesa, costo per prenotazione e per presenza, come il pannello; i rapporti solo con campioni abbastanza grandi. */
@Composable
private fun EconomicsBlock(economics: SponsorshipEconomics, help: String?) {
    val none = stringResource(R.string.sponsorships_not_available)
    SponsorshipSection(stringResource(R.string.sponsorships_economics_title))
    SponsorshipMetric(stringResource(R.string.sponsorships_spend), sponsorshipMoney(economics.spendCents, economics.currency) ?: none)
    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
        Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_bookings), economics.bookings?.toString() ?: none) }
        Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_attendances), economics.attendances?.toString() ?: none) }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
        Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_per_booking), sponsorshipMoney(economics.perBookingCents, economics.currency) ?: none) }
        Box(Modifier.weight(1f)) { SponsorshipMetric(stringResource(R.string.sponsorships_per_attendance), sponsorshipMoney(economics.perAttendanceCents, economics.currency) ?: none) }
    }
    help?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
}

/** La scelta della campagna del filtro: le ultime cinquanta, con la ricerca. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CampaignOptionsSheet(
    query: String,
    options: List<it.fabiodalez.incitta.data.management.SponsorshipCampaignOption>,
    busy: Boolean,
    zone: ZoneId,
    viewModel: SponsorshipAnalyticsViewModel,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var text by rememberSaveable { mutableStateOf(query) }
    ModalBottomSheet(onDismissRequest = viewModel::closeOptions, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.sponsorships_campaign_filter), style = MaterialTheme.typography.titleLarge)
            OutlinedTextField(text, { text = it.take(120) }, label = { Text(stringResource(R.string.sponsorships_campaign_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedButton(enabled = !busy, onClick = { viewModel.searchOptions(text) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_search_action)) }
            TextButton(onClick = { viewModel.chooseCampaign(null) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_all_campaigns)) }
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            if (!busy && options.isEmpty()) Text(stringResource(R.string.sponsorships_campaign_none), color = Muted)
            options.forEach { option ->
                Surface(onClick = { viewModel.chooseCampaign(option) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                    Column(Modifier.padding(vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        val event = option.event
                        if (event != null) SponsorshipEventLine(event, zone) else Text(option.eventGone ?: stringResource(R.string.sponsorships_event_gone), style = MaterialTheme.typography.titleMedium)
                        Text(listOfNotNull(sponsorshipDay(option.startsAt, zone)?.let { start -> "$start – ${sponsorshipDay(option.endsAt, zone).orEmpty()}" }, option.placement.label).joinToString(" · "),
                            color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
                HorizontalDivider()
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

// --------------------------------------------------------- una campagna

/**
 * La scheda di una campagna (la «Campagna» di prima, ora anche per le
 * organizzazioni): evento, fase, periodo, numeri, avanzamento della sua unità
 * con consegnate e attese, conti economici, e il collegamento alle sue
 * statistiche.
 */
@Composable
internal fun SponsorshipCampaignScreen(viewModel: SponsorshipCampaignViewModel, context: ManagementGraphContext, onAnalytics: (subjectKey: String, campaignId: Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val campaign = state.campaign.valueOrNull
    val subject = state.subject.valueOrNull
    ManagementScaffold(
        title = campaign?.event?.title ?: stringResource(R.string.sponsorships_campaign_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
    ) {
        LoadState(state.campaign, onRetry = viewModel::load, emptyText = stringResource(R.string.sponsorships_subject_missing)) { detail ->
            CampaignDetail(detail, subject?.timezone ?: ZoneId.of("Europe/Rome"), onAnalytics = { subject?.let { onAnalytics(it.key, detail.id) } })
        }
    }
}

@Composable
private fun CampaignDetail(detail: SponsorshipCampaignDetail, zone: ZoneId, onAnalytics: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        val event = detail.event
        if (event != null) SponsorshipEventLine(event, zone) else Text(detail.eventGone ?: stringResource(R.string.sponsorships_event_gone))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            SponsorshipBadge(detail.phase.label, detail.phase.color)
            Text(detail.placement.label, color = Muted)
        }
        Text("${sponsorshipMoment(detail.startsAt, zone).orEmpty()} — ${sponsorshipMoment(detail.endsAt, zone).orEmpty()}", color = Muted)
        Text(stringResource(R.string.sponsorships_campaign_numbers, sponsorshipCount(detail.impressions), sponsorshipCount(detail.clicks)), style = MaterialTheme.typography.titleMedium)
        detail.progress?.let { progress ->
            SponsorshipSection(stringResource(R.string.sponsorships_progress_title), detail.progressLead)
            SponsorshipProgressView(progress, forceBadge = true)
            detail.delivery?.let { DeliveryBlock(it, zone) }
        }
        detail.economics?.let { EconomicsBlock(it, detail.economicsHelp) }
        OutlinedButton(onClick = onAnalytics, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_campaign_stats)) }
    }
}
