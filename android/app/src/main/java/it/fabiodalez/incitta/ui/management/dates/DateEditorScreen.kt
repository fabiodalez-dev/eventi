package it.fabiodalez.incitta.ui.management.dates

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.EventDateChecksResult
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.events.ChoiceChips
import it.fabiodalez.incitta.ui.management.events.ChoiceField
import it.fabiodalez.incitta.ui.management.events.CollapsibleSection
import it.fabiodalez.incitta.ui.management.events.PlainField
import it.fabiodalez.incitta.ui.management.events.RepeaterField
import it.fabiodalez.incitta.ui.management.events.VenuePickerField
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Il modulo di una data (W-DT-02, 03, 04, 10), nelle due varianti dei
 * pannelli. Locale: inizio, fine, posti totali e rimasti, evidenza, lineup.
 * Organizzazione («Date e luoghi»): inizio, fine, tutto il giorno, locale
 * della data, stato fra i cinque, avviso al pubblico. Per tutti, in una
 * sezione richiudibile, informazioni pratiche e costi della data (anche il
 * collaboratore, come sul sito). Accanto all'orario gli avvisi «Stessa sera
 * in zona» e chiusura straordinaria.
 */
@Composable
internal fun DateEditorScreen(viewModel: DateEditorViewModel, context: ManagementGraphContext, onDone: (UiText) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var catalog by remember { mutableStateOf(ManagementOptions()) }
    LaunchedEffect(Unit) { context.repository.options()?.let { catalog = it } }
    LaunchedEffect(state.done) { state.done?.let { viewModel.consumeDone(); onDone(it) } }
    val subject = state.subject
    val zone = datesZone(state.meta, subject)
    val kind = subject?.kind ?: SubjectKind.Venue
    val form = state.form

    val localTexts = mapOf(
        "starts_at" to stringResource(R.string.management_dates_error_starts),
        "ends_at" to stringResource(R.string.management_dates_error_ends),
        "cost_breakdown" to stringResource(R.string.management_dates_error_costs),
        "number" to stringResource(R.string.management_dates_error_number),
    )
    val error: (String) -> String? = { key -> state.errors.field(key) ?: if (key in state.local) localTexts[key] ?: localTexts["number"] else null }

    ManagementScaffold(
        title = stringResource(if (viewModel.creating) R.string.management_dates_new else R.string.management_dates_edit_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = state.meta?.event?.title,
        busy = state.busy,
    ) {
        LoadState(state.loading, onRetry = viewModel::load) {
            FormScaffold(
                errors = state.errors,
                busy = state.busy,
                submitLabel = stringResource(if (viewModel.creating) R.string.management_dates_add else R.string.management_dates_save),
                onSubmit = viewModel::save,
            ) {
                if (state.local.isNotEmpty() && state.errors.isEmpty) Text(stringResource(R.string.management_form_fix), color = MaterialTheme.colorScheme.error)
                val city = state.meta?.event?.city?.ifBlank { null } ?: subject?.cityName
                CityDateTimeField(stringResource(R.string.management_dates_starts), form.startsAt, zone, city,
                    onChange = { v -> viewModel.edit { it.copy(startsAt = v) } }, error = error("starts_at"))
                CityDateTimeField(stringResource(R.string.management_dates_ends), form.endsAt, zone, city,
                    onChange = { v -> viewModel.edit { it.copy(endsAt = v) } }, error = error("ends_at"), clearable = true)
                Checks(state.checks)

                if (kind == SubjectKind.Venue) VenueFields(form, catalog, viewModel, error)
                else OrganizerFields(form, catalog, state, viewModel, error)

                DecisionFields(form, state.meta?.event?.currency ?: "EUR", viewModel, error)
            }
        }
    }
}

@Composable
private fun VenueFields(form: DateForm, catalog: ManagementOptions, viewModel: DateEditorViewModel, error: (String) -> String?) {
    PlainField(form.capacity, { v -> viewModel.edit { it.copy(capacity = v.filter(Char::isDigit).take(7)) } }, stringResource(R.string.management_dates_capacity_total),
        error = error("capacity"), help = stringResource(R.string.management_dates_capacity_total_hint), keyboard = KeyboardType.Number)
    PlainField(form.capacityLeft, { v -> viewModel.edit { it.copy(capacityLeft = v.filter(Char::isDigit).take(7)) } }, stringResource(R.string.management_dates_capacity_left_field),
        error = error("capacity_left"), help = stringResource(R.string.management_dates_capacity_left_hint), keyboard = KeyboardType.Number)
    PlainField(form.highlight, { v -> viewModel.edit { it.copy(highlight = v.take(40)) } }, stringResource(R.string.management_dates_highlight),
        error = error("highlight"), help = stringResource(R.string.management_dates_highlight_hint))

    val roles = catalog.choices("lineup_roles").map { it.value to it.label }.ifEmpty { DEFAULT_ROLES.map { it to it } }
    Text(stringResource(R.string.management_dates_lineup_title), style = MaterialTheme.typography.titleSmall)
    RepeaterField(form.lineup, { rows -> viewModel.edit { it.copy(lineup = rows) } }, stringResource(R.string.management_dates_lineup_add), 100, { LineupRow() },
        error = error("lineup")) { row, update ->
        PlainField(row.name, { update(row.copy(name = it.take(255))) }, stringResource(R.string.management_dates_artist))
        ChoiceField(stringResource(R.string.management_dates_artist_role), roles, row.role, { update(row.copy(role = it ?: "live")) })
    }
}

@Composable
private fun OrganizerFields(form: DateForm, catalog: ManagementOptions, state: DateEditorUiState, viewModel: DateEditorViewModel, error: (String) -> String?) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(R.string.management_dates_all_day), modifier = Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
        val label = stringResource(R.string.management_dates_all_day)
        Switch(checked = form.isAllDay, onCheckedChange = { on -> viewModel.edit { it.copy(isAllDay = on) } }, modifier = Modifier.semantics { contentDescription = label })
    }
    error("is_all_day")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    VenuePickerField(stringResource(R.string.management_dates_venue), form.venue, state.venues.map { it.id to listOfNotNull(it.name, it.city).joinToString(" · ") },
        onSearch = viewModel::searchVenues, onSelect = { v -> viewModel.edit { it.copy(venue = v) } }, error = error("venue_id"),
        help = stringResource(R.string.management_dates_venue_hint), allowNone = true)
    val statuses = catalog.choices("occurrence_statuses", "organizer").map { it.value to it.label }.ifEmpty { DEFAULT_STATUSES.map { it to it } }
    ChoiceField(stringResource(R.string.management_dates_status), statuses, form.status, { v -> viewModel.edit { it.copy(status = v ?: "scheduled") } }, error = error("status"))
    RichTextField(form.statusNote, { v -> viewModel.edit { it.copy(statusNote = v) } }, stringResource(R.string.management_dates_status_note),
        error = error("status_note"), help = stringResource(R.string.management_dates_status_note_hint), minLines = 3)
}

/** Informazioni pratiche e costi della data: vuoto = «usa quello dell'evento o del locale». */
@Composable
private fun DecisionFields(form: DateForm, currency: String, viewModel: DateEditorViewModel, error: (String) -> String?) {
    CollapsibleSection(stringResource(R.string.management_dates_practical), stringResource(R.string.management_inherit)) {
        fun setPractical(key: String, value: String?) = viewModel.edit { it.copy(practical = it.practical + (key to value.orEmpty())) }
        ChoiceChips(stringResource(R.string.management_accessibility),
            listOf("yes" to stringResource(R.string.management_yes), "no" to stringResource(R.string.management_no), "unknown" to stringResource(R.string.management_unknown)),
            form.practical["accessibility"]?.ifBlank { null }, { setPractical("accessibility", it) }, clearable = true)
        ChoiceChips(stringResource(R.string.management_membership),
            listOf("required" to stringResource(R.string.management_required), "not_required" to stringResource(R.string.management_not_required)),
            form.practical["membership"]?.ifBlank { null }, { setPractical("membership", it) }, clearable = true)
        Text(stringResource(R.string.management_dates_inherit_hint), color = Muted)
        listOf("entrance_notes" to R.string.management_entrance, "membership_notes" to R.string.management_membership_notes, "parking_notes" to R.string.management_parking,
            "transit_notes" to R.string.management_transit, "food_notes" to R.string.management_food, "start_notes" to R.string.management_start).forEach { (key, label) ->
            PlainField(form.practical[key].orEmpty(), { setPractical(key, it.take(2000)) }, stringResource(label), error = error("practical_details.$key"), minLines = 2)
        }
        error("practical_details")?.let { Text(it, color = MaterialTheme.colorScheme.error) }

        Text(stringResource(R.string.management_costs), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.management_costs_help, currency), color = Muted)
        listOf("admission" to R.string.management_admission, "drink" to R.string.management_drink, "membership" to R.string.management_membership_cost, "other" to R.string.management_other).forEach { (key, label) ->
            val value = form.costs[key].orEmpty()
            PlainField(value, { v -> viewModel.edit { it.copy(costs = it.costs + (key to v.take(10))) } }, stringResource(label),
                error = error("cost_breakdown.$key") ?: if (!DateForm.validCost(value)) stringResource(R.string.management_dates_error_costs) else null, keyboard = KeyboardType.Decimal)
        }
        error("cost_breakdown")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Gli avvisi accanto all'orario: stessa sera in zona e chiusura straordinaria. Non bloccano. */
@Composable
private fun Checks(checks: EventDateChecksResult) {
    if (checks.sameNight.isNotEmpty()) {
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(stringResource(R.string.management_events_same_night), style = MaterialTheme.typography.titleSmall)
            Text(stringResource(R.string.management_events_same_night_hint, checks.radiusKm.let { if (it % 1.0 == 0.0) it.toInt().toString() else it.toString() }), color = Muted)
            checks.sameNight.forEach { item -> Text(stringResource(R.string.management_events_same_night_row, item.time, item.title, item.place)) }
        }
    }
    checks.closure?.let { closure ->
        val format = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale.ITALIAN)
        fun day(value: String) = runCatching { LocalDate.parse(value.take(10)).format(format) }.getOrDefault(value)
        Text(stringResource(R.string.management_events_closure), style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.error)
        Text(stringResource(R.string.management_events_closure_body, day(closure.from), day(closure.to), closure.note?.let { " ($it)" } ?: ""), color = MaterialTheme.colorScheme.error)
    }
}

private val DEFAULT_ROLES = listOf("live", "dj", "opening", "special_guest", "speaker")
private val DEFAULT_STATUSES = listOf("scheduled", "cancelled", "sold_out", "postponed", "moved")
