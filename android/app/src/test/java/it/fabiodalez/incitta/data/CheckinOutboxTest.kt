package it.fabiodalez.incitta.data

import java.io.IOException
import kotlinx.coroutines.test.runTest
import org.junit.Assert.*
import org.junit.Test

class CheckinOutboxTest {
    @Test fun lostReplySurvivesRecreationWithTheSameKey() = runTest {
        var stored = emptyList<PendingCheckin>()
        val sent = mutableListOf<String>()
        val first = CheckinOutbox(7, { stored }, { stored = it }, { sent += it.requestKey; throw IOException() })
        first.add(19, "A".repeat(64)); first.flush()
        assertEquals(1, stored.size)
        val restored = CheckinOutbox(7, { stored }, { stored = it }, { sent += it.requestKey; CheckedInTicket(1, "Ada", "checked_in") })
        restored.flush()
        assertEquals(sent[0], sent[1]); assertTrue(stored.isEmpty())
    }
    @Test fun duplicatePendingReadIsDeduplicatedButANewScanHasANewKey() = runTest {
        var stored = emptyList<PendingCheckin>()
        val keys = mutableListOf<String>()
        val queue = CheckinOutbox(7, { stored }, { stored = it }, { keys += it.requestKey; CheckedInTicket(1, "Ada", "checked_in") })
        queue.add(1, "B".repeat(64)); queue.add(1, "B".repeat(64)); assertEquals(1, stored.size)
        queue.flush(); queue.add(1, "B".repeat(64)); queue.flush()
        assertEquals(2, keys.size); assertNotEquals(keys[0], keys[1])
    }
    @Test fun anotherAccountNeverSendsPendingReads() = runTest {
        var stored = listOf(PendingCheckin(7, 1, "C".repeat(64)))
        val queue = CheckinOutbox(8, { stored }, { stored = it }, { error("Must not send another account's ticket") })
        queue.flush(); assertEquals(1, stored.size); assertTrue(queue.pending().isEmpty())
    }
    @Test fun terminalRejectionIsRemovedAndCannotBecomeAdmission() = runTest {
        var stored = emptyList<PendingCheckin>()
        val queue = CheckinOutbox(7, { stored }, { stored = it }, { throw ApiException(403, null) })
        queue.add(1, "D".repeat(64))
        var outcome: CheckinOutcome? = null
        queue.flush { outcome = it }
        assertFalse(outcome!!.accepted); assertTrue(stored.isEmpty())
    }
    @Test fun invalidCodesAreNeverStored() = runTest {
        var stored = emptyList<PendingCheckin>()
        val queue = CheckinOutbox(7, { stored }, { stored = it }, { error("Must not send") })
        assertTrue(runCatching { queue.add(1, "https://example.test/") }.isFailure)
        assertTrue(stored.isEmpty())
    }
}
