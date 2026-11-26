package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold

/**
 * La schermata di un'area non ancora portata nell'app: che cosa conterrà e
 * il pulsante che apre la stessa pagina del pannello sul sito. Ogni grafo
 * vuoto delle fondamenta la mostra; il pacchetto la sostituisce.
 */
@Composable
internal fun EditorialComingSoonScreen(area: EditorialArea, context: EditorialGraphContext) {
    ManagementScaffold(title = stringResource(area.title), padding = context.padding, onBack = context::back, subtitle = stringResource(area.help)) {
        Text(stringResource(R.string.editorial_coming_title), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_coming_text), color = Muted)
        Button(onClick = { context.openWeb(area.webPath) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
            Text(stringResource(R.string.editorial_open_web), Modifier.padding(start = 8.dp))
        }
    }
}
