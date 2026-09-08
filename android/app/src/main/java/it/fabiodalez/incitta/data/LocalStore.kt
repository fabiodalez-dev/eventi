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
        if (readSession()?.user?.id != session.user.id) {
            cacheOccurrences(emptyList())
            runCatching { it.fabiodalez.incitta.calendar.NativeCalendar.disconnect(context) }
        }
        val stored = StoredSession(session.token, session.user, session.expiresAt)
        prefs.edit { putString(KEY_SESSION, encrypt(json.encodeToString(stored))) }
    }

    fun clearSession() {
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
