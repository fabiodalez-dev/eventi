package it.fabiodalez.incitta.data

import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.Serializable

/*
 * `scannedAt` è l'istante della lettura (ISO 8601): il server lo registra come
 * ora d'ingresso se cade nella finestra della data. Le letture salvate da
 * versioni precedenti non lo hanno, e allora il server usa l'ora d'arrivo.
 */
@Serializable internal data class PendingCheckin(val userId: Long, val occurrenceId: Long, val code: String, val requestKey: String = UUID.randomUUID().toString(), val scannedAt: String? = null)
internal data class CheckinOutcome(val occurrenceId: Long, val accepted: Boolean, val message: String)

/** Persist before sending. A missing reply never means admission is authorized. */
internal class CheckinOutbox(
    private val userId: Long,
    private val read: () -> List<PendingCheckin>,
    private val write: (List<PendingCheckin>) -> Unit,
    private val send: suspend (PendingCheckin) -> CheckedInTicket,
) {
    private val mutex = Mutex()
    fun pending(): List<PendingCheckin> = read().filter { it.userId == userId }
    suspend fun add(date: Long, code: String, scannedAt: Instant = Instant.now()) = mutex.withLock {
        require(code.matches(Regex("[A-Za-z0-9]{64}")))
        val entries = pending()
        if (entries.any { it.occurrenceId == date && it.code == code }) return@withLock
        check(entries.size < 200)
        write(entries + PendingCheckin(userId, date, code, scannedAt = scannedAt.toString()))
    }
    suspend fun flush(changed: (CheckinOutcome?) -> Unit = {}) = mutex.withLock {
        for (entry in pending()) {
            try {
                val result = send(entry)
                write(pending().filterNot { it.requestKey == entry.requestKey })
                changed(CheckinOutcome(entry.occurrenceId, true, result.attendeeName))
            } catch (error: CancellationException) { throw error }
            catch (error: Exception) {
                // Retry transient responses and uncertain delivery with the original key.
                if (error is ApiException && error.status in listOf(403, 404, 422)) {
                    write(pending().filterNot { it.requestKey == entry.requestKey })
                    changed(CheckinOutcome(entry.occurrenceId, false, requestFailureMessage(error)))
                } else {
                    changed(null)
                    break
                }
            }
        }
    }
}
