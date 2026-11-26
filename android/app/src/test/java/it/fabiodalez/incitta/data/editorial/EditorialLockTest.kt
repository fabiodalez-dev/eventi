package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import java.io.IOException
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.async
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.*
import org.junit.Test

/* I due blocchi della redazione: i 423 del server con la richiesta della password, e il blocco del telefono dopo 15 minuti. */
@OptIn(ExperimentalCoroutinesApi::class)
class EditorialLockTest {
    private fun locked(code: String = "EDITORIAL_LOCKED") = ApiException(423, ApiProblem(message = "Bloccata", code = code))

    @Test fun recognisesOnlyTheEditorial423() {
        assertEquals(EditorialLockReason.Locked, editorialLockReason(locked()))
        assertEquals(EditorialLockReason.PasswordRequired, editorialLockReason(locked("PASSWORD_REQUIRED")))
        /* Un 423 senza codice resta un blocco: si chiede la password. */
        assertEquals(EditorialLockReason.Locked, editorialLockReason(ApiException(423, null)))
        assertNull(editorialLockReason(ApiException(403, ApiProblem(code = "FORBIDDEN"))))
        assertNull(editorialLockReason(IOException("rete")))
    }

    @Test fun asksThePasswordUnlocksAndRepeatsTheCall() = runTest {
        val passwords = mutableListOf<String>()
        val guard = EditorialUnlockGuard { password -> passwords += password; EditorialUnlockState(unlocked = true) }
        var calls = 0
        val result = async { guard.run { calls += 1; if (calls == 1) throw locked("PASSWORD_REQUIRED") else "fatto" } }
        runCurrent()
        assertEquals(EditorialLockReason.PasswordRequired, guard.prompt.value?.reason)
        guard.submit("segreta")
        assertEquals("fatto", result.await())
        assertEquals(listOf("segreta"), passwords)
        assertEquals(2, calls)
        assertNull(guard.prompt.value)
        assertTrue(guard.lastState?.unlocked == true)
    }

    @Test fun aWrongPasswordStaysOnThePromptWithTheReason() = runTest {
        val guard = EditorialUnlockGuard { password ->
            if (password != "giusta") throw ApiException(422, ApiProblem(message = "Alcuni dati non sono validi.", fields = mapOf("password" to listOf("La password non è corretta."))))
            EditorialUnlockState(unlocked = true)
        }
        var calls = 0
        val result = async { guard.run { calls += 1; if (calls == 1) throw locked() else calls } }
        runCurrent()
        guard.submit("sbagliata")
        runCurrent()
        assertEquals("La password non è corretta.", guard.prompt.value?.error)
        assertFalse(guard.prompt.value?.busy ?: true)
        guard.submit("giusta")
        assertEquals(2, result.await())
    }

    @Test fun closingThePromptFailsTheCallWithItsOwn423() = runTest {
        val guard = EditorialUnlockGuard { EditorialUnlockState(unlocked = true) }
        val result = async { runCatching { guard.run<String> { throw locked() } } }
        runCurrent()
        guard.dismiss()
        val failure = result.await().exceptionOrNull()
        assertEquals(423, (failure as ApiException).status)
        assertNull(guard.prompt.value)
    }

    @Test fun otherErrorsPassThroughWithoutAskingAnything() = runTest {
        val guard = EditorialUnlockGuard { error("non deve sbloccare") }
        val failure = runCatching { guard.run<String> { throw ApiException(403, ApiProblem(code = "FORBIDDEN")) } }.exceptionOrNull()
        assertEquals(403, (failure as ApiException).status)
        assertNull(guard.prompt.value)
    }

    @Test fun twoBlockedCallsShareOnePrompt() = runTest {
        var unlocks = 0
        val guard = EditorialUnlockGuard { unlocks += 1; EditorialUnlockState(unlocked = true) }
        var open = false
        val first = async { guard.run { if (!open) throw locked() else "uno" } }
        val second = async { guard.run { if (!open) throw locked() else "due" } }
        runCurrent()
        open = true
        guard.submit("segreta")
        assertEquals("uno", first.await())
        assertEquals("due", second.await())
        assertEquals(1, unlocks)
    }

    @Test fun theAreaLocksAfterFifteenMinutesInTheBackgroundOrWithoutTouches() {
        var now = 0L
        val lock = EditorialLocalLock(clock = { now })
        assertTrue(lock.locked.value)
        lock.unlock()
        assertFalse(lock.locked.value)

        /* In secondo piano per meno di 15 minuti: resta aperta. */
        lock.onBackground(); now += 14 * 60_000L; lock.onForeground()
        assertFalse(lock.locked.value)
        /* Un tocco rimanda il conto dell'inattività. */
        lock.touch(); now += 14 * 60_000L
        assertFalse(lock.checkIdle())
        now += 60_000L
        assertTrue(lock.checkIdle())

        lock.unlock()
        lock.onBackground(); now += 15 * 60_000L; lock.onForeground()
        assertTrue(lock.locked.value)
    }

    @Test fun aTouchAfterTheLimitClosesTheAreaInsteadOfResettingTheCount() {
        var now = 0L
        val lock = EditorialLocalLock(clock = { now })
        lock.unlock()
        /* Lasciata aperta e ripresa dopo 16 minuti: il primo tocco non deve azzerare il conto. */
        now += 16 * 60_000L
        lock.touch()
        assertTrue(lock.locked.value)
        /* E non la riapre nemmeno un tocco subito dopo. */
        lock.touch()
        assertTrue(lock.locked.value)
    }

    @Test fun enteringTheAreaAfterTheLimitClosesItAtOnce() {
        var now = 0L
        val lock = EditorialLocalLock(clock = { now })
        lock.unlock()
        /* Entro il limite si rientra aperti. */
        now += 10 * 60_000L
        assertFalse(lock.enter())
        /* Oltre il limite (l'app era tornata alla Home, senza osservatore del ciclo di vita): chiusa subito. */
        now += 15 * 60_000L
        assertTrue(lock.enter())
        assertTrue(lock.locked.value)
    }

    @Test fun leavingTheAreaClosesIt() {
        var now = 0L
        val lock = EditorialLocalLock(clock = { now })
        lock.unlock()
        lock.leave()
        assertTrue(lock.locked.value)
        /* Rientrando un istante dopo resta chiusa: si riapre solo con il blocco del telefono. */
        now += 1_000L
        assertTrue(lock.enter())
        lock.touch()
        assertTrue(lock.locked.value)
        lock.unlock()
        assertFalse(lock.locked.value)
    }

    @Test fun theServerDecidesTheMinutesButNeverBelowOne() {
        var now = 0L
        val lock = EditorialLocalLock(clock = { now })
        lock.configure(5)
        lock.unlock(); now += 5 * 60_000L
        assertTrue(lock.checkIdle())
        lock.configure(0)
        assertEquals(60_000L, lock.lockAfterMillis)
        /* Chiusa, un tocco non la riapre. */
        lock.touch()
        assertTrue(lock.locked.value)
    }

    @Test fun thePasswordAlsoOpensThePhoneLock() = runTest {
        var now = 0L
        val desk = FakeDesk()
        val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { now }))
        assertTrue(repository.localLock.locked.value)
        val opened = async { repository.openWithPassword() }
        runCurrent()
        assertEquals(EditorialLockReason.Locked, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertTrue(opened.await())
        assertFalse(repository.localLock.locked.value)
        assertEquals(listOf("unlock:segreta", "desk:null"), desk.calls)
    }

    @Test fun afterThePhoneLockAnUnlockedServerOpensWithoutThePassword() = runTest {
        val desk = FakeDesk(unlocked = true)
        val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))
        assertTrue(repository.openAfterPhoneLock())
        assertFalse(repository.localLock.locked.value)
        assertNull(repository.guard.prompt.value)
        assertEquals(listOf("desk:null"), desk.calls)
    }

    @Test fun choosingACityReadsTheDeskOfThatCity() = runTest {
        val desk = FakeDesk()
        val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L }))
        repository.loadDesk()
        assertEquals("padova", repository.selectedCity)
        repository.selectCity("vicenza")
        assertEquals(listOf("desk:null", "desk:vicenza"), desk.calls)
    }
}

/* Un server finto per le fondamenta: registra le chiamate. */
internal class FakeDesk(private val unlocked: Boolean = false) : EditorialDeskSource {
    val calls = mutableListOf<String>()
    override suspend fun enabled() = true
    override suspend fun desk(city: String?): EditorialDesk {
        calls += "desk:$city"
        return EditorialDesk(role = "admin", city = EditorialCity(1, city ?: "padova", "Padova"), unlock = EditorialUnlockState(unlocked = unlocked))
    }
    override suspend fun options() = it.fabiodalez.incitta.data.management.ManagementOptions()
    override suspend fun unlock(password: String): EditorialUnlockState {
        calls += "unlock:$password"
        return EditorialUnlockState(unlocked = true)
    }
}
