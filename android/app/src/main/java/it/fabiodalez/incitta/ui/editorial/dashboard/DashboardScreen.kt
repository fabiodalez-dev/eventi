package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.DashboardRegistration
import it.fabiodalez.incitta.data.editorial.DashboardSection
import it.fabiodalez.incitta.data.editorial.DashboardStat
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialDashboard
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.CityPicker
import it.fabiodalez.incitta.ui.editorial.common.StatTile
import it.fabiodalez.incitta.ui.management.analytics.LineChart
import it.fabiodalez.incitta.ui.management.analytics.LineSeries
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Il riepilogo della redazione: le sezioni del pannello una sotto l'altra
 * (da lavorare adesso, pubblicazione, qualità, nuove iscrizioni, ritorno
 * delle persone, abilitazioni, sponsorizzazioni e il loro andamento), solo
 * quelle che il server manda a chi guarda.
 */
@Composable
internal fun DashboardScreen(
    state: Loadable<EditorialDashboard>,
    desk: EditorialDesk?,
    busy: Boolean,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onRefresh: () -> Unit,
    onCity: (String) -> Unit,
    onTarget: (DashboardTarget) -> Unit,
    onRegistration: (DashboardRegistration) -> Unit,
) {
    val loaded = (state as? Loadable.Ready)?.value
    val city = loaded?.city ?: desk?.city
    ManagementScaffold(
        title = stringResource(R.string.editorial_dashboard_title),
        padding = padding,
        onBack = onBack,
        subtitle = city?.name,
        busy = busy && loaded != null,
    ) {
        desk?.let { CityPicker(it.cities, city?.slug, onSelect = { picked -> onCity(picked.slug) }, enabled = !busy) }
        LoadState(state, onRetry = onRetry) { dashboard ->
            if (dashboard.city == null) Text(stringResource(R.string.editorial_dashboard_no_city), color = Muted)
            if (dashboard.sections.isEmpty()) Text(stringResource(R.string.editorial_dashboard_empty), color = Muted)
            val zone = dashboard.city?.zone ?: ZoneId.of("Europe/Rome")
            dashboard.sections.forEach { section ->
                when {
                    section.chart != null -> TrendSection(section)
                    section.key == DashboardSection.REGISTRATIONS -> RegistrationsSection(section, zone, onTarget, onRegistration)
                    else -> StatsSection(section, onTarget)
                }
            }
            OutlinedButton(onClick = onRefresh, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_dashboard_refresh))
            }
        }
    }
}

@Composable
private fun SectionHeader(title: String, description: String?) {
    Column(Modifier.padding(top = 8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
    }
}

/** I riquadri di una sezione, due per riga; quelli con una destinazione si toccano. */
@Composable
private fun StatsSection(section: DashboardSection, onTarget: (DashboardTarget) -> Unit) {
    SectionHeader(section.title, section.description)
    section.stats.chunked(2).forEach { pair ->
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            pair.forEach { stat -> DashboardTile(stat, onTarget, Modifier.weight(1f)) }
            if (pair.size == 1) Spacer(Modifier.weight(1f))
        }
    }
}

@Composable
private fun DashboardTile(stat: DashboardStat, onTarget: (DashboardTarget) -> Unit, modifier: Modifier) {
    val target = stat.target
    StatTile(
        label = stat.label,
        value = stat.value,
        modifier = modifier,
        detail = stat.description,
        alert = stat.alert,
        onClick = target?.let { { onTarget(it) } },
    )
}

/**
 * «Nuove iscrizioni»: la frase con i conteggi, «Solo in attesa» e «Tutti
 * gli utenti», le ultime persone iscritte. Una riga si apre solo per chi può
 * modificare la persona, come nel pannello.
 */
@Composable
private fun RegistrationsSection(
    section: DashboardSection,
    zone: ZoneId,
    onTarget: (DashboardTarget) -> Unit,
    onRegistration: (DashboardRegistration) -> Unit,
) {
    SectionHeader(section.title, section.description)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        section.links.forEach { link ->
            val target = link.target
            val label = link.badge?.let { stringResource(R.string.editorial_dashboard_link_badge, link.label, it) } ?: link.label
            OutlinedButton(onClick = { target?.let(onTarget) }, enabled = target != null, modifier = Modifier.heightIn(min = 48.dp)) { Text(label) }
        }
    }
    if (section.rows.isEmpty()) {
        section.empty?.let { empty ->
            Text(empty.title, style = MaterialTheme.typography.titleMedium)
            Text(empty.description, color = Muted)
        }
        return
    }
    section.rows.forEach { row -> RegistrationRow(row, zone, onRegistration) }
}

@Composable
private fun RegistrationRow(row: DashboardRegistration, zone: ZoneId, onOpen: (DashboardRegistration) -> Unit) {
    val opens = row.webPath != null
    val moment = dashboardMoment(row.registeredAt, zone)
    val line = moment?.let { stringResource(R.string.editorial_dashboard_registered, row.state.label, it) } ?: row.state.label
    val spoken = listOf(row.name, row.detail, line).filter { it.isNotBlank() }.joinToString(", ")
    Column(
        Modifier.fillMaxWidth()
            .heightIn(min = 56.dp)
            .then(if (opens) Modifier.clickable(role = Role.Button, onClick = { onOpen(row) }) else Modifier)
            .semantics(mergeDescendants = true) { contentDescription = spoken }
            .padding(vertical = 8.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Text(row.name, style = MaterialTheme.typography.titleMedium)
        if (row.detail.isNotBlank()) Text(row.detail, color = Muted)
        Text(line, style = MaterialTheme.typography.bodySmall, color = if (row.state.tone == DashboardStat.TONE_WARNING) MaterialTheme.colorScheme.error else Muted)
    }
    HorizontalDivider()
}

/** L'andamento delle sponsorizzazioni: lo stesso grafico a due linee delle statistiche. */
@Composable
private fun TrendSection(section: DashboardSection) {
    val chart = section.chart ?: return
    SectionHeader(section.title, section.description)
    LineChart(
        key = "editorial-dashboard-" + section.key,
        title = section.title,
        dates = chart.days,
        series = chart.series.map { LineSeries(it.label, it.values, it.values.sum()) },
    )
}

private val momentFormat: DateTimeFormatter = DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)

/** «26 nov 2026, 14:05» nel fuso della città; `null` se la data manca o non si legge. */
internal fun dashboardMoment(iso: String?, zone: ZoneId): String? = iso?.let { value ->
    runCatching { OffsetDateTime.parse(value).atZoneSameInstant(zone).format(momentFormat) }.getOrNull()
}
