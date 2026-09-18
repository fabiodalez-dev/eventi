package it.fabiodalez.incitta.community

import android.content.Context
import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import it.fabiodalez.incitta.MainActivity
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** WhatsApp launches the callback from its own task: the forward must open, or reuse, the app screen. */
@RunWith(AndroidJUnit4::class)
class WhatsappForwardIntentTest {
    private val context = ApplicationProvider.getApplicationContext<Context>()

    @Test fun forwardTargetsMainActivityInANewOrReusedTask() {
        val intent = whatsappForwardIntent(context)
        assertEquals(MainActivity::class.java.name, intent.component?.className)
        assertEquals(context.packageName, intent.component?.packageName)
        listOf(Intent.FLAG_ACTIVITY_NEW_TASK, Intent.FLAG_ACTIVITY_CLEAR_TOP, Intent.FLAG_ACTIVITY_SINGLE_TOP).forEach {
            assertEquals(it, intent.flags and it)
        }
    }
}
