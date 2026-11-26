package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialCity
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDeskSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSocialBatch
import it.fabiodalez.incitta.data.editorial.EditorialSocialSource
import it.fabiodalez.incitta.data.editorial.EditorialUnlockState
import it.fabiodalez.incitta.data.editorial.SocialMetaLink
import it.fabiodalez.incitta.data.editorial.SocialPublicationRow
import it.fabiodalez.incitta.data.editorial.SocialResult
import it.fabiodalez.incitta.data.editorial.SocialResultMeta
import it.fabiodalez.incitta.data.editorial.SocialSettings
import it.fabiodalez.incitta.data.editorial.SocialSettingsStatus
import it.fabiodalez.incitta.data.editorial.SocialSettingsValues
import it.fabiodalez.incitta.data.editorial.SocialToday
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialCatalogMeta
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialDayMeta
import it.fabiodalez.incitta.data.management.SocialLabel
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialBatchScreen
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialBatchViewModel
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialStudioScreen
import it.fabiodalez.incitta.ui.editorial.social.EditorialSocialStudioViewModel
import it.fabiodalez.incitta.ui.editorial.social.SocialPublicationsScreen
import it.fabiodalez.incitta.ui.editorial.social.SocialPublicationsViewModel
import it.fabiodalez.incitta.ui.editorial.social.SocialSettingsScreen
import it.fabiodalez.incitta.ui.editorial.social.SocialSettingsViewModel
import java.io.File
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali del Social della redazione (R9) su un'attività
 * vuota, con un server finto: lo studio con le sue porte e «Prepara», un
 * pacchetto con la conferma prima di pubblicare, lo storico con le azioni
 * della riga, le impostazioni senza i segreti. I testi si leggono dalle
 * risorse.
 */
class SocialUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private class Desk : EditorialDeskSource {
        override suspend fun enabled() = true
        override suspend fun desk(city: String?) = EditorialDesk(role = "admin", city = EditorialCity(1, city ?: "padova", "Padova", isActive = true), unlock = EditorialUnlockState(unlocked = true))
        override suspend fun options() = ManagementOptions()
        override suspend fun unlock(password: String) = EditorialUnlockState(unlocked = true)
    }

    private class Social : EditorialSocialSource {
        val calls = CopyOnWriteArrayList<String>()
        private val day = listOf(SocialCard(id = 1, eventId = 10, title = "Concerto di musica popolare", businessDate = "2026-09-11", selectable = true, status = SocialLabel("scheduled", "In programma")))
        val publication = SocialPublicationRow(id = 7, platform = "telegram", platformLabel = "Telegram", part = 1, status = SocialLabel("scheduled", "Programmata"),
            abilities = buildJsonObject { put("reschedule", JsonPrimitive(true)); put("cancel", JsonPrimitive(true)) })
        var settings = SocialSettings(
            values = SocialSettingsValues(cityId = 1, pageId = "500", caption = "Il :data"),
            status = SocialSettingsStatus(exists = true, appSecretSaved = true, accessTokenSaved = false),
        )

        override suspend fun catalog(city: String?, query: SocialCatalogQuery) = SocialCatalogPage(day, SocialCatalogMeta(total = 1, from = "2026-09-10", until = "2026-10-09"))
        override suspend fun calendar(city: String?, month: String, date: String, eventId: Long?, search: String) = SocialCalendar()
        override suspend fun day(city: String?, date: String?, eventId: Long?) = SocialDay(day, SocialDayMeta(date = "2026-09-11"))
        override suspend fun today(city: String?) = SocialToday(day)
        override suspend fun prepareToday(city: String?) = EditorialSocialBatch(id = "oggi")
        override suspend fun recent(city: String?) = emptyList<SocialBatchSummary>()
        override suspend fun prepare(city: String?, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): EditorialSocialBatch {
            calls += "prepare:$occurrences"
            return EditorialSocialBatch(id = "nuovo")
        }
        override suspend fun batch(id: String) = EditorialSocialBatch(id = id, date = "2026-09-11", timezone = "Europe/Rome", captions = listOf("Cosa fare a Padova il 11 settembre 2026?"))
        override suspend fun image(batchId: String, item: SocialBatchItem, directory: File) = DownloadedFile(File(directory, "a.jpg"), "image/jpeg")
        override suspend fun zip(batch: EditorialSocialBatch, directory: File) = DownloadedFile(File(directory, "a.zip"), "application/zip")
        override suspend fun publish(batchId: String): SocialResult<List<SocialPublicationRow>> {
            calls += "publish:$batchId"
            return SocialResult(listOf(publication), SocialResultMeta("Pubblicazione messa in coda. Controlla l’esito nello storico."))
        }
        override suspend fun schedule(batchId: String, local: String) = SocialResult(listOf(publication))
        override suspend fun publications(city: String?, params: List<Pair<String, String>>) = EditorialPage(listOf(publication), PageMeta(total = 1))
        override suspend fun reschedule(id: Long, local: String) = SocialResult(publication)
        override suspend fun cancel(id: Long): SocialResult<SocialPublicationRow> {
            calls += "cancel:$id"
            return SocialResult(publication.copy(status = SocialLabel("cancelled", "Programmazione annullata"), abilities = null), SocialResultMeta("Programmazione annullata se non era già in esecuzione"))
        }
        override suspend fun retry(id: Long) = SocialResult(publication)
        override suspend fun settings() = settings
        override suspend fun saveSettings(values: SocialSettingsValues): SocialResult<SocialSettings> {
            calls += "save"
            return SocialResult(settings.copy(values = values), SocialResultMeta("Impostazioni salvate"))
        }
        override suspend fun verifyMeta() = SocialResult(settings)
        override suspend fun verifyTelegram(): SocialResult<SocialSettings> {
            calls += "telegram"
            return SocialResult(settings, SocialResultMeta("Bot e permessi del canale Telegram verificati"))
        }
        override suspend fun metaLink() = SocialMetaLink(url = "https://example.test/social/meta/collega")
        override suspend fun disconnectMeta() = SocialResult(settings)
    }

    private val social = Social()
    private val repository = EditorialRepository(1, "t", Desk(), EditorialLocalLock(clock = { 0L })).also { it.selectedCity = "padova" }

    @Test fun theStudioPreparesTheChosenEveningsAndOpensTheDoorsAroundIt() {
        val opened = CopyOnWriteArrayList<String>()
        compose.runOnIdle {
            val viewModel = EditorialSocialStudioViewModel(repository, social, SavedStateHandle())
            compose.activity.setContent {
                InCittaTheme { Surface {
                    EditorialSocialStudioScreen(viewModel, PaddingValues(), token = "t", onBack = {},
                        onOpenBatch = { opened += "batch:$it" }, onOpenToday = { opened += "today" },
                        onOpenHistory = { opened += "history" }, onOpenSettings = { opened += "settings" })
                } }
            }
        }
        compose.onNodeWithText(text(R.string.editorial_social_settings)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_social_today_card)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.social_choose)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.social_generate)).performScrollTo().assertIsEnabled().performClick()
        compose.waitUntil(5_000) { opened.any { it.startsWith("batch:") } }
        compose.runOnIdle {
            assertEquals(listOf("settings", "today", "batch:nuovo"), opened)
            assertEquals(listOf("prepare:[1]"), social.calls)
        }
    }

    @Test fun aBatchIsPublishedOnlyAfterTheConfirm() {
        compose.runOnIdle {
            val viewModel = EditorialSocialBatchViewModel(repository, social, SavedStateHandle(mapOf("id" to "abc")))
            compose.activity.setContent { InCittaTheme { Surface { EditorialSocialBatchScreen(viewModel, PaddingValues(), onBack = {}, onOpenHistory = {}) } } }
        }
        compose.onNodeWithText("Cosa fare a Padova il 11 settembre 2026?").performScrollTo().assertExists()
        compose.onAllNodesWithText(text(R.string.editorial_social_publish))[0].performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_social_publish_confirm)).assertExists()
        compose.runOnIdle { assertTrue(social.calls.isEmpty()) }
        compose.onAllNodesWithText(text(R.string.editorial_social_publish)).filterToOne(hasAnyAncestor(isDialog())).performClick()
        compose.waitUntil(5_000) { social.calls.contains("publish:abc") }
        compose.onNodeWithText("Pubblicazione messa in coda. Controlla l’esito nello storico.").assertExists()
    }

    @Test fun theHistoryOffersOnlyTheActionsOfTheRow() {
        compose.runOnIdle {
            val viewModel = SocialPublicationsViewModel(repository, social, SavedStateHandle())
            compose.activity.setContent {
                InCittaTheme { Surface { SocialPublicationsScreen(viewModel, PaddingValues(), ZoneId.of("Europe/Rome"), onBack = {}, onOpenBatch = {}) } }
            }
        }
        val title = text(R.string.editorial_social_row_title, "Telegram", 1)
        compose.onNodeWithText(title).assertExists()
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, title)).performClick()
        compose.onNodeWithText(text(R.string.editorial_social_retry)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_social_cancel_schedule)).performClick()
        compose.waitUntil(5_000) { social.calls.contains("cancel:7") }
        compose.onNodeWithText("Programmazione annullata").assertExists()
    }

    @Test fun theSettingsNeverAskForSecretsAndSaveBeforeVerifying() {
        compose.runOnIdle {
            val viewModel = SocialSettingsViewModel(repository, social)
            compose.activity.setContent {
                InCittaTheme { Surface { SocialSettingsScreen(viewModel, PaddingValues(), onBack = {}, onOpenWeb = {}, onOpenUrl = {}) } }
            }
        }
        compose.onNodeWithText(text(R.string.editorial_social_secrets_web)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_social_meta_status, text(R.string.editorial_social_saved), text(R.string.editorial_social_missing), text(R.string.editorial_social_to_verify)))
            .performScrollTo().assertExists()
        compose.onNodeWithText("Meta App Secret").assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_social_verify_telegram)).performScrollTo().performClick()
        compose.waitUntil(5_000) { social.calls.contains("telegram") }
        compose.runOnIdle { assertEquals(listOf("save", "telegram"), social.calls) }
        compose.onNodeWithText("Bot e permessi del canale Telegram verificati").performScrollTo().assertExists()
    }
}
