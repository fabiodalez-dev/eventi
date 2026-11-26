package it.fabiodalez.incitta.management

import androidx.activity.ComponentActivity
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ApplicationDrafts
import it.fabiodalez.incitta.data.management.ApplicationOptions
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ClaimDraft
import it.fabiodalez.incitta.data.management.ClaimTarget
import it.fabiodalez.incitta.data.management.DraftFiles
import it.fabiodalez.incitta.data.management.DraftScopes
import it.fabiodalez.incitta.data.management.DraftVault
import it.fabiodalez.incitta.data.management.ManagementApplicationsSource
import it.fabiodalez.incitta.data.management.MyApplicationsPage
import it.fabiodalez.incitta.data.management.OrganizerApplicationDraft
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueApplicationDraft
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.management.registration.GuestSignUp
import it.fabiodalez.incitta.ui.management.registration.RegisterOrganizerScreen
import it.fabiodalez.incitta.ui.management.registration.RegisterOrganizerViewModel
import it.fabiodalez.incitta.ui.management.registration.RegistrationChrome
import java.util.concurrent.CopyOnWriteArrayList
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/*
 * Q2 (W-AP-02): «Registra la tua organizzazione» aperto da ospite. In fondo
 * email e password, «Crea l'account e invia» scrive al server di
 * registrazione e mostra «Controlla la posta»; «Ho già un account» porta
 * all'accesso. Nessuna richiesta parte prima della sessione, e la password
 * non finisce nella bozza. I testi si leggono dalle risorse.
 */
class GuestRegistrationUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private val calls = CopyOnWriteArrayList<String>()
    private val registrations = CopyOnWriteArrayList<Triple<String, String, String>>()
    @Volatile private var signIn = false
    @Volatile private var stored: String? = null

    private val api = object : ManagementApplicationsSource {
        override suspend fun applications(page: Int): MyApplicationsPage { calls += "list"; return MyApplicationsPage() }
        override suspend fun options(): ApplicationOptions = ApplicationOptions(municipality = "Padova")
        override suspend fun lookup(query: String): List<CatalogueVenue> = emptyList()
        override suspend fun claimTarget(slug: String): ClaimTarget = ClaimTarget(1, slug, "Osteria")
        override suspend fun submitVenue(draft: VenueApplicationDraft, image: PreparedImage?, idempotencyKey: String?): ApplicationSent { calls += "venue"; return ApplicationSent() }
        override suspend fun claim(slug: String, draft: ClaimDraft, idempotencyKey: String?): ApplicationSent { calls += "claim"; return ApplicationSent() }
        override suspend fun submitOrganizer(draft: OrganizerApplicationDraft, idempotencyKey: String?): ApplicationSent { calls += "organizer"; return ApplicationSent() }
        override suspend fun confirm(id: Long): String = ""
        override suspend fun discard(id: Long): String = ""
    }

    private val drafts = ApplicationDrafts(
        object : DraftVault {
            override fun read(): String? = stored
            override fun write(value: String?) { stored = value }
        },
        object : DraftFiles {
            override fun write(name: String, bytes: ByteArray) {}
            override fun read(name: String): ByteArray? = null
            override fun delete(name: String) {}
            override fun clear() {}
        },
    )

    private fun show(): RegisterOrganizerViewModel {
        lateinit var viewModel: RegisterOrganizerViewModel
        compose.runOnIdle {
            viewModel = RegisterOrganizerViewModel(api, SavedStateHandle(), "", drafts, GuestSignUp { name, email, password -> registrations += Triple(name, email, password) })
        }
        compose.setContent {
            InCittaTheme {
                Surface {
                    RegisterOrganizerScreen(viewModel, RegistrationChrome(PaddingValues(), null, onExit = {}, onSignIn = { signIn = true }), onBack = {}, onOpen = {})
                }
            }
        }
        return viewModel
    }

    private fun waitFor(label: String) = compose.waitUntil(10000) { compose.onAllNodesWithText(label).fetchSemanticsNodes().isNotEmpty() }

    @Test fun theGuestCreatesTheAccountAndChecksTheMail() {
        show()
        waitFor(text(R.string.application_guest_submit))
        compose.onNodeWithText(text(R.string.application_account_title)).performScrollTo().assertExists()

        compose.onNodeWithText(text(R.string.register_organizer_name) + " *").performScrollTo().performTextInput("Collettivo Aurora")
        compose.onNodeWithText(text(R.string.application_contact_name) + " *").performScrollTo().performTextInput("Marta Rossi")
        compose.onNodeWithText(text(R.string.application_contact_email) + " *").performScrollTo().performTextInput("marta@esempio.it")
        compose.onNodeWithText(text(R.string.application_password) + " *").performScrollTo().performTextInput("Password-2026")
        compose.onNodeWithText(text(R.string.application_password_confirmation) + " *").performScrollTo().performTextInput("Password-2025")
        compose.onNodeWithText(text(R.string.application_guest_submit)).performScrollTo().performClick()
        waitFor(text(R.string.application_password_mismatch))
        assertTrue(registrations.isEmpty())

        compose.onNodeWithText(text(R.string.application_password_confirmation) + " *").performScrollTo().performTextClearance()
        compose.onNodeWithText(text(R.string.application_password_confirmation) + " *").performScrollTo().performTextInput("Password-2026")
        compose.onNodeWithText(text(R.string.application_guest_submit)).performScrollTo().performClick()

        waitFor(text(R.string.application_check_email_title))
        compose.onNodeWithText(text(R.string.application_check_email_body, "marta@esempio.it")).assertExists()
        assertEquals(listOf(Triple("Marta Rossi", "marta@esempio.it", "Password-2026")), registrations.toList())
        /* Nessuna richiesta prima della sessione, e la password non è nella bozza. */
        assertTrue(calls.isEmpty())
        assertFalse(stored.orEmpty().contains("Password-2026"))
        assertTrue(stored.orEmpty().contains("Collettivo Aurora"))
        assertEquals(DraftScopes.ORGANIZER, drafts.awaiting()?.scope)

        compose.onNodeWithText(text(R.string.application_check_email_resend)).performScrollTo().performClick()
        waitFor(text(R.string.application_check_email_resent))
        assertEquals(2, registrations.size)

        compose.onNodeWithText(text(R.string.application_check_email_edit)).performScrollTo().performClick()
        waitFor(text(R.string.application_guest_submit))
    }

    @Test fun alreadyHaveAnAccountGoesToTheSignIn() {
        show()
        waitFor(text(R.string.application_login_action))
        compose.onNodeWithText(text(R.string.register_organizer_name) + " *").performScrollTo().performTextInput("Collettivo Aurora")
        compose.onAllNodesWithText(text(R.string.application_login_action))[0].performScrollTo().performClick()
        compose.waitUntil(5000) { signIn }
        assertEquals(DraftScopes.ORGANIZER, drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        assertTrue(stored.orEmpty().contains("Collettivo Aurora"))
    }
}
