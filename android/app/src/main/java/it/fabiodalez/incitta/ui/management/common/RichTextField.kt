package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted

/**
 * Il testo formattato della gestione (descrizione dell'evento, dettagli di
 * «Prima di andare», informazioni editoriali): un campo di testo con una barra
 * per grassetto, corsivo, titolo, elenchi e link. `value` è il testo con i
 * segni di `RichText`; chi lo usa lo converte in HTML al salvataggio.
 *
 * Editor minimo scritto a mano (decisione 4 del piano): nessuna libreria
 * nuova, e la formattazione del sito sopravvive al salvataggio dal telefono.
 */
@Composable
internal fun RichTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier.fillMaxWidth(),
    error: String? = null,
    help: String? = null,
    minLines: Int = 4,
    toolbar: Boolean = true,
) {
    var field by remember { mutableStateOf(TextFieldValue(value)) }
    // Un valore cambiato da fuori (import da Facebook, modulo ricaricato) prende il posto del testo.
    if (field.text != value) field = TextFieldValue(value, TextRange(value.length))
    var askLink by remember { mutableStateOf(false) }

    fun update(next: TextFieldValue) {
        field = next
        if (next.text != value) onValueChange(next.text)
    }

    Column(modifier, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (toolbar) {
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                ToolbarButton(stringResource(R.string.management_rich_bold)) { update(RichTextEdits.wrap(field, "**")) }
                ToolbarButton(stringResource(R.string.management_rich_italic)) { update(RichTextEdits.wrap(field, "*")) }
                ToolbarButton(stringResource(R.string.management_rich_heading)) { update(RichTextEdits.prefixLines(field, "## ")) }
                ToolbarButton(stringResource(R.string.management_rich_bullets)) { update(RichTextEdits.prefixLines(field, "- ")) }
                ToolbarButton(stringResource(R.string.management_rich_numbers)) { update(RichTextEdits.numberLines(field)) }
                ToolbarButton(stringResource(R.string.management_rich_link)) { askLink = true }
            }
        }
        OutlinedTextField(
            value = field,
            onValueChange = ::update,
            label = { Text(label) },
            minLines = minLines,
            isError = error != null,
            supportingText = (error ?: help)?.let { text -> { Text(text) } },
            modifier = Modifier.fillMaxWidth(),
        )
        if (toolbar) Text(stringResource(R.string.management_rich_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
    }

    if (askLink) {
        var url by remember { mutableStateOf("https://") }
        AlertDialog(
            onDismissRequest = { askLink = false },
            title = { Text(stringResource(R.string.management_rich_link_title)) },
            text = {
                OutlinedTextField(
                    value = url,
                    onValueChange = { url = it },
                    label = { Text(stringResource(R.string.management_rich_link_url)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                )
            },
            confirmButton = {
                TextButton(enabled = RichTextEdits.validUrl(url), onClick = { askLink = false; update(RichTextEdits.link(field, url.trim())) }) {
                    Text(stringResource(R.string.management_rich_link_add))
                }
            },
            dismissButton = { TextButton(onClick = { askLink = false }) { Text(stringResource(R.string.management_cancel)) } },
        )
    }
}

@Composable
private fun ToolbarButton(label: String, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 12.dp)) { Text(label) }
}

/** Le modifiche della barra, come funzioni pure (le prova un test unitario). */
internal object RichTextEdits {
    /** Racchiude la selezione fra due segni; senza selezione li inserisce e mette il cursore in mezzo. */
    fun wrap(value: TextFieldValue, mark: String): TextFieldValue {
        val start = value.selection.min
        val end = value.selection.max
        val text = value.text
        val next = text.substring(0, start) + mark + text.substring(start, end) + mark + text.substring(end)
        return TextFieldValue(next, if (start == end) TextRange(start + mark.length) else TextRange(start, end + mark.length * 2))
    }

    /** Mette `prefix` all'inizio di ogni riga toccata dalla selezione (titolo, elenco puntato). */
    fun prefixLines(value: TextFieldValue, prefix: String): TextFieldValue = mapLines(value) { _, line -> if (line.startsWith(prefix)) line else prefix + line }

    /** Numera le righe toccate dalla selezione: `1. `, `2. `… */
    fun numberLines(value: TextFieldValue): TextFieldValue = mapLines(value) { index, line -> "${index + 1}. " + line.replace(Regex("^\\d+[.)]\\s+"), "") }

    /** Trasforma la selezione in un link; senza selezione usa l'indirizzo come testo. */
    fun link(value: TextFieldValue, url: String): TextFieldValue {
        val start = value.selection.min
        val end = value.selection.max
        val label = value.text.substring(start, end).ifBlank { url }
        val inserted = "[$label]($url)"
        val next = value.text.substring(0, start) + inserted + value.text.substring(end)
        return TextFieldValue(next, TextRange(start + inserted.length))
    }

    fun validUrl(url: String): Boolean = Regex("^https?://[^\\s()]+\\.[^\\s()]+$").matches(url.trim())

    private fun mapLines(value: TextFieldValue, transform: (Int, String) -> String): TextFieldValue {
        val text = value.text
        val lineStart = text.lastIndexOf('\n', (value.selection.min - 1).coerceAtLeast(0)).let { if (value.selection.min == 0 || it < 0) 0 else it + 1 }
        val lineEnd = text.indexOf('\n', value.selection.max).let { if (it < 0) text.length else it }
        val block = text.substring(lineStart, lineEnd)
        val changed = block.split('\n').mapIndexed(transform).joinToString("\n")
        val next = text.substring(0, lineStart) + changed + text.substring(lineEnd)
        return TextFieldValue(next, TextRange(lineStart + changed.length))
    }
}
