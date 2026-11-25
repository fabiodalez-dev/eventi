package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiEnvelope
import java.io.ByteArrayInputStream
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* P9: le forme di `CalendarImportController` lette dall'app, i corpi che l'app manda e il tetto del file. */
class ManagementCalendarImportTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun readsTheStateOfAConnectedCalendar() {
        val state = json.decodeFromString<ApiEnvelope<CalendarImportState>>(
            """{"data":{"source":{"id":4,"type":"csv","type_label":"CSV","address":"upload:calendario-ab.csv","url":null,"is_upload":true,"is_active":false,
            "last_run_at":"2026-11-25T21:00:00+01:00","last_status":"failed","last_status_label":"Fallito","last_error":"Il file caricato non c'è più",
            "can_run":true,"can_update":true,"can_delete":true,"nuovo":1},
            "form":{"type":"csv","url":null,"exclude_keywords":["chiuso","ferie"]},"default_exclude_keywords":["chiuso"],
            "limits":{"max_keywords":100,"max_keyword_length":100,"max_url_length":1000,"max_file_bytes":8388608,"preview_size":20}}}""",
        ).data
        val source = state.source!!
        assertTrue(source.isUpload)
        assertFalse(source.isActive)
        assertNull(source.url)
        assertEquals("Fallito", source.lastStatusLabel)
        assertEquals(CalendarSourceType.Csv, CalendarSourceType.from(state.form.type))
        assertEquals(listOf("chiuso", "ferie"), state.form.excludeKeywords)
        assertEquals(8388608L, state.limits.maxFileBytes)

        val empty = json.decodeFromString<ApiEnvelope<CalendarImportState>>("""{"data":{"source":null,"form":{"type":"ics"}}}""").data
        assertNull(empty.source)
        assertEquals(CalendarSourceType.Ics, CalendarSourceType.from(empty.form.type))
        assertEquals(CalendarSourceType.Ics, CalendarSourceType.from("altro"))
    }

    @Test fun readsThePreviewAndTheRunOutcome() {
        val preview = json.decodeFromString<ApiEnvelope<CalendarPreview>>(
            """{"data":{"rows":[{"title":"Jazz","starts_at":"2026-12-05T21:00:00+01:00","ends_at":null,"when":"05/12/2026 21:00","where":null,
            "all_day":false,"recurring":true,"cancelled":false,"notes":["Si ripete"]}],"error":null,"preview_token":"segno","expires_in_minutes":60}}""",
        ).data
        assertEquals("05/12/2026 21:00", preview.rows.single().`when`)
        assertEquals(listOf("Si ripete"), preview.rows.single().notes)
        assertEquals("segno", preview.previewToken)

        val run = json.decodeFromString<ApiEnvelope<CalendarRunResult>>(
            """{"data":{"outcome":"failed","outcome_label":"Fallito","title":"Lettura completata.","message":"Il calendario non risponde","counters":null,"state":{"source":null}}}""",
        ).data
        assertTrue(run.failed)
        assertEquals("Il calendario non risponde", run.message)

        val change = json.decodeFromString<CalendarChange>("""{"data":{"source":{"id":1,"is_active":true}},"message":"Collegamento riattivato."}""")
        assertTrue(change.data.source!!.isActive)
        assertEquals("Collegamento riattivato.", change.message)
    }

    @Test fun sendsTheFormAsJsonAndAsMultipart() {
        val draft = CalendarDraft(CalendarSourceType.Ics, "  https://calendario.test/eventi.ics ", listOf("riunione", "chiuso"))
        val body = calendarDraftBody(draft, "segno")
        assertEquals(JsonPrimitive("ics"), body["type"])
        assertEquals(JsonPrimitive("https://calendario.test/eventi.ics"), body["url"])
        assertEquals(JsonArray(listOf(JsonPrimitive("riunione"), JsonPrimitive("chiuso"))), body["exclude_keywords"])
        assertEquals(JsonPrimitive("segno"), body["preview_token"])
        assertNull(calendarDraftBody(draft.copy(url = " "), null)["url"])
        assertNull(calendarDraftBody(draft, null)["preview_token"])
        // Nessuna parola: un elenco vuoto, così il server non rimette quelle predefinite.
        assertEquals(JsonArray(emptyList()), calendarDraftBody(draft.copy(keywords = emptyList()), null)["exclude_keywords"])

        val fields = calendarMultipartFields(CalendarDraft(CalendarSourceType.Csv, "", listOf("riunione", "ferie")), "segno")
        assertEquals(listOf("type" to "csv", "exclude_keywords[]" to "riunione", "exclude_keywords[]" to "ferie", "preview_token" to "segno"), fields)
        assertEquals("stagione.csv", ManagementCalendarImportApi.uploadName("stagione.csv"))
        assertEquals("stagione.csv", ManagementCalendarImportApi.uploadName("stagione"))
        assertEquals("date.txt", ManagementCalendarImportApi.uploadName("date.txt"))
    }

    @Test fun stopsReadingAFileOverTheServerLimit() {
        assertEquals(10, readAtMost(ByteArrayInputStream(ByteArray(10)), 10).size)
        val error = assertThrows(CalendarFileException::class.java) { readAtMost(ByteArrayInputStream(ByteArray(20_000)), 10_000) }
        assertEquals(CalendarFileException.Reason.TooLarge, error.reason)
    }
}
