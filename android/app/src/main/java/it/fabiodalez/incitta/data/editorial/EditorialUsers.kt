package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R5 — gli account nella redazione (R-US-01, 02, 04…06, 08…12): elenco,
 * scheda, creazione, modifica, ruoli, credenziali proprie, eliminazione e
 * ripristino, sospensione, nome utente, verifica WhatsApp. Le regole le
 * decide il server con le classi del pannello; qui solo le forme.
 */

/** Una scelta `{value, label}` delle opzioni del server. */
@Serializable
internal data class UserChoice(val value: String = "", val label: String = "") {
    fun toOption() = OptionChoice(value, label)
}

@Serializable
internal data class UserRegistration(val value: String = "", val label: String = "", val description: String = "")

@Serializable
internal data class UserMembership(
    @SerialName("venue_id") val venueId: Long = 0,
    @SerialName("venue_name") val venueName: String = "",
    @SerialName("city_name") val cityName: String? = null,
    val role: String = "",
    @SerialName("role_label") val roleLabel: String = "",
)

@Serializable
internal data class UserCity(val id: Long = 0, val name: String = "")

/** I campi del modulo, come il server li ha salvati. */
@Serializable
internal data class UserFormValues(
    @SerialName("city_id") val cityId: Long? = null,
    val name: String = "",
    val email: String = "",
    val timezone: String = "Europe/Rome",
    val locale: String = "it",
    val roles: List<String> = emptyList(),
)

/**
 * Una persona: la riga dell'elenco e, quando arriva da `GET users/{id}` o da
 * un'azione, anche la scheda (`form`, `whatsapp_masked`, `assignable_roles`).
 */
@Serializable
internal data class EditorialPerson(
    val id: Long = 0,
    /* Il nome, o l'indirizzo se la persona non ne ha dato uno (`has_name` falso). */
    val name: String = "",
    @SerialName("has_name") val hasName: Boolean = true,
    val email: String = "",
    val registration: UserRegistration = UserRegistration(),
    val roles: List<UserChoice> = emptyList(),
    val memberships: List<UserMembership> = emptyList(),
    val city: UserCity? = null,
    @SerialName("venue_cities") val venueCities: List<String> = emptyList(),
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("email_verified_at") val emailVerifiedAt: String? = null,
    @SerialName("whatsapp_verified_at") val whatsappVerifiedAt: String? = null,
    @SerialName("community_suspended_at") val communitySuspendedAt: String? = null,
    @SerialName("community_handle") val communityHandle: String? = null,
    @SerialName("last_active_at") val lastActiveAt: String? = null,
    @SerialName("deleted_at") val deletedAt: String? = null,
    val abilities: JsonElement? = null,
    val form: UserFormValues? = null,
    @SerialName("whatsapp_masked") val whatsappMasked: String? = null,
    @SerialName("assignable_roles") val assignableRoles: List<String> = emptyList(),
) {
    val can: Map<String, Boolean> get() = abilitiesOf(abilities)
    fun allows(ability: String): Boolean = can[ability] == true
    val trashed: Boolean get() = deletedAt != null
    val suspended: Boolean get() = communitySuspendedAt != null
}

@Serializable
internal data class UserMessageMeta(val message: String? = null, @SerialName("signed_out") val signedOut: Boolean = false)

@Serializable
internal data class UserEnvelope(val data: EditorialPerson = EditorialPerson(), val meta: UserMessageMeta = UserMessageMeta())

/** Il risultato di un'azione: la persona com'è adesso e il messaggio del server. */
internal data class UserChange(val user: EditorialPerson, val message: String?)

@Serializable
internal data class UserCredentialsResult(val changes: List<String> = emptyList())

@Serializable
internal data class UserCredentialsEnvelope(val data: UserCredentialsResult = UserCredentialsResult(), val meta: UserMessageMeta = UserMessageMeta())

/** Le scelte dei filtri e dei moduli: `GET editorial/users/options`. */
@Serializable
internal data class UsersOptions(
    @SerialName("registration_states") val registrationStates: List<UserChoice> = emptyList(),
    val roles: List<UserChoice> = emptyList(),
    @SerialName("assignable_roles") val assignableRoles: List<String> = emptyList(),
    val cities: List<UserChoice> = emptyList(),
    val venues: List<UserChoice> = emptyList(),
    val timezones: List<String> = emptyList(),
    val locales: List<String> = listOf("it", "en", "de"),
    @SerialName("default_timezone") val defaultTimezone: String = "Europe/Rome",
    @SerialName("default_locale") val defaultLocale: String = "it",
) {
    fun roleLabel(value: String): String = roles.firstOrNull { it.value == value }?.label ?: value
}

/** Un tentativo di verifica WhatsApp: mai il numero intero, solo le ultime quattro cifre. */
@Serializable
internal data class WhatsappAttempt(
    val id: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("phone_masked") val phoneMasked: String = "",
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    val attempts: Int = 0,
    @SerialName("expires_at") val expiresAt: String? = null,
)

@Serializable
internal data class NewUserBody(
    val name: String,
    val email: String,
    val password: String,
    @SerialName("city_id") val cityId: Long? = null,
    val timezone: String,
    val locale: String,
    val roles: List<String>,
)

@Serializable
internal data class UserUpdateBody(
    @SerialName("city_id") val cityId: Long?,
    val name: String,
    val timezone: String,
    val locale: String,
)

@Serializable
internal data class UserCredentialsBody(val email: String, val password: String? = null)

@Serializable
internal data class UserRolesBody(val roles: List<String>)

@Serializable
internal data class UserHandleBody(val handle: String, val reason: String)

@Serializable
internal class UsersEmptyBody

/** Le chiamate dell'area «Utenti»; un'interfaccia per provare i ViewModel con un server finto. */
internal interface EditorialUsersSource {
    suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialPerson>
    suspend fun options(): UsersOptions
    suspend fun show(id: Long): EditorialPerson
    suspend fun create(body: NewUserBody): UserChange
    suspend fun update(id: Long, body: UserUpdateBody): UserChange
    /** Le proprie credenziali: vero se il server ha chiuso tutte le sessioni dell'app. */
    suspend fun credentials(id: Long, body: UserCredentialsBody): Pair<Boolean, String?>
    suspend fun roles(id: Long, roles: List<String>): UserChange
    suspend fun destroy(id: Long): UserChange
    suspend fun restore(id: Long): UserChange
    suspend fun suspend(id: Long): UserChange
    suspend fun unsuspend(id: Long): UserChange
    suspend fun renameHandle(id: Long, handle: String, reason: String): UserChange
    suspend fun revokeWhatsapp(id: Long): UserChange
    suspend fun whatsappHistory(id: Long): List<WhatsappAttempt>
}

/** `E/users…` con il client della sessione; la città di lavoro va su ogni percorso, come vuole il contratto. */
internal class EditorialUsersApi(
    private val api: ApiClient,
    private val token: String,
    private val city: () -> String? = { null },
) : EditorialUsersSource {
    private fun path(path: String, params: List<Pair<String, String>> = emptyList()) = editorialPath(path, city(), params)

    override suspend fun list(params: List<Pair<String, String>>): EditorialPage<EditorialPerson> =
        api.get(path("users", params), token)

    override suspend fun options(): UsersOptions = api.get<ApiEnvelope<UsersOptions>>(path("users/options"), token).data
    override suspend fun show(id: Long): EditorialPerson = api.get<UserEnvelope>(path("users/$id"), token).data
    override suspend fun create(body: NewUserBody): UserChange = api.post<UserEnvelope, NewUserBody>(path("users"), body, token).change()
    override suspend fun update(id: Long, body: UserUpdateBody): UserChange =
        api.execute<UserEnvelope>(path("users/$id"), "PATCH", api.json.encodeToString(UserUpdateBody.serializer(), body), token).change()

    override suspend fun credentials(id: Long, body: UserCredentialsBody): Pair<Boolean, String?> {
        val result = api.execute<UserCredentialsEnvelope>(path("users/$id/credentials"), "PUT", api.json.encodeToString(UserCredentialsBody.serializer(), body), token)
        return result.meta.signedOut to result.meta.message
    }

    override suspend fun roles(id: Long, roles: List<String>): UserChange =
        api.execute<UserEnvelope>(path("users/$id/roles"), "PUT", api.json.encodeToString(UserRolesBody.serializer(), UserRolesBody(roles)), token).change()

    override suspend fun destroy(id: Long): UserChange = api.delete<UserEnvelope>(path("users/$id"), token).change()
    override suspend fun restore(id: Long): UserChange = api.post<UserEnvelope, UsersEmptyBody>(path("users/$id/restore"), UsersEmptyBody(), token).change()
    override suspend fun suspend(id: Long): UserChange = api.post<UserEnvelope, UsersEmptyBody>(path("users/$id/community/suspend"), UsersEmptyBody(), token).change()
    override suspend fun unsuspend(id: Long): UserChange = api.post<UserEnvelope, UsersEmptyBody>(path("users/$id/community/unsuspend"), UsersEmptyBody(), token).change()
    override suspend fun renameHandle(id: Long, handle: String, reason: String): UserChange =
        api.post<UserEnvelope, UserHandleBody>(path("users/$id/community/handle"), UserHandleBody(handle, reason), token).change()

    override suspend fun revokeWhatsapp(id: Long): UserChange = api.delete<UserEnvelope>(path("users/$id/whatsapp"), token).change()
    override suspend fun whatsappHistory(id: Long): List<WhatsappAttempt> =
        api.get<ApiEnvelope<List<WhatsappAttempt>>>(path("users/$id/whatsapp/history"), token).data

    private fun UserEnvelope.change() = UserChange(data, meta.message)
}
