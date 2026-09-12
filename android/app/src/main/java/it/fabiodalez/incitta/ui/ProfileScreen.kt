package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import java.time.ZoneId

@Composable
internal fun ProfileScreen(state: AppUiState, padding: PaddingValues, onTickets: () -> Unit, onSaved: () -> Unit,
    onLogout: () -> Unit, onDeleteAccount: (String) -> Unit, onInterestsSaved: () -> Unit,
    onAppearance: (String) -> Unit, onProfileSaved: () -> Unit) {
    val session = state.session ?: return
    val uriHandler = androidx.compose.ui.platform.LocalUriHandler.current
    var section by rememberSaveable(session.user.id) { mutableStateOf<String?>(null) }
    var deletePassword by remember(session.user.id) { mutableStateOf("") }
    var deleteArmed by remember(session.user.id) { mutableStateOf(false) }
    BackHandler(section != null) { section = null }
    key(section) {
        Column(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            if (section != null) TextButton(onClick = { section = null }, modifier = Modifier.heightIn(min = 48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = null)
                Text("Il mio profilo", Modifier.padding(start = 8.dp))
            }
            Text(section ?: "Il mio profilo", style = MaterialTheme.typography.headlineLarge)
            when (section) {
                null -> {
                    AccountIdentity(session.user, onLogout, !state.isAuthenticating)
                    ProfileRow("Dati personali", "Nome e fuso orario", Icons.Outlined.Person) { section = "Dati personali" }
                    ProfileRow("I miei biglietti", "Prossimi, passati e annullati", Icons.Outlined.ConfirmationNumber, onTickets)
                    ProfileRow("I miei interessi", "Scegli quali eventi vedere", Icons.Outlined.FavoriteBorder) { section = "I miei interessi" }
                    ProfileRow("Notifiche e newsletter", "Canali, orari e permessi del dispositivo", Icons.Outlined.NotificationsNone) { section = "Notifiche e newsletter" }
                    ProfileRow("Eventi salvati", "Ritrova le tue date", Icons.Outlined.BookmarkBorder, onSaved)
                    ProfileRow("Calendario", "Google Calendar e calendario del telefono", Icons.Outlined.CalendarMonth) { section = "Calendario" }
                    ProfileRow("Aspetto", "Tema chiaro o scuro", Icons.Outlined.Palette) { section = "Aspetto" }
                    ProfileRow("Account e accesso", "Esci o cancella il tuo account", Icons.Outlined.ManageAccounts) { section = "Account e accesso" }
                    if (session.user.managementLinks.isNotEmpty()) {
                        Text("Gestione", style = MaterialTheme.typography.titleLarge)
                        session.user.managementLinks.forEach { link ->
                            ProfileRow(link.label, "Apri il pannello web", when (link.icon) {
                                "newsletter" -> Icons.Outlined.Email
                                "tickets" -> Icons.Outlined.ConfirmationNumber
                                "venue" -> Icons.Outlined.Storefront
                                else -> Icons.Outlined.Dashboard
                            }) { uriHandler.openUri(link.url) }
                        }
                    }
                }
                "Dati personali" -> ProfileDetails(session, onProfileSaved)
                "I miei interessi" -> ContentPreferencesPanel(session, standalone = true, onSaved = onInterestsSaved)
                "Notifiche e newsletter" -> NotificationSettingsPanel(session, standalone = true)
                "Calendario" -> CalendarSubscriptionPanel(initiallyExpanded = true)
                "Aspetto" -> AppearancePicker(state.appearance, state.appearanceSaving, true, onAppearance)
                "Account e accesso" -> {
                    Text("I tuoi salvataggi e biglietti restano associati a questo account.", color = Muted)
                    OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Esci da questo dispositivo") }
                    HorizontalDivider(color = Rule)
                    Text("Cancella account", style = MaterialTheme.typography.titleLarge, color = Danger)
                    Text("La cancellazione rimuove account, salvati, follow, dispositivi e notifiche. Non può essere annullata.", color = Muted)
                    if (deleteArmed) {
                        OutlinedTextField(deletePassword, { deletePassword = it }, label = { Text("Password corrente") }, singleLine = true,
                            visualTransformation = androidx.compose.ui.text.input.PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                        Button(onClick = { onDeleteAccount(deletePassword) }, enabled = deletePassword.isNotBlank() && !state.isAuthenticating,
                            colors = ButtonDefaults.buttonColors(containerColor = Danger), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text("Conferma cancellazione") }
                    } else TextButton(onClick = { deleteArmed = true }) { Text("Cancella il mio account", color = Danger) }
                }
            }
        }
    }
}

@Composable
private fun ProfileRow(title: String, description: String, icon: ImageVector, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        Icon(icon, contentDescription = null, tint = Acid, modifier = Modifier.size(24.dp))
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(description, style = MaterialTheme.typography.bodyMedium, color = Muted)
        }
        Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = null, tint = Muted)
    }
    HorizontalDivider(color = Rule)
}

@Composable
private fun ProfileDetails(session: Session, onSaved: () -> Unit) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val scope = rememberCoroutineScope()
    var name by remember(session.user.id) { mutableStateOf(session.user.name.orEmpty()) }
    var timezone by remember(session.user.id) { mutableStateOf(session.user.timezone) }
    var choosing by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("") }
    val zones = remember { ZoneId.getAvailableZoneIds().filterNot { it.startsWith("SystemV/") }.sorted() }
    OutlinedTextField(name, { name = it.take(120) }, label = { Text("Nome") }, enabled = !busy, modifier = Modifier.fillMaxWidth())
    Text(session.user.email, color = Muted)
    Text("Fuso orario", style = MaterialTheme.typography.titleMedium)
    OutlinedButton(onClick = { choosing = true }, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(timezone) }
    Text("Usato anche per gli orari delle notifiche e le ore di silenzio.", color = Muted)
    if (status.isNotBlank()) Text(status)
    Button(enabled = !busy, onClick = {
        scope.launch {
            busy = true
            try {
                api.execute<ApiEnvelope<User>>("me", "PATCH", buildJsonObject { put("name", name); put("timezone", timezone) }.toString(), session.token)
                status = "Profilo aggiornato."
                onSaved()
            } catch (error: Exception) {
                if (error is CancellationException) throw error
                status = requestFailureMessage(error)
            } finally { busy = false }
        }
    }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(if (busy) "Salvataggio…" else "Salva profilo") }
    if (choosing) AlertDialog(onDismissRequest = { choosing = false }, title = { Text("Scegli il fuso orario") }, text = {
        Column {
            OutlinedTextField(query, { query = it }, label = { Text("Cerca città o fuso") }, singleLine = true)
            LazyColumn(Modifier.heightIn(max = 320.dp)) {
                items(zones.filter { it.contains(query, ignoreCase = true) }) { zone ->
                    TextButton(onClick = { timezone = zone; choosing = false; query = "" }, modifier = Modifier.fillMaxWidth()) { Text(zone) }
                }
            }
        }
    }, confirmButton = { TextButton(onClick = { choosing = false }) { Text("Chiudi") } })
}
