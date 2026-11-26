package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialCity
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDeskSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialUnlockState
import it.fabiodalez.incitta.data.editorial.PasswordPrompt
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.PasswordDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.UnlockGate
import java.util.concurrent.CopyOnWriteArrayList
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * I componenti comuni della redazione su un'attività vuota: il motivo
 * obbligatorio, la richiesta della password, l'elenco con schede e conteggi,
 * la porta che tiene chiusa l'area finché la password non è giusta. I testi
 * si leggono dalle risorse.
 */
class EditorialCommonUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    @Test fun theReasonIsRequiredBeforeConfirming() {
        val confirmed = CopyOnWriteArrayList<String>()
        show { ReasonDialog(title = "Rifiuta l’evento", text = "Il motivo arriva al locale.", confirmLabel = "Rifiuta", onConfirm = { confirmed += it }, onDismiss = {}) }
        compose.onNodeWithText("Rifiuta").assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.editorial_reason_field)).performTextInput("ok")
        compose.onNodeWithText(text(R.string.editorial_reason_too_short, 5)).assertExists()
        compose.onNodeWithText("Rifiuta").assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.editorial_reason_field)).performTextInput(" è un doppione  ")
        compose.onNodeWithText("Rifiuta").assertIsEnabled().performClick()
        compose.runOnIdle { assertEquals(listOf("ok è un doppione"), confirmed) }
    }

    @Test fun thePasswordPromptSaysWhyAndSendsThePassword() {
        val sent = CopyOnWriteArrayList<String>()
        show { PasswordDialog(PasswordPrompt(EditorialLockReason.PasswordRequired, error = "La password non è corretta."), onSubmit = { sent += it }, onDismiss = {}) }
        compose.onNodeWithText(text(R.string.editorial_password_required)).assertExists()
        compose.onNodeWithText("La password non è corretta.").assertExists()
        compose.onNodeWithText(text(R.string.editorial_password_submit)).assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.editorial_password_field)).performTextInput("segreta")
        compose.onNodeWithText(text(R.string.editorial_password_submit)).performClick()
        compose.runOnIdle { assertEquals(listOf("segreta"), sent) }
    }

    @Test fun theListShowsRowsTabsWithCountsAndTheEmptyState() {
        val tabs = CopyOnWriteArrayList<String?>()
        show {
            PagedListScreen(
                title = "Eventi",
                padding = PaddingValues(),
                state = PagedState(query = ListQuery(tab = "pending"), items = listOf("Concerto all’alba", "Mostra in piazza"), page = 1, hasMore = false, total = 2, tabCounts = mapOf("pending" to 2, "all" to 9)),
                onBack = {}, onSearch = {}, onLoadMore = {}, onRetry = {},
                rowKey = { it },
                tabs = listOf(ListTab(null, "Tutti"), ListTab("pending", "In attesa")),
                onTab = { tabs += it },
            ) { Text(it) }
        }
        compose.onNodeWithText("Concerto all’alba").assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_total, 2)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_tab_count, "In attesa", 2)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_tab_count, "Tutti", 9)).performClick()
        compose.runOnIdle { assertEquals(listOf<String?>(null), tabs) }
    }

    @Test fun anEmptyListSaysSo() {
        show {
            PagedListScreen(title = "Segnalazioni", padding = PaddingValues(), state = PagedState<String>(page = 1, hasMore = false),
                onBack = {}, onSearch = {}, onLoadMore = {}, onRetry = {}, rowKey = { it }) { Text(it) }
        }
        compose.onNodeWithText(text(R.string.editorial_list_empty)).assertExists()
    }

    @Test fun theGateKeepsTheAreaClosedUntilThePasswordIsRight() {
        val desk = UiFakeDesk()
        val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))
        show {
            UnlockGate(repository, phoneLockAvailable = { false }, phoneLock = { _, _, _, done -> done(false) }) {
                Text("Contenuto della redazione")
            }
        }
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_password_field)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_locked_title)).assertExists()
        compose.onNodeWithText("Contenuto della redazione").assertDoesNotExist()

        compose.onNodeWithText(text(R.string.editorial_password_field)).performTextInput("sbagliata")
        compose.onNodeWithText(text(R.string.editorial_password_submit)).performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("La password non è corretta.").fetchSemanticsNodes().isNotEmpty() }

        compose.onNodeWithText(text(R.string.editorial_password_field)).performTextInput("giusta")
        compose.onNodeWithText(text(R.string.editorial_password_submit)).performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("Contenuto della redazione").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_locked_title)).assertDoesNotExist()
        compose.runOnIdle { assertEquals(listOf("sbagliata", "giusta"), desk.passwords) }
    }
}

private class UiFakeDesk : EditorialDeskSource {
    val passwords = CopyOnWriteArrayList<String>()
    override suspend fun enabled() = true
    override suspend fun desk(city: String?) = EditorialDesk(role = "admin", city = EditorialCity(1, "padova", "Padova"), unlock = EditorialUnlockState(unlocked = passwords.lastOrNull() == "giusta"))
    override suspend fun options() = ManagementOptions()
    override suspend fun unlock(password: String): EditorialUnlockState {
        passwords += password
        if (password != "giusta") throw it.fabiodalez.incitta.data.ApiException(422, it.fabiodalez.incitta.data.ApiProblem(message = "Alcuni dati non sono validi.", fields = mapOf("password" to listOf("La password non è corretta."))))
        return EditorialUnlockState(unlocked = true)
    }
}
