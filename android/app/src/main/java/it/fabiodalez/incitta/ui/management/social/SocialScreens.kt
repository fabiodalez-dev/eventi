package it.fabiodalez.incitta.ui.management.social

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringArrayResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil3.compose.AsyncImage
import coil3.network.NetworkHeaders
import coil3.network.httpHeaders
import coil3.request.ImageRequest
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SOCIAL_MAX_SELECTION
import it.fabiodalez.incitta.data.management.SOCIAL_TITLE_MAX
import it.fabiodalez.incitta.data.management.SocialBatch
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCalendarDay
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.data.management.socialPreviewPath
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.EventPickerSheet
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.pickerDate
import it.fabiodalez.incitta.ui.management.common.pickerMillis
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.events.StatusBadge
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Le schermate delle grafiche social: lo studio, nei tre passi della pagina
 * «Social» (scegli le serate, formato e stile, grafiche pronte), e un
 * pacchetto preparato con le sue immagini.
 */

@Composable
internal fun SocialStudioScreen(viewModel: SocialStudioViewModel, context: ManagementGraphContext, onOpenBatch: (subjectKey: String, id: String) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var picking by remember { mutableStateOf(false) }
    LaunchedEffect(state.prepared) {
        state.prepared?.let { id ->
            viewModel.consumePrepared()
            state.venueSubject?.let { onOpenBatch(it.key, id) }
        }
    }
    ManagementScaffold(
        title = stringResource(R.string.social_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = state.venueSubject?.name,
        busy = state.generating,
    ) {
        Text(stringResource(R.string.social_lead))
        LoadState(state.venue, onRetry = viewModel::start, emptyText = stringResource(R.string.social_venue_missing)) { venue ->
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                EventFilter(state, onChoose = { picking = true }, onClear = viewModel::clearEvent)
                ChooseSection(state, venue, viewModel)
                HorizontalDivider()
                StyleSection(state, venue, context.repository.token, viewModel)
                HorizontalDivider()
                RecentSection(state, viewModel, onOpen = { id -> onOpenBatch(venue.key, id) })
            }
        }
    }
    if (picking) {
        EventPickerSheet(
            search = viewModel::searchEvents,
            onPick = { viewModel.setEvent(it); picking = false },
            onDismiss = { picking = false },
            title = stringResource(R.string.social_choose_event_title),
            hint = stringResource(R.string.social_choose_event_hint),
        )
    }
}

/** L'evento a cui è ristretto lo studio (W-SO-08), oppure il pulsante per sceglierne uno. */
@Composable
private fun EventFilter(state: SocialStudioUiState, onChoose: () -> Unit, onClear: () -> Unit) {
    val event = state.event
    if (event != null) {
        Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(stringResource(R.string.social_only_event, event.title))
                OutlinedButton(onClick = onClear, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_clear_event)) }
            }
        }
    } else {
        OutlinedButton(onClick = onChoose, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_choose_event)) }
    }
}

// ---------------------------------------------------------------- 1. Scegli le serate

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChooseSection(state: SocialStudioUiState, venue: ManagementSubject, viewModel: SocialStudioViewModel) {
    val zone = venue.timezone
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
            if (page.data.any { it.demo }) Text(stringResource(R.string.social_demo_hint), color = Muted)
        }
    }
}

/** «Dal» o «Al»: un pulsante con la data, che apre il calendario di sistema. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DateButton(label: String, value: String, zone: ZoneId, modifier: Modifier, onPick: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val date = runCatching { LocalDate.parse(value) }.getOrNull()
    Column(modifier, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.labelLarge)
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(date?.let(::socialDayLabel) ?: stringResource(R.string.social_pick_date))
        }
    }
    if (open) {
        val picker = rememberDatePickerState(initialSelectedDateMillis = pickerMillis(date ?: LocalDate.now(zone)))
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = { open = false; picker.selectedDateMillis?.let { onPick(pickerDate(it).toString()) } }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_datetime_done))
                }
            },
            dismissButton = { TextButton(onClick = { open = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = picker) }
    }
}

/** Il piccolo calendario del mese: i giorni con serate si toccano, gli altri no (W-SO-02). */
@Composable
private fun CalendarGrid(calendar: SocialCalendar, selected: String, onShift: (Int) -> Unit, onPick: (String) -> Unit) {
    val weekdays = stringArrayResource(R.array.social_weekdays)
    val calendarLabel = stringResource(R.string.social_calendar)
    Column(Modifier.fillMaxWidth().semantics { contentDescription = calendarLabel }, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { onShift(-1) }, modifier = Modifier.size(48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.KeyboardArrowLeft, contentDescription = stringResource(R.string.social_previous_month))
            }
            Text(calendar.meta.label, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center, modifier = Modifier.weight(1f).semantics { heading() })
            IconButton(onClick = { onShift(1) }, modifier = Modifier.size(48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = stringResource(R.string.social_next_month))
            }
        }
        Row(Modifier.fillMaxWidth().clearAndSetSemantics { }) {
            weekdays.forEach { Text(it, style = MaterialTheme.typography.labelSmall, color = Muted, textAlign = TextAlign.Center, modifier = Modifier.weight(1f)) }
        }
        calendar.data.chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                week.forEach { day -> CalendarCell(day, picked = day.date == selected, onPick = onPick, modifier = Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun CalendarCell(day: SocialCalendarDay, picked: Boolean, onPick: (String) -> Unit, modifier: Modifier) {
    val label = runCatching { LocalDate.parse(day.date) }.getOrNull()?.let(::socialDayLabel) ?: day.date
    val spoken = if (day.count == 0) stringResource(R.string.social_day_none, label) else pluralStringResource(R.plurals.social_day_count, day.count, label, day.count)
    val colors = MaterialTheme.colorScheme
    val container = when {
        picked -> colors.primary
        day.count > 0 -> colors.secondaryContainer
        else -> colors.surface
    }
    val content = when {
        picked -> colors.onPrimary
        day.count > 0 -> colors.onSecondaryContainer
        day.inMonth -> colors.onSurface
        else -> Muted
    }
    Surface(
        shape = MaterialTheme.shapes.small,
        color = container,
        contentColor = content,
        border = if (day.today) BorderStroke(2.dp, colors.primary) else null,
        modifier = modifier
            .heightIn(min = 48.dp)
            .then(if (day.count > 0) Modifier.selectable(selected = picked, role = Role.Button, onClick = { onPick(day.date) }) else Modifier)
            .clearAndSetSemantics { contentDescription = spoken; if (day.count > 0) selected = picked },
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center, modifier = Modifier.padding(vertical = 4.dp)) {
            Text(day.day.toString(), style = MaterialTheme.typography.bodyMedium)
            if (day.count > 0) Text(day.count.toString(), style = MaterialTheme.typography.labelSmall)
        }
    }
}

/** Una serata dell'elenco: locandina, titolo, quando, stato, dimostrativo; si tocca per sceglierla (W-SO-03). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CatalogCard(card: SocialCard, picked: Boolean, zone: ZoneId, onClick: () -> Unit) {
    val whenText = socialWhen(card, zone, stringResource(R.string.social_all_day))
    val spoken = stringResource(if (picked) R.string.social_remove_label else R.string.social_pick_label, card.title, whenText)
    OutlinedCard(
        modifier = Modifier.fillMaxWidth()
            .toggleable(value = picked, enabled = card.selectable, role = Role.Checkbox, onValueChange = { onClick() })
            .semantics { contentDescription = spoken },
        border = BorderStroke(if (picked) 2.dp else 1.dp, if (picked) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant),
    ) {
        Row(Modifier.padding(10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            PosterImage(card.poster?.thumb ?: card.poster?.card ?: card.poster?.full, Modifier.size(width = 56.dp, height = 75.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(card.title, style = MaterialTheme.typography.titleSmall, maxLines = 3, overflow = TextOverflow.Ellipsis)
                Text(whenText, color = Muted, style = MaterialTheme.typography.bodyMedium)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    StatusBadge(card.status.label)
                    if (card.demo) StatusBadge(stringResource(R.string.social_demo))
                    if (!card.selectable) StatusBadge(stringResource(R.string.social_not_available))
                }
            }
            if (card.selectable) Icon(
                if (picked) Icons.Outlined.Check else Icons.Outlined.Add,
                contentDescription = null,
                tint = if (picked) MaterialTheme.colorScheme.primary else Muted,
                modifier = Modifier.size(28.dp),
            )
        }
    }
}

// ---------------------------------------------------------------- 2. Formato e stile

@Composable
private fun StyleSection(state: SocialStudioUiState, venue: ManagementSubject, token: String, viewModel: SocialStudioViewModel) {
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
                    PreviewTile(card, venue, token, picked = card.id in state.selected) { viewModel.toggle(card.id) }
                }
            }
            if (day.data.size > 1) OutlinedButton(onClick = viewModel::selectAll, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_select_all)) }
        }
    }
    OptionsForm(state, viewModel)
}

/** L'anteprima JPEG della grafica di una serata del giorno, con la casella per sceglierla (W-SO-09). */
@Composable
private fun PreviewTile(card: SocialCard, venue: ManagementSubject, token: String, picked: Boolean, onToggle: () -> Unit) {
    Column(Modifier.width(150.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        AuthorizedImage(
            url = BuildConfig.API_BASE_URL + socialPreviewPath(venue, card.eventId, card.id),
            token = token,
            description = stringResource(R.string.social_preview_alt, card.title),
            modifier = Modifier.fillMaxWidth().aspectRatio(1080f / 1350f),
        )
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

/** Formato, inquadratura, le tre caselle e il titolo alternativo (W-SO-04), poi «Prepara» (W-SO-05). */
@Composable
private fun OptionsForm(state: SocialStudioUiState, viewModel: SocialStudioViewModel) {
    val options = state.options
    Text(stringResource(R.string.social_format), style = MaterialTheme.typography.titleSmall)
    Column {
        SocialFormatChoice.entries.forEach { format ->
            RadioRow(stringResource(formatLabel(format)), options.format == format) { viewModel.setOptions(options.copy(format = format)) }
        }
    }
    Text(stringResource(R.string.social_image_fit), style = MaterialTheme.typography.titleSmall)
    Column {
        RadioRow(stringResource(R.string.social_contain), !options.cover) { viewModel.setOptions(options.copy(cover = false)) }
        RadioRow(stringResource(R.string.social_cover), options.cover) { viewModel.setOptions(options.copy(cover = true)) }
    }
    Text(stringResource(R.string.social_options), style = MaterialTheme.typography.titleSmall)
    Column {
        CheckRow(stringResource(R.string.social_monochrome), options.monochrome) { viewModel.setOptions(options.copy(monochrome = it)) }
        CheckRow(stringResource(R.string.social_address), options.showAddress) { viewModel.setOptions(options.copy(showAddress = it)) }
        CheckRow(stringResource(R.string.social_price), options.showPrice) { viewModel.setOptions(options.copy(showPrice = it)) }
    }
    val titleError = state.errors.field("title")
    OutlinedTextField(
        value = options.title,
        onValueChange = { viewModel.setOptions(options.copy(title = it)) },
        label = { Text(stringResource(R.string.social_short_title)) },
        supportingText = { Text(titleError ?: stringResource(R.string.social_title_counter, options.title.length, SOCIAL_TITLE_MAX)) },
        isError = titleError != null,
        modifier = Modifier.fillMaxWidth(),
    )
    state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    listOf("format", "image_fit").mapNotNull(state.errors::field).forEach { Text(it, color = MaterialTheme.colorScheme.error) }
    if (state.chosen.size > SOCIAL_MAX_SELECTION) Text(stringResource(R.string.social_too_many, SOCIAL_MAX_SELECTION), color = MaterialTheme.colorScheme.error)
    Button(enabled = state.canGenerate, onClick = viewModel::generate, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(if (state.generating) R.string.social_generating else R.string.social_generate))
    }
}

@Composable
private fun RadioRow(label: String, selected: Boolean, onSelect: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = selected, role = Role.RadioButton, onClick = onSelect),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        RadioButton(selected = selected, onClick = null)
        Text(label)
    }
}

@Composable
private fun CheckRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(value = checked, role = Role.Checkbox, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Checkbox(checked = checked, onCheckedChange = null)
        Text(label)
    }
}

// ---------------------------------------------------------------- 3. Grafiche pronte

@Composable
private fun RecentSection(state: SocialStudioUiState, viewModel: SocialStudioViewModel, onOpen: (String) -> Unit) {
    SectionTitle(stringResource(R.string.social_ready))
    Text(stringResource(R.string.social_ready_hint), color = Muted)
    LoadState(state.recent, onRetry = viewModel::retryRecent, emptyText = stringResource(R.string.social_ready_empty)) { list ->
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            list.forEach { batch -> RecentRow(batch, state.venueSubject?.timezone ?: ZoneId.of("Europe/Rome")) { onOpen(batch.id) } }
        }
    }
}

@Composable
private fun RecentRow(batch: SocialBatchSummary, zone: ZoneId, onOpen: () -> Unit) {
    val day = runCatching { LocalDate.parse(batch.date) }.getOrNull()?.let(::socialDayLabel) ?: batch.date
    val format = stringResource(formatLabel(SocialFormatChoice.from(batch.format.value)))
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Column(Modifier.weight(1f)) {
            Text(pluralStringResource(R.plurals.social_recent_item, batch.count, day, format, batch.count))
            batch.createdAt?.let { iso -> socialMoment(iso, zone)?.let { Text(stringResource(R.string.social_prepared_at, it), style = MaterialTheme.typography.bodySmall, color = Muted) } }
        }
        OutlinedButton(onClick = onOpen, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_open_batch)) }
    }
}

// ---------------------------------------------------------------- un pacchetto

@Composable
internal fun SocialBatchScreen(viewModel: SocialBatchViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val androidContext = LocalContext.current
    val imageChooser = stringResource(R.string.social_share_chooser)
    val zipChooser = stringResource(R.string.social_zip_share)
    LaunchedEffect(state.file) {
        val pending = state.file ?: return@LaunchedEffect
        when (pending.use) {
            SocialFileUse.SaveImage -> if (SocialImages.save(androidContext, pending.file.file, pending.name)) viewModel.fileHandled(UiText.Res(R.string.social_saved))
                else viewModel.fileHandled(null, UiText.Res(R.string.social_save_failed))
            SocialFileUse.ShareImage -> if (SocialImages.share(androidContext, pending.file.file, "image/jpeg", imageChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.social_share_failed))
            SocialFileUse.ShareZip -> if (SharedFiles.share(androidContext, pending.file.file, "application/zip", zipChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.social_share_failed))
            SocialFileUse.OpenZip -> if (SharedFiles.open(androidContext, pending.file.file, "application/zip", zipChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.management_export_no_app))
        }
    }
    val batch = (state.batch as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.social_batch_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = batch?.let { listOfNotNull(runCatching { LocalDate.parse(it.date) }.getOrNull()?.let(::socialLongDayLabel), stringResource(formatLabel(SocialFormatChoice.from(it.format.value)))).joinToString(" · ") },
        busy = state.busy,
    ) {
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        LoadState(state.batch, onRetry = viewModel::load, emptyText = stringResource(R.string.social_batch_missing)) { loaded ->
            BatchContent(loaded, state, viewModel)
        }
    }
}

@Composable
private fun BatchContent(batch: SocialBatch, state: SocialBatchUiState, viewModel: SocialBatchViewModel) {
    val androidContext = LocalContext.current
    val cacheDir = androidContext.cacheDir
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text(stringResource(R.string.social_snapshot), color = Muted)
        val ratio = batch.format.width.toFloat() / batch.format.height.coerceAtLeast(1)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            items(batch.items, key = { it.index }) { item ->
                BatchImage(item, ratio, busy = state.busy, downloading = state.downloading == item.index,
                    onSave = { viewModel.image(item, SocialFileUse.SaveImage, cacheDir) },
                    onShare = { viewModel.image(item, SocialFileUse.ShareImage, cacheDir) })
            }
        }
        if (batch.caption.isNotBlank()) {
            Text(stringResource(R.string.social_caption_label), style = MaterialTheme.typography.titleSmall)
            Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
                Text(batch.caption, modifier = Modifier.padding(12.dp))
            }
            OutlinedButton(onClick = { if (copyCaption(androidContext, batch.caption)) viewModel.captionCopied() }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.social_copy_caption))
            }
        }
        HorizontalDivider()
        Text(stringResource(R.string.social_zip), style = MaterialTheme.typography.titleSmall)
        Text(stringResource(R.string.social_zip_hint), color = Muted)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(enabled = !state.busy, onClick = { viewModel.zip(SocialFileUse.ShareZip, cacheDir) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (state.downloading == SocialBatchViewModel.ZIP) R.string.social_downloading else R.string.social_zip_share))
            }
            OutlinedButton(enabled = !state.busy, onClick = { viewModel.zip(SocialFileUse.OpenZip, cacheDir) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.social_zip_open))
            }
        }
    }
}

/** Un'immagine del pacchetto con «Salva nella galleria» (da Android 10) e «Condividi». */
@Composable
private fun BatchImage(item: SocialBatchItem, ratio: Float, busy: Boolean, downloading: Boolean, onSave: () -> Unit, onShare: () -> Unit) {
    Column(Modifier.width(240.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        AsyncImage(
            model = item.url,
            contentDescription = stringResource(R.string.social_image_alt, item.index + 1, item.title),
            contentScale = ContentScale.Fit,
            modifier = Modifier.fillMaxWidth().aspectRatio(ratio),
        )
        Text(stringResource(R.string.social_image_label, item.index + 1, item.title), style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
        if (downloading) Text(stringResource(R.string.social_downloading), color = Muted)
        if (SocialImages.canSave) Button(enabled = !busy, onClick = onSave, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.social_save)) }
        OutlinedButton(enabled = !busy, onClick = onShare, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.social_share)) }
    }
}

// ---------------------------------------------------------------- grafica di una data (W-DT-12)

/**
 * La grafica social di una data, scaricata dal server: grande, con
 * «Salva nella galleria» e «Condividi». La apre l'elenco delle date
 * dell'organizzazione, come «Grafica social» del pannello.
 */
@Composable
internal fun SocialPreviewDialog(file: java.io.File, title: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val chooser = stringResource(R.string.social_share_chooser)
    var outcome by remember { mutableStateOf<Int?>(null) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.social_date_preview_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                AsyncImage(model = file, contentDescription = stringResource(R.string.social_preview_alt, title), contentScale = ContentScale.Fit,
                    modifier = Modifier.fillMaxWidth().aspectRatio(1080f / 1350f))
                if (SocialImages.canSave) Button(onClick = { outcome = if (SocialImages.save(context, file, file.nameWithoutExtension)) R.string.social_saved else R.string.social_save_failed },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.social_save)) }
                OutlinedButton(onClick = { if (!SocialImages.share(context, file, "image/jpeg", chooser)) outcome = R.string.social_share_failed },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.social_share)) }
                outcome?.let { Text(stringResource(it), color = if (it == R.string.social_saved) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.social_close)) } },
    )
}

// ---------------------------------------------------------------- pezzi comuni

@Composable
private fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
}

/** Un'immagine dell'API di gestione, che vuole il token (le anteprime delle grafiche). */
@Composable
private fun AuthorizedImage(url: String, token: String, description: String, modifier: Modifier) {
    val context = LocalContext.current
    val request = remember(url, token) {
        ImageRequest.Builder(context)
            .data(url)
            .httpHeaders(NetworkHeaders.Builder().set("Authorization", "Bearer $token").set("Accept", "image/jpeg").build())
            .build()
    }
    Surface(shape = MaterialTheme.shapes.small, tonalElevation = 1.dp, modifier = modifier) {
        AsyncImage(model = request, contentDescription = description, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
    }
}

private fun copyCaption(context: Context, caption: String): Boolean {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return false
    clipboard.setPrimaryClip(ClipData.newPlainText("didascalia", caption))
    return true
}

private val PERIOD_LABELS = listOf(
    "today" to R.string.social_period_today,
    "week" to R.string.social_period_week,
    "next30" to R.string.social_period_next30,
    "custom" to R.string.social_period_custom,
)

private fun formatLabel(format: SocialFormatChoice): Int = when (format) {
    SocialFormatChoice.Portrait -> R.string.social_format_portrait
    SocialFormatChoice.Square -> R.string.social_format_square
    SocialFormatChoice.Story -> R.string.social_format_story
}

private val dayFormat = DateTimeFormatter.ofPattern("EEE d MMM", Locale.ITALIAN)
private val dayYearFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.ITALIAN)
private val longDayFormat = DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.ITALIAN)
private val timeFormat = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

/** «sab 22 nov» (con l'anno se non è quello in corso), come le date dei pannelli. */
internal fun socialDayLabel(date: LocalDate, today: LocalDate = LocalDate.now()): String =
    date.format(if (date.year == today.year) dayFormat else dayYearFormat)

/** «Sabato 22 novembre», il giorno delle grafiche. */
internal fun socialLongDayLabel(date: LocalDate): String = date.format(longDayFormat).replaceFirstChar { it.titlecase(Locale.ITALIAN) }

/** «sab 22 nov, 21:00» nel fuso della città; per le serate di tutto il giorno «sab 22 nov · tutto il giorno». */
internal fun socialWhen(card: SocialCard, zone: ZoneId, allDay: String): String {
    val cardZone = card.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: zone
    val start = card.startsAt?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(cardZone) }.getOrNull() }
        ?: return runCatching { socialDayLabel(LocalDate.parse(card.businessDate)) }.getOrDefault(card.businessDate)
    val day = socialDayLabel(start.toLocalDate(), LocalDate.now(cardZone))
    return if (card.isAllDay) "$day · $allDay" else "$day, ${start.format(timeFormat)}"
}

/** Quando è stato preparato un pacchetto: «sab 22 nov, 21:05». */
internal fun socialMoment(iso: String, zone: ZoneId): String? =
    runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone) }.getOrNull()?.let { "${socialDayLabel(it.toLocalDate(), LocalDate.now(zone))}, ${it.format(timeFormat)}" }
