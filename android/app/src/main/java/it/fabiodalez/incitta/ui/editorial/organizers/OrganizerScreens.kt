package it.fabiodalez.incitta.ui.editorial.organizers

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.editorial.OrganizerDetail
import it.fabiodalez.incitta.data.editorial.OrganizerDraft
import it.fabiodalez.incitta.data.editorial.OrganizerRow
import it.fabiodalez.incitta.data.editorial.PersonChoice
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.venues.OrganizerFormRoute
import it.fabiodalez.incitta.ui.editorial.venues.PersonOptions
import it.fabiodalez.incitta.ui.editorial.venues.SwitchRow
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json

/*
 * Gli organizzatori (R-OR-01…02), solo admin e super admin: l'elenco con
 * ricerca, il nuovo e la modifica con i campi del pannello (nome, città,
 * responsabile, collaboratori, descrizione, sito, modulo di contatto, email
 * pubblica, attivo). Nessuna eliminazione, come nel pannello.
 */

private val draftJson = Json { ignoreUnknownKeys = true; encodeDefaults = true }

internal class OrganizerListViewModel(
    repository: EditorialRepository,
    source: EditorialVenuesSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery()) { query, page -> repository.call { source.organizers(query.params(page)) } }

    init { list.refresh() }
}

@Composable
internal fun OrganizerListScreen(model: OrganizerListViewModel, context: EditorialGraphContext) {
    val state by model.list.current.collectAsStateWithLifecycle()
    /* Tornando dal modulo l'elenco si rilegge: nomi e stati possono essere cambiati. */
    var visited by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (visited) model.list.refresh() else visited = true }
    PagedListScreen(
        title = stringResource(R.string.editorial_organizers_title),
        padding = context.padding,
        state = state,
        onBack = context::back,
        onSearch = model.list::search,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        rowKey = OrganizerRow::id,
        searchLabel = stringResource(R.string.editorial_organizers_search),
        emptyText = stringResource(R.string.editorial_organizers_empty),
        header = {
            Button(onClick = { context.navController.navigate(OrganizerFormRoute(0)) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_organizer_new))
            }
        },
    ) { row ->
        Row(
            Modifier.fillMaxWidth().clickable(role = Role.Button) { context.navController.navigate(OrganizerFormRoute(row.id)) }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(row.name, style = MaterialTheme.typography.titleMedium)
                Text(listOfNotNull(row.city?.name, row.owner?.let { "${it.name} · ${it.email}" }).joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
            Text(row.statusLabel, color = if (row.isActive) MaterialTheme.colorScheme.primary else Muted, style = MaterialTheme.typography.labelLarge)
        }
        HorizontalDivider()
    }
}

/** Quale scelta di persone è aperta: il responsabile o i collaboratori. */
internal enum class PeoplePicker { Owner, Users }

internal data class OrganizerFormUiState(
    val organizer: Loadable<OrganizerDetail> = Loadable.Loading,
    val draft: OrganizerDraft? = null,
    val cities: List<OptionChoice> = emptyList(),
    val contactModes: List<OptionChoice> = emptyList(),
    val picker: PeoplePicker? = null,
    val people: List<PersonChoice> = emptyList(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

internal class OrganizerFormViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    /* 0 = nuovo organizzatore; dopo il primo salvataggio diventa il suo id. */
    var organizerId: Long = saved.get<Long>(KEY_ORGANIZER) ?: 0L
        private set
    private val state = MutableStateFlow(OrganizerFormUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { draftJson.decodeFromString(OrganizerDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<OrganizerFormUiState> = state.asStateFlow()
    private var search: Job? = null

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                if (organizerId == 0L) {
                    val options = ManagementOptions(repository.call { source.organizerChoices() })
                    val city = repository.current.value?.city?.id?.toString().orEmpty()
                    state.update {
                        it.copy(organizer = Loadable.Ready(OrganizerDetail()), draft = it.draft ?: OrganizerDraft(cityId = city),
                            cities = options.choices("cities"), contactModes = options.choices("contact_modes"))
                    }
                } else {
                    val detail = repository.call { source.organizer(organizerId) }
                    state.update { it.copy(organizer = Loadable.Ready(detail), draft = it.draft ?: OrganizerDraft.from(detail), cities = detail.cities, contactModes = detail.contactModes) }
                }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(organizer = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (OrganizerDraft) -> OrganizerDraft) {
        state.update { it.copy(draft = it.draft?.let(change), message = null) }
        persist()
    }

    fun openPicker(picker: PeoplePicker) { state.update { it.copy(picker = picker, people = emptyList()) }; searchPeople("") }
    fun closePicker() = state.update { it.copy(picker = null) }

    /** Le persone per email o nome, dopo una breve pausa. */
    fun searchPeople(q: String) {
        search?.cancel()
        search = viewModelScope.launch {
            delay(SEARCH_PAUSE)
            try { state.update { it.copy(people = repository.call { source.people(q.trim()) }) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
        }
    }

    fun choose(person: PersonChoice) {
        when (state.value.picker) {
            PeoplePicker.Owner -> edit { it.copy(owner = person) }
            PeoplePicker.Users -> edit { draft -> if (draft.users.any { it.id == person.id }) draft else draft.copy(users = draft.users + person) }
            null -> Unit
        }
        if (state.value.picker == PeoplePicker.Owner) closePicker()
    }

    fun removeUser(person: PersonChoice) = edit { draft -> draft.copy(users = draft.users.filterNot { it.id == person.id }) }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val detail = repository.call { if (organizerId == 0L) source.createOrganizer(draft.payload()) else source.updateOrganizer(organizerId, draft.payload()) }
                organizerId = detail.id
                saved[KEY_ORGANIZER] = detail.id
                state.update { it.copy(organizer = Loadable.Ready(detail), draft = OrganizerDraft.from(detail), message = UiText.Res(R.string.editorial_venues_saved)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, ORGANIZER_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = draftJson.encodeToString(OrganizerDraft.serializer(), it) } }

    companion object {
        const val KEY_ORGANIZER = "organizerId"
        const val KEY_DRAFT = "draft"
        private const val SEARCH_PAUSE = 350L
        val ORGANIZER_FIELDS = listOf("name", "city_id", "owner_id", "users", "description", "website", "email", "contact_mode", "contact_email", "is_active")
    }
}

@Composable
internal fun OrganizerFormScreen(model: OrganizerFormViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    val uriHandler = LocalUriHandler.current
    val title = stringResource(if (model.organizerId == 0L) R.string.editorial_organizer_new else R.string.editorial_organizer_edit)
    ManagementScaffold(title = title, padding = context.padding, onBack = context::back, subtitle = state.draft?.name, busy = state.busy) {
        LoadState(state.organizer, onRetry = model::load) { organizer ->
            val draft = state.draft ?: return@LoadState
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.editorial_venues_save), onSubmit = model::save) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                OrganizerFields(draft, state.cities, state.contactModes, state.errors, model::edit, onPickOwner = { model.openPicker(PeoplePicker.Owner) },
                    onAddUser = { model.openPicker(PeoplePicker.Users) }, onRemoveUser = model::removeUser)
            }
            organizer.publicUrl?.let { url ->
                OutlinedButton(onClick = { runCatching { uriHandler.openUri(url) } }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_organizer_open_public)) }
            }
        }
    }
    state.picker?.let { picker -> PeopleDialog(picker, state.people, state.draft, model::searchPeople, model::choose, model::closePicker) }
}

/** I campi del modulo dell'organizzatore, senza ViewModel: li provano i test strumentati. */
@Composable
internal fun OrganizerFields(
    draft: OrganizerDraft,
    cities: List<OptionChoice>,
    contactModes: List<OptionChoice>,
    errors: FormErrors,
    onEdit: ((OrganizerDraft) -> OrganizerDraft) -> Unit,
    onPickOwner: () -> Unit,
    onAddUser: () -> Unit,
    onRemoveUser: (PersonChoice) -> Unit,
) {
    FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_organizer_name), errors, "name", singleLine = true)
    ChoiceField(stringResource(R.string.editorial_organizer_city), cities, draft.cityId, { v -> onEdit { it.copy(cityId = v) } }, errors.field("city_id"))
    Text(stringResource(R.string.editorial_organizer_owner), style = MaterialTheme.typography.titleMedium)
    Text(draft.owner?.let { "${it.name} · ${it.email}" } ?: stringResource(R.string.editorial_organizer_owner_none), color = Muted)
    errors.field("owner_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    OutlinedButton(onClick = onPickOwner, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_organizer_owner_choose)) }
    Text(stringResource(R.string.editorial_organizer_users), style = MaterialTheme.typography.titleMedium)
    errors.field("users")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    if (draft.users.isEmpty()) Text(stringResource(R.string.editorial_organizer_users_none), color = Muted)
    draft.users.forEach { person ->
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("${person.name} · ${person.email}", modifier = Modifier.weight(1f))
            TextButton(onClick = { onRemoveUser(person) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_members_remove)) }
        }
    }
    OutlinedButton(onClick = onAddUser, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_organizer_users_add)) }
    RichTextField(draft.description, { v -> onEdit { it.copy(description = v) } }, stringResource(R.string.editorial_organizer_description), errors, "description", minLines = 6)
    FormTextField(draft.website, { v -> onEdit { it.copy(website = v) } }, stringResource(R.string.editorial_organizer_website), errors, "website", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
    ChoiceField(stringResource(R.string.editorial_organizer_contact_mode), contactModes, draft.contactMode, { v -> onEdit { it.copy(contactMode = v) } }, errors.field("contact_mode"))
    if (draft.contactActive) {
        FormTextField(draft.contactEmail, { v -> onEdit { it.copy(contactEmail = v) } }, stringResource(R.string.editorial_organizer_contact_email), errors, "contact_email", singleLine = true,
            help = stringResource(R.string.editorial_organizer_contact_email_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
    }
    FormTextField(draft.email, { v -> onEdit { it.copy(email = v) } }, stringResource(R.string.editorial_organizer_email), errors, "email", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
    SwitchRow(stringResource(R.string.editorial_organizer_active), stringResource(R.string.editorial_organizer_active_help), draft.isActive) { v -> onEdit { it.copy(isActive = v) } }
}

@Composable
private fun PeopleDialog(picker: PeoplePicker, people: List<PersonChoice>, draft: OrganizerDraft?, onSearch: (String) -> Unit, onChoose: (PersonChoice) -> Unit, onDismiss: () -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    val chosen = if (picker == PeoplePicker.Owner) draft?.owner?.id else null
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(if (picker == PeoplePicker.Owner) R.string.editorial_organizer_owner_choose else R.string.editorial_organizer_users_add)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(query, { query = it; onSearch(it) }, label = { Text(stringResource(R.string.editorial_venues_people_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                PersonOptions(people.filterNot { person -> picker == PeoplePicker.Users && draft?.users?.any { it.id == person.id } == true }, chosen, onChoose)
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venues_done)) } },
    )
}
