package it.fabiodalez.incitta.ui.editorial.calendar

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.editorial.BellItem
import it.fabiodalez.incitta.data.editorial.BellPage
import it.fabiodalez.incitta.data.editorial.CalendarItem
import it.fabiodalez.incitta.data.editorial.CalendarWindow
import it.fabiodalez.incitta.data.editorial.EditorialCalendarSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.Loadable
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneId
import java.time.temporal.TemporalAdjusters
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * I giorni della griglia di un mese: settimane intere da lunedì a domenica,
 * come il calendario del pannello (`firstDay: 1`). Da quattro a sei righe.
 */
internal fun calendarGrid(month: YearMonth): List<LocalDate> {
    val first = month.atDay(1).with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY))
    val last = month.atEndOfMonth().with(TemporalAdjusters.nextOrSame(DayOfWeek.SUNDAY))
    return generateSequence(first) { it.plusDays(1) }.takeWhile { !it.isAfter(last) }.toList()
}

/** Le date per giorno (`date` è già nel fuso della città dell'evento), nell'ordine del server. */
internal fun calendarByDay(items: List<CalendarItem>): Map<String, List<CalendarItem>> = items.groupBy { it.date }

internal data class CalendarUiState(
    /** `AAAA-MM`. */
    val month: String,
    /** `AAAA-MM-GG`, il giorno di cui si vede l'elenco. */
    val selected: String,
    val window: Loadable<CalendarWindow> = Loadable.Loading,
) {
    val yearMonth: YearMonth get() = runCatching { YearMonth.parse(month) }.getOrElse { YearMonth.now() }
    val byDay: Map<String, List<CalendarItem>> get() = (window as? Loadable.Ready)?.value?.data?.let(::calendarByDay).orEmpty()
}

/**
 * Il calendario della redazione (R-EV-27): un mese alla volta, le date di
 * tutte le città. Mese e giorno scelti restano nello stato salvato.
 */
internal class EditorialCalendarViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialCalendarSource,
    private val saved: SavedStateHandle,
    private val today: () -> LocalDate = { LocalDate.now(repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")) },
) : ViewModel() {
    private val state = MutableStateFlow(
        CalendarUiState(
            month = saved.get<String>(KEY_MONTH) ?: YearMonth.from(today()).toString(),
            selected = saved.get<String>(KEY_DAY) ?: today().toString(),
        ),
    )
    val uiState: StateFlow<CalendarUiState> = state.asStateFlow()
    private var job: Job? = null

    init { load() }

    fun load() {
        val grid = calendarGrid(state.value.yearMonth)
        job?.cancel()
        state.value = state.value.copy(window = Loadable.Loading)
        job = viewModelScope.launch {
            val result = try {
                Loadable.Ready(repository.call { source.calendar(grid.first().toString(), grid.last().toString(), repository.selectedCity) })
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
            state.value = state.value.copy(window = result)
        }
    }

    /** Mese prima o dopo; il giorno scelto diventa il primo del mese (o oggi, se è quel mese). */
    fun shift(step: Int) = goTo(state.value.yearMonth.plusMonths(step.toLong()))

    fun goToToday() = goTo(YearMonth.from(today()))

    fun pick(day: String) {
        if (runCatching { LocalDate.parse(day) }.isFailure) return
        saved[KEY_DAY] = day
        state.value = state.value.copy(selected = day)
    }

    private fun goTo(month: YearMonth) {
        val now = today()
        val day = if (YearMonth.from(now) == month) now else month.atDay(1)
        saved[KEY_MONTH] = month.toString()
        saved[KEY_DAY] = day.toString()
        state.value = state.value.copy(month = month.toString(), selected = day.toString())
        load()
    }

    private companion object {
        const val KEY_MONTH = "editorial.calendar.month"
        const val KEY_DAY = "editorial.calendar.day"
    }
}

internal data class BellUiState(
    val page: Loadable<BellPage> = Loadable.Loading,
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
) {
    val items: List<BellItem> get() = (page as? Loadable.Ready)?.value?.data.orEmpty()
    val unread: Int get() = (page as? Loadable.Ready)?.value?.meta?.unread ?: 0
}

/**
 * La campanella della redazione (R-DB-11): le notifiche del pannello, con
 * le stesse azioni. Aprire una notifica la segna come letta e porta alla sua
 * pagina, nell'app se c'è.
 */
internal class EditorialBellViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialCalendarSource,
) : ViewModel() {
    private val state = MutableStateFlow(BellUiState())
    val uiState: StateFlow<BellUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        if (state.value.page !is Loadable.Ready) state.value = state.value.copy(page = Loadable.Loading)
        viewModelScope.launch {
            val result = try {
                val page = repository.call { source.notifications() }
                if (page.data.isEmpty()) Loadable.Empty else Loadable.Ready(page)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Loadable.Failed(requestFailureMessage(e))
            }
            state.value = state.value.copy(page = result)
        }
    }

    fun markRead(item: BellItem) = change { source.markRead(item.id) }
    fun markUnread(item: BellItem) = change { source.markUnread(item.id) }
    fun remove(item: BellItem) = change { source.remove(item.id) }
    fun markAllRead() = change { source.markAllRead() }
    fun clear() = change { source.clear() }

    /** La segna come letta (senza aspettare) e dice dove andare. */
    fun open(item: BellItem): String? {
        if (!item.read) markRead(item)
        return item.url ?: item.actions.firstOrNull()?.url
    }

    fun dismissMessage() { state.value = state.value.copy(message = null, error = null) }

    private fun change(block: suspend () -> it.fabiodalez.incitta.data.editorial.BellChange) {
        if (state.value.busy) return
        state.value = state.value.copy(busy = true, error = null)
        viewModelScope.launch {
            try {
                val done = repository.call { block() }
                state.value = state.value.copy(busy = false, message = done.meta.message)
                load()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                state.value = state.value.copy(busy = false, error = requestFailureMessage(e))
            }
        }
    }
}
