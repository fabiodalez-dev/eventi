package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.LabeledValue
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.events.ChoiceChips
import it.fabiodalez.incitta.ui.management.events.ChoiceField
import it.fabiodalez.incitta.ui.management.events.CollapsibleSection
import it.fabiodalez.incitta.ui.management.events.PlainField
import it.fabiodalez.incitta.ui.management.events.RepeaterField
import it.fabiodalez.incitta.ui.management.events.VenuePickerField

/*
 * «Aggiungi data» e «Modifica» di una data (R-EV-19): i campi del modulo
 * della sezione «Date» del pannello, nell'ordine del pannello. Gli orari
 * sono nell'ora della città dell'evento.
 */

private val PRACTICAL_LABELS = listOf(
    "entrance_notes" to R.string.editorial_dates_entrance_notes,
    "membership_notes" to R.string.editorial_dates_membership_notes,
    "parking_notes" to R.string.editorial_dates_parking_notes,
    "transit_notes" to R.string.editorial_dates_transit_notes,
    "food_notes" to R.string.editorial_dates_food_notes,
    "start_notes" to R.string.editorial_dates_start_notes,
)

private fun List<LabeledValue>.choices(): List<Pair<String, String>> = map { it.value to it.label }

@Composable
internal fun DateEditorScreen(viewModel: DateEditorViewModel, padding: PaddingValues, onBack: () -> Unit, onSaved: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    LaunchedEffect(state.saved) { if (state.saved) { viewModel.consumeSaved(); onSaved() } }
    val draft = state.draft
    val errors = state.errors
    val zone = state.zone
    val city = state.meta.event.city.ifBlank { null }
    val choices = state.meta.choices
    ManagementScaffold(
        title = stringResource(if (state.creating) R.string.editorial_dates_new_title else R.string.editorial_dates_edit_title),
        padding = padding,
        onBack = onBack,
        subtitle = state.meta.event.title.ifBlank { null },
        busy = state.busy || state.loading,
    ) {
        if (state.loading) return@ManagementScaffold
        state.loadError?.let { message ->
            Text(message, color = MaterialTheme.colorScheme.error)
            OutlinedButton(onClick = viewModel::load, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
            return@ManagementScaffold
        }
        val general = errors.general ?: if (errors.fields.isNotEmpty()) stringResource(R.string.editorial_ee_fix) else null
        general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(stringResource(R.string.editorial_dates_timezone, city ?: zone.id), color = Muted)

        CollapsibleSection(stringResource(R.string.editorial_dates_practical), stringResource(R.string.editorial_dates_practical_hint)) {
            ChoiceField(stringResource(R.string.editorial_dates_accessibility),
                listOf("yes" to stringResource(R.string.editorial_dates_accessible), "no" to stringResource(R.string.editorial_dates_not_accessible), "unknown" to stringResource(R.string.editorial_dates_unknown)),
                draft.practical["accessibility"]?.ifBlank { null }, { v -> viewModel.edit { it.copy(practical = it.practical + ("accessibility" to v.orEmpty())) } }, allowNone = true)
            ChoiceField(stringResource(R.string.editorial_dates_membership), choices.memberships.choices(), draft.practical["membership"]?.ifBlank { null },
                { v -> viewModel.edit { it.copy(practical = it.practical + ("membership" to v.orEmpty())) } }, allowNone = true)
            PRACTICAL_LABELS.forEach { (key, label) ->
                PlainField(draft.practical[key].orEmpty(), { v -> viewModel.edit { it.copy(practical = it.practical + (key to v)) } }, stringResource(label),
                    error = errors.field("practical_details.$key"), minLines = 2)
            }
            Text(stringResource(R.string.editorial_dates_costs, state.meta.event.currency), style = MaterialTheme.typography.titleSmall)
            Text(stringResource(R.string.editorial_dates_costs_hint), color = Muted)
            EditorialDateDraft.COST_KEYS.forEach { key ->
                PlainField(draft.costs[key].orEmpty(), { v -> viewModel.edit { it.copy(costs = it.costs + (key to v)) } }, costLabel(key),
                    error = errors.field("cost_breakdown.$key"), keyboard = KeyboardType.Decimal)
            }
        }

        VenuePickerField(stringResource(R.string.editorial_dates_venue), draft.venue, state.venueResults.map { it.id to listOfNotNull(it.name, it.city).joinToString(" · ") },
            viewModel::searchVenues, { v -> viewModel.edit { it.copy(venue = v?.let { ref -> NamedRef(ref.id, state.venueResults.firstOrNull { r -> r.id == ref.id }?.name ?: ref.name) }) } },
            error = errors.field("venue_id"), help = stringResource(R.string.editorial_dates_venue_hint), allowNone = true)
        CityDateTimeField(stringResource(R.string.editorial_ee_starts_at), draft.startsAt, zone, city, { v -> viewModel.edit { it.copy(startsAt = v) } }, error = errors.field("starts_at"))
        CityDateTimeField(stringResource(R.string.editorial_ee_ends_at), draft.endsAt, zone, city, { v -> viewModel.edit { it.copy(endsAt = v) } }, error = errors.field("ends_at"), clearable = true)
        CityDateTimeField(stringResource(R.string.editorial_ee_doors_at), draft.doorsAt, zone, city, { v -> viewModel.edit { it.copy(doorsAt = v) } }, error = errors.field("doors_at"), clearable = true)
        val allDay = stringResource(R.string.editorial_ee_all_day)
        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(allDay, modifier = Modifier.weight(1f))
            Switch(checked = draft.allDay, onCheckedChange = { v -> viewModel.edit { it.copy(allDay = v) } }, modifier = Modifier.semantics { contentDescription = allDay })
        }
        ChoiceChips(stringResource(R.string.editorial_dates_status), choices.occurrenceStatuses.choices(), draft.status, { v -> viewModel.edit { it.copy(status = v ?: "scheduled") } },
            error = errors.field("status"))
        PlainField(draft.capacity, { v -> viewModel.edit { it.copy(capacity = v) } }, stringResource(R.string.editorial_dates_capacity), error = errors.field("capacity"),
            help = stringResource(R.string.editorial_dates_capacity_hint), keyboard = KeyboardType.Number)
        PlainField(draft.capacityLeft, { v -> viewModel.edit { it.copy(capacityLeft = v) } }, stringResource(R.string.editorial_dates_capacity_left), error = errors.field("capacity_left"),
            keyboard = KeyboardType.Number)
        PlainField(draft.highlight, { v -> viewModel.edit { it.copy(highlight = v) } }, stringResource(R.string.editorial_dates_highlight), error = errors.field("highlight"),
            help = stringResource(R.string.editorial_dates_highlight_hint))
        RichTextField(draft.statusNote, { v -> viewModel.edit { it.copy(statusNote = v) } }, stringResource(R.string.editorial_dates_status_note), error = errors.field("status_note"), minLines = 2)

        Text(stringResource(R.string.editorial_dates_tiers), style = MaterialTheme.typography.titleMedium)
        Text(stringResource(R.string.editorial_dates_tiers_hint), color = Muted)
        RepeaterField(draft.tiers, { v -> viewModel.edit { it.copy(tiers = v) } }, stringResource(R.string.editorial_dates_tier_add), 12, { DateTierRow() }, error = errors.field("ticket_tiers")) { row, update ->
            PlainField(row.name, { update(row.copy(name = it)) }, stringResource(R.string.editorial_dates_tier_name))
            PlainField(row.price, { update(row.copy(price = it)) }, stringResource(R.string.editorial_dates_tier_price), keyboard = KeyboardType.Decimal)
            ChoiceChips(stringResource(R.string.editorial_dates_tier_status), choices.ticketTierStatuses.choices(), row.status, { update(row.copy(status = it ?: "available")) })
            PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.editorial_dates_tier_url), keyboard = KeyboardType.Uri)
            PlainField(row.note, { update(row.copy(note = it)) }, stringResource(R.string.editorial_dates_tier_note))
        }

        Text(stringResource(R.string.editorial_dates_lineup), style = MaterialTheme.typography.titleMedium)
        RepeaterField(draft.lineup, { v -> viewModel.edit { it.copy(lineup = v) } }, stringResource(R.string.editorial_dates_lineup_add), 100, { LineupRow() }, error = errors.field("lineup")) { row, update ->
            PlainField(row.name, { update(row.copy(name = it)) }, stringResource(R.string.editorial_dates_lineup_name))
            ChoiceField(stringResource(R.string.editorial_dates_lineup_role), choices.lineupRoles.choices(), row.role, { update(row.copy(role = it ?: "live")) })
            CityDateTimeField(stringResource(R.string.editorial_dates_lineup_starts), row.startsAt, zone, city, { update(row.copy(startsAt = it)) }, clearable = true)
            PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.editorial_dates_lineup_url), keyboard = KeyboardType.Uri)
        }

        Button(enabled = !state.busy, onClick = viewModel::save, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("date-save")) {
            Text(stringResource(R.string.editorial_dates_save))
        }
    }
}

@Composable
private fun costLabel(key: String): String = when (key) {
    "admission" -> stringResource(R.string.editorial_dates_cost_admission)
    "drink" -> stringResource(R.string.editorial_dates_cost_drink)
    "membership" -> stringResource(R.string.editorial_dates_cost_membership)
    else -> stringResource(R.string.editorial_dates_cost_other)
}

