package it.fabiodalez.incitta.ui.submission

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.EventSubmissionBody
import it.fabiodalez.incitta.data.EventSubmissionSource
import it.fabiodalez.incitta.data.SubmissionCity
import it.fabiodalez.incitta.data.SubmissionReceipt
import it.fabiodalez.incitta.data.SubmissionVenue
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.io.IOException
import java.time.LocalDateTime
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* Q1 con un server finto: i campi del sito, gli errori sotto i campi, una chiave per proposta, la ricerca dei locali. */
@OptIn(ExperimentalCoroutinesApi::class)
class EventSubmissionViewModelTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeSubmissions : EventSubmissionSource {
        val queries = mutableListOf<String>()
        val sent = mutableListOf<Pair<EventSubmissionBody, String>>()
        val failures = ArrayDeque<Exception>()
        var city = SubmissionCity("padova", "Padova", "Europe/Rome")
        override suspend fun city(): SubmissionCity = city
        override suspend fun venues(query: String): List<SubmissionVenue> {
            queries += query
            return if (query == "nessuno") emptyList() else listOf(SubmissionVenue(5, "Caffè Pedrocchi", "Padova"), SubmissionVenue(8, "Circolo Aurora", "Padova"))
        }
        override suspend fun submit(body: EventSubmissionBody, idempotencyKey: String): SubmissionReceipt {
            sent += body to idempotencyKey
            failures.removeFirstOrNull()?.let { throw it }
            return SubmissionReceipt(41, "pending", "Proposta ricevuta: la redazione la valuterà prima della pubblicazione.")
        }
    }

    @Test fun theAccountFillsOnlyTheEmptyContactFields() = runTest(dispatcher) {
        val saved = SavedStateHandle()
        val viewModel = EventSubmissionViewModel(FakeSubmissions(), saved, "marta@esempio.it", "Marta Rossi")
        assertEquals("marta@esempio.it", viewModel.uiState.value.draft.contactEmail)
        assertEquals("Marta Rossi", viewModel.uiState.value.draft.contactName)

        viewModel.update { it.copy(contactEmail = "redazione@circolo.it", title = "Jazz in cortile") }
        viewModel.prefill("marta@esempio.it", "Marta Rossi")
        assertEquals("redazione@circolo.it", viewModel.uiState.value.draft.contactEmail)

        /* Quello che si scrive resta nello stato salvato: dopo la chiusura dell'app il modulo torna com'era. */
        val restored = EventSubmissionViewModel(FakeSubmissions(), saved, "altro@esempio.it", null)
        assertEquals("Jazz in cortile", restored.uiState.value.draft.title)
        assertEquals("redazione@circolo.it", restored.uiState.value.draft.contactEmail)

        /* Da ospite non c'è niente da precompilare. */
        val guest = EventSubmissionViewModel(FakeSubmissions(), SavedStateHandle())
        assertEquals("", guest.uiState.value.draft.contactEmail)
    }

    @Test fun titleAndEmailAreCheckedBeforeSpendingAnAttempt() = runTest(dispatcher) {
        val api = FakeSubmissions()
        val viewModel = EventSubmissionViewModel(api, SavedStateHandle())
        viewModel.submit()
        assertEquals(mapOf("title" to R.string.event_submission_title_required, "contact_email" to R.string.event_submission_email_required), viewModel.uiState.value.missing)
        viewModel.update { it.copy(title = "Jazz", contactEmail = "marta@") }
        viewModel.submit()
        assertEquals(mapOf("contact_email" to R.string.event_submission_email_invalid), viewModel.uiState.value.missing)
        assertTrue(api.sent.isEmpty())
        /* Correggendo il campo l'errore se ne va. */
        viewModel.update { it.copy(contactEmail = "marta@esempio.it") }
        assertTrue(viewModel.uiState.value.missing.isEmpty())
    }

    @Test fun theProposalGoesOutAsOnTheSiteAndARetryKeepsItsKey() = runTest(dispatcher) {
        val api = FakeSubmissions()
        val saved = SavedStateHandle()
        val viewModel = EventSubmissionViewModel(api, saved, "marta@esempio.it", " Marta Rossi ")
        viewModel.update { it.copy(title = "  Jazz in cortile ", rawText = "Ingresso **libero**", venueHint = "  ") }
        viewModel.setStartsAt(LocalDateTime.of(2026, 12, 5, 21, 0))
        viewModel.chooseVenue(SubmissionVenue(5, "Caffè Pedrocchi"))
        assertEquals("Caffè Pedrocchi", viewModel.uiState.value.draft.venueName)

        /* La risposta si perde per strada: il secondo tentativo usa la stessa chiave. */
        api.failures += IOException("Connessione non disponibile. Riprova tra poco.")
        viewModel.submit()
        assertNotNull(viewModel.uiState.value.errors.general)
        assertNull(viewModel.uiState.value.receipt)
        viewModel.submit()
        assertEquals(2, api.sent.size)
        assertEquals(api.sent[0].second, api.sent[1].second)

        val body = api.sent.last().first
        assertEquals("Jazz in cortile", body.title)
        /* L'ora di Padova, con il suo scarto: in dicembre è l'ora solare. */
        assertEquals("2026-12-05T21:00:00+01:00", body.startsAtHint)
        assertEquals(5L, body.venueId)
        assertNull(body.venueHint)
        assertEquals("<p>Ingresso <strong>libero</strong></p>", body.rawText)
        assertEquals("Marta Rossi", body.contactName)
        assertEquals("marta@esempio.it", body.contactEmail)

        val receipt = viewModel.uiState.value.receipt
        assertEquals("pending", receipt?.status)
        assertEquals("Proposta ricevuta: la redazione la valuterà prima della pubblicazione.", receipt?.message)
        /* Inviata: la bozza e la chiave non servono più. */
        assertTrue(saved.keys().none { it.startsWith("submission_") })
        viewModel.submit()
        assertEquals(2, api.sent.size)

        /* Un'altra proposta parte da capo, con nome ed email già scritti, e ha una chiave nuova. */
        viewModel.another()
        assertNull(viewModel.uiState.value.receipt)
        assertEquals("", viewModel.uiState.value.draft.title)
        assertNull(viewModel.uiState.value.draft.venueId)
        assertEquals("Marta Rossi", viewModel.uiState.value.draft.contactName.trim())
        viewModel.update { it.copy(title = "Sagra") }
        viewModel.submit()
        assertNotEquals(api.sent[1].second, api.sent[2].second)
    }

    @Test fun aChangedTextIsAnotherProposalWithAnotherKey() = runTest(dispatcher) {
        val api = FakeSubmissions()
        val viewModel = EventSubmissionViewModel(api, SavedStateHandle(), "marta@esempio.it", null)
        viewModel.update { it.copy(title = "Jazz") }
        api.failures += IOException("Connessione non disponibile. Riprova tra poco.")
        viewModel.submit()
        viewModel.update { it.copy(title = "Jazz in cortile") }
        viewModel.submit()
        assertNotEquals(api.sent[0].second, api.sent[1].second)
        assertTrue(api.sent.all { it.second.length in 16..128 })
    }

    @Test fun theServerErrorsGoUnderTheirFields() = runTest(dispatcher) {
        val api = FakeSubmissions()
        val viewModel = EventSubmissionViewModel(api, SavedStateHandle(), "marta@esempio.it", null)
        viewModel.update { it.copy(title = "Jazz") }
        api.failures += ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf(
            "starts_at_hint" to listOf("Il campo quando non è una data valida."),
            "raw_text" to listOf("Il testo non può superare 5000 caratteri."),
            "honeypot" to listOf("Altro"),
        )))
        viewModel.submit()
        val errors = viewModel.uiState.value.errors
        assertEquals("Il campo quando non è una data valida.", errors.field("starts_at_hint"))
        assertEquals("Il testo non può superare 5000 caratteri.", errors.field("raw_text"))
        /* Un campo che il modulo non mostra non si perde: va in cima. */
        assertEquals("Altro", errors.general)
        assertFalse(viewModel.uiState.value.busy)
        assertNull(viewModel.uiState.value.receipt)
    }

    @Test fun theCityDecidesTheTimezone() = runTest(dispatcher) {
        val api = FakeSubmissions().apply { city = SubmissionCity("lisbona", "Lisbona", "Europe/Lisbon") }
        val viewModel = EventSubmissionViewModel(api, SavedStateHandle(), "marta@esempio.it", null)
        assertEquals("Lisbona", viewModel.uiState.value.city.name)
        viewModel.update { it.copy(title = "Fado") }
        viewModel.setStartsAt(LocalDateTime.of(2026, 7, 5, 21, 0))
        viewModel.submit()
        assertEquals("2026-07-05T21:00:00+01:00", api.sent.single().first.startsAtHint)
    }

    @Test fun theVenueListOpensWithTheCityAndSearchesAfterAPause() = runTest(dispatcher) {
        val api = FakeSubmissions()
        val viewModel = EventSubmissionViewModel(api, SavedStateHandle())
        assertNull(viewModel.uiState.value.picker.results)
        viewModel.openPicker()
        assertEquals(listOf(""), api.queries)
        assertEquals(listOf(5L, 8L), viewModel.uiState.value.picker.results?.valueOrNull?.map { it.id })
        /* Riaprendo non si rilegge. */
        viewModel.openPicker()
        assertEquals(1, api.queries.size)

        viewModel.setVenueQuery("ca")
        viewModel.setVenueQuery("caf")
        advanceTimeBy(EventSubmissionViewModel.SEARCH_PAUSE_MS - 1)
        assertEquals(1, api.queries.size)
        advanceUntilIdle()
        assertEquals(listOf("", "caf"), api.queries)
        assertEquals("caf", viewModel.uiState.value.picker.query)

        viewModel.setVenueQuery("nessuno")
        advanceUntilIdle()
        assertEquals(Loadable.Empty, viewModel.uiState.value.picker.results)

        viewModel.chooseVenue(SubmissionVenue(8, "Circolo Aurora"))
        assertEquals(8L, viewModel.uiState.value.draft.venueId)
        viewModel.chooseVenue(null)
        assertNull(viewModel.uiState.value.draft.venueId)
        assertNull(viewModel.uiState.value.draft.venueName)
    }
}
