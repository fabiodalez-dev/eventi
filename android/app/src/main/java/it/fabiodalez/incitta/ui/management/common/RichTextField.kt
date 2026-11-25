package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.FormatListBulleted
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted

/**
 * Il testo formattato della gestione (descrizione e testi dell'evento,
 * profilo del locale e dell'organizzazione, istruzioni per chi prenota): un
 * campo di testo con la barra dei segni sopra. `value` è il testo con i segni
 * di `RichText`; chi lo usa lo converte in HTML al salvataggio con
 * `RichText.toHtml` e lo riceve con `RichText.toMarkup`.
 *
 * I pulsanti agiscono sulla selezione: senza selezione inseriscono la coppia
 * di segni dove sta il cursore. Ci sono tutti e soli i formati che il filtro
 * del sito tiene, quindi un testo scritto sul sito e salvato dal telefono non
 * perde la formattazione. `toolbar = false` per i campi corti (una risposta,
 * una voce del programma), dove i segni si possono comunque scrivere a mano.
 *
 * Editor minimo scritto a mano (decisione 4 del piano): nessuna libreria nuova.
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
    enabled: Boolean = true,
) {
    var field by remember { mutableStateOf(TextFieldValue(value)) }
    // Un valore cambiato da fuori (import da Facebook, modulo ricaricato) prende il posto del testo.
    if (field.text != value) field = TextFieldValue(value, TextRange(value.length))
    var linking by remember { mutableStateOf(false) }

    fun update(next: TextFieldValue) {
        field = next
        if (next.text != value) onValueChange(next.text)
    }

    Column(modifier, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (toolbar) {
            Row(Modifier.fillMaxWidth()) {
                MarkButton(Icons.Outlined.FormatBold, R.string.management_rich_bold, enabled) { update(RichTextEdits.wrap(field, "**")) }
                MarkButton(Icons.Outlined.FormatItalic, R.string.management_rich_italic, enabled) { update(RichTextEdits.wrap(field, "*")) }
                MarkButton(Icons.Outlined.FormatUnderlined, R.string.management_rich_underline, enabled) { update(RichTextEdits.wrap(field, "__")) }
                MarkButton(Icons.Outlined.FormatStrikethrough, R.string.management_rich_strike, enabled) { update(RichTextEdits.wrap(field, "~~")) }
                MarkButton(Icons.Outlined.Link, R.string.management_rich_link, enabled) { linking = true }
            }
            Row(Modifier.fillMaxWidth()) {
                MarkButton(Icons.Outlined.Title, R.string.management_rich_h2, enabled) { update(RichTextEdits.prefixLines(field, "## ")) }
                MarkButton(Icons.Outlined.TextFields, R.string.management_rich_h3, enabled) { update(RichTextEdits.prefixLines(field, "### ")) }
                MarkButton(Icons.Outlined.FormatQuote, R.string.management_rich_quote, enabled) { update(RichTextEdits.prefixLines(field, "> ")) }
                MarkButton(Icons.AutoMirrored.Outlined.FormatListBulleted, R.string.management_rich_bullets, enabled) { update(RichTextEdits.prefixLines(field, "- ")) }
                MarkButton(Icons.Outlined.FormatListNumbered, R.string.management_rich_numbers, enabled) { update(RichTextEdits.numberLines(field)) }
            }
        }
        OutlinedTextField(
            value = field,
            onValueChange = ::update,
            label = { Text(label) },
            minLines = minLines,
            enabled = enabled,
            isError = error != null,
            supportingText = (error ?: help)?.let { text -> { Text(text) } },
            modifier = Modifier.fillMaxWidth(),
        )
        if (toolbar) Text(stringResource(R.string.management_rich_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
    }

    if (linking) LinkDialog(
        initialText = field.text.substring(field.selection.min, field.selection.max),
        onDismiss = { linking = false },
        onAdd = { text, url -> linking = false; update(RichTextEdits.link(field, url, text)) },
    )
}

/** Lo stesso campo con l'errore del server preso da `errors` per la chiave `field`. */
@Composable
internal fun RichTextField(value: String, onValueChange: (String) -> Unit, label: String, errors: FormErrors, field: String, help: String? = null, minLines: Int = 5) =
    RichTextField(value, onValueChange, label, error = errors.field(field), help = help, minLines = minLines)

@Composable
private fun MarkButton(icon: ImageVector, label: Int, enabled: Boolean, onClick: () -> Unit) {
    IconButton(onClick = onClick, enabled = enabled, modifier = Modifier.size(48.dp)) { Icon(icon, contentDescription = stringResource(label)) }
}

@Composable
private fun LinkDialog(initialText: String, onDismiss: () -> Unit, onAdd: (String, String) -> Unit) {
    var text by remember { mutableStateOf(initialText) }
    var url by remember { mutableStateOf("https://") }
    val valid = RichTextEdits.validUrl(url)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.management_rich_link_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(text, { text = it }, label = { Text(stringResource(R.string.management_rich_link_text)) }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(url, { url = it }, label = { Text(stringResource(R.string.management_rich_link_url)) }, singleLine = true,
                    isError = !valid && url.length > 8, supportingText = { if (!valid) Text(stringResource(R.string.management_rich_link_invalid)) },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(enabled = valid, onClick = { onAdd(text, url.trim()) }) { Text(stringResource(R.string.management_rich_link_add)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.management_cancel)) } },
    )
}

/** Le modifiche della barra, come funzioni pure (le prova un test unitario). */
internal object RichTextEdits {
    /* Il segno di riga già presente (titolo, citazione, elenco): un pulsante di riga lo sostituisce. */
    private val LINE_MARK = Regex("^(#{1,3} |> |[-•] |\\d{1,3}[.)] )")

    /** Racchiude la selezione fra due segni; senza selezione li inserisce e mette il cursore in mezzo. */
    fun wrap(value: TextFieldValue, mark: String): TextFieldValue {
        val start = value.selection.min
        val end = value.selection.max
        val text = value.text
        val next = text.substring(0, start) + mark + text.substring(start, end) + mark + text.substring(end)
        return TextFieldValue(next, if (start == end) TextRange(start + mark.length) else TextRange(start, end + mark.length * 2))
    }

    /**
     * Mette `prefix` all'inizio di ogni riga toccata dalla selezione (titolo,
     * citazione, elenco puntato). Una riga che lo ha già resta com'è; un altro
     * segno di riga viene sostituito, così un elenco diventa un titolo e non
     * «## - voce».
     */
    fun prefixLines(value: TextFieldValue, prefix: String): TextFieldValue =
        mapLines(value) { _, line -> if (line.startsWith(prefix)) line else prefix + line.replace(LINE_MARK, "") }

    /** Numera le righe toccate dalla selezione: `1. `, `2. `… al posto di un altro segno di riga. */
    fun numberLines(value: TextFieldValue): TextFieldValue = mapLines(value) { index, line -> "${index + 1}. " + line.replace(LINE_MARK, "") }

    /**
     * `[testo](indirizzo)` al posto della selezione. Senza testo usa
     * l'indirizzo; le parentesi quadre del testo si proteggono, gli spazi
     * dell'indirizzo diventano `%20`.
     */
    fun link(value: TextFieldValue, url: String, label: String? = null): TextFieldValue {
        val start = value.selection.min
        val end = value.selection.max
        val text = (label ?: value.text.substring(start, end)).ifBlank { url }
        val safe = text.replace("\\", "\\\\").replace("[", "\\[").replace("]", "\\]")
        val inserted = "[$safe](${url.trim().replace(" ", "%20")})"
        val next = value.text.substring(0, start) + inserted + value.text.substring(end)
        return TextFieldValue(next, TextRange(start + inserted.length))
    }

    /** Un indirizzo che il filtro del sito tiene: http o https con un dominio, oppure mailto con una chiocciola. */
    fun validUrl(url: String): Boolean {
        val clean = url.trim()
        return Regex("^https?://[^\\s()]+\\.[^\\s()]+$", RegexOption.IGNORE_CASE).matches(clean) ||
            Regex("^mailto:[^\\s()@]+@[^\\s()@]+$", RegexOption.IGNORE_CASE).matches(clean)
    }

    private fun mapLines(value: TextFieldValue, transform: (Int, String) -> String): TextFieldValue {
        val text = value.text
        val lineStart = if (value.selection.min == 0) 0 else text.lastIndexOf('\n', value.selection.min - 1) + 1
        val lineEnd = text.indexOf('\n', value.selection.max).let { if (it < 0) text.length else it }
        val block = text.substring(lineStart, lineEnd)
        val changed = block.split('\n').mapIndexed(transform).joinToString("\n")
        val next = text.substring(0, lineStart) + changed + text.substring(lineEnd)
        return TextFieldValue(next, TextRange(lineStart + changed.length))
    }
}
