package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.Modifier
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CancelImpact
import it.fabiodalez.incitta.data.editorial.EditorialActivityRow
import it.fabiodalez.incitta.data.editorial.EditorialEventCity
import it.fabiodalez.incitta.data.editorial.EditorialEventDetail
import it.fabiodalez.incitta.data.editorial.EditorialEventLinks
import it.fabiodalez.incitta.data.editorial.EditorialEventRow
import it.fabiodalez.incitta.data.editorial.EditorialSubmissionDetail
import it.fabiodalez.incitta.data.editorial.EditorialChoice
import it.fabiodalez.incitta.data.editorial.SubmissionChoices
import it.fabiodalez.incitta.data.editorial.SubmissionForm
import it.fabiodalez.incitta.data.management.LabeledValue
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.events.ActivityContent
import it.fabiodalez.incitta.ui.editorial.events.EventDecision
import it.fabiodalez.incitta.ui.editorial.events.EventDecisionDialog
import it.fabiodalez.incitta.ui.editorial.events.EventRecordContent
import it.fabiodalez.incitta.ui.editorial.events.EventsQueueContent
import it.fabiodalez.incitta.ui.editorial.events.SubmissionDraft
import it.fabiodalez.incitta.ui.editorial.events.SubmissionReviewContent
import it.fabiodalez.incitta.ui.management.common.FormErrors
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R2 — le schermate principali degli eventi della redazione su un'attività
 * vuota, con stati preparati: l'elenco con le schede e il menu della riga
 * che mostra solo le azioni permesse, la scheda con le decisioni e le
 * pagine collegate, la conferma dell'annullamento con i numeri, l'esame di
 * una proposta per chi non può pubblicare, la cronologia con «Ripristina».
 */
class EditorialEventsUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    /* Scheda ed esame stanno dentro la colonna che scorre di `ManagementScaffold`: qui la stessa colonna. */
    private fun showScrolling(content: @androidx.compose.runtime.Composable () -> Unit) {
        show { Column(Modifier.verticalScroll(rememberScrollState())) { content() } }
    }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private val padova = EditorialEventCity(1, "Padova", "Europe/Rome")

    private val pending = EditorialEventRow(
        id = 41, title = "Concerto all’alba", status = LabeledValue("pending", "In attesa"),
        venue = NamedRef(3, "Circolo Aurora"), city = padova, occurrencesCount = 2,
        nextStartsAt = "2026-12-12T20:00:00+00:00",
        abilities = abilities("view", "publish", "reject", "cancel"),
    )

    @Test fun theListShowsRowsAndOnlyThePermittedActions() {
        val actions = CopyOnWriteArrayList<String>()
        val tabs = CopyOnWriteArrayList<String?>()
        show {
            EventsQueueContent(
                state = PagedState(query = ListQuery(tab = "pending"), items = listOf(pending), page = 1, hasMore = false, total = 1, tabCounts = mapOf("all" to 9, "pending" to 1)),
                filters = emptyList(), padding = PaddingValues(), selection = emptySet(), busy = false, message = null, isError = false,
                exportReady = false, showSubmissions = true,
                onBack = {}, onSearch = {}, onLoadMore = {}, onRetry = {}, onTab = { tabs += it }, onFilters = {},
                onOpen = {}, onSelect = {}, onAction = { _, key -> actions += key },
                onExport = {}, onShareExport = {}, onOpenExport = {}, onDeleteSelected = {}, onClearSelection = {}, onDismissMessage = {}, onSubmissions = {},
            )
        }
        compose.onNodeWithText("Concerto all’alba").assertExists()
        compose.onNodeWithText(text(R.string.editorial_events_submissions)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_list_tab_count, text(R.string.editorial_events_tab_all), 9)).performClick()
        compose.runOnIdle { assertEquals(listOf<String?>(null), tabs) }

        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Concerto all’alba")).performClick()
        compose.onNodeWithText(text(R.string.editorial_events_action_publish)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_events_action_reject)).assertExists()
        // Il moderatore: niente evidenza, copia, unione, eliminazione.
        compose.onNodeWithText(text(R.string.editorial_events_action_feature)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_events_action_duplicate)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_events_action_destroy)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_events_action_publish)).performClick()
        compose.runOnIdle { assertEquals(listOf("publish"), actions) }
    }

    @Test fun theSelectionBarAppearsWithTheCount() {
        val deleted = CopyOnWriteArrayList<Boolean>()
        show {
            EventsQueueContent(
                state = PagedState(items = listOf(pending.copy(abilities = abilities("view", "destroy"))), page = 1, hasMore = false, total = 1),
                filters = emptyList(), padding = PaddingValues(), selection = setOf(41L), busy = false, message = "Eliminati: 1. Non eliminabili: 0.", isError = false,
                exportReady = true, showSubmissions = false,
                onBack = {}, onSearch = {}, onLoadMore = {}, onRetry = {}, onTab = {}, onFilters = {},
                onOpen = {}, onSelect = {}, onAction = { _, _ -> },
                onExport = {}, onShareExport = {}, onOpenExport = {}, onDeleteSelected = { deleted += true }, onClearSelection = {}, onDismissMessage = {}, onSubmissions = {},
            )
        }
        compose.onNodeWithText(text(R.string.editorial_events_selected, 1)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_events_export_share)).assertExists()
        compose.onNodeWithText("Eliminati: 1. Non eliminabili: 0.").assertExists()
        compose.onNodeWithText(text(R.string.editorial_events_submissions)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_events_delete_selected)).performClick()
        compose.runOnIdle { assertEquals(listOf(true), deleted) }
    }

    @Test fun theRecordShowsTheDecisionsAndTheLinkedPages() {
        val actions = CopyOnWriteArrayList<String>()
        val web = CopyOnWriteArrayList<String>()
        val event = EditorialEventDetail(
            id = 41, title = "Concerto all’alba", status = LabeledValue("draft", "Bozza"), city = padova,
            venue = NamedRef(3, "Circolo Aurora"), scheduledPublishAt = "2099-01-01T10:00:00+00:00",
            links = EditorialEventLinks(statistics = "admin/statistiche-condivisioni/event/41"),
            abilities = abilities("publish", "reject", "cancel", "preview", "activity", "edit"),
        )
        showScrolling {
            EventRecordContent(event, busy = false, message = null, isError = false, onDismissMessage = {},
                onAction = { actions += it }, onPreview = { actions += "preview" }, onActivity = { actions += "activity" }, onOpenWeb = { web += it })
        }
        // Una pubblicazione programmata nel futuro: il pulsante dice «Approva programmazione», come il pannello.
        compose.onNodeWithText(text(R.string.editorial_events_action_approve_schedule)).performClick()
        compose.onNodeWithText(text(R.string.editorial_events_action_preview)).performClick()
        compose.onNodeWithText(text(R.string.editorial_events_action_statistics)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_events_action_social)).assertDoesNotExist()
        compose.runOnIdle {
            assertEquals(listOf("publish", "preview"), actions)
            assertEquals(listOf("admin/statistiche-condivisioni/event/41"), web)
        }
    }

    @Test fun cancellingSaysHowManyDatesAndPeopleAndSendsTheNote() {
        val decisions = CopyOnWriteArrayList<EventDecision>()
        show {
            EventDecisionDialog(key = "cancel", featured = false, approvesSchedule = false, zone = ZoneId.of("Europe/Rome"), cityName = "Padova",
                impact = CancelImpact(upcomingDates = 3, savedBy = 12), busy = false, onDecision = { decisions += it }, onDismiss = {})
        }
        compose.onNodeWithText(text(R.string.editorial_events_cancel_text, 3, 12)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_events_cancel_note)).performTextInput("Maltempo")
        compose.onNodeWithText(text(R.string.editorial_events_cancel_confirm)).performClick()
        compose.runOnIdle { assertEquals(listOf<EventDecision>(EventDecision.Cancel("Maltempo")), decisions) }
    }

    @Test fun aModeratorExaminesAndRejectsButCannotPublishASubmission() {
        val rejects = CopyOnWriteArrayList<Boolean>()
        val detail = EditorialSubmissionDetail(
            id = 5, title = "Proposta", contactName = "Giulia Rossi", contactEmail = "giulia@example.test", venueHint = "Cortile delle Arti",
            status = LabeledValue("pending", "In attesa"), timezone = "Europe/Rome",
            form = SubmissionForm(title = "Proposta", startsAtHint = "2026-12-10T19:00:00+00:00"),
            choices = SubmissionChoices(venues = listOf(EditorialChoice("3", "Circolo Aurora")), priceTypes = listOf(EditorialChoice("unknown", "Non indicato"))),
            abilities = abilities("reject"),
        )
        showScrolling {
            SubmissionReviewContent(detail, SubmissionDraft.from(detail), FormErrors.None, busy = false, message = null, isError = false,
                onEdit = {}, onPublish = {}, onReject = { rejects += true }, onOpenEvent = {})
        }
        compose.onNodeWithText("giulia@example.test").assertExists()
        compose.onNodeWithText(text(R.string.editorial_submission_publish_denied)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.editorial_submission_publish)).performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText(text(R.string.editorial_submission_reject)).performScrollTo().performClick()
        compose.runOnIdle { assertEquals(listOf(true), rejects) }
    }

    @Test fun theHistoryOffersRestoreOnlyWhereItCan() {
        val restored = CopyOnWriteArrayList<Long>()
        val rows = listOf(
            EditorialActivityRow(id = 1, createdAt = "2026-11-20T10:00:00+00:00", event = LabeledValue("updated", "Modificato"), causer = "Ada Rossi",
                changes = "Titolo: Vecchio → Nuovo", restorableFields = listOf("Titolo"), abilities = abilities("restore")),
            EditorialActivityRow(id = 2, createdAt = "2026-11-19T10:00:00+00:00", event = LabeledValue("created", "Creato"), causer = "Sistema"),
        )
        show {
            ActivityContent(PagedState(items = rows, page = 1, hasMore = false, total = 2), PaddingValues(), ZoneId.of("Europe/Rome"), busy = false,
                message = null, isError = false, onBack = {}, onLoadMore = {}, onRetry = {}, onRestore = { restored += it.id }, onDismissMessage = {})
        }
        compose.onNodeWithText("Titolo: Vecchio → Nuovo").assertExists()
        compose.onAllNodesWithText(text(R.string.editorial_activity_restore)).assertCountEquals(1)
        compose.onNodeWithText(text(R.string.editorial_activity_restore)).performClick()
        compose.runOnIdle { assertEquals(listOf(1L), restored) }
    }
}
