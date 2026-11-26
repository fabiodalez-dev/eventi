package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.junit.Assert.*
import org.junit.Test

/* R12: le righe del server si leggono con i valori predefiniti, la mappatura va e torna senza perdere niente. */
class SystemModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun anImportSourceIsReadWithoutCredentialsAndWithItsAbilities() {
        val page = json.decodeFromString<EditorialPage<EditorialImportSource>>(
            """{"data":[{"id":3,"city":{"id":1,"name":"Padova"},"venue":null,"type":"ics","type_label":"Calendario ICS","url":"https://x.test/a.ics",
               "has_credentials":true,"mapping":{"timezone":"Europe/Rome","exclude_keywords":["chiuso","ferie"]},"default_category":{"id":2,"name":"Musica"},
               "is_active":false,"last_status":"failed","last_status_label":"Non riuscita","runnable":true,"web_path":"admin/import-sources/3/edit",
               "abilities":{"update":true,"destroy":true,"preview":true,"run":true,"runs":true},"extra":1}],
               "meta":{"current_page":1,"last_page":2,"per_page":20,"total":21}}""",
        )
        val source = page.data.single()
        assertTrue(source.hasCredentials)
        assertTrue(source.failed)
        assertFalse(source.isActive)
        assertEquals("https://x.test/a.ics", source.label)
        assertEquals(true, abilitiesOf(source.abilities)["run"])
        assertTrue(page.meta.hasMore)
        /* Un server con meno campi non rompe la lettura. */
        val minimal = json.decodeFromString<EditorialImportSource>("""{"id":4,"type":"manual"}""")
        assertFalse(minimal.runnable)
        assertEquals("manual", minimal.label)
    }

    @Test fun thePreviewAndTheSchedulerAreReadAsTheServerSendsThem() {
        val preview = json.decodeFromString<ImportPreview>("""{"rows":[{"when":"05/09/2026 21:30","title":"Concerto","where":null,"notes":["ricorrente"],"cancelled":true}],"error":null,"timezone":"Europe/Rome"}""")
        assertEquals("05/09/2026 21:30", preview.rows.single().whenText)
        assertTrue(preview.rows.single().cancelled)
        val overview = json.decodeFromString<SchedulerOverviewData>(
            """{"installed":{"readable":true,"scheduler":true,"worker":false},"cron":{"scheduler":"* * * * * a","worker":"* * * * * b"},"queue":"database",
               "social":[{"city_id":1,"city":"Padova","automatic":true,"publish_time":"09:00"}],"tasks":[{"name":"notifications:send","expression":"*/5 * * * *","monitored":false}]}""",
        )
        assertTrue(overview.installed.scheduler)
        assertEquals("09:00", overview.social.single().publishTime)
        assertNull(overview.tasks.single().lastFinished)
        val row = json.decodeFromString<ScheduledNotificationRow>("""{"id":9,"status":"pending","abilities":{"cancel":true},"recipient":null}""")
        assertTrue(row.pending)
        assertNull(row.recipient)
    }

    @Test fun theMappingKeepsListsAndWhatTheFormCannotShow() {
        val mapping = buildJsonObject {
            put("timezone", "Europe/Rome")
            put("exclude_keywords", JsonArray(listOf(JsonPrimitive("chiuso"), JsonPrimitive("ferie"))))
            put("columns", buildJsonObject { put("title", "Titolo") })
            put("port", 8080)
        }
        val draft = mappingDraft(mapping)
        assertEquals(listOf(MappingEntry("timezone", "Europe/Rome"), MappingEntry("exclude_keywords", "chiuso, ferie", list = true), MappingEntry("port", "8080")), draft.entries)
        assertEquals(setOf("columns"), draft.preserved.keys)
        val back = mappingJson(draft.copy(entries = draft.entries + MappingEntry("  ", "scartata") + MappingEntry("auth", "basic")))!!
        assertEquals(JsonArray(listOf(JsonPrimitive("chiuso"), JsonPrimitive("ferie"))), back["exclude_keywords"])
        assertEquals(JsonPrimitive("basic"), back["auth"])
        assertTrue(back["columns"] is JsonObject)
        assertFalse(back.containsKey(""))
        assertNull(mappingJson(MappingDraft()))
        assertEquals(MappingDraft(), mappingDraft(null))
    }

    @Test fun thePathsAreTheOnesOfTheServer() {
        assertEquals("editorial/import-sources?page=1&per_page=20&filter[failed]=1", editorialPath("import-sources", null, listOf("page" to "1", "per_page" to "20", "filter[failed]" to "1")))
        assertEquals("editorial/import-sources/venues?q=Teatro%20Verdi", editorialPath("import-sources/venues", null, listOf("q" to "Teatro Verdi")))
        assertEquals("editorial/scheduled-notifications/7/cancel", editorialPath("scheduled-notifications/7/cancel", null))
    }
}
