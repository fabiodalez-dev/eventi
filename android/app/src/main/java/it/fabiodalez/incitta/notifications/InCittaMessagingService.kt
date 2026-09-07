package it.fabiodalez.incitta.notifications

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.Uri
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import it.fabiodalez.incitta.MainActivity
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.LocalStore

class InCittaMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) { PushRegistration.register(applicationContext, token) }

    override fun onMessageReceived(message: RemoteMessage) {
        val session = LocalStore(this).readSession() ?: return
        if (!PushRegistration.enabled(this) || message.data["user_id"] != session.user.id.toString()) return
        if (!NotificationManagerCompat.from(this).areNotificationsEnabled()) return
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel("eventi", getString(R.string.notification_channel), NotificationManager.IMPORTANCE_DEFAULT))
        val target = Intent(this, MainActivity::class.java).apply {
            action = Intent.ACTION_VIEW
            data = Uri.parse(message.data["url"] ?: "")
            putExtra("notification_user_id", session.user.id)
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val id = (message.messageId ?: message.data.toString()).hashCode()
        val pending = PendingIntent.getActivity(this, id, target, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(this, "eventi")
            .setSmallIcon(R.drawable.ic_launcher)
            .setContentTitle(message.data["title"] ?: getString(R.string.app_name))
            .setContentText(message.data["body"].orEmpty())
            .setStyle(NotificationCompat.BigTextStyle().bigText(message.data["body"].orEmpty()))
            .setContentIntent(pending).setAutoCancel(true)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE).build()
        try { manager.notify(id, notification) } catch (_: SecurityException) { }
    }
}
