package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.PendingCheckin
import java.io.IOException
import java.time.Instant
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.*
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ManagementRepositoryTest {
    private val code = "A".repeat(64)

    @Test fun pendingReadsAreRetriedEveryFifteenSecondsWithoutAnyScreen() = runTest {
        val device = MemoryDevice()
        val ticketing = FakeTicketing().apply { checkInFailure = IOException("rete") }
        val scope = TestScope(testScheduler)
        val repository = fakeRepository(scope, ticketing, device = device)
        repository.addCheckin(41, code, Instant.parse("2026-09-25T20:01:00Z"))
        assertEquals(1, repository.checkins.value.pending.size)
        assertEquals(1, ticketing.calls.count { it.startsWith("checkin") })

        scope.advanceTimeBy(14_999); scope.runCurrent()
        assertEquals(1, ticketing.calls.count { it.startsWith("checkin") })
        scope.advanceTimeBy(2); scope.runCurrent()
        assertEquals(2, ticketing.calls.count { it.startsWith("checkin") })

        ticketing.checkInFailure = null
        scope.advanceTimeBy(15_001); scope.runCurrent()
        assertTrue(repository.checkins.value.pending.isEmpty())
        assertTrue(device.checkins.isEmpty())
        assertEquals("Ada Rossi", repository.checkins.value.outcomes.first().message)
        assertTrue(repository.checkins.value.outcomes.first().accepted)

        /* Coda vuota: il giro si ferma, niente altre chiamate. */
        val calls = ticketing.calls.size
        scope.advanceTimeBy(60_000); scope.runCurrent()
        assertEquals(calls, ticketing.calls.size)
        repository.close()
    }

    @Test fun readsLeftFromAnEarlierSessionAreSentAsSoonAsTheRepositoryStarts() = runTest {
        val device = MemoryDevice().apply { checkins = listOf(PendingCheckin(7, 41, code)) }
        val ticketing = FakeTicketing()
        val scope = TestScope(testScheduler)
        val repository = fakeRepository(scope, ticketing, device = device)
        scope.runCurrent()
        assertTrue(repository.checkins.value.pending.isEmpty())
        assertEquals(listOf("checkin:41:AAAA"), ticketing.calls)
        repository.close()
    }

    @Test fun anotherAccountsReadsNeverLeaveAndClosingStopsTheRetries() = runTest {
        val device = MemoryDevice().apply { checkins = listOf(PendingCheckin(8, 41, code)) }
        val ticketing = FakeTicketing().apply { checkInFailure = IOException("rete") }
        val scope = TestScope(testScheduler)
        val repository = fakeRepository(scope, ticketing, device = device, userId = 7)
        scope.advanceTimeBy(60_000); scope.runCurrent()
        assertTrue(ticketing.calls.isEmpty())
        assertTrue(repository.checkins.value.pending.isEmpty())

        repository.addCheckin(41, "B".repeat(64), Instant.now())
        repository.close()
        val calls = ticketing.calls.size
        scope.advanceTimeBy(60_000); scope.runCurrent()
        assertEquals(calls, ticketing.calls.size)
        /* La lettura resta salvata, cifrata, per quando l'account rientra. */
        assertEquals(listOf(7L), device.checkins.map { it.userId })
    }

    @Test fun aRejectedReadLeavesTheQueueAndShowsTheReason() = runTest {
        val ticketing = FakeTicketing().apply { checkInFailure = ApiException(422, it.fabiodalez.incitta.data.ApiProblem(message = "Biglietto annullato")) }
        val scope = TestScope(testScheduler)
        val repository = fakeRepository(scope, ticketing)
        repository.addCheckin(41, code, Instant.now())
        assertTrue(repository.checkins.value.pending.isEmpty())
        assertFalse(repository.checkins.value.outcomes.first().accepted)
        assertEquals("Biglietto annullato", repository.checkins.value.outcomes.first().message)
        repository.close()
    }

    @Test fun landingFallsBackToTheDatesListWheneverSomethingIsMissing() = runTest {
        val scope = TestScope(testScheduler)
        val venue = ManagedVenue(id = 12, name = "Teatro")
        assertEquals(ManagementLanding.TicketingDates, fakeRepository(scope, identity = FakeIdentity(enabled = false, list = ManagementSubjects(listOf(venue)))).landing())
        assertEquals(ManagementLanding.TicketingDates, fakeRepository(scope, identity = FakeIdentity(failure = IOException("senza rete"))).landing())
        assertEquals(ManagementLanding.TicketingDates, fakeRepository(scope, identity = FakeIdentity(list = ManagementSubjects(checkinOnly = true))).landing())
        val repository = fakeRepository(scope, identity = FakeIdentity(list = ManagementSubjects(listOf(venue), listOf(ManagedOrganizer(id = 3)))))
        assertEquals(ManagementLanding.Subject("venue:12"), repository.landing())
        assertEquals(venue.toSubject(), repository.subjects.value?.all()?.first())
        /* Il soggetto scelto resta per la sessione. */
        repository.selectedSubjectKey = "organizer:3"
        assertEquals(ManagementLanding.Subject("organizer:3"), repository.landing())
    }

    @Test fun optionsAreAskedOnlyWithTheNewAreaOn() = runTest {
        val scope = TestScope(testScheduler)
        val off = fakeRepository(scope, identity = FakeIdentity(enabled = false))
        off.landing()
        assertNull(off.options())
        val on = fakeRepository(scope, identity = FakeIdentity(options = ManagementOptions.from(kotlinx.serialization.json.buildJsonObject { put("closures", kotlinx.serialization.json.JsonPrimitive(20)) })))
        on.landing()
        assertEquals(20L, on.options()?.limit("closures"))
    }
}
