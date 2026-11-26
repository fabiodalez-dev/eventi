package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenueForm
import it.fabiodalez.incitta.data.editorial.EditorialVenueSection
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.editorial.NewVenueDraft
import it.fabiodalez.incitta.data.editorial.VenueChoices
import it.fabiodalez.incitta.data.editorial.VenueEditorialDraft
import it.fabiodalez.incitta.data.management.VenueProfileDraft
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.profile.SearchableChoiceField
import it.fabiodalez.incitta.ui.management.profile.VenueSectionFields
import it.fabiodalez.incitta.ui.management.profile.sectionStatus
import it.fabiodalez.incitta.ui.management.profile.sectionTitle
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json

/*
 * Il modulo del locale della redazione (R-VN-04, 05, 11, 16): l'indice delle
 * sezioni come le schede del pannello. Le sezioni della scheda pubblica
 * (descrizione, dove, orari, contatti, come arrivare, accessibilità, scheda,
 * prima di andare, informazioni, SEO, modulo di contatto) sono le stesse
 * della gestione (`VenueSectionFields`); quelle che tiene solo la redazione
 * (identità, moderazione, ticketing, visibilità) sono qui. Ogni sezione
 * salva solo le sue chiavi.
 */

private val draftJson = Json { ignoreUnknownKeys = true; encodeDefaults = true }

/** Le sezioni della scheda pubblica nell'ordine delle schede del pannello. */
internal val PROFILE_SECTIONS = listOf(
    VenueSection.Description, VenueSection.Where, VenueSection.Transit, VenueSection.Contacts, VenueSection.ContactForm,
    VenueSection.Card, VenueSection.Accessibility, VenueSection.BeforeGoing, VenueSection.Editorial, VenueSection.Seo, VenueSection.Hours,
)

internal fun editorialSectionTitle(section: EditorialVenueSection): Int = when (section) {
    EditorialVenueSection.Identity -> R.string.editorial_venue_section_identity
    EditorialVenueSection.Moderation -> R.string.editorial_venue_section_moderation
    EditorialVenueSection.Ticketing -> R.string.editorial_venue_section_ticketing
    EditorialVenueSection.Visibility -> R.string.editorial_venue_section_visibility
}

/** L'indice: il modulo intero, riletto a ogni ritorno da una sezione. */
internal class VenueFormViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    private val state = MutableStateFlow<Loadable<EditorialVenueForm>>(Loadable.Loading)
    val form: StateFlow<Loadable<EditorialVenueForm>> = state.asStateFlow()

    fun load() {
        if (state.value !is Loadable.Ready) state.value = Loadable.Loading
        viewModelScope.launch {
            try { state.value = Loadable.Ready(repository.call { source.venueForm(venueId) }) }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { if (state.value !is Loadable.Ready) state.value = Loadable.Failed(requestFailureMessage(e)) }
        }
    }
}

@Composable
internal fun VenueEditScreen(model: VenueFormViewModel, context: EditorialGraphContext) {
    val state by model.form.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { model.load() }
    val nav = context.navController
    val form = (state as? Loadable.Ready)?.value
    ManagementScaffold(title = stringResource(R.string.editorial_venue_edit), padding = context.padding, onBack = context::back, subtitle = form?.editorial?.name) {
        LoadState(state, onRetry = model::load) { loaded ->
            Text(stringResource(R.string.editorial_venue_edit_lead), color = Muted)
            val choices = loaded.choices
            ManagementRow(stringResource(R.string.editorial_venue_section_identity), listOfNotNull(
                loaded.editorial.name, choices.types.firstOrNull { it.value == loaded.editorial.type }?.label,
                choices.cities.firstOrNull { it.value == loaded.editorial.cityId.toString() }?.label,
            ).joinToString(" · "), { nav.navigate(VenueEditorialSectionRoute(model.venueId, EditorialVenueSection.Identity.name)) })
            ManagementRow(stringResource(R.string.editorial_venue_images), stringResource(R.string.editorial_venue_images_help), { nav.navigate(VenueImagesEditorialRoute(model.venueId)) })
            PROFILE_SECTIONS.forEach { section ->
                ManagementRow(stringResource(sectionTitle(section)), sectionStatus(section, loaded.profile).resolve(), { nav.navigate(VenueProfileSectionRoute(model.venueId, section.name)) })
            }
            ManagementRow(stringResource(R.string.editorial_venue_section_visibility), choices.seoIndexing.firstOrNull { it.value == loaded.editorial.seoIndexing }?.label,
                { nav.navigate(VenueEditorialSectionRoute(model.venueId, EditorialVenueSection.Visibility.name)) })
            if (loaded.editorial.canTicketing) {
                ManagementRow(stringResource(R.string.editorial_venue_section_ticketing),
                    stringResource(if (loaded.editorial.ticketingEnabled) R.string.editorial_venue_ticketing_on else R.string.editorial_venue_ticketing_off),
                    { nav.navigate(VenueEditorialSectionRoute(model.venueId, EditorialVenueSection.Ticketing.name)) })
            }
            ManagementRow(stringResource(R.string.editorial_venue_section_moderation), choices.statuses.firstOrNull { it.value == loaded.editorial.status }?.label,
                { nav.navigate(VenueEditorialSectionRoute(model.venueId, EditorialVenueSection.Moderation.name)) })
            if (loaded.profile.widget.code.isNotBlank()) {
                Text(stringResource(R.string.editorial_venue_widget), style = MaterialTheme.typography.titleMedium)
                Text(loaded.profile.widget.code, color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

internal data class EditorialSectionUiState(
    val form: Loadable<EditorialVenueForm> = Loadable.Loading,
    val draft: VenueEditorialDraft? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

/** Una sezione della redazione: identità, moderazione, ticketing, visibilità. */
internal class VenueEditorialSectionViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    val section: EditorialVenueSection = EditorialVenueSection.fromName(saved.get<String>(KEY_SECTION)) ?: EditorialVenueSection.Identity
    private val state = MutableStateFlow(EditorialSectionUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { draftJson.decodeFromString(VenueEditorialDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<EditorialSectionUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val form = repository.call { source.venueForm(venueId) }
                state.update { it.copy(form = Loadable.Ready(form), draft = it.draft ?: VenueEditorialDraft.from(form.editorial)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(form = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (VenueEditorialDraft) -> VenueEditorialDraft) {
        state.update { it.copy(draft = it.draft?.let(change), message = null) }
        persist()
    }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val form = repository.call { source.updateVenue(venueId, draft.payload(section)) }
                state.update { it.copy(form = Loadable.Ready(form), draft = VenueEditorialDraft.from(form.editorial), message = UiText.Res(R.string.editorial_venues_saved)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, section.keys)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = draftJson.encodeToString(VenueEditorialDraft.serializer(), it) } }

    companion object {
        const val KEY_SECTION = "section"
        const val KEY_DRAFT = "draft"
    }
}

@Composable
internal fun VenueEditorialSectionScreen(model: VenueEditorialSectionViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(title = stringResource(editorialSectionTitle(model.section)), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.form, onRetry = model::load) { form ->
            val draft = state.draft ?: return@LoadState
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.editorial_venues_save), onSubmit = model::save) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                EditorialSectionFields(model.section, draft, form.choices, state.errors, model::edit)
            }
        }
    }
}

/** I campi di una sezione della redazione, con le etichette del pannello. */
@Composable
internal fun EditorialSectionFields(
    section: EditorialVenueSection,
    draft: VenueEditorialDraft,
    choices: VenueChoices,
    errors: FormErrors,
    onEdit: ((VenueEditorialDraft) -> VenueEditorialDraft) -> Unit,
) {
    when (section) {
        EditorialVenueSection.Identity -> {
            FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_venue_name), errors, "name", singleLine = true)
            FormTextField(draft.slug, { v -> onEdit { it.copy(slug = v) } }, stringResource(R.string.editorial_venue_slug), errors, "slug", singleLine = true,
                help = stringResource(R.string.editorial_venue_slug_help))
            ChoiceField(stringResource(R.string.editorial_venue_city), choices.cities, draft.cityId, { v -> onEdit { it.copy(cityId = v) } }, errors.field("city_id"))
            Text(stringResource(R.string.editorial_venue_city_help), color = Muted, style = MaterialTheme.typography.bodySmall)
            ChoiceField(stringResource(R.string.editorial_venue_type), choices.types, draft.type, { v -> onEdit { it.copy(type = v) } }, errors.field("type"))
            FormTextField(draft.provinceCode, { v -> onEdit { it.copy(provinceCode = v.take(4)) } }, stringResource(R.string.editorial_venue_province_code), errors, "province_code", singleLine = true)
        }
        EditorialVenueSection.Moderation -> {
            /* Lo stato si legge e basta: lo cambiano le azioni di moderazione della scheda, come nel pannello. */
            Text(stringResource(R.string.editorial_venue_status), style = MaterialTheme.typography.labelLarge)
            Text(choices.statuses.firstOrNull { it.value == draft.status }?.label ?: draft.status)
            Text(stringResource(R.string.editorial_venue_status_actions), color = Muted, style = MaterialTheme.typography.bodySmall)
            SwitchRow(stringResource(R.string.editorial_venue_verified), stringResource(R.string.editorial_venue_verified_help), draft.isVerified) { v -> onEdit { it.copy(isVerified = v) } }
            SwitchRow(stringResource(R.string.editorial_venue_auto_publish), stringResource(R.string.editorial_venue_auto_publish_help), draft.autoPublish) { v -> onEdit { it.copy(autoPublish = v) } }
            SwitchRow(stringResource(R.string.editorial_venue_nonprofit), null, draft.isNonprofit) { v -> onEdit { it.copy(isNonprofit = v) } }
            ChoiceField(stringResource(R.string.editorial_venue_plan), choices.plans, draft.plan, { v -> onEdit { it.copy(plan = v) } }, errors.field("plan"))
            FormTextField(draft.rejectionReason, { v -> onEdit { it.copy(rejectionReason = v) } }, stringResource(R.string.editorial_venue_rejection_reason), errors, "rejection_reason", minLines = 2)
        }
        EditorialVenueSection.Ticketing -> {
            Text(stringResource(R.string.editorial_venue_ticketing_lead), color = Muted)
            SwitchRow(stringResource(R.string.editorial_venue_ticketing), stringResource(R.string.editorial_venue_ticketing_help), draft.ticketingEnabled) { v -> onEdit { it.copy(ticketingEnabled = v) } }
        }
        EditorialVenueSection.Visibility -> {
            ChoiceField(stringResource(R.string.editorial_venue_indexing), choices.seoIndexing, draft.seoIndexing, { v -> onEdit { it.copy(seoIndexing = v) } }, errors.field("seo_indexing"))
            SwitchRow(stringResource(R.string.editorial_venue_demo), stringResource(R.string.editorial_venue_demo_help), draft.isDemo) { v -> onEdit { it.copy(isDemo = v) } }
        }
    }
}

@Composable
internal fun SwitchRow(label: String, help: String?, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyLarge)
            help?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
        Switch(checked = checked, onCheckedChange = onChange)
    }
}

internal data class ProfileSectionUiState(
    val form: Loadable<EditorialVenueForm> = Loadable.Loading,
    val draft: VenueProfileDraft? = null,
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val message: UiText? = null,
)

/**
 * Una sezione della scheda pubblica, con i campi e la regola della gestione
 * (`VenueProfileDraft`), salvata su `PATCH E/venues/{id}`. Nella sezione
 * «Dove» la redazione vuole anche le coordinate, come il pannello.
 */
internal class VenueProfileSectionViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(VenueDetailViewModel.KEY_VENUE) ?: 0L
    val section: VenueSection = VenueSection.fromName(saved.get<String>(VenueEditorialSectionViewModel.KEY_SECTION)) ?: VenueSection.Description
    private val state = MutableStateFlow(ProfileSectionUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { draftJson.decodeFromString(VenueProfileDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<ProfileSectionUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val form = repository.call { source.venueForm(venueId) }
                state.update { it.copy(form = Loadable.Ready(form), draft = it.draft ?: VenueProfileDraft.from(form.profile)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(form = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (VenueProfileDraft) -> VenueProfileDraft) {
        state.update { it.copy(draft = it.draft?.let(change), message = null) }
        persist()
    }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        val district = (state.value.form as? Loadable.Ready)?.value?.profile?.choices?.districtMunicipality ?: "Padova"
        state.update { it.copy(busy = true, errors = FormErrors.None, message = null) }
        viewModelScope.launch {
            try {
                val form = repository.call { source.updateVenue(venueId, draft.payload(section, district)) }
                state.update { it.copy(form = Loadable.Ready(form), draft = VenueProfileDraft.from(form.profile), message = UiText.Res(R.string.editorial_venues_saved)) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, section.keys.map { key -> key.substringBefore('.') }.distinct() + section.keys)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = draftJson.encodeToString(VenueProfileDraft.serializer(), it) } }

    companion object { const val KEY_DRAFT = "draft" }
}

@Composable
internal fun VenueProfileSectionScreen(model: VenueProfileSectionViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(title = stringResource(sectionTitle(model.section)), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.form, onRetry = model::load) { form ->
            val draft = state.draft ?: return@LoadState
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.editorial_venues_save), onSubmit = model::save) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                if (model.section == VenueSection.ContactForm) Text(stringResource(R.string.editorial_venue_contact_resend_web), color = Muted, style = MaterialTheme.typography.bodySmall)
                /* «Reinvia la conferma» è un'azione del referente dal suo pannello: qui non c'è. */
                VenueSectionFields(model.section, form.profile, draft, form.options, state.errors, state.busy, model::edit, onResendContact = {})
            }
        }
    }
}

internal data class VenueCreateUiState(
    val choices: Loadable<VenueChoices> = Loadable.Loading,
    val draft: NewVenueDraft = NewVenueDraft(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val createdId: Long? = null,
    /* «Trova» ha messo il punto: lo si dice, come la notifica del pannello. */
    val located: Boolean = false,
)

/** «Nuovo locale»: i campi obbligatori, poi la modifica sezione per sezione. */
internal class VenueCreateViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val state = MutableStateFlow(VenueCreateUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { draftJson.decodeFromString(NewVenueDraft.serializer(), it) }.getOrNull() } ?: NewVenueDraft()))
    val uiState: StateFlow<VenueCreateUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val choices = repository.call { source.venueChoices() }
                state.update { current ->
                    val draft = current.draft
                    /* Si parte dalla città di lavoro e dal tipo «altro», come i valori predefiniti del modulo. */
                    val city = draft.cityId.ifBlank { repository.current.value?.city?.id?.toString().orEmpty() }
                    current.copy(choices = Loadable.Ready(choices), draft = draft.copy(cityId = city, type = draft.type.ifBlank { "altro" }))
                }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(choices = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (NewVenueDraft) -> NewVenueDraft) {
        state.update { it.copy(draft = change(it.draft)) }
        persist()
    }

    fun create() {
        if (state.value.busy) return
        val choices = (state.value.choices as? Loadable.Ready)?.value ?: return
        val draft = state.value.draft
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                val form = repository.call { source.createVenue(draft.payload(choices.districtMunicipality)) }
                saved.remove<String>(KEY_DRAFT)
                state.update { it.copy(createdId = form.profile.id) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, NEW_VENUE_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Trova»: dall'indirizzo e dal comune al punto, con il servizio del pannello. */
    fun locate() {
        if (state.value.busy) return
        val draft = state.value.draft
        state.update { it.copy(busy = true, errors = FormErrors.None, located = false) }
        viewModelScope.launch {
            try {
                val point = repository.call { source.locate(draft.address, draft.municipality) }
                edit { it.copy(lat = point.lat.toString(), lng = point.lng.toString()) }
                state.update { it.copy(located = true) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, NEW_VENUE_FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** «Centra sulla città»: il centro della città scelta, da spostare poi sul punto giusto. */
    fun centerOnCity() {
        val choices = (state.value.choices as? Loadable.Ready)?.value ?: return
        val center = choices.center(state.value.draft.cityId.toLongOrNull() ?: return) ?: return
        edit { it.copy(lat = center.first, lng = center.second) }
    }

    private fun persist() { saved[KEY_DRAFT] = draftJson.encodeToString(NewVenueDraft.serializer(), state.value.draft) }

    companion object {
        const val KEY_DRAFT = "draft"
        val NEW_VENUE_FIELDS = listOf("name", "city_id", "type", "address", "municipality", "zone", "province_code", "lat", "lng")
    }
}

@Composable
internal fun VenueCreateScreen(model: VenueCreateViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    LaunchedEffect(state.createdId) {
        state.createdId?.let { id -> context.navController.navigate(VenueEditRoute(id)) { popUpTo(VenueCreateRoute) { inclusive = true } } }
    }
    ManagementScaffold(title = stringResource(R.string.editorial_venue_create_title), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.choices, onRetry = model::load) { choices ->
            Text(stringResource(R.string.editorial_venue_create_lead), color = Muted)
            NewVenueFields(state.draft, choices, state.errors, state.busy, model::edit, model::create, onLocate = model::locate, onCenter = model::centerOnCity, located = state.located)
        }
    }
}

/** I campi di «Nuovo locale», senza ViewModel: li provano i test strumentati. */
@Composable
internal fun NewVenueFields(
    draft: NewVenueDraft,
    choices: VenueChoices,
    errors: FormErrors,
    busy: Boolean,
    onEdit: ((NewVenueDraft) -> NewVenueDraft) -> Unit,
    onCreate: () -> Unit,
    onLocate: () -> Unit = {},
    onCenter: () -> Unit = {},
    located: Boolean = false,
) {
    val decimal = KeyboardOptions(keyboardType = KeyboardType.Decimal)
    FormScaffold(errors = errors, busy = busy, submitLabel = stringResource(R.string.editorial_venue_create), onSubmit = onCreate) {
        FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_venue_name), errors, "name", singleLine = true)
        ChoiceField(stringResource(R.string.editorial_venue_city), choices.cities, draft.cityId, { v -> onEdit { it.copy(cityId = v, municipality = "", zone = "") } }, errors.field("city_id"))
        ChoiceField(stringResource(R.string.editorial_venue_type), choices.types, draft.type, { v -> onEdit { it.copy(type = v) } }, errors.field("type"))
        FormTextField(draft.address, { v -> onEdit { it.copy(address = v) } }, stringResource(R.string.editorial_venue_address), errors, "address", singleLine = true)
        val municipalities = choices.municipalities(draft.cityId.toLongOrNull() ?: 0L).map { it.value }
        SearchableChoiceField(stringResource(R.string.editorial_venue_municipality), municipalities, draft.municipality, { v -> onEdit { it.copy(municipality = v, zone = "") } }, errors.field("municipality"))
        if (draft.needsDistrict(choices.districtMunicipality)) {
            SearchableChoiceField(stringResource(R.string.editorial_venue_district), choices.districts.map { it.value }, draft.zone, { v -> onEdit { it.copy(zone = v) } }, errors.field("zone"))
        }
        FormTextField(draft.provinceCode, { v -> onEdit { it.copy(provinceCode = v.take(4)) } }, stringResource(R.string.editorial_venue_province_code), errors, "province_code", singleLine = true)
        Text(stringResource(R.string.editorial_venue_coordinates_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FormTextField(draft.lat, { v -> onEdit { it.copy(lat = v) } }, stringResource(R.string.editorial_venue_lat), errors, "lat", modifier = Modifier.weight(1f), singleLine = true, keyboardOptions = decimal)
            FormTextField(draft.lng, { v -> onEdit { it.copy(lng = v) } }, stringResource(R.string.editorial_venue_lng), errors, "lng", modifier = Modifier.weight(1f), singleLine = true, keyboardOptions = decimal)
        }
        FlowRowActions {
            OutlinedButton(onClick = onLocate, enabled = !busy && draft.address.isNotBlank(), modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_locate)) }
            OutlinedButton(onClick = onCenter, enabled = !busy && draft.cityId.isNotBlank(), modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_center_city)) }
        }
        if (located) Text(stringResource(R.string.editorial_venue_located), color = MaterialTheme.colorScheme.primary)
    }
}
