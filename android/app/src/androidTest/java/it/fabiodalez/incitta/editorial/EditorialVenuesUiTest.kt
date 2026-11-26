package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.ApplicationRow
import it.fabiodalez.incitta.data.editorial.EditorialVenueOverview
import it.fabiodalez.incitta.data.editorial.EditorialVenueRow
import it.fabiodalez.incitta.data.editorial.NewVenueDraft
import it.fabiodalez.incitta.data.editorial.OrganizerDraft
import it.fabiodalez.incitta.data.editorial.PersonChoice
import it.fabiodalez.incitta.data.editorial.VenueChoices
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationDecision
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationListContent
import it.fabiodalez.incitta.ui.editorial.applications.ApplicationListUiState
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.organizers.OrganizerFields
import it.fabiodalez.incitta.ui.editorial.venues.NewVenueFields
import it.fabiodalez.incitta.ui.editorial.venues.VenueAction
import it.fabiodalez.incitta.ui.editorial.venues.VenueDetailContent
import it.fabiodalez.incitta.ui.editorial.venues.VenueDetailUiState
import it.fabiodalez.incitta.ui.editorial.venues.VenueListContent
import it.fabiodalez.incitta.ui.editorial.venues.VenueListUiState
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.add
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R4 — le schermate principali di locali, richieste e organizzatori su
 * un'attività vuota: l'elenco con schede e menu della riga secondo
 * `abilities`, la scheda con la moderazione in chiaro, «Nuovo locale», la
 * coda delle richieste, il modulo dell'organizzatore. I testi si leggono
 * dalle risorse.
 */
class EditorialVenuesUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun abilities(vararg allowed: String) = buildJsonObject { allowed.forEach { put(it, true) } }

    @Test fun theVenueListShowsTabsAndOnlyTheAllowedRowActions() {
        val actions = CopyOnWriteArrayList<String>()
        val opened = CopyOnWriteArrayList<Long>()
        val rows = listOf(
            EditorialVenueRow(id = 1, name = "Osteria in attesa", subtitle = "Padova · Bar", status = ValueLabel("pending", "In attesa"), abilities = abilities("approve", "reject")),
            EditorialVenueRow(id = 2, name = "Circolo Aurora", status = ValueLabel("approved", "Approvato"), isVerified = true),
        )
        show {
            VenueListContent(
                state = PagedState(query = ListQuery(tab = "pending"), items = rows, page = 1, hasMore = false, total = 2, tabCounts = mapOf("pending" to 1, "all" to 2)),
                ui = VenueListUiState(statusChoices = listOf(OptionChoice("pending", "In attesa"))),
                padding = PaddingValues(),
                onBack = {}, onSearch = {}, onTab = {}, onFilters = {}, onSort = {}, onLoadMore = {}, onRetry = {},
                onOpen = { opened += it.id },
                onAction = { row, action -> actions += "${action.key}:${row.id}" },
                onExport = {},
            )
        }
        compose.onNodeWithText(text(R.string.editorial_list_tab_count, text(R.string.editorial_venues_tab_pending), 1)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_venues_export)).assertExists()
        /* Il menu c'è solo sulla riga con azioni permesse. */
        compose.onAllNodesWithContentDescription(text(R.string.editorial_actions_for, "Circolo Aurora")).assertCountEquals(0)
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Osteria in attesa")).performClick()
        compose.onNodeWithText(text(R.string.editorial_venue_suspend)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_venue_approve)).performClick()
        compose.onNodeWithText("Circolo Aurora").performClick()
        compose.runOnIdle {
            assertEquals(listOf("approve:1"), actions)
            assertEquals(listOf(2L), opened)
        }
    }

    @Test fun theVenueCardShowsModerationAndHidesWhatTheModeratorCannotDo() {
        val actions = CopyOnWriteArrayList<VenueAction>()
        var edited = false
        val venue = EditorialVenueOverview(
            id = 3, name = "Teatro Belzoni", status = ValueLabel("approved", "Approvato"), isVerified = false, eventsCount = 2,
            address = "Via Roma 1", municipality = "Padova", plan = ValueLabel("free", "Gratuito"), abilities = abilities("suspend", "verify", "reject"),
        )
        /* `VenueDetailContent` scorre da sé (`ManagementScaffold`): niente colonna che scorre intorno. */
        show {
            VenueDetailContent(
                state = VenueDetailUiState(venue = Loadable.Ready(venue)),
                zone = ZoneId.of("Europe/Rome"),
                padding = PaddingValues(),
                onBack = {}, onRetry = {}, onAction = { actions += it }, onTicketing = {}, onDelete = {},
                onEdit = { edited = true }, onImages = {}, onMembers = {}, onEvents = {}, onActivity = {},
            )
        }
        compose.onNodeWithText(text(R.string.editorial_venue_approve)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_venue_edit)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_venue_delete)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_venue_members_manage)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_venue_suspend)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_venue_verify)).performScrollTo().performClick()
        compose.runOnIdle {
            assertEquals(listOf(VenueAction.Suspend, VenueAction.Verify), actions)
            assertFalse(edited)
        }
    }

    @Test fun aNewVenueAsksTheDistrictOnlyForPadova() {
        val choices = VenueChoices(buildJsonObject {
            put("cities", buildJsonArray { add(buildJsonObject { put("value", "1"); put("label", "Padova") }) })
            put("types", buildJsonArray { add(buildJsonObject { put("value", "bar"); put("label", "Bar") }) })
            put("district_municipality", buildJsonArray { add(buildJsonObject { put("value", "Padova"); put("label", "Padova") }) })
        })
        var created = false
        show {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                NewVenueFields(NewVenueDraft(cityId = "1", type = "bar", municipality = "Padova"), choices, FormErrors(fields = mapOf("lat" to "La latitudine è obbligatoria.")), false, onEdit = {}, onCreate = { created = true })
            }
        }
        compose.onNodeWithText(text(R.string.editorial_venue_district)).assertExists()
        compose.onNodeWithText("La latitudine è obbligatoria.").assertExists()
        compose.onNodeWithText(text(R.string.editorial_venue_create)).performScrollTo().performClick()
        compose.runOnIdle { assertTrue(created) }
    }

    @Test fun theApplicationQueueFlagsDuplicatesAndOffersTheDecisions() {
        val decisions = CopyOnWriteArrayList<ApplicationDecision>()
        show {
            ApplicationListContent(
                state = PagedState(query = ListQuery(tab = "pending"), items = listOf(
                    ApplicationRow(id = 9, venueName = "Bottega Nuova", kind = ValueLabel("venue", "Locale nuovo"), status = ValueLabel("pending", "In attesa"), possibleDuplicate = true, abilities = abilities("approve", "reject")),
                ), page = 1, hasMore = false, total = 1),
                ui = ApplicationListUiState(),
                padding = PaddingValues(),
                onBack = {}, onSearch = {}, onTab = {}, onFilters = {}, onLoadMore = {}, onRetry = {}, onOpen = {},
                onDecision = { decisions += it },
            )
        }
        compose.onNodeWithText(text(R.string.editorial_application_duplicate)).assertExists()
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Bottega Nuova")).performClick()
        compose.onNodeWithText(text(R.string.editorial_venue_reject)).performClick()
        compose.runOnIdle { assertTrue(decisions.single() is ApplicationDecision.Reject) }
    }

    @Test fun theOrganizerFormAsksTheRecipientOnlyWithMessagesOn() {
        var draft by mutableStateOf(OrganizerDraft(name = "Musica Insieme", owner = PersonChoice(7, "Ada", "ada@example.test")))
        show {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                OrganizerFields(draft, listOf(OptionChoice("1", "Padova")), listOf(OptionChoice("disabled", "Nessuno"), OptionChoice("everyone", "Tutti")), FormErrors.None,
                    onEdit = { change -> draft = change(draft) }, onPickOwner = {}, onAddUser = {}, onRemoveUser = {})
            }
        }
        compose.onNodeWithText("Ada · ada@example.test").assertExists()
        compose.onNodeWithText(text(R.string.editorial_organizer_contact_email)).assertDoesNotExist()
        compose.runOnIdle { draft = draft.copy(contactMode = "everyone") }
        compose.onNodeWithText(text(R.string.editorial_organizer_contact_email)).assertExists()
        assertEquals(JsonPrimitive(7L), draft.payload()["owner_id"])
    }
}
