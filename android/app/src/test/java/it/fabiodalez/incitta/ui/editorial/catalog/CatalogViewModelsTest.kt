package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.CatalogBounds
import it.fabiodalez.incitta.data.editorial.CatalogChange
import it.fabiodalez.incitta.data.editorial.CatalogChoices
import it.fabiodalez.incitta.data.editorial.CatalogContentDetails
import it.fabiodalez.incitta.data.editorial.CatalogFaq
import it.fabiodalez.incitta.data.editorial.CatalogFlag
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogOverview
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.data.editorial.CatalogSectionSummary
import it.fabiodalez.incitta.data.editorial.CatalogSeo
import it.fabiodalez.incitta.data.editorial.EditorialCatalogSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.double
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R11 con un server finto: ingresso, elenchi con filtri e azioni di riga, unione dei tag, moduli con bozza, 409 delle pagine. */
@OptIn(ExperimentalCoroutinesApi::class)
class CatalogViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun abilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

    private fun tag(id: Long, name: String, vararg can: String) = CatalogRecord(id = id, name = name, abilities = abilities(*can))

    private inner class FakeCatalog : EditorialCatalogSource {
        val calls = mutableListOf<String>()
        var rows = listOf(tag(1, "Jazz", "update", "destroy", "merge"), tag(2, "Swing", "update", "destroy", "merge"), tag(3, "Blues"))
        var failure: Exception? = null
        var lastBody: JsonObject? = null
        override suspend fun overview() = CatalogOverview(listOf(
            CatalogSectionSummary("categories", 4, canCreate = false),
            CatalogSectionSummary("sconosciuta", 1),
            CatalogSectionSummary("cities", 2, canCreate = false),
        ))
        override suspend fun choices() = CatalogChoices(timezones = listOf("Europe/Rome"))
        override suspend fun list(kind: CatalogKind, params: List<Pair<String, String>>): EditorialPage<CatalogRecord> {
            calls += "list:${kind.path}:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))
        }
        override suspend fun show(kind: CatalogKind, id: Long): CatalogRecord {
            calls += "show:${kind.path}:$id"
            failure?.let { throw it }
            return when (kind) {
                CatalogKind.Pages -> CatalogRecord(id = id, title = "Privacy", slug = "privacy", body = "# Titolo", revision = "r1", abilities = abilities("update", "destroy"))
                CatalogKind.Cities -> CatalogRecord(id = id, name = "Padova", centerLat = 45.4064, centerLng = 11.8768, nightCutoffTime = "06:00", timezone = "Europe/Rome",
                    provinceCode = "PD", provinceName = "Padova", region = "Veneto", settings = listOf(CatalogFlag("community", true)), abilities = abilities("update"))
                else -> CatalogRecord(id = id, name = "Musica", sortOrder = 2, abilities = abilities("update", "destroy"),
                    contentDetails = CatalogContentDetails(introduction = "<p><strong>Ciao</strong></p>", faqs = listOf(CatalogFaq("Quando?", "<p>Sempre</p>"))),
                    seo = CatalogSeo(title = "Musica dal vivo", indexing = "excluded"))
            }
        }
        override suspend fun create(kind: CatalogKind, body: JsonObject): CatalogChange { calls += "create:${kind.path}"; lastBody = body; failure?.let { throw it }; return CatalogChange(CatalogRecord(id = 99, name = "Nuova"), "Creato.") }
        override suspend fun update(kind: CatalogKind, id: Long, body: JsonObject): CatalogChange {
            calls += "update:${kind.path}:$id"; lastBody = body; failure?.let { throw it }
            return CatalogChange(CatalogRecord(id = id, title = "Privacy", body = (body["body"] as? JsonPrimitive)?.content ?: "", revision = "r2", abilities = abilities("update")), "Modifiche salvate.")
        }
        override suspend fun destroy(kind: CatalogKind, id: Long): String? { calls += "destroy:${kind.path}:$id"; failure?.let { throw it }; return "Eliminato." }
        override suspend fun mergeTag(keepId: Long, absorbedId: Long): CatalogChange { calls += "merge:$keepId:$absorbedId"; return CatalogChange(tag(keepId, "Jazz", "update").copy(synonyms = listOf("Swing")), "Tag uniti: 3 eventi spostati.") }
        override suspend fun previewPage(body: String): String { calls += "preview"; return "<h1>Titolo</h1>" }
    }

    private fun repository() = EditorialRepository(1, "t", FakeDesk(unlocked = true), EditorialLocalLock(clock = { 0L }))

    @Test fun theEntryShowsOnlyTheKnownSections() {
        val vm = CatalogHomeViewModel(repository(), FakeCatalog())
        val sections = (vm.sections.value as Loadable.Ready).value
        assertEquals(listOf(CatalogKind.Categories, CatalogKind.Cities), sections.map { it.kind })
        assertFalse(sections.first().canCreate)
    }

    @Test fun theListSendsFiltersAndSortAndKeepsThemAfterRotation() {
        val api = FakeCatalog()
        val saved = SavedStateHandle()
        val vm = CatalogListViewModel(repository(), api, saved, CatalogKind.Tags)
        assertEquals(3, vm.rows.value.items.size)
        vm.applyFilters(mapOf("is_approved" to "0", "vuoto" to ""))
        vm.sortBy("-usage_count")
        assertTrue(api.calls.last().startsWith("list:tags:"))
        assertTrue(api.calls.last().contains("filter[is_approved]=0"))
        assertTrue(api.calls.last().contains("sort=-usage_count"))
        assertFalse(api.calls.last().contains("vuoto"))
        val again = CatalogListViewModel(repository(), api, saved, CatalogKind.Tags)
        assertEquals(mapOf("is_approved" to "0"), again.rows.value.query.filters)
        assertEquals("-usage_count", again.rows.value.query.sort)
        assertEquals(listOf("is_approved" to R.string.editorial_catalog_field_approved), catalogFilters(CatalogKind.Tags))
    }

    @Test fun deleteAndMergeRunOnlyWhenTheRowAllowsThem() {
        val api = FakeCatalog()
        val vm = CatalogListViewModel(repository(), api, SavedStateHandle(), CatalogKind.Tags)
        val blues = vm.rows.value.items.first { it.id == 3L }
        vm.requestDelete(blues)
        vm.requestMerge(blues)
        assertNull(vm.actions.value.pendingDelete)
        assertNull(vm.actions.value.merge)

        val jazz = vm.rows.value.items.first { it.id == 1L }
        vm.requestDelete(jazz)
        vm.confirmDelete()
        assertTrue("destroy:tags:1" in api.calls)
        assertFalse(vm.rows.value.items.any { it.id == 1L })
        assertEquals(UiText.Raw("Eliminato."), vm.actions.value.message)

        val swing = vm.rows.value.items.first { it.id == 2L }
        api.rows = listOf(tag(2, "Swing", "merge"), tag(3, "Blues"))
        vm.requestMerge(swing)
        val merge = vm.actions.value.merge!!
        /* Il tag che resta non è fra le scelte. */
        assertEquals(listOf(3L), (merge.results as Loadable.Ready).value.map { it.id })
        vm.confirmMerge()
        assertTrue(api.calls.none { it.startsWith("merge:") })
        vm.chooseMerge(tag(3, "Blues"))
        vm.confirmMerge()
        assertTrue("merge:2:3" in api.calls)
        assertNull(vm.actions.value.merge)
        assertFalse(vm.rows.value.items.any { it.id == 3L })
        assertEquals(listOf("Swing"), vm.rows.value.items.first { it.id == 2L }.synonyms)
        assertEquals(UiText.Raw("Tag uniti: 3 eventi spostati."), vm.actions.value.message)
    }

    @Test fun aNewItemStopsOnLocalProblemsThenCreates() {
        val api = FakeCatalog()
        val vm = CatalogFormViewModel(repository(), api, SavedStateHandle(), CatalogKind.Categories, null)
        assertTrue(vm.isNew)
        assertEquals(Loadable.Ready(null), vm.state.value.record)
        vm.save(admin = true)
        assertEquals(R.string.editorial_catalog_required, vm.state.value.problems["name"])
        assertTrue(api.calls.none { it.startsWith("create") })
        vm.edit { it.copy(name = "Laboratori", defaultDuration = "novanta") }
        vm.save(admin = true)
        assertEquals(R.string.editorial_catalog_integer, vm.state.value.problems["default_duration_minutes"])
        vm.edit { it.copy(defaultDuration = "90") }
        vm.save(admin = true)
        assertEquals("create:categories", api.calls.last())
        assertEquals(CatalogDone.Created(99), vm.state.value.done)
        assertEquals(90, api.lastBody!!["default_duration_minutes"]!!.jsonPrimitive.int)
        assertEquals(JsonNull, api.lastBody!!["parent_id"])
    }

    @Test fun theDraftSurvivesRotationAndTheServerErrorsGoUnderTheFields() {
        val api = FakeCatalog()
        val saved = SavedStateHandle()
        val vm = CatalogFormViewModel(repository(), api, saved, CatalogKind.Categories, 5)
        assertEquals("Musica", vm.draft.value.name)
        /* Il testo formattato del sito diventa quello dell'editor. */
        assertEquals("**Ciao**", vm.draft.value.introduction)
        assertEquals("Sempre", vm.draft.value.faqs.single().answer)
        vm.edit { it.copy(name = "Musica live") }
        val again = CatalogFormViewModel(repository(), api, saved, CatalogKind.Categories, 5)
        assertEquals("Musica live", again.draft.value.name)

        api.failure = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf("slug" to listOf("Indirizzo già usato."), "altro" to listOf("Qualcosa"))))
        again.save(admin = true)
        assertEquals("Indirizzo già usato.", again.state.value.errors.fields["slug"])
        assertEquals("Qualcosa", again.state.value.errors.general)
    }

    @Test fun aChangedPageAnswers409AndReloadReplacesTheDraft() {
        val api = FakeCatalog()
        val vm = CatalogFormViewModel(repository(), api, SavedStateHandle(), CatalogKind.Pages, 7)
        assertEquals("r1", vm.draft.value.revision)
        vm.edit { it.copy(body = "# Titolo corretto") }
        api.failure = ApiException(409, ApiProblem("Nel frattempo qualcuno ha modificato questa pagina", "CONFLICT"))
        vm.save(admin = true)
        assertTrue(vm.state.value.conflict)
        assertEquals("r1", (api.lastBody!!["revision"] as JsonPrimitive).content)
        api.failure = null
        vm.load()
        assertFalse(vm.state.value.conflict)
        assertEquals("# Titolo", vm.draft.value.body)
        vm.edit { it.copy(body = "# Nuovo") }
        vm.save(admin = true)
        assertEquals("r2", vm.draft.value.revision)
        assertEquals(UiText.Raw("Modifiche salvate."), vm.state.value.message)
        vm.preview()
        assertEquals(Loadable.Ready("<h1>Titolo</h1>"), vm.state.value.preview)
    }

    @Test fun deletingFromTheFormGoesBackToTheList() {
        val api = FakeCatalog()
        val vm = CatalogFormViewModel(repository(), api, SavedStateHandle(), CatalogKind.Tags, 4)
        vm.requestDelete()
        assertTrue(vm.state.value.confirmDelete)
        vm.confirmDelete()
        assertTrue("destroy:tags:4" in api.calls)
        assertEquals(CatalogDone.Deleted, vm.state.value.done)
        vm.consumeDone()
        assertNull(vm.state.value.done)
    }

    @Test fun theCityBodyHasTheFieldsOfThePanelForm() {
        val record = CatalogRecord(
            id = 1, name = "Padova", slug = "padova", provinceCode = "PD", provinceName = "Padova", region = "Veneto",
            centerLat = 45.4064, centerLng = 11.8768, defaultZoom = 12, radiusKm = 30, bounds = CatalogBounds(north = 45.6),
            timezone = "Europe/Rome", nightCutoffTime = "06:00", startingSoonMinutes = 180, locale = "it", isActive = true,
            settings = listOf(CatalogFlag("community", true)),
        )
        val draft = CatalogDraft.from(CatalogKind.Cities, record)
        assertTrue(draft.problems(CatalogKind.Cities).isEmpty())
        val body = draft.copy(nightCutoff = "05:30", centerLat = "45,41").toJson(CatalogKind.Cities, admin = false)
        assertEquals("05:30", body["night_cutoff_time"]!!.jsonPrimitive.content)
        assertEquals(45.41, body["center_lat"]!!.jsonPrimitive.double, 0.0001)
        val bounds = body["bounds"]!!.jsonObject
        assertEquals(45.6, bounds["north"]!!.jsonPrimitive.double, 0.0001)
        assertEquals(JsonNull, bounds["south"])
        assertTrue((body["settings"] as JsonArray).single().jsonObject["enabled"]!!.jsonPrimitive.boolean)
        /* Chi non è admin non manda visibilità sui motori e contenuto dimostrativo. */
        assertFalse(body["seo"]!!.jsonObject.containsKey("indexing"))
        assertFalse(body.containsKey("is_demo"))
        assertTrue(CatalogDraft.from(CatalogKind.Cities, record).toJson(CatalogKind.Cities, admin = true)["seo"]!!.jsonObject.containsKey("indexing"))
        /* Un'ora scritta male si ferma sul telefono. */
        assertEquals(R.string.editorial_catalog_time, draft.copy(nightCutoff = "25:00").problems(CatalogKind.Cities)["night_cutoff_time"])
        /* Le caratteristiche non hanno le sezioni comuni, e quelle di sistema non mandano «Disponibile». */
        val feature = CatalogDraft.empty(CatalogKind.Features).copy(name = "Guardaroba", isSystem = true).toJson(CatalogKind.Features, admin = true)
        assertFalse(feature.containsKey("content_details"))
        assertFalse(feature.containsKey("is_active"))
    }
}
