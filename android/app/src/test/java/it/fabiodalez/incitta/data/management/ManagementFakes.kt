package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiEnvelope
import it.fabiodalez.incitta.data.AttendeeMessage
import it.fabiodalez.incitta.data.Booking
import it.fabiodalez.incitta.data.CheckedInTicket
import it.fabiodalez.incitta.data.CheckinManifest
import it.fabiodalez.incitta.data.ManagedCampaign
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.ManifestPayload
import it.fabiodalez.incitta.data.ManifestTicket
import it.fabiodalez.incitta.data.PageMeta
import it.fabiodalez.incitta.data.PendingCheckin
import it.fabiodalez.incitta.data.TicketingApi
import kotlinx.coroutines.CoroutineScope
import kotlinx.serialization.json.JsonObject

/* Finti per provare repository e ViewModel della gestione senza rete né Android. */

internal class MemoryDevice : ManagementDeviceStore {
    var checkins = emptyList<PendingCheckin>()
    val manifests = mutableMapOf<Long, CheckinManifest>()
    override fun readCheckins() = checkins
    override fun writeCheckins(entries: List<PendingCheckin>) { checkins = entries }
    override fun readManifest(occurrenceId: Long) = manifests[occurrenceId]
    override fun writeManifest(occurrenceId: Long, manifest: CheckinManifest) { manifests[occurrenceId] = manifest }
}

internal class FakeTicketing(
    var date: ManagedDate = ManagedDate(41, "Concerto della sera", canManage = true, canCheckIn = true),
    var manifestTickets: List<ManifestTicket> = emptyList(),
) : TicketingApi {
    val calls = mutableListOf<String>()
    var failDates: Exception? = null
    var checkInFailure: Exception? = null
    override suspend fun dates(past: Boolean, page: Int): ApiEnvelope<List<ManagedDate>> {
        calls += "dates:${if (past) "past" else "upcoming"}:$page"
        failDates?.let { throw it }
        return ApiEnvelope(listOf(date.copy(id = date.id + page - 1)), PageMeta(nextPage = if (page == 1) 2 else null))
    }
    override suspend fun date(id: Long): ManagedDate { calls += "date:$id"; return date.copy(id = id) }
    override suspend fun staff(id: Long, email: String, remove: Boolean) { calls += "staff:$id:$email:$remove" }
    override suspend fun details(id: Long, body: JsonObject) { calls += "details:$id:$body" }
    override suspend fun campaigns(page: Int): ApiEnvelope<List<ManagedCampaign>> { calls += "campaigns:$page"; return ApiEnvelope(listOf(ManagedCampaign(7, "Campagna")), PageMeta()) }
    override suspend fun campaign(id: Long): ManagedCampaign { calls += "campaign:$id"; return ManagedCampaign(id, "Campagna") }
    override suspend fun message(id: Long, subject: String, body: String, includeWaitlisted: Boolean): AttendeeMessage { calls += "message:$id:$subject"; return AttendeeMessage(1, recipientsCount = 12) }
    override suspend fun guestBooking(id: Long, names: String, email: String?, phone: String?, note: String?): Booking {
        calls += "guest:$id:$names"
        return Booking(id = 1, occurrenceId = id, title = "Concerto", status = "confirmed")
    }
    override suspend fun checkIn(read: PendingCheckin): CheckedInTicket {
        calls += "checkin:${read.occurrenceId}:${read.code.take(4)}"
        checkInFailure?.let { throw it }
        return CheckedInTicket(1, "Ada Rossi", "checked_in")
    }
    override suspend fun manifest(id: Long): ManifestPayload { calls += "manifest:$id"; return ManifestPayload("2026-09-25T18:00:00Z", tickets = manifestTickets) }
}

internal class FakeIdentity(
    var enabled: Boolean = true,
    var list: ManagementSubjects = ManagementSubjects(),
    var options: ManagementOptions = ManagementOptions(),
    var failure: Exception? = null,
) : ManagementIdentitySource {
    var subjectCalls = 0
    override suspend fun managementEnabled(): Boolean { failure?.let { throw it }; return enabled }
    override suspend fun subjects(): ManagementSubjects { subjectCalls++; failure?.let { throw it }; return list }
    override suspend fun options(): ManagementOptions = options
}

internal fun fakeRepository(
    scope: CoroutineScope,
    ticketing: FakeTicketing = FakeTicketing(),
    identity: FakeIdentity = FakeIdentity(),
    device: MemoryDevice = MemoryDevice(),
    userId: Long = 7,
) = ManagementRepository(userId, "token", ticketing, identity, null, device, scope)
