package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.ApplicationOptions
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.ApplicationSubject
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ClaimDraft
import it.fabiodalez.incitta.data.management.ClaimTarget
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagementApplicationsSource
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.MyApplication
import it.fabiodalez.incitta.data.management.MyApplicationsMeta
import it.fabiodalez.incitta.data.management.MyApplicationsPage
import it.fabiodalez.incitta.data.management.OrganizerApplicationDraft
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.RegistrationForm
import it.fabiodalez.incitta.data.management.VenueApplicationDraft
import it.fabiodalez.incitta.data.management.applicationBody
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import it.fabiodalez.incitta.ui.management.registration.ApplicationsRoute
import it.fabiodalez.incitta.ui.management.registration.ApplicationsViewModel
import it.fabiodalez.incitta.ui.management.registration.ClaimVenueRoute
import it.fabiodalez.incitta.ui.management.registration.ClaimVenueViewModel
import it.fabiodalez.incitta.ui.management.registration.RegisterOrganizerRoute
import it.fabiodalez.incitta.ui.management.registration.RegisterVenueRoute
import it.fabiodalez.incitta.ui.management.registration.RegisterVenueViewModel
import it.fabiodalez.incitta.ui.management.registration.registrationRouteFor
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* P13 con un server finto: «Le mie richieste», i moduli con gli errori sotto i campi, il catalogo, l'immagine e i collegamenti del sito. */
@OptIn(ExperimentalCoroutinesApi::class)
class RegistrationViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeApplications : ManagementApplicationsSource {
        val calls = mutableListOf<String>()
        var submitFailure: Exception? = null
        var lastImage: PreparedImage? = null
        var lastVenue: VenueApplicationDraft? = null
        var items = listOf(
            MyApplication(id = 1, name = "Bozza mia", state = "awaiting", canConfirm = true),
            MyApplication(id = 2, name = "In coda", state = "pending"),
            MyApplication(id = 3, name = "Accolta", state = "approved", subject = ApplicationSubject("venue", 12)),
        )
        override suspend fun applications(page: Int): MyApplicationsPage {
            calls += "list:$page"
            return if (page == 1) MyApplicationsPage(items, MyApplicationsMeta(nextPage = 2)) else MyApplicationsPage(listOf(MyApplication(id = 4, name = "Vecchia", state = "rejected")))
        }
        override suspend fun options(): ApplicationOptions { calls += "options"; return ApplicationOptions(municipality = "Padova", accountEmail = "marta@esempio.it") }
        override suspend fun lookup(query: String): List<CatalogueVenue> { calls += "lookup:$query"; return listOf(CatalogueVenue(5, "ponte", "Osteria del Ponte", claimable = true)) }
        override suspend fun claimTarget(slug: String): ClaimTarget { calls += "target:$slug"; return ClaimTarget(5, slug, "Osteria del Ponte") }
        override suspend fun submitVenue(draft: VenueApplicationDraft, image: PreparedImage?, idempotencyKey: String?): ApplicationSent {
            calls += "venue"; lastVenue = draft; lastImage = image
            submitFailure?.let { throw it }
            return ApplicationSent("queued", "Richiesta ricevuta", MyApplication(id = 9))
        }
        override suspend fun claim(slug: String, draft: ClaimDraft, idempotencyKey: String?): ApplicationSent { calls += "claim:$slug:${draft.message}"; return ApplicationSent("queued", "ok") }
        override suspend fun submitOrganizer(draft: OrganizerApplicationDraft, idempotencyKey: String?): ApplicationSent { calls += "organizer"; return ApplicationSent("organizer", "ok") }
        override suspend fun confirm(id: Long): String { calls += "confirm:$id"; items = items.map { if (it.id == id) it.copy(state = "pending", canConfirm = false) else it }; return "Richiesta confermata" }
        override suspend fun discard(id: Long): String { calls += "discard:$id"; items = items.filterNot { it.id == id }; return "Richiesta eliminata." }
    }

    private fun repository(scope: CoroutineScope, applications: FakeApplications, identity: FakeIdentity = FakeIdentity()) =
        ManagementRepository(7, "token", FakeTicketing(), identity, null, MemoryDevice(), scope, applications = applications)

    @Test fun myApplicationsLoadPageByPageAndConfirmOrDiscard() = runTest(dispatcher) {
        val api = FakeApplications()
        val viewModel = ApplicationsViewModel(repository(TestScope(testScheduler), api))
        assertEquals(listOf(1L, 2L, 3L), viewModel.uiState.value.items.valueOrNull?.map { it.id })
        viewModel.loadMore()
        assertEquals(listOf(1L, 2L, 3L, 4L), viewModel.uiState.value.items.valueOrNull?.map { it.id })
        assertNull(viewModel.uiState.value.nextPage)

        viewModel.confirm(MyApplication(id = 1))
        assertEquals("Richiesta confermata", viewModel.uiState.value.message)
        assertEquals("pending", viewModel.uiState.value.items.valueOrNull?.first { it.id == 1L }?.state)
        viewModel.discard(MyApplication(id = 2))
        assertEquals("Richiesta eliminata.", viewModel.uiState.value.message)
        assertTrue(api.calls.containsAll(listOf("confirm:1", "discard:2")))
        assertFalse(viewModel.uiState.value.items.valueOrNull.orEmpty().any { it.id == 2L })
    }

    @Test fun openPanelChoosesTheSubjectAndRereadsTheList() = runTest(dispatcher) {
        val identity = FakeIdentity()
        val repository = repository(TestScope(testScheduler), FakeApplications(), identity)
        val viewModel = ApplicationsViewModel(repository)
        viewModel.openPanel(MyApplication(id = 3, state = "approved", subject = ApplicationSubject("venue", 12)))
        assertEquals("venue:12", viewModel.uiState.value.openSubject)
        assertEquals("venue:12", repository.selectedSubjectKey)
        viewModel.subjectOpened()
        assertNull(viewModel.uiState.value.openSubject)
        /* Senza soggetto (accolta ma l'accesso arriva con l'invito) non si apre niente. */
        viewModel.openPanel(MyApplication(id = 8, state = "approved"))
        assertNull(viewModel.uiState.value.openSubject)
    }

    @Test fun theVenueFormKeepsTheDraftShowsFieldErrorsAndSends() = runTest(dispatcher) {
        val api = FakeApplications()
        val saved = SavedStateHandle()
        val viewModel = RegisterVenueViewModel(repository(TestScope(testScheduler), api), saved, "Marta Rossi")
        val state = viewModel.uiState.value
        assertEquals("Marta Rossi", state.draft.contactName)
        /* Il comune della città, una volta sola. */
        assertEquals("Padova", state.draft.municipality)
        assertEquals(listOf("Bozza mia"), state.awaiting.map { it.name })
        assertEquals(listOf("In coda"), state.inReview.map { it.name })

        viewModel.update { it.copy(venueName = "Circolo del Ponte", capacity = "120") }
        /* Quello che si scrive resta nella voce di navigazione. */
        val restored = RegisterVenueViewModel(repository(TestScope(testScheduler), FakeApplications()), saved, "Altro")
        assertEquals("Circolo del Ponte", restored.uiState.value.draft.venueName)
        assertEquals("Marta Rossi", restored.uiState.value.draft.contactName)

        api.submitFailure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("address" to listOf("Scrivi l’indirizzo."), "form_started" to listOf("Altro"))))
        viewModel.submit()
        assertEquals("Scrivi l’indirizzo.", viewModel.uiState.value.errors.field("address"))
        assertEquals("Altro", viewModel.uiState.value.errors.general)
        assertNull(viewModel.uiState.value.sent)

        api.submitFailure = ApiException(409, ApiProblem("Hai già tre richieste in attesa", "CONFLICT", emptyMap()))
        viewModel.submit()
        assertEquals("Hai già tre richieste in attesa", viewModel.uiState.value.errors.general)

        api.submitFailure = null
        viewModel.setImage(PreparedImage(ByteArray(10), 800, 600))
        viewModel.submit()
        assertEquals("queued", viewModel.uiState.value.sent?.state)
        assertEquals(800, api.lastImage?.width)
        assertEquals("120", api.lastVenue?.capacity)
        assertNull(viewModel.image.value.image)
        assertNull(saved.get<String>("draft"))
    }

    @Test fun theImageMustBeLargeEnoughAndTheSearchNeedsThreeLetters() = runTest(dispatcher) {
        val api = FakeApplications()
        val viewModel = RegisterVenueViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(), "Marta")
        viewModel.setImage(PreparedImage(ByteArray(10), 300, 200))
        assertEquals(R.string.register_image_too_small, viewModel.image.value.error)
        assertNull(viewModel.image.value.image)
        viewModel.setImage(PreparedImage(ByteArray(6 * 1024 * 1024), 2400, 1800))
        assertEquals(R.string.register_image_too_heavy, viewModel.image.value.error)

        viewModel.setQuery("po")
        viewModel.search()
        assertTrue(viewModel.catalogue.value.tooShort)
        assertFalse(api.calls.any { it.startsWith("lookup") })
        viewModel.setQuery("ponte")
        viewModel.search()
        assertEquals(listOf("ponte"), (viewModel.catalogue.value.results as Loadable.Ready).value.map { it.slug })
    }

    @Test fun theClaimLoadsTheVenueAndSendsTheMessage() = runTest(dispatcher) {
        val api = FakeApplications()
        val viewModel = ClaimVenueViewModel(repository(TestScope(testScheduler), api), SavedStateHandle(), "ponte", "Marta")
        assertEquals("Osteria del Ponte", viewModel.target.value.valueOrNull?.name)
        viewModel.update { it.copy(message = "Sono la titolare dal 2019.") }
        viewModel.submit()
        assertTrue(api.calls.contains("claim:ponte:Sono la titolare dal 2019."))
        assertEquals("queued", viewModel.uiState.value.sent?.state)
    }

    @Test fun siteLinksOpenTheNativeForms() {
        val origin = "https://eventi.fabiodalez.it/api/v1/"
        assertEquals(ManagementDestination.Registration(RegistrationForm.Applications), ManagementDestination.parse("https://eventi.fabiodalez.it/le-mie-richieste", origin))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Venue), ManagementDestination.parse("https://eventi.fabiodalez.it/registra-il-tuo-locale?cerca=ponte", origin))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Organizer), ManagementDestination.parse("https://eventi.fabiodalez.it/registra-la-tua-organizzazione/", origin))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Claim, "osteria-ponte"), ManagementDestination.parse("https://eventi.fabiodalez.it/locali/osteria-ponte/rivendica", origin))
        assertNull(ManagementDestination.parse("https://altro.example/registra-il-tuo-locale", origin))
        assertNull(ManagementDestination.parse("https://eventi.fabiodalez.it/locali/osteria-ponte", origin))

        assertEquals(ApplicationsRoute(fromOutside = true), registrationRouteFor(ManagementDestination.Registration(RegistrationForm.Applications)))
        assertEquals(RegisterVenueRoute(fromOutside = true), registrationRouteFor(ManagementDestination.Registration(RegistrationForm.Venue)))
        assertEquals(RegisterOrganizerRoute(fromOutside = true), registrationRouteFor(ManagementDestination.Registration(RegistrationForm.Organizer)))
        assertEquals(ClaimVenueRoute("osteria-ponte", fromOutside = true), registrationRouteFor(ManagementDestination.Registration(RegistrationForm.Claim, "osteria-ponte")))
    }

    @Test fun onlyWrittenFieldsAreSentAndTheCapacityIsANumber() {
        val body = applicationBody(VenueApplicationDraft(venueName = " Circolo ", capacity = "120", website = " ").fields())
        assertEquals(setOf("venue_name", "capacity"), body.keys)
        assertEquals("Circolo", body["venue_name"]?.jsonPrimitive?.content)
        assertEquals(120, body["capacity"]?.jsonPrimitive?.int)
    }
}
