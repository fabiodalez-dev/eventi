package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CacheSettingsView
import it.fabiodalez.incitta.data.editorial.CacheEnvironment
import it.fabiodalez.incitta.data.editorial.ConsentCategoryInfo
import it.fabiodalez.incitta.data.editorial.CookieRow
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EmailText
import it.fabiodalez.incitta.data.editorial.EmailTextGroup
import it.fabiodalez.incitta.data.editorial.EmailTextVariable
import it.fabiodalez.incitta.data.editorial.EmailTexts
import it.fabiodalez.incitta.data.editorial.MailSettingsView
import it.fabiodalez.incitta.data.editorial.MailStatus
import it.fabiodalez.incitta.data.editorial.RedirectCity
import it.fabiodalez.incitta.data.editorial.RedirectOptions
import it.fabiodalez.incitta.data.editorial.RedirectStatusChoice
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.settings.CacheContent
import it.fabiodalez.incitta.ui.editorial.settings.CacheUiState
import it.fabiodalez.incitta.ui.editorial.settings.CookiesList
import it.fabiodalez.incitta.ui.editorial.settings.CookiesUiState
import it.fabiodalez.incitta.ui.editorial.settings.EmailTextEditUiState
import it.fabiodalez.incitta.ui.editorial.settings.EmailTextEditor
import it.fabiodalez.incitta.ui.editorial.settings.EmailTextsContent
import it.fabiodalez.incitta.ui.editorial.settings.EmailTextsUiState
import it.fabiodalez.incitta.ui.editorial.settings.MailSettingsContent
import it.fabiodalez.incitta.ui.editorial.settings.MailUiState
import it.fabiodalez.incitta.ui.editorial.settings.RedirectDraft
import it.fabiodalez.incitta.ui.editorial.settings.RedirectNewForm
import it.fabiodalez.incitta.ui.editorial.settings.RedirectNewUiState
import it.fabiodalez.incitta.ui.editorial.settings.SettingsHub
import it.fabiodalez.incitta.ui.editorial.settings.SettingsSection
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.util.concurrent.CopyOnWriteArrayList
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali della configurazione tecnica (R13) su un'attività
 * vuota: il menu per ruolo, i testi delle email con «Inserisci» e il
 * segnaposto sconosciuto, la posta senza password con la prova, i cookie
 * per finalità con il riordino, il nuovo indirizzo con la destinazione
 * interna, la cache con la conferma. I testi si leggono dalle risorse.
 */
class EditorialSettingsUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun desk(vararg keys: String) = EditorialDesk(role = "admin", permissions = keys.associateWith { true })

    @Test fun theAdminSeesTheSectionsOfThePanelButNotMailAndNewsletter() {
        val opened = CopyOnWriteArrayList<SettingsSection>()
        show { SettingsHub(desk("system_manage", "pages_manage"), "Configurazione tecnica", PaddingValues(), onBack = {}, onRetry = {}, onOpen = { opened += it }) }
        compose.onNodeWithText(text(R.string.config_texts_title)).assertExists()
        compose.onNodeWithText(text(R.string.config_cache_title)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.config_mail_title)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.config_newsletter_title)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.config_redirects_title)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf(SettingsSection.Redirects), opened) }
    }

    @Test fun theSuperAdminAlsoSeesMailAndNewsletter() {
        show { SettingsHub(desk("system_manage", "pages_manage", "mail_manage"), "Configurazione tecnica", PaddingValues(), onBack = {}, onRetry = {}, onOpen = {}) }
        compose.onNodeWithText(text(R.string.config_mail_title)).assertExists()
        compose.onNodeWithText(text(R.string.config_newsletter_title)).performScrollTo().assertExists()
    }

    private val subject = EmailText(key = "notifications.moved.subject", label = "Oggetto", default = "Spostato: :title", value = "Nuovo orario: :title", modified = true,
        variables = listOf(EmailTextVariable("title", "titolo evento")), preview = "Nuovo orario: Jazz in acustico", revision = "r1")

    @Test fun theTextsAreShownByGroupAndOpenTheEditor() {
        val opened = CopyOnWriteArrayList<String>()
        val texts = EmailTexts(listOf(EmailTextGroup("moved", "Evento spostato", listOf(subject)), EmailTextGroup("cancelled", "Evento annullato", emptyList())))
        show {
            EmailTextsContent(EmailTextsUiState(texts = Loadable.Ready(texts), group = "moved"), PaddingValues(), onBack = {}, onRetry = {}, onGroup = {},
                onOpen = { opened += it }, onAskReset = {}, onDismissReset = {}, onReset = {})
        }
        compose.onNodeWithText(text(R.string.config_texts_modified)).assertExists()
        compose.onNodeWithText("Nuovo orario: :title").performClick()
        compose.onNodeWithText(text(R.string.config_texts_reset_all)).performScrollTo().assertIsEnabled()
        compose.runOnIdle { assertEquals(listOf("notifications.moved.subject"), opened) }
    }

    @Test fun theEditorInsertsPlaceholdersAndBlocksUnknownOnes() {
        val actions = CopyOnWriteArrayList<String>()
        show {
            EmailTextEditor(EmailTextEditUiState(text = Loadable.Ready(subject), value = "Nuovo :boh", preview = "Nuovo :boh"), PaddingValues(),
                onBack = {}, onRetry = {}, onValue = { actions += "value:$it" }, onInsert = { actions += "insert:${it.name}" }, onRestore = { actions += "restore" }, onSave = { actions += "save" })
        }
        compose.onNodeWithText(text(R.string.config_texts_unknown, "boh")).assertExists()
        compose.onNodeWithText(text(R.string.config_save)).performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.config_texts_insert, "titolo evento", ":title")).performClick()
        compose.onNodeWithText(text(R.string.config_texts_restore)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.config_texts_preview)).performScrollTo().assertExists()
        compose.runOnIdle { assertEquals(listOf("insert:title", "restore"), actions) }
    }

    @Test fun theMailShowsNoPasswordAndAsksBeforeTheTest() {
        val actions = CopyOnWriteArrayList<String>()
        val mail = MailSettingsView(host = "smtp.example.test", port = 587, encryptionLabel = "TLS / STARTTLS", username = "tizio", hasPassword = true, recipient = "ada@example.test",
            status = MailStatus(false, "La posta esce dalla configurazione del server", "Trasporto «sendmail»."))
        show {
            MailSettingsContent(MailUiState(mail = Loadable.Ready(mail), confirmingTest = true), PaddingValues(), onBack = {}, onRetry = {},
                onAskTest = { actions += "ask" }, onDismissTest = {}, onSendTest = { actions += "send" }, onWeb = { actions += "web" })
        }
        compose.onNodeWithText(text(R.string.config_mail_test_confirm, "ada@example.test")).assertExists()
        compose.onNodeWithText(text(R.string.config_mail_test_send)).performClick()
        compose.runOnIdle { assertEquals(listOf("send"), actions) }
    }

    @Test fun theMailPageOffersTheTestAndTheWebForTheCredentials() {
        val actions = CopyOnWriteArrayList<String>()
        val mail = MailSettingsView(host = "smtp.example.test", hasPassword = true, recipient = "ada@example.test")
        show {
            MailSettingsContent(MailUiState(mail = Loadable.Ready(mail)), PaddingValues(), onBack = {}, onRetry = {},
                onAskTest = { actions += "ask" }, onDismissTest = {}, onSendTest = {}, onWeb = { actions += "web" })
        }
        compose.onNodeWithText(text(R.string.config_password_saved)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.config_mail_test)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.config_edit_on_web)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("ask", "web"), actions) }
    }

    @Test fun cookiesHaveATabPerPurposeAndMoveWithTheButtons() {
        val actions = CopyOnWriteArrayList<String>()
        val rows = listOf(CookieRow(1, "necessary", name = "incitta_session", purpose = "La sessione"), CookieRow(2, "necessary", name = "XSRF-TOKEN", provider = null, purpose = "Protegge i moduli"))
        val categories = listOf(ConsentCategoryInfo("necessary", "Necessari"), ConsentCategoryInfo("statistics", "Statistiche"), ConsentCategoryInfo("marketing", "Marketing"))
        show {
            CookiesList(PagedState(query = ListQuery(tab = "necessary"), items = rows, page = 1, hasMore = false, total = 2), CookiesUiState(categories = categories), PaddingValues(),
                onBack = {}, onSearch = {}, onTab = { actions += "tab:$it" }, onLoadMore = {}, onRetry = {}, onAdd = { actions += "add" }, onMove = { row, delta -> actions += "move:${row.id}:$delta" })
        }
        compose.onNodeWithText("Marketing").performClick()
        compose.onAllNodesWithText(text(R.string.config_cookies_own)).assertCountEquals(2)
        compose.onNodeWithContentDescription(text(R.string.config_cookies_move_up, "XSRF-TOKEN")).performClick()
        compose.onNodeWithContentDescription(text(R.string.config_cookies_move_up, "incitta_session")).assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.config_cookies_add)).performClick()
        compose.runOnIdle { assertEquals(listOf("tab:marketing", "move:2:-1", "add"), actions) }
    }

    @Test fun aNewRedirectNeedsAnInternalTarget() {
        val options = RedirectOptions(listOf(RedirectCity(1, "Padova")), listOf(RedirectStatusChoice(301, "Definitivo (301)"), RedirectStatusChoice(302, "Temporaneo (302)")))
        show {
            RedirectNewForm(RedirectNewUiState(options = Loadable.Ready(options), draft = RedirectDraft(fromPath = "/volantino", toPath = "//evil.example")), PaddingValues(),
                onBack = {}, onRetry = {}, onEdit = {}, onSave = {})
        }
        compose.onNodeWithText(text(R.string.config_redirects_to_invalid)).performScrollTo().assertExists()
        compose.onAllNodesWithText(text(R.string.config_redirects_add)).filterToOne(hasClickAction()).performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText("Temporaneo (302)").performScrollTo().assertExists()
    }

    @Test fun theCacheIsClearedOnlyAfterConfirming() {
        val actions = CopyOnWriteArrayList<String>()
        val cache = CacheSettingsView(store = "frontend-file", storeLabel = "File sul server", ttlMinutes = 60, environment = CacheEnvironment(redisExtension = false, server = "LiteSpeed"))
        show {
            CacheContent(CacheUiState(cache = Loadable.Ready(cache), confirmingClear = true), PaddingValues(), onBack = {}, onRetry = {}, onAskClear = { actions += "ask" },
                onDismissClear = {}, onClear = { actions += "clear" }, onVerify = { actions += "verify" }, onWeb = {})
        }
        compose.onNodeWithText(text(R.string.config_cache_clear_confirm)).assertExists()
        compose.onNodeWithText(text(R.string.config_cache_clear_action)).performClick()
        compose.runOnIdle { assertEquals(listOf("clear"), actions) }
    }
}
