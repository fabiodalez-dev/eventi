package it.fabiodalez.incitta.ui.management.collaborators

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.management.CheckinInvitation
import it.fabiodalez.incitta.data.management.InvitationAnswer
import it.fabiodalez.incitta.data.management.ManagementPeopleSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ReceivedInvitations
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.VenueAccess
import it.fabiodalez.incitta.data.management.VenueInvitation
import it.fabiodalez.incitta.data.management.VenueMember
import it.fabiodalez.incitta.data.management.VenueMembersMeta
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * P8: i collaboratori del locale (W-CO-01…05), «Il tuo accesso» (W-CO-06) e
 * gli inviti ricevuti (W-CO-07, W-TK-15). Le regole le decide il server, con
 * le stesse classi della pagina del pannello: i ViewModel portano le
 * richieste e tengono lo stato, che sopravvive alla rotazione.
 */

/** Il locale aperto nella gestione: dalla scelta del menu (`venue:12`), salvato nella voce di navigazione. */
internal fun selectedVenueId(repository: ManagementRepository, saved: SavedStateHandle, key: String): Long {
    saved.get<Long>(key)?.let { return it }
    val selected = repository.selectedSubjectKey
    val id = repository.subjects.value?.find(selected)?.takeIf { it.kind == SubjectKind.Venue }?.id
        ?: selected?.takeIf { it.startsWith("venue:") }?.removePrefix("venue:")?.toLongOrNull()
        ?: 0L
    if (id > 0) saved[key] = id
    return id
}

/** Un'azione confermata su una riga: la riga, e quale. */
internal enum class MemberAction { Resend, Withdraw, Remove }

internal data class CollaboratorsUiState(
    val access: Loadable<VenueAccess> = Loadable.Loading,
    val members: List<VenueMember> = emptyList(),
    val meta: VenueMembersMeta = VenueMembersMeta(),
    val query: String = "",
    val loaded: Boolean = false,
    val busy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val inviteOpen: Boolean = false,
    val inviteName: String = "",
    val inviteEmail: String = "",
    val inviteErrors: FormErrors = FormErrors.None,
    val inviteBusy: Boolean = false,
) {
    val canManage: Boolean get() = (access as? Loadable.Ready)?.value?.canManageCollaborators == true
}

/** «Collaboratori»: per tutti la voce «Il tuo accesso», per il referente l'elenco, l'invito e le azioni sulle righe. */
internal class CollaboratorsViewModel(repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    private val people: ManagementPeopleSource? = repository.people
    val venueId: Long = selectedVenueId(repository, saved, KEY_VENUE)
    private val state = MutableStateFlow(
        CollaboratorsUiState(
            query = saved[KEY_QUERY] ?: "",
            inviteOpen = saved[KEY_INVITE_OPEN] ?: false,
            inviteName = saved[KEY_INVITE_NAME] ?: "",
            inviteEmail = saved[KEY_INVITE_EMAIL] ?: "",
        ),
    )
    val uiState: StateFlow<CollaboratorsUiState> = state.asStateFlow()
    private var loading: Job? = null

    init { load() }

    /** Prima il proprio accesso (dice se si è referenti), poi, per il referente, l'elenco. */
    fun load() {
        val api = people
        if (api == null || venueId <= 0) { state.update { it.copy(access = Loadable.Failed(NO_VENUE)) }; return }
        state.update { it.copy(access = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try {
                val access = api.access(venueId)
                state.update { it.copy(access = Loadable.Ready(access)) }
                if (access.canManageCollaborators) loadMembers(page = 1)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(access = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun setQuery(value: String) {
        saved[KEY_QUERY] = value
        state.update { it.copy(query = value) }
    }

    fun search() { if (state.value.canManage) loadMembers(page = 1) }
    fun more() { val page = state.value.meta.nextPage ?: return; if (!state.value.busy) loadMembers(page) }
    fun clearMessage() = state.update { it.copy(message = null) }

    private fun loadMembers(page: Int) {
        val api = people ?: return
        loading?.cancel()
        state.update { it.copy(busy = true, error = null) }
        loading = viewModelScope.launch {
            try {
                val response = api.members(venueId, state.value.query, page)
                state.update { current ->
                    val rows = if (page == 1) response.data else (current.members + response.data).distinctBy(VenueMember::id)
                    current.copy(members = rows, meta = response.meta, loaded = true)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun openInvite(open: Boolean) {
        saved[KEY_INVITE_OPEN] = open
        state.update { it.copy(inviteOpen = open, inviteErrors = FormErrors.None) }
    }

    fun setInviteName(value: String) { saved[KEY_INVITE_NAME] = value; state.update { it.copy(inviteName = value) } }
    fun setInviteEmail(value: String) { saved[KEY_INVITE_EMAIL] = value; state.update { it.copy(inviteEmail = value) } }

    fun invite() {
        val api = people ?: return
        val current = state.value
        if (current.inviteBusy) return
        state.update { it.copy(inviteBusy = true, inviteErrors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val change = api.invite(venueId, current.inviteName, current.inviteEmail)
                saved[KEY_INVITE_NAME] = ""
                saved[KEY_INVITE_EMAIL] = ""
                saved[KEY_INVITE_OPEN] = false
                state.update { it.copy(inviteOpen = false, inviteName = "", inviteEmail = "", message = change.message) }
                loadMembers(page = 1)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(inviteErrors = FormErrors.from(e, INVITE_FIELDS)) } }
            finally { state.update { it.copy(inviteBusy = false) } }
        }
    }

    /** Reinvia, ritira o togli, dopo la conferma; poi l'elenco si rilegge. */
    fun act(member: VenueMember, action: MemberAction) {
        val api = people ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val message = when (action) {
                    MemberAction.Resend -> api.resend(venueId, member.id).message
                    MemberAction.Withdraw -> api.withdraw(venueId, member.id)
                    MemberAction.Remove -> api.remove(venueId, member.id)
                }
                state.update { it.copy(message = message, busy = false) }
                loadMembers(page = 1)
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e), busy = false) } }
        }
    }

    companion object {
        const val KEY_VENUE = "venue"
        const val KEY_QUERY = "query"
        const val KEY_INVITE_OPEN = "invite_open"
        const val KEY_INVITE_NAME = "invite_name"
        const val KEY_INVITE_EMAIL = "invite_email"
        val INVITE_FIELDS = listOf("name", "email")
        const val NO_VENUE = "Apri un locale dalla gestione per vedere i suoi collaboratori."
    }
}

internal data class MyAccessUiState(
    val access: Loadable<VenueAccess> = Loadable.Loading,
    val busy: Boolean = false,
    val error: String? = null,
    /** Uscita riuscita: quanti locali e organizzazioni restano. La schermata decide dove tornare. */
    val left: Int? = null,
)

/** «Il tuo accesso» e «Lascia il locale». */
internal class MyAccessViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    private val people: ManagementPeopleSource? = repository.people
    val venueId: Long = selectedVenueId(repository, saved, CollaboratorsViewModel.KEY_VENUE)
    private val state = MutableStateFlow(MyAccessUiState())
    val uiState: StateFlow<MyAccessUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        val api = people
        if (api == null || venueId <= 0) { state.update { it.copy(access = Loadable.Failed(CollaboratorsViewModel.NO_VENUE)) }; return }
        state.update { it.copy(access = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try { state.update { it.copy(access = Loadable.Ready(api.access(venueId))) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(access = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /**
     * Esce dal locale. Dopo, l'elenco dei soggetti si rilegge e la scelta si
     * azzera: il locale lasciato non deve restare aperto da nessuna parte.
     */
    fun leave() {
        val api = people ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val result = api.leave(venueId)
                repository.selectedSubjectKey = null
                try { repository.loadSubjects() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* La pagina iniziale riprova da sola. */ }
                state.update { it.copy(left = result.remaining) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }
}

/** Dove porta un invito accettato: nel locale, o nella scheda della data. */
internal sealed interface InvitationOutcome {
    data class Venue(val key: String) : InvitationOutcome
    data class CheckinDate(val occurrenceId: Long) : InvitationOutcome
}

internal data class InvitationsUiState(
    val invitations: Loadable<ReceivedInvitations> = Loadable.Loading,
    val busy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val outcome: InvitationOutcome? = null,
)

/** «I tuoi inviti»: rispondere a un invito in un locale o al personale d'ingresso. */
internal class InvitationsViewModel(private val repository: ManagementRepository) : ViewModel() {
    private val people: ManagementPeopleSource? = repository.people
    private val state = MutableStateFlow(InvitationsUiState())
    val uiState: StateFlow<InvitationsUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        val api = people ?: run { state.update { it.copy(invitations = Loadable.Empty) }; return }
        state.update { it.copy(invitations = Loadable.Loading, error = null) }
        viewModelScope.launch {
            try { show(api.invitations()) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(invitations = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun answerVenue(invitation: VenueInvitation, accept: Boolean) = answer { api ->
        val answer = api.answerVenue(invitation.venue.id, invitation.sent, accept)
        if (answer.accepted) {
            val key = answer.subject?.key ?: "venue:${invitation.venue.id}"
            repository.selectedSubjectKey = key
            try { repository.loadSubjects() } catch (e: CancellationException) { throw e } catch (_: Exception) { /* La pagina iniziale riprova da sola. */ }
            state.update { it.copy(outcome = InvitationOutcome.Venue(key)) }
        }
        answer
    }

    fun answerCheckin(invitation: CheckinInvitation, accept: Boolean) = answer { api ->
        val answer = api.answerCheckin(invitation.id, invitation.sent, accept)
        val date = answer.occurrenceId ?: invitation.occurrence.id
        if (answer.accepted && date > 0) state.update { it.copy(outcome = InvitationOutcome.CheckinDate(date)) }
        answer
    }

    fun outcomeHandled() = state.update { it.copy(outcome = null) }

    private fun answer(call: suspend (ManagementPeopleSource) -> InvitationAnswer) {
        val api = people ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null, message = null) }
        viewModelScope.launch {
            try {
                val answer = call(api)
                state.update { it.copy(message = answer.message.ifBlank { null }) }
                if (!answer.accepted) show(api.invitations())
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                state.update { it.copy(error = requestFailureMessage(e)) }
                /* Un invito ritirato o scaduto nel frattempo: l'elenco si rilegge. */
                try { show(api.invitations()) } catch (inner: CancellationException) { throw inner } catch (_: Exception) {}
            } finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun show(invitations: ReceivedInvitations) {
        state.update { it.copy(invitations = if (invitations.isEmpty) Loadable.Empty else Loadable.Ready(invitations)) }
    }
}
