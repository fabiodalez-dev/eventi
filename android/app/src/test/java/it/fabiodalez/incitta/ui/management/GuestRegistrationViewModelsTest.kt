package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.ApplicationDrafts
import it.fabiodalez.incitta.data.management.ApplicationDraftsTest
import it.fabiodalez.incitta.data.management.ApplicationOptions
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ClaimDraft
import it.fabiodalez.incitta.data.management.ClaimTarget
import it.fabiodalez.incitta.data.management.DraftScopes
import it.fabiodalez.incitta.data.management.ManagementApplicationsSource
import it.fabiodalez.incitta.data.management.MyApplicationsPage
import it.fabiodalez.incitta.data.management.OrganizerApplicationDraft
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueApplicationDraft
import it.fabiodalez.incitta.ui.management.registration.ACCOUNT_FIELDS
import it.fabiodalez.incitta.ui.management.registration.ClaimVenueViewModel
import it.fabiodalez.incitta.ui.management.registration.GuestSignUp
import it.fabiodalez.incitta.ui.management.registration.RegisterOrganizerViewModel
import it.fabiodalez.incitta.ui.management.registration.RegisterVenueViewModel
import it.fabiodalez.incitta.ui.management.registration.accountFieldsOf
import it.fabiodalez.incitta.ui.management.registration.accountProblems
import it.fabiodalez.incitta.ui.management.common.FormErrors
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/*
 * Q2 (W-AP-02): i moduli da ospite. Crea l'account invece di mandare, la
 * bozza resta sul telefono senza password, «Ho già un account» torna al
 * modulo, e da collegati la bozza parte da sola solo dopo la registrazione
 * chiesta dal modulo.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class GuestRegistrationViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeApplications : ManagementApplicationsSource {
        val calls = mutableListOf<String>()
        var claimTarget: CompletableDeferred<ClaimTarget>? = null
        var lastVenue: VenueApplicationDraft? = null
        var lastImage: PreparedImage? = null
        val keys = mutableListOf<String?>()
        var failNext: Exception? = null
        override suspend fun applications(page: Int): MyApplicationsPage { calls += "list:$page"; return MyApplicationsPage() }
        override suspend fun options(): ApplicationOptions { calls += "options"; return ApplicationOptions(municipality = "Padova", accountEmail = null) }
        override suspend fun lookup(query: String): List<CatalogueVenue> { calls += "lookup:$query"; return emptyList() }
        override suspend fun claimTarget(slug: String): ClaimTarget { calls += "target:$slug"; return claimTarget?.await() ?: ClaimTarget(5, slug, "Osteria del Ponte") }
        override suspend fun submitVenue(draft: VenueApplicationDraft, image: PreparedImage?, idempotencyKey: String?): ApplicationSent {
            calls += "venue"; lastVenue = draft; lastImage = image; keys += idempotencyKey
            failNext?.let { failNext = null; throw it }
            return ApplicationSent("queued", "ok")
        }
        override suspend fun claim(slug: String, draft: ClaimDraft, idempotencyKey: String?): ApplicationSent { calls += "claim:$slug"; keys += idempotencyKey; return ApplicationSent("queued", "ok") }
        override suspend fun submitOrganizer(draft: OrganizerApplicationDraft, idempotencyKey: String?): ApplicationSent { calls += "organizer:${draft.name}"; keys += idempotencyKey; return ApplicationSent("organizer", "ok") }
        override suspend fun confirm(id: Long): String { calls += "confirm:$id"; return "" }
        override suspend fun discard(id: Long): String { calls += "discard:$id"; return "" }
    }

    private class FakeSignUp : GuestSignUp {
        val calls = mutableListOf<Triple<String, String, String>>()
        var failure: Exception? = null
        override suspend fun register(name: String, email: String, password: String) {
            calls += Triple(name, email, password)
            failure?.let { throw it }
        }
    }

    private val vault = ApplicationDraftsTest.MemoryVault()
    private val files = ApplicationDraftsTest.MemoryFiles()
    /* L'account collegato secondo le bozze: `null` da ospite. */
    private var signedIn: Long? = null
    private val drafts = ApplicationDrafts(vault, files, owner = { signedIn })

    @Test fun theGuestCreatesTheAccountAndNothingIsSentYet() = runTest(dispatcher) {
        val api = FakeApplications()
        val signUp = FakeSignUp()
        val viewModel = RegisterVenueViewModel(api, SavedStateHandle(), "", drafts, signUp)
        assertTrue(viewModel.uiState.value.guest)
        /* Da ospite niente «Le mie richieste» in cima: vuole il token. */
        assertFalse(api.calls.any { it.startsWith("list") })
        assertEquals("Padova", viewModel.uiState.value.draft.municipality)

        /* Prima di scrivere al server: i campi obbligatori e la password. */
        viewModel.submit()
        val required = viewModel.uiState.value.required
        assertEquals(R.string.application_required, required["venue_name"])
        assertEquals(R.string.application_required, required["address"])
        assertEquals(R.string.application_required, required["contact_name"])
        assertEquals(R.string.application_required, required["contact_email"])
        assertEquals(R.string.application_required, required["password"])
        assertTrue(signUp.calls.isEmpty())

        viewModel.update { it.copy(venueName = "Circolo del Ponte", address = "Via Roma 1", contactName = "Marta Rossi") }
        viewModel.setEmail("marta@esempio.it")
        viewModel.setPassword("Password-2026")
        viewModel.setPasswordConfirmation("Password-2025")
        viewModel.submit()
        assertEquals(R.string.application_password_mismatch, viewModel.uiState.value.required["password_confirmation"])
        assertTrue(signUp.calls.isEmpty())

        viewModel.setPasswordConfirmation("Password-2026")
        viewModel.setImage(PreparedImage(ByteArray(10), 800, 600))
        viewModel.submit()
        assertEquals(listOf(Triple("Marta Rossi", "marta@esempio.it", "Password-2026")), signUp.calls)
        assertEquals("marta@esempio.it", viewModel.uiState.value.awaitingEmail)
        assertTrue(viewModel.uiState.value.canResend)
        assertFalse(api.calls.contains("venue"))

        /* Sul telefono la bozza, l'email e l'immagine; mai la password. */
        assertEquals("marta@esempio.it", drafts.awaiting()?.email)
        assertEquals("marta@esempio.it", drafts.load(DraftScopes.VENUE)?.email)
        assertEquals(800, drafts.image(DraftScopes.VENUE)?.width)
        assertFalse(vault.value.orEmpty().contains("Password-2026"))

        viewModel.resend()
        assertEquals(2, signUp.calls.size)
        assertTrue(viewModel.uiState.value.resent)

        /* Riaperto (l'app chiusa): «controlla la posta» di nuovo, ma senza password non si reinvia. */
        val reopened = RegisterVenueViewModel(api, SavedStateHandle(), "", drafts, signUp)
        assertEquals("marta@esempio.it", reopened.uiState.value.awaitingEmail)
        assertEquals("Circolo del Ponte", reopened.uiState.value.draft.venueName)
        assertFalse(reopened.uiState.value.canResend)
        assertEquals(800, reopened.image.value.image?.width)
        reopened.editAgain()
        assertNull(reopened.uiState.value.awaitingEmail)
        assertNull(drafts.awaiting())
    }

    @Test fun registrationErrorsGoUnderTheFormFields() = runTest(dispatcher) {
        val signUp = FakeSignUp().apply {
            failure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("email" to listOf("Email non valida."), "password" to listOf("Troppo corta."), "name" to listOf("Troppo lungo."))))
        }
        val viewModel = RegisterOrganizerViewModel(FakeApplications(), SavedStateHandle(), "", drafts, signUp)
        viewModel.update { it.copy(name = "Collettivo", contactName = "Marta") }
        viewModel.setEmail("marta@esempio.it")
        viewModel.setPassword("abcdefgh")
        viewModel.setPasswordConfirmation("abcdefgh")
        viewModel.submit()
        val errors = viewModel.uiState.value.errors
        assertEquals("Email non valida.", errors.field("contact_email"))
        assertEquals("Troppo corta.", errors.field("password"))
        assertEquals("Troppo lungo.", errors.field("contact_name"))
        assertNull(viewModel.uiState.value.awaitingEmail)
        assertNull(drafts.awaiting())
    }

    @Test fun alreadyHaveAnAccountKeepsTheDraftAndComesBack() = runTest(dispatcher) {
        val viewModel = ClaimVenueViewModel(FakeApplications(), SavedStateHandle(), "osteria-ponte", "", drafts, FakeSignUp())
        viewModel.update { it.copy(contactName = "Marta", message = "Sono la titolare dal 2019.") }
        viewModel.setEmail("marta@esempio.it")
        viewModel.signInInstead()
        assertTrue(viewModel.uiState.value.signIn)
        viewModel.signInOpened()
        assertFalse(viewModel.uiState.value.signIn)

        /* L'accesso con un account qualunque riapre questa rivendicazione, precompilata. */
        signedIn = 7
        assertEquals("claim:osteria-ponte", drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        val api = FakeApplications()
        val signedIn = ClaimVenueViewModel(api, SavedStateHandle(), "osteria-ponte", "Marta Rossi", drafts)
        assertFalse(signedIn.uiState.value.guest)
        assertEquals("Sono la titolare dal 2019.", signedIn.uiState.value.draft.message)
        assertEquals("Marta", signedIn.uiState.value.draft.contactName)
        /* Si manda a mano, come la conferma sul sito. */
        assertFalse(api.calls.any { it.startsWith("claim:") })
        signedIn.submit()
        assertTrue(api.calls.contains("claim:osteria-ponte"))
        assertNull(drafts.load("claim:osteria-ponte"))
    }

    @Test fun afterTheRegistrationTheDraftIsSentByItselfAndDeleted() = runTest(dispatcher) {
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo del Ponte\",\"address\":\"Via Roma 1\",\"contactName\":\"Marta Rossi\"}", "marta@esempio.it")
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(10), 800, 600))
        drafts.awaitAccount(DraftScopes.VENUE, "marta@esempio.it")
        signedIn = 7
        assertEquals(DraftScopes.VENUE, drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = true))

        val api = FakeApplications()
        val viewModel = RegisterVenueViewModel(api, SavedStateHandle(), "Marta Rossi", drafts)
        assertEquals(1, api.calls.count { it == "venue" })
        assertEquals("Circolo del Ponte", api.lastVenue?.venueName)
        assertEquals(800, api.lastImage?.width)
        assertTrue(viewModel.uiState.value.landOnApplications)
        assertNull(drafts.load(DraftScopes.VENUE))
        assertTrue(files.files.isEmpty())
        viewModel.applicationsOpened()
        assertFalse(viewModel.uiState.value.landOnApplications)

        /* Riaprendo il modulo non riparte niente. */
        RegisterVenueViewModel(api, SavedStateHandle(), "Marta Rossi", drafts)
        assertEquals(1, api.calls.count { it == "venue" })
    }

    @Test fun theClaimWaitsForTheVenueBeforeSendingByItself() = runTest(dispatcher) {
        drafts.save(DraftScopes.claim("osteria"), "{\"contactName\":\"Marta\",\"message\":\"Sono la titolare dal 2019.\"}", "marta@esempio.it")
        drafts.awaitAccount(DraftScopes.claim("osteria"), "marta@esempio.it")
        signedIn = 7
        drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = true)

        val api = FakeApplications().apply { claimTarget = CompletableDeferred() }
        val viewModel = ClaimVenueViewModel(api, SavedStateHandle(), "osteria", "Marta", drafts)
        assertTrue(viewModel.uiState.value.autoSending)
        assertFalse(api.calls.contains("claim:osteria"))
        api.claimTarget?.complete(ClaimTarget(5, "osteria", "Osteria"))
        testScheduler.advanceUntilIdle()
        assertTrue(api.calls.contains("claim:osteria"))
        assertTrue(viewModel.uiState.value.landOnApplications)
        assertFalse(viewModel.uiState.value.autoSending)
    }

    @Test fun aLostResponseIsRetriedWithTheSameKeyAndAnEditGetsANewOne() = runTest(dispatcher) {
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo del Ponte\",\"address\":\"Via Roma 1\",\"contactName\":\"Marta Rossi\"}", "marta@esempio.it")
        drafts.awaitAccount(DraftScopes.VENUE, "marta@esempio.it")
        signedIn = 7
        drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = true)

        /* L'invio automatico parte, ma la risposta si perde per strada. */
        val api = FakeApplications().apply { failNext = java.io.IOException("Connessione non disponibile. Riprova tra poco.") }
        val viewModel = RegisterVenueViewModel(api, SavedStateHandle(), "Marta Rossi", drafts)
        assertEquals(1, api.keys.size)
        val first = api.keys.single()
        assertNotNull(first)
        assertNull(viewModel.uiState.value.sent)
        /* La chiave sta nella bozza: anche riaprendo l'app il nuovo tentativo è lo stesso invio. */
        assertEquals(first, drafts.load(DraftScopes.VENUE)?.sendKey)

        val reopened = RegisterVenueViewModel(api, SavedStateHandle(), "Marta Rossi", drafts)
        reopened.submit()
        assertEquals(listOf(first, first), api.keys)
        assertNotNull(reopened.uiState.value.sent)

        /* Una richiesta nuova, con un altro contenuto, ha un'altra chiave. */
        reopened.update { it.copy(venueName = "Secondo circolo", address = "Via Po 2", contactName = "Marta Rossi") }
        reopened.submit()
        assertEquals(3, api.keys.size)
        assertNotEquals(first, api.keys.last())
    }

    @Test fun theDraftOfAnotherAccountNeverFillsTheFormNorLeaves() = runTest(dispatcher) {
        /* A scrive da collegata, con l'immagine; la sessione cade senza logout ed entra B. */
        signedIn = 7
        val writer = RegisterVenueViewModel(FakeApplications(), SavedStateHandle(), "Marta Rossi", drafts)
        writer.update { it.copy(venueName = "Circolo di Marta", address = "Via Roma 1", contactPhone = "+39 333 0000000") }
        writer.setImage(PreparedImage(ByteArray(10), 800, 600))
        signedIn = 8

        val api = FakeApplications()
        val other = RegisterVenueViewModel(api, SavedStateHandle(), "Bruno Bianchi", drafts)
        assertEquals("", other.uiState.value.draft.venueName)
        assertEquals("Bruno Bianchi", other.uiState.value.draft.contactName)
        assertNull(other.image.value.image)
        assertFalse(api.calls.contains("venue"))
    }

    @Test fun theAccountChecksMatchTheSiteForm() {
        assertEquals(emptyMap<String, Int>(), accountProblems("Marta", "marta@esempio.it", "abcdefgh", "abcdefgh"))
        assertEquals(R.string.application_email_invalid, accountProblems("Marta", "marta@", "abcdefgh", "abcdefgh")["contact_email"])
        assertEquals(R.string.application_password_short, accountProblems("Marta", "marta@esempio.it", "abc", "abc")["password"])

        val mapped = FormErrors.from(accountFieldsOf(ApiException(422, ApiProblem(fields = mapOf("email" to listOf("No."))))), ACCOUNT_FIELDS)
        assertEquals("No.", mapped.field("contact_email"))
        /* Un errore che non è di validazione resta com'è. */
        val other = ApiException(429, ApiProblem("Troppe richieste"))
        assertSame(other, accountFieldsOf(other))
    }
}
