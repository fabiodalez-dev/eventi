package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CheckinManifestTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val valid = "A".repeat(64)
    private val used = "B".repeat(64)
    // sha256("A" x 64), calcolato fuori dall'app: lo stesso valore di hash('sha256', $code) in PHP.
    private val validHash = "d53eda7a637c99cc7fb566d96e9fa109bf15c478410a3f5eb4d4c4e26cd081f6"

    private fun manifest(): CheckinManifest {
        val payload = json.decodeFromString<ApiEnvelope<ManifestPayload>>("""{"data":{"generated_at":"2026-10-23T19:30:00+02:00",
            "window":{"from":"2026-10-23T18:00:00+02:00","to":"2026-10-24T02:00:00+02:00"},
            "tickets":[{"h":"$validHash","name":"Ada Rossi","status":"valid"},
                       {"h":"${CheckinManifest.hash(used)}","name":"Bruno Bianchi","status":"checked_in"}]}}""").data
        return CheckinManifest.from(12, payload, nowMillis = 1_000)
    }

    @Test fun `hash is lowercase hexadecimal SHA-256 of the code`() {
        assertEquals(validHash, CheckinManifest.hash(valid))
    }

    @Test fun `known code is found by its real hash`() {
        val list = manifest()
        assertEquals(2, list.size)
        assertEquals("Ada Rossi", list.lookup(valid)?.name)
        assertEquals(ManifestVerdict.Known("Ada Rossi"), list.verdict(valid))
    }

    @Test fun `unknown code is rejected without a name`() {
        val list = manifest()
        assertNull(list.lookup("C".repeat(64)))
        assertEquals(ManifestVerdict.Unknown, list.verdict("C".repeat(64)))
    }

    @Test fun `ticket already checked in on the server is reported as used`() {
        assertEquals(ManifestVerdict.AlreadyUsed("Bruno Bianchi"), manifest().verdict(used))
    }

    @Test fun `markUsed makes a second scan on this phone an admission already registered`() {
        val first = manifest()
        val marked = first.markUsed(valid)
        assertEquals(ManifestVerdict.Known("Ada Rossi"), first.verdict(valid))
        assertEquals(ManifestVerdict.AlreadyUsed("Ada Rossi"), marked.verdict(valid))
        assertEquals(marked, marked.markUsed(valid))
    }

    @Test fun `manifest survives serialization for encrypted storage`() {
        val marked = manifest().markUsed(valid)
        assertEquals(marked, json.decodeFromString<CheckinManifest>(json.encodeToString(CheckinManifest.serializer(), marked)))
    }

    @Test fun `a refreshed list keeps local marks that the server has not confirmed yet`() {
        val local = manifest().markUsed(valid)
        val fresh = manifest().refreshedWith(local)
        assertEquals(ManifestVerdict.AlreadyUsed("Ada Rossi"), fresh.verdict(valid))
        assertEquals(manifest(), manifest().refreshedWith(null))
    }

    @Test fun `lists older than 48 hours are expired`() {
        val list = manifest()
        assertFalse(list.expired(1_000 + CheckinManifest.MAX_AGE_MILLIS))
        assertTrue(list.expired(1_001 + CheckinManifest.MAX_AGE_MILLIS))
    }

    @Test fun `with network every scan goes to the server, even codes missing from the downloaded list`() {
        assertEquals(ScanDecision.SendWarnUnknown, scanDecision(true, ManifestVerdict.Unknown))
        assertEquals(ScanDecision.SendWarnUsed, scanDecision(true, ManifestVerdict.AlreadyUsed("Ada")))
        assertEquals(ScanDecision.Send, scanDecision(true, ManifestVerdict.Known("Ada")))
        assertEquals(ScanDecision.PendingUnknown, scanDecision(false, ManifestVerdict.Unknown))
        assertEquals(ScanDecision.RejectUsed, scanDecision(false, ManifestVerdict.AlreadyUsed("Ada")))
        assertEquals(ScanDecision.Pending, scanDecision(false, ManifestVerdict.Known("Ada")))
        assertEquals(ScanDecision.Pending, scanDecision(false, null))
    }
}
