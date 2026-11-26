package it.fabiodalez.incitta.ui.editorial.common

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialCity
import it.fabiodalez.incitta.ui.Muted

/**
 * Il selettore della città di lavoro in cima all'area (decisione 10):
 * nascosto finché la città è una sola. Elenca anche le città non ancora
 * attive, che la redazione prepara prima di accenderle.
 */
@Composable
internal fun CityPicker(cities: List<EditorialCity>, selectedSlug: String?, onSelect: (EditorialCity) -> Unit, enabled: Boolean = true) {
    if (cities.size < 2) return
    var open by remember { mutableStateOf(false) }
    val current = cities.firstOrNull { it.slug == selectedSlug } ?: cities.first()
    val switchLabel = stringResource(R.string.editorial_city_switch)
    Box {
        OutlinedButton(
            onClick = { open = true },
            enabled = enabled,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = switchLabel },
        ) {
            Text(stringResource(R.string.editorial_city_current, current.name), modifier = Modifier.weight(1f))
            Icon(Icons.Outlined.ExpandMore, contentDescription = null)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            cities.forEach { city ->
                DropdownMenuItem(
                    text = {
                        if (city.isActive) Text(city.name, style = MaterialTheme.typography.titleMedium)
                        else Text(stringResource(R.string.editorial_city_inactive, city.name), color = Muted)
                    },
                    onClick = { open = false; if (city.slug != current.slug) onSelect(city) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
    }
}
