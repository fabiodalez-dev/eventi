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
import it.fabiodalez.incitta.data.editorial.EditorialUnlockState
import it.fabiodalez.incitta.data.editorial.EditorialPerson
import it.fabiodalez.incitta.data.editorial.EditorialUsersSource
import it.fabiodalez.incitta.data.editorial.NewUserBody
import it.fabiodalez.incitta.data.editorial.UserChange
import it.fabiodalez.incitta.data.editorial.UserChoice
import it.fabiodalez.incitta.data.editorial.UserCredentialsBody
import it.fabiodalez.incitta.data.editorial.UserFormValues
import it.fabiodalez.incitta.data.editorial.UserRegistration
import it.fabiodalez.incitta.data.editorial.UserUpdateBody
import it.fabiodalez.incitta.data.editorial.UsersOptions
import it.fabiodalez.incitta.data.editorial.WhatsappAttempt
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.editorial.users.UserCreateScreen
import it.fabiodalez.incitta.ui.editorial.users.UserCreateViewModel
import it.fabiodalez.incitta.ui.editorial.users.UserDetailScreen
import it.fabiodalez.incitta.ui.editorial.users.UserDetailViewModel
import it.fabiodalez.incitta.ui.editorial.users.UsersListScreen
import it.fabiodalez.incitta.ui.editorial.users.UsersListViewModel
import java.time.ZoneId
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R5 sulle schermate vere, con un server finto: l'elenco con le schede del
 * pannello e il menu della riga (solo le azioni permesse), la sospensione
 * con conferma, la scheda con i ruoli che si danno dall'app e quelli solo
 * del sito, «Nuovo utente» con gli errori sotto i campi. I testi si leggono
 * dalle risorse.
 */
class EditorialUsersUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)
    private val zone = ZoneId.of("Europe/Rome")

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun repository(userId: Long = 1) = EditorialRepository(userId, "t", UsersUiDesk(), EditorialLocalLock(clock = { 0L }))

    @Test fun theListShowsThePanelTabsAndOnlyTheAllowedActions() {
        val api = UsersUiFake()
        val vm = compose.runOnIdle { UsersListViewModel(repository(), api, SavedStateHandle()) }
        val opened = CopyOnWriteArrayList<Long>()
        show { UsersListScreen(vm, PaddingValues(), canCreate = true, zone = zone, onBack = {}, onOpen = { opened += it.id }, onCreate = {}) }
        compose.waitUntil(5000) { compose.onAllNodesWithText("Marta Rossi").fetchSemanticsNodes().isNotEmpty() }
        // Le schede stanno in una fila che scorre: quelle fuori schermo non sono ancora disegnate.
        val staffTab = text(R.string.editorial_list_tab_count, text(R.string.editorial_users_tab_staff), 1)
        compose.onAllNodes(hasScrollToNodeAction()).fetchSemanticsNodes().indices.forEach { index ->
            runCatching { compose.onAllNodes(hasScrollToNodeAction())[index].performScrollToNode(hasText(staffTab)) }
        }
        compose.onNodeWithText(staffTab).assertExists()
        compose.onNodeWithText(text(R.string.editorial_users_new)).assertExists()

        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Marta Rossi")).performClick()
        compose.onNodeWithText(text(R.string.editorial_users_action_suspend)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_users_action_whatsapp_revoke)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_users_action_suspend)).performClick()
        compose.onNodeWithText(text(R.string.editorial_users_confirm_suspend_title, "Marta Rossi")).assertExists()
        compose.onAllNodesWithText(text(R.string.editorial_users_action_suspend)).onLast().performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("Sospeso dalla community.").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_users_badge_suspended)).assertExists()
        compose.runOnIdle { assertTrue("suspend:1" in api.calls) }

        compose.onNodeWithText("Marta Rossi").performClick()
        compose.runOnIdle { assertEquals(listOf(1L), opened) }
    }

    @Test fun theDetailOffersOnlyTheRolesTheAppMayGive() {
        val api = UsersUiFake()
        val vm = compose.runOnIdle { UserDetailViewModel(repository(), api, SavedStateHandle(), userId = 3) }
        show { UserDetailScreen(vm, PaddingValues(), zone, onBack = {}, onSignedOut = {}, onOpenWeb = {}) }
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_users_roles_save)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_users_roles_web, "Amministratore")).performScrollTo().assertExists()
        compose.onNodeWithText("Moderatore").performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_users_roles_save)).performScrollTo().performClick()
        compose.waitUntil(5000) { api.calls.any { it.startsWith("roles:3") } }
        compose.runOnIdle { assertEquals(listOf("moderator"), api.lastRoles) }
        /* La scheda di un'altra persona non ha email e password. */
        compose.onNodeWithText(text(R.string.editorial_users_section_credentials)).assertDoesNotExist()
        compose.onNodeWithText(text(R.string.editorial_users_web_only)).performScrollTo().assertExists()
    }

    @Test fun creatingShowsTheServerErrorUnderTheField() {
        val api = UsersUiFake()
        val vm = compose.runOnIdle { UserCreateViewModel(repository(), api, SavedStateHandle()) }
        val created = CopyOnWriteArrayList<Long>()
        show { UserCreateScreen(vm, PaddingValues(), onBack = {}, onCreated = { created += it }) }
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_users_create)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_users_create)).performScrollTo().performClick()
        compose.onNodeWithText(text(R.string.editorial_users_name_required)).performScrollTo().assertExists()
        compose.onNodeWithText(text(R.string.editorial_users_field_name)).performTextInput("Nuova Persona")
        compose.onNodeWithText(text(R.string.editorial_users_field_email)).performTextInput("presa@esempio.it")
        compose.onNodeWithText(text(R.string.editorial_users_field_password)).performTextInput("password-lunga")
        compose.onNodeWithText(text(R.string.editorial_users_create)).performScrollTo().performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("L’email è già in uso.").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_users_field_email)).performTextReplacement("libera@esempio.it")
        compose.onNodeWithText(text(R.string.editorial_users_create)).performScrollTo().performClick()
        compose.waitUntil(5000) { created.isNotEmpty() }
        compose.runOnIdle { assertEquals(listOf(9L), created) }
    }
}

private fun can(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

private fun uiPerson(id: Long, name: String, vararg abilities: String, roles: List<String> = listOf("user")) = EditorialPerson(
    id = id, name = name, email = "p$id@esempio.it", registration = UserRegistration("confirmed", "Confermato"),
    roles = roles.map { UserChoice(it, if (it == "admin") "Amministratore" else "Utente") }, abilities = can(*abilities),
    form = UserFormValues(name = name, email = "p$id@esempio.it", roles = roles),
    assignableRoles = listOf("user", "venue_owner", "venue_editor", "moderator"),
)

private class UsersUiDesk : EditorialDeskSource {
    override suspend fun enabled() = true
    override suspend fun desk(city: String?) = EditorialDesk(role = "admin", city = EditorialCity(1, "padova", "Padova"), unlock = EditorialUnlockState(unlocked = true))
    override suspend fun options() = ManagementOptions()
    override suspend fun unlock(password: String) = EditorialUnlockState(unlocked = true)
}

private class UsersUiFake : EditorialUsersSource {
    val calls = CopyOnWriteArrayList<String>()
    @Volatile var lastRoles: List<String>? = null
    override suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialPerson> {
        calls += "list"
        return EditorialPage(
            listOf(uiPerson(1, "Marta Rossi", "suspend", "whatsapp_history"), uiPerson(2, "Luca Bianchi")),
            PageMeta(currentPage = 1, lastPage = 1, total = 2, tabs = buildJsonObject { put("all", JsonPrimitive(2)); put("staff", JsonPrimitive(1)) }),
        )
    }
    override suspend fun options() = UsersOptions(
        roles = listOf(UserChoice("user", "Utente"), UserChoice("venue_owner", "Referente"), UserChoice("venue_editor", "Collaboratore"), UserChoice("moderator", "Moderatore"), UserChoice("admin", "Amministratore")),
        assignableRoles = listOf("user", "venue_owner", "venue_editor", "moderator"),
        timezones = listOf("Europe/Rome", "Europe/London"),
    )
    override suspend fun show(id: Long) = uiPerson(id, "Giulia Verdi", "update", "roles", roles = listOf("admin"))
    override suspend fun create(body: NewUserBody): UserChange {
        calls += "create:${body.email}"
        if (body.email == "presa@esempio.it") throw it.fabiodalez.incitta.data.ApiException(422, it.fabiodalez.incitta.data.ApiProblem(message = "Dati non validi", fields = mapOf("email" to listOf("L’email è già in uso."))))
        return UserChange(uiPerson(9, body.name), "Utente creato.")
    }
    override suspend fun update(id: Long, body: UserUpdateBody) = UserChange(uiPerson(id, body.name, "update"), "Modifiche salvate.")
    override suspend fun credentials(id: Long, body: UserCredentialsBody) = true to "Credenziali cambiate"
    override suspend fun roles(id: Long, roles: List<String>): UserChange {
        calls += "roles:$id"
        lastRoles = roles
        return UserChange(uiPerson(id, "Giulia Verdi", "update", "roles", roles = listOf("admin") + roles), "Ruoli aggiornati.")
    }
    override suspend fun destroy(id: Long) = UserChange(uiPerson(id, "X"), null)
    override suspend fun restore(id: Long) = UserChange(uiPerson(id, "X"), null)
    override suspend fun suspend(id: Long): UserChange {
        calls += "suspend:$id"
        return UserChange(uiPerson(id, "Marta Rossi", "unsuspend").copy(communitySuspendedAt = "2026-11-26T10:00:00+01:00"), "Sospeso dalla community.")
    }
    override suspend fun unsuspend(id: Long) = UserChange(uiPerson(id, "Marta Rossi", "suspend"), null)
    override suspend fun renameHandle(id: Long, handle: String, reason: String) = UserChange(uiPerson(id, "X"), null)
    override suspend fun revokeWhatsapp(id: Long) = UserChange(uiPerson(id, "X"), null)
    override suspend fun whatsappHistory(id: Long) = emptyList<WhatsappAttempt>()
}
