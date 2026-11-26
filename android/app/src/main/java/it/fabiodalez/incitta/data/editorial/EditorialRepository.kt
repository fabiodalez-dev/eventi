package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.Session
import it.fabiodalez.incitta.data.management.ManagementOptions
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Le API e la memoria della redazione per una sessione: il banco, la città
 * scelta, gli elenchi del server e i due blocchi. Uno solo per account
 * collegato (lo tiene `EditorialGraph`): cambiando account o uscendo si
 * butta, e la redazione riparte chiusa.
 *
 * I pacchetti aggiungono qui la loro API come parametro con valore
 * predefinito `null` (come `ManagementRepository`), e la chiamano dentro
 * `call { … }`, che si occupa dei 423.
 */
internal class EditorialRepository(
    val userId: Long,
    val token: String,
    val desk: EditorialDeskSource,
    val localLock: EditorialLocalLock,
    /* Il client HTTP della sessione: lo usano le API dei pacchetti. */
    val client: ApiClient? = null,
) {
    val guard = EditorialUnlockGuard { password -> desk.unlock(password).also { localLock.unlock() } }

    private val deskState = MutableStateFlow<EditorialDesk?>(null)
    /** L'ultimo banco letto: ruolo, città, permessi, contatori. */
    val current: StateFlow<EditorialDesk?> = deskState.asStateFlow()
    /** Lo slug della città scelta nel selettore; `null` finché non se ne sceglie una (vale quella del server). */
    @Volatile var selectedCity: String? = null
    @Volatile private var enabledValue: Boolean? = null
    @Volatile private var cachedOptions: ManagementOptions? = null

    /** `features.editorial`, letto una volta per sessione. Un errore di rete non spegne l'area: rilancia. */
    suspend fun enabled(): Boolean = enabledValue ?: desk.enabled().also { enabledValue = it }

    /** Il banco per la città scelta. Il banco non chiede lo sblocco: dice proprio se c'è. */
    suspend fun loadDesk(): EditorialDesk = desk.desk(selectedCity).also { loaded ->
        deskState.value = loaded
        if (selectedCity == null) selectedCity = loaded.city?.slug
        localLock.configure(loaded.unlock.lockAfterMinutes)
        if (loaded.unlock.unlocked) guard.markUnlocked(loaded.unlock)
    }

    /** Cambia la città di lavoro e rilegge il banco (permessi e contatori sono della città). */
    suspend fun selectCity(slug: String): EditorialDesk {
        selectedCity = slug
        return loadDesk()
    }

    /** Gli elenchi del server; `null` se la chiamata non riesce (le schermate usano i valori di sempre). */
    suspend fun options(): ManagementOptions? {
        cachedOptions?.let { return it }
        return try { desk.options().also { cachedOptions = it } } catch (e: CancellationException) { throw e } catch (_: Exception) { null }
    }

    /** Ogni chiamata dei pacchetti passa da qui: con un 423 chiede la password, sblocca e ripete. */
    suspend fun <T> call(block: suspend () -> T): T = guard.run(block)

    /**
     * L'ingresso dopo il blocco del telefono: se il server non è sbloccato
     * chiede la password (vero se lo sblocco riesce, falso se la persona
     * rinuncia). Con il server già sbloccato apre subito.
     */
    suspend fun openAfterPhoneLock(): Boolean {
        val loaded = current.value ?: loadDesk()
        if (loaded.unlock.unlocked) {
            localLock.unlock()
            return true
        }
        val ok = guard.askAndUnlock(EditorialLockReason.Locked)
        if (ok) runCatching { loadDesk() }.exceptionOrNull()?.let { if (it is CancellationException) throw it }
        return ok
    }

    /**
     * Senza blocco del telefono (nessun PIN né impronta impostati) l'area si
     * apre solo con la password dell'account, ogni volta: rinnova anche lo
     * sblocco del server.
     */
    suspend fun openWithPassword(): Boolean {
        val ok = guard.askAndUnlock(EditorialLockReason.Locked)
        if (ok) runCatching { loadDesk() }.exceptionOrNull()?.let { if (it is CancellationException) throw it }
        return ok
    }

    companion object {
        fun create(session: Session, client: ApiClient, clock: () -> Long) = EditorialRepository(
            userId = session.user.id,
            token = session.token,
            desk = EditorialApi(client, session.token),
            localLock = EditorialLocalLock(clock),
            client = client,
        )
    }
}

/**
 * Tiene il repository della sessione collegata. Cambiando account (o uscendo)
 * il vecchio si butta, e con lui lo sblocco del telefono: la redazione di un
 * account non resta aperta per il successivo.
 */
internal object EditorialGraph {
    private var current: EditorialRepository? = null
    private var currentClient: ApiClient? = null

    @Synchronized
    fun repository(context: android.content.Context, session: Session, client: ApiClient? = null): EditorialRepository {
        current?.takeIf { it.token == session.token && it.userId == session.user.id && (client == null || client === currentClient) }?.let { return it }
        val api = client ?: ApiClient(LocalStore(context.applicationContext).installationId)
        return EditorialRepository.create(session, api, android.os.SystemClock::elapsedRealtime).also {
            current = it
            currentClient = api
        }
    }

    /** Butta il repository se la sessione non è più quella: lo chiama l'app a ogni cambio di token. */
    @Synchronized
    fun retain(token: String?) {
        if (current != null && current?.token != token) {
            current = null
            currentClient = null
        }
    }
}
