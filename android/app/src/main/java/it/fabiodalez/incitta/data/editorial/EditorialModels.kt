package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.data.management.ManagementPermissionsSerializer
import it.fabiodalez.incitta.data.management.countOf
import it.fabiodalez.incitta.data.management.truthy
import java.time.ZoneId
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/*
 * Il banco della redazione: chi lavora, con quale ruolo, su quale città, che
 * cosa può fare e a che punto è lo sblocco. Ogni campo ha un valore
 * predefinito e i pezzi di forma incerta restano tolleranti, come per la
 * gestione: un server diverso non deve mai aprire più di quanto dice.
 */

/** Risposta di `GET /v1/editorial/desk`. */
@Serializable
internal data class EditorialDesk(
    val user: EditorialUser = EditorialUser(),
    /* `moderator`, `admin` o `super_admin`: il ruolo più alto della persona. */
    val role: String = "",
    /* La città di lavoro: quella chiesta con `?city=`, altrimenti la prima attiva. */
    val city: EditorialCity? = null,
    /* Tutte le città, anche quelle non ancora attive. */
    val cities: List<EditorialCity> = emptyList(),
    @Serializable(with = ManagementPermissionsSerializer::class) val permissions: Map<String, Boolean> = emptyMap(),
    /* I contatori del menu: un numero, oppure `null` dove la voce non si vede. */
    val badges: JsonElement? = null,
    val unlock: EditorialUnlockState = EditorialUnlockState(),
) {
    val can: EditorialPermissions get() = EditorialPermissions(permissions)
    val isModerator: Boolean get() = role == ROLE_MODERATOR
    val isAdmin: Boolean get() = role == ROLE_ADMIN || role == ROLE_SUPER_ADMIN
    val isSuperAdmin: Boolean get() = role == ROLE_SUPER_ADMIN

    /** Il numero di una voce del menu; `null` se il server non lo manda o la voce non si vede. */
    fun badge(key: String): Int? {
        val value = (badges as? JsonObject)?.get(key) ?: return null
        if (value is JsonNull) return null
        return countOf(value)
    }

    companion object {
        const val ROLE_MODERATOR = "moderator"
        const val ROLE_ADMIN = "admin"
        const val ROLE_SUPER_ADMIN = "super_admin"
    }
}

@Serializable
internal data class EditorialUser(val id: Long = 0, val name: String = "")

@Serializable
internal data class EditorialCity(
    val id: Long = 0,
    val slug: String = "",
    val name: String = "",
    val timezone: String = "Europe/Rome",
    @SerialName("is_active") val isActive: Boolean = false,
) {
    /* Date e ore della redazione si leggono nel fuso della città, mai in quello del telefono. */
    val zone: ZoneId get() = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.of("Europe/Rome"))
}

/** Lo sblocco del token: vale 12 ore per l'area, 15 minuti per le azioni pericolose. */
@Serializable
internal data class EditorialUnlockState(
    val unlocked: Boolean = false,
    @SerialName("unlocked_until") val unlockedUntil: String? = null,
    @SerialName("password_confirmed_until") val passwordConfirmedUntil: String? = null,
    @SerialName("unlock_hours") val unlockHours: Int = 12,
    @SerialName("password_minutes") val passwordMinutes: Int = 15,
    /* Dopo quanti minuti in secondo piano o senza tocchi l'app blocca l'area. */
    @SerialName("lock_after_minutes") val lockAfterMinutes: Int = 15,
)

/**
 * Le chiavi di `permissions`, una per azione che l'app mostra o nasconde.
 * Una chiave assente vale «no». Il server ricontrolla comunque ogni azione.
 */
internal class EditorialPermissions(private val values: Map<String, Boolean> = emptyMap()) {
    fun allows(key: String): Boolean = values[key] == true
    val eventsView get() = allows("events_view")
    val eventsEdit get() = allows("events_edit")
    val eventsPublish get() = allows("events_publish")
    val eventsModerate get() = allows("events_moderate")
    val eventsFeature get() = allows("events_feature")
    val submissionsPublish get() = allows("submissions_publish")
    val venuesView get() = allows("venues_view")
    val venuesEdit get() = allows("venues_edit")
    val venuesModerate get() = allows("venues_moderate")
    val venuesMembers get() = allows("venues_members")
    val applicationsReview get() = allows("applications_review")
    val usersView get() = allows("users_view")
    val usersManage get() = allows("users_manage")
    val usersRoles get() = allows("users_roles")
    val reportsResolve get() = allows("reports_resolve")
    val reviewsModerate get() = allows("reviews_moderate")
    val commentsModerate get() = allows("comments_moderate")
    val commentsDelete get() = allows("comments_delete")
    val communityManage get() = allows("community_manage")
    val casesManage get() = allows("cases_manage")
    val carpoolManage get() = allows("carpool_manage")
    val messagesRead get() = allows("messages_read")
    val sponsorshipsManage get() = allows("sponsorships_manage")
    val socialUse get() = allows("social_use")
    val analyticsView get() = allows("analytics_view")
    val searchMissesManage get() = allows("search_misses_manage")
    val catalogManage get() = allows("catalog_manage")
    val pagesManage get() = allows("pages_manage")
    val importsManage get() = allows("imports_manage")
    val notificationsView get() = allows("notifications_view")
    val notificationsCancel get() = allows("notifications_cancel")
    val systemView get() = allows("system_view")
    val systemManage get() = allows("system_manage")
    val mailManage get() = allows("mail_manage")
    val organizersManage get() = allows("organizers_manage")
    /** Vero se almeno una delle chiavi è permessa. */
    fun any(vararg keys: String): Boolean = keys.any(::allows)
    override fun equals(other: Any?) = other is EditorialPermissions && other.values == values
    override fun hashCode() = values.hashCode()
    override fun toString() = "EditorialPermissions($values)"
}

/** `features.editorial` di `GET /config`: l'area nativa si accende solo con un sì esplicito. */
internal fun editorialFeatureEnabled(config: JsonElement?): Boolean =
    truthy(((config as? JsonObject)?.get("features") as? JsonObject)?.get("editorial"))

/** `management.editorial` di `GET /me`: moderatore, admin o super admin. Un server vecchio non lo manda: allora no. */
internal fun User.isEditorialStaff(): Boolean = truthy((management as? JsonObject)?.get("editorial") as? JsonPrimitive)

/** La riga «Amministrazione» del profilo apre l'area nativa (se `features.editorial` è acceso lo controlla l'area all'ingresso). */
internal fun showsEditorialEntry(user: User): Boolean = user.isEditorialStaff()

/** Il collegamento «Amministrazione» di `management_links`: lo riconosce l'icona, che il server manda fissa. */
internal const val EDITORIAL_LINK_ICON = "admin"
