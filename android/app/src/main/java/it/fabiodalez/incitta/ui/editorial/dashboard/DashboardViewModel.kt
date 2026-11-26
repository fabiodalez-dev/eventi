package it.fabiodalez.incitta.ui.editorial.dashboard

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.editorial.DashboardSource
import it.fabiodalez.incitta.data.editorial.DashboardTarget
import it.fabiodalez.incitta.data.editorial.EditorialDashboard
import it.fabiodalez.incitta.data.editorial.EditorialPermissions
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.editorial.EditorialArea
import it.fabiodalez.incitta.ui.editorial.common.EditorialListHandoff
import it.fabiodalez.incitta.ui.editorial.common.EditorialListTarget
import it.fabiodalez.incitta.ui.management.common.Loadable
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * Il riepilogo della città di lavoro. Si carica all'apertura e a richiesta;
 * cambiare città rilegge prima il banco (permessi e contatori) e poi i
 * riquadri. Una richiesta nuova annulla quella in corso, così una risposta
 * vecchia non copre la città appena scelta.
 */
internal class DashboardViewModel(
    private val repository: EditorialRepository,
    private val source: DashboardSource,
) : ViewModel() {
    private val mutable = MutableStateFlow<Loadable<EditorialDashboard>>(Loadable.Loading)
    val state: StateFlow<Loadable<EditorialDashboard>> = mutable.asStateFlow()
    private val busyState = MutableStateFlow(false)
    /** Vero mentre arriva un riepilogo nuovo sopra quello già mostrato. */
    val busy: StateFlow<Boolean> = busyState.asStateFlow()
    private var job: Job? = null

    init { load() }

    fun load() = fetch { }

    /** Cambia la città di lavoro di tutta l'area e rilegge il riepilogo. */
    fun selectCity(slug: String) = fetch { repository.selectCity(slug) }

    private fun fetch(before: suspend () -> Unit) {
        job?.cancel()
        if (mutable.value !is Loadable.Ready) mutable.value = Loadable.Loading
        busyState.value = true
        job = viewModelScope.launch {
            try {
                before()
                val loaded = repository.call { source.dashboard(repository.selectedCity) }
                mutable.value = Loadable.Ready(loaded)
                busyState.value = false
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                mutable.value = Loadable.Failed(requestFailureMessage(e))
                busyState.value = false
            }
        }
    }
}

/** Dove porta il tocco su un riquadro. */
internal sealed interface DashboardDestination {
    /** L'elenco nativo dell'area, già filtrato. */
    data class Native(val area: EditorialArea, val target: EditorialListTarget) : DashboardDestination
    /** La pagina del pannello già filtrata (`admin/events?filters[status][value]=pending`), nel browser. */
    data class Web(val path: String) : DashboardDestination
}

/** L'area dell'app di un elenco del riepilogo; `null` per un elenco che questa versione non conosce. */
internal fun dashboardArea(list: String): EditorialArea? = when (list) {
    "events" -> EditorialArea.Events
    /* Locali e richieste dei locali stanno nella stessa area. */
    "venues", "applications" -> EditorialArea.Venues
    "reports" -> EditorialArea.Moderation
    "import_sources" -> EditorialArea.System
    "users" -> EditorialArea.Users
    else -> null
}

/**
 * Nativo quando l'area è già nell'app, la persona la apre e il suo elenco sa
 * ricevere il filtro; altrimenti la pagina del pannello già filtrata, come
 * il collegamento del riquadro sul sito (anche quando la persona lì non
 * entra: il pannello fa così). `null` se non c'è dove andare.
 */
internal fun dashboardDestination(
    target: DashboardTarget,
    can: EditorialPermissions?,
    accepts: (String) -> Boolean = EditorialListHandoff::accepts,
): DashboardDestination? {
    val area = dashboardArea(target.area)
    if (area != null && area.native && can != null && area.allowedFor(can) && accepts(target.area)) {
        return DashboardDestination.Native(area, EditorialListTarget(target.area, target.tab, target.filterMap))
    }
    return target.webPath.takeIf { it.isNotBlank() }?.let { DashboardDestination.Web(it) }
}
