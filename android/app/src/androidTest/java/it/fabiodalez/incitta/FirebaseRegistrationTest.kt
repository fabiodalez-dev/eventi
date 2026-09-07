package it.fabiodalez.incitta

import android.content.Context
import android.content.pm.PackageManager
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.android.gms.tasks.Tasks
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.TimeUnit

@RunWith(AndroidJUnit4::class)
class FirebaseRegistrationTest {
    @Test fun registeredReleasePackageObtainsAndRevokesItsFcmToken() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        assumeTrue("Only the explicitly configured Firebase build", FirebaseApp.getApps(context).isNotEmpty())
        val metadata = context.packageManager.getApplicationInfo(context.packageName, PackageManager.GET_META_DATA).metaData
        assertFalse(metadata.getBoolean("firebase_messaging_installation_id_enabled"))
        val messaging = FirebaseMessaging.getInstance()
        messaging.isAutoInitEnabled = false
        val token = Tasks.await(messaging.token, 60, TimeUnit.SECONDS)
        assertTrue(token.length > 20)
        Tasks.await(messaging.deleteToken(), 60, TimeUnit.SECONDS)
        assertFalse(messaging.isAutoInitEnabled)
    }
}
