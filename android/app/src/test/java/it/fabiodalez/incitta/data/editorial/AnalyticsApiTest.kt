package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.management.AnalyticsReport
import it.fabiodalez.incitta.data.management.AnalyticsTarget
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* R10: i percorsi e i filtri come li vuole il server, le regole del codice di Search Console, la lettura delle risposte. */
class AnalyticsApiTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun theOverviewSendsEveryFilterAndTheSubjectPageOnlyPeriodAndChannel() {
        val query = EditorialReportQuery(period = "custom", from = "2026-09-01", until = "2026-09-10", venue = 4, organizer = 7, event = 9, channel = "whatsapp")
        assertEquals(
            "editorial/analytics/report?period=custom&from=2026-09-01&until=2026-09-10&venue=4&organizer=7&event=9&channel=whatsapp&city=padova",
            editorialReportPath(null, query, "padova"),
        )
        assertEquals(
            "editorial/analytics/subjects/venue/4/table?period=custom&from=2026-09-01&until=2026-09-10&channel=whatsapp&dataset=links&page=2",
            editorialReportPath(AnalyticsTarget("venue", 4), query, null, "/table", listOf("dataset" to "links", "page" to "2")),
        )
        // Un periodo con nome non manda le date; i filtri a zero non partono.
        assertEquals("editorial/analytics/report?period=week", editorialReportPath(null, EditorialReportQuery(period = "week", from = "2026-01-01", venue = 0), null))
    }

    @Test fun periodAndChannelTravelBetweenPagesTheSubjectDoesNot() {
        val query = EditorialReportQuery(period = "year", venue = 4, organizer = 7, event = 9, channel = "telegram")
        assertEquals(EditorialReportQuery(period = "year", channel = "telegram"), query.periodAndChannel())
        assertEquals(4, query.activeFilters)
        assertEquals(0, EditorialReportQuery().activeFilters)
    }

    @Test fun theSearchConsoleCodeFollowsThePanelField() {
        assertNull(seoCodeProblem(""))
        assertNull(seoCodeProblem("   "))
        assertNull(seoCodeProblem("Google-test_token-123"))
        assertEquals(SeoCodeProblem.Invalid, seoCodeProblem("<meta name=\"google-site-verification\">"))
        assertEquals(SeoCodeProblem.Invalid, seoCodeProblem("due parole"))
        assertEquals(SeoCodeProblem.TooLong, seoCodeProblem("a".repeat(256)))
        assertNull(seoCodeProblem("a".repeat(255)))
    }

    @Test fun theSeoPageAndTheSearchMissesAreReadWithDefaults() {
        val page = json.decodeFromString(SeoPage.serializer(), """
            {"title":"SEO e qualità del catalogo","search_console":{"title":"Collega","max":255,"pattern":"^[A-Za-z0-9_-]+$","default_city_id":1,
             "cities":[{"id":1,"slug":"padova","name":"Padova","is_active":true,"verification":"abc"}]},
             "steps":{"heading":"Come si collega","items":[{"text":"Apri","code":"https://example.test"},{"text":"Scegli","code":null}]},
             "tools":{"heading":"Strumenti","links":[{"key":"sitemap","label":"Sitemap XML","url":"https://example.test/sitemap.xml"}]},
             "audit":{"tabs":[{"key":"issues","label":"Da sistemare"},{"key":"all","label":"Tutti"}],"novità":true}}
        """.trimIndent())
        assertEquals(1L, page.searchConsole.defaultCityId)
        assertEquals("abc", page.searchConsole.cities.single().verification)
        assertEquals(listOf("issues", "all"), page.audit.tabs.map { it.key })
        assertNull(page.steps.items[1].code)

        val misses = json.decodeFromString(SearchMissPage.serializer(), """
            {"data":[{"id":3,"term":"burraco","count":4,"last_searched_label":"09/09/2026 21:15","city":{"id":1,"name":"Padova"},"abilities":{"remove":true}}],
             "meta":{"current_page":1,"last_page":1,"per_page":20,"total":1},"choices":{"cities":[{"value":"1","label":"Padova"}]}}
        """.trimIndent())
        assertEquals("burraco", misses.page().data.single().term)
        assertEquals(1, misses.page().meta.total)
        assertEquals("Padova", misses.choices.cities.single().label)
    }

    @Test fun theReportOfTheDeskKeepsTheVenueAndOrganizerFilters() {
        val report = json.decodeFromString(AnalyticsReport.serializer(), """
            {"period":"month","until":"2026-09-10","filters":{"event":null,"channel":null,"venue":4,"organizer":null},
             "active_filters":[{"key":"venue","label":"Locale","value":"Circolo Aurora"}],"cards":[{"key":"views","label":"Visite","value":110}]}
        """.trimIndent())
        assertEquals("Circolo Aurora", report.activeFilters.single().value)
        assertEquals(110L, report.cards.single().value)
    }
}
