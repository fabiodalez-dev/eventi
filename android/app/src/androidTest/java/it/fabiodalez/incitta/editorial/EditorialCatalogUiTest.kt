package it.fabiodalez.incitta.editorial

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Surface
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.CatalogChange
import it.fabiodalez.incitta.data.editorial.CatalogChoice
import it.fabiodalez.incitta.data.editorial.CatalogChoices
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogOverview
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.data.editorial.CatalogSectionSummary
import it.fabiodalez.incitta.data.editorial.EditorialCatalogSource
import it.fabiodalez.incitta.data.editorial.EditorialCity
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EditorialDeskSource
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialUnlockState
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.ui.InCittaTheme
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormScreen
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogFormViewModel
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogHomeScreen
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogHomeViewModel
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogListScreen
import it.fabiodalez.incitta.ui.editorial.catalog.CatalogListViewModel
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test

/*
 * R11 sulle schermate vere, con un server finto: l'ingresso con le voci che
 * il ruolo vede, l'elenco dei tag con il menu della riga (solo le azioni
 * permesse) e l'unione di un doppione, il modulo di una categoria con gli
 * errori sotto i campi, il modulo di una pagina con l'anteprima e il 409
 * della revisione. I testi si leggono dalle risorse.
 */
class EditorialCatalogUiTest {
    @get:Rule val compose = createAndroidComposeRule<ComponentActivity>()
    private fun text(id: Int, vararg args: Any): String = compose.activity.getString(id, *args)

    private fun show(content: @androidx.compose.runtime.Composable () -> Unit) {
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { Surface { content() } } } }
    }

    private fun repository() = EditorialRepository(1, "t", CatalogUiDesk(), EditorialLocalLock(clock = { 0L }))

    @Test fun theEntryShowsTheSectionsOfTheRole() {
        val api = CatalogUiFake()
        val vm = compose.runOnIdle { CatalogHomeViewModel(repository(), api) }
        val opened = CopyOnWriteArrayList<String>()
        show { CatalogHomeScreen(vm, PaddingValues(), onBack = {}, onOpen = { opened += it.key }) }
        val tags = text(R.string.editorial_catalog_section_count, text(R.string.editorial_catalog_tags), 12)
        compose.waitUntil(5000) { compose.onAllNodesWithText(tags).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_catalog_section_count, text(R.string.editorial_catalog_pages), 3)).assertDoesNotExist()
        compose.onNodeWithText(tags).performClick()
        compose.runOnIdle { assertEquals(listOf("tags"), opened) }
    }

    @Test fun theTagListOffersOnlyAllowedActionsAndMergesADuplicate() {
        val api = CatalogUiFake()
        val vm = compose.runOnIdle { CatalogListViewModel(repository(), api, SavedStateHandle(), CatalogKind.Tags) }
        val opened = CopyOnWriteArrayList<Long>()
        val web = CopyOnWriteArrayList<String>()
        show { CatalogListScreen(vm, PaddingValues(), canCreate = true, onBack = {}, onOpen = { opened += it.id }, onCreate = {}, onOpenWeb = { web += it }) }
        compose.waitUntil(5000) { compose.onAllNodesWithText("Musica dal vivo").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_catalog_new_tag)).assertExists()
        // «Blues» non permette niente: niente menu.
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Blues")).assertDoesNotExist()

        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Musica dal vivo")).performClick()
        // Eliminare resta sul sito (decisione 4): la voce del menu apre la pagina del pannello.
        compose.onNodeWithText(text(R.string.editorial_catalog_action_delete_web)).performClick()
        compose.runOnIdle { assertEquals(listOf("admin/tags/musica-dal-vivo/edit"), web) }
        compose.onNodeWithContentDescription(text(R.string.editorial_actions_for, "Musica dal vivo")).performClick()
        compose.onNodeWithText(text(R.string.editorial_catalog_action_merge)).performClick()
        compose.onNodeWithText(text(R.string.editorial_catalog_merge_title, "Musica dal vivo")).assertExists()
        compose.waitUntil(5000) { compose.onAllNodesWithText("Live music").fetchSemanticsNodes().size > 1 }
        compose.onAllNodesWithText("Live music").onLast().performClick()
        compose.onNodeWithText(text(R.string.editorial_catalog_merge_summary, "Live music", "Musica dal vivo")).assertExists()
        compose.onNodeWithText(text(R.string.editorial_catalog_merge_confirm)).performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("Tag uniti: 2 eventi spostati.").fetchSemanticsNodes().isNotEmpty() }
        compose.runOnIdle { assertTrue("merge:1:2" in api.calls) }

        compose.onNodeWithText("Musica dal vivo").performClick()
        compose.runOnIdle { assertEquals(listOf(1L), opened) }
    }

    @Test fun aNewCategoryShowsTheErrorsUnderTheFields() {
        val api = CatalogUiFake()
        val vm = compose.runOnIdle { CatalogFormViewModel(repository(), api, SavedStateHandle(), CatalogKind.Categories, null) }
        show { CatalogFormScreen(vm, PaddingValues(), admin = true, onBack = {}, onOpenWeb = {}) }
        val create = text(R.string.editorial_catalog_create)
        compose.waitUntil(5000) { compose.onAllNodesWithText(create).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_catalog_section_temporal)).assertExists()
        compose.onNodeWithText(text(R.string.editorial_catalog_field_nightlife)).assertExists()
        // Admin: visibilità sui motori e contenuto dimostrativo.
        compose.onNodeWithText(text(R.string.editorial_catalog_field_demo)).performScrollTo().assertExists()
        compose.onNodeWithText(create).performScrollTo().performClick()
        compose.onAllNodesWithText(text(R.string.editorial_catalog_required)).onFirst().assertExists()
        compose.runOnIdle { assertTrue(api.calls.none { it.startsWith("create") }) }

        compose.onNodeWithText(text(R.string.editorial_catalog_field_name)).performScrollTo().performTextInput("Musica")
        compose.onNodeWithText(create).performScrollTo().performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText("L’indirizzo è già usato.").fetchSemanticsNodes().isNotEmpty() }
        compose.runOnIdle { assertTrue("create:categories" in api.calls) }
    }

    @Test fun aPageShowsThePreviewAndTheConflict() {
        val api = CatalogUiFake()
        val vm = compose.runOnIdle { CatalogFormViewModel(repository(), api, SavedStateHandle(), CatalogKind.Pages, 7) }
        show { CatalogFormScreen(vm, PaddingValues(), admin = true, onBack = {}, onOpenWeb = {}) }
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_catalog_preview)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_catalog_preview)).performScrollTo().performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_catalog_preview_title)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Testo dell’anteprima", substring = true).assertExists()
        compose.onNodeWithText(text(R.string.editorial_catalog_preview_close)).performClick()

        compose.onNodeWithText(text(R.string.editorial_catalog_save)).performScrollTo().performClick()
        compose.waitUntil(5000) { compose.onAllNodesWithText(text(R.string.editorial_catalog_reload)).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText(text(R.string.editorial_catalog_conflict)).assertExists()
        compose.runOnIdle { assertEquals("r1", (api.lastBody?.get("revision") as? JsonPrimitive)?.content) }
    }
}

private class CatalogUiDesk : EditorialDeskSource {
    override suspend fun enabled() = true
    override suspend fun desk(city: String?) = EditorialDesk(role = "admin", city = EditorialCity(1, "padova", "Padova"), unlock = EditorialUnlockState(unlocked = true))
    override suspend fun options() = ManagementOptions()
    override suspend fun unlock(password: String) = EditorialUnlockState(unlocked = true)
}

private fun uiAbilities(vararg keys: String) = buildJsonObject { keys.forEach { put(it, JsonPrimitive(true)) } }

private class CatalogUiFake : EditorialCatalogSource {
    val calls = CopyOnWriteArrayList<String>()
    @Volatile var lastBody: JsonObject? = null
    override suspend fun overview() = CatalogOverview(listOf(CatalogSectionSummary("categories", 8), CatalogSectionSummary("tags", 12), CatalogSectionSummary("cities", 1)))
    override suspend fun choices() = CatalogChoices(
        categories = listOf(CatalogChoice("1", "Musica")),
        cities = listOf(CatalogChoice("1", "Padova")),
        seoIndexing = listOf(CatalogChoice("automatic", "Automatico"), CatalogChoice("excluded", "Escludi dai motori")),
        timezones = listOf("Europe/Rome"),
    )
    override suspend fun list(kind: CatalogKind, params: List<Pair<String, String>>): EditorialPage<CatalogRecord> {
        calls += "list:${kind.path}"
        val rows = listOf(
            CatalogRecord(id = 1, name = "Musica dal vivo", eventsCount = 4, usageCount = 4, isApproved = true, webPath = "admin/tags/musica-dal-vivo/edit", abilities = uiAbilities("update", "delete_on_web", "merge")),
            CatalogRecord(id = 2, name = "Live music", eventsCount = 2, abilities = uiAbilities("update", "delete_on_web", "merge")),
            CatalogRecord(id = 3, name = "Blues"),
        )
        return EditorialPage(rows, PageMeta(currentPage = 1, lastPage = 1, total = rows.size))
    }
    override suspend fun show(kind: CatalogKind, id: Long) =
        CatalogRecord(id = id, title = "Privacy", slug = "privacy", body = "# Informativa", revision = "r1", isPublished = true, abilities = uiAbilities("update", "delete_on_web"))
    override suspend fun create(kind: CatalogKind, body: JsonObject): CatalogChange {
        calls += "create:${kind.path}"
        throw ApiException(422, ApiProblem(message = "Dati non validi", fields = mapOf("slug" to listOf("L’indirizzo è già usato."))))
    }
    override suspend fun update(kind: CatalogKind, id: Long, body: JsonObject): CatalogChange {
        calls += "update:${kind.path}:$id"
        lastBody = body
        throw ApiException(409, ApiProblem(message = "Nel frattempo qualcuno ha modificato questa pagina", code = "CONFLICT"))
    }
    override suspend fun mergeTag(keepId: Long, absorbedId: Long): CatalogChange {
        calls += "merge:$keepId:$absorbedId"
        return CatalogChange(CatalogRecord(id = keepId, name = "Musica dal vivo", synonyms = listOf("Live music"), abilities = uiAbilities("update", "delete_on_web", "merge")), "Tag uniti: 2 eventi spostati.")
    }
    override suspend fun previewPage(body: String) = "<h1>Informativa</h1><p>Testo dell’anteprima</p>"
}
