package it.fabiodalez.incitta.ui.editorial.users

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialPerson
import it.fabiodalez.incitta.data.editorial.EditorialUsersSource
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.NewUserBody
import it.fabiodalez.incitta.data.editorial.UserChange
import it.fabiodalez.incitta.data.editorial.UserChoice
import it.fabiodalez.incitta.data.editorial.UserCredentialsBody
import it.fabiodalez.incitta.data.editorial.UserFormValues
import it.fabiodalez.incitta.data.editorial.UserUpdateBody
import it.fabiodalez.incitta.data.editorial.UsersOptions
import it.fabiodalez.incitta.data.editorial.WhatsappAttempt
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R5 con un server finto: elenco con schede e filtri, azioni di riga, scheda, ruoli, credenziali proprie, creazione. */
@OptIn(ExperimentalCoroutinesApi::class)
class UsersViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private fun person(id: Long, vararg can: String, roles: List<String> = listOf("user"), assignable: List<String> = listOf("user", "venue_owner", "venue_editor", "moderator")) = EditorialPerson(
        id = id, name = "Persona $id", email = "p$id@esempio.it", abilities = abilities(*can),
        form = UserFormValues(cityId = null, name = "Persona $id", email = "p$id@esempio.it", timezone = "Europe/Rome", locale = "it", roles = roles),
        assignableRoles = assignable,
    )

    private inner class FakeUsers : EditorialUsersSource {
        val calls = mutableListOf<String>()
        var rows = listOf(person(1, "suspend", "destroy", "whatsapp_history"), person(2, "restore"))
        var failure: Exception? = null
        var lastUpdate: UserUpdateBody? = null
        var lastCreate: NewUserBody? = null
        var lastRoles: List<String>? = null
        var lastCredentials: UserCredentialsBody? = null
        override suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialPerson> {
            calls += "list:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))
        }
        override suspend fun options() = UsersOptions(
            roles = listOf(UserChoice("user", "Utente"), UserChoice("moderator", "Moderatore"), UserChoice("admin", "Amministratore")),
            assignableRoles = listOf("user", "venue_owner", "venue_editor", "moderator"),
            timezones = listOf("Europe/Rome", "Europe/London"),
        )
        override suspend fun show(id: Long): EditorialPerson { calls += "show:$id"; failure?.let { throw it }; return person(id, "update", "roles", "credentials", "destroy") }
        override suspend fun create(body: NewUserBody): UserChange { calls += "create"; lastCreate = body; failure?.let { throw it }; return UserChange(person(9), "Utente creato.") }
        override suspend fun update(id: Long, body: UserUpdateBody): UserChange { calls += "update:$id"; lastUpdate = body; failure?.let { throw it }; return UserChange(person(id, "update"), "Modifiche salvate.") }
        override suspend fun credentials(id: Long, body: UserCredentialsBody): Pair<Boolean, String?> { calls += "credentials:$id"; lastCredentials = body; return true to "Credenziali cambiate" }
        override suspend fun roles(id: Long, roles: List<String>): UserChange { calls += "roles:$id"; lastRoles = roles; return UserChange(person(id, "roles", roles = roles), "Ruoli aggiornati.") }
        override suspend fun destroy(id: Long): UserChange { calls += "destroy:$id"; return UserChange(person(id, "restore").copy(deletedAt = "2026-11-26T10:00:00+01:00"), "Utente eliminato.") }
        override suspend fun restore(id: Long): UserChange { calls += "restore:$id"; return UserChange(person(id, "destroy"), "Utente ripristinato.") }
        override suspend fun suspend(id: Long): UserChange { calls += "suspend:$id"; failure?.let { throw it }; return UserChange(person(id, "unsuspend").copy(communitySuspendedAt = "2026-11-26T10:00:00+01:00"), "Sospeso dalla community.") }
        override suspend fun unsuspend(id: Long): UserChange { calls += "unsuspend:$id"; return UserChange(person(id, "suspend"), "Riattivato.") }
        override suspend fun renameHandle(id: Long, handle: String, reason: String): UserChange { calls += "handle:$id:$handle:$reason"; return UserChange(person(id).copy(communityHandle = handle), "Nome utente cambiato.") }
        override suspend fun revokeWhatsapp(id: Long): UserChange { calls += "whatsapp:$id"; return UserChange(person(id), "Revocata.") }
        override suspend fun whatsappHistory(id: Long): List<WhatsappAttempt> { calls += "history:$id"; return listOf(WhatsappAttempt("a", phoneMasked = "••••4567", status = "sent", statusLabel = "Inviato", attempts = 1)) }
    }

    private fun repository(userId: Long = 1) = EditorialRepository(userId, "t", FakeDesk(unlocked = true), EditorialLocalLock(clock = { 0L }))

    @Test fun theListKeepsTabsAndFiltersAndSendsThem() {
        val api = FakeUsers()
        val saved = SavedStateHandle()
        val vm = UsersListViewModel(repository(), api, saved)
        assertEquals(2, vm.rows.value.items.size)
        vm.selectTab("staff")
        vm.applyFilters(mapOf("role" to "moderator", "trashed" to "with", "vuoto" to ""))
        assertTrue(api.calls.last().contains("tab=staff"))
        assertTrue(api.calls.last().contains("filter[role]=moderator"))
        assertTrue(api.calls.last().contains("filter[trashed]=with"))
        assertFalse(api.calls.last().contains("vuoto"))
        /* Dopo la rotazione i criteri tornano dallo stato salvato. */
        val again = UsersListViewModel(repository(), api, saved)
        assertEquals("staff", again.rows.value.query.tab)
        assertEquals(mapOf("role" to "moderator", "trashed" to "with"), again.rows.value.query.filters)
        assertTrue(again.options.value is Loadable.Ready)
    }

    @Test fun theSummaryOpensTheListOnThePendingTabAndTheMenuOnAll() {
        val api = FakeUsers()
        /* «Solo in attesa» del riepilogo: `UsersRoute(tab = "pending")`. */
        val saved = SavedStateHandle(mapOf("tab" to "pending", "filter" to ""))
        val vm = UsersListViewModel(repository(), api, saved)
        assertEquals("pending", vm.rows.value.query.tab)
        assertTrue(api.calls.last(), api.calls.last().startsWith("list:") && api.calls.last().contains("tab=pending"))
        vm.selectTab("staff")
        /* Riaperta dal sistema: la scheda scelta, non quella del riquadro. */
        assertEquals("staff", UsersListViewModel(repository(), api, saved).rows.value.query.tab)
        /* «Tutti gli utenti» e la voce del menu: `UsersRoute()`, nessuna scheda. */
        val all = UsersListViewModel(repository(), api, SavedStateHandle(mapOf("tab" to "", "filter" to "")))
        assertNull(all.rows.value.query.tab)
        assertFalse(api.calls.last().contains("tab="))
    }

    @Test fun onlyAllowedActionsRunAndTheRowFollowsTheResult() {
        val api = FakeUsers()
        val vm = UsersListViewModel(repository(), api, SavedStateHandle())
        val first = vm.rows.value.items.first()
        assertEquals(listOf(UserAction.Suspend, UserAction.WhatsappHistory, UserAction.Destroy), allowedActions(first))
        /* Un'azione che la riga non permette non apre niente. */
        vm.actions.request(UserAction.WhatsappRevoke, first)
        assertNull(vm.actions.current.value.pending)
        vm.actions.request(UserAction.Suspend, first)
        assertEquals(UserAction.Suspend, vm.actions.current.value.pending?.action)
        vm.actions.confirm()
        assertTrue("suspend:1" in api.calls)
        assertNull(vm.actions.current.value.pending)
        assertEquals(UiText.Raw("Sospeso dalla community."), vm.actions.current.value.message)
        assertTrue(vm.rows.value.items.first { it.id == 1L }.suspended)
        /* Eliminata senza il filtro dei cestinati: esce dall'elenco. */
        vm.actions.request(UserAction.Destroy, first)
        vm.actions.confirm()
        assertNull(vm.rows.value.items.firstOrNull { it.id == 1L })
    }

    @Test fun aFailedActionKeepsTheDialogOpenWithTheServerMessage() {
        val api = FakeUsers().apply { failure = ApiException(403, ApiProblem(message = "Non puoi sospendere chi ha il tuo stesso ruolo.")) }
        val vm = UsersListViewModel(repository(), api, SavedStateHandle())
        val first = vm.rows.value.items.first()
        vm.actions.request(UserAction.Suspend, first)
        vm.actions.confirm()
        assertEquals(UserAction.Suspend, vm.actions.current.value.pending?.action)
        assertEquals("Non puoi sospendere chi ha il tuo stesso ruolo.", vm.actions.current.value.error)
        assertFalse(vm.actions.current.value.busy)
    }

    @Test fun theWhatsappHistoryOpensWithoutConfirmation() {
        val api = FakeUsers()
        val vm = UsersListViewModel(repository(), api, SavedStateHandle())
        vm.actions.request(UserAction.WhatsappHistory, vm.rows.value.items.first())
        assertNull(vm.actions.current.value.pending)
        val attempts = (vm.actions.current.value.history?.attempts as Loadable.Ready).value
        assertEquals("••••4567", attempts.single().phoneMasked)
        vm.actions.closeHistory()
        assertNull(vm.actions.current.value.history)
    }

    @Test fun theDetailSavesTheFormAndOnlyTheRolesTheAppMayGive() {
        val api = FakeUsers()
        val vm = UserDetailViewModel(repository(userId = 1), api, SavedStateHandle(), userId = 5)
        assertTrue(vm.uiState.value.user is Loadable.Ready)
        assertFalse(vm.isSelf)
        vm.editDraft { it.copy(name = "Nome nuovo", timezone = "Europe/London", locale = "en") }
        vm.save()
        assertEquals(UserUpdateBody(null, "Nome nuovo", "Europe/London", "en"), api.lastUpdate)
        assertEquals(UiText.Raw("Modifiche salvate."), vm.uiState.value.message)
        /* Un fuso che non è nell'elenco si ferma sul telefono. */
        vm.editDraft { it.copy(timezone = "Marte/Base") }
        vm.save()
        assertEquals(1, api.calls.count { it.startsWith("update") })
        assertNotNull(vm.uiState.value.errors.field("timezone"))
        vm.toggleRole("moderator")
        vm.toggleRole("admin")
        vm.saveRoles()
        assertEquals(listOf("moderator", "user"), api.lastRoles)
        /* Le credenziali di un'altra persona non partono. */
        vm.saveCredentials()
        assertFalse(api.calls.any { it.startsWith("credentials") })
    }

    @Test fun changingOwnCredentialsSignsOut() {
        val api = FakeUsers()
        val vm = UserDetailViewModel(repository(userId = 5), api, SavedStateHandle(), userId = 5)
        assertTrue(vm.isSelf)
        vm.editCredentials { it.copy(email = "nuova@esempio.it", password = "corta") }
        vm.saveCredentials()
        assertNotNull(vm.uiState.value.credentialErrors.field("password"))
        assertNull(api.lastCredentials)
        vm.editCredentials { it.copy(password = "lunga-abbastanza") }
        vm.saveCredentials()
        assertEquals(UserCredentialsBody("nuova@esempio.it", "lunga-abbastanza"), api.lastCredentials)
        assertTrue(vm.uiState.value.signedOut)
        assertEquals("", vm.uiState.value.credentials.password)
    }

    @Test fun creatingChecksTheFieldsFirstAndPutsServerErrorsUnderThem() {
        val api = FakeUsers()
        val vm = UserCreateViewModel(repository(), api, SavedStateHandle())
        vm.create()
        assertNull(api.lastCreate)
        assertNotNull(vm.uiState.value.errors.field("name"))
        assertNotNull(vm.uiState.value.errors.field("email"))
        assertNotNull(vm.uiState.value.errors.field("password"))
        vm.edit { it.copy(name = "Nuova", email = "nuova@esempio.it", password = "password-lunga") }
        vm.toggleRole("moderator")
        vm.toggleRole("admin") // non si dà dall'app: non parte
        api.failure = ApiException(422, ApiProblem(message = "Dati non validi", fields = mapOf("email" to listOf("L'email è già in uso."))))
        vm.create()
        assertEquals("L'email è già in uso.", vm.uiState.value.errors.field("email"))
        assertEquals(listOf("moderator"), api.lastCreate?.roles)
        api.failure = null
        vm.create()
        assertEquals(9L, vm.uiState.value.createdId)
    }

    @Test fun pureHelpers() {
        assertEquals(listOf("Europe/Rome"), filterTimezones(listOf("Europe/Rome", "America/New_York"), " rome "))
        assertEquals(listOf("America/New_York"), filterTimezones(listOf("Europe/Rome", "America/New_York"), "new york"))
        assertEquals(RoleChoices(listOf("user", "moderator"), listOf("admin")), RoleChoices.of(listOf("admin", "moderator"), listOf("user", "moderator")))
        assertEquals(mapOf("password" to R.string.editorial_users_password_short), UserDraft(name = "A", email = "a@b.it", password = "1234567").problems(DraftMode.Create))
        assertTrue(UserDraft(name = "", email = "").problems(DraftMode.Credentials).containsKey("email"))
        assertTrue(UserDraft(name = "A").problems(DraftMode.Update).isEmpty())
        assertEquals("Testo", localFieldMessage("res:${R.string.editorial_users_name_required}") { if (it == R.string.editorial_users_name_required) "Testo" else "?" })
        assertEquals("dal server", localFieldMessage("dal server") { "?" })
    }
}
