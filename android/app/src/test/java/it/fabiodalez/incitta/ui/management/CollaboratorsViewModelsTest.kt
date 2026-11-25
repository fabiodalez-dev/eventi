package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.AnsweredSubject
import it.fabiodalez.incitta.data.management.CheckinInvitation
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.InvitationAnswer
import it.fabiodalez.incitta.data.management.InvitedOccurrence
import it.fabiodalez.incitta.data.management.InvitedVenue
import it.fabiodalez.incitta.data.management.LeaveResult
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementPeopleSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.ReceivedInvitations
import it.fabiodalez.incitta.data.management.VenueAccess
import it.fabiodalez.incitta.data.management.VenueInvitation
import it.fabiodalez.incitta.data.management.VenueMember
import it.fabiodalez.incitta.data.management.VenueMemberChange
import it.fabiodalez.incitta.data.management.VenueMembersMeta
import it.fabiodalez.incitta.data.management.VenueMembersPage
import it.fabiodalez.incitta.ui.management.collaborators.CollaboratorsViewModel
import it.fabiodalez.incitta.ui.management.collaborators.InvitationOutcome
import it.fabiodalez.incitta.ui.management.collaborators.InvitationsViewModel
import it.fabiodalez.incitta.ui.management.collaborators.MemberAction
import it.fabiodalez.incitta.ui.management.collaborators.MyAccessViewModel
import it.fabiodalez.incitta.ui.management.common.Loadable
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* P8 con un server finto: chi vede l'elenco, l'invito con gli errori sotto il campo, il tetto, l'uscita e le risposte agli inviti. */
@OptIn(ExperimentalCoroutinesApi::class)
class CollaboratorsViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakePeople(var access: VenueAccess = VenueAccess(role = "owner", canLeave = true, canManageCollaborators = true)) : ManagementPeopleSource {
        val calls = mutableListOf<String>()
        var inviteFailure: Exception? = null
        var leaveFailure: Exception? = null
        var invitations = ReceivedInvitations(
            venues = listOf(VenueInvitation(InvitedVenue(12, "aurora", "Circolo Aurora"), "editor", "Collaboratore", sent = 100)),
            checkin = listOf(CheckinInvitation(9, InvitedOccurrence(41, "Concerto"), sent = 200)),
        )
        override suspend fun members(venueId: Long, query: String, page: Int): VenueMembersPage {
            calls += "members:$venueId:$query:$page"
            return VenueMembersPage(listOf(VenueMember(id = page.toLong(), email = "a$page@esempio.it")), VenueMembersMeta(nextPage = if (page == 1) 2 else null))
        }
        override suspend fun invite(venueId: Long, name: String, email: String): VenueMemberChange {
            calls += "invite:$venueId:$name:$email"
            inviteFailure?.let { throw it }
            return VenueMemberChange(VenueMember(id = 9, pending = true), "L’invito è partito verso $email.")
        }
        override suspend fun resend(venueId: Long, memberId: Long): VenueMemberChange { calls += "resend:$memberId"; return VenueMemberChange(message = "Invito reinviato") }
        override suspend fun withdraw(venueId: Long, memberId: Long): String { calls += "withdraw:$memberId"; return "Invito ritirato" }
        override suspend fun remove(venueId: Long, memberId: Long): String { calls += "remove:$memberId"; return "Collaboratore rimosso" }
        override suspend fun access(venueId: Long): VenueAccess { calls += "access:$venueId"; return access }
        override suspend fun leave(venueId: Long): LeaveResult { calls += "leave:$venueId"; leaveFailure?.let { throw it }; return LeaveResult("Hai lasciato", remaining = 1) }
        override suspend fun invitations(): ReceivedInvitations { calls += "invitations"; return invitations }
        override suspend fun answerVenue(venueId: Long, sent: Long, accept: Boolean): InvitationAnswer {
            calls += "venue:$venueId:$sent:$accept"
            invitations = invitations.copy(venues = emptyList())
            return if (accept) InvitationAnswer(true, "Hai accettato", AnsweredSubject("venue", venueId)) else InvitationAnswer(false, "Hai rifiutato")
        }
        override suspend fun answerCheckin(invitationId: Long, sent: Long, accept: Boolean): InvitationAnswer {
            calls += "checkin:$invitationId:$sent:$accept"
            invitations = invitations.copy(checkin = emptyList())
            return InvitationAnswer(accept, "ok", occurrenceId = if (accept) 41 else null)
        }
    }

    private fun repository(scope: CoroutineScope, people: FakePeople, identity: FakeIdentity = FakeIdentity()) =
        ManagementRepository(7, "token", FakeTicketing(), identity, null, MemoryDevice(), scope, people = people).also { it.selectedSubjectKey = "venue:12" }

    @Test fun theOwnerSeesTheListAndTheEditorOnlyHisAccess() = runTest(dispatcher) {
        val people = FakePeople()
        val saved = SavedStateHandle()
        val owner = CollaboratorsViewModel(repository(TestScope(testScheduler), people), saved)
        assertEquals(12L, saved.get<Long>(CollaboratorsViewModel.KEY_VENUE))
        assertEquals(listOf("access:12", "members:12::1"), people.calls)
        assertTrue(owner.uiState.value.canManage)
        owner.more()
        assertEquals(listOf(1L, 2L), owner.uiState.value.members.map { it.id })

        val editorPeople = FakePeople(VenueAccess(role = "editor", canLeave = true))
        val editor = CollaboratorsViewModel(repository(TestScope(testScheduler), editorPeople), SavedStateHandle())
        assertEquals(listOf("access:12"), editorPeople.calls)
        assertFalse(editor.uiState.value.canManage)
    }

    @Test fun inviteShowsServerErrorsUnderTheFieldAndTheLimitOnTop() = runTest(dispatcher) {
        val people = FakePeople()
        val saved = SavedStateHandle()
        val viewModel = CollaboratorsViewModel(repository(TestScope(testScheduler), people), saved)
        viewModel.openInvite(true)
        viewModel.setInviteName("Marta")
        viewModel.setInviteEmail("marta@esempio.it")
        assertEquals("marta@esempio.it", saved.get<String>(CollaboratorsViewModel.KEY_INVITE_EMAIL))

        people.inviteFailure = ApiException(422, ApiProblem("già invitato", "VALIDATION_FAILED", mapOf("email" to listOf("C’è già un invito in attesa"))))
        viewModel.invite()
        assertEquals("C’è già un invito in attesa", viewModel.uiState.value.inviteErrors.field("email"))
        assertTrue(viewModel.uiState.value.inviteOpen)

        people.inviteFailure = ApiException(429, ApiProblem("Hai mandato molti inviti di seguito.", "RATE_LIMITED", mapOf("invite" to listOf("Hai mandato molti inviti di seguito."))))
        viewModel.invite()
        assertEquals("Hai mandato molti inviti di seguito.", viewModel.uiState.value.inviteErrors.general)

        people.inviteFailure = null
        viewModel.invite()
        assertFalse(viewModel.uiState.value.inviteOpen)
        assertEquals("", viewModel.uiState.value.inviteEmail)
        assertEquals("L’invito è partito verso marta@esempio.it.", viewModel.uiState.value.message)
        assertEquals("members:12::1", people.calls.last())
    }

    @Test fun rowActionsCallTheServerAndReloadTheList() = runTest(dispatcher) {
        val people = FakePeople()
        val viewModel = CollaboratorsViewModel(repository(TestScope(testScheduler), people), SavedStateHandle())
        val member = VenueMember(id = 5, canResend = true, canWithdraw = true)
        viewModel.act(member, MemberAction.Resend)
        assertEquals("Invito reinviato", viewModel.uiState.value.message)
        viewModel.act(member, MemberAction.Withdraw)
        viewModel.act(member.copy(canRemove = true), MemberAction.Remove)
        assertEquals(listOf("resend:5", "withdraw:5", "remove:5"), people.calls.filter { !it.startsWith("members") && !it.startsWith("access") })
        viewModel.setQuery("marta")
        viewModel.search()
        assertEquals("members:12:marta:1", people.calls.last())
    }

    @Test fun leavingRereadsTheSubjectsAndClearsTheChoice() = runTest(dispatcher) {
        val people = FakePeople(VenueAccess(role = "editor", canLeave = true))
        val identity = FakeIdentity(list = ManagementSubjects(venues = listOf(ManagedVenue(id = 30, name = "Altro locale"))))
        val repository = repository(TestScope(testScheduler), people, identity)
        val viewModel = MyAccessViewModel(repository, SavedStateHandle())
        people.leaveFailure = ApiException(409, ApiProblem("Sei l’unico referente", "CONFLICT"))
        viewModel.leave()
        assertEquals("Sei l’unico referente", viewModel.uiState.value.error)
        assertNull(viewModel.uiState.value.left)

        people.leaveFailure = null
        viewModel.leave()
        assertEquals(1, viewModel.uiState.value.left)
        assertNull(repository.selectedSubjectKey)
        assertEquals(1, identity.subjectCalls)
    }

    @Test fun answeringInvitationsLeadsToTheVenueOrTheDate() = runTest(dispatcher) {
        val people = FakePeople()
        val repository = repository(TestScope(testScheduler), people)
        repository.selectedSubjectKey = null
        val viewModel = InvitationsViewModel(repository)
        val received = (viewModel.uiState.value.invitations as Loadable.Ready).value

        viewModel.answerVenue(received.venues.single(), accept = true)
        assertEquals("venue:12:100:true", people.calls[1])
        assertEquals(InvitationOutcome.Venue("venue:12"), viewModel.uiState.value.outcome)
        assertEquals("venue:12", repository.selectedSubjectKey)
        viewModel.outcomeHandled()

        viewModel.answerCheckin(received.checkin.single(), accept = false)
        assertEquals("checkin:9:200:false", people.calls.last { it.startsWith("checkin") })
        assertNull(viewModel.uiState.value.outcome)
        assertEquals(Loadable.Empty, viewModel.uiState.value.invitations)
    }
}
