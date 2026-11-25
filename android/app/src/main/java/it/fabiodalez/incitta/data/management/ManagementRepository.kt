package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.CheckedInTicket
import it.fabiodalez.incitta.data.CheckinManifest
import it.fabiodalez.incitta.data.CheckinOutbox
import it.fabiodalez.incitta.data.CheckinOutcome
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.ManagementApi
import it.fabiodalez.incitta.data.PendingCheckin
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.TicketingApi
import java.time.Instant
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** Quello che la gestione tiene sul telefono: coda cifrata delle letture ed elenchi per il controllo senza rete. */
internal interface ManagementDeviceStore {
    fun readCheckins(): List<PendingCheckin>
    fun writeCheckins(entries: List<PendingCheckin>)
    fun readManifest(occurrenceId: Long): CheckinManifest?
    fun writeManifest(occurrenceId: Long, manifest: CheckinManifest)
}

/** Lo stesso `LocalStore` cifrato di sempre: il formato salvato non cambia. */
internal class LocalManagementStore(private val store: LocalStore) : ManagementDeviceStore {
    override fun readCheckins() = store.readCheckins()
    override fun writeCheckins(entries: List<PendingCheckin>) = store.writeCheckins(entries)
    override fun readManifest(occurrenceId: Long) = store.readManifest(occurrenceId)
    override fun writeManifest(occurrenceId: Long, manifest: CheckinManifest) = store.writeManifest(occurrenceId, manifest)
}

/** Le letture in attesa di questo account e gli esiti recenti, per tutte le date. */
internal data class CheckinQueue(
    val pending: List<PendingCheckin> = emptyList(),
    val outcomes: List<CheckinOutcome> = emptyList(),
)

/** Dove atterra chi apre la gestione. */
internal sealed interface ManagementLanding {
    /** L'elenco delle date, come prima: area nuova spenta, server vecchio, solo personale d'ingresso o nessun soggetto. */
    data object TicketingDates : ManagementLanding
    /** La pagina del soggetto, con il menu delle aree. */
    data class Subject(val key: String) : ManagementLanding
}

/** Chi gestisce almeno un locale o un'organizzazione atterra sul primo; gli altri sull'elenco delle date. */
internal fun landingFor(enabled: Boolean, subjects: ManagementSubjects?): ManagementLanding {
    if (!enabled || subjects == null) return ManagementLanding.TicketingDates
    val first = subjects.all().firstOrNull() ?: return ManagementLanding.TicketingDates
    return ManagementLanding.Subject(first.key)
}

/**
 * Le API e la memoria della gestione per una sessione. Uno solo per account
 * collegato (lo tiene `ManagementGraph`), così la coda delle letture si
 * ritenta ogni 15 secondi finché la sessione è viva, anche con la schermata
 * chiusa. La coda resta `CheckinOutbox` con le sue regole: questa classe
 * decide solo quando chiamarla.
 */
internal class ManagementRepository(
    val userId: Long,
    val token: String,
    val ticketing: TicketingApi,
    val identity: ManagementIdentitySource,
    val media: ManagementMediaApi?,
    val device: ManagementDeviceStore,
    private val scope: CoroutineScope,
    private val retryDelayMillis: Long = RETRY_DELAY_MILLIS,
    send: (suspend (PendingCheckin) -> CheckedInTicket)? = null,
    /* Profilo del locale e dell'organizzazione (P4). */
    val profile: ManagementProfileSource? = null,
) {
    private val outbox = CheckinOutbox(userId, device::readCheckins, device::writeCheckins, send ?: ticketing::checkIn)
    private val checkinState = MutableStateFlow(CheckinQueue(safePending()))
    val checkins: StateFlow<CheckinQueue> = checkinState.asStateFlow()
    private var retryJob: Job? = null

    /* Memoria della sessione: l'ultimo elenco dei soggetti, la scelta, gli elenchi del server. */
    private val subjectsState = MutableStateFlow<ManagementSubjects?>(null)
    val subjects: StateFlow<ManagementSubjects?> = subjectsState.asStateFlow()
    @Volatile var selectedSubjectKey: String? = null
    @Volatile private var enabled: Boolean? = null
    @Volatile private var cachedOptions: ManagementOptions? = null

    init { ensureRetrying(immediate = true) }

    /**
     * Decide l'atterraggio. Qualsiasi errore (rete assente, server vecchio,
     * risposta diversa) riporta all'elenco delle date: chi è alla porta deve
     * poter arrivare al controllo ingressi anche quando il resto non risponde.
     */
    suspend fun landing(): ManagementLanding {
        val on = enabled ?: try { identity.managementEnabled() } catch (e: CancellationException) { throw e } catch (_: Exception) { return ManagementLanding.TicketingDates }
        enabled = on
        if (!on) return ManagementLanding.TicketingDates
        val list = try { loadSubjects() } catch (e: CancellationException) { throw e } catch (_: Exception) { return ManagementLanding.TicketingDates }
        val chosen = list.find(selectedSubjectKey)
        return if (chosen != null) ManagementLanding.Subject(chosen.key) else landingFor(true, list)
    }

    suspend fun loadSubjects(): ManagementSubjects = identity.subjects().also { subjectsState.value = it }

    /** Gli elenchi del server; `null` con l'area spenta o se la chiamata non riesce (si usano i valori di sempre). */
    suspend fun options(): ManagementOptions? {
        cachedOptions?.let { return it }
        if (enabled != true) return null
        return try { identity.options().also { cachedOptions = it } } catch (e: CancellationException) { throw e } catch (_: Exception) { null }
    }

    /** Salva la lettura cifrata e prova subito a inviarla. Le regole (64 caratteri, tetto di 200, doppioni) restano di `CheckinOutbox`. */
    suspend fun addCheckin(occurrenceId: Long, code: String, scannedAt: Instant) {
        outbox.add(occurrenceId, code, scannedAt)
        refreshPending()
        flushCheckins()
    }

    /** Un esito deciso sul telefono (codice già usato o assente dall'elenco), mostrato accanto a quelli del server. */
    fun recordOutcome(outcome: CheckinOutcome) {
        checkinState.update { it.copy(outcomes = (listOf(outcome) + it.outcomes).take(MAX_OUTCOMES)) }
    }

    suspend fun flushCheckins() {
        try {
            outbox.flush { result ->
                if (result != null) recordOutcome(result)
                refreshPending()
            }
        } finally {
            refreshPending()
            ensureRetrying()
        }
    }

    /*
     * Un solo giro di ritentativi alla volta, finché resta qualcosa in coda.
     * All'apertura della sessione il primo tentativo è subito, come faceva la
     * schermata; dopo una lettura nuova si aspetta il giro normale, perché la
     * lettura è appena stata provata. Se una lettura arriva mentre il giro si
     * chiude, il controllo a fine giro lo riapre.
     */
    @Synchronized
    private fun ensureRetrying(immediate: Boolean = false) {
        if (retryJob?.isActive == true || checkinState.value.pending.isEmpty()) return
        retryJob = scope.launch {
            var wait = !immediate
            while (isActive) {
                if (wait) delay(retryDelayMillis)
                wait = true
                try { outbox.flush { result -> if (result != null) recordOutcome(result); refreshPending() } }
                catch (e: CancellationException) { throw e }
                catch (_: Exception) { /* Resta in coda: si riprova al giro dopo. */ }
                refreshPending()
                if (checkinState.value.pending.isEmpty()) break
            }
        }.also { job -> job.invokeOnCompletion { cause -> if (cause == null) ensureRetrying() } }
    }

    private fun refreshPending() { checkinState.update { it.copy(pending = safePending()) } }
    private fun safePending(): List<PendingCheckin> = try { outbox.pending() } catch (e: CancellationException) { throw e } catch (_: Exception) { emptyList() }

    fun close() { scope.cancel() }

    companion object {
        const val RETRY_DELAY_MILLIS = 15_000L
        const val MAX_OUTCOMES = 30

        fun create(session: Session, client: ApiClient, device: ManagementDeviceStore, scope: CoroutineScope) = ManagementRepository(
            userId = session.user.id, token = session.token,
            ticketing = ManagementApi(client, session.token), identity = ManagementIdentityApi(client, session.token),
            media = ManagementMediaApi(client, session.token), device = device, scope = scope,
            profile = ManagementProfileApi(client, session.token),
        )
    }
}

/**
 * Tiene il repository della sessione collegata. Cambiando account (o uscendo)
 * il vecchio si chiude e il ritentativo si ferma: le letture in attesa di un
 * account non partono mai con il token di un altro.
 */
internal object ManagementGraph {
    private var current: ManagementRepository? = null
    private var currentClient: ApiClient? = null

    @Synchronized
    fun repository(context: android.content.Context, session: Session, client: ApiClient? = null): ManagementRepository {
        current?.takeIf { it.token == session.token && it.userId == session.user.id && (client == null || client === currentClient) }?.let { return it }
        current?.close()
        val store = LocalStore(context.applicationContext)
        val api = client ?: ApiClient(store.installationId)
        return ManagementRepository.create(session, api, LocalManagementStore(store), CoroutineScope(SupervisorJob() + Dispatchers.IO)).also {
            current = it
            currentClient = api
        }
    }

    /** Chiude il repository se la sessione non è più quella: lo chiama l'app a ogni cambio di token. */
    @Synchronized
    fun retain(token: String?) {
        if (current != null && current?.token != token) {
            current?.close()
            current = null
            currentClient = null
        }
    }
}
