package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted

/**
 * Il motivo obbligatorio di un rifiuto, di una sospensione, di un
 * annullamento: da 5 a 1000 caratteri, come i moduli del pannello. I tetti
 * arrivano da `GET editorial/options` (`limits.reason_min`, `reason_max`).
 */
internal data class ReasonRules(val min: Int = DEFAULT_MIN, val max: Int = DEFAULT_MAX) {
    /** Il problema del testo così com'è, o `null` se va bene. Gli spazi ai bordi non contano. */
    fun problem(text: String): ReasonProblem? {
        val length = text.trim().length
        return when {
            length < min -> ReasonProblem.TooShort
            length > max -> ReasonProblem.TooLong
            else -> null
        }
    }

    fun accepts(text: String): Boolean = problem(text) == null

    companion object {
        const val DEFAULT_MIN = 5
        const val DEFAULT_MAX = 1000
    }
}

internal enum class ReasonProblem { TooShort, TooLong }

/**
 * La finestra con il motivo. «Conferma» resta spento finché il motivo non è
 * valido; il testo scritto sopravvive alla rotazione. `error` è il messaggio
 * del server (422) da mostrare sotto il campo.
 */
@Composable
internal fun ReasonDialog(
    title: String,
    text: String,
    confirmLabel: String,
    onConfirm: (String) -> Unit,
    onDismiss: () -> Unit,
    rules: ReasonRules = ReasonRules(),
    destructive: Boolean = false,
    busy: Boolean = false,
    error: String? = null,
    fieldLabel: String = stringResource(R.string.editorial_reason_field),
) {
    var reason by rememberSaveable { mutableStateOf("") }
    var touched by rememberSaveable { mutableStateOf(false) }
    val problem = rules.problem(reason)
    val message = error ?: if (touched) when (problem) {
        ReasonProblem.TooShort -> stringResource(R.string.editorial_reason_too_short, rules.min)
        ReasonProblem.TooLong -> stringResource(R.string.editorial_reason_too_long, rules.max)
        null -> null
    } else null
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(text)
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it; touched = true },
                    label = { Text(fieldLabel) },
                    isError = message != null,
                    supportingText = {
                        Text(message ?: stringResource(R.string.editorial_reason_help, rules.min, rules.max), color = if (message != null) MaterialTheme.colorScheme.error else Muted)
                    },
                    minLines = 3,
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                )
                Text(stringResource(R.string.editorial_reason_counter, reason.trim().length, rules.max), color = Muted, style = MaterialTheme.typography.bodySmall)
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = problem == null && !busy, onClick = { onConfirm(reason.trim()) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(confirmLabel, color = if (destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
            }
        },
        dismissButton = {
            TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) }
        },
    )
}
