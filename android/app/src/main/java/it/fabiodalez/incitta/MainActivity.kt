package it.fabiodalez.incitta

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import it.fabiodalez.incitta.ui.InCittaApp
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import kotlinx.coroutines.Dispatchers

class MainActivity : ComponentActivity() {
    private val viewModel: MainViewModel by viewModels()

    override fun onResume() {
        super.onResume()
        lifecycleScope.launch(Dispatchers.IO) {
            runCatching {
                if (it.fabiodalez.incitta.calendar.NativeCalendar.enabled(applicationContext)) {
                    it.fabiodalez.incitta.calendar.NativeCalendar.refresh(applicationContext)
                } else it.fabiodalez.incitta.calendar.NativeCalendar.disconnect(applicationContext)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        it.fabiodalez.incitta.notifications.PushRegistration.refresh(applicationContext)
        handleIntent(intent)
        setContent { InCittaApp(viewModel) }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIntent(intent)
    }

    private fun handleIntent(intent: Intent?) {
        if (intent?.hasExtra("notification_user_id") == true &&
            intent.getLongExtra("notification_user_id", -1) != it.fabiodalez.incitta.data.LocalStore(this).readSession()?.user?.id) return
        val uri = intent?.data ?: return
        when {
            uri.scheme == "incitta" && uri.host == "account" -> viewModel.selectTab(AppTab.ACCOUNT)
            uri.scheme == "incitta" && uri.host == "auth" -> {
                uri.getQueryParameter("token")?.takeIf(String::isNotBlank)?.let(viewModel::exchangeMagicToken)
            }
            uri.host == "eventi.fabiodalez.it" || uri.host == android.net.Uri.parse(BuildConfig.API_BASE_URL).host -> {
                val parts = uri.pathSegments
                when {
                    parts.firstOrNull() == "il-mio-feed" || parts.firstOrNull() == "notifiche" -> viewModel.selectTab(AppTab.ACCOUNT)
                    parts.firstOrNull() == "locali" && parts.size >= 2 -> viewModel.openVenueSlug(parts[1])
                    parts.firstOrNull() == "mappa" -> viewModel.selectTab(AppTab.MAP)
                    parts.firstOrNull() == "calendario" -> viewModel.selectTab(AppTab.CALENDAR)
                    parts.firstOrNull() == "biglietti" -> viewModel.selectTab(AppTab.TICKETS)
                    parts.take(2) == listOf("eventi", "tag") && parts.size >= 3 ->
                        viewModel.browseTag(it.fabiodalez.incitta.data.Tag(slug = parts[2], name = parts[2]))
                    parts.take(2) == listOf("eventi", "categoria") && parts.size >= 3 -> viewModel.browseCategory(parts[2])
                    parts == listOf("eventi", "oggi") -> { viewModel.selectTab(AppTab.EVENTS); viewModel.refresh(it.fabiodalez.incitta.data.EventFilter.TODAY) }
                    parts == listOf("eventi", "domani") -> { viewModel.selectTab(AppTab.EVENTS); viewModel.refresh(it.fabiodalez.incitta.data.EventFilter.TOMORROW) }
                    parts == listOf("eventi", "weekend") -> { viewModel.selectTab(AppTab.EVENTS); viewModel.refresh(it.fabiodalez.incitta.data.EventFilter.WEEKEND) }
                    parts == listOf("eventi", "gratis") -> { viewModel.selectTab(AppTab.EVENTS); viewModel.refresh(it.fabiodalez.incitta.data.EventFilter.FREE) }
                    parts.firstOrNull() == "eventi" && parts.size >= 2 -> viewModel.openSlug(parts[1])
                }
            }
        }
    }
}
