package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.editorial.PersonChoice
import it.fabiodalez.incitta.data.editorial.VenueActivityRow
import it.fabiodalez.incitta.data.editorial.VenueEventRow
import it.fabiodalez.incitta.data.editorial.VenueMemberRow
import it.fabiodalez.incitta.data.editorial.VenueMembers
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.editorial.events.EditorialEventRoute
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Le persone del locale (R-VN-12), gli eventi collegati (R-VN-13) e la
 * cronologia delle modifiche (R-VN-14), come le tre sezioni sotto la
 * scheda del pannello.
 */

private val momentFormat = DateTimeFormatter.ofPattern("d/M/yyyy HH:mm", Locale.ITALIAN)

/** Quale finestra delle persone è aperta. */
internal sealed interface MemberDialog {
    data object Invite : MemberDialog
    data object Attach : MemberDialog
    data class Edit(val member: VenueMemberRow) : MemberDialog
    data class Remove(val member: VenueMemberRow) : MemberDialog
}

internal data class MembersUiState(
    val members: Loadable<VenueMembers> = Loadable.Loading,
    val dialog: MemberDialog? = null,
    val candidates: List<PersonChoice> = emptyList(),
    val busy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
)

internal class VenueMembersViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    private val state = MutableStateFlow(MembersUiState())
    val uiState: StateFlow<MembersUiState> = state.asStateFlow()
    private var search: Job? = null

    init { load() }

    fun load() {
        viewModelScope.launch {
            try { state.update { it.copy(members = Loadable.Ready(repository.call { source.members(venueId) })) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(members = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun open(dialog: MemberDialog) {
        state.update { it.copy(dialog = dialog, error = null, message = null, candidates = emptyList()) }
        if (dialog == MemberDialog.Attach) searchCandidates("")
    }

    fun close() = state.update { it.copy(dialog = null, error = null) }

    /** Gli account da aggiungere: email confermata e non ancora del locale, cercati dopo una breve pausa. */
    fun searchCandidates(q: String) {
        search?.cancel()
        search = viewModelScope.launch {
            delay(SEARCH_PAUSE)
            try { state.update { it.copy(candidates = repository.call { source.memberCandidates(venueId, q.trim()) }) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
        }
    }

    fun invite(name: String, email: String, role: String) = write { source.inviteMember(venueId, name.trim(), email.trim(), role) }
    fun attach(userId: Long, role: String) = write { source.attachMember(venueId, userId, role) }

    /** `accepted`: vero = l'accesso vale da adesso (o dalla data che aveva); falso = invito ancora da accettare. */
    fun update(member: VenueMemberRow, role: String, accepted: Boolean) =
        write { source.updateMember(venueId, member.id, role, if (accepted) member.acceptedAt ?: Instant.now().toString() else null) }

    fun remove(member: VenueMemberRow) = write { source.removeMember(venueId, member.id) }

    private fun write(call: suspend () -> VenueMembers) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { call() }
                state.update { current ->
                    val roles = result.roles.ifEmpty { (current.members as? Loadable.Ready)?.value?.roles.orEmpty() }
                    current.copy(members = Loadable.Ready(result.copy(roles = roles)), dialog = null, message = result.message)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private companion object { const val SEARCH_PAUSE = 350L }
}

@Composable
internal fun VenueMembersScreen(model: VenueMembersViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
    val roles = (state.members as? Loadable.Ready)?.value?.roles.orEmpty().map { OptionChoice(it.value, it.label) }
    ManagementScaffold(title = stringResource(R.string.editorial_venue_members_manage), padding = context.padding, onBack = context::back, busy = state.busy) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        if (state.dialog == null) state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(stringResource(R.string.editorial_venue_members_lead), color = Muted)
        FlowRowActions {
            Button(onClick = { model.open(MemberDialog.Invite) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_members_invite)) }
            OutlinedButton(onClick = { model.open(MemberDialog.Attach) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_members_attach)) }
        }
        LoadState(state.members, onRetry = model::load, emptyText = stringResource(R.string.editorial_venue_members_empty)) { loaded ->
            if (loaded.members.isEmpty()) Text(stringResource(R.string.editorial_venue_members_empty), color = Muted)
            loaded.members.forEach { member ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(member.name, style = MaterialTheme.typography.titleMedium)
                        Text(member.email, color = Muted, style = MaterialTheme.typography.bodySmall)
                        val accepted = cityLocalDateTime(member.acceptedAt, zone)?.format(momentFormat)
                        Text(listOfNotNull(member.role?.label, accepted?.let { stringResource(R.string.editorial_venue_member_accepted, it) } ?: stringResource(R.string.editorial_venue_member_pending)).joinToString(" · "),
                            color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                    ActionMenu(
                        listOf(RowAction("edit", stringResource(R.string.editorial_venue_members_edit)), RowAction("remove", stringResource(R.string.editorial_venue_members_remove), destructive = true)),
                        mapOf("edit" to true, "remove" to true),
                        onAction = { action -> model.open(if (action.key == "edit") MemberDialog.Edit(member) else MemberDialog.Remove(member)) },
                        subject = member.name,
                    )
                }
                HorizontalDivider()
            }
        }
    }
    when (val dialog = state.dialog) {
        MemberDialog.Invite -> InviteDialog(roles, state.busy, state.error, model::invite, model::close)
        MemberDialog.Attach -> AttachDialog(roles, state.candidates, state.busy, state.error, model::searchCandidates, model::attach, model::close)
        is MemberDialog.Edit -> EditMemberDialog(dialog.member, roles, state.busy, state.error, { role, accepted -> model.update(dialog.member, role, accepted) }, model::close)
        is MemberDialog.Remove -> ConfirmDialog(
            title = stringResource(R.string.editorial_venue_members_remove_title, dialog.member.name),
            text = stringResource(R.string.editorial_venue_members_remove_text),
            confirmLabel = stringResource(R.string.editorial_venue_members_remove),
            destructive = true,
            enabled = !state.busy,
            onConfirm = { model.remove(dialog.member) },
            onDismiss = model::close,
        )
        null -> Unit
    }
}

@Composable
private fun InviteDialog(roles: List<OptionChoice>, busy: Boolean, error: String?, onInvite: (String, String, String) -> Unit, onDismiss: () -> Unit) {
    var name by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    /* Il pannello propone «referente»: accreditare il referente di un locale appena approvato è il caso tipico. */
    var role by rememberSaveable { mutableStateOf("owner") }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_venue_members_invite)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_venue_members_invite_help), color = Muted)
                OutlinedTextField(name, { name = it }, label = { Text(stringResource(R.string.editorial_venue_name_person)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(email, { email = it }, label = { Text(stringResource(R.string.editorial_venue_email)) }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
                ChoiceField(stringResource(R.string.editorial_venue_members_role), roles, role, { role = it })
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && name.isNotBlank() && email.isNotBlank(), onClick = { onInvite(name, email, role) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_venue_members_send))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

@Composable
private fun AttachDialog(
    roles: List<OptionChoice>,
    candidates: List<PersonChoice>,
    busy: Boolean,
    error: String?,
    onSearch: (String) -> Unit,
    onAttach: (Long, String) -> Unit,
    onDismiss: () -> Unit,
) {
    var query by rememberSaveable { mutableStateOf("") }
    var chosen by rememberSaveable { mutableStateOf<Long?>(null) }
    var role by rememberSaveable { mutableStateOf("editor") }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_venue_members_attach)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_venue_members_attach_help), color = Muted)
                OutlinedTextField(query, { query = it; onSearch(it) }, label = { Text(stringResource(R.string.editorial_venues_people_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                PersonOptions(candidates, chosen, onChoose = { chosen = it.id })
                ChoiceField(stringResource(R.string.editorial_venue_members_role), roles, role, { role = it })
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && chosen != null, onClick = { chosen?.let { onAttach(it, role) } }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_venue_members_add))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

/** Le persone trovate, una riga per scelta (al massimo otto, per stare nella finestra). */
@Composable
internal fun PersonOptions(people: List<PersonChoice>, chosen: Long?, onChoose: (PersonChoice) -> Unit) {
    if (people.isEmpty()) {
        Text(stringResource(R.string.editorial_venues_people_none), color = Muted)
        return
    }
    Column {
        people.take(8).forEach { person ->
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                RadioButton(selected = chosen == person.id, onClick = { onChoose(person) })
                Column(Modifier.weight(1f)) {
                    Text(person.name, style = MaterialTheme.typography.bodyMedium)
                    Text(person.email, color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

@Composable
private fun EditMemberDialog(member: VenueMemberRow, roles: List<OptionChoice>, busy: Boolean, error: String?, onSave: (String, Boolean) -> Unit, onDismiss: () -> Unit) {
    var role by rememberSaveable { mutableStateOf(member.role?.value ?: "editor") }
    var accepted by rememberSaveable { mutableStateOf(!member.pending) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_venue_members_edit_title, member.name)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ChoiceField(stringResource(R.string.editorial_venue_members_role), roles, role, { role = it })
                SwitchRow(stringResource(R.string.editorial_venue_members_accepted), stringResource(R.string.editorial_venue_members_accepted_help), accepted) { accepted = it }
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = { onSave(role, accepted) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venues_save)) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

/** Gli eventi del locale: filtro di stato, ricerca sul titolo, i più recenti prima. */
internal class VenueEventsViewModel(
    repository: EditorialRepository,
    source: EditorialVenuesSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    val list = PagedListController(viewModelScope, ListQuery()) { query, page -> repository.call { source.venueEvents(venueId, query.params(page)) } }
    private val statuses = MutableStateFlow<List<OptionChoice>>(emptyList())
    val statusChoices: StateFlow<List<OptionChoice>> = statuses.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch { statuses.value = repository.options()?.choices("event_statuses").orEmpty() }
    }
}

/** La scheda nativa di un evento del locale (`EventRecordScreen` di R2), per chi lo può vedere; `null` altrimenti. */
internal fun venueEventRoute(event: VenueEventRow): EditorialEventRoute? =
    if (abilitiesOf(event.abilities)["view"] == true) EditorialEventRoute(event.id) else null

@Composable
internal fun VenueEventsScreen(model: VenueEventsViewModel, context: EditorialGraphContext) {
    val state by model.list.current.collectAsStateWithLifecycle()
    val statuses by model.statusChoices.collectAsStateWithLifecycle()
    val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
    PagedListScreen(
        title = stringResource(R.string.editorial_venue_events),
        padding = context.padding,
        state = state,
        onBack = context::back,
        onSearch = model.list::search,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        rowKey = VenueEventRow::id,
        filters = listOf(FilterDefinition("status", stringResource(R.string.editorial_venue_status), statuses)).filter { it.options.isNotEmpty() },
        onFilters = model.list::applyFilters,
        searchLabel = stringResource(R.string.editorial_venue_events_search),
        emptyText = stringResource(R.string.editorial_venue_events_empty),
        header = { Text(stringResource(R.string.editorial_venue_events_lead), color = Muted, style = MaterialTheme.typography.bodySmall) },
    ) { event ->
        val published = cityLocalDateTime(event.publishedAt, zone)?.format(momentFormat)
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 6.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(event.title, style = MaterialTheme.typography.titleMedium)
                Text(listOfNotNull(event.category, androidx.compose.ui.res.pluralStringResource(R.plurals.editorial_venue_dates_count, event.occurrencesCount, event.occurrencesCount),
                    published?.let { stringResource(R.string.editorial_venue_published_at, it) }).joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
                StatusLabel(event.status)
            }
            /* La scheda dell'evento è quella nativa dell'area Eventi (R2), con le sue decisioni. */
            venueEventRoute(event)?.let { route ->
                TextButton(onClick = { context.navController.navigate(route) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venues_open_record))
                }
            }
        }
        HorizontalDivider()
    }
}

internal data class ActivityUiState(val restoring: VenueActivityRow? = null, val busy: Boolean = false, val error: String? = null, val message: String? = null)

/** La cronologia delle modifiche, con «Ripristina» per chi può modificare. */
internal class VenueActivityViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    val list = PagedListController(viewModelScope, ListQuery()) { query, page -> repository.call { source.venueActivity(venueId, query.params(page)) } }
    private val state = MutableStateFlow(ActivityUiState())
    val uiState: StateFlow<ActivityUiState> = state.asStateFlow()

    init { list.refresh() }

    fun ask(row: VenueActivityRow) = state.update { it.copy(restoring = row, error = null, message = null) }
    fun dismiss() = state.update { it.copy(restoring = null, error = null) }

    fun restore() {
        val row = state.value.restoring ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                repository.call { source.restoreActivity(venueId, row.id) }
                state.update { it.copy(restoring = null, message = RESTORED) }
                list.refresh()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object { const val RESTORED = "restored" }
}

@Composable
internal fun VenueActivityScreen(model: VenueActivityViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
    PagedListScreen(
        title = stringResource(R.string.editorial_venue_activity),
        padding = context.padding,
        state = list,
        onBack = context::back,
        onSearch = model.list::search,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        rowKey = VenueActivityRow::id,
        emptyText = stringResource(R.string.editorial_venue_activity_empty),
        header = {
            if (state.message != null) Text(stringResource(R.string.editorial_venue_activity_restored), color = MaterialTheme.colorScheme.primary)
            if (state.restoring == null) state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        },
    ) { row ->
        val abilities = abilitiesOf(row.abilities)
        Column(Modifier.padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(listOfNotNull(cityLocalDateTime(row.createdAt, zone)?.format(momentFormat), row.event.label, row.causer).joinToString(" · "), style = MaterialTheme.typography.titleSmall)
            Text(row.changes, style = MaterialTheme.typography.bodySmall)
            if (abilities["restore"] == true) {
                TextButton(onClick = { model.ask(row) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_activity_restore)) }
            }
        }
        HorizontalDivider()
    }
    state.restoring?.let { row ->
        ConfirmDialog(
            title = stringResource(R.string.editorial_venue_activity_restore_title),
            text = stringResource(R.string.editorial_venue_activity_restore_text, row.restorableFields.joinToString(", ")) + (state.error?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.editorial_venue_activity_restore),
            enabled = !state.busy,
            onConfirm = model::restore,
            onDismiss = model::dismiss,
        )
    }
}
