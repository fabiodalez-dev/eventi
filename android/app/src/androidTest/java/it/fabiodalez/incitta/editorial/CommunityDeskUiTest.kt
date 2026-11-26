package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CarpoolSection
import it.fabiodalez.incitta.data.editorial.CaseEvidence
import it.fabiodalez.incitta.data.editorial.CaseMessage
import it.fabiodalez.incitta.data.editorial.CasePerson
import it.fabiodalez.incitta.data.editorial.CommunitySection
import it.fabiodalez.incitta.data.editorial.DeskCarpoolCase
import it.fabiodalez.incitta.data.editorial.DeskCarpoolItem
import it.fabiodalez.incitta.data.editorial.DeskCommunityItem
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.community.CarpoolCaseDetail
import it.fabiodalez.incitta.ui.editorial.community.CarpoolCaseUiState
import it.fabiodalez.incitta.ui.editorial.community.CarpoolList
import it.fabiodalez.incitta.ui.editorial.community.CarpoolRowAction
import it.fabiodalez.incitta.ui.editorial.community.CarpoolUiState
import it.fabiodalez.incitta.ui.editorial.community.CaseDialog
import it.fabiodalez.incitta.ui.editorial.community.CommunityAction
import it.fabiodalez.incitta.ui.editorial.community.CommunityModerationList
import it.fabiodalez.incitta.ui.editorial.community.CommunityUiState
import it.fabiodalez.incitta.ui.editorial.community.ReplyDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * Le schermate principali di community e passaggi (R7) su un'attività vuota:
 * le schede di «Profili e post» con i pulsanti che le abilità permettono, le
 * schede dei passaggi con la ricerca solo dove il pannello la ha, il caso
 * aperto con conservazione ed evidenze solo a chi le può usare, la risposta
 * a una persona del caso. I testi si leggono dalle risorse.
 */
class CommunityDeskUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)
    private val zone = ZoneId.of("Europe/Rome")

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    @Test fun theCommunityTabsShowOnlyTheAllowedActions() {
        val sections = CopyOnWriteArrayList<String>()
        val actions = CopyOnWriteArrayList<String>()
        val profile = DeskCommunityItem(kind = "profile", id = 3, userId = 9, displayName = "Marta Bellini", handle = "marta", featured = true,
            visibilityLabel = "Tutti, anche senza account", abilities = abilities("feature", "unsuspend"))
        val colleague = DeskCommunityItem(kind = "profile", id = 4, userId = 10, displayName = "Altra moderatrice", handle = "altra", abilities = abilities("feature"))
        show {
            CommunityModerationList(PagedState(query = ListQuery(tab = CommunitySection.PROFILES), items = listOf(profile, colleague), page = 1, hasMore = false, total = 2),
                CommunityUiState(), PaddingValues(), zone, onBack = {}, onSection = { sections += it }, onSearch = {}, onLoadMore = {}, onRetry = {},
                onAction = { item, action -> actions += "${item.id}:$action" })
        }
        compose.onNodeWithText(text(R.string.ed_community_page_title)).assertExists()
        compose.onNodeWithText(text(R.string.ed_community_search_name)).assertExists()
        compose.onNodeWithText("Marta Bellini").assertExists()
        compose.onNodeWithText(text(R.string.ed_community_handle, "marta")).assertExists()
        /* Già in evidenza: si offre di toglierla. Il pari grado non si sospende. */
        compose.onNodeWithText(text(R.string.ed_community_unfeature)).performClick()
        compose.onAllNodesWithText(text(R.string.ed_community_suspend)).assertCountEquals(0)
        compose.onNodeWithText(text(R.string.ed_community_section_restrictions)).performClick()
        compose.runOnIdle {
            assertEquals(listOf("3:${CommunityAction.Unfeature}"), actions)
            assertEquals(listOf(CommunitySection.RESTRICTIONS), sections)
        }
    }

    @Test fun theCasesTabHasNoSearchAndOpensTheCase() {
        val opened = CopyOnWriteArrayList<Long>()
        val case = DeskCarpoolItem(kind = "case", id = 12, reporter = "Ada", status = "open", statusLabel = "Aperto", reason = "safety", abilities = abilities("show"))
        show {
            CarpoolList(PagedState(query = ListQuery(tab = CarpoolSection.CASES), items = listOf(case), page = 1, hasMore = false, total = 1), CarpoolUiState(), null,
                listOf(CarpoolSection.CASES, CarpoolSection.AUDIT), PaddingValues(), zone, onBack = {}, onSection = {}, onSearch = {}, onFilters = {}, onLoadMore = {}, onRetry = {},
                onOpenCase = { opened += it }, onAction = { _, _ -> })
        }
        compose.onNodeWithText(text(R.string.ed_carpool_search)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_section_audit)).assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_section_rides)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_case_row, 12, "Ada")).assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_open_case)).performClick()
        compose.runOnIdle { assertEquals(listOf(12L), opened) }
    }

    @Test fun theUsersTabOffersTheRestrictionsTheServerAllows() {
        val actions = CopyOnWriteArrayList<CarpoolRowAction>()
        val person = DeskCarpoolItem(kind = "user", id = 21, name = "Bruno Neri", carpoolSuspended = true,
            abilities = abilities("suspend_social", "restore_carpool"))
        show {
            CarpoolList(PagedState(query = ListQuery(tab = CarpoolSection.USERS), items = listOf(person), page = 1, hasMore = false, total = 1), CarpoolUiState(), null,
                CarpoolSection.all, PaddingValues(), zone, onBack = {}, onSection = {}, onSearch = {}, onFilters = {}, onLoadMore = {}, onRetry = {},
                onOpenCase = {}, onAction = { _, action -> actions += action })
        }
        compose.onNodeWithText(text(R.string.ed_carpool_search)).assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_user_row, "Bruno Neri", 21)).assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_suspend_global)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_restore_carpool)).performClick()
        compose.runOnIdle { assertEquals(listOf<CarpoolRowAction>(CarpoolRowAction.Restore("carpool")), actions) }
    }

    @Test fun theModeratorSeesTheCaseWithoutRetentionAndEvidence() {
        val asked = CopyOnWriteArrayList<CaseDialog>()
        val case = DeskCarpoolCase(id = 12, reporter = "Ada", status = "open", statusLabel = "Aperto", reason = "safety", body = "Vorrei chiarire questo problema.",
            rideRequestId = 4, revision = 1, messages = listOf(CaseMessage(1, author = "Carla", internal = true, body = "Esamino la segnalazione")),
            people = listOf(CasePerson(7, "Ada #7")), abilities = abilities("assign", "note", "resolve", "reply", "freeze", "unfreeze"))
        show { CarpoolCaseDetail(CarpoolCaseUiState(case = Loadable.Ready(case)), PaddingValues(), zone, onBack = {}, onRetry = {}, onAsk = { asked += it }, onCloseEvidence = {}, onOpenWeb = {}) }
        compose.onNodeWithText(text(R.string.ed_carpool_case_title, 12)).assertExists()
        compose.onNodeWithText("Vorrei chiarire questo problema.").assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_op_reopen)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_retention_title)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_inspect)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.ed_carpool_op_assign)).performClick()
        compose.onNodeWithText("Esamino la segnalazione").performScrollTo().assertExists()
        compose.runOnIdle { assertEquals(listOf<CaseDialog>(CaseDialog.Operation("assign")), asked) }
    }

    @Test fun theAdminSeesRetentionEvidenceAndTheExportOnTheWebsite() {
        val asked = CopyOnWriteArrayList<CaseDialog>()
        val web = CopyOnWriteArrayList<String>()
        val case = DeskCarpoolCase(id = 12, status = "resolved", closedAt = "2026-11-20T10:00:00+00:00", revision = 3,
            abilities = abilities("assign", "note", "reopen", "reply", "hold", "release", "evidence", "export"))
        show { CarpoolCaseDetail(CarpoolCaseUiState(case = Loadable.Ready(case)), PaddingValues(), zone, onBack = {}, onRetry = {}, onAsk = { asked += it }, onCloseEvidence = {}, onOpenWeb = { web += it }) }
        compose.onNodeWithText(text(R.string.ed_carpool_op_reopen)).assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_retention_title)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_inspect)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.ed_carpool_export_open)).performScrollTo().performClick()
        compose.runOnIdle {
            assertEquals(listOf<CaseDialog>(CaseDialog.Evidence), asked)
            assertEquals(listOf("admin/carpool"), web)
        }
    }

    @Test fun theEvidenceIsShownAfterReading() {
        val case = DeskCarpoolCase(id = 12, revision = 1, abilities = abilities("evidence"))
        val evidence = CaseEvidence(caseId = 12, sha256 = "0123456789abcdef0123")
        show { CarpoolCaseDetail(CarpoolCaseUiState(case = Loadable.Ready(case), evidence = evidence), PaddingValues(), zone, onBack = {}, onRetry = {}, onAsk = {}, onCloseEvidence = {}, onOpenWeb = {}) }
        compose.onNodeWithText(text(R.string.ed_carpool_evidence_no_messages)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.ed_carpool_inspect)).assertDoesNotExist()
    }

    @Test fun theReplyNeedsAPersonAndAMessage() {
        val sent = CopyOnWriteArrayList<String>()
        val case = DeskCarpoolCase(id = 12, revision = 1, people = listOf(CasePerson(7, "Ada #7"), CasePerson(8, "Bruno #8")))
        show { ReplyDialog(case, busy = false, errors = FormErrors.None, rules = ReasonRules(), onSend = { id, message -> sent += "$id:$message" }, onDismiss = {}) }
        compose.onNodeWithText(text(R.string.ed_carpool_choose_recipient)).assertExists()
        compose.onNode(hasText(text(R.string.ed_carpool_reply)) and hasClickAction()).assertIsNotEnabled()
        compose.onNodeWithText("Bruno #8").performClick()
        compose.onNodeWithText(text(R.string.ed_carpool_message)).performTextInput("Puoi raccontarmi meglio?")
        compose.onNode(hasText(text(R.string.ed_carpool_reply)) and hasClickAction()).performClick()
        compose.runOnIdle { assertEquals(listOf("8:Puoi raccontarmi meglio?"), sent) }
    }
}
