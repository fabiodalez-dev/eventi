package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted

/** Lo stato di un dato da caricare: in corso, pronto, vuoto, non riuscito. */
internal sealed interface Loadable<out T> {
    data object Loading : Loadable<Nothing>
    data class Ready<T>(val value: T) : Loadable<T>
    data object Empty : Loadable<Nothing>
    data class Failed(val message: String) : Loadable<Nothing>
}

internal val <T> Loadable<T>.valueOrNull: T? get() = (this as? Loadable.Ready<T>)?.value

/**
 * I quattro stati con lo stesso aspetto in tutte le aree: una barra mentre
 * carica, il contenuto, un testo per il vuoto (ogni schermata dice il suo),
 * l'errore con «Riprova».
 */
@Composable
internal fun <T> LoadState(
    state: Loadable<T>,
    onRetry: () -> Unit,
    emptyText: String = stringResource(R.string.management_empty_default),
    content: @Composable (T) -> Unit,
) {
    when (state) {
        Loadable.Loading -> {
            val loading = stringResource(R.string.management_loading)
            LinearProgressIndicator(Modifier.fillMaxWidth().semantics { contentDescription = loading })
        }
        is Loadable.Ready -> content(state.value)
        Loadable.Empty -> Text(emptyText, color = Muted)
        is Loadable.Failed -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(state.message, color = MaterialTheme.colorScheme.error)
            OutlinedButton(onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
        }
    }
}
