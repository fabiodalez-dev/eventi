package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.ui.management.analytics.axisDate
import it.fabiodalez.incitta.ui.management.analytics.barFraction
import it.fabiodalez.incitta.ui.management.analytics.chartCeiling
import it.fabiodalez.incitta.ui.management.analytics.chartPoints
import it.fabiodalez.incitta.ui.management.analytics.dateTime
import it.fabiodalez.incitta.ui.management.analytics.qrName
import it.fabiodalez.incitta.ui.management.common.eventChoiceWhen
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.*
import org.junit.Test

/* Statistiche (P5): forma delle risposte, percorsi con i filtri, scale dei grafici. */
class ManagementAnalyticsTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }
    private val venue = ManagedVenue(id = 12, slug = "circolo-aurora", name = "Circolo Aurora").toSubject()
    private val organizer = ManagedOrganizer(id = 3, slug = "collettivo", name = "Collettivo").toSubject()

    @Test fun readsTheStatisticsPage() {
        val payload = """{"data":{"period":{"value":"week","days":7,"from":"2026-09-04","until":"2026-09-10"},
            "periods":[{"value":"week","label":"7 giorni"},{"value":"month","label":"30 giorni"}],
            "cards":[{"key":"event_views","label":"Aperture eventi","value":10,"hint":"Visite alle pagine dei tuoi eventi."}],
            "empty":false,"series":[{"date":"2026-09-09","views":0,"profile_views":7,"clicks":0}],
            "interactions":[{"key":"phone_clicks","label":"Telefono","value":10}],
            "profile":[{"key":"views","label":"Visualizzazioni","value":7},{"key":"followers","label":"Chi ti segue","value":0}],
            "details":[],"paid":{"impressions":0,"clicks":0,"ctr":null,"channels":[],"breakdown":[]},
            "events":{"columns":[{"key":"views","label":"Visualizzazioni"}],"data":[{"id":41,"title":"Concerto","values":{"views":10,"saves":2}}],"meta":{"page":1,"next_page":null,"total":1}},
            "shares":{"data":[{"title":"Concerto","occurrence":null,"channel":"WhatsApp","url":"https://x/s/abcdefg","shares":2,"clicks":3}],"meta":{"page":1,"next_page":2,"total":21}},
            "nuovo":true}}"""
        val summary = json.decodeFromString<AnalyticsEnvelope<AnalyticsSummary>>(payload).data
        assertEquals("week", summary.period.value)
        assertEquals(10L, summary.cards.single().value)
        assertEquals(7L, summary.series.single().profileViews)
        assertNull(summary.paid.ctr)
        assertEquals(2L, summary.events.data.single().values["saves"])
        assertEquals(2, summary.shares.meta.nextPage)
        /* Un server che manda meno campi non fa fallire la lettura. */
        assertEquals(AnalyticsSummary(), json.decodeFromString<AnalyticsEnvelope<AnalyticsSummary>>("""{"data":{}}""").data)
    }

    @Test fun readsTheDetailedReportWithChartsAndTheSubject() {
        val payload = """{"data":{"period":"custom","from":null,"until":"2026-09-10","filters":{"event":null,"channel":"whatsapp"},
            "active_filters":[{"key":"channel","label":"Canale di condivisione","value":"WhatsApp"}],
            "channels":[{"value":"whatsapp","label":"WhatsApp"},{"value":"c-volantino","label":"Volantino"}],
            "cards":[{"key":"views","label":"Visualizzazioni","value":10,"hint":"…"}],"empty":false,"selected_event":null,
            "series":{"granularity":"month","points":[{"date":"2026-09","views":10,"short_shares":8,"short_clicks":12}]},
            "series_labels":{"views":"Visualizzazioni"},
            "charts":[{"key":"events","link":"event","metric_label":"Visualizzazioni","secondary_label":"Aperture del link","rows":[{"id":41,"name":"Concerto","value":10,"secondary":12}]},
              {"key":"paid","link":"event","metric_label":null,"secondary_label":null,"rows":[],"totals":[{"key":"paid_impressions","label":"Esposizioni","value":0}]}],
            "datasets":[{"key":"events","label":"Eventi","count":2}],
            "subject":{"type":"venue","id":12,"name":"Circolo Aurora","title":"Statistiche · Circolo Aurora","event":null,"related":[],
              "information":[{"label":"Città","value":"Padova","link":null}],"description":null,
              "profile_report":[{"key":"profile_views","label":"Profilo: Visualizzazioni","value":7},{"key":"followers","label":"Follower attuali","value":null},{"key":"rating","label":"Voto medio","value":4.25}]}}}"""
        val report = json.decodeFromString<AnalyticsEnvelope<AnalyticsReport>>(payload).data
        assertNull(report.from)
        assertEquals("whatsapp", report.filters.channel)
        assertEquals("month", report.series.granularity)
        assertEquals(12L, report.charts.first().rows.single().secondary)
        assertEquals("event", report.charts.first().link)
        assertEquals(1, report.charts.last().totals.size)
        val profile = report.subject!!.profileReport
        assertEquals("7", formatMetric(profile[0].value, "n.d."))
        assertEquals("n.d.", formatMetric(profile[1].value, "n.d."))
        assertEquals("4,25", formatMetric(profile[2].value, "n.d."))
    }

    @Test fun readsATablePageWithItsLinksAndCells() {
        val payload = """{"data":{"dataset":"events","label":"Eventi","columns":[{"key":"event","label":"Evento","numeric":false},{"key":"views","label":"Visualizzazioni","numeric":true},{"key":"rating","label":"Voto","numeric":true}],
            "rows":[{"cells":["=Concerto",1234,null],"links":{"event":{"type":"event","id":41}}},{"cells":["Reading",0,3.5],"links":{}}],
            "unavailable":"n.d.","meta":{"page":1,"next_page":null,"total":2}}}"""
        val table = json.decodeFromString<AnalyticsEnvelope<AnalyticsTable>>(payload).data
        assertEquals(41L, table.rows[0].links["event"]?.id)
        assertTrue(table.rows[1].links.isEmpty())
        assertEquals("=Concerto", analyticsCellText(table.rows[0].cells[0], table.unavailable))
        assertEquals("1.234", analyticsCellText(table.rows[0].cells[1], table.unavailable))
        assertEquals("n.d.", analyticsCellText(table.rows[0].cells[2], table.unavailable))
        assertEquals("3,50", analyticsCellText(table.rows[1].cells[2], table.unavailable))
        assertEquals("n.d.", analyticsCellText(JsonNull, "n.d."))
        assertEquals("sì", analyticsCellText(JsonPrimitive("sì"), "n.d."))
    }

    @Test fun readsTheEventChoicesAndWritesTheDateInTheCityZone() {
        val payload = """{"data":{"upcoming":[{"id":41,"title":"Concerto","poster":{"thumb":"https://x/p.jpg"},"starts_at":"2026-09-20T19:00:00+00:00","timezone":"Europe/Rome","past":false,"more":2,"venue":"Circolo Aurora"}],"past":[]}}"""
        val groups = json.decodeFromString<AnalyticsEnvelope<EventChoiceGroups>>(payload).data
        val choice = groups.upcoming.single()
        assertEquals("https://x/p.jpg", choice.poster?.thumb)
        assertEquals("dom 20 set 2026, 21:00", eventChoiceWhen(choice))
        assertNull(eventChoiceWhen(choice.copy(startsAt = null)))
        assertFalse(groups.isEmpty)
        assertTrue(EventChoiceGroups().isEmpty)
        assertEquals("ven 11 set 2026, 21:00", dateTime("2026-09-11T19:00:00+00:00", "Europe/Rome"))
    }

    @Test fun readsTheDatesTheOccurrenceSheetAndTheChannels() {
        val dates = json.decodeFromString<AnalyticsEnvelope<List<DateAnalyticsRow>>>("""{"data":[{"id":5,"starts_at":"2026-09-11T19:00:00+00:00","timezone":"Europe/Rome","status":{"value":"scheduled","label":"In programma"},"views":4,"clicks":32,"saves":1,"can_view_report":true}]}""").data
        assertTrue(dates.single().canViewReport)
        val occurrence = json.decodeFromString<AnalyticsEnvelope<OccurrenceAnalyticsReport>>("""{"data":{"id":5,"event":{"id":41,"title":"Concerto"},"starts_at":"2026-09-11T19:00:00+00:00","note":"Solo questa data","cards":[],"series":[{"date":"2026-09-08","views":4,"clicks":32}]}}""").data
        assertEquals(32L, occurrence.series.single().clicks)
        val channels = json.decodeFromString<ChannelsList>("""{"data":[{"code":"abcdefg","channel":"Volantino","target":"Circolo Aurora","url":"https://x/s/abcdefg","shares":0,"clicks":3}],"meta":{"max":12,"custom":1},"created":"abcdefg","message":"Canale «Volantino» creato."}""")
        assertEquals("abcdefg", channels.created)
        assertEquals(1, channels.meta.custom)
        val links = json.decodeFromString<AnalyticsEnvelope<EventShareLinks>>("""{"data":{"available":false,"links":[]}}""").data
        assertFalse(links.available)
    }

    @Test fun buildsThePathsWithTheFiltersOfThePanel() {
        assertEquals("management/venues/12/analytics/summary?period=week&page=2&share_page=1", summaryPath(venue, "week", 2, 1))
        val month = ReportQuery()
        assertEquals("management/venues/12/analytics/report?period=month", reportPath(venue, null, month))
        /* Le date si mandano solo con il periodo «a mano»; l'evento solo nella pagina d'insieme. */
        val custom = ReportQuery(period = "custom", from = "2026-09-01", until = "2026-09-10", event = 41, channel = "c-volantino estate")
        assertEquals("management/venues/12/analytics/report?period=custom&from=2026-09-01&until=2026-09-10&event=41&channel=c-volantino%20estate", reportPath(venue, null, custom))
        assertEquals("management/organizers/3/analytics/subjects/event/41?period=custom&from=2026-09-01&until=2026-09-10&channel=c-volantino%20estate",
            reportPath(organizer, AnalyticsTarget("event", 41), custom))
        assertEquals("management/venues/12/analytics/report?period=year", reportPath(venue, null, ReportQuery(period = "year", from = "2025-01-01", until = "2026-01-01")))
        assertEquals("management/venues/12/analytics/report/table?period=month&dataset=links&page=3", reportTablePath(venue, null, month, "links", 3))
        assertEquals("management/venues/12/analytics/subjects/venue/12/export?period=month&dataset=events&format=xlsx", reportExportPath(venue, AnalyticsTarget("venue", 12), month, "events", "xlsx"))
        assertEquals("management/venues/12/analytics/events", eventChoicesPath(venue, "  "))
        assertEquals("management/venues/12/analytics/events?q=Blue%20Note", eventChoicesPath(venue, "Blue Note"))
        assertEquals("statistiche-links-20260910-180000.csv", analyticsExportName("links", "csv", "20260910-180000"))
    }

    @Test fun scalesTheChartsLikeThePanel() {
        assertEquals(10L, chartCeiling(0))
        assertEquals(10L, chartCeiling(7))
        assertEquals(20L, chartCeiling(11))
        assertEquals(100L, chartCeiling(100))
        val points = chartPoints(listOf(0, 5, 10), ceiling = 10, width = 100f, height = 50f)
        assertEquals(listOf(0f to 50f, 50f to 25f, 100f to 0f), points)
        /* Un solo giorno sta a sinistra; un valore fuori scala resta dentro l'area. */
        assertEquals(listOf(0f to 0f), chartPoints(listOf(30), ceiling = 10, width = 100f, height = 50f))
        assertTrue(chartPoints(emptyList(), 10, 100f, 50f).isEmpty())
        assertEquals(0.5f, barFraction(5, 10))
        assertEquals(0f, barFraction(5, 0))
        assertEquals(1f, barFraction(20, 10))
        assertEquals("12 set", axisDate("2026-09-12"))
        assertEquals("set 2026", axisDate("2026-09"))
        assertEquals("altro", axisDate("altro"))
        assertEquals("1.234.567", formatCount(1_234_567))
        assertEquals("qr-volantino-estate", qrName("Volantino Estate!"))
        assertEquals("qr-link", qrName("!!!"))
    }
}
