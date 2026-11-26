package it.fabiodalez.incitta.ui.editorial.social

import androidx.lifecycle.SavedStateHandle
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.editorial.EditorialLocalLock
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialSocialBatch
import it.fabiodalez.incitta.data.editorial.EditorialSocialSource
import it.fabiodalez.incitta.data.editorial.FakeDesk
import it.fabiodalez.incitta.data.editorial.SocialMetaLink
import it.fabiodalez.incitta.data.editorial.SocialPublicationRow
import it.fabiodalez.incitta.data.editorial.SocialResult
import it.fabiodalez.incitta.data.editorial.SocialResultMeta
import it.fabiodalez.incitta.data.editorial.SocialSettings
import it.fabiodalez.incitta.data.editorial.SocialSettingsValues
import it.fabiodalez.incitta.data.editorial.SocialToday
import it.fabiodalez.incitta.data.management.DownloadedFile
import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialBatchSummary
import it.fabiodalez.incitta.data.management.SocialCalendar
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialCatalogMeta
import it.fabiodalez.incitta.data.management.SocialCatalogPage
import it.fabiodalez.incitta.data.management.SocialCatalogQuery
import it.fabiodalez.incitta.data.management.SocialDay
import it.fabiodalez.incitta.data.management.SocialDayMeta
import it.fabiodalez.incitta.data.management.SocialLabel
import it.fabiodalez.incitta.data.management.SocialOptions
import it.fabiodalez.incitta.ui.editorial.common.EditorialPage
import it.fabiodalez.incitta.ui.editorial.common.PageMeta
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import java.io.File
import java.time.LocalDateTime
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/* R9 con un server finto: lo studio della città, la pubblicazione di un pacchetto, lo storico, le impostazioni, «Social di oggi». */
@OptIn(ExperimentalCoroutinesApi::class)
class SocialViewModelsTest {
    private val dispatcher = UnconfinedTestDispatcher()

    @Before fun main() { Dispatchers.setMain(dispatcher) }
    @After fun reset() { Dispatchers.resetMain() }

    private fun card(id: Long, day: String, selectable: Boolean = true) =
        SocialCard(id = id, eventId = id * 10, title = "Serata $id", businessDate = day, selectable = selectable, status = SocialLabel("scheduled", "In programma"))

    private class FakeSocial : EditorialSocialSource {
        val calls = mutableListOf<String>()
        var days = mapOf(
            "2026-09-11" to listOf(SocialCard(id = 1, title = "Concerto", businessDate = "2026-09-11", selectable = true), SocialCard(id = 2, title = "Jam", businessDate = "2026-09-11", selectable = true)),
            "2026-09-12" to listOf(SocialCard(id = 3, title = "Reading", businessDate = "2026-09-12", selectable = true)),
        )
        var failNext: Exception? = null
        var settings = SocialSettings(values = SocialSettingsValues(cityId = 1, caption = "Il :data"))
        var publication = SocialPublicationRow(id = 7, platform = "telegram", status = SocialLabel("scheduled", "Programmata"),
            abilities = buildJsonObject { put("reschedule", JsonPrimitive(true)); put("cancel", JsonPrimitive(true)) })

        private fun maybeFail() { failNext?.let { failNext = null; throw it } }
        private fun batchOf(id: String) = EditorialSocialBatch(id = id, date = "2026-09-11", captions = listOf("Parte 1"))

        override suspend fun catalog(city: String?, query: SocialCatalogQuery): SocialCatalogPage {
            calls += "catalog:$city:${query.period}:${query.eventId}"
            return SocialCatalogPage(days.values.flatten(), SocialCatalogMeta(from = "2026-09-10", until = "2026-10-09", total = 3, timezone = "Europe/Rome"))
        }
        override suspend fun calendar(city: String?, month: String, date: String, eventId: Long?, search: String): SocialCalendar { calls += "calendar:$city:$month"; return SocialCalendar() }
        override suspend fun day(city: String?, date: String?, eventId: Long?): SocialDay {
            calls += "day:$city:$date:$eventId"
            val day = date ?: "2026-09-11"
            return SocialDay(days[day].orEmpty(), SocialDayMeta(date = day, timezone = "Europe/Rome"))
        }
        override suspend fun today(city: String?): SocialToday { calls += "today:$city"; return SocialToday(days["2026-09-11"].orEmpty()) }
        override suspend fun prepareToday(city: String?): EditorialSocialBatch { calls += "prepare-today:$city"; maybeFail(); return batchOf("oggi") }
        override suspend fun recent(city: String?): List<SocialBatchSummary> { calls += "recent:$city"; return emptyList() }
        override suspend fun prepare(city: String?, date: String, occurrences: List<Long>, options: SocialOptions, eventId: Long?): EditorialSocialBatch {
            calls += "prepare:$city:$date:$occurrences:${options.title}"
            maybeFail()
            return batchOf("nuovo")
        }
        override suspend fun batch(id: String): EditorialSocialBatch { calls += "batch:$id"; return batchOf(id) }
        override suspend fun image(batchId: String, item: SocialBatchItem, directory: File): DownloadedFile = DownloadedFile(File(directory, "a.jpg"), "image/jpeg")
        override suspend fun zip(batch: EditorialSocialBatch, directory: File): DownloadedFile = DownloadedFile(File(directory, "a.zip"), "application/zip")
        override suspend fun publish(batchId: String): SocialResult<List<SocialPublicationRow>> {
            calls += "publish:$batchId"
            maybeFail()
            return SocialResult(listOf(publication.copy(status = SocialLabel("queued", "In coda"))), SocialResultMeta("Pubblicazione messa in coda."))
        }
        override suspend fun schedule(batchId: String, local: String): SocialResult<List<SocialPublicationRow>> {
            calls += "schedule:$batchId:$local"
            maybeFail()
            return SocialResult(listOf(publication), SocialResultMeta("Pubblicazione programmata"))
        }
        override suspend fun publications(city: String?, params: List<Pair<String, String>>): EditorialPage<SocialPublicationRow> {
            calls += "publications:$city:" + params.joinToString("&") { "${it.first}=${it.second}" }
            return EditorialPage(listOf(publication), PageMeta(total = 1))
        }
        override suspend fun reschedule(id: Long, local: String): SocialResult<SocialPublicationRow> {
            calls += "reschedule:$id:$local"
            return SocialResult(publication.copy(scheduledAt = "2026-09-10T18:00:00+00:00"), SocialResultMeta("Programmazione aggiornata", changed = true))
        }
        override suspend fun cancel(id: Long): SocialResult<SocialPublicationRow> {
            calls += "cancel:$id"
            return SocialResult(publication.copy(status = SocialLabel("cancelled", "Programmazione annullata"), abilities = null), SocialResultMeta("Annullata"))
        }
        override suspend fun retry(id: Long): SocialResult<SocialPublicationRow> { calls += "retry:$id"; maybeFail(); return SocialResult(publication.copy(status = SocialLabel("queued", "In coda"))) }
        override suspend fun settings(): SocialSettings { calls += "settings"; return settings }
        override suspend fun saveSettings(values: SocialSettingsValues): SocialResult<SocialSettings> {
            calls += "save:${values.caption}"
            maybeFail()
            settings = settings.copy(values = values.copy(automatic = false))
            return SocialResult(settings, SocialResultMeta("Impostazioni salvate", warning = if (values.automatic) "Salva e verifica un collegamento." else null))
        }
        override suspend fun verifyMeta(): SocialResult<SocialSettings> { calls += "verify"; maybeFail(); return SocialResult(settings, SocialResultMeta("Collegamento verificato")) }
        override suspend fun verifyTelegram(): SocialResult<SocialSettings> { calls += "telegram"; maybeFail(); return SocialResult(settings) }
        override suspend fun metaLink(): SocialMetaLink { calls += "meta-link"; return SocialMetaLink(url = "https://example.test/social/meta/collega") }
        override suspend fun disconnectMeta(): SocialResult<SocialSettings> { calls += "disconnect"; return SocialResult(settings, SocialResultMeta("Scollegato")) }
    }

    private val desk = FakeDesk(unlocked = true)
    private val repository = EditorialRepository(1, "t", desk, EditorialLocalLock(clock = { 0L })).also { it.selectedCity = "padova" }
    private val source = FakeSocial()

    private fun invalid(field: String, message: String) = ApiException(422, ApiProblem(message = message, code = "VALIDATION_FAILED", fields = mapOf(field to listOf(message))))

    @Test fun theStudioOpensOnTheServerDayOfTheWorkingCityAndSelectsAllItsEvenings() {
        val viewModel = EditorialSocialStudioViewModel(repository, source, SavedStateHandle(), searchDelayMillis = 0)
        val state = viewModel.uiState.value
        assertEquals("2026-09-11", state.date)
        assertEquals(listOf(1L, 2L), state.selected)
        assertTrue(source.calls.contains("day:padova:null:null"))
        assertTrue(source.calls.contains("catalog:padova:next30:null"))
        assertTrue(source.calls.contains("recent:padova"))
        assertTrue(source.calls.contains("calendar:padova:2026-09"))
    }

    @Test fun aCardOfAnotherDayMovesTheDayAndIsTheOnlyOneChosen() {
        val viewModel = EditorialSocialStudioViewModel(repository, source, SavedStateHandle(), searchDelayMillis = 0)
        viewModel.pick(card(3, "2026-09-12"))
        assertEquals("2026-09-12", viewModel.uiState.value.date)
        assertEquals(listOf(3L), viewModel.uiState.value.selected)
        // Una dello stesso giorno entra o esce; una non preparabile non fa niente.
        viewModel.pick(card(3, "2026-09-12"))
        assertEquals(emptyList<Long>(), viewModel.uiState.value.selected)
        viewModel.pick(card(9, "2026-09-12", selectable = false))
        assertEquals(emptyList<Long>(), viewModel.uiState.value.selected)
    }

    @Test fun theHandWrittenTitleIsForOneEveningAndPreparingOpensTheNewBatch() {
        val viewModel = EditorialSocialStudioViewModel(repository, source, SavedStateHandle(), searchDelayMillis = 0)
        viewModel.setOptions(SocialOptions(title = "Stasera"))
        viewModel.generate()
        assertNotNull(viewModel.uiState.value.errors.field("title"))
        assertFalse(source.calls.any { it.startsWith("prepare:") })

        viewModel.toggle(2)
        viewModel.generate()
        assertTrue(source.calls.contains("prepare:padova:2026-09-11:[1]:Stasera"))
        assertEquals("nuovo", viewModel.uiState.value.prepared)
        viewModel.consumePrepared()
        assertNull(viewModel.uiState.value.prepared)

        source.failNext = invalid("generate", "Un testo è troppo lungo.")
        viewModel.generate()
        assertEquals("Un testo è troppo lungo.", viewModel.uiState.value.errors.general)
    }

    @Test fun changingCityRestartsTheStudioWithoutTheEvent() {
        val saved = SavedStateHandle(mapOf("event" to 40L))
        val viewModel = EditorialSocialStudioViewModel(repository, source, saved, searchDelayMillis = 0)
        assertEquals(40L, viewModel.uiState.value.event?.id)
        assertTrue(source.calls.contains("day:padova:null:40"))

        viewModel.selectCity("vicenza")
        assertEquals("vicenza", repository.selectedCity)
        assertTrue(desk.calls.contains("desk:vicenza"))
        assertNull(viewModel.uiState.value.event)
        assertTrue(source.calls.contains("day:vicenza:null:null"))
        assertTrue(source.calls.contains("catalog:vicenza:next30:null"))
        assertNull(saved.get<Long>("event"))
    }

    @Test fun aBatchPublishesAfterTheConfirmAndSchedulesInTheCityTime() {
        val viewModel = EditorialSocialBatchViewModel(repository, source, SavedStateHandle(mapOf("id" to "abc")))
        assertTrue(viewModel.uiState.value.batch is Loadable.Ready)

        viewModel.schedule()
        assertNotNull(viewModel.uiState.value.errors.field("scheduled_at"))
        assertFalse(source.calls.any { it.startsWith("schedule:") })

        viewModel.setScheduledAt(LocalDateTime.of(2026, 9, 10, 18, 30))
        viewModel.schedule()
        assertTrue(source.calls.contains("schedule:abc:2026-09-10T18:30"))
        assertEquals(UiText.Raw("Pubblicazione programmata"), viewModel.uiState.value.message)
        assertTrue((viewModel.uiState.value.batch as Loadable.Ready).value.inHistory)

        source.failNext = invalid("publish", "Salva e verifica un collegamento con almeno un canale attivo prima di pubblicare.")
        viewModel.publish()
        assertEquals("Salva e verifica un collegamento con almeno un canale attivo prima di pubblicare.", viewModel.uiState.value.errors.field("publish"))
        viewModel.publish()
        assertEquals("In coda", viewModel.uiState.value.history.single().status.label)
    }

    @Test fun theHistoryReschedulesCancelsAndRetriesOnlyAfterTheConfirm() {
        val viewModel = SocialPublicationsViewModel(repository, source, SavedStateHandle())
        assertTrue(source.calls.any { it.startsWith("publications:padova:page=1&per_page=30") })
        val row = viewModel.list.current.value.items.single()

        viewModel.startReschedule(row, null)
        viewModel.confirmReschedule()
        assertFalse(source.calls.any { it.startsWith("reschedule:") })
        viewModel.setReschedule(LocalDateTime.of(2026, 9, 10, 20, 0))
        viewModel.confirmReschedule()
        assertTrue(source.calls.contains("reschedule:7:2026-09-10T20:00"))
        assertNull(viewModel.uiState.value.reschedule)

        viewModel.cancel(row)
        assertEquals("cancelled", viewModel.list.current.value.items.single().status.value)

        viewModel.askRetry(row)
        assertFalse(source.calls.contains("retry:7"))
        viewModel.confirmRetry()
        assertTrue(source.calls.contains("retry:7"))

        viewModel.applyFilters(mapOf("status" to "failed"))
        assertTrue(source.calls.any { it.contains("filter[status]=failed") })
    }

    @Test fun theSettingsSaveBeforeEveryCheckAndOpenTheMetaLinkInTheBrowser() {
        val viewModel = SocialSettingsViewModel(repository, source)
        assertEquals("Il :data", viewModel.uiState.value.draft?.caption)

        viewModel.edit(viewModel.uiState.value.draft!!.copy(caption = "Il :data a :citta", automatic = true))
        assertTrue(viewModel.uiState.value.dirty)
        viewModel.verifyMeta()
        assertEquals(listOf("settings", "save:Il :data a :citta", "verify"), source.calls)
        assertEquals(UiText.Raw("Collegamento verificato"), viewModel.uiState.value.message)
        // L'avviso dell'automatico spento resta anche dopo la verifica.
        assertEquals(UiText.Raw("Salva e verifica un collegamento."), viewModel.uiState.value.warning)
        assertFalse(viewModel.uiState.value.dirty)

        source.failNext = invalid("caption", "Segnaposti non riconosciuti: inventato")
        viewModel.edit(viewModel.uiState.value.draft!!.copy(caption = ":inventato"))
        viewModel.verifyTelegram()
        assertEquals("Segnaposti non riconosciuti: inventato", viewModel.uiState.value.errors.field("caption"))
        assertFalse(source.calls.contains("telegram"))

        viewModel.edit(viewModel.uiState.value.draft!!.copy(caption = "Il :data"))
        viewModel.connectMeta()
        assertEquals("https://example.test/social/meta/collega", viewModel.uiState.value.openUrl)
        viewModel.consumeOpenUrl()
        assertNull(viewModel.uiState.value.openUrl)
    }

    @Test fun todayPreparesThePortraitBatchAndShowsTheReasonWhenItCannot() {
        val viewModel = SocialTodayViewModel(repository, source)
        assertTrue(viewModel.uiState.value.today is Loadable.Ready)
        source.failNext = invalid("generate", "Scegli almeno un evento da preparare.")
        viewModel.prepare()
        assertEquals("Scegli almeno un evento da preparare.", viewModel.uiState.value.error)
        viewModel.prepare()
        assertEquals("oggi", viewModel.uiState.value.prepared)
        assertTrue(source.calls.contains("prepare-today:padova"))
    }

    @Test fun aLockedServerAsksForThePasswordAndRepeatsTheCall() {
        val locked = object : EditorialSocialSource by source {
            var first = true
            override suspend fun today(city: String?): SocialToday {
                if (first) { first = false; throw ApiException(423, ApiProblem(message = "Bloccata", code = "EDITORIAL_LOCKED")) }
                return source.today(city)
            }
        }
        val viewModel = SocialTodayViewModel(repository, locked)
        assertEquals(EditorialLockReason.Locked, repository.guard.prompt.value?.reason)
        repository.guard.submit("segreta")
        assertTrue(desk.calls.contains("unlock:segreta"))
        assertTrue(viewModel.uiState.value.today is Loadable.Ready)
    }
}
