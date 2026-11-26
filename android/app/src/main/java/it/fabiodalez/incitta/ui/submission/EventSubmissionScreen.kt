package it.fabiodalez.incitta.ui.submission

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.viewmodel.viewModelFactory
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.EventSubmissionApi
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.SubmissionVenue
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichTextField

/**
 * Chi apre «Proponi un evento»: `InCittaApp` lo fornisce, `OrganizeCta` lo
 * usa per il pulsante. Senza (anteprime, test) il pulsante apre il sito.
 */
internal val LocalProposeEvent = compositionLocalOf<(() -> Unit)?> { null }

/**
 * La schermata dentro l'app: il ViewModel vive con l'attività, così una
 * proposta lasciata a metà si ritrova riaprendo il modulo. Da collegati email
 * e nome dell'account riempiono i campi vuoti.
 */
@Composable
internal fun EventSubmissionRoute(session: Session?, padding: PaddingValues, onBack: () -> Unit, client: ApiClient? = null) {
    val context = LocalContext.current
    val viewModel: EventSubmissionViewModel = viewModel(
        key = "event-submission",
        factory = viewModelFactory {
            initializer {
                val api = client ?: ApiClient(LocalStore(context.applicationContext).installationId)
                EventSubmissionViewModel(EventSubmissionApi(api), createSavedStateHandle(), session?.user?.email, session?.user?.name)
            }
        },
    )
    LaunchedEffect(session?.user?.id) { viewModel.prefill(session?.user?.email, session?.user?.name) }
    EventSubmissionScreen(viewModel, padding, onBack)
}

@Composable
internal fun EventSubmissionScreen(viewModel: EventSubmissionViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    /* Dopo l'invio si esce con il modulo già vuoto: la prossima proposta parte da capo. */
    val close = { if (state.receipt != null) viewModel.another(); onBack() }
    BackHandler(onBack = close)
    var picking by rememberSaveable { mutableStateOf(false) }
    ManagementScaffold(
        title = stringResource(R.string.event_submission_title),
        padding = padding,
        onBack = close,
        subtitle = if (state.receipt == null) stringResource(R.string.event_submission_lead, state.city.name) else null,
    ) {
        val receipt = state.receipt
        if (receipt != null) SubmissionSent(receipt.message, onAnother = viewModel::another, onClose = close)
        else SubmissionForm(state, viewModel, onPickVenue = { picking = true; viewModel.openPicker() })
    }
    if (picking) {
        VenuePickerSheet(
            state.picker,
            onQuery = viewModel::setVenueQuery,
            onRetry = viewModel::retryVenues,
            onPick = { venue -> viewModel.chooseVenue(venue); picking = false },
            onDismiss = { picking = false },
        )
    }
}

/** Le tre parti del modulo del sito: l'evento, dove, come ricontattarti. */
@Composable
private fun SubmissionForm(state: EventSubmissionUi, viewModel: EventSubmissionViewModel, onPickVenue: () -> Unit) {
    val draft = state.draft
    val errors = state.errors
    FormScaffold(errors, state.busy, stringResource(R.string.event_submission_submit), onSubmit = viewModel::submit) {
        SectionTitle(stringResource(R.string.event_submission_section_event))
        val titleError = state.missing[EventSubmissionViewModel.FIELD_TITLE]?.let { stringResource(it) } ?: errors.field("title")
        OutlinedTextField(
            value = draft.title,
            onValueChange = { v -> viewModel.update { it.copy(title = v) } },
            label = { Text(stringResource(R.string.event_submission_field_title) + " *") },
            singleLine = true,
            isError = titleError != null,
            supportingText = titleError?.let { text -> { Text(text) } },
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Next),
            modifier = Modifier.fillMaxWidth(),
        )
        CityDateTimeField(
            label = stringResource(R.string.event_submission_field_starts_at),
            value = draft.startsAtLocal,
            zone = state.zone,
            cityName = state.city.name,
            onChange = viewModel::setStartsAt,
            error = errors.field("starts_at_hint"),
            enabled = !state.busy,
            clearable = true,
        )
        Text(stringResource(R.string.event_submission_hint_starts_at), color = Muted, style = MaterialTheme.typography.bodySmall)
        RichTextField(
            draft.rawText, { v -> viewModel.update { it.copy(rawText = v) } }, stringResource(R.string.event_submission_field_raw_text),
            errors, "raw_text", help = stringResource(R.string.event_submission_hint_raw_text), minLines = 5,
        )

        SectionTitle(stringResource(R.string.event_submission_section_place))
        VenueChoice(draft.venueName, errors.field("venue_id"), enabled = !state.busy, onOpen = onPickVenue, onClear = { viewModel.chooseVenue(null) })
        FormTextField(
            draft.venueHint, { v -> viewModel.update { it.copy(venueHint = v) } }, stringResource(R.string.event_submission_field_venue_hint),
            errors, "venue_hint", singleLine = true, help = stringResource(R.string.event_submission_hint_venue_hint),
        )

        SectionTitle(stringResource(R.string.event_submission_section_contact))
        Text(stringResource(R.string.event_submission_privacy), color = Muted, style = MaterialTheme.typography.bodySmall)
        FormTextField(
            draft.contactName, { v -> viewModel.update { it.copy(contactName = v) } }, stringResource(R.string.event_submission_field_contact_name),
            errors, "contact_name", singleLine = true,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Next),
        )
        val emailError = state.missing[EventSubmissionViewModel.FIELD_EMAIL]?.let { stringResource(it) } ?: errors.field("contact_email")
        OutlinedTextField(
            value = draft.contactEmail,
            onValueChange = { v -> viewModel.update { it.copy(contactEmail = v) } },
            label = { Text(stringResource(R.string.event_submission_field_contact_email) + " *") },
            singleLine = true,
            isError = emailError != null,
            supportingText = emailError?.let { text -> { Text(text) } },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Done),
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 8.dp).semantics { heading() })
}

/** Il locale scelto (o la scelta da fare) e, se c'è, il pulsante per toglierlo. */
@Composable
private fun VenueChoice(name: String?, error: String?, enabled: Boolean, onOpen: () -> Unit, onClear: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.event_submission_field_venue), style = MaterialTheme.typography.titleMedium)
        val chosen = name?.let { stringResource(R.string.event_submission_venue_chosen, it) }
        OutlinedButton(
            enabled = enabled,
            onClick = onOpen,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).then(if (chosen != null) Modifier.semantics { stateDescription = chosen } else Modifier),
        ) {
            Icon(Icons.Outlined.Search, contentDescription = null)
            Spacer(Modifier.width(8.dp))
            Text(name ?: stringResource(R.string.event_submission_venue_choose))
        }
        if (name != null) {
            TextButton(enabled = enabled, onClick = onClear, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.event_submission_venue_clear))
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/**
 * L'elenco dei locali della città con la ricerca: lo stesso elenco del menu
 * del sito (i locali approvati, in ordine di nome), letto da `GET /v1/venues`.
 * In cima «Il locale non è in elenco», come la prima voce del menu del sito.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun VenuePickerSheet(
    picker: VenuePickerUi,
    onQuery: (String) -> Unit,
    onRetry: () -> Unit,
    onPick: (SubmissionVenue?) -> Unit,
    onDismiss: () -> Unit,
) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.event_submission_picker_title), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            OutlinedTextField(
                value = picker.query,
                onValueChange = onQuery,
                label = { Text(stringResource(R.string.event_submission_picker_search)) },
                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                modifier = Modifier.fillMaxWidth(),
            )
            VenueRow(stringResource(R.string.event_submission_venue_unknown), null) { onPick(null) }
            HorizontalDivider()
            val results = picker.results
            if (results != null) {
                LoadState(results, onRetry = onRetry, emptyText = stringResource(R.string.event_submission_picker_empty)) { venues ->
                    LazyColumn(Modifier.fillMaxWidth().heightIn(max = 420.dp)) {
                        items(venues, key = { it.id }) { venue ->
                            val place = listOfNotNull(venue.zone?.takeIf(String::isNotBlank), venue.municipality?.takeIf(String::isNotBlank)).distinct().joinToString(" · ")
                            VenueRow(venue.name, place.ifBlank { null }, stringResource(R.string.event_submission_picker_pick, venue.name)) { onPick(venue) }
                        }
                    }
                }
            }
            TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.event_submission_picker_close)) }
        }
    }
}

@Composable
private fun VenueRow(title: String, subtitle: String?, actionLabel: String? = null, onClick: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(role = Role.Button, onClickLabel = actionLabel, onClick = onClick).padding(vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Text(title, style = MaterialTheme.typography.bodyLarge)
        subtitle?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

/** Dopo l'invio: il messaggio del server e, chiaro, che la proposta non è ancora pubblicata. */
@Composable
private fun SubmissionSent(message: String, onAnother: () -> Unit, onClose: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.event_submission_sent_title), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
        if (message.isNotBlank()) Text(message)
        Text(stringResource(R.string.event_submission_sent_body), color = Muted)
        Button(onClick = onAnother, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.event_submission_sent_another)) }
        OutlinedButton(onClick = onClose, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.event_submission_sent_close)) }
    }
}
