package it.fabiodalez.incitta.ui.management.profile

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ContactSettings
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.ManagementProfileSource
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.OrganizerProfile
import it.fabiodalez.incitta.data.management.OrganizerProfileDraft
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueImageSlot
import it.fabiodalez.incitta.data.management.VenueProfile
import it.fabiodalez.incitta.data.management.VenueProfileDraft
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.UiText
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull

/*
 * I ViewModel del profilo (P4). Ogni sezione ha il suo: legge la scheda,
 * tiene il modulo nello stato salvato (sopravvive alla rotazione e alla morte
 * del processo) e salva solo le chiavi della sezione. Il server risponde con
 * la scheda aggiornata, che diventa il nuovo punto di partenza del modulo.
 */

private val draftJson = Json { ignoreUnknownKeys = true; encodeDefaults = true }

/** Il soggetto aperto nella gestione: quello scelto nel menu, o il primo. */
internal fun currentSubject(repository: ManagementRepository): ManagementSubject? {
    val list = repository.subjects.value ?: return null
    return list.find(repository.selectedSubjectKey) ?: list.all().firstOrNull()
}

private fun ManagementRepository.profileApi(): ManagementProfileSource =
    profile ?: throw IllegalStateException("Profilo non disponibile")

/** L'indice delle sezioni: la scheda intera, ricaricata a ogni ritorno dalle sezioni. */
internal class VenueProfileViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    val venueId: Long = saved.get<Long>(KEY_VENUE) ?: 0L
    private val state = MutableStateFlow<Loadable<VenueProfile>>(Loadable.Loading)
    val profile: StateFlow<Loadable<VenueProfile>> = state.asStateFlow()

    /** La prima volta mostra la barra; le volte dopo aggiorna senza togliere la scheda. */
    fun load() {
        if (state.value !is Loadable.Ready) state.value = Loadable.Loading
        viewModelScope.launch {
            try { state.value = Loadable.Ready(repository.profileApi().venueProfile(venueId)) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { if (state.value !is Loadable.Ready) state.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }

    companion object { const val KEY_VENUE = "venueId" }
}

internal data class VenueSectionUiState(
    val profile: Loadable<VenueProfile> = Loadable.Loading,
    val draft: VenueProfileDraft? = null,
    val options: ManagementOptions = ManagementOptions(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

/** Una sezione della scheda del locale. */
internal class VenueSectionViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueProfileViewModel.KEY_VENUE) ?: 0L
    val section: VenueSection = VenueSection.fromName(saved.get<String>(KEY_SECTION)) ?: VenueSection.Description
    private val state = MutableStateFlow(VenueSectionUiState(draft = restoreDraft()))
    val uiState: StateFlow<VenueSectionUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(profile = if (it.profile is Loadable.Ready) it.profile else Loadable.Loading) }
        viewModelScope.launch {
            val options = repository.options() ?: ManagementOptions()
            try {
                val profile = repository.profileApi().venueProfile(venueId)
                /* Un modulo già cominciato (rotazione, processo riaperto) non si perde. */
                state.update { it.copy(profile = Loadable.Ready(profile), draft = it.draft ?: VenueProfileDraft.from(profile), options = options) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(profile = Loadable.Failed(requestFailureMessage(e)), options = options) } }
        }
    }

    fun edit(change: (VenueProfileDraft) -> VenueProfileDraft) {
        state.update { current -> current.copy(draft = current.draft?.let(change), message = null) }
        persist()
    }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        val district = (state.value.profile as? Loadable.Ready)?.value?.choices?.districtMunicipality ?: "Padova"
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val profile = repository.profileApi().updateVenueProfile(venueId, draft.payload(section, district))
                state.update { it.copy(profile = Loadable.Ready(profile), draft = VenueProfileDraft.from(profile), message = UiText.Res(R.string.profile_saved)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, fieldKeys(draft))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Reinvia la conferma» del recapito del modulo di contatto. */
    fun resendContact() {
        if (state.value.busy) return
        val subject = repository.subjects.value?.venues?.firstOrNull { it.id == venueId }?.toSubject() ?: return
        state.update { it.copy(busy = true, message = null, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.profileApi().resendContactConfirmation(subject)
                state.update { current ->
                    val profile = (current.profile as? Loadable.Ready)?.value?.copy(contact = result.contact)
                    current.copy(profile = profile?.let { Loadable.Ready(it) } ?: current.profile, message = UiText.Raw(result.message))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /*
     * I campi del modulo con i nomi del server, i più precisi prima: l'errore
     * di `opening_hours.2.close` va sotto la terza riga, quello di
     * `opening_hours` (troppe righe) sotto l'elenco.
     */
    private fun fieldKeys(draft: VenueProfileDraft): List<String> {
        fun rows(key: String, count: Int) = (0 until count).map { "$key.$it" }
        val rows = when (section) {
            VenueSection.Hours -> rows("opening_hours", draft.hours.size) + rows("closures", draft.closures.size)
            VenueSection.Contacts -> rows("socials", draft.socials.size)
            VenueSection.BeforeGoing -> rows("content_details.practical_custom", draft.practical.size)
            VenueSection.Editorial -> rows("content_details.faqs", draft.faqs.size)
            else -> emptyList()
        }
        return rows + section.keys
    }

    private fun restoreDraft(): VenueProfileDraft? = saved.get<String>(KEY_DRAFT)?.let { json ->
        runCatching { draftJson.decodeFromString(VenueProfileDraft.serializer(), json) }.getOrNull()
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = draftJson.encodeToString(VenueProfileDraft.serializer(), it) } }

    companion object {
        const val KEY_SECTION = "section"
        const val KEY_DRAFT = "draft"
    }
}

/** Una foto del locale come la mostra l'app: `id` (solo galleria) e l'indirizzo più adatto. */
internal data class VenuePhoto(val id: Long, val url: String)

internal data class VenueImages(val logo: String? = null, val cover: String? = null, val gallery: List<VenuePhoto> = emptyList())

/** Legge `GET V/media` e le risposte delle scritture: la stessa forma dell'oggetto immagine. */
internal fun venueImagesFrom(data: JsonObject, previous: VenueImages = VenueImages()): VenueImages {
    fun url(element: Any?): String? = (element as? JsonObject)?.let { image ->
        listOf("card", "thumb", "full").firstNotNullOfOrNull { (image[it] as? JsonPrimitive)?.contentOrNull?.takeIf(String::isNotBlank) }
    }
    val gallery = (data["gallery"] as? JsonArray)?.mapNotNull { item ->
        val image = item as? JsonObject ?: return@mapNotNull null
        val id = (image["id"] as? JsonPrimitive)?.let { it.longOrNull ?: it.contentOrNull?.toLongOrNull() } ?: return@mapNotNull null
        url(image)?.let { VenuePhoto(id, it) }
    }
    val collection = (data["collection"] as? JsonPrimitive)?.contentOrNull
    return when {
        collection == "logo" -> previous.copy(logo = url(data["image"]))
        collection == "cover" -> previous.copy(cover = url(data["image"]))
        data.containsKey("logo") || data.containsKey("cover") -> VenueImages(url(data["logo"]), url(data["cover"]), gallery.orEmpty())
        gallery != null -> previous.copy(gallery = gallery)
        else -> previous
    }
}

internal data class VenueImagesUiState(
    val images: Loadable<VenueImages> = Loadable.Loading,
    val busy: Boolean = false,
    /* Il tetto della galleria da `GET /management/options` (30 se non risponde). */
    val galleryMax: Int = 30,
    /* L'errore sotto il riquadro che l'ha causato: `logo`, `cover` o `gallery`. */
    val errors: Map<String, String> = emptyMap(),
)

/** Logo, copertina e galleria: gli endpoint `V/media` delle fondamenta. */
internal class VenueImagesViewModel(private val repository: ManagementRepository, saved: SavedStateHandle) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueProfileViewModel.KEY_VENUE) ?: 0L
    private val state = MutableStateFlow(VenueImagesUiState())
    val uiState: StateFlow<VenueImagesUiState> = state.asStateFlow()
    private val media get() = repository.media ?: throw IllegalStateException("Immagini non disponibili")

    init { load() }

    fun load() {
        state.update { it.copy(images = Loadable.Loading, errors = emptyMap()) }
        viewModelScope.launch {
            repository.options()?.limit("gallery_images")?.let { max -> state.update { it.copy(galleryMax = max.toInt()) } }
            try { state.update { it.copy(images = Loadable.Ready(venueImagesFrom(media.venueMedia(venueId)))) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(images = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun upload(slot: VenueImageSlot, image: PreparedImage) = write(slot.segment) { media.uploadVenueImage(venueId, slot, image) }
    fun remove(slot: VenueImageSlot) = write(slot.segment) { media.deleteVenueImage(venueId, slot); JsonObject(mapOf("collection" to JsonPrimitive(slot.segment), "image" to kotlinx.serialization.json.JsonNull)) }
    fun addToGallery(image: PreparedImage) = write(GALLERY) { media.addGalleryImage(venueId, image) }
    fun removeFromGallery(id: Long) = write(GALLERY) { media.deleteGalleryImage(venueId, id); media.venueMedia(venueId) }

    /** Sposta una foto di un posto (−1 prima, +1 dopo) e manda il nuovo ordine intero. */
    fun move(id: Long, delta: Int) {
        val current = (state.value.images as? Loadable.Ready)?.value?.gallery ?: return
        val order = movedOrder(current.map(VenuePhoto::id), id, delta) ?: return
        write(GALLERY) { media.reorderGallery(venueId, order); media.venueMedia(venueId) }
    }

    /** Un errore prima dell'invio (immagine illeggibile, troppo grande), sotto il riquadro giusto. */
    fun showError(key: String, message: String) { state.update { it.copy(errors = it.errors + (key to message)) } }

    private fun write(key: String, call: suspend () -> JsonObject) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = it.errors - key) }
        viewModelScope.launch {
            try {
                val data = call()
                state.update { current ->
                    val previous = (current.images as? Loadable.Ready)?.value ?: VenueImages()
                    current.copy(images = Loadable.Ready(venueImagesFrom(data, previous)))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                val message = FormErrors.from(e, listOf("image", "ids")).let { it.field("image") ?: it.field("ids") ?: it.general ?: requestFailureMessage(e) }
                state.update { it.copy(errors = it.errors + (key to message)) }
            } finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object { const val GALLERY = "gallery" }
}

/** Il nuovo ordine dopo aver spostato `id` di `delta` posti; `null` se non si muove. */
internal fun movedOrder(ids: List<Long>, id: Long, delta: Int): List<Long>? {
    val from = ids.indexOf(id)
    val to = from + delta
    if (from < 0 || to !in ids.indices || delta == 0) return null
    return ids.toMutableList().apply { add(to, removeAt(from)) }
}

internal data class NoticeUiState(
    val profile: Loadable<VenueProfile> = Loadable.Loading,
    val kind: String = "info",
    val body: String = "",
    /* `yyyy-MM-dd`, vuoto = nessuna scadenza. */
    val until: String = "",
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

/** «Avvisa chi ti segue». */
internal class VenueNoticeViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueProfileViewModel.KEY_VENUE) ?: 0L
    private val state = MutableStateFlow(NoticeUiState(kind = saved[KEY_KIND] ?: "info", body = saved[KEY_BODY] ?: "", until = saved[KEY_UNTIL] ?: ""))
    val uiState: StateFlow<NoticeUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try { state.update { it.copy(profile = Loadable.Ready(repository.profileApi().venueProfile(venueId))) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(profile = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun setKind(kind: String) { saved[KEY_KIND] = kind; state.update { it.copy(kind = kind, message = null) } }
    fun setBody(body: String) { saved[KEY_BODY] = body; state.update { it.copy(body = body, message = null) } }
    fun setUntil(until: String) { saved[KEY_UNTIL] = until; state.update { it.copy(until = until, message = null) } }

    fun publish() {
        val current = state.value
        if (current.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val result = repository.profileApi().publishNotice(venueId, current.kind, current.body.trim(), current.until.ifBlank { null })
                saved[KEY_BODY] = ""
                saved[KEY_UNTIL] = ""
                state.update { it.copy(body = "", until = "", message = UiText.Plural(R.plurals.profile_notice_published, result.notified, listOf(result.notified))) }
                load()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, listOf("kind", "body", "until"))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_KIND = "kind"
        const val KEY_BODY = "body"
        const val KEY_UNTIL = "until"
    }
}

internal data class OrganizerProfileUiState(
    val profile: Loadable<OrganizerProfile> = Loadable.Loading,
    val draft: OrganizerProfileDraft? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

/** Il profilo dell'organizzazione: presentazione, sito, email, modulo di contatto. */
internal class OrganizerProfileViewModel(private val repository: ManagementRepository, private val saved: SavedStateHandle) : ViewModel() {
    val organizerId: Long = saved.get<Long>(KEY_ORGANIZER) ?: 0L
    private val state = MutableStateFlow(OrganizerProfileUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { draftJson.decodeFromString(OrganizerProfileDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<OrganizerProfileUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        state.update { it.copy(profile = if (it.profile is Loadable.Ready) it.profile else Loadable.Loading) }
        viewModelScope.launch {
            try {
                val profile = repository.profileApi().organizerProfile(organizerId)
                state.update { it.copy(profile = Loadable.Ready(profile), draft = it.draft ?: OrganizerProfileDraft.from(profile)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(profile = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (OrganizerProfileDraft) -> OrganizerProfileDraft) {
        state.update { it.copy(draft = it.draft?.let(change), message = null) }
        persist()
    }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val profile = repository.profileApi().updateOrganizerProfile(organizerId, draft.payload())
                state.update { it.copy(profile = Loadable.Ready(profile), draft = OrganizerProfileDraft.from(profile), message = UiText.Res(R.string.profile_saved)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, listOf("description", "website", "email", "contact_mode", "contact_email"))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    fun resendContact() {
        if (state.value.busy) return
        val subject = repository.subjects.value?.organizers?.firstOrNull { it.id == organizerId }?.toSubject() ?: return
        state.update { it.copy(busy = true, message = null, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val result = repository.profileApi().resendContactConfirmation(subject)
                state.update { current ->
                    val profile = (current.profile as? Loadable.Ready)?.value?.copy(contact = result.contact)
                    current.copy(profile = profile?.let { Loadable.Ready(it) } ?: current.profile, message = UiText.Raw(result.message))
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors(general = requestFailureMessage(e))) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = draftJson.encodeToString(OrganizerProfileDraft.serializer(), it) } }

    companion object {
        const val KEY_ORGANIZER = "organizerId"
        const val KEY_DRAFT = "draft"
    }
}

/** La riga di stato del modulo di contatto, uguale per locale e organizzazione. */
internal fun contactStatus(contact: ContactSettings): UiText? = when {
    contact.email == null -> null
    contact.confirmed -> UiText.Res(R.string.profile_contact_confirmed, listOf(contact.email))
    else -> UiText.Res(R.string.profile_contact_pending, listOf(contact.email, contact.linkDays))
}
