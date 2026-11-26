package it.fabiodalez.incitta.data.editorial

import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* La risposta di `GET editorial/dashboard`, letta come la legge l'app. */
class DashboardModelsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    private val sample = """
        {"city": {"id": 1, "slug": "padova", "name": "Padova", "timezone": "Europe/Rome", "is_active": true},
         "sections": [
          {"key": "queue", "title": "Da lavorare adesso", "description": "Tutto ciò che aspetta una decisione della redazione.",
           "stats": [
            {"key": "pending_events", "label": "Eventi in attesa", "value": "2", "count": 2, "description": "2 eventi aspettano…", "tone": "warning",
             "target": {"area": "events", "tab": null, "filters": {"status": "pending"}, "web_path": "admin/events?filters%5Bstatus%5D%5Bvalue%5D=pending"}},
            {"key": "open_reports", "label": "Segnalazioni aperte", "value": "0", "count": 0, "description": "Nessuna…", "tone": "gray",
             "target": {"area": "reports", "tab": null, "filters": {"open": "1"}, "web_path": "admin/reports?filters%5Bopen%5D%5BisActive%5D=1"}}]},
          {"key": "registrations", "title": "Nuove iscrizioni", "description": "3 iscrizioni negli ultimi 7 giorni · una in attesa di conferma",
           "counts": {"recent": 3, "pending": 1},
           "links": [{"key": "pending", "label": "Solo in attesa", "badge": 1, "target": {"area": "users", "tab": "pending", "filters": {}, "web_path": "admin/users?tab=pending"}},
                     {"key": "all", "label": "Tutti gli utenti", "badge": null, "target": {"area": "users", "tab": null, "filters": [], "web_path": "admin/users"}}],
           "rows": [{"id": 9, "name": "Ada", "detail": "ada@example.test", "state": {"value": "pending", "label": "In attesa di conferma", "description": "…", "tone": "warning"},
                     "registered_at": "2026-11-26T10:05:00+00:00", "abilities": {"update": true}, "web_path": "admin/users/9/edit"},
                    {"id": 8, "name": "bob@example.test", "detail": "Senza nome", "state": {"value": "confirmed", "label": "Confermato"}, "abilities": {"update": false}, "web_path": null}],
           "empty": {"title": "Ancora nessun iscritto", "description": "…"}},
          {"key": "returns", "title": "Ritorno delle persone", "stats": [{"key": "attendance", "label": "Presenze", "value": "—", "count": null}]},
          {"key": "sponsorship_trend", "title": "Andamento", "description": "La distanza…",
           "chart": {"days": ["2026-11-25", "2026-11-26"], "labels": ["25 nov", "26 nov"],
                     "series": [{"key": "impressions", "label": "Visualizzazioni", "values": [10, 0]}, {"key": "clicks", "label": "Aperture", "values": [1, 0]}]}}
         ]}
    """.trimIndent()

    @Test fun readsSectionsStatsTargetsRegistrationsAndChart() {
        val dashboard = json.decodeFromString(EditorialDashboard.serializer(), sample)
        assertEquals("padova", dashboard.city?.slug)
        assertEquals(listOf("queue", "registrations", "returns", "sponsorship_trend"), dashboard.sections.map { it.key })

        val pending = dashboard.section(DashboardSection.QUEUE)!!.stats.first()
        assertEquals("2", pending.value)
        assertEquals(2.0, pending.number!!, 0.0)
        assertTrue(pending.alert)
        assertEquals("events", pending.target?.area)
        assertEquals(mapOf("status" to "pending"), pending.target?.filterMap)
        val reports = dashboard.section(DashboardSection.QUEUE)!!.stats[1]
        assertFalse(reports.alert)
        assertEquals(mapOf("open" to "1"), reports.target?.filterMap)

        val registrations = dashboard.section(DashboardSection.REGISTRATIONS)!!
        assertEquals(1, registrations.counts?.pending)
        assertEquals("pending", registrations.links.first().target?.tab)
        assertEquals(1, registrations.links.first().badge)
        /* Un server vecchio che manda `[]` per i filtri vuoti non rompe la lettura. */
        assertEquals(emptyMap<String, String>(), registrations.links[1].target?.filterMap)
        assertTrue(registrations.rows.first().canUpdate)
        assertFalse(registrations.rows[1].canUpdate)
        assertNull(registrations.rows[1].webPath)

        val attendance = dashboard.section(DashboardSection.RETURNS)!!.stats.single()
        assertEquals("—", attendance.value)
        assertNull(attendance.number)

        val chart = dashboard.section(DashboardSection.SPONSORSHIP_TREND)!!.chart!!
        assertEquals(listOf(10L, 0L), chart.series.first().values)
        assertEquals(2, chart.days.size)
    }

    @Test fun toleratesAnEmptyAnswer() {
        val dashboard = json.decodeFromString(EditorialDashboard.serializer(), """{"city": null, "sections": []}""")
        assertNull(dashboard.city)
        assertTrue(dashboard.sections.isEmpty())
    }
}
