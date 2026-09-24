package it.fabiodalez.incitta.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.core.content.edit
import java.nio.charset.StandardCharsets
import java.security.KeyStore
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

class LocalStore(private val context: Context) {
    private val prefs = context.getSharedPreferences("incitta", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    val installationId: String
        get() = prefs.getString(KEY_INSTALLATION, null) ?: UUID.randomUUID().toString().also {
            prefs.edit { putString(KEY_INSTALLATION, it) }
        }

    fun guestAppearance(): String {
        val saved = prefs.getString("guest_appearance", null)
        if (saved == "light" || saved == "dark") return saved
        val dark = context.resources.configuration.uiMode and android.content.res.Configuration.UI_MODE_NIGHT_MASK == android.content.res.Configuration.UI_MODE_NIGHT_YES
        return (if (dark) "dark" else "light").also(::setGuestAppearance)
    }
    fun setGuestAppearance(value: String) { prefs.edit { putString("guest_appearance", value) } }

    fun rememberedPosition(): RememberedPosition? {
        val raw = prefs.getString("remembered_position", null) ?: return null
        val position = runCatching { json.decodeFromString<RememberedPosition>(decrypt(raw)) }.getOrNull()
        if (position?.valid() == true) return position
        forgetPosition()
        return null
    }

    fun rememberPosition(position: RememberedPosition) {
        prefs.edit { putString("remembered_position", encrypt(json.encodeToString(position))) }
    }

    fun forgetPosition() { prefs.edit { remove("remembered_position") } }

    fun nearbyRadius(): Int = prefs.getInt("nearby_radius", 5).takeIf { it == 5 || it == 10 } ?: 5
    fun setNearbyRadius(value: Int) { if (value == 5 || value == 10) prefs.edit { putInt("nearby_radius", value) } }

    fun writeMagicVerifier(value: String) {
        prefs.edit { putString("magic_verifier", encrypt(value)); putLong("magic_requested_at", System.currentTimeMillis()) }
    }

    fun readMagicVerifier(): String? {
        if (System.currentTimeMillis() - prefs.getLong("magic_requested_at", 0) > 15 * 60 * 1000) return null
        return prefs.getString("magic_verifier", null)?.let { runCatching { decrypt(it) }.getOrNull() }
    }

    fun clearMagicVerifier() { prefs.edit { remove("magic_verifier"); remove("magic_requested_at") } }

    internal fun writeWhatsappRequest(request: WhatsappPendingRequest) {
        prefs.edit(commit = true) { putString("whatsapp_handshake", encrypt(json.encodeToString(request))) }
    }

    internal fun readWhatsappRequest(): WhatsappPendingRequest? = prefs.getString("whatsapp_handshake", null)?.let {
        runCatching { json.decodeFromString<WhatsappPendingRequest>(decrypt(it)) }.getOrNull()
    }

    internal fun clearWhatsappRequest() { prefs.edit(commit = true) { remove("whatsapp_handshake") } }

    internal fun writeCarpoolPending(command: CarpoolPending) { writeCarpoolPendings((readCarpoolPendings().filterNot { it.key == command.key } + command).takeLast(30)) }
    private fun writeCarpoolPendings(commands: List<CarpoolPending>) { prefs.edit(commit = true) { putString("carpool_pending", encrypt(json.encodeToString(commands))) } }
    internal fun removeCarpoolPending(key: String) { writeCarpoolPendings(readCarpoolPendings().filterNot { it.key == key }) }
    internal fun readCarpoolPendings(): List<CarpoolPending> = prefs.getString("carpool_pending", null)?.let { runCatching { json.decodeFromString<List<CarpoolPending>>(decrypt(it)) }.getOrNull() }.orEmpty()
    internal fun clearCarpoolPending() { prefs.edit(commit = true) { remove("carpool_pending") } }

    internal fun readCheckins(): List<PendingCheckin> = prefs.getString("checkin_pending", null)?.let {
        runCatching { json.decodeFromString<List<PendingCheckin>>(decrypt(it)) }.getOrNull()
    }.orEmpty()
    internal fun writeCheckins(entries: List<PendingCheckin>) {
        check(prefs.edit().putString("checkin_pending", encrypt(json.encodeToString(entries))).commit())
    }
    private fun clearCheckins() { prefs.edit(commit = true) { remove("checkin_pending") } }

    fun readSession(): Session? {
        val encrypted = prefs.getString(KEY_SESSION, null) ?: return null
        return runCatching {
            json.decodeFromString<StoredSession>(decrypt(encrypted)).toSession()
        }.getOrElse {
            clearSession()
            null
        }
    }

    fun writeSession(session: Session) {
        if (readSession()?.token != session.token) {
            clearWhatsappRequest()
            clearCarpoolPending()
            it.fabiodalez.incitta.community.WhatsappAutofill.discard()
        }
        if (readSession()?.user?.id != session.user.id) {
            clearCheckins()
            cacheOccurrences(emptyList())
            runCatching { it.fabiodalez.incitta.calendar.NativeCalendar.disconnect(context) }
        }
        val stored = StoredSession(session.token, session.user, session.expiresAt)
        prefs.edit { putString(KEY_SESSION, encrypt(json.encodeToString(stored))) }
    }

    fun clearSession() {
        clearCheckins()
        clearWhatsappRequest()
        clearCarpoolPending()
        it.fabiodalez.incitta.community.WhatsappAutofill.discard()
        prefs.edit { remove(KEY_SESSION); remove(KEY_OCCURRENCES) }
        runCatching { it.fabiodalez.incitta.calendar.NativeCalendar.disconnect(context) }
        it.fabiodalez.incitta.notifications.PushRegistration.disable(context)
    }

    fun guestSavedIds(): Set<Long> = prefs.getStringSet(KEY_GUEST_SAVED, emptySet())
        .orEmpty()
        .mapNotNull(String::toLongOrNull)
        .toSet()

    fun setGuestSaved(ids: Set<Long>) {
        prefs.edit { putStringSet(KEY_GUEST_SAVED, ids.map(Long::toString).toSet()) }
    }

    fun cachedOccurrences(): List<Occurrence> = prefs.getString(KEY_OCCURRENCES, null)
        ?.let { runCatching { json.decodeFromString<List<Occurrence>>(it) }.getOrNull() }
        .orEmpty()

    fun cacheOccurrences(items: List<Occurrence>) {
        prefs.edit { putString(KEY_OCCURRENCES, json.encodeToString(items.take(60))) }
    }

    fun privacyConsent(): Boolean? = if (prefs.contains(KEY_PRIVACY_CONSENT)) {
        prefs.getBoolean(KEY_PRIVACY_CONSENT, false)
    } else null

    fun setPrivacyConsent(accepted: Boolean) {
        prefs.edit { putBoolean(KEY_PRIVACY_CONSENT, accepted) }
    }

    private fun encrypt(value: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        val iv = Base64.encodeToString(cipher.iv, Base64.NO_WRAP)
        val ciphertext = Base64.encodeToString(
            cipher.doFinal(value.toByteArray(StandardCharsets.UTF_8)),
            Base64.NO_WRAP,
        )
        return "$iv.$ciphertext"
    }

    private fun decrypt(value: String): String {
        val parts = value.split('.', limit = 2)
        require(parts.size == 2)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(
            Cipher.DECRYPT_MODE,
            secretKey(),
            GCMParameterSpec(128, Base64.decode(parts[0], Base64.NO_WRAP)),
        )
        return String(cipher.doFinal(Base64.decode(parts[1], Base64.NO_WRAP)), StandardCharsets.UTF_8)
    }

    private fun secretKey(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
            init(
                KeyGenParameterSpec.Builder(
                    KEY_ALIAS,
                    KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
                )
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setRandomizedEncryptionRequired(true)
                    .build(),
            )
            generateKey()
        }
    }

    @kotlinx.serialization.Serializable
    private data class StoredSession(
        val token: String,
        val user: User,
        val expiresAt: String? = null,
    ) {
        fun toSession() = Session(token, user, expiresAt)
    }

    private companion object {
        const val KEY_INSTALLATION = "installation_id"
        const val KEY_SESSION = "session"
        const val KEY_GUEST_SAVED = "guest_saved"
        const val KEY_OCCURRENCES = "occurrences"
        const val KEY_PRIVACY_CONSENT = "privacy_consent"
        const val KEY_ALIAS = "incitta_session_key"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
    }
}
