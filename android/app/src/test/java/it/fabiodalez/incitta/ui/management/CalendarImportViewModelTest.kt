package it.fabiodalez.incitta.ui.management

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.CalendarChange
import it.fabiodalez.incitta.data.management.CalendarDraft
import it.fabiodalez.incitta.data.management.CalendarFile
import it.fabiodalez.incitta.data.management.CalendarFileException
import it.fabiodalez.incitta.data.management.CalendarForm
import it.fabiodalez.incitta.data.management.CalendarImportState
import it.fabiodalez.incitta.data.management.CalendarPreview
import it.fabiodalez.incitta.data.management.CalendarPreviewRow
import it.fabiodalez.incitta.data.management.CalendarRunResult
import it.fabiodalez.incitta.data.management.CalendarSource
import it.fabiodalez.incitta.data.management.CalendarSourceType
import it.fabiodalez.incitta.data.management.FakeIdentity
import it.fabiodalez.incitta.data.management.FakeTicketing
import it.fabiodalez.incitta.data.management.ManagementCalendarImportSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.MemoryDevice
import it.fabiodalez.incitta.ui.management.calendarimport.CalendarAction
import it.fabiodalez.incitta.ui.management.calendarimport.CalendarImportViewModel
import it.fabiodalez.incitta.ui.management.common.Loadable
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* P9 con un server finto: il modulo riempito dal server, l'anteprima che si perde cambiando indirizzo, il collegamento col segno, le azioni. */
@OptIn(ExperimentalCoroutinesApi::class)
class CalendarImportViewModelTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private class FakeCalendar : ManagementCalendarImportSource {
        val calls = mutableListOf<String>()
        var connected: CalendarSource? = null
        var previewFailure: Exception? = null
        private fun state() = CalendarImportState(source = connected, form = CalendarForm("ics", connected?.url, listOf("chiuso", "ferie")))
        override suspend fun state(venueId: Long): CalendarImportState { calls += "state:$venueId"; return state() }
        override suspend fun preview(venueId: Long, draft: CalendarDraft, file: CalendarFile?): CalendarPreview {
            calls += "preview:${draft.type.value}:${draft.url}:${draft.keywords.joinToString(",")}:${file?.name}"
            previewFailure?.let { throw it }
            return CalendarPreview(listOf(CalendarPreviewRow(title = "Jazz", `when` = "05/12/2026 21:00")), previewToken = "segno-${draft.url}")
        }
        override suspend fun connect(venueId: Long, draft: CalendarDraft, file: CalendarFile?, previewToken: String): CalendarChange {
            calls += "connect:$previewToken:${file?.size}"
            connected = CalendarSource(id = 1, url = draft.url, address = draft.url, isActive = true, canRun = true, canUpdate = true, canDelete = true)
            return CalendarChange(state(), "Calendario collegato.")
        }
        override suspend fun run(venueId: Long): CalendarRunResult { calls += "run"; return CalendarRunResult("success", title = "Lettura completata.", message = "Creati: 0.", state = state()) }
        override suspend fun pause(venueId: Long): CalendarChange { calls += "pause"; connected = connected?.copy(isActive = false); return CalendarChange(state(), "Collegamento sospeso.") }
        override suspend fun resume(venueId: Long): CalendarChange { calls += "resume"; connected = connected?.copy(isActive = true); return CalendarChange(state(), "Collegamento riattivato.") }
        override suspend fun disconnect(venueId: Long): CalendarChange { calls += "disconnect"; connected = null; return CalendarChange(state(), "Calendario scollegato.") }
    }

    private fun repository(scope: CoroutineScope, calendar: FakeCalendar) =
        ManagementRepository(7, "token", FakeTicketing(), FakeIdentity(), null, MemoryDevice(), scope, calendar = calendar).also { it.selectedSubjectKey = "venue:12" }

    private fun viewModel(calendar: FakeCalendar, saved: SavedStateHandle = SavedStateHandle()) =
        CalendarImportViewModel(repository(TestScope(dispatcher), calendar), saved, dispatcher)

    @Test fun theFormStartsFromTheServerAndThenKeepsWhatIsBeingWritten() = runTest(dispatcher) {
        val calendar = FakeCalendar()
        val saved = SavedStateHandle()
        val first = viewModel(calendar, saved)
        assertEquals(listOf("state:12"), calendar.calls)
        assertEquals(listOf("chiuso", "ferie"), first.uiState.value.keywords)
        assertNull(first.uiState.value.source)

        first.setUrl("https://calendario.test/eventi.ics")
        first.setKeywordInput("Riunione, chiuso, manutenzione")
        first.addKeyword()
        assertEquals(listOf("chiuso", "ferie", "Riunione", "manutenzione"), first.uiState.value.keywords)
        first.removeKeyword("ferie")

        // Dopo la morte del processo il modulo torna com'era, non come lo dice il server.
        val restored = viewModel(calendar, saved)
        assertEquals("https://calendario.test/eventi.ics", restored.uiState.value.url)
        assertEquals(listOf("chiuso", "Riunione", "manutenzione"), restored.uiState.value.keywords)
    }

    @Test fun connectsOnlyAfterThePreviewOfTheSameAddress() = runTest(dispatcher) {
        val calendar = FakeCalendar()
        val viewModel = viewModel(calendar)
        viewModel.setUrl("https://calendario.test/eventi.ics")
        viewModel.connect()
        assertFalse(calendar.calls.any { it.startsWith("connect") })
        assertEquals(CalendarImportViewModel.PREVIEW_FIRST, viewModel.uiState.value.errors.general)

        viewModel.preview()
        assertTrue(viewModel.uiState.value.previewed)
        assertEquals("Jazz", viewModel.uiState.value.preview!!.rows.single().title)

        // Cambiato l'indirizzo, l'anteprima di prima non descrive più ciò che si accende.
        viewModel.setUrl("https://calendario.test/altro.ics")
        assertFalse(viewModel.uiState.value.previewed)
        viewModel.preview()
        viewModel.connect()
        assertEquals("connect:segno-https://calendario.test/altro.ics:null", calendar.calls.last())
        assertEquals("Calendario collegato.", viewModel.uiState.value.message)
        assertTrue(viewModel.uiState.value.source!!.isActive)
    }

    @Test fun theCsvFileTravelsWithThePreviewAndTheConnection() = runTest(dispatcher) {
        val calendar = FakeCalendar()
        val viewModel = viewModel(calendar)
        viewModel.setType(CalendarSourceType.Csv)
        viewModel.fileChosen { throw CalendarFileException(CalendarFileException.Reason.TooLarge) }
        assertEquals(CalendarFileException.Reason.TooLarge, viewModel.uiState.value.fileError)

        viewModel.fileChosen { CalendarFile("stagione.csv", "titolo,inizio\n".toByteArray()) }
        assertEquals("stagione.csv", viewModel.uiState.value.fileName)
        assertNull(viewModel.uiState.value.fileError)
        viewModel.preview()
        viewModel.connect()
        assertTrue(calendar.calls.contains("preview:csv::chiuso,ferie:stagione.csv"))
        assertEquals("connect:segno-:14", calendar.calls.last())

        // Togliere il file spegne l'anteprima; tornare all'ICS non manda il file.
        viewModel.clearFile()
        assertFalse(viewModel.uiState.value.previewed)
        viewModel.fileChosen { CalendarFile("altro.csv", "x".toByteArray()) }
        viewModel.setType(CalendarSourceType.Ics)
        viewModel.preview()
        assertTrue(calendar.calls.last().endsWith(":null"))
    }

    @Test fun serverErrorsGoUnderTheirField() = runTest(dispatcher) {
        val calendar = FakeCalendar()
        calendar.previewFailure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("url" to listOf("Indirizzo interno non ammesso."))))
        val viewModel = viewModel(calendar)
        viewModel.setUrl("http://127.0.0.1/x.ics")
        viewModel.preview()
        assertEquals("Indirizzo interno non ammesso.", viewModel.uiState.value.errors.field("url"))
        assertFalse(viewModel.uiState.value.previewed)
    }

    @Test fun runsPausesResumesAndDisconnects() = runTest(dispatcher) {
        val calendar = FakeCalendar()
        calendar.connected = CalendarSource(id = 1, url = "https://calendario.test/eventi.ics", isActive = true, canRun = true, canUpdate = true, canDelete = true)
        val viewModel = viewModel(calendar)
        viewModel.preview()

        viewModel.act(CalendarAction.Run)
        assertEquals("Lettura completata.", viewModel.uiState.value.runResult!!.title)
        viewModel.act(CalendarAction.Pause)
        assertFalse(viewModel.uiState.value.source!!.isActive)
        assertEquals("Collegamento sospeso.", viewModel.uiState.value.message)
        viewModel.act(CalendarAction.Resume)
        assertTrue(viewModel.uiState.value.source!!.isActive)
        viewModel.act(CalendarAction.Disconnect)
        assertNull(viewModel.uiState.value.source)
        assertFalse(viewModel.uiState.value.previewed)
        assertTrue(viewModel.uiState.value.load is Loadable.Ready)
        assertEquals(listOf("run", "pause", "resume", "disconnect"), calendar.calls.filter { it in setOf("run", "pause", "resume", "disconnect") })
    }
}
