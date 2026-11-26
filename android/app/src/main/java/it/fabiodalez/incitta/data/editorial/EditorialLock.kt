package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.requestFailureMessage
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/*
 * I due blocchi della redazione (decisioni 2 e 3).
 *
 * - Sul server: il token va sbloccato con la password ogni 12 ore (423
 *   `EDITORIAL_LOCKED`), e le azioni pericolose vogliono la password scritta
 *   negli ultimi 15 minuti (423 `PASSWORD_REQUIRED`). `EditorialUnlockGuard`
 *   intercetta i due 423 di qualunque chiamata, chiede la password, sblocca
 *   e ripete la chiamata.
 * - Sul telefono: dopo 15 minuti in secondo piano o senza tocchi l'area si
 *   chiude e si riapre con il blocco del telefono; si chiude anche uscendo
 *   dall'area. `EditorialLocalLock` tiene il conto con l'orologio che non
 *   salta (`elapsedRealtime`).
 */

/** Perché il server ha fermato la chiamata. */
internal enum class EditorialLockReason(val code: String) {
    /* Il token non è sbloccato (o lo sblocco ha più di 12 ore). */
    Locked("EDITORIAL_LOCKED"),
    /* Azione pericolosa: la password va riscritta (ultimi 15 minuti). */
    PasswordRequired("PASSWORD_REQUIRED"),
}

/** Il motivo di un 423 della redazione, o `null` per ogni altro errore. */
internal fun editorialLockReason(error: Throwable): EditorialLockReason? {
    val api = error as? ApiException ?: return null
    if (api.status != 423) return null
    return EditorialLockReason.entries.firstOrNull { it.code == api.problem?.code } ?: EditorialLockReason.Locked
}

/** La richiesta della password che la schermata mostra: perché, l'eventuale errore, se sta verificando. */
internal data class PasswordPrompt(
    val reason: EditorialLockReason,
    val error: String? = null,
    val busy: Boolean = false,
)

/**
 * Chiede la password quando il server ferma una chiamata con 423, sblocca
 * con `unlock` e ripete la chiamata. Una sola richiesta della password alla
 * volta: due chiamate fermate insieme aspettano lo stesso sblocco, e quella
 * che arriva dopo uno sblocco appena riuscito riprova senza chiedere.
 *
 * Se la persona chiude la richiesta, la chiamata fallisce con il 423 di
 * partenza: la schermata lo mostra come un errore qualunque, con «Riprova».
 */
internal class EditorialUnlockGuard(private val unlock: suspend (String) -> EditorialUnlockState) {
    private val promptState = MutableStateFlow<PasswordPrompt?>(null)
    val prompt: StateFlow<PasswordPrompt?> = promptState.asStateFlow()
    private val mutex = Mutex()
    @Volatile private var pending: CompletableDeferred<String?>? = null
    @Volatile private var generation = 0
    /** L'ultimo stato dello sblocco ricevuto dal server, per chi lo vuole mostrare. */
    @Volatile var lastState: EditorialUnlockState? = null
        private set

    /** Esegue `block`; a ogni 423 della redazione chiede la password, sblocca e riprova. */
    suspend fun <T> run(block: suspend () -> T): T {
        while (true) {
            val seen = generation
            try {
                return block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val reason = editorialLockReason(e) ?: throw e
                if (!askAndUnlock(reason, seen)) throw e
            }
        }
    }

    /**
     * Chiede la password finché lo sblocco riesce (vero) o la persona chiude
     * la richiesta (falso). `seen` è lo sblocco che la chiamata conosceva: se
     * nel frattempo un'altra chiamata ha sbloccato, non si chiede di nuovo.
     */
    suspend fun askAndUnlock(reason: EditorialLockReason, seen: Int = generation): Boolean = mutex.withLock {
        if (generation != seen) return@withLock true
        var error: String? = null
        var outcome: Boolean? = null
        while (outcome == null) {
            val answer = CompletableDeferred<String?>()
            pending = answer
            promptState.value = PasswordPrompt(reason, error)
            val password = try { answer.await() } finally { pending = null }
            if (password == null) {
                promptState.value = null
                outcome = false
                continue
            }
            promptState.value = PasswordPrompt(reason, busy = true)
            try {
                lastState = unlock(password)
                generation += 1
                promptState.value = null
                outcome = true
            } catch (e: CancellationException) {
                promptState.value = null
                throw e
            } catch (e: Exception) {
                /* Password sbagliata (422), troppi tentativi (429), rete assente: si resta sulla richiesta con il motivo. */
                error = requestFailureMessage(e)
            }
        }
        outcome == true
    }

    /** La password scritta dalla persona. */
    fun submit(password: String) { pending?.complete(password) }

    /** La persona ha chiuso la richiesta: la chiamata fallisce con il suo 423. */
    fun dismiss() { pending?.complete(null) }

    /** Lo sblocco l'ha fatto qualcun altro (l'ingresso nell'area): le chiamate ferme possono riprovare. */
    fun markUnlocked(state: EditorialUnlockState) {
        lastState = state
        generation += 1
    }
}

/**
 * Il blocco dell'area sul telefono: chiusa all'inizio, si apre con
 * `unlock()` (blocco del telefono riuscito o password giusta) e si richiude
 * dopo `lockAfterMillis` in secondo piano o senza tocchi. L'orologio è
 * iniettato: nell'app è `SystemClock.elapsedRealtime`, che non salta se si
 * cambia l'ora del telefono.
 */
internal class EditorialLocalLock(
    private val clock: () -> Long,
    lockAfterMillis: Long = DEFAULT_LOCK_AFTER_MILLIS,
) {
    @Volatile var lockAfterMillis: Long = lockAfterMillis
        private set
    private val lockedState = MutableStateFlow(true)
    val locked: StateFlow<Boolean> = lockedState.asStateFlow()
    @Volatile private var lastActivity: Long = clock()
    @Volatile private var backgroundSince: Long? = null

    fun unlock() {
        lastActivity = clock()
        backgroundSince = null
        lockedState.value = false
    }

    fun lock() { lockedState.value = true }

    /**
     * Un tocco o un'azione: rimanda il blocco per inattività. Prima guarda se
     * il limite è già passato: un tocco dopo 15 minuti fermi chiude l'area,
     * non la tiene aperta per altri 15.
     */
    fun touch() { if (!checkIdle()) lastActivity = clock() }

    /**
     * Si entra nell'area: se è rimasta aperta (il repository vive finché il
     * token è lo stesso) e l'inattività ha già passato il limite, si chiude
     * subito, prima di mostrare qualcosa. Vero se adesso è chiusa.
     */
    fun enter(): Boolean = checkIdle()

    /** Si esce dall'area (Home, Profilo…): si chiude, e rientrando si riapre con il blocco del telefono. */
    fun leave() = lock()

    fun onBackground() { backgroundSince = clock() }

    /** Tornando in primo piano: chiude se è rimasta in secondo piano troppo a lungo. */
    fun onForeground() {
        val since = backgroundSince
        backgroundSince = null
        if (since != null && clock() - since >= lockAfterMillis) lock()
        else checkIdle()
    }

    /** Chiude se nessuno tocca lo schermo da troppo tempo; vero se adesso è chiusa. */
    fun checkIdle(): Boolean {
        if (!lockedState.value && clock() - lastActivity >= lockAfterMillis) lock()
        return lockedState.value
    }

    /** Il tempo deciso dal server (`unlock.lock_after_minutes`), mai sotto un minuto. */
    fun configure(minutes: Int) {
        lockAfterMillis = minutes.coerceAtLeast(1) * 60_000L
    }

    companion object {
        const val DEFAULT_LOCK_AFTER_MILLIS = 15 * 60_000L
    }
}
