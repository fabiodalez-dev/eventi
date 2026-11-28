package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.editorialSocialPreviewPath
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_SELECTION
import it.fabiodalez.incitta.data.management.SOCIAL_TITLE_MAX
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.CityPicker
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.social.AuthorizedImage
import it.fabiodalez.incitta.ui.management.social.CalendarGrid
import it.fabiodalez.incitta.ui.management.social.CatalogCard
import it.fabiodalez.incitta.ui.management.social.CheckRow
import it.fabiodalez.incitta.ui.management.social.DateButton
import it.fabiodalez.incitta.ui.management.social.PERIOD_LABELS
import it.fabiodalez.incitta.ui.management.social.RadioRow
import it.fabiodalez.incitta.ui.management.social.RecentRow
import it.fabiodalez.incitta.ui.management.social.SectionTitle
import it.fabiodalez.incitta.ui.management.social.formatLabel
import it.fabiodalez.incitta.ui.management.social.socialLongDayLabel
import java.time.LocalDate

/*
 * Lo studio della redazione, nei tre passi della pagina «Social» del
 * pannello (scegli le serate, formato e stile, grafiche pronte), con i pezzi
 * dello studio del locale (`ui/management/social`). In cima le tre porte che
 * nel pannello stanno intorno allo studio: «Social di oggi» del riepilogo,
 * lo storico delle pubblicazioni e «Collega account e programma».
 */
@Composable
internal fun EditorialSocialStudioScreen(
    viewModel: EditorialSocialStudioViewModel,
    padding: PaddingValues,
    token: String,
    onBack: () -> Unit,
    onOpenBatch: (String) -> Unit,
    onOpenToday: () -> Unit,
    onOpenHistory: () -> Unit,
    onOpenSettings: () -> Unit,
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
        title = stringResource(R.string.social_title),
        padding = padding,
        onBack = onBack,
        subtitle = desk?.city?.name,
        busy = state.generating || state.switchingCity,
    ) {
        Text(stringResource(R.string.editorial_social_lead))
        desk?.let { current ->
            CityPicker(current.cities, current.city?.slug, onSelect = { viewModel.selectCity(it.slug) }, enabled = !state.switchingCity)
        }
        HubLink(stringResource(R.string.editorial_social_today_card), stringResource(R.string.editorial_social_today_card_help), onOpenToday)
        HubLink(stringResource(R.string.editorial_social_history), stringResource(R.string.editorial_social_history_help), onOpenHistory)
        HubLink(stringResource(R.string.editorial_social_settings), stringResource(R.string.editorial_social_settings_help), onOpenSettings)
        HorizontalDivider()
        state.event?.let { event ->
            Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(stringResource(R.string.social_only_event, event.title))
                    OutlinedButton(onClick = viewModel::clearEvent, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_clear_event)) }
                }
            }
        }
        ChooseSection(state, viewModel)
        HorizontalDivider()
        StyleSection(state, token, viewModel)
        HorizontalDivider()
        SectionTitle(stringResource(R.string.social_ready))
        Text(stringResource(R.string.social_ready_hint), color = Muted)
        LoadState(state.recent, onRetry = viewModel::retryRecent, emptyText = stringResource(R.string.social_ready_empty)) { list ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                list.forEach { batch -> RecentRow(batch, state.zone) { onOpenBatch(batch.id) } }
            }
        }
    }
}

/** Una porta dello studio verso un'altra schermata dell'area. */
@Composable
internal fun HubLink(title: String, help: String, onClick: () -> Unit) {
    OutlinedCard(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Text(help, color = Muted, style = MaterialTheme.typography.bodyMedium)
            }
            Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = null)
        }
    }
}

// ---------------------------------------------------------------- 1. Scegli le serate

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChooseSection(state: EditorialStudioUiState, viewModel: EditorialSocialStudioViewModel) {
    val zone = state.zone
    SectionTitle(stringResource(R.string.social_choose))
    Text(stringResource(R.string.social_choose_hint), color = Muted)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        PERIOD_LABELS.forEach { (value, label) ->
            FilterChip(selected = state.period == value, onClick = { viewModel.setPeriod(value) }, label = { Text(stringResource(label)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
    if (state.period == "custom") {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            DateButton(stringResource(R.string.social_from), state.from, zone, Modifier.weight(1f)) { viewModel.setRange(it, null) }
            DateButton(stringResource(R.string.social_until), state.until, zone, Modifier.weight(1f)) { viewModel.setRange(null, it) }
        }
    }
    OutlinedTextField(
        value = state.search,
        onValueChange = viewModel::setSearch,
        label = { Text(stringResource(R.string.social_search)) },
        placeholder = { Text(stringResource(R.string.social_search_placeholder)) },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
    LoadState(state.calendar, onRetry = viewModel::retryCalendar) { calendar ->
        CalendarGrid(calendar, state.date, onShift = viewModel::shiftMonth, onPick = viewModel::pickDay)
    }
    LoadState(state.catalog, onRetry = viewModel::retryCatalog) { page ->
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(
                if (page.meta.total == 0) stringResource(R.string.social_results_none) else pluralStringResource(R.plurals.social_results, page.meta.total, page.meta.total),
                style = MaterialTheme.typography.titleSmall,
            )
            if (page.data.isEmpty()) Text(stringResource(R.string.social_empty), color = Muted)
            page.data.forEach { card ->
                CatalogCard(card, picked = card.businessDate == state.date && card.id in state.selected, zone = zone) { viewModel.pick(card) }
            }
            if (page.hasMore) OutlinedButton(onClick = viewModel::showMore, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.social_more)) }
        }
    }
}

// ---------------------------------------------------------------- 2. Formato e stile

@Composable
private fun StyleSection(state: EditorialStudioUiState, token: String, viewModel: EditorialSocialStudioViewModel) {
    SectionTitle(stringResource(R.string.social_style))
    Text(stringResource(R.string.social_style_hint), color = Muted)
    val dayLabel = runCatching { LocalDate.parse(state.date) }.getOrNull()?.let(::socialLongDayLabel) ?: "—"
    Text(stringResource(R.string.social_day), style = MaterialTheme.typography.labelLarge, color = Muted)
    Text(dayLabel, style = MaterialTheme.typography.titleMedium)
    val count = state.chosen.size
    Text(if (count == 0) stringResource(R.string.social_selected_none) else pluralStringResource(R.plurals.social_selected_count, count, count), style = MaterialTheme.typography.titleSmall)
    LoadState(state.day, onRetry = viewModel::retryDay) { day ->
        if (day.data.isEmpty() || count == 0) Text(stringResource(R.string.social_nothing_selected), color = Muted)
        if (day.data.isNotEmpty()) {
            val selectionLabel = stringResource(R.string.social_selection)
            LazyRow(Modifier.fillMaxWidth().semantics { contentDescription = selectionLabel }, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                items(day.data, key = { it.id }) { card ->
                    EditorialPreviewTile(card, token, picked = card.id in state.selected) { viewModel.toggle(card.id) }
                }
            }
            if (day.data.size > 1) OutlinedButton(onClick = viewModel::selectAll, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_select_all)) }
        }
    }
    SocialOptionsForm(state.options, state.errors, onChange = viewModel::setOptions)
    if (count > SOCIAL_MAX_SELECTION) Text(stringResource(R.string.social_too_many, SOCIAL_MAX_SELECTION), color = MaterialTheme.colorScheme.error)
    Button(enabled = state.canGenerate, onClick = viewModel::generate, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(if (state.generating) R.string.social_generating else R.string.social_generate))
    }
}

/** L'anteprima JPEG della grafica di una serata del giorno (con il token), con la casella per sceglierla. */
@Composable
internal fun EditorialPreviewTile(card: SocialCard, token: String, picked: Boolean?, onToggle: () -> Unit = {}) {
    Column(Modifier.width(150.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        AuthorizedImage(
            url = BuildConfig.API_BASE_URL + editorialSocialPreviewPath(card.id),
            token = token,
            description = stringResource(R.string.social_preview_alt, card.title),
            modifier = Modifier.fillMaxWidth().aspectRatio(1080f / 1350f),
        )
        if (picked == null) {
            Text(card.title, style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
        } else {
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(value = picked, role = Role.Checkbox, onValueChange = { onToggle() }),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Checkbox(checked = picked, onCheckedChange = null)
                Text(card.title, style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/** Formato, inquadratura, le tre caselle e il titolo alternativo, come lo studio del locale. */
@Composable
private fun SocialOptionsForm(options: SocialOptions, errors: FormErrors, onChange: (SocialOptions) -> Unit) {
    Text(stringResource(R.string.social_format), style = MaterialTheme.typography.titleSmall)
    Column {
        SocialFormatChoice.entries.forEach { format ->
            RadioRow(stringResource(formatLabel(format)), options.format == format) { onChange(options.copy(format = format)) }
        }
    }
    Text(stringResource(R.string.social_image_fit), style = MaterialTheme.typography.titleSmall)
    Column {
        RadioRow(stringResource(R.string.social_contain), !options.cover) { onChange(options.copy(cover = false)) }
        RadioRow(stringResource(R.string.social_cover), options.cover) { onChange(options.copy(cover = true)) }
    }
    Text(stringResource(R.string.social_options), style = MaterialTheme.typography.titleSmall)
    Column {
        CheckRow(stringResource(R.string.social_monochrome), options.monochrome) { onChange(options.copy(monochrome = it)) }
        CheckRow(stringResource(R.string.social_address), options.showAddress) { onChange(options.copy(showAddress = it)) }
        CheckRow(stringResource(R.string.social_price), options.showPrice) { onChange(options.copy(showPrice = it)) }
    }
    val titleError = errors.field("title")
    OutlinedTextField(
        value = options.title,
        onValueChange = { onChange(options.copy(title = it)) },
        label = { Text(stringResource(R.string.social_short_title)) },
        supportingText = { Text(titleError ?: stringResource(R.string.social_title_counter, options.title.length, SOCIAL_TITLE_MAX)) },
        isError = titleError != null,
        modifier = Modifier.fillMaxWidth(),
    )
    errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    listOf("format", "image_fit").mapNotNull(errors::field).forEach { Text(it, color = MaterialTheme.colorScheme.error) }
}
