package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.requestFailureMessage

/**
 * Gli errori di un modulo: quelli di `error.fields` del server vanno sotto il
 * campo che li riguarda, il resto in cima al modulo. Un campo del server che
 * il modulo non mostra non deve perdersi: finisce in cima.
 */
internal data class FormErrors(val general: String? = null, val fields: Map<String, String> = emptyMap()) {
    val isEmpty: Boolean get() = general == null && fields.isEmpty()

    /** L'errore del campo, anche quando il server lo segnala su una sua parte (`practical_details.entrance_notes`). */
    fun field(key: String): String? = fields[key] ?: fields.entries.firstOrNull { it.key.startsWith("$key.") }?.value

    companion object {
        val None = FormErrors()

        /** `fieldKeys` sono i campi che il modulo mostra, con i nomi del server. */
        fun from(error: Throwable, fieldKeys: Collection<String>): FormErrors {
            val problem = (error as? ApiException)?.problem
            if (error !is ApiException || error.status != 422 || problem == null || problem.fields.isEmpty()) {
                return FormErrors(general = requestFailureMessage(error))
            }
            val shown = mutableMapOf<String, String>()
            val other = mutableListOf<String>()
            problem.fields.forEach { (key, messages) ->
                val message = messages.firstOrNull()?.takeIf { it.isNotBlank() } ?: return@forEach
                val owner = fieldKeys.firstOrNull { key == it || key.startsWith("$it.") }
                if (owner != null) shown.putIfAbsent(owner, message) else other += message
            }
            val general = other.distinct().joinToString("\n").ifBlank { null }
                ?: if (shown.isEmpty()) requestFailureMessage(error) else null
            return FormErrors(general, shown)
        }
    }
}

/**
 * Un modulo della gestione: errore generale in cima (o «controlla i campi
 * segnati» se gli errori sono tutti sui campi), i campi, il pulsante da 48 dp.
 */
@Composable
internal fun FormScaffold(
    errors: FormErrors,
    busy: Boolean,
    submitLabel: String,
    onSubmit: () -> Unit,
    submitEnabled: Boolean = true,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val general = errors.general ?: if (errors.fields.isNotEmpty()) stringResource(R.string.management_form_fix) else null
        general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        content()
        Button(enabled = !busy && submitEnabled, onClick = onSubmit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(submitLabel)
        }
    }
}

/** Un campo di testo che mostra sotto di sé l'errore del server per `field`. */
@Composable
internal fun FormTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    errors: FormErrors,
    field: String,
    modifier: Modifier = Modifier.fillMaxWidth(),
    enabled: Boolean = true,
    singleLine: Boolean = false,
    minLines: Int = 1,
    help: String? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
) {
    val error = errors.field(field)
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        enabled = enabled,
        singleLine = singleLine,
        minLines = minLines,
        isError = error != null,
        supportingText = (error ?: help)?.let { text -> { Text(text) } },
        keyboardOptions = keyboardOptions,
        modifier = modifier,
    )
}
