package it.fabiodalez.incitta

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import it.fabiodalez.incitta.ui.InCittaApp

class MainActivity : ComponentActivity() {
    private val viewModel: MainViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        handleIntent(intent)
        setContent { InCittaApp(viewModel) }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIntent(intent)
    }

    private fun handleIntent(intent: Intent?) {
        val uri = intent?.data ?: return
        when {
            uri.scheme == "incitta" && uri.host == "auth" -> {
                uri.getQueryParameter("token")?.takeIf(String::isNotBlank)?.let(viewModel::exchangeMagicToken)
            }
            uri.host == "eventi.fabiodalez.it" -> {
                val parts = uri.pathSegments
                when {
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
