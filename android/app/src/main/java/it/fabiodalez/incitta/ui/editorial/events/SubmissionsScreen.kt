package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionDetail
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionRow
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.RichTextField
import java.time.ZoneId

/**
 * Le proposte dal pubblico (R-SB-01): dalla più recente, con chi l'ha
 * mandata, quando dice che si fa (nell'ora della sua città), il luogo
 * indicato e lo stato; il filtro di stato del pannello.
 */
@Composable
internal fun SubmissionsScreen(viewModel: SubmissionsViewModel, padding: PaddingValues, statuses: List<OptionChoice>, onBack: () -> Unit, onOpen: (Long) -> Unit) {
    val state by viewModel.rows.collectAsStateWithLifecycle()
    SubmissionsContent(state, padding, statuses, onBack, viewModel.list::search, viewModel.list::loadMore, viewModel.list::retry, viewModel.list::applyFilters, onOpen)
}

@Composable
internal fun SubmissionsContent(
    state: PagedState<EditorialSubmissionRow>,
    padding: PaddingValues,
    statuses: List<OptionChoice>,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onOpen: (Long) -> Unit,
) {
    PagedListScreen(
        title = stringResource(R.string.editorial_submissions_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        filters = listOf(FilterDefinition("status", stringResource(R.string.editorial_submissions_filter_status), statuses)).filter { it.options.isNotEmpty() },
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_submissions_search),
        emptyText = stringResource(R.string.editorial_submissions_empty),
    ) { row ->
        val zone = row.city?.zone ?: ZoneId.of("Europe/Rome")
        val openLabel = stringResource(R.string.editorial_submissions_open, row.title)
        Surface(
            shape = RoundedCornerShape(12.dp),
            tonalElevation = 1.dp,
            modifier = Modifier.fillMaxWidth().clickable(onClickLabel = openLabel) { onOpen(row.id) },
        ) {
            Column(Modifier.padding(12.dp).heightIn(min = 48.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(row.title, Modifier.weight(1f), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                    Text(row.status.label, color = Muted)
                }
                row.contactName?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.editorial_submissions_from, it), color = Muted) }
                Text(listOfNotNull(editorialMoment(row.startsAtHint, zone), row.venueHint?.takeIf { it.isNotBlank() }, row.city?.name).joinToString(" · "))
                editorialMoment(row.createdAt, zone)?.let { Text(stringResource(R.string.editorial_submissions_received, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
        }
    }
}

/**
 * «Esamina» (R-SB-02…05): i dati ricevuti, il modulo dell'evento già
 * compilato (titolo, descrizione, locale della città, categoria, inizio e
 * fine nell'ora della città, ingresso e prezzo), «Crea e pubblica» per chi
 * può pubblicare, «Rifiuta proposta» con il motivo interno, «Apri l'evento»
 * quando è già stata pubblicata.
 */
@Composable
internal fun SubmissionReviewScreen(viewModel: SubmissionReviewViewModel, padding: PaddingValues, onBack: () -> Unit, onOpenEvent: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val draft by viewModel.draft.collectAsStateWithLifecycle()
    val errors by viewModel.errors.collectAsStateWithLifecycle()
    val action by viewModel.action.collectAsStateWithLifecycle()
    val published by viewModel.published.collectAsStateWithLifecycle()
    var rejecting by remember { mutableStateOf(false) }
    LaunchedEffect(published) { published?.let { viewModel.consumePublished(); onOpenEvent(it) } }
    val required = stringResource(R.string.editorial_submission_required)
    ManagementScaffold(title = stringResource(R.string.editorial_submission_title), padding = padding, onBack = onBack, busy = action.busy) {
        LoadState(state, onRetry = viewModel::load) { detail ->
            SubmissionReviewContent(
                detail = detail, draft = draft, errors = errors, busy = action.busy,
                message = action.error ?: action.message, isError = action.error != null,
                onEdit = viewModel::edit, onPublish = { viewModel.publish(required) },
                onReject = { rejecting = true }, onOpenEvent = onOpenEvent,
            )
        }
    }
    val detail = (state as? Loadable.Ready)?.value
    if (rejecting && detail != null) {
        ReasonDialog(
            title = stringResource(R.string.editorial_submission_reject_title),
            text = stringResource(R.string.editorial_submission_reject_text),
            confirmLabel = stringResource(R.string.editorial_submission_reject),
            onConfirm = { rejecting = false; viewModel.reject(it) },
            onDismiss = { rejecting = false },
            rules = ReasonRules(min = 1, max = detail.limits.notes),
            destructive = true,
            busy = action.busy,
            fieldLabel = stringResource(R.string.editorial_submission_reject_field),
        )
    }
}

@Composable
internal fun SubmissionReviewContent(
    detail: EditorialSubmissionDetail,
    draft: SubmissionDraft,
    errors: FormErrors,
    busy: Boolean,
    message: String?,
    isError: Boolean,
    onEdit: ((SubmissionDraft) -> SubmissionDraft) -> Unit,
    onPublish: () -> Unit,
    onReject: () -> Unit,
    onOpenEvent: (Long) -> Unit,
) {
    val zone = detail.zone
    val can = detail.can
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        message?.let { Text(it, color = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary) }
        Text(stringResource(R.string.editorial_submission_received), style = MaterialTheme.typography.titleLarge)
        Labeled(stringResource(R.string.editorial_submission_contact), detail.contactName)
        Labeled(stringResource(R.string.editorial_submission_email), detail.contactEmail)
        Labeled(stringResource(R.string.editorial_submission_place), detail.venueHint)
        Labeled(stringResource(R.string.editorial_submission_when), editorialMoment(detail.startsAtHint, zone))
        Labeled(stringResource(R.string.editorial_submission_status), detail.status.label)
        Labeled(stringResource(R.string.editorial_submission_text), RichText.plain(RichText.toMarkup(detail.rawText)))
        Labeled(stringResource(R.string.editorial_submission_notes), detail.notes)
        Text(stringResource(R.string.editorial_submission_private), color = Muted, style = MaterialTheme.typography.bodySmall)

        if (can["open_event"] == true && detail.eventId != null) {
            Button(onClick = { onOpenEvent(detail.eventId) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_submission_open_event))
            }
        }
        if (can["reject"] == true) {
            OutlinedButton(onClick = onReject, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_submission_reject), color = MaterialTheme.colorScheme.error)
            }
        }

        val choices = detail.choices
        if (detail.form != null && choices != null) {
            Text(stringResource(R.string.editorial_submission_form), style = MaterialTheme.typography.titleLarge)
            Text(stringResource(R.string.editorial_submission_form_help), color = Muted)
            if (can["publish"] != true) Text(stringResource(R.string.editorial_submission_publish_denied), color = Muted)
            val editable = can["publish"] == true && !busy
            FormScaffold(errors = errors, busy = busy, submitLabel = stringResource(R.string.editorial_submission_publish), onSubmit = onPublish, submitEnabled = can["publish"] == true) {
                FormTextField(draft.title, { v -> onEdit { it.copy(title = v) } }, stringResource(R.string.editorial_submission_field_title), errors, "title", enabled = editable)
                RichTextField(draft.description, { v -> onEdit { it.copy(description = v) } }, stringResource(R.string.editorial_submission_field_description), error = errors.field("raw_text"), enabled = editable)
                ChoiceField(stringResource(R.string.editorial_submission_field_venue), choices.venues.map { it.toOption() }, draft.venueId, errors.field("venue_id"), editable) { v -> onEdit { it.copy(venueId = v) } }
                ChoiceField(stringResource(R.string.editorial_submission_field_category), choices.categories.map { it.toOption() }, draft.categoryId, errors.field("category_id"), editable) { v -> onEdit { it.copy(categoryId = v) } }
                CityDateTimeField(stringResource(R.string.editorial_submission_field_starts), draft.startsAt, zone, detail.city?.name, { v -> onEdit { it.copy(startsAt = v) } }, error = errors.field("starts_at_hint"), enabled = editable)
                CityDateTimeField(stringResource(R.string.editorial_submission_field_ends), draft.endsAt, zone, detail.city?.name, { v -> onEdit { it.copy(endsAt = v) } }, error = errors.field("ends_at"), enabled = editable, clearable = true)
                ChoiceField(stringResource(R.string.editorial_submission_field_price_type), choices.priceTypes.map { it.toOption() }, draft.priceType, errors.field("price_type"), editable) { v -> onEdit { it.copy(priceType = v) } }
                FormTextField(draft.priceMin, { v -> onEdit { it.copy(priceMin = v) } }, stringResource(R.string.editorial_submission_field_price_min), errors, "price_min", enabled = editable, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun Labeled(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Column {
        Text(label, color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(value)
    }
}

/** Una scelta da un elenco (locale, categoria, ingresso) in un menu a tendina. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ChoiceField(label: String, options: List<OptionChoice>, selected: String?, error: String?, enabled: Boolean, onSelect: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val current = options.firstOrNull { it.value == selected }?.label.orEmpty()
    ExposedDropdownMenuBox(expanded = open && enabled, onExpandedChange = { if (enabled) open = it }) {
        OutlinedTextField(
            value = current,
            onValueChange = {},
            readOnly = true,
            enabled = enabled,
            label = { Text(label) },
            placeholder = { Text(stringResource(R.string.editorial_submission_choose)) },
            isError = error != null,
            supportingText = error?.let { { Text(it) } },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable, enabled),
        )
        ExposedDropdownMenu(expanded = open && enabled, onDismissRequest = { open = false }) {
            options.forEach { option ->
                DropdownMenuItem(text = { Text(option.label) }, onClick = { open = false; onSelect(option.value) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
}
