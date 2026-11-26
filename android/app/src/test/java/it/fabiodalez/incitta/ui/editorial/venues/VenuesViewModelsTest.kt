package it.fabiodalez.incitta.ui.editorial.venues

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.*
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementMediaApi
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationDecision
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationDetailViewModel
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationListViewModel
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerFormViewModel
import it.fabiodalez.incitta.ui.editorial.organizers.PeoplePicker
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* I ViewModel di R4 con un server finto: che cosa chiedono, con quali dati, e che cosa mostrano dopo. */
@OptIn(ExperimentalCoroutinesApi::class)
class VenuesViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()
    private val source = FakeVenues()
    private val repository = EditorialRepository(1, "t", FakeDesk(unlocked = true), EditorialLocalLock(clock = { 0L }))

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    @Test fun theListAsksWithTheTabAndRefreshesAfterAModeration() = runTest(dispatcher) {
        val model = VenueListViewModel(repository, source, SavedStateHandle(mapOf(VenueListViewModel.KEY_TAB to "pending")))
        advanceUntilIdle()
        assertEquals(listOf("page" to "1", "per_page" to "20", "tab" to "pending"), source.listParams.single())
        assertEquals(listOf("Osteria"), model.list.current.value.items.map { it.name })

        model.ask(model.list.current.value.items.single(), VenueAction.Reject)
        model.confirm("Scheda copiata")
        advanceUntilIdle()
        assertEquals(listOf("reject:4:Scheda copiata"), source.actions)
        assertNull(model.uiState.value.pending)
        assertEquals("Rifiutato.", model.uiState.value.message)
        assertEquals(2, source.listParams.size) // l'elenco si rilegge
    }

    @Test fun aFailedActionKeepsTheDialogOpenWithTheServerMessage() = runTest(dispatcher) {
        source.failNext = ApiException(409, ApiProblem(code = "CONFLICT", message = "Già approvato"))
        val model = VenueDetailViewModel(repository, source, SavedStateHandle(mapOf(VenueDetailViewModel.KEY_VENUE to 4L)))
        advanceUntilIdle()
        model.ask(VenueAction.Approve)
        model.confirm("")
        advanceUntilIdle()
        assertNotNull(model.uiState.value.pending)
        assertNotNull(model.uiState.value.actionError)
    }

    @Test fun theDetailVerifiesTogglesTicketingAndLeavesAfterDeleting() = runTest(dispatcher) {
        val model = VenueDetailViewModel(repository, source, SavedStateHandle(mapOf(VenueDetailViewModel.KEY_VENUE to 4L)))
        advanceUntilIdle()
        assertEquals("Osteria", (model.uiState.value.venue as Loadable.Ready).value.name)
        model.ask(VenueAction.Verify)
        model.confirm("")
        model.setTicketing(true)
        model.askDelete()
        model.delete()
        advanceUntilIdle()
        assertEquals(listOf("verify:4:true", "ticketing:4:true", "delete:4"), source.actions)
        assertTrue(model.uiState.value.deleted)
    }

    @Test fun anEditorialSectionSavesOnlyItsKeysAndShowsFieldErrors() = runTest(dispatcher) {
        val saved = SavedStateHandle(mapOf(VenueDetailViewModel.KEY_VENUE to 4L, VenueEditorialSectionViewModel.KEY_SECTION to "Moderation"))
        val model = VenueEditorialSectionViewModel(repository, source, saved)
        advanceUntilIdle()
        model.edit { it.copy(status = "suspended", rejectionReason = "Chiuso") }
        /* Il modulo sopravvive alla morte del processo. */
        assertTrue(saved.get<String>(VenueEditorialSectionViewModel.KEY_DRAFT).orEmpty().contains("suspended"))
        source.failNext = ApiException(422, ApiProblem(code = "VALIDATION_FAILED", message = "x", fields = mapOf("status" to listOf("Stato non valido"))))
        model.save()
        advanceUntilIdle()
        assertEquals("Stato non valido", model.uiState.value.errors.field("status"))
        model.save()
        advanceUntilIdle()
        assertEquals(EditorialVenueSection.Moderation.keys.toSet(), source.updates.last().keys)
        assertEquals(JsonPrimitive("Chiuso"), source.updates.last()["rejection_reason"])
        /* Dopo il salvataggio il modulo riparte da quello che risponde il server. */
        assertEquals("pending", model.uiState.value.draft?.status)
    }

    @Test fun aNewVenueOpensItsFormAfterCreation() = runTest(dispatcher) {
        val model = VenueCreateViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        assertEquals("altro", model.uiState.value.draft.type)
        model.edit { it.copy(name = "Bottega", address = "Via Roma 1", municipality = "Padova", zone = "Centro") }
        model.locate()
        advanceUntilIdle()
        assertEquals("45.41", model.uiState.value.draft.lat)
        assertTrue(model.uiState.value.located)
        source.failNext = ApiException(422, ApiProblem(code = "VALIDATION_FAILED", fields = mapOf("address" to listOf("Non ho trovato questo indirizzo"))))
        model.locate()
        advanceUntilIdle()
        assertEquals("Non ho trovato questo indirizzo", model.uiState.value.errors.field("address"))
        model.create()
        advanceUntilIdle()
        assertEquals(77L, model.uiState.value.createdId)
        assertEquals(JsonPrimitive("Centro"), source.created.single()["zone"])
    }

    @Test fun applicationsOpenOnPendingAndADecisionReloadsTheQueue() = runTest(dispatcher) {
        val model = ApplicationListViewModel(repository, source, SavedStateHandle())
        advanceUntilIdle()
        assertTrue(("tab" to "pending") in source.applicationParams.single())
        model.ask(ApplicationDecision.Reject(9, "Bottega", canNotify = true, email = null))
        model.reject("Non è un locale", notify = true, message = "Riprova")
        advanceUntilIdle()
        assertEquals(listOf("reject-application:9:Non è un locale:true:Riprova"), source.actions)
        assertEquals(2, source.applicationParams.size)
    }

    @Test fun theApplicationPageSavesNotesAndLeavesAfterDeleting() = runTest(dispatcher) {
        val model = ApplicationDetailViewModel(repository, source, SavedStateHandle(mapOf(ApplicationDetailViewModel.KEY_APPLICATION to 9L)))
        advanceUntilIdle()
        assertEquals("Prima nota", model.uiState.value.notes)
        model.editNotes("Chiamato il gestore")
        model.saveNotes()
        model.ask(ApplicationDecision.Delete(9, "Bottega"))
        model.delete()
        advanceUntilIdle()
        assertEquals(listOf("notes:9:Chiamato il gestore", "delete-application:9"), source.actions)
        assertTrue(model.uiState.value.deleted)
    }

    @Test fun aNewOrganizerIsCreatedWithOwnerAndCollaborators() = runTest(dispatcher) {
        val model = OrganizerFormViewModel(repository, source, SavedStateHandle(mapOf(OrganizerFormViewModel.KEY_ORGANIZER to 0L)))
        advanceUntilIdle()
        assertEquals(listOf("Padova"), model.uiState.value.cities.map { it.label })
        model.edit { it.copy(name = "Musica Insieme") }
        model.openPicker(PeoplePicker.Owner)
        advanceUntilIdle()
        model.choose(PersonChoice(7, "Ada", "ada@example.test"))
        assertNull(model.uiState.value.picker)
        model.openPicker(PeoplePicker.Users)
        model.choose(PersonChoice(8, "Bea", "bea@example.test"))
        model.choose(PersonChoice(8, "Bea", "bea@example.test"))
        model.save()
        advanceUntilIdle()
        val body = source.organizerBodies.single()
        assertEquals(JsonPrimitive(7L), body["owner_id"])
        assertEquals(1, (body["users"] as kotlinx.serialization.json.JsonArray).size)
        assertEquals(12L, model.organizerId)
    }
}

/** Un server finto per R4: risponde sempre bene, tranne una volta se `failNext` è impostato. */
internal class FakeVenues : EditorialVenuesSource {
    val listParams = mutableListOf<List<Pair<String, String>>>()
    val applicationParams = mutableListOf<List<Pair<String, String>>>()
    val actions = mutableListOf<String>()
    val updates = mutableListOf<JsonObject>()
    val created = mutableListOf<JsonObject>()
    val organizerBodies = mutableListOf<JsonObject>()
    var failNext: Exception? = null

    private fun maybeFail() { failNext?.let { failNext = null; throw it } }
    private val row = EditorialVenueRow(id = 4, name = "Osteria", status = ValueLabel("pending", "In attesa"))
    private val overview = EditorialVenueOverview(id = 4, name = "Osteria", status = ValueLabel("pending", "In attesa"))
    private val form = EditorialVenueForm(
        profile = it.fabiodalez.incitta.data.management.VenueProfile(id = 4, name = "Osteria"),
        editorial = VenueEditorialFields(name = "Osteria", cityId = 1, type = "bar", status = "pending"),
    )
    private val application = ApplicationDetail(id = 9, venueName = "Bottega", notes = "Prima nota")

    override suspend fun venues(params: List<Pair<String, String>>) = EditorialPage(listOf(row), PageMeta()).also { listParams += params }
    override suspend fun exportVenues(params: List<Pair<String, String>>, target: File) = DownloadedFile(target, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
    override suspend fun venueChoices() = VenueChoices(buildJsonObject { put("district_municipality", kotlinx.serialization.json.JsonArray(listOf(buildJsonObject { put("value", "Padova"); put("label", "Padova") }))) })
    override suspend fun locate(address: String, municipality: String): LocatedPoint { maybeFail(); actions += "locate:$address:$municipality"; return LocatedPoint(45.41, 11.87) }
    override suspend fun venue(id: Long) = overview
    override suspend fun venueForm(id: Long) = form
    override suspend fun createVenue(body: JsonObject): EditorialVenueForm { maybeFail(); created += body; return form.copy(profile = form.profile.copy(id = 77)) }
    override suspend fun updateVenue(id: Long, body: JsonObject): EditorialVenueForm { maybeFail(); updates += body; return form }
    override suspend fun approveVenue(id: Long): VenueModerationResult { maybeFail(); actions += "approve:$id"; return VenueModerationResult("Approvato.", overview) }
    override suspend fun rejectVenue(id: Long, reason: String): VenueModerationResult { maybeFail(); actions += "reject:$id:$reason"; return VenueModerationResult("Rifiutato.", overview) }
    override suspend fun suspendVenue(id: Long, reason: String): VenueModerationResult { maybeFail(); actions += "suspend:$id:$reason"; return VenueModerationResult("Sospeso.", overview) }
    override suspend fun verifyVenue(id: Long, verified: Boolean): VenueModerationResult { maybeFail(); actions += "verify:$id:$verified"; return VenueModerationResult("Fatto.", overview.copy(isVerified = verified)) }
    override suspend fun setTicketing(id: Long, enabled: Boolean): EditorialVenueOverview { maybeFail(); actions += "ticketing:$id:$enabled"; return overview.copy(ticketingEnabled = enabled) }
    override suspend fun deleteVenue(id: Long) { maybeFail(); actions += "delete:$id" }
    override suspend fun venueEvents(id: Long, params: List<Pair<String, String>>) = EditorialPage<VenueEventRow>()
    override suspend fun venueActivity(id: Long, params: List<Pair<String, String>>) = EditorialPage<VenueActivityRow>()
    override suspend fun restoreActivity(venueId: Long, activityId: Long) { actions += "restore:$venueId:$activityId" }
    override suspend fun members(venueId: Long) = VenueMembers()
    override suspend fun memberCandidates(venueId: Long, q: String) = emptyList<PersonChoice>()
    override suspend fun inviteMember(venueId: Long, name: String, email: String, role: String) = VenueMembers()
    override suspend fun attachMember(venueId: Long, userId: Long, role: String) = VenueMembers()
    override suspend fun updateMember(venueId: Long, userId: Long, role: String, acceptedAt: String?) = VenueMembers()
    override suspend fun removeMember(venueId: Long, userId: Long) = VenueMembers()
    override suspend fun applications(params: List<Pair<String, String>>) = EditorialPage(listOf(ApplicationRow(id = 9, venueName = "Bottega"))).also { applicationParams += params }
    override suspend fun application(id: Long) = application
    override suspend fun applicationImage(id: Long, target: File) = DownloadedFile(target, "image/jpeg")
    override suspend fun approveApplication(id: Long): ApplicationOutcome { actions += "approve-application:$id"; return ApplicationOutcome("Accolta.", application = application) }
    override suspend fun rejectApplication(id: Long, notes: String, notify: Boolean, message: String?): ApplicationOutcome {
        actions += "reject-application:$id:$notes:$notify:$message"; return ApplicationOutcome("Rifiutata.", application = application)
    }
    override suspend fun saveApplicationNotes(id: Long, notes: String?): ApplicationOutcome { actions += "notes:$id:$notes"; return ApplicationOutcome("Note salvate.", application = application.copy(notes = notes)) }
    override suspend fun deleteApplication(id: Long) { actions += "delete-application:$id" }
    override suspend fun organizers(params: List<Pair<String, String>>) = EditorialPage<OrganizerRow>()
    override suspend fun organizer(id: Long) = OrganizerDetail(id = id, name = "Assoc")
    override suspend fun organizerChoices() = buildJsonObject { put("cities", kotlinx.serialization.json.JsonArray(listOf(buildJsonObject { put("value", "1"); put("label", "Padova") }))) }
    override suspend fun people(q: String) = listOf(PersonChoice(7, "Ada", "ada@example.test"))
    override suspend fun createOrganizer(body: JsonObject): OrganizerDetail { organizerBodies += body; return OrganizerDetail(id = 12, name = "Musica Insieme") }
    override suspend fun updateOrganizer(id: Long, body: JsonObject): OrganizerDetail { organizerBodies += body; return OrganizerDetail(id = id) }
    override fun media(): ManagementMediaApi? = null
}
