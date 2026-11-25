package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.ContactResent
import it.fabiodalez.incitta.data.management.ContactSettings
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.HourDraft
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagementProfileSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.ManagementSubjects
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.data.management.NoticeState
import it.fabiodalez.incitta.data.management.OpeningHourRow
import it.fabiodalez.incitta.data.management.OrganizerProfile
import it.fabiodalez.incitta.data.management.PublishedNotice
import it.fabiodalez.incitta.data.management.VenueNotice
import it.fabiodalez.incitta.data.management.VenueProfile
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.profile.OrganizerProfileViewModel
import it.fabiodalez.incitta.ui.management.profile.VenueImages
import it.fabiodalez.incitta.ui.management.profile.VenueNoticeViewModel
import it.fabiodalez.incitta.ui.management.profile.VenuePhoto
import it.fabiodalez.incitta.ui.management.profile.VenueProfileViewModel
import it.fabiodalez.incitta.ui.management.profile.VenueSectionViewModel
import it.fabiodalez.incitta.ui.management.profile.movedOrder
import it.fabiodalez.incitta.ui.management.profile.nextHourRow
import it.fabiodalez.incitta.ui.management.profile.sectionStatus
import it.fabiodalez.incitta.ui.management.profile.venueImagesFrom
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* I ViewModel del profilo con un server finto: una sezione alla volta, errori sotto il campo, modulo che sopravvive. */
@OptIn(ExperimentalCoroutinesApi::class)
class ProfileViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeProfile : ManagementProfileSource {
        var venue = VenueProfile(id = 12, name = "Circolo Aurora", openingHours = listOf(OpeningHourRow("fri", "18:00", "02:00")), capacity = 90,
            notices = NoticeState(followers = 3))
        var organizer = OrganizerProfile(id = 3, name = "Collettivo Portici", description = "<p>Chi siamo</p>")
        val sent = mutableListOf<Pair<String, JsonObject>>()
        var failure: Exception? = null
        override suspend fun venueProfile(venueId: Long) = venue.also { sent += "get:$venueId" to JsonObject(emptyMap()) }
        override suspend fun updateVenueProfile(venueId: Long, changes: JsonObject): VenueProfile {
            sent += "patch:$venueId" to changes
            failure?.let { throw it }
            val rows = changes["opening_hours"]?.jsonArray?.map { OpeningHourRow(it.jsonObject["day"]!!.jsonPrimitive.content, it.jsonObject["open"]!!.jsonPrimitive.content, it.jsonObject["close"]!!.jsonPrimitive.content) }
            venue = venue.copy(openingHours = rows ?: venue.openingHours)
            return venue
        }
        override suspend fun publishNotice(venueId: Long, kind: String, body: String, until: String?): PublishedNotice {
            sent += "notice:$kind:$body:$until" to JsonObject(emptyMap())
            failure?.let { throw it }
            return PublishedNotice(VenueNotice(kind = kind, body = body), notified = 3)
        }
        override suspend fun organizerProfile(organizerId: Long) = organizer
        override suspend fun updateOrganizerProfile(organizerId: Long, changes: JsonObject): OrganizerProfile {
            sent += "org:$organizerId" to changes
            return organizer.copy(description = changes["description"]?.jsonPrimitive?.content)
        }
        override suspend fun resendContactConfirmation(subject: ManagementSubject) = ContactResent(ContactSettings(email = "a@b.test", pending = true), "Inviata")
    }

    private fun repository(scope: CoroutineScope, profile: FakeProfile, identity: FakeIdentity = FakeIdentity()) =
        ManagementRepository(7, "token", FakeTicketing(), identity, null, MemoryDevice(), scope, profile = profile)

    private fun validation(vararg fields: Pair<String, String>) = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", fields.associate { it.first to listOf(it.second) }))

    @Test fun aSectionSavesOnlyItsKeysAndShowsTheSavedProfile() = runTest(dispatcher) {
        val profile = FakeProfile()
        val saved = SavedStateHandle(mapOf(VenueProfileViewModel.KEY_VENUE to 12L, VenueSectionViewModel.KEY_SECTION to VenueSection.Hours.name))
        val viewModel = VenueSectionViewModel(repository(TestScope(testScheduler), profile), saved)
        assertEquals(VenueSection.Hours, viewModel.section)
        assertEquals(listOf(HourDraft("fri", "18:00", "02:00")), viewModel.uiState.value.draft?.hours)

        viewModel.edit { it.copy(hours = it.hours + HourDraft("sat", "10:00", "13:00")) }
        viewModel.save()

        val (call, body) = profile.sent.last()
        assertEquals("patch:12", call)
        assertEquals(setOf("opening_hours", "closures", "capacity"), body.keys)
        assertEquals(2, (viewModel.uiState.value.profile as Loadable.Ready).value.openingHours.size)
        assertEquals(UiText.Res(R.string.profile_saved), viewModel.uiState.value.message)
        assertFalse(viewModel.uiState.value.busy)
    }

    @Test fun serverErrorsGoUnderTheRowThatCausedThem() = runTest(dispatcher) {
        val profile = FakeProfile().apply { failure = validation("opening_hours.1.close" to "Il campo Alle non è valido.", "capacity" to "Troppo grande.", "altro" to "Qualcosa d'altro") }
        val saved = SavedStateHandle(mapOf(VenueProfileViewModel.KEY_VENUE to 12L, VenueSectionViewModel.KEY_SECTION to VenueSection.Hours.name))
        val viewModel = VenueSectionViewModel(repository(TestScope(testScheduler), profile), saved)
        viewModel.edit { it.copy(hours = it.hours + HourDraft("sat", "10:00", "")) }
        viewModel.save()
        val errors = viewModel.uiState.value.errors
        assertEquals("Il campo Alle non è valido.", errors.fields["opening_hours.1"])
        assertNull(errors.fields["opening_hours"])
        assertEquals("Troppo grande.", errors.field("capacity"))
        assertEquals("Qualcosa d'altro", errors.general)
        /* Il modulo resta com'era: niente lavoro perso. */
        assertEquals(2, viewModel.uiState.value.draft?.hours?.size)
    }

    @Test fun aStartedFormSurvivesTheProcess() = runTest(dispatcher) {
        val profile = FakeProfile()
        val saved = SavedStateHandle(mapOf(VenueProfileViewModel.KEY_VENUE to 12L, VenueSectionViewModel.KEY_SECTION to VenueSection.Description.name))
        VenueSectionViewModel(repository(TestScope(testScheduler), profile), saved).edit { it.copy(shortDescription = "Scritta a metà") }
        /* Il sistema chiude l'app e la riapre: il ViewModel nuovo riparte dallo stato salvato, non dalla scheda del server. */
        val again = VenueSectionViewModel(repository(TestScope(testScheduler), profile), saved)
        assertEquals("Scritta a metà", again.uiState.value.draft?.shortDescription)
    }

    @Test fun noticeKeepsTheTextWhenTooSoonAndClearsItWhenSent() = runTest(dispatcher) {
        val profile = FakeProfile().apply { failure = validation("body" to "Hai già pubblicato un avviso negli ultimi 7 giorni.") }
        val saved = SavedStateHandle(mapOf(VenueProfileViewModel.KEY_VENUE to 12L))
        val viewModel = VenueNoticeViewModel(repository(TestScope(testScheduler), profile), saved)
        viewModel.setKind("pause")
        viewModel.setBody("Chiusi per ferie")
        viewModel.setUntil("2026-12-01")
        viewModel.publish()
        assertEquals("notice:pause:Chiusi per ferie:2026-12-01", profile.sent.last().first)
        assertEquals("Hai già pubblicato un avviso negli ultimi 7 giorni.", viewModel.uiState.value.errors.field("body"))
        assertEquals("Chiusi per ferie", viewModel.uiState.value.body)

        profile.failure = null
        viewModel.setUntil("")
        viewModel.publish()
        /* Pubblicato senza scadenza, poi la scheda si rilegge per mostrare l'ultimo avviso. */
        assertEquals(listOf("notice:pause:Chiusi per ferie:null", "get:12"), profile.sent.takeLast(2).map { it.first })
        assertEquals("", viewModel.uiState.value.body)
        assertEquals("", saved.get<String>(VenueNoticeViewModel.KEY_BODY))
        assertEquals(UiText.Plural(R.plurals.profile_notice_published, 3, listOf(3)), viewModel.uiState.value.message)
    }

    @Test fun organizerSavesItsWholeFormAndResendsTheConfirmation() = runTest(dispatcher) {
        val profile = FakeProfile()
        val identity = FakeIdentity(list = ManagementSubjects(organizers = listOf(ManagedOrganizer(id = 3, role = "owner"))))
        val repository = repository(TestScope(testScheduler), profile, identity)
        repository.loadSubjects()
        val viewModel = OrganizerProfileViewModel(repository, SavedStateHandle(mapOf(OrganizerProfileViewModel.KEY_ORGANIZER to 3L)))
        assertEquals("Chi siamo", viewModel.uiState.value.draft?.description)
        viewModel.edit { it.copy(description = "Chi **siamo**") }
        viewModel.save()
        val (call, body) = profile.sent.last()
        assertEquals("org:3", call)
        assertEquals(setOf("description", "website", "email", "contact_mode", "contact_email"), body.keys)
        assertEquals("<p>Chi <strong>siamo</strong></p>", body["description"]!!.jsonPrimitive.content)
        assertEquals("Chi **siamo**", viewModel.uiState.value.draft?.description)

        viewModel.resendContact()
        assertEquals(UiText.Raw("Inviata"), viewModel.uiState.value.message)
        assertEquals(true, (viewModel.uiState.value.profile as Loadable.Ready).value.contact.pending)
    }

    @Test fun statusLinesAndHelpers() {
        val profile = Json { ignoreUnknownKeys = true }.decodeFromString(VenueProfile.serializer(),
            """{"short_description": "", "description": "<p>Musica <strong>dal vivo</strong></p>", "address": "Via Roma 1", "municipality": "Padova",
               "content_details": {"stroller": "yes", "age_groups": [], "membership_notes": null, "faqs": [{"question": "a", "answer": "b"}]},
               "contact": {"mode": "disabled"}}""")
        assertEquals(UiText.Raw("Musica dal vivo"), sectionStatus(VenueSection.Description, profile))
        assertEquals(UiText.Raw("Via Roma 1 · Padova"), sectionStatus(VenueSection.Where, profile))
        assertEquals(UiText.Plural(R.plurals.profile_status_rows, 1, listOf(1)), sectionStatus(VenueSection.BeforeGoing, profile))
        assertEquals(UiText.Plural(R.plurals.profile_status_faqs, 1, listOf(1)), sectionStatus(VenueSection.Editorial, profile))
        assertEquals(UiText.Res(R.string.profile_to_fill), sectionStatus(VenueSection.Transit, profile))
        assertEquals(UiText.Res(R.string.profile_status_contact_off), sectionStatus(VenueSection.ContactForm, profile))

        assertEquals(HourDraft("sat", "18:00", "02:00"), nextHourRow(listOf(HourDraft("fri", "18:00", "02:00"))))
        assertEquals(HourDraft("mon", "18:00", "23:00"), nextHourRow(listOf(HourDraft("sun", "18:00", "23:00"))))
        assertEquals(listOf(2L, 1L, 3L), movedOrder(listOf(1L, 2L, 3L), 2L, -1))
        assertNull(movedOrder(listOf(1L, 2L, 3L), 1L, -1))

        val media = Json.parseToJsonElement("""{"logo": {"thumb": "t.webp", "card": "c.webp"}, "cover": null, "gallery": [{"id": 5, "card": "g5.webp"}, {"id": "6", "full": "g6.jpg"}]}""").jsonObject
        val images = venueImagesFrom(media)
        assertEquals(VenueImages("c.webp", null, listOf(VenuePhoto(5, "g5.webp"), VenuePhoto(6, "g6.jpg"))), images)
        val cover = Json.parseToJsonElement("""{"collection": "cover", "image": {"full": "cover.jpg"}}""").jsonObject
        assertEquals("cover.jpg", venueImagesFrom(cover, images).cover)
        assertEquals("c.webp", venueImagesFrom(cover, images).logo)
    }
}
