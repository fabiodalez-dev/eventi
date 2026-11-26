package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CronInstalled
import it.fabiodalez.incitta.data.editorial.CronLines
import it.fabiodalez.incitta.data.editorial.EditorialImportSource
import it.fabiodalez.incitta.data.editorial.ImportFormDefaults
import it.fabiodalez.incitta.data.editorial.ImportPreview
import it.fabiodalez.incitta.data.editorial.ImportPreviewRow
import it.fabiodalez.incitta.data.editorial.ImportSourceFormOptions
import it.fabiodalez.incitta.data.editorial.ImportTypeChoice
import it.fabiodalez.incitta.data.editorial.ScheduledNotificationRow
import it.fabiodalez.incitta.data.editorial.ScheduledTask
import it.fabiodalez.incitta.data.editorial.SchedulerOverviewData
import it.fabiodalez.incitta.data.editorial.SystemRef
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.system.ImportDetailUiState
import it.fabiodalez.incitta.ui.editorial.system.ImportDraft
import it.fabiodalez.incitta.ui.editorial.system.ImportFormCallbacks
import it.fabiodalez.incitta.ui.editorial.system.ImportFormUiState
import it.fabiodalez.incitta.ui.editorial.system.ImportPreviewContent
import it.fabiodalez.incitta.ui.editorial.system.ImportSourceDetail
import it.fabiodalez.incitta.ui.editorial.system.ImportSourceForm
import it.fabiodalez.incitta.ui.editorial.system.ImportSourcesList
import it.fabiodalez.incitta.ui.editorial.system.NotificationsUiState
import it.fabiodalez.incitta.ui.editorial.system.ScheduledNotificationsList
import it.fabiodalez.incitta.ui.editorial.system.ScheduledOperationsContent
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali del sistema (R12) su un'attività vuota: elenco e
 * scheda di una sorgente con i pulsanti che le abilità permettono, il
 * modulo con la categoria obbligatoria senza locale, l'anteprima, gli invii
 * previsti con «Annulla questo invio» solo dove si può, la pagina del cron.
 * I testi si leggono dalle risorse.
 */
class SystemUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)
    private val zone = ZoneId.of("Europe/Rome")

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private val source = EditorialImportSource(id = 3, city = SystemRef(1, "Padova"), venue = SystemRef(5, "Teatro Verdi"), type = "ics", typeLabel = "Calendario ICS",
        url = "https://calendario.test/eventi.ics", hasCredentials = true, defaultCategory = SystemRef(2, "Musica"), isActive = true,
        lastStatus = "failed", lastStatusLabel = "Non riuscita", lastError = "Calendario irraggiungibile", runnable = true, webPath = "admin/import-sources/3/edit")

    @Test fun theImportListHasNoSearchOffersNewAndOpensARow() {
        val opened = CopyOnWriteArrayList<Long>()
        val created = CopyOnWriteArrayList<Boolean>()
        show {
            ImportSourcesList(PagedState(query = ListQuery(), items = listOf(source), page = 1, hasMore = false, total = 1), null, PaddingValues(), zone, canCreate = true,
                onBack = {}, onOpen = { opened += it }, onCreate = { created += true }, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {})
        }
        compose.onNodeWithText(text(R.string.system_imports_title)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_search)).assertDoesNotExist()
        compose.onNodeWithText("Non riuscita").assertExists()
        compose.onNodeWithText("Calendario irraggiungibile").assertExists()
        compose.onNodeWithText(text(R.string.system_imports_new)).performClick()
        compose.onNodeWithText("Teatro Verdi").performClick()
        compose.runOnIdle {
            assertEquals(listOf(3L), opened)
            assertEquals(listOf(true), created)
        }
    }

    @Test fun theSourceShowsOnlyTheAllowedActionsAndNeverTheCredentials() {
        val actions = CopyOnWriteArrayList<String>()
        fun detail(abilities: kotlinx.serialization.json.JsonObject) = ImportDetailUiState(source = Loadable.Ready(source.copy(abilities = abilities)))
        show {
            ImportSourceDetail(detail(abilities("preview", "run", "runs")), PaddingValues(), zone, onBack = {}, onRetry = {}, onEdit = { actions += "edit" },
                onPreview = { actions += "preview" }, onRuns = { actions += "runs" }, onWeb = { actions += "web:$it" }, onToggleActive = { actions += "toggle" },
                onRun = { actions += "run" }, onDelete = { actions += "delete" })
        }
        compose.onNodeWithText(text(R.string.system_import_credentials_set)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.system_import_edit)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.system_import_delete)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.system_import_open_web)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.system_import_preview)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.system_import_run)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("web:admin/import-sources/3/edit", "preview", "run"), actions) }
    }

    @Test fun anAdminCanEditToggleAndDelete() {
        val actions = CopyOnWriteArrayList<String>()
        show {
            ImportSourceDetail(ImportDetailUiState(source = Loadable.Ready(source.copy(abilities = abilities("update", "destroy")))), PaddingValues(), zone, onBack = {}, onRetry = {},
                onEdit = { actions += "edit" }, onPreview = {}, onRuns = {}, onWeb = {}, onToggleActive = { actions += "toggle" }, onRun = {}, onDelete = { actions += "delete" })
        }
        compose.onNodeWithText(text(R.string.system_import_run)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.system_import_edit)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.system_import_deactivate)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.system_import_delete)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf("edit", "toggle", "delete"), actions) }
    }

    @Test fun theFormAsksForTheCategoryWithoutAVenue() {
        val saves = CopyOnWriteArrayList<Boolean>()
        val options = ImportSourceFormOptions(types = listOf(ImportTypeChoice("ics", "Calendario ICS", true), ImportTypeChoice("manual", "Manuale", false)),
            categories = listOf(SystemRef(2, "Musica")), defaults = ImportFormDefaults(cityId = 1))
        val state = ImportFormUiState(options = Loadable.Ready(options), draft = ImportDraft(cityId = null, type = "ics"))
        show { ImportSourceForm(state, PaddingValues(), onBack = {}, callbacks = ImportFormCallbacks(onSave = { saves += true })) }
        compose.onNodeWithText(text(R.string.system_import_new_title)).assertExists()
        compose.onNodeWithText(text(R.string.system_import_error_category)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.system_import_credentials_web)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.system_import_save)).performScrollTo().assertIsNotEnabled()
        compose.runOnIdle { assertTrue(saves.isEmpty()) }
    }

    @Test fun thePreviewListsTheDatesOrTheReason() {
        val rows = ImportPreview(rows = listOf(ImportPreviewRow("05/09/2026 21:30", "Concerto del quartetto", "Teatro Verdi", cancelled = true)), timezone = "Europe/Rome")
        show { ImportPreviewContent(Loadable.Ready(rows), PaddingValues(), onBack = {}, onRetry = {}) }
        compose.onNodeWithText("05/09/2026 21:30").assertExists()
        compose.onNodeWithText("Concerto del quartetto").assertExists()
        compose.onNodeWithText(text(R.string.system_preview_cancelled)).assertExists()
    }

    @Test fun aScheduledNotificationIsCancelledOnlyWhereTheRowAllowsIt() {
        val cancelled = CopyOnWriteArrayList<Long>()
        val rows = listOf(
            ScheduledNotificationRow(id = 9, type = "event_reminder", typeLabel = "Promemoria evento", recipient = "anna@example.test", status = "pending", statusLabel = "In attesa", abilities = abilities("cancel")),
            ScheduledNotificationRow(id = 10, type = "daily_digest", typeLabel = "Riepilogo quotidiano", status = "sent", statusLabel = "Inviata"),
        )
        show {
            ScheduledNotificationsList(PagedState(query = ListQuery(), items = rows, page = 1, hasMore = false, total = 2), NotificationsUiState(), null, PaddingValues(), zone,
                onBack = {}, onSearch = {}, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {}, onCancel = { cancelled += it.id })
        }
        compose.onNodeWithText(text(R.string.system_notifications_search)).assertExists()
        compose.onNodeWithText(text(R.string.system_notification_recipient, "anna@example.test")).assertExists()
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Riepilogo quotidiano")).assertDoesNotExist()
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Promemoria evento")).performClick()
        compose.onNodeWithText(text(R.string.system_notification_cancel)).performClick()
        compose.runOnIdle { assertEquals(listOf(9L), cancelled) }
    }

    @Test fun theCronPageShowsTheLinesAndTheTasks() {
        val data = SchedulerOverviewData(
            installed = CronInstalled(readable = true, scheduler = true, worker = false),
            cron = CronLines("* * * * * cd /sito && php artisan schedule:run", "* * * * * cd /sito && php artisan queue:work"),
            queue = "database",
            tasks = listOf(ScheduledTask(name = "notifications:send", explanation = "Invia le notifiche giunte a scadenza.", command = "php artisan notifications:send", expression = "*/5 * * * *")),
        )
        show { ScheduledOperationsContent(Loadable.Ready(data), PaddingValues(), zone, onBack = {}, onRetry = {}, onSocial = null) }
        compose.onNodeWithText(text(R.string.system_operations_installed, text(R.string.system_operations_in_crontab), text(R.string.system_operations_not_in_crontab))).assertExists()
        compose.onNodeWithText("* * * * * cd /sito && php artisan schedule:run").assertExists()
        compose.onNodeWithText(text(R.string.system_operations_no_social)).performScrollTo().assertExists()
        compose.onNodeWithText("notifications:send").performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.system_operations_open_social)).assertDoesNotExist()
    }
}
