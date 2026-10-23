package it.fabiodalez.incitta.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.semantics.toggleableState
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.CollectionDetail
import it.fabiodalez.incitta.data.CollectionItem
import it.fabiodalez.incitta.data.CollectionsApi
import it.fabiodalez.incitta.data.EventCollection
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.requestFailureMessage
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
private fun rememberCollectionsApi(session: Session?): CollectionsApi {
    val context = LocalContext.current
    return remember(session?.token) { CollectionsApi(ApiClient(LocalStore(context).installationId), session?.token) }
}

/**
 * Le raccolte della persona: elenco con creazione, poi il dettaglio con
 * rinomina, interruttore «condivisa con un link», link da copiare o
 * condividere e l'elenco delle date con «Togli».
 */
@Composable
internal fun CollectionsScreen(session: Session?, padding: PaddingValues, onBack: () -> Unit, onOpenOccurrence: (Long) -> Unit, onLogin: () -> Unit) {
    val api = rememberCollectionsApi(session)
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var collections by remember(session?.token) { mutableStateOf<List<EventCollection>?>(null) }
    var selectedId by remember(session?.token) { mutableStateOf<Long?>(null) }
    var detail by remember(session?.token) { mutableStateOf<CollectionDetail?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var confirmDelete by remember { mutableStateOf(false) }
    val texts = CollectionTexts(
        created = stringResource(R.string.collections_created), saved = stringResource(R.string.collections_saved),
        removed = stringResource(R.string.collections_item_removed), deleted = stringResource(R.string.collections_deleted),
        copied = stringResource(R.string.collections_copied), chooser = stringResource(R.string.collections_share_chooser),
    )

    fun action(block: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true; error = null; message = null
            try { block() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }

    BackHandler { if (selectedId != null) { selectedId = null; detail = null; error = null; message = null } else onBack() }

    LaunchedEffect(session?.token, selectedId, reload) {
        if (session == null) return@LaunchedEffect
        busy = true; error = null
        try {
            val id = selectedId
            if (id == null) collections = api.list() else detail = api.show(id)
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }

    Column(
        Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        TextButton(
            onClick = { if (selectedId != null) { selectedId = null; detail = null; error = null; message = null } else onBack() },
            modifier = Modifier.heightIn(min = 48.dp),
        ) { Text(stringResource(if (selectedId != null) R.string.collections_back else R.string.collections_back_app)) }
        Text(detail?.takeIf { selectedId != null }?.name ?: stringResource(R.string.collections_title), style = MaterialTheme.typography.headlineLarge)
        if (session == null) {
            Text(stringResource(R.string.collections_add_login), color = Muted)
            Button(onClick = onLogin, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_login)) }
            return@Column
        }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error)
            OutlinedButton(onClick = { reload++ }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_retry)) }
        }
        message?.let { Text(it, color = Acid) }

        val current = detail
        if (selectedId == null) {
            Text(stringResource(R.string.collections_intro), color = Muted)
            var name by remember { mutableStateOf("") }
            var public by remember { mutableStateOf(false) }
            OutlinedTextField(name, { name = it.take(120) }, label = { Text(stringResource(R.string.collections_new_name)) }, singleLine = true, enabled = !busy, modifier = Modifier.fillMaxWidth())
            PublicSwitch(public, !busy) { public = it }
            Button(
                enabled = !busy && name.isNotBlank(),
                onClick = { action { val created = api.create(name, public); name = ""; public = false; collections = listOf(created) + collections.orEmpty(); message = texts.created } },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.collections_create)) }
            HorizontalDivider(color = Rule)
            val list = collections
            if (list != null && list.isEmpty() && !busy) Text(stringResource(R.string.collections_empty), color = Muted)
            list.orEmpty().forEach { collection ->
                CollectionRow(collection) { selectedId = collection.id; detail = null; message = null }
            }
        } else if (current != null) {
            var name by remember(current.id) { mutableStateOf(current.name) }
            var description by remember(current.id) { mutableStateOf(current.description.orEmpty()) }
            var public by remember(current.id) { mutableStateOf(current.isPublic) }
            OutlinedTextField(name, { name = it.take(120) }, label = { Text(stringResource(R.string.collections_name)) }, singleLine = true, enabled = !busy, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(description, { description = it.take(500) }, label = { Text(stringResource(R.string.collections_description)) }, enabled = !busy, modifier = Modifier.fillMaxWidth())
            PublicSwitch(public, !busy) { public = it }
            Button(
                enabled = !busy && name.isNotBlank(),
                onClick = { action {
                    val id = requireNotNull(current.id)
                    api.update(id, name, description, public)
                    detail = api.show(id)
                    message = texts.saved
                } },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.collections_save)) }

            Text(stringResource(R.string.collections_link), style = MaterialTheme.typography.titleMedium)
            val link = current.publicUrl?.takeIf { current.isPublic }
            if (link == null) Text(stringResource(R.string.collections_private_note), color = Muted)
            else {
                Text(link, color = Muted)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { copyLink(context, current.name, link); message = texts.copied }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_copy)) }
                    Button(onClick = { shareLink(context, current.name, link, texts.chooser) }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_share)) }
                }
            }

            HorizontalDivider(color = Rule)
            Text(stringResource(R.string.collections_dates), style = MaterialTheme.typography.titleLarge)
            if (current.items.isEmpty()) Text(stringResource(R.string.collections_items_empty), color = Muted)
            current.items.forEach { item ->
                CollectionItemRow(item, onOpen = { onOpenOccurrence(item.occurrenceId) }, onRemove = {
                    action {
                        val id = requireNotNull(current.id)
                        api.removeItem(id, item.occurrenceId)
                        detail = api.show(id)
                        message = texts.removed
                    }
                }, enabled = !busy)
            }
            HorizontalDivider(color = Rule)
            TextButton(onClick = { confirmDelete = true }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.collections_delete), color = Danger)
            }
            if (confirmDelete) AlertDialog(
                onDismissRequest = { confirmDelete = false },
                title = { Text(stringResource(R.string.collections_delete)) },
                text = { Text(stringResource(R.string.collections_delete_confirm, current.name)) },
                confirmButton = { TextButton(onClick = {
                    confirmDelete = false
                    action {
                        api.delete(requireNotNull(current.id))
                        collections = collections.orEmpty().filterNot { it.id == current.id }
                        selectedId = null; detail = null
                        message = texts.deleted
                    }
                }) { Text(stringResource(R.string.collections_delete), color = Danger) } },
                dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.collections_cancel)) } },
            )
        }
    }
}

/** Una raccolta condivisa da qualcun altro, in sola lettura. Funziona anche senza account. */
@Composable
internal fun SharedCollectionScreen(token: String, padding: PaddingValues, onBack: () -> Unit, onOpenOccurrence: (Long) -> Unit) {
    val api = rememberCollectionsApi(null)
    var detail by remember(token) { mutableStateOf<CollectionDetail?>(null) }
    var missing by remember(token) { mutableStateOf(false) }
    var error by remember(token) { mutableStateOf<String?>(null) }
    var busy by remember(token) { mutableStateOf(true) }
    var reload by remember { mutableIntStateOf(0) }
    BackHandler(onBack = onBack)
    LaunchedEffect(token, reload) {
        busy = true; error = null; missing = false
        try { detail = api.shared(token) }
        catch (e: CancellationException) { throw e }
        catch (e: ApiException) { if (e.status == 404) missing = true else error = requestFailureMessage(e) }
        catch (e: IllegalArgumentException) { missing = true }
        catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }
    Column(
        Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        TextButton(onClick = onBack, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_back_app)) }
        Text(stringResource(R.string.collections_shared_title), color = Acid, style = MaterialTheme.typography.labelLarge)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (missing) Text(stringResource(R.string.collections_shared_missing), color = Muted)
        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error)
            OutlinedButton(onClick = { reload++ }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_retry)) }
        }
        detail?.let { shared ->
            Text(shared.name, style = MaterialTheme.typography.headlineLarge)
            shared.owner?.takeIf(String::isNotBlank)?.let { Text(stringResource(R.string.collections_shared_by, it), color = Muted) }
            shared.description?.takeIf(String::isNotBlank)?.let { Text(it) }
            HorizontalDivider(color = Rule)
            if (shared.items.isEmpty()) Text(stringResource(R.string.collections_items_empty), color = Muted)
            shared.items.forEach { item -> CollectionItemRow(item, onOpen = { onOpenOccurrence(item.occurrenceId) }) }
        }
    }
}

/**
 * Il foglio «Aggiungi a una raccolta» della scheda evento: le raccolte della
 * persona e «Nuova raccolta…». Fuori sessione porta al login.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun AddToCollectionSheet(session: Session?, occurrenceId: Long, onDismiss: () -> Unit, onLogin: () -> Unit) {
    val api = rememberCollectionsApi(session)
    val scope = rememberCoroutineScope()
    var collections by remember(session?.token) { mutableStateOf<List<EventCollection>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var done by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf("") }
    var public by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val addedTemplate = stringResource(R.string.collections_added)
    fun action(block: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true; error = null
            try { block() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }
    LaunchedEffect(session?.token, reload) {
        if (session == null) return@LaunchedEffect
        busy = true; error = null
        try { collections = api.list() }
        catch (e: CancellationException) { throw e }
        catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.collections_add), style = MaterialTheme.typography.titleLarge)
            if (session == null) {
                Text(stringResource(R.string.collections_add_login), color = Muted)
                Button(onClick = { onDismiss(); onLogin() }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_login)) }
                return@Column
            }
            if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            error?.let {
                Text(it, color = MaterialTheme.colorScheme.error)
                OutlinedButton(onClick = { reload++ }, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_retry)) }
            }
            done?.let { Text(it, color = Acid) }
            Text(stringResource(R.string.collections_choose), color = Muted)
            collections.orEmpty().forEach { collection ->
                CollectionRow(collection, enabled = !busy) {
                    action { api.addItem(collection.id, occurrenceId); done = addedTemplate.format(collection.name) }
                }
            }
            if (!creating) {
                OutlinedButton(onClick = { creating = true }, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_new)) }
            } else {
                OutlinedTextField(name, { name = it.take(120) }, label = { Text(stringResource(R.string.collections_new_name)) }, singleLine = true, enabled = !busy, modifier = Modifier.fillMaxWidth())
                PublicSwitch(public, !busy) { public = it }
                Button(enabled = !busy && name.isNotBlank(), onClick = {
                    action {
                        val created = api.create(name, public, occurrenceId)
                        collections = listOf(created) + collections.orEmpty()
                        done = addedTemplate.format(created.name)
                        creating = false; name = ""; public = false
                    }
                }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.collections_create)) }
            }
            TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.End).heightIn(min = 48.dp)) { Text(stringResource(R.string.saved_preview_close)) }
        }
    }
}

@Composable
private fun PublicSwitch(checked: Boolean, enabled: Boolean, onChange: (Boolean) -> Unit) {
    val label = stringResource(R.string.collections_public)
    val state = stringResource(if (checked) R.string.collections_badge_public else R.string.collections_badge_private)
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(enabled = enabled, role = Role.Switch) { onChange(!checked) }
            .semantics(mergeDescendants = true) {
                contentDescription = label
                stateDescription = state
                toggleableState = ToggleableState(checked)
            },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.collections_public_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
        Switch(checked = checked, onCheckedChange = null, enabled = enabled)
    }
}

@Composable
private fun CollectionRow(collection: EventCollection, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 56.dp).clickable(enabled = enabled, onClick = onClick).padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(Modifier.weight(1f)) {
            Text(collection.name, style = MaterialTheme.typography.titleMedium)
            Text(pluralStringResource(R.plurals.collections_items, collection.itemsCount, collection.itemsCount), color = Muted)
        }
        Text(
            stringResource(if (collection.isPublic) R.string.collections_badge_public else R.string.collections_badge_private),
            color = if (collection.isPublic) Acid else Muted,
            style = MaterialTheme.typography.labelMedium,
            modifier = Modifier.border(BorderStroke(1.dp, Rule), ControlShape).padding(horizontal = 10.dp, vertical = 6.dp),
        )
    }
    HorizontalDivider(color = Rule)
}

@Composable
private fun CollectionItemRow(item: CollectionItem, onOpen: () -> Unit, onRemove: (() -> Unit)? = null, enabled: Boolean = true) {
    val title = item.title?.takeIf(String::isNotBlank) ?: stringResource(R.string.collections_untitled)
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f).heightIn(min = 56.dp).clickable(onClick = onOpen).padding(vertical = 8.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(collectionDate(item.startsAt), color = Acid, style = MaterialTheme.typography.labelLarge)
            item.venue?.let { Text(it, color = Muted) }
            item.note?.takeIf(String::isNotBlank)?.let { Text(stringResource(R.string.collections_note, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
        if (onRemove != null) {
            val label = stringResource(R.string.collections_remove_item_label, title)
            TextButton(onClick = onRemove, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = label }) {
                Text(stringResource(R.string.collections_remove_item))
            }
        }
    }
    HorizontalDivider(color = Rule)
}

private data class CollectionTexts(val created: String, val saved: String, val removed: String, val deleted: String, val copied: String, val chooser: String)

private val collectionDateFormatter = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)
private fun collectionDate(value: String): String = runCatching {
    OffsetDateTime.parse(value).atZoneSameInstant(ZoneId.systemDefault()).format(collectionDateFormatter).replaceFirstChar { it.uppercase() }
}.getOrDefault(value.take(10))

private fun copyLink(context: Context, label: String, link: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)?.setPrimaryClip(ClipData.newPlainText(label, link))
}

private fun shareLink(context: Context, title: String, link: String, chooser: String) {
    runCatching {
        context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, title)
            putExtra(Intent.EXTRA_TEXT, "$title\n$link")
        }, chooser))
    }
}
