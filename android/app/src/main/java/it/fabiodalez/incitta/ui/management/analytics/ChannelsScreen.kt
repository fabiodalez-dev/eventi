package it.fabiodalez.incitta.ui.management.analytics

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.ClipboardManager
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.CHANNEL_LABEL_MAX
import it.fabiodalez.incitta.data.management.ChannelRow
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.formatCount
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.EventChoiceRow
import it.fabiodalez.incitta.ui.management.common.EventPickerSheet
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.ticketQr
import java.io.File

/*
 * I canali propri del locale e i loro QR (W-ST-06), e i link brevi di un
 * evento (W-PB-05). Il QR lo disegna l'app dal link breve con ZXing, già
 * fra le dipendenze per i biglietti: è lo stesso codice che il pannello
 * stampa in SVG, quindi le aperture finiscono nello stesso conteggio.
 */

/** La sezione «Canali e codici QR» di «Statistiche in dettaglio», solo per il referente. */
@Composable
internal fun ChannelsScreen(viewModel: ChannelsViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var picking by remember { mutableStateOf(false) }
    ManagementScaffold(
        title = stringResource(R.string.analytics_channels_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = state.subject?.name,
        busy = state.busy,
    ) {
        Text(stringResource(R.string.analytics_channels_lead))
        LoadState(state.channels, onRetry = viewModel::load, emptyText = stringResource(R.string.analytics_channel_owner_only)) { list ->
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(
                    value = state.label,
                    onValueChange = viewModel::setLabel,
                    label = { Text(stringResource(R.string.analytics_channel_label)) },
                    placeholder = { Text(stringResource(R.string.analytics_channel_placeholder)) },
                    supportingText = { Text(state.errors.field("label") ?: stringResource(R.string.analytics_channel_counter, state.label.length, CHANNEL_LABEL_MAX)) },
                    isError = state.errors.field("label") != null,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(stringResource(R.string.analytics_channel_target), style = MaterialTheme.typography.titleSmall)
                state.event?.let { EventChoiceRow(it) } ?: Text(stringResource(R.string.analytics_channel_venue_target))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { picking = true }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_filter_event_choose)) }
                    if (state.event != null) TextButton(onClick = { viewModel.setEvent(null) }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.analytics_channel_venue_target))
                    }
                }
                state.errors.field("event")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                Button(
                    enabled = !state.busy && state.label.isNotBlank(),
                    onClick = viewModel::create,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                ) { Text(stringResource(R.string.analytics_channel_create)) }
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                Text(stringResource(R.string.analytics_channel_count, list.meta.custom, list.meta.max), color = Muted)
                if (list.meta.custom == 0) Text(stringResource(R.string.analytics_channel_empty), color = Muted)
                HorizontalDivider()
                list.data.forEach { row -> ChannelCard(row, highlighted = row.code == state.created) }
            }
        }
    }
    if (picking) {
        EventPickerSheet(search = viewModel::searchEvents, onPick = { viewModel.setEvent(it); picking = false }, onDismiss = { picking = false })
    }
}

@Composable
private fun ChannelCard(row: ChannelRow, highlighted: Boolean) {
    Surface(
        shape = MaterialTheme.shapes.medium,
        tonalElevation = if (highlighted) 4.dp else 1.dp,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(row.channel, style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.analytics_channel_goes_to, row.target), color = Muted)
            Text(stringResource(R.string.analytics_share_counts, formatCount(row.shares), formatCount(row.clicks)))
            ShortLinkRow(row.channel, row.url)
        }
    }
}

/**
 * Un link breve con i suoi strumenti: copia, condividi, QR. Il QR si apre
 * grande in un riquadro e si salva nelle immagini o si condivide.
 */
@Composable
internal fun ShortLinkRow(label: String, url: String) {
    val context = LocalContext.current
    var showQr by remember { mutableStateOf(false) }
    var copied by remember { mutableStateOf(false) }
    val chooser = stringResource(R.string.analytics_share_link)
    val qrAlt = stringResource(R.string.analytics_qr_alt, label)
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            val bitmap = remember(url) { ticketQr(url) }
            Image(
                bitmap.asImageBitmap(), contentDescription = qrAlt,
                modifier = Modifier.size(72.dp).clickable(role = Role.Button) { showQr = true },
            )
            Column(Modifier.weight(1f)) {
                Text(label, style = MaterialTheme.typography.titleSmall)
                Text(url, style = MaterialTheme.typography.bodySmall, color = Muted)
            }
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { copied = copyLink(context, label, url) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_copy_link)) }
            OutlinedButton(onClick = { shareText(context, url, chooser) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_share_link)) }
            OutlinedButton(onClick = { showQr = true }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_show_qr)) }
        }
        if (copied) Text(stringResource(R.string.analytics_link_copied), color = MaterialTheme.colorScheme.primary, modifier = Modifier)
    }
    if (showQr) QrDialog(label, url, onDismiss = { showQr = false })
}

/** Il QR grande, con «Salva il QR nelle immagini» e «Condividi il QR». */
@Composable
private fun QrDialog(label: String, url: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val bitmap = remember(url) { ticketQr(url) }
    var outcome by remember { mutableStateOf<Int?>(null) }
    val chooser = stringResource(R.string.analytics_qr_share)
    Dialog(onDismissRequest = onDismiss) {
        Surface(shape = MaterialTheme.shapes.large) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(label, style = MaterialTheme.typography.titleLarge)
                Image(bitmap.asImageBitmap(), contentDescription = stringResource(R.string.analytics_qr_alt, label), modifier = Modifier.size(260.dp))
                Text(url, style = MaterialTheme.typography.bodySmall, color = Muted)
                if (QrImages.canSave) Button(onClick = { outcome = if (QrImages.save(context, bitmap, qrName(label))) R.string.analytics_qr_saved else R.string.analytics_qr_failed },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_qr_save)) }
                OutlinedButton(onClick = { if (!QrImages.share(context, bitmap, qrName(label), chooser)) outcome = R.string.analytics_qr_failed },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_qr_share)) }
                outcome?.let { Text(stringResource(it), color = if (it == R.string.analytics_qr_saved) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error) }
                TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.analytics_close)) }
            }
        }
    }
}

/** Il nome del file del QR: «qr-volantino-estate». */
internal fun qrName(label: String): String =
    "qr-" + label.lowercase().map { if (it.isLetterOrDigit()) it else '-' }.joinToString("").replace(Regex("-+"), "-").trim('-').ifBlank { "link" }.take(40)

private fun copyLink(context: Context, label: String, url: String): Boolean {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return false
    clipboard.setPrimaryClip(ClipData.newPlainText(label, url))
    return true
}

private fun shareText(context: Context, text: String, chooser: String) {
    val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text)
    try { context.startActivity(Intent.createChooser(send, chooser)) } catch (_: ActivityNotFoundException) { }
}

/**
 * Il QR come immagine PNG: nelle immagini del telefono (da Android 10, senza
 * permessi) oppure condiviso dalla cache dell'app, come i file della gestione.
 */
internal object QrImages {
    val canSave: Boolean get() = Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q

    fun save(context: Context, bitmap: Bitmap, name: String): Boolean {
        if (!canSave) return false
        val resolver = context.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Images.Media.DISPLAY_NAME, "$name.png")
            put(MediaStore.Images.Media.MIME_TYPE, "image/png")
            put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/inCitta")
            put(MediaStore.Images.Media.IS_PENDING, 1)
        }
        val uri = runCatching { resolver.insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values) }.getOrNull() ?: return false
        val written = runCatching { resolver.openOutputStream(uri)?.use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) } == true }.getOrDefault(false)
        if (!written) {
            runCatching { resolver.delete(uri, null, null) }
            return false
        }
        values.clear()
        values.put(MediaStore.Images.Media.IS_PENDING, 0)
        runCatching { resolver.update(uri, values, null, null) }
        return true
    }

    fun share(context: Context, bitmap: Bitmap, name: String, chooser: String): Boolean {
        val directory = ManagementFiles.directory(context.cacheDir).apply { mkdirs() }
        runCatching { ManagementFiles.purge(context.cacheDir) }
        val file = File(directory, "$name.png")
        val written = runCatching { file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) } }.getOrDefault(false)
        return written && SharedFiles.share(context, file, "image/png", chooser)
    }
}
