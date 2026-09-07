package it.fabiodalez.incitta.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay

@Composable
fun CalendarSubscriptionPanel(initiallyExpanded: Boolean = false) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var expanded by remember { mutableStateOf(initiallyExpanded) }
    var categories by remember { mutableStateOf(emptyList<Category>()) }
    var selected by remember { mutableStateOf(emptySet<String>()) }
    var venue by remember { mutableStateOf<Venue?>(null) }
    var query by remember { mutableStateOf("") }
    var suggestions by remember { mutableStateOf(emptyList<Venue>()) }
    var days by remember { mutableIntStateOf(30) }
    var free by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    LaunchedEffect(expanded) {
        if (expanded) {
            loading = true
            try { categories = api.get<ApiEnvelope<List<Category>>>("categories").data }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = e.message.orEmpty() }
            finally { loading = false }
        }
    }
    LaunchedEffect(query) {
        suggestions = emptyList()
        if (venue != null || query.trim().length < 2) return@LaunchedEffect
        delay(250)
        try { suggestions = api.get<ApiEnvelope<SearchResults>>("search?q=${Uri.encode(query.trim())}&limit=8").data.venues }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = e.message.orEmpty() }
    }
    OutlinedButton(onClick = { expanded = !expanded }, modifier = Modifier.fillMaxWidth().padding(12.dp)) {
        Text(stringResource(R.string.calendar_customize))
    }
    if (!expanded) return
    Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(stringResource(R.string.calendar_sync_help), color = Muted)
        if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (error.isNotBlank()) Text(error, color = Acid)
        Column(Modifier.heightIn(max = 240.dp).verticalScroll(rememberScrollState())) {
            categories.filter { it.slug != null }.forEach { category ->
                val slug = requireNotNull(category.slug)
                Row {
                    Checkbox(checked = slug in selected, onCheckedChange = { selected = if (it) selected + slug else selected - slug })
                    Text(category.name, Modifier.padding(top = 12.dp))
                }
            }
        }
        Text(stringResource(R.string.calendar_all_categories), color = Muted)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(7, 30, 90).forEach { value -> FilterChip(selected = days == value, onClick = { days = value }, label = { Text(stringResource(R.string.calendar_days, value)) }) }
        }
        OutlinedTextField(value = query, onValueChange = { query = it; venue = null }, label = { Text(stringResource(R.string.calendar_venue_search)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
        suggestions.forEach { item -> TextButton(onClick = { venue = item; query = item.name; suggestions = emptyList() }) { Text(item.name) } }
        if (query.isNotEmpty()) TextButton(onClick = { venue = null; query = "" }) { Text(stringResource(R.string.calendar_all_venues)) }
        Row { Checkbox(checked = free, onCheckedChange = { free = it }); Text(stringResource(R.string.calendar_free), Modifier.padding(top = 12.dp)) }
        val url = Uri.parse(BuildConfig.API_BASE_URL).buildUpon().path("/eventi.ics").clearQuery()
            .appendQueryParameter("days", days.toString()).apply {
                if (selected.isNotEmpty()) appendQueryParameter("category", selected.joinToString(","))
                venue?.let { appendQueryParameter("venue", it.slug) }
                if (free) appendQueryParameter("price", "free")
            }.build()
        val valid = !loading && (query.isBlank() || venue != null)
        Button(enabled = valid, onClick = {
            val google = Uri.parse("https://calendar.google.com/calendar/u/0/r").buildUpon().appendQueryParameter("cid", url.toString()).build()
            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, google)) }.onFailure { error = it.message.orEmpty() }
        }, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.calendar_subscribe_google)) }
        TextButton(enabled = valid, onClick = {
            val clipboard = context.getSystemService(android.content.Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager
            clipboard.setPrimaryClip(android.content.ClipData.newPlainText(context.getString(R.string.calendar_customize), url.toString()))
            error = context.getString(R.string.calendar_link_copied)
        }) { Text(stringResource(R.string.calendar_copy_link)) }
        TextButton(enabled = valid, onClick = { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url)) }.onFailure { error = it.message.orEmpty() } }) { Text(stringResource(R.string.calendar_download)) }
    }
}
