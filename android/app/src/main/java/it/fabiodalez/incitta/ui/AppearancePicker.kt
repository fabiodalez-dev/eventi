package it.fabiodalez.incitta.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

@Composable
fun AppearancePicker(appearance: String, saving: Boolean, authenticated: Boolean, onSelect: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Aspetto", style = MaterialTheme.typography.titleLarge)
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            listOf("dark" to "Scuro", "light" to "Chiaro").forEach { (value, label) ->
                val active = appearance == value
                OutlinedButton(
                    onClick = { onSelect(value) }, enabled = !saving,
                    modifier = Modifier.weight(1f).heightIn(min = 64.dp).semantics { selected = active },
                    shape = RoundedCornerShape(6.dp),
                    border = BorderStroke(if (active) 2.dp else 1.dp, if (active) Acid else Rule),
                    contentPadding = PaddingValues(12.dp),
                ) {
                    Box(Modifier.size(20.dp).background(if (value == "light") Color(0xFFFAF9F6) else Color(0xFF0B0B0B), RoundedCornerShape(4.dp)))
                    Spacer(Modifier.width(8.dp))
                    Text(if (active) "$label ✓" else label, color = Paper)
                }
            }
        }
        Text(if (saving) "Salvataggio…" else if (authenticated) "Salvato nel profilo, anche sul sito e sugli altri dispositivi." else "La scelta resta salvata su questo dispositivo.", color = Muted, style = MaterialTheme.typography.bodyMedium)
    }
}
