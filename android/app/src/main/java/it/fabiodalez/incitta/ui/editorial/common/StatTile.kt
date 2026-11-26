package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.Rule

/**
 * Un numero con la sua etichetta, come i riquadri del riepilogo del
 * pannello (eventi in attesa, pubblicati oggi…). Con `onClick` il riquadro
 * apre l'elenco già filtrato; `alert` lo colora quando c'è qualcosa da fare.
 * Lo legge lo screen reader come una frase sola: «12, Eventi in attesa».
 */
@Composable
internal fun StatTile(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    detail: String? = null,
    alert: Boolean = false,
    onClick: (() -> Unit)? = null,
) {
    val spoken = listOfNotNull(value, label, detail).joinToString(", ")
    OutlinedCard(
        modifier = modifier
            .heightIn(min = 72.dp)
            .then(if (onClick != null) Modifier.clickable(role = Role.Button, onClick = onClick) else Modifier)
            .semantics(mergeDescendants = true) { contentDescription = spoken },
        border = BorderStroke(1.dp, if (alert) MaterialTheme.colorScheme.primary else Rule),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(value, style = MaterialTheme.typography.headlineMedium, color = if (alert) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
            Text(label, style = MaterialTheme.typography.titleSmall)
            detail?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
    }
}
