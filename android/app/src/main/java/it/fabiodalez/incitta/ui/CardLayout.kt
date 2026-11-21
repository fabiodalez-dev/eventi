package it.fabiodalez.incitta.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ViewList
import androidx.compose.material.icons.outlined.GridView
import androidx.compose.material.icons.outlined.ViewAgenda
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.cardRows

/*
 * La vista degli elenchi scelta dalla persona, la stessa del sito: la mette
 * `InCittaApp` dallo stato, la leggono `EventRow` e gli elenchi qui sotto.
 */
val LocalCardLayout = staticCompositionLocalOf { "full" }

/** Chi cambia la vista: il ViewModel, che la salva anche nel profilo. */
val LocalCardLayoutChange = staticCompositionLocalOf<(String) -> Unit> { {} }

/** Il selettore sopra un elenco, con la vista in uso. */
@Composable
fun CardLayoutBar(modifier: Modifier = Modifier) {
    CardLayoutPicker(LocalCardLayout.current, LocalCardLayoutChange.current, modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 10.dp))
}

/** Il selettore delle tre viste, un gruppo radio come sul sito. */
@Composable
fun CardLayoutPicker(layout: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    val options = listOf(
        Triple("full", Icons.Outlined.ViewAgenda, stringResource(R.string.card_layout_full)),
        Triple("grid", Icons.Outlined.GridView, stringResource(R.string.card_layout_grid)),
        Triple("list", Icons.AutoMirrored.Outlined.ViewList, stringResource(R.string.card_layout_list)),
    )
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp, Alignment.End)) {
        Text(stringResource(R.string.card_layout_label), color = Muted, style = MaterialTheme.typography.labelMedium)
        /* Ritagliato sulla forma del bordo: la voce scelta si colora dentro
           gli angoli tondi, non oltre. */
        Row(Modifier.selectableGroup().clip(ControlShape).border(1.dp, Rule, ControlShape)) {
            options.forEach { (value, icon, label) ->
                val active = layout == value
                androidx.compose.foundation.layout.Box(
                    Modifier.size(48.dp)
                        .background(if (active) Paper else androidx.compose.ui.graphics.Color.Transparent)
                        .selectable(selected = active, role = Role.RadioButton, onClick = { onSelect(value) })
                        .semantics { contentDescription = label },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(icon, contentDescription = null, tint = if (active) Ink else Paper)
                }
            }
        }
    }
}

/** Un elenco di date dentro una colonna normale (pannelli, schede). */
@Composable
fun EventCards(events: List<Occurrence>, savedIds: Set<Long>, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    val layout = LocalCardLayout.current
    cardRows(events, layout).forEach { row -> EventCardRow(row, layout, savedIds, onOpen, onSave) }
}

/**
 * Un elenco di date in un `LazyColumn`: nella griglia due per riga, con la
 * chiave della prima, altrimenti una per riga. `below` è quello che una
 * schermata mette sotto ogni card (i salvati: chi vede la data).
 */
fun LazyListScope.eventCards(
    events: List<Occurrence>,
    layout: String,
    savedIds: Set<Long>,
    onOpen: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
    rowModifier: Modifier = Modifier,
    below: (@Composable (Occurrence) -> Unit)? = null,
) {
    items(cardRows(events, layout), key = { row -> row.joinToString("-") { it.occurrenceId.toString() } }) { row ->
        EventCardRow(row, layout, savedIds, onOpen, onSave, rowModifier, below)
    }
}

@Composable
private fun EventCardRow(
    row: List<Occurrence>,
    layout: String,
    savedIds: Set<Long>,
    onOpen: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
    modifier: Modifier = Modifier,
    below: (@Composable (Occurrence) -> Unit)? = null,
) {
    if (layout != "grid") {
        Column(modifier.fillMaxWidth()) {
            row.forEach { event -> EventRow(event, event.occurrenceId in savedIds, onOpen, onSave, layout = layout); below?.invoke(event) }
        }
        return
    }
    Row(modifier.fillMaxWidth().padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        row.forEach { event ->
            Column(Modifier.weight(1f)) {
                EventRow(event, event.occurrenceId in savedIds, onOpen, onSave, layout = "grid")
                below?.invoke(event)
            }
        }
        if (row.size == 1) Spacer(Modifier.weight(1f))
    }
    Spacer(Modifier.height(12.dp))
}
