package it.fabiodalez.incitta.ui.editorial.community

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.CarpoolSection
import it.fabiodalez.incitta.data.editorial.CaseEvidence
import it.fabiodalez.incitta.data.editorial.CasePerson
import it.fabiodalez.incitta.data.editorial.CommunityDeskMeta
import it.fabiodalez.incitta.data.editorial.CommunityDeskResult
import it.fabiodalez.incitta.data.editorial.CommunityDeskSource
import it.fabiodalez.incitta.data.editorial.DeskCarpoolCase
import it.fabiodalez.incitta.data.editorial.DeskCarpoolItem
import it.fabiodalez.incitta.data.editorial.DeskCommunityItem
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDeskSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R7 con un server finto: le pagine per permesso, le schede, le azioni con il motivo, la password, i conflitti del caso. */
@OptIn(ExperimentalCoroutinesApi::class)
class CommunityViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private class FakeCommunityDesk : CommunityDeskSource {
        val calls = mutableListOf<String>()
        var community = mapOf<String, List<DeskCommunityItem>>()
        var carpool = mapOf<String, List<DeskCarpoolItem>>()
        var case = DeskCarpoolCase(id = 5, status = "open", revision = 1, people = listOf(CasePerson(7, "Ada #7"), CasePerson(8, "Bruno #8")))
        var failNext: Exception? = null

        private fun maybeFail() { failNext?.let { failNext = null; throw it } }
        private fun <T> page(rows: List<T>) = EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))
        private fun q(params: List<Pair<String, String>>) = params.joinToString("&") { "${it.first}=${it.second}" }

        override suspend fun community(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCommunityItem> { calls += "community/$section?${q(params)}"; return page(community[section].orEmpty()) }
        override suspend fun featureProfile(id: Long, enabled: Boolean, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "feature:$id:$enabled:$reason"; maybeFail()
            return CommunityDeskResult(community.getValue("profiles").first { it.id == id }.copy(featured = enabled), CommunityDeskMeta("Fatto."))
        }
        override suspend fun suspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "suspend:$userId:$reason"; maybeFail()
            return CommunityDeskResult(community.getValue("profiles").first { it.userId == userId }.copy(suspended = true))
        }
        override suspend fun unsuspendMember(userId: Long, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "unsuspend:$userId:$reason"
            return CommunityDeskResult(community.getValue("profiles").first { it.userId == userId }.copy(suspended = false))
        }
        override suspend fun hideContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "hide:$kind:$id:$reason"
            return CommunityDeskResult(community.getValue(kind).first { it.id == id }.copy(hidden = true))
        }
        override suspend fun restoreContent(kind: String, id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "restore:$kind:$id:$reason"
            return CommunityDeskResult(community.getValue(kind).first { it.id == id }.copy(hidden = false))
        }
        override suspend fun removeRestriction(id: Long, reason: String): CommunityDeskResult<DeskCommunityItem> {
            calls += "remove:$id:$reason"
            return CommunityDeskResult(DeskCommunityItem(kind = "restriction", id = id, removed = true))
        }
        override suspend fun carpool(section: String, params: List<Pair<String, String>>): EditorialPage<DeskCarpoolItem> { calls += "carpool/$section?${q(params)}"; return page(carpool[section].orEmpty()) }
        override suspend fun carpoolCase(id: Long): DeskCarpoolCase { calls += "case:$id"; return case }
        override suspend fun manageCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> {
            calls += "manage:$id:$op:$reason:$revision"; maybeFail()
            case = case.copy(status = if (op == "resolve") "resolved" else "assigned", revision = revision + 1)
            return CommunityDeskResult(case, CommunityDeskMeta("Fatto: l'operazione è registrata."))
        }
        override suspend fun replyCase(id: Long, recipientId: Long, message: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> {
            calls += "reply:$id:$recipientId:$message:$revision"
            case = case.copy(status = "waiting", revision = revision + 1)
            return CommunityDeskResult(case)
        }
        override suspend fun holdCase(id: Long, op: String, reason: String, revision: Int): CommunityDeskResult<DeskCarpoolCase> {
            calls += "hold:$id:$op:$reason:$revision"; maybeFail()
            case = case.copy(revision = revision + 1, holdUntil = "2027-01-01T00:00:00+00:00")
            return CommunityDeskResult(case)
        }
        override suspend fun freezeChat(id: Long, freeze: Boolean, reason: String): CommunityDeskResult<DeskCarpoolCase> {
            calls += "freeze:$id:$freeze:$reason"
            return CommunityDeskResult(case)
        }
        override suspend fun caseEvidence(id: Long, reason: String): CaseEvidence { calls += "evidence:$id:$reason"; maybeFail(); return CaseEvidence(caseId = id, sha256 = "abc") }
        override suspend fun cancelRide(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem> {
            calls += "cancel:$id:$reason"; maybeFail()
            return CommunityDeskResult(carpool.getValue("rides").first { it.id == id }.copy(status = "cancelled", abilities = null))
        }
        override suspend fun moderateRideReview(id: Long, visible: Boolean, reason: String): CommunityDeskResult<DeskCarpoolItem> {
            calls += "review:$id:$visible:$reason"
            return CommunityDeskResult(carpool.getValue("reviews").first { it.id == id }.copy(hidden = !visible))
        }
        override suspend fun suspendRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem> {
            calls += "suspend-rider:$userId:$scope:$reason"; maybeFail()
            return CommunityDeskResult(carpool.getValue("users").first { it.id == userId }.copy(carpoolSuspended = true))
        }
        override suspend fun restoreRider(userId: Long, scope: String, reason: String): CommunityDeskResult<DeskCarpoolItem> {
            calls += "restore-rider:$userId:$scope:$reason"
            return CommunityDeskResult(carpool.getValue("users").first { it.id == userId }.copy(carpoolSuspended = false))
        }
        override suspend fun retryDelivery(id: Long, reason: String): CommunityDeskResult<DeskCarpoolItem> {
            calls += "retry:$id:$reason"
            return CommunityDeskResult(DeskCarpoolItem(kind = "delivery", id = id, retried = true))
        }
    }

    /* Un banco con tutti i permessi della pagina dei passaggi. */
    private class PermissiveDesk : EditorialDeskSource by FakeDesk() {
        override suspend fun desk(city: String?): EditorialDesk =
            EditorialDesk(role = "admin", permissions = mapOf("community_manage" to true, "cases_manage" to true, "carpool_manage" to true))
    }

    private val desk = FakeDesk()
    private val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))

    private fun passwordRequired() = ApiException(423, ApiProblem(message = "Password", code = "PASSWORD_REQUIRED"))
    private fun conflict() = ApiException(409, ApiProblem(message = "Il caso è cambiato.", code = "CONFLICT"))

    @Test fun thePagesFollowThePanelPermissions() {
        val staff = EditorialPermissions(mapOf("community_manage" to true, "cases_manage" to true, "carpool_manage" to true))
        assertEquals(CommunityPage.entries, communityPages(staff))
        assertEquals(listOf(CommunityPage.Carpool), communityPages(EditorialPermissions(mapOf("cases_manage" to true))))
        assertEquals(emptyList<CommunityPage>(), communityPages(EditorialPermissions()))
        assertEquals("carpool_open", CommunityPage.Carpool.badge)
        /* Senza `carpool_manage` restano casi e registro, come il pannello. */
        assertEquals(CarpoolSection.all, carpoolSections(staff))
        assertEquals(listOf(CarpoolSection.CASES, CarpoolSection.AUDIT), carpoolSections(EditorialPermissions(mapOf("cases_manage" to true))))
    }

    @Test fun theRowsAreReadFromTheServerWithTheirAbilities() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
        val profile = json.decodeFromString<DeskCommunityItem>(
            """{"kind":"profile","id":3,"user_id":9,"display_name":"Marta","handle":"marta","featured":true,"suspended":false,"abilities":{"feature":true,"suspend":true,"unsuspend":false},"extra":1}""",
        )
        assertEquals("profile-3", profile.key)
        assertEquals(listOf(CommunityAction.Unfeature, CommunityAction.Suspend), communityActions(profile, abilitiesOf(profile.abilities)))
        val post = json.decodeFromString<DeskCommunityItem>("""{"kind":"post","id":3,"hidden":true,"abilities":{"hide":false,"restore":true}}""")
        assertEquals("post-3", post.key)
        assertEquals(listOf(CommunityAction.Restore), communityActions(post, abilitiesOf(post.abilities)))
        val user = json.decodeFromString<DeskCarpoolItem>(
            """{"kind":"user","id":4,"carpool_suspended":true,"abilities":{"suspend_social":true,"restore_social":false,"suspend_carpool":false,"restore_carpool":true,"suspend_global":false,"restore_global":false}}""",
        )
        assertEquals(listOf(CarpoolRowAction.Suspend("social"), CarpoolRowAction.Restore("carpool")), carpoolActions(user, abilitiesOf(user.abilities)))
        val feedback = json.decodeFromString<DeskCarpoolItem>("""{"kind":"feedback","id":2,"abilities":{}}""")
        assertEquals(emptyList<CarpoolRowAction>(), carpoolActions(feedback, abilitiesOf(feedback.abilities)))
        val case = json.decodeFromString<DeskCarpoolCase>("""{"id":5,"revision":3,"messages":[{"id":1,"internal":true,"body":"Nota"}],"people":[{"id":7,"label":"Ada #7"}],"review":{"rating":2,"body":"x"}}""")
        assertTrue(case.messages.single().internal)
        assertEquals(2, case.review?.rating)
    }

    @Test fun theCommunitySectionIsInThePathAndNotAParameter() {
        val source = FakeCommunityDesk()
        val saved = SavedStateHandle(mapOf("section" to "posts", "q" to "concerto"))
        val viewModel = CommunityModerationViewModel(repository, source, saved)
        assertEquals("community/posts?page=1&per_page=20&q=concerto", source.calls.first())
        viewModel.selectSection("restrictions")
        assertEquals("restrictions", saved.get<String>("section"))
        assertEquals("community/restrictions?page=1&per_page=20&q=concerto", source.calls.last())
        viewModel.selectSection("sconosciuta")
        assertEquals("community/restrictions?page=1&per_page=20&q=concerto", source.calls.last())
    }

    @Test fun aCommunityActionNeedsAValidReasonAndReplacesTheRow() {
        val profile = DeskCommunityItem(kind = "profile", id = 3, userId = 9, abilities = abilities("feature", "suspend"))
        val source = FakeCommunityDesk().apply { community = mapOf("profiles" to listOf(profile)) }
        val viewModel = CommunityModerationViewModel(repository, source, SavedStateHandle())
        viewModel.ask(profile, CommunityAction.Feature)
        viewModel.confirm("no")
        assertFalse(source.calls.any { it.startsWith("feature") })
        viewModel.confirm("  Profilo molto attivo  ")
        assertEquals("feature:3:true:Profilo molto attivo", source.calls.last())
        assertTrue(viewModel.list.current.value.items.single().featured)
        assertNull(viewModel.uiState.value.pending)
        assertEquals(UiText.Raw("Fatto."), viewModel.uiState.value.message)
    }

    @Test fun suspendingAsksThePasswordAndThenRetries() {
        val profile = DeskCommunityItem(kind = "profile", id = 3, userId = 9, abilities = abilities("feature", "suspend"))
        val source = FakeCommunityDesk().apply { community = mapOf("profiles" to listOf(profile)); failNext = passwordRequired() }
        val viewModel = CommunityModerationViewModel(repository, source, SavedStateHandle())
        viewModel.ask(profile, CommunityAction.Suspend)
        viewModel.confirm("Messaggi offensivi ripetuti")
        assertEquals(EditorialLockReason.PasswordRequired, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertEquals(listOf("suspend:9:Messaggi offensivi ripetuti", "suspend:9:Messaggi offensivi ripetuti"), source.calls.filter { it.startsWith("suspend") })
        assertTrue(viewModel.list.current.value.items.single().suspended)
    }

    @Test fun postsAndCommentsUseTheirPathAndARestrictionLeavesTheList() {
        val post = DeskCommunityItem(kind = "post", id = 4, abilities = abilities("hide"))
        val restriction = DeskCommunityItem(kind = "restriction", id = 6, abilities = abilities("remove"))
        val source = FakeCommunityDesk().apply { community = mapOf("posts" to listOf(post), "restrictions" to listOf(restriction)) }
        val viewModel = CommunityModerationViewModel(repository, source, SavedStateHandle(mapOf("section" to "posts")))
        viewModel.ask(post, CommunityAction.Hide)
        viewModel.confirm("Contenuto fuori tema")
        assertEquals("hide:posts:4:Contenuto fuori tema", source.calls.last())
        assertTrue(viewModel.list.current.value.items.single().hidden)
        assertEquals("comments", CommunityModerationViewModel.contentPath("comment"))

        viewModel.selectSection("restrictions")
        viewModel.ask(restriction, CommunityAction.RemoveRestriction)
        viewModel.confirm("Serata di nuovo aperta")
        assertEquals("remove:6:Serata di nuovo aperta", source.calls.last())
        assertTrue(viewModel.list.current.value.items.isEmpty())
    }

    @Test fun carpoolParamsDropWhatThePanelIgnores() {
        val query = ListQuery(q = "Valle", tab = CarpoolSection.CASES, filters = mapOf("status" to "open"))
        assertEquals(listOf("page" to "1", "per_page" to "20", "filter[status]" to "open"), CarpoolViewModel.carpoolParams(query, 1))
        assertEquals(listOf("page" to "1", "per_page" to "20", "q" to "Valle", "filter[status]" to "open"), CarpoolViewModel.carpoolParams(query.copy(tab = CarpoolSection.RIDES), 1))
        assertEquals(listOf("page" to "1", "per_page" to "20", "q" to "Valle"), CarpoolViewModel.carpoolParams(query.copy(tab = CarpoolSection.AUDIT), 1))
        assertEquals(listOf("page" to "1", "per_page" to "20"), CarpoolViewModel.carpoolParams(query.copy(tab = CarpoolSection.DELIVERIES), 1))
    }

    @Test fun withoutCarpoolManageOnlyCasesAndAuditAreOpen() {
        val source = FakeCommunityDesk()
        val saved = SavedStateHandle(mapOf("section" to "rides", "status" to "open", "q" to "Valle"))
        val viewModel = CarpoolViewModel(repository, source, saved)
        /* Il banco finto non dà `carpool_manage`: la scheda salvata non vale, si parte dai casi (senza ricerca). */
        assertEquals(listOf(CarpoolSection.CASES, CarpoolSection.AUDIT), viewModel.sections)
        assertEquals("carpool/cases?page=1&per_page=20&filter[status]=open", source.calls.first())
        viewModel.selectSection(CarpoolSection.RIDES)
        assertEquals("carpool/cases?page=1&per_page=20&filter[status]=open", source.calls.last())
    }

    @Test fun changingCarpoolSectionClearsTheStatusAndKeepsTheSearch() = runTest(dispatcher) {
        val staff = EditorialRepository(1, "t", PermissiveDesk(), EditorialLocalLock(clock = { 0L }))
        staff.loadDesk()
        val source = FakeCommunityDesk()
        val saved = SavedStateHandle(mapOf("section" to "rides", "status" to "open", "q" to "Valle"))
        val viewModel = CarpoolViewModel(staff, source, saved)
        assertEquals(CarpoolSection.all, viewModel.sections)
        assertEquals("carpool/rides?page=1&per_page=20&q=Valle&filter[status]=open", source.calls.first())
        viewModel.selectSection(CarpoolSection.CASES)
        assertEquals("", saved.get<String>("status"))
        assertEquals("carpool/cases?page=1&per_page=20", source.calls.last())
        viewModel.applyFilters(mapOf("status" to "resolved"))
        assertEquals("resolved", saved.get<String>("status"))
        assertEquals("carpool/cases?page=1&per_page=20&filter[status]=resolved", source.calls.last())
        viewModel.selectSection(CarpoolSection.AUDIT)
        assertEquals("carpool/audit?page=1&per_page=20&q=Valle", source.calls.last())
    }

    @Test fun carpoolRowActionsReplaceTheRowOrLeaveTheList() {
        val ride = DeskCarpoolItem(kind = "ride", id = 4, status = "open", abilities = abilities("cancel"))
        val delivery = DeskCarpoolItem(kind = "delivery", id = 9, attempts = 8, abilities = abilities("retry"))
        val source = FakeCommunityDesk().apply { carpool = mapOf("cases" to listOf(ride, delivery), "rides" to listOf(ride)) }
        val viewModel = CarpoolViewModel(repository, source, SavedStateHandle())
        viewModel.ask(ride, CarpoolRowAction.CancelRide)
        viewModel.confirm("Conducente segnalato più volte")
        assertEquals("cancel:4:Conducente segnalato più volte", source.calls.last())
        assertEquals("cancelled", viewModel.list.current.value.items.first { it.id == 4L }.status)

        viewModel.ask(delivery, CarpoolRowAction.RetryDelivery)
        viewModel.confirm("Servizio di nuovo raggiungibile")
        assertEquals(listOf(4L), viewModel.list.current.value.items.map { it.id })
    }

    @Test fun aRideChangedByOthersReloadsTheList() {
        val ride = DeskCarpoolItem(kind = "ride", id = 4, status = "open", abilities = abilities("cancel"))
        val source = FakeCommunityDesk().apply { carpool = mapOf("cases" to listOf(ride), "rides" to listOf(ride)); failNext = conflict() }
        val viewModel = CarpoolViewModel(repository, source, SavedStateHandle())
        val before = source.calls.count { it.startsWith("carpool/") }
        viewModel.ask(ride, CarpoolRowAction.CancelRide)
        viewModel.confirm("Conducente segnalato più volte")
        assertEquals(before + 1, source.calls.count { it.startsWith("carpool/") })
        assertNull(viewModel.uiState.value.pending)
        assertEquals(UiText.Res(R.string.ed_carpool_changed), viewModel.uiState.value.message)
    }

    @Test fun theCaseIsManagedWithTheRevisionAndAConflictReloadsIt() {
        val source = FakeCommunityDesk()
        val viewModel = CarpoolCaseViewModel(repository, source, SavedStateHandle(mapOf("id" to 5L)))
        assertTrue(viewModel.uiState.value.case is Loadable.Ready)
        assertEquals(listOf("assign", "note", "resolve"), CarpoolCaseViewModel.operations(closed = false))
        assertEquals(listOf("assign", "note", "reopen"), CarpoolCaseViewModel.operations(closed = true))

        viewModel.ask(CaseDialog.Operation("assign"))
        viewModel.confirm("Esamino la segnalazione")
        assertEquals("manage:5:assign:Esamino la segnalazione:1", source.calls.last())
        assertEquals(2, (viewModel.uiState.value.case as Loadable.Ready).value.revision)
        assertTrue(viewModel.uiState.value.changed)

        source.failNext = conflict()
        viewModel.ask(CaseDialog.Operation("resolve"))
        viewModel.confirm("Chiudo il caso")
        assertEquals("manage:5:resolve:Chiudo il caso:2", source.calls[source.calls.size - 2])
        assertEquals("case:5", source.calls.last())
        assertNull(viewModel.uiState.value.dialog)
        assertEquals(UiText.Res(R.string.ed_carpool_changed), viewModel.uiState.value.message)
    }

    @Test fun theReplyGoesOnlyToAPersonOfTheCase() {
        val source = FakeCommunityDesk()
        val viewModel = CarpoolCaseViewModel(repository, source, SavedStateHandle(mapOf("id" to 5L)))
        viewModel.ask(CaseDialog.Reply)
        viewModel.reply(99, "Puoi raccontarmi meglio?")
        viewModel.reply(8, "ok")
        assertFalse(source.calls.any { it.startsWith("reply") })
        viewModel.reply(8, " Puoi raccontarmi meglio? ")
        assertEquals("reply:5:8:Puoi raccontarmi meglio?:1", source.calls.last())
        assertEquals("waiting", (viewModel.uiState.value.case as Loadable.Ready).value.status)
    }

    @Test fun holdAndEvidenceAskThePasswordAndTheEvidenceStaysInMemory() {
        val source = FakeCommunityDesk().apply { failNext = passwordRequired() }
        val viewModel = CarpoolCaseViewModel(repository, source, SavedStateHandle(mapOf("id" to 5L)))
        viewModel.ask(CaseDialog.Retention("hold"))
        viewModel.confirm("Possibile denuncia in corso")
        assertEquals(EditorialLockReason.PasswordRequired, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertEquals(2, source.calls.count { it.startsWith("hold:5:hold:") })
        assertNotNull((viewModel.uiState.value.case as Loadable.Ready).value.holdUntil)

        viewModel.ask(CaseDialog.Evidence)
        viewModel.confirm("Verifica della segnalazione")
        assertEquals("evidence:5:Verifica della segnalazione", source.calls.last())
        assertEquals("abc", viewModel.uiState.value.evidence?.sha256)
        assertNull(viewModel.uiState.value.dialog)
        viewModel.closeEvidence()
        assertNull(viewModel.uiState.value.evidence)
    }
}
