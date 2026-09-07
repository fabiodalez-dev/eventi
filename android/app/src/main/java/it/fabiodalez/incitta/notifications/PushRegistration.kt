package it.fabiodalez.incitta.notifications

import android.content.Context
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.*
import kotlinx.serialization.json.JsonObject

object PushRegistration {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private fun settings(context: Context) = context.getSharedPreferences("push", Context.MODE_PRIVATE)
    fun enabled(context: Context): Boolean {
        val user = LocalStore(context).readSession()?.user?.id ?: return false
        return settings(context).getLong("user_id", -1) == user
    }
    fun available(context: Context): Boolean = FirebaseApp.getApps(context).isNotEmpty()
    fun enable(context: Context, result: (Boolean) -> Unit) {
        val session = LocalStore(context).readSession() ?: return result(false)
        if (!available(context) || !NotificationManagerCompat.from(context).areNotificationsEnabled()) return result(false)
        settings(context).edit().putLong("user_id", session.user.id).apply()
        FirebaseMessaging.getInstance().isAutoInitEnabled = true
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            register(context.applicationContext, token, result)
        }.addOnFailureListener { result(false) }
    }
    fun refresh(context: Context) {
        if (enabled(context)) enable(context) { }
    }
    fun disable(context: Context) {
        settings(context).edit().clear().apply()
        NotificationManagerCompat.from(context).cancelAll()
        if (available(context)) {
            FirebaseMessaging.getInstance().isAutoInitEnabled = false
            FirebaseMessaging.getInstance().deleteToken()
        }
    }
    fun register(context: Context, token: String, result: (Boolean) -> Unit = {}) {
        if (!enabled(context)) return result(false)
        val store = LocalStore(context)
        val session = store.readSession() ?: return result(false)
        scope.launch {
            val success = runCatching {
                ApiClient(store.installationId).post<ApiEnvelope<JsonObject>, PushDeviceBody>(
                    "me/devices", PushDeviceBody(token, store.installationId, "android"), session.token,
                )
            }.isSuccess && store.readSession()?.token == session.token
            withContext(Dispatchers.Main) { result(success) }
        }
    }
}
