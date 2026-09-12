package it.fabiodalez.incitta.notifications

import android.content.Context
import android.app.NotificationChannel
import android.app.NotificationManager
import androidx.core.app.NotificationCompat
import it.fabiodalez.incitta.R
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
    fun canNotify(context: Context): Boolean {
        if (!NotificationManagerCompat.from(context).areNotificationsEnabled()) return false
        val channel = context.getSystemService(NotificationManager::class.java).getNotificationChannel("eventi")
        return channel == null || channel.importance != NotificationManager.IMPORTANCE_NONE
    }
    fun available(context: Context): Boolean = FirebaseApp.getApps(context).isNotEmpty()
    fun enable(context: Context, result: (Boolean) -> Unit) {
        val session = LocalStore(context).readSession() ?: return result(false)
        if (!available(context) || !canNotify(context)) return result(false)
        val previouslyEnabled = enabled(context)
        val complete: (Boolean) -> Unit = { success ->
            if (!success && !previouslyEnabled && LocalStore(context).readSession()?.user?.id == session.user.id) {
                settings(context).edit().remove("user_id").apply()
            }
            result(success)
        }
        settings(context).edit().putLong("user_id", session.user.id).apply()
        FirebaseMessaging.getInstance().isAutoInitEnabled = true
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            register(context.applicationContext, token, complete)
        }.addOnFailureListener { complete(false) }
    }
    fun showTest(context: Context): Boolean {
        if (!canNotify(context)) return false
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel("eventi", context.getString(R.string.notification_channel), NotificationManager.IMPORTANCE_DEFAULT))
        if (manager.getNotificationChannel("eventi").importance == NotificationManager.IMPORTANCE_NONE) return false
        return try {
            NotificationManagerCompat.from(context).notify(1978, NotificationCompat.Builder(context, "eventi")
                .setSmallIcon(R.drawable.ic_notification).setContentTitle("Notifica di prova")
                .setContentText("Questo dispositivo può mostrare le notifiche di inCittà.")
                .setAutoCancel(true).build())
            true
        } catch (_: SecurityException) { false }
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
