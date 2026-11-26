package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.data.Poster
import it.fabiodalez.incitta.data.editorial.EditorEventOptions
import it.fabiodalez.incitta.data.editorial.EditorEventResult
import it.fabiodalez.incitta.data.editorial.EditorEventValues
import it.fabiodalez.incitta.data.editorial.EditorGalleryItem
import it.fabiodalez.incitta.data.editorial.EditorImages
import it.fabiodalez.incitta.data.editorial.EditorVenueChoice
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EventEditorApi
import it.fabiodalez.incitta.data.editorial.EventEditorSource
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.events.EventForm
import java.time.ZoneId
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Lo stato della schermata del modulo. */
internal data class EventEditorUiState(
    val loading: Boolean = true,
    val loadError: String? = null,
    val options: EditorEventOptions? = null,
    val catalog: ManagementOptions = ManagementOptions(),
    /** La scheda salvata; `null` per un evento nuovo. */
    val values: EditorEventValues? = null,
    val draft: EditorialEventDraft = EditorialEventDraft(),
    val tab: EditorTab = EditorTab.What,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: String? = null,
    val tagResults: List<NamedRef> = emptyList(),
    val canCreateTag: Boolean = false,
    val venueResults: List<EditorVenueChoice> = emptyList(),
    val approvedVenueResults: List<EditorVenueChoice> = emptyList(),
    val organizerResults: List<NamedRef> = emptyList(),
    val poster: Poster? = null,
    val gallery: List<EditorGalleryItem> = emptyList(),
    /** L'id dell'evento appena creato: la schermata passa alla modifica. */
    val createdId: Long? = null,
) {
    val creating: Boolean get() = values == null
    val zone: ZoneId get() = options?.zone(draft.base.cityId) ?: ZoneId.of("Europe/Rome")
    val cityName: String? get() = options?.city(draft.base.cityId)?.name
}

/**
 * Il modulo evento della redazione: carica scelte e scheda, tiene la bozza,
 * salva, carica le immagini, programma la pubblicazione, genera le date.
 * Ogni chiamata passa da `repository.call { }` (la richiesta della password
 * sui 423). Il server non si chiama mai dentro `update { }`.
 */
internal class EventEditorViewModel(
    private val repository: EditorialRepository,
    private val saved: SavedStateHandle,
    private val source: EventEditorSource = EventEditorApi.of(repository),
) : ViewModel() {
    /** L'evento in modifica; 0 per «Nuovo evento». Dopo la creazione diventa quello nuovo. */
    var eventId: Long = saved.get<Long>(KEY_ID) ?: 0L
        private set
    private val state = MutableStateFlow(EventEditorUiState())
    val uiState: StateFlow<EventEditorUiState> = state.asStateFlow()
    private var tagSearch: Job? = null
    private var venueSearch: Job? = null
    private var organizerSearch: Job? = null

    init { load() }

    fun load() {
        state.update { it.copy(loading = true, loadError = null) }
        viewModelScope.launch {
            try {
                val envelope = repository.call { if (eventId > 0) source.editForm(eventId) else source.newForm() }
                val catalog = ManagementOptions.from(envelope.options.catalog)
                val event = envelope.event
                if (event == null) {
                    state.update { it.copy(loading = false, options = envelope.options, catalog = catalog, draft = EditorialEventDraft.blank(envelope.options)) }
                } else {
                    val (draft, values) = EditorialEventDraft.from(event)
                    state.update {
                        it.copy(loading = false, options = envelope.options, catalog = catalog, values = values, draft = draft, poster = values.poster, gallery = values.gallery)
                    }
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(loading = false, loadError = requestFailureMessage(e)) }
            }
        }
    }

    fun edit(change: (EditorialEventDraft) -> EditorialEventDraft) = state.update { it.copy(draft = change(it.draft)) }
    fun editBase(change: (EventForm) -> EventForm) = state.update { it.copy(draft = it.draft.copy(base = change(it.draft.base))) }
    fun selectTab(tab: EditorTab) = state.update { it.copy(tab = tab) }
    fun consumeMessage() = state.update { it.copy(message = null) }
    fun consumeCreated() = state.update { it.copy(createdId = null) }

    /** La città cambia: il locale scelto non è più fra quelli ammessi (il pannello filtra i locali per città). */
    fun selectCity(id: Long?) {
        val previous = state.value.draft.base.venue
        state.update { it.copy(draft = it.draft.copy(base = it.draft.base.copy(cityId = id, venue = null)), venueResults = emptyList()) }
        if (previous != null) rebase(previous.id, null)
    }

    /** Il locale cambia: «Prima di andare» si ricalcola sul server, come il campo del pannello. */
    fun selectVenue(venue: NamedRef?) {
        val previous = state.value.draft.base.venue
        state.update { it.copy(draft = it.draft.copy(base = it.draft.base.copy(venue = venue))) }
        if (previous?.id != venue?.id) rebase(previous?.id, venue?.id)
    }

    private fun rebase(previous: Long?, next: Long?) {
        val details = EditorialEventDraft.details(state.value.draft, state.value.zone)
        viewModelScope.launch {
            try {
                val merged = repository.call { source.rebaseBeforeGoing(previous, next, details) }
                state.update { it.copy(draft = EditorialEventDraft.withDetails(it.draft, merged)) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) {
                // Senza il ricalcolo restano i valori di prima: il server toglie comunque quelli uguali al locale.
            }
        }
    }

    fun searchTags(query: String) {
        tagSearch?.cancel()
        tagSearch = viewModelScope.launch {
            delay(SEARCH_PAUSE_MILLIS)
            try {
                val found = repository.call { source.tags(query.trim()) }
                state.update { it.copy(tagResults = found.data, canCreateTag = found.meta.canCreate) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { state.update { it.copy(tagResults = emptyList(), canCreateTag = false) } }
        }
    }

    fun createTag(name: String) {
        viewModelScope.launch {
            try {
                val tag = repository.call { source.createTag(name.trim(), eventId.takeIf { it > 0 }) }
                state.update { current ->
                    val tags = (current.draft.base.tags + tag).distinctBy { it.id }
                    current.copy(draft = current.draft.copy(base = current.draft.base.copy(tags = tags)), canCreateTag = false)
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) { state.update { it.copy(message = requestFailureMessage(e)) } }
        }
    }

    /** I locali della città scelta (`approved = false`) o i soli approvati (organizzatore registrato). */
    fun searchVenues(query: String, approved: Boolean = false) {
        venueSearch?.cancel()
        val city = if (approved) null else state.value.draft.base.cityId
        venueSearch = viewModelScope.launch {
            delay(SEARCH_PAUSE_MILLIS)
            try {
                val found = repository.call { source.venues(query.trim(), city, approved) }
                state.update { if (approved) it.copy(approvedVenueResults = found) else it.copy(venueResults = found) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { }
        }
    }

    fun searchOrganizers(query: String) {
        organizerSearch?.cancel()
        organizerSearch = viewModelScope.launch {
            delay(SEARCH_PAUSE_MILLIS)
            try {
                val found = repository.call { source.organizers(query.trim()) }
                state.update { it.copy(organizerResults = found) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { }
        }
    }

    /** «Crea» o «Salva»: il modulo intero; gli errori vanno sotto i campi e la scheda si apre su quella sbagliata. */
    fun save() {
        val current = state.value
        val options = current.options ?: return
        if (current.busy) return
        val body = current.draft.toJson(current.zone, current.creating, options.can)
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { if (current.creating) source.create(body) else source.update(eventId, body) }
                applyResult(result)
                if (current.creating) {
                    // Da qui la schermata è la modifica dell'evento nato (anche dopo una rotazione).
                    eventId = state.value.values?.id ?: 0L
                    saved[KEY_ID] = eventId
                    state.update { it.copy(createdId = eventId.takeIf { id -> id > 0 }) }
                }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                val errors = FormErrors.from(e, EditorialEventDraft.FIELD_KEYS)
                state.update { it.copy(busy = false, errors = errors, tab = tabFor(errors) ?: it.tab) }
            }
        }
    }

    fun uploadPoster(image: PreparedImage) = images { source.uploadPoster(eventId, image) }
    fun removePoster() = images { source.removePoster(eventId) }
    fun addGalleryPhoto(image: PreparedImage) = images { source.addGalleryPhoto(eventId, image) }
    fun removeGalleryPhoto(id: Long) = images { source.removeGalleryPhoto(eventId, id) }

    /** Sposta una foto di un posto, come le frecce del ripetitore del pannello. */
    fun moveGalleryPhoto(id: Long, offset: Int) {
        val ids = state.value.gallery.map { it.id }.toMutableList()
        val from = ids.indexOf(id)
        val to = from + offset
        if (from < 0 || to !in ids.indices) return
        ids.add(to, ids.removeAt(from))
        images { source.orderGallery(eventId, ids) }
    }

    fun imageError(message: String) = state.update { it.copy(message = message) }

    private fun images(block: suspend () -> EditorImages) {
        if (eventId <= 0 || state.value.busy) return
        state.update { it.copy(busy = true, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.call { block() }
                state.update { it.copy(busy = false, poster = result.poster, gallery = result.gallery) }
            } catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, message = requestFailureMessage(e)) }
            }
        }
    }

    /** «Programma pubblicazione» all'istante scelto (con lo scarto). */
    fun schedule(publishAtIso: String) = action { source.schedule(eventId, publishAtIso) }
    fun unschedule() = action { source.unschedule(eventId) }
    /** «Genera date» dalle ricorrenze. */
    fun generate() = action { source.generate(eventId) }

    private fun action(block: suspend () -> EditorEventResult) {
        if (eventId <= 0 || state.value.busy) return
        state.update { it.copy(busy = true, message = null, errors = FormErrors.None) }
        viewModelScope.launch {
            try { applyResult(repository.call { block() }) }
            catch (e: CancellationException) { throw e } catch (e: Exception) {
                state.update { it.copy(busy = false, message = requestFailureMessage(e)) }
            }
        }
    }

    /** La scheda aggiornata dal server prende il posto della bozza: è quella salvata. */
    private fun applyResult(result: EditorEventResult) {
        val (draft, values) = EditorialEventDraft.from(result.data)
        state.update {
            it.copy(busy = false, values = values, draft = draft, poster = values.poster, gallery = values.gallery, message = result.message, errors = FormErrors.None)
        }
    }

    companion object {
        const val KEY_ID = "id"
        private const val SEARCH_PAUSE_MILLIS = 300L
        /** Le chiavi di «Prima di andare» dentro `content_details` (`EventFormRules::BEFORE_GOING_KEYS`). */
        private val BEFORE_GOING_KEYS = setOf(
            "membership", "membership_notes", "accessibility", "accessibility_notes", "feature_ids", "practical_custom",
            "age_groups", "stroller", "changing_table", "kids_area", "food_notes", "start_notes",
        )

        /** La scheda del modulo che contiene il primo campo sbagliato. */
        fun tabFor(errors: FormErrors): EditorTab? {
            val key = errors.fields.keys.firstOrNull() ?: return null
            return when {
                key in setOf("title", "subtitle", "slug", "short_description", "description", "category_id", "tags", "age_restriction", "language", "video_url") -> EditorTab.What
                key in setOf("occurrences", "recurrences") -> EditorTab.When
                key in setOf("city_id", "venue_id", "custom_location", "is_outdoor", "organizer_id", "organizers", "organizer_name", "organizer_url") -> EditorTab.Where
                key in setOf("price_type", "price_min", "price_max", "price_notes", "currency", "ticket_url", "booking_required", "booking_url", "booking_phone", "ticket_tiers") -> EditorTab.Tickets
                key.startsWith("content_details.") && key.removePrefix("content_details.").substringBefore('.') in BEFORE_GOING_KEYS -> EditorTab.BeforeGoing
                key == "content_details" || key.startsWith("content_details.") || key == "seo" -> EditorTab.Content
                key in setOf("facts", "external_links") -> EditorTab.Extra
                else -> EditorTab.Publish
            }
        }
    }
}
