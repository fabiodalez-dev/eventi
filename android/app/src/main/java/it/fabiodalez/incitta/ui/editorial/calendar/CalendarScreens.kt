package it.fabiodalez.incitta.ui.editorial.calendar

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringArrayResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.BellItem
import it.fabiodalez.incitta.data.editorial.CalendarItem
import it.fabiodalez.incitta.data.editorial.CalendarLegendEntry
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.YearMonth
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

private val ITALIAN = Locale.ITALIAN
private val MONTH = DateTimeFormatter.ofPattern("LLLL yyyy", ITALIAN)
private val DAY = DateTimeFormatter.ofPattern("EEEE d MMMM", ITALIAN)
private val TIME = DateTimeFormatter.ofPattern("HH:mm", ITALIAN)

/** `#7a9900` → il colore della casella; un valore strano diventa il grigio delle bozze. */
internal fun calendarColor(hex: String): Color = runCatching {
    val clean = hex.removePrefix("#")
    require(clean.length == 6)
    Color(0xFF000000 or clean.toLong(16))
}.getOrDefault(Color(0xFF6B6B66))

/** «21:00–23:30» nel fuso della città dell'evento, «Tutto il giorno» per una data senza orario. */
@Composable
internal fun calendarTime(item: CalendarItem): String {
    if (item.allDay) return stringResource(R.string.editorial_calendar_all_day)
    val start = runCatching { OffsetDateTime.parse(item.startsAt) }.getOrNull() ?: return ""
    val end = runCatching { OffsetDateTime.parse(item.endsAt) }.getOrNull()
    val from = start.format(TIME)
    return if (end == null || !end.isAfter(start)) from else stringResource(R.string.editorial_calendar_time_range, from, end.format(TIME))
}

/** Il calendario del mese (R-EV-27): griglia, elenco del giorno scelto, legenda. Toccare una data apre la scheda dell'evento. */
@Composable
internal fun EditorialCalendarScreen(viewModel: EditorialCalendarViewModel, padding: PaddingValues, onBack: () -> Unit, onOpenEvent: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    EditorialCalendarContent(state, padding, onBack, viewModel::load, viewModel::shift, viewModel::goToToday, viewModel::pick, onOpenEvent)
}

@Composable
internal fun EditorialCalendarContent(
    state: CalendarUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onShift: (Int) -> Unit,
    onToday: () -> Unit,
    onPick: (String) -> Unit,
    onOpenEvent: (Long) -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.editorial_area_calendar), padding = padding, onBack = onBack, subtitle = stringResource(R.string.editorial_calendar_lead)) {
        val month = state.yearMonth
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { onShift(-1) }, modifier = Modifier.size(48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.KeyboardArrowLeft, contentDescription = stringResource(R.string.editorial_calendar_previous))
            }
            Text(
                month.format(MONTH).replaceFirstChar { it.titlecase(ITALIAN) },
                style = MaterialTheme.typography.titleMedium,
                textAlign = TextAlign.Center,
                modifier = Modifier.weight(1f).semantics { heading() },
            )
            IconButton(onClick = { onShift(1) }, modifier = Modifier.size(48.dp)) {
                Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = stringResource(R.string.editorial_calendar_next))
            }
        }
        OutlinedButton(onClick = onToday, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_calendar_today)) }
        LoadState(state.window, onRetry = onRetry) { window ->
            val byDay = state.byDay
            MonthGrid(month, byDay, state.selected, onPick)
            if (window.meta.truncated) Text(stringResource(R.string.editorial_calendar_truncated), color = MaterialTheme.colorScheme.error)
            val selected = runCatching { LocalDate.parse(state.selected) }.getOrNull()
            Text(
                selected?.format(DAY)?.replaceFirstChar { it.titlecase(ITALIAN) } ?: state.selected,
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.semantics { heading() },
            )
            val items = byDay[state.selected].orEmpty()
            if (items.isEmpty()) Text(stringResource(R.string.editorial_calendar_day_empty), color = Muted)
            items.forEach { item -> CalendarRow(item, onOpenEvent) }
            if (window.meta.legend.isNotEmpty()) Legend(window.meta.legend)
        }
    }
}

@Composable
private fun MonthGrid(month: YearMonth, byDay: Map<String, List<CalendarItem>>, selected: String, onPick: (String) -> Unit) {
    val weekdays = stringArrayResource(R.array.social_weekdays)
    val label = stringResource(R.string.editorial_calendar_grid)
    Column(Modifier.fillMaxWidth().semantics { contentDescription = label }, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(Modifier.fillMaxWidth().clearAndSetSemantics { }) {
            weekdays.forEach { Text(it, style = MaterialTheme.typography.labelSmall, color = Muted, textAlign = TextAlign.Center, modifier = Modifier.weight(1f)) }
        }
        calendarGrid(month).chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                week.forEach { day ->
                    DayCell(day, inMonth = YearMonth.from(day) == month, items = byDay[day.toString()].orEmpty(), picked = day.toString() == selected,
                        onPick = onPick, modifier = Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun DayCell(day: LocalDate, inMonth: Boolean, items: List<CalendarItem>, picked: Boolean, onPick: (String) -> Unit, modifier: Modifier) {
    val colors = MaterialTheme.colorScheme
    val spokenDay = day.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.LONG).withLocale(ITALIAN))
    val spoken = if (items.isEmpty()) stringResource(R.string.editorial_calendar_day_none, spokenDay)
    else pluralStringResource(R.plurals.editorial_calendar_day_count, items.size, spokenDay, items.size)
    val today = day == LocalDate.now(ZoneId.systemDefault())
    Surface(
        shape = MaterialTheme.shapes.small,
        color = if (picked) colors.primary else colors.surface,
        contentColor = when {
            picked -> colors.onPrimary
            inMonth -> colors.onSurface
            else -> Muted
        },
        border = if (today && !picked) BorderStroke(2.dp, colors.primary) else BorderStroke(1.dp, colors.outlineVariant),
        modifier = modifier
            .heightIn(min = 52.dp)
            .selectable(selected = picked, role = Role.Button, onClick = { onPick(day.toString()) })
            .clearAndSetSemantics { contentDescription = spoken; selected = picked },
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp), modifier = Modifier.padding(vertical = 4.dp)) {
            Text(day.dayOfMonth.toString(), style = MaterialTheme.typography.bodyMedium)
            if (items.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically) {
                    items.take(3).forEach { Box(Modifier.size(6.dp).background(calendarColor(it.color), CircleShape)) }
                }
                if (items.size > 3) Text(stringResource(R.string.editorial_calendar_more, items.size - 3), style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}

@Composable
private fun CalendarRow(item: CalendarItem, onOpenEvent: (Long) -> Unit) {
    val open = item.abilities.open && item.eventId > 0
    val openLabel = stringResource(R.string.editorial_calendar_item_open, item.eventTitle.ifBlank { item.title })
    OutlinedCard(
        modifier = Modifier.fillMaxWidth()
            .then(if (open) Modifier.clickable(role = Role.Button, onClickLabel = openLabel) { onOpenEvent(item.eventId) } else Modifier)
            .heightIn(min = 48.dp),
    ) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(6.dp).fillMaxHeight().background(calendarColor(item.color)))
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(item.title, style = MaterialTheme.typography.titleSmall)
                Text(stringResource(R.string.editorial_calendar_item_meta, calendarTime(item), item.city.name), style = MaterialTheme.typography.bodySmall)
                Text(item.eventStatus.label, style = MaterialTheme.typography.bodySmall, color = Muted)
                if (item.status.value.isNotBlank() && item.status.value != "scheduled") {
                    Text(stringResource(R.string.editorial_calendar_date_status, item.status.label), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Legend(entries: List<CalendarLegendEntry>) {
    Text(stringResource(R.string.editorial_calendar_legend), style = MaterialTheme.typography.labelLarge)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        entries.forEach { entry ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Box(Modifier.size(10.dp).background(calendarColor(entry.color), CircleShape))
                Text(entry.label, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

// ------------------------------------------------------------------ campanella

/** La campanella della redazione (R-DB-11). Toccare una notifica la segna come letta e apre la sua pagina. */
@Composable
internal fun EditorialBellScreen(viewModel: EditorialBellViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onOpenUrl: (String) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    EditorialBellContent(
        state, padding, zone, onBack,
        onRetry = viewModel::load,
        onOpen = { item -> viewModel.open(item)?.let(onOpenUrl) },
        onRead = viewModel::markRead,
        onUnread = viewModel::markUnread,
        onRemove = viewModel::remove,
        onReadAll = viewModel::markAllRead,
        onClear = viewModel::clear,
        onDismissMessage = viewModel::dismissMessage,
    )
}

@Composable
internal fun EditorialBellContent(
    state: BellUiState,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (BellItem) -> Unit,
    onRead: (BellItem) -> Unit,
    onUnread: (BellItem) -> Unit,
    onRemove: (BellItem) -> Unit,
    onReadAll: () -> Unit,
    onClear: () -> Unit,
    onDismissMessage: () -> Unit,
) {
    var confirmClear by remember { mutableStateOf(false) }
    ManagementScaffold(title = stringResource(R.string.editorial_bell_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.editorial_bell_lead), busy = state.busy) {
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        state.message?.let { message ->
            LaunchedEffect(message) { kotlinx.coroutines.delay(2500); onDismissMessage() }
            Text(message, color = Muted)
        }
        LoadState(state.page, onRetry = onRetry, emptyText = stringResource(R.string.editorial_bell_empty)) { page ->
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (page.meta.unread > 0) OutlinedButton(onClick = onReadAll, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_bell_read_all)) }
                OutlinedButton(onClick = { confirmClear = true }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_bell_clear)) }
            }
            page.data.forEach { item -> BellRow(item, zone, onOpen, onRead, onUnread, onRemove) }
        }
    }
    if (confirmClear) {
        ConfirmDialog(
            title = stringResource(R.string.editorial_bell_clear_title),
            text = stringResource(R.string.editorial_bell_clear_text),
            confirmLabel = stringResource(R.string.editorial_bell_clear),
            onConfirm = { confirmClear = false; onClear() },
            onDismiss = { confirmClear = false },
            destructive = true,
        )
    }
}

@Composable
private fun BellRow(item: BellItem, zone: ZoneId, onOpen: (BellItem) -> Unit, onRead: (BellItem) -> Unit, onUnread: (BellItem) -> Unit, onRemove: (BellItem) -> Unit) {
    var menu by remember { mutableStateOf(false) }
    val warning = item.status == "warning" || item.status == "danger"
    val opens = item.url != null || item.actions.isNotEmpty()
    OutlinedCard(
        modifier = Modifier.fillMaxWidth().then(if (opens) Modifier.clickable(role = Role.Button) { onOpen(item) } else Modifier),
        border = BorderStroke(if (item.read) 1.dp else 2.dp, if (warning && !item.read) MaterialTheme.colorScheme.tertiary else MaterialTheme.colorScheme.outlineVariant),
    ) {
        Row(Modifier.padding(start = 12.dp, top = 8.dp, bottom = 8.dp), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (!item.read) Text(stringResource(R.string.editorial_bell_unread_mark), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                Text(item.title, style = MaterialTheme.typography.titleSmall, fontWeight = if (item.read) FontWeight.Normal else FontWeight.SemiBold)
                item.body?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                bellDate(item.createdAt, zone)?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                item.actions.forEach { action ->
                    TextButton(onClick = { onOpen(item) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(action.label) }
                }
            }
            Box {
                IconButton(onClick = { menu = true }, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.MoreVert, contentDescription = stringResource(R.string.editorial_bell_more, item.title))
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    if (item.read) DropdownMenuItem(text = { Text(stringResource(R.string.editorial_bell_mark_unread)) }, onClick = { menu = false; onUnread(item) })
                    else DropdownMenuItem(text = { Text(stringResource(R.string.editorial_bell_mark_read)) }, onClick = { menu = false; onRead(item) })
                    DropdownMenuItem(text = { Text(stringResource(R.string.editorial_bell_remove)) }, onClick = { menu = false; onRemove(item) })
                }
            }
        }
    }
}

private val BELL_DATE = DateTimeFormatter.ofPattern("d MMMM, HH:mm", ITALIAN)

internal fun bellDate(value: String?, zone: ZoneId): String? =
    value?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(zone).format(BELL_DATE) }.getOrNull() }
