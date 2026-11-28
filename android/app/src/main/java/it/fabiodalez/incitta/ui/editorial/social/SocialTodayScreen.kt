package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.CityPicker
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold

/*
 * «Social di oggi» (R-DB-10), il riquadro in cima al riepilogo del pannello
 * per l'amministrazione: le serate di oggi con l'anteprima della grafica e
 * «Scarica le grafiche di oggi». Nel pannello il pulsante porta subito allo
 * ZIP; qui apre il pacchetto appena preparato, da cui lo ZIP si condivide
 * o si apre con un'altra app.
 */
@Composable
internal fun SocialTodayScreen(
    viewModel: SocialTodayViewModel,
    padding: PaddingValues,
    token: String,
    onBack: () -> Unit,
    onOpenBatch: (String) -> Unit,
    onOpenStudio: () -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val desk by viewModel.desk.collectAsStateWithLifecycle()
    LaunchedEffect(state.prepared) {
        state.prepared?.let { id ->
            viewModel.consumePrepared()
            onOpenBatch(id)
        }
    }
    ManagementScaffold(
        title = stringResource(R.string.editorial_social_today_title),
        padding = padding,
        onBack = onBack,
        subtitle = desk?.city?.name,
        busy = state.preparing,
    ) {
        Text(stringResource(R.string.editorial_social_today_card))
        desk?.let { current -> CityPicker(current.cities, current.city?.slug, onSelect = { viewModel.selectCity(it.slug) }) }
        LoadState(state.today, onRetry = viewModel::load, emptyText = stringResource(R.string.editorial_social_today_empty)) { today ->
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(today.meta.label, style = MaterialTheme.typography.titleMedium)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(today.data, key = { it.id }) { card -> EditorialPreviewTile(card, token, picked = null) }
                }
                state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                Button(enabled = !state.preparing, onClick = viewModel::prepare, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(if (state.preparing) R.string.editorial_social_today_preparing else R.string.editorial_social_today_download))
                }
            }
        }
        Text(stringResource(R.string.editorial_social_city_hint), color = Muted)
        OutlinedButton(onClick = onOpenStudio, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.editorial_social_today_open_studio))
        }
    }
}
