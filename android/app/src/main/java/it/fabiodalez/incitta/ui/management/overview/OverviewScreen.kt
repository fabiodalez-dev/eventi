package it.fabiodalez.incitta.ui.management.overview

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavController
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.DashboardCard
import it.fabiodalez.incitta.data.management.DashboardProgramme
import it.fabiodalez.incitta.data.management.DashboardSummary
import it.fabiodalez.incitta.data.management.EventsTab
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.ManagementArea
import it.fabiodalez.incitta.ui.management.analytics.ExportResult
import it.fabiodalez.incitta.ui.management.analytics.ExportState
import it.fabiodalez.incitta.ui.management.analytics.ShareAnalyticsRoute
import it.fabiodalez.incitta.ui.management.analytics.SubjectAnalyticsRoute
import it.fabiodalez.incitta.ui.management.availableAreas
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.events.EventEditorRoute
import it.fabiodalez.incitta.ui.management.events.EventOverviewRoute
import it.fabiodalez.incitta.ui.management.events.EventsListRoute
import it.fabiodalez.incitta.ui.management.social.SocialStudioRoute
import it.fabiodalez.incitta.ui.management.events.StatusBadge
import it.fabiodalez.incitta.ui.management.events.formatEventDate
import it.fabiodalez.incitta.ui.management.profile.VenueSectionRoute
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDateRoute
import java.text.NumberFormat
import java.time.ZoneId
import java.util.Locale

/*
 * I riquadri del riepilogo (P11), nell'ordine della prima pagina dei
 * pannelli: l'avviso della mappa, «Il tuo cartellone», «In programma oggi»,
 * «Prossimi eventi», «Ultime visite», «Vai a». Sotto, al posto del rapporto
 * incorporato, il collegamento alla pagina delle statistiche del soggetto
 * (P5) e, per il locale, i due programmi in PDF che nel pannello stanno
 * accanto al titolo.
 *
 * Le scorciatoie non dipendono dal riepilogo: si vedono anche se la lettura
 * non riesce, così dalla gestione si arriva comunque dappertutto.
 */

/** Dove porta ogni tocco del riepilogo: le schermate delle aree già fatte, con il soggetto scelto. */
internal class OverviewNavigation(private val navController: NavController, private val subject: ManagementSubject) {
    fun event(id: Long) = navController.navigate(EventOverviewRoute(id))
    fun edit(id: Long) = navController.navigate(EventEditorRoute(id))
    fun tickets(occurrenceId: Long) = navController.navigate(TicketingDateRoute(occurrenceId))
    fun eventStatistics(id: Long) = navController.navigate(SubjectAnalyticsRoute(subject = subject.key, type = "event", id = id))
    fun sponsor(id: Long) = navController.navigate(SponsorshipsRoute(subject = subject.key, event = id))
    /** «Grafica social» di un prossimo evento; `null` se la card non la porta. */
    fun social(card: DashboardCard): (() -> Unit)? = overviewSocialRoute(subject, card)?.let { route -> { navController.navigate(route) } }
    /** Un numero del cartellone: l'elenco degli eventi sulla scheda giusta. */
    fun list(tab: EventsTab) = navController.navigate(EventsListRoute(tab.value))
    /** «Nuovo evento»: il modulo sopra l'elenco, così dopo il salvataggio si torna fra gli eventi come nel pannello. */
    fun create() {
        navController.navigate(EventsListRoute())
        navController.navigate(EventEditorRoute())
    }
    /** «Apri le statistiche» delle ultime visite: «Statistiche in dettaglio». */
    fun statistics() = navController.navigate(ShareAnalyticsRoute(subject = subject.key))
    /** Il rapporto del locale o dell'organizzazione: nel pannello è sotto i riquadri. */
    fun report() = navController.navigate(SubjectAnalyticsRoute(subject = subject.key, type = subject.kind.name.lowercase(), id = subject.id))
    fun location() = navController.navigate(VenueSectionRoute(subject.id, VenueSection.Where.name))
    fun area(area: ManagementArea) { area.entry?.let { navController.navigate(it) } }
    fun allAreas() = navController.navigate(ManagementAreasRoute)
}

/**
 * «Grafica social» di una card dei prossimi eventi: lo studio di P10 del
 * locale scelto, ristretto a quell'evento (`?event=` del pannello). Come nella
 * scheda dell'evento servono il collegamento del server (solo locale) e il
 * permesso dello studio.
 */
internal fun overviewSocialRoute(subject: ManagementSubject, card: DashboardCard): SocialStudioRoute? =
    SocialStudioRoute(subject = subject.key, event = card.event.id).takeIf { card.links.social && subject.permissions.socialUse && card.event.id > 0 }

/** Le aree nei tre gruppi di «Vai a», come `ManagementContext::shortcuts`: solo quelle fatte e permesse. */
internal data class ShortcutGroup(val title: Int, val areas: List<ManagementArea>, val createEvent: Boolean = false)

internal fun shortcutGroups(subject: ManagementSubject, hasCheckinDates: Boolean): List<ShortcutGroup> {
    val available = availableAreas(subject, hasCheckinDates).toSet()
    fun pick(vararg areas: ManagementArea) = areas.filter { it in available }
    return listOf(
        ShortcutGroup(R.string.overview_group_events, pick(ManagementArea.Events, ManagementArea.Social, ManagementArea.Sponsorships, ManagementArea.Ticketing),
            createEvent = subject.permissions.eventsCreate && ManagementArea.Events in available),
        ShortcutGroup(R.string.overview_group_audience, pick(ManagementArea.Analytics, ManagementArea.Feedback)),
        ShortcutGroup(
            if (subject.kind == SubjectKind.Venue) R.string.overview_group_venue else R.string.overview_group_organizer,
            pick(ManagementArea.Profile, ManagementArea.Collaborators, ManagementArea.Import),
        ),
    ).filter { it.areas.isNotEmpty() || it.createEvent }
}

@Composable
internal fun OverviewContent(
    subject: ManagementSubject,
    summary: Loadable<DashboardSummary>,
    programme: ExportState,
    hasCheckinDates: Boolean,
    navigation: OverviewNavigation,
    onRetry: () -> Unit,
    onProgramme: (String) -> Unit,
) {
    Text(stringResource(R.string.overview_subheading), color = Muted)
    when (summary) {
        Loadable.Empty -> Unit
        is Loadable.Failed -> LoadState(Loadable.Failed(stringResource(R.string.overview_error) + " " + summary.message), onRetry = onRetry) {}
        else -> LoadState(summary, onRetry = onRetry) { data -> Tiles(subject, data, navigation) }
    }
    Shortcuts(subject, hasCheckinDates, navigation)
    if (subject.permissions.analyticsView && ManagementArea.Analytics.implemented) ReportLink(subject, navigation)
    (summary as? Loadable.Ready)?.value?.programme?.takeIf { it.available }?.let { Programme(it, programme, onProgramme) }
}

@Composable
private fun Tiles(subject: ManagementSubject, data: DashboardSummary, navigation: OverviewNavigation) {
    val zone = runCatching { ZoneId.of(data.timezone) }.getOrDefault(subject.timezone)
    if (data.missingLocation) MissingLocation(subject.permissions.profileUpdate, navigation::location)
    Board(data, subject.permissions.eventsCreate, navigation)
    if (data.today.isNotEmpty()) {
        Section(stringResource(R.string.overview_today_schedule), stringResource(R.string.overview_today_schedule_description)) {
            data.today.forEach { card -> EventTile(card, zone, navigation, today = true) }
        }
    }
    Section(stringResource(R.string.overview_upcoming_heading), stringResource(R.string.overview_upcoming_description)) {
        if (data.upcoming.isEmpty()) {
            Text(stringResource(R.string.overview_upcoming_empty), style = MaterialTheme.typography.titleSmall)
            Text(stringResource(R.string.overview_upcoming_empty_body), color = Muted)
        } else {
            data.upcoming.forEach { card -> EventTile(card, zone, navigation, today = false) }
        }
        TextButton(onClick = { navigation.list(EventsTab.Upcoming) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_all_events)) }
    }
    if (data.recentViews.items.isNotEmpty()) {
        Section(stringResource(R.string.overview_recent_views), stringResource(R.string.overview_recent_views_description, data.recentViews.days)) {
            data.recentViews.items.forEach { card -> ViewedTile(card, navigation) }
            if (subject.permissions.analyticsView) {
                TextButton(onClick = navigation::statistics, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_open_statistics)) }
            }
        }
    }
}

/** Un riquadro con titolo e frase, come le sezioni del pannello. */
@Composable
private fun Section(title: String, description: String?, content: @Composable ColumnScope.() -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            description?.let { Text(it, color = Muted) }
            content()
        }
    }
}

@Composable
private fun MissingLocation(canFix: Boolean, onFix: () -> Unit) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.tertiaryContainer)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.overview_location_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.overview_location_body))
            if (canFix) Button(onClick = onFix, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_location_action)) }
        }
    }
}

/** «Il tuo cartellone»: tre numeri che aprono l'elenco sulla scheda giusta, e «Nuovo evento». */
@Composable
private fun Board(data: DashboardSummary, canCreate: Boolean, navigation: OverviewNavigation) {
    val format = NumberFormat.getIntegerInstance(Locale.ITALIAN)
    Section(stringResource(R.string.overview_summary), stringResource(R.string.overview_summary_description)) {
        listOf(
            Triple(R.string.overview_today, R.string.overview_today_hint, data.counts.today) to EventsTab.Upcoming,
            Triple(R.string.overview_upcoming, R.string.overview_upcoming_hint, data.counts.upcoming) to EventsTab.Upcoming,
            Triple(R.string.overview_drafts, R.string.overview_drafts_hint, data.counts.drafts) to EventsTab.Drafts,
        ).forEach { (number, tab) ->
            val (label, hint, value) = number
            val labelText = stringResource(label)
            val hintText = stringResource(hint)
            val description = stringResource(R.string.overview_count_open, labelText, format.format(value), hintText)
            Row(
                Modifier.fillMaxWidth().heightIn(min = 56.dp).clickable(role = Role.Button) { navigation.list(tab) }
                    .semantics(mergeDescendants = true) { contentDescription = description }.padding(vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(format.format(value), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Column(Modifier.weight(1f)) {
                    Text(labelText, style = MaterialTheme.typography.titleMedium)
                    Text(hintText, color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
        if (canCreate) Button(onClick = navigation::create, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_new_event)) }
    }
}

/** Una card di «Oggi» o «Prossimi eventi»: locandina 72×96, titolo che apre la scheda, data, luogo, stato e i collegamenti del pannello. */
@Composable
private fun EventTile(card: DashboardCard, zone: ZoneId, navigation: OverviewNavigation, today: Boolean) {
    val title = card.event.title
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TileHeader(card, navigation) {
            card.date?.let { date ->
                if (date.startsAt.isNotBlank()) Text(formatEventDate(date.startsAt, zone), color = Muted)
                if (date.place.isNotBlank()) Text(date.place, color = Muted)
                if (!date.scheduled && date.status.label.isNotBlank()) StatusBadge(date.status.label)
            }
        }
        val links = card.links
        /* Le stesse azioni e lo stesso ordine del pannello: oggi scheda, biglietteria, modifica; prossimi scheda, modifica, social, sponsorizza. */
        val actions = buildList<Pair<Int, () -> Unit>> {
            if (today) {
                links.ticketsOccurrenceId?.let { id -> add(R.string.overview_link_tickets to { navigation.tickets(id) }) }
                if (links.edit) add(R.string.overview_link_edit to { navigation.edit(card.event.id) })
            } else {
                if (links.edit) add(R.string.overview_link_edit to { navigation.edit(card.event.id) })
                navigation.social(card)?.let { open -> add(R.string.overview_link_social to open) }
                if (links.sponsor) add(R.string.overview_link_sponsor to { navigation.sponsor(card.event.id) })
            }
        }
        TileActions(actions, title)
        HorizontalDivider()
    }
}

/** Una card di «Ultime visite»: senza data, con le visite e il collegamento alle statistiche dell'evento. */
@Composable
private fun ViewedTile(card: DashboardCard, navigation: OverviewNavigation) {
    val views = card.views ?: 0
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TileHeader(card, navigation) {
            Text(pluralStringResource(R.plurals.overview_views_count, views.coerceAtMost(Int.MAX_VALUE.toLong()).toInt(), NumberFormat.getIntegerInstance(Locale.ITALIAN).format(views)), color = Muted)
        }
        TileActions(if (card.links.statistics) listOf(R.string.overview_link_statistics to { navigation.eventStatistics(card.event.id) }) else emptyList(), card.event.title)
        HorizontalDivider()
    }
}

@Composable
private fun TileHeader(card: DashboardCard, navigation: OverviewNavigation, lines: @Composable ColumnScope.() -> Unit) {
    val open = stringResource(R.string.overview_card_open, card.event.title)
    val posterLabel = stringResource(R.string.overview_poster, card.event.title)
    Row(
        Modifier.fillMaxWidth().clickable(role = Role.Button, onClickLabel = open) { navigation.event(card.event.id) }.padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        PosterImage(card.event.poster?.thumb ?: card.event.poster?.card, Modifier.size(width = 72.dp, height = 96.dp).semantics { contentDescription = posterLabel })
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(card.event.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            lines()
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TileActions(actions: List<Pair<Int, () -> Unit>>, title: String) {
    if (actions.isEmpty()) return
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        actions.forEach { (label, action) ->
            val text = stringResource(label)
            val description = stringResource(R.string.overview_link_for, text, title)
            val modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = description }
            /* Come nel pannello: «Modifica» è il pulsante pieno, gli altri sono contornati. */
            if (label == R.string.overview_link_edit) Button(onClick = action, modifier = modifier) { Text(text) }
            else OutlinedButton(onClick = action, modifier = modifier) { Text(text) }
        }
    }
}

/** «Vai a»: le aree in tre gruppi, poi «Tutte le aree» (il menu di prima). */
@Composable
private fun Shortcuts(subject: ManagementSubject, hasCheckinDates: Boolean, navigation: OverviewNavigation) {
    Section(stringResource(R.string.overview_shortcuts), stringResource(R.string.overview_shortcuts_description)) {
        shortcutGroups(subject, hasCheckinDates).forEach { group ->
            Text(stringResource(group.title), style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.semantics { heading() })
            if (group.createEvent) {
                Button(onClick = navigation::create, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_new_event)) }
            }
            Column {
                group.areas.forEach { area -> ManagementRow(stringResource(area.title), stringResource(area.help), onClick = { navigation.area(area) }) }
            }
        }
        ManagementRow(stringResource(R.string.overview_all_areas), stringResource(R.string.overview_all_areas_help), onClick = navigation::allAreas)
    }
}

@Composable
private fun ReportLink(subject: ManagementSubject, navigation: OverviewNavigation) {
    Section(stringResource(R.string.overview_report), stringResource(R.string.overview_report_description, subject.name)) {
        OutlinedButton(onClick = navigation::report, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.overview_report_open)) }
    }
}

/** I due programmi in PDF del locale: il file del server, poi «Apri» e «Condividi» (decisione 2). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Programme(programme: DashboardProgramme, file: ExportState, onDownload: (String) -> Unit) {
    val busy = stringResource(R.string.overview_programme_busy)
    Section(stringResource(R.string.overview_programme), stringResource(R.string.overview_programme_hint)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(enabled = file.busy == null, onClick = { onDownload(programme.thisWeek) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(if (file.busy == programme.thisWeek) busy else stringResource(R.string.overview_programme_this_week))
            }
            OutlinedButton(enabled = file.busy == null, onClick = { onDownload(programme.nextWeek) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(if (file.busy == programme.nextWeek) busy else stringResource(R.string.overview_programme_next_week))
            }
        }
        ExportResult(file)
    }
}
