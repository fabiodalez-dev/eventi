package it.fabiodalez.incitta.ui

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import it.fabiodalez.incitta.notifications.PushRegistration
import kotlinx.coroutines.launch
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

@Composable
fun NotificationSettingsPanel(session: Session) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val preferenceJson = remember { Json(api.json) { encodeDefaults = true } }
    var expanded by remember(session.user.id) { mutableStateOf(false) }
    var preferences by remember(session.user.id) { mutableStateOf<NotificationPreferences?>(null) }
    var interests by remember(session.user.id) { mutableStateOf(NotificationInterests()) }
    var busy by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("") }
    var inbox by remember(session.user.id) { mutableStateOf(emptyList<InboxNotification>()) }
    var nextCursor by remember(session.user.id) { mutableStateOf<String?>(null) }
    val saved = stringResource(R.string.notification_saved)
    val enabled = stringResource(R.string.notification_device_enabled)
    val failed = stringResource(R.string.notification_device_failed)
    val enableDevice = {
        PushRegistration.enable(context.applicationContext) { success -> status = if (success) enabled else failed }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) enableDevice() else status = failed
    }
    LaunchedEffect(expanded, session.token) {
        if (!expanded) return@LaunchedEffect
        busy = true
        try {
            preferences = api.get<ApiEnvelope<NotificationPreferences>>("me/notification-preferences", session.token).data
            interests = api.get<ApiEnvelope<NotificationInterests>>("me/notification-interests", session.token).data
            val messages = api.get<ApiEnvelope<List<InboxNotification>>>("me/notifications", session.token)
            inbox = messages.data
            nextCursor = messages.meta?.nextCursor
        } catch (error: Exception) { status = error.message.orEmpty() }
        finally { busy = false }
    }
    OutlinedButton(onClick = { expanded = !expanded }, modifier = Modifier.fillMaxWidth()) {
        Text(stringResource(R.string.notification_settings))
    }
    if (!expanded) return
    Column(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.notification_interest_help), color = Muted)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (status.isNotBlank()) Text(status, color = Acid)
        preferences?.let { prefs ->
            Text(stringResource(R.string.notification_delivery), style = MaterialTheme.typography.titleMedium)
            val labels = listOf(R.string.notification_auto, R.string.notification_mail, R.string.notification_push, R.string.notification_both, R.string.notification_archive)
            listOf("auto", "mail", "push", "both", "database").forEachIndexed { index, value ->
                Row {
                    RadioButton(selected = prefs.delivery == value, enabled = !busy, onClick = { preferences = prefs.copy(delivery = value) })
                    Text(stringResource(labels[index]), Modifier.padding(top = 12.dp))
                }
            }
            NotificationToggle(stringResource(R.string.notification_daily), prefs.dailyDigest, !busy) { preferences = prefs.copy(dailyDigest = it) }
            NotificationToggle(stringResource(R.string.notification_weekly), prefs.venueDigest, !busy) { preferences = prefs.copy(venueDigest = it) }
            NotificationToggle(stringResource(R.string.notification_reminders), prefs.reminders, !busy) { preferences = prefs.copy(reminders = it) }
            NotificationToggle(stringResource(R.string.notification_sold_out), prefs.soldOut, !busy) { preferences = prefs.copy(soldOut = it) }
            OutlinedTextField(value = prefs.dailyDigestTime?.take(5) ?: "17:00", onValueChange = { preferences = prefs.copy(dailyDigestTime = it) }, label = { Text(stringResource(R.string.notification_time)) }, enabled = !busy, modifier = Modifier.fillMaxWidth(), singleLine = true)
            Text(stringResource(R.string.notification_categories), style = MaterialTheme.typography.titleMedium)
            Column(Modifier.heightIn(max = 260.dp).verticalScroll(rememberScrollState())) {
                interests.categories.forEach { choice ->
                    NotificationToggle(choice.name, choice.selected, !busy) { selected -> interests = interests.copy(categories = interests.categories.map { if (it.id == choice.id) it.copy(selected = selected) else it }) }
                }
            }
            Text(stringResource(R.string.notification_venues), style = MaterialTheme.typography.titleMedium)
            Column(Modifier.heightIn(max = 260.dp).verticalScroll(rememberScrollState())) {
                interests.venues.forEach { choice ->
                    NotificationToggle(choice.name, choice.selected, !busy) { selected -> interests = interests.copy(venues = interests.venues.map { if (it.id == choice.id) it.copy(selected = selected) else it }) }
                }
            }
            Button(onClick = {
                scope.launch {
                    busy = true
                    try {
                        val selection = InterestSelection(interests.categories.filter { it.selected }.map { it.id }, interests.venues.filter { it.selected }.map { it.id })
                        interests = api.execute<ApiEnvelope<NotificationInterests>>("me/notification-interests", "PATCH", api.json.encodeToString(selection), session.token).data
                        preferences = api.execute<ApiEnvelope<NotificationPreferences>>("me/notification-preferences", "PATCH", preferenceJson.encodeToString(prefs.copy(dailyDigestTime = prefs.dailyDigestTime?.take(5))), session.token).data
                        status = saved
                    } catch (error: Exception) { status = error.message.orEmpty() }
                    finally { busy = false }
                }
            }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.notification_save)) }
            if (prefs.pushAvailable && PushRegistration.available(context)) {
                Button(onClick = {
                    if (Build.VERSION.SDK_INT >= 33) permission.launch(Manifest.permission.POST_NOTIFICATIONS) else enableDevice()
                }, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.notification_enable_device)) }
                TextButton(onClick = { PushRegistration.disable(context); status = context.getString(R.string.notification_device_disabled) }) { Text(stringResource(R.string.notification_disable_device)) }
            } else Text(stringResource(R.string.notification_push_unavailable), color = Muted)
        }
        HorizontalDivider(Modifier.padding(vertical = 12.dp))
        Text(stringResource(R.string.notification_inbox), style = MaterialTheme.typography.titleMedium)
        inbox.forEach { item ->
            TextButton(onClick = {
                scope.launch {
                    runCatching { api.execute<ApiEnvelope<InboxNotification>>("me/notifications/${item.id}/read", "PATCH", "{}", session.token) }
                    inbox = inbox.map { if (it.id == item.id) it.copy(read = true) else it }
                }
                val intent = android.content.Intent(context, it.fabiodalez.incitta.MainActivity::class.java).apply {
                    action = android.content.Intent.ACTION_VIEW
                    data = android.net.Uri.parse(item.data.url)
                    putExtra("notification_user_id", session.user.id)
                    flags = android.content.Intent.FLAG_ACTIVITY_SINGLE_TOP or android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP
                }
                context.startActivity(intent)
            }, modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.fillMaxWidth()) {
                    Text(item.data.title, color = if (item.read) Muted else Acid)
                    Text(item.data.body, color = Paper)
                }
            }
        }
        if (nextCursor != null) TextButton(enabled = !busy, onClick = {
            scope.launch {
                busy = true
                try {
                    val cursor = java.net.URLEncoder.encode(nextCursor, "UTF-8")
                    val page = api.get<ApiEnvelope<List<InboxNotification>>>("me/notifications?cursor=$cursor", session.token)
                    inbox = (inbox + page.data).distinctBy { it.id }
                    nextCursor = page.meta?.nextCursor
                } catch (error: Exception) { status = error.message.orEmpty() }
                finally { busy = false }
            }
        }) { Text(stringResource(R.string.notification_more)) }
    }
}

@Composable
private fun NotificationToggle(label: String, checked: Boolean, enabled: Boolean, change: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth()) {
        Checkbox(checked = checked, enabled = enabled, onCheckedChange = change)
        Text(label, Modifier.padding(top = 12.dp))
    }
}
