package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.PromoEvent
import it.fabiodalez.incitta.data.editorial.SponsorChoice
import it.fabiodalez.incitta.data.editorial.WizardCreated
import it.fabiodalez.incitta.data.editorial.WizardPreview
import it.fabiodalez.incitta.data.editorial.euroOrNull
import it.fabiodalez.incitta.data.editorial.numberOrNull
import it.fabiodalez.incitta.data.editorial.options
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipEventLine
import it.fabiodalez.incitta.ui.management.sponsorships.sponsorshipColor
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/*
 * «Nuova sponsorizzazione» (R-SP-03): i quattro passi del pannello,
 * committente, cosa, dove e quanto, pagamento. Ogni passo si controlla sul
 * server quando si va avanti (`POST E/sponsorships/estimate` con `step`),
 * con i messaggi del pannello; mentre si compila il server dice il fuso, la
 * fine dopo N mesi, il prezzo del listino, la stima dello spazio e chi lo
 * occupa già. «Un evento» salva una campagna, «tutti gli eventi»
 * un'abilitazione automatica.
 */

/** Lo stato del percorso, come lo scrive la persona. */
@Serializable
internal data class WizardDraft(
    val sponsorType: String = "venue",
    val venueId: Long? = null,
    val organizerId: Long? = null,
    val sponsorLabel: String = "",
    val venueFilter: Long? = null,
    val venueFilterLabel: String = "",
    val advertiserName: String = "",
    val advertiserEmail: String = "",
    val advertiserUrl: String = "",
    val scope: String = "event",
    val eventId: Long? = null,
    val eventTitle: String = "",
    val placement: String = "",
    val startsAt: String? = null,
    val endsAt: String? = null,
    val months: String = "1",
    val product: String = "period",
    val impressionsGoal: String = "",
    val pacing: String = "even",
    val complimentary: Boolean = false,
    val amount: String = "",
    val invoiceReference: String = "",
    val paidAt: String? = null,
    val paymentMethod: String = "",
    val paymentReference: String = "",
    val notes: String = "",
    val status: String = "active",
    val priority: String = "0",
    val weight: String = "1",
) {
    val external: Boolean get() = sponsorType == "external"
    /** «Un evento» è l'unica scelta di un committente esterno. */
    val effectiveScope: String get() = if (external) "event" else scope

    /** Il corpo per la stima e per il salvataggio; `step` controlla i passi fino a quello. */
    fun body(step: Int? = null): JsonObject = buildJsonObject {
        step?.let { put("step", it) }
        put("sponsor_type", sponsorType)
        venueId?.let { put("venue_id", it) }
        organizerId?.let { put("organizer_id", it) }
        venueFilter?.let { put("venue_filter", it) }
        put("advertiser_name", advertiserName.trim())
        put("advertiser_email", advertiserEmail.trim().ifEmpty { null }?.let { JsonPrimitive(it) } ?: JsonNull)
        put("advertiser_url", advertiserUrl.trim().ifEmpty { null }?.let { JsonPrimitive(it) } ?: JsonNull)
        put("scope", effectiveScope)
        if (effectiveScope == "event") eventId?.let { put("event_id", it) }
        if (placement.isNotBlank()) put("placement", placement)
        startsAt?.let { put("starts_at", it) }
        endsAt?.let { put("ends_at", it) }
        months.toIntOrNull()?.let { put("months", it) }
        put("product", product)
        if (product == "views") {
            put("impressions_goal", numberOrNull(impressionsGoal))
            put("pacing", pacing)
        }
        put("complimentary", complimentary)
        if (!complimentary) {
            put("amount", euroOrNull(amount))
            if (effectiveScope == "event") invoiceReference.trim().ifEmpty { null }?.let { put("invoice_reference", it) }
            if (effectiveScope == "all") {
                paidAt?.let { put("paid_at", it) }
                paymentMethod.trim().ifEmpty { null }?.let { put("payment_method", it) }
                paymentReference.trim().ifEmpty { null }?.let { put("payment_reference", it) }
            }
        }
        put("notes", notes.takeIf { it.isNotBlank() }?.let { JsonPrimitive(it) } ?: JsonNull)
        put("status", status)
        put("priority", numberOrNull(priority))
        put("weight", numberOrNull(weight))
    }

    companion object {
        val FIELDS = listOf("sponsor_type", "venue_id", "organizer_id", "advertiser_name", "advertiser_email", "advertiser_url", "scope", "event_id",
            "placement", "starts_at", "ends_at", "months", "product", "impressions_goal", "pacing", "complimentary", "amount", "invoice_reference",
            "paid_at", "payment_method", "payment_reference", "notes", "status", "priority", "weight")
    }
}

/** Quale scelta è aperta: il committente, il locale che filtra gli eventi di un esterno, l'evento. */
internal enum class WizardPicker { Sponsor, VenueFilter, Event }

internal data class WizardUiState(
    val start: Loadable<PromoChoices> = Loadable.Loading,
    val step: Int = 1,
    val draft: WizardDraft = WizardDraft(),
    val preview: WizardPreview = WizardPreview(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val picker: WizardPicker? = null,
    val sponsors: List<SponsorChoice> = emptyList(),
    val events: List<PromoEvent> = emptyList(),
    val pickerBusy: Boolean = false,
    val created: WizardCreated? = null,
)

internal class CampaignWizardViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    private val eventId: Long = saved.get<Long>(KEY_EVENT) ?: 0L
    private val state = MutableStateFlow(WizardUiState(
        step = saved.get<Int>(KEY_STEP) ?: 1,
        draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { promoJson.decodeFromString(WizardDraft.serializer(), it) }.getOrNull() } ?: WizardDraft(),
    ))
    val uiState: StateFlow<WizardUiState> = state.asStateFlow()
    private var previewJob: Job? = null
    private var pickerJob: Job? = null

    init { start() }

    fun start() {
        state.update { it.copy(start = Loadable.Loading) }
        viewModelScope.launch {
            try {
                val prefill = repository.call { source.prefill(eventId) }
                val restored = saved.get<String>(KEY_DRAFT) != null
                if (!restored) {
                    state.update {
                        it.copy(draft = WizardDraft(
                            sponsorType = prefill.sponsorType, venueId = prefill.venueId, organizerId = prefill.organizerId, sponsorLabel = prefill.sponsorLabel.orEmpty(),
                            advertiserName = prefill.advertiserName.orEmpty(), advertiserEmail = prefill.advertiserEmail.orEmpty(), advertiserUrl = prefill.advertiserUrl.orEmpty(),
                            scope = prefill.scope, eventId = prefill.eventId, eventTitle = prefill.event?.title.orEmpty(),
                            startsAt = prefill.startsAt, endsAt = prefill.endsAt, months = prefill.months?.toString().orEmpty(),
                        ))
                    }
                    persist()
                }
                state.update { it.copy(start = Loadable.Ready(prefill.choices)) }
                refreshPreview()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(start = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (WizardDraft) -> WizardDraft) {
        state.update { it.copy(draft = change(it.draft)) }
        persist()
    }

    /** Cambia il tipo di committente: si ricomincia la scelta, come nel pannello. */
    fun chooseSponsorType(type: String) = editAndPreview {
        val cleared = it.copy(sponsorType = type, venueId = null, organizerId = null, sponsorLabel = "", venueFilter = null, venueFilterLabel = "", eventId = null, eventTitle = "")
        if (type == "external") cleared.copy(advertiserName = "", advertiserEmail = "", advertiserUrl = "") else cleared
    }

    /** Un committente scelto compila nome e contatti di chi paga, che restano modificabili. */
    fun chooseSponsor(sponsor: SponsorChoice) {
        when (state.value.picker) {
            WizardPicker.VenueFilter -> edit { it.copy(venueFilter = sponsor.id, venueFilterLabel = sponsor.label, eventId = null, eventTitle = "") }
            else -> editAndPreview {
                it.copy(
                    venueId = if (it.sponsorType == "venue") sponsor.id else null,
                    organizerId = if (it.sponsorType == "organizer") sponsor.id else null,
                    sponsorLabel = sponsor.label, eventId = null, eventTitle = "",
                    advertiserName = sponsor.name, advertiserEmail = sponsor.email.orEmpty(), advertiserUrl = sponsor.website.orEmpty(),
                )
            }
        }
        closePicker()
    }

    fun clearVenueFilter() = edit { it.copy(venueFilter = null, venueFilterLabel = "", eventId = null, eventTitle = "") }

    fun chooseEvent(event: PromoEvent) {
        editAndPreview { it.copy(eventId = event.id, eventTitle = event.title) }
        closePicker()
    }

    fun chooseScope(scope: String) = editAndPreview { it.copy(scope = scope) }

    /** La collocazione: una solo a periodo spegne «a visualizzazioni»; il listino propone l'importo. */
    fun choosePlacement(value: String) = editAndPreview(proposeAmount = true) { it.copy(placement = value) }

    fun chooseMonths(value: String) = editAndPreview(proposeAmount = true) { it.copy(months = value) }

    fun chooseStart(iso: String?) = editAndPreview { it.copy(startsAt = iso) }

    /** Una fine scritta a mano toglie la durata in mesi. */
    fun chooseEnd(iso: String?) = editAndPreview { it.copy(endsAt = iso, months = "") }

    fun chooseProduct(value: String) = editAndPreview { it.copy(product = value) }

    fun editGoal(value: String) = editAndPreview { it.copy(impressionsGoal = value) }

    private fun editAndPreview(proposeAmount: Boolean = false, change: (WizardDraft) -> WizardDraft) {
        edit(change)
        refreshPreview(proposeAmount)
    }

    /** Chiede al server quello che il pannello calcola mentre si compila; una sola richiesta alla volta. */
    fun refreshPreview(proposeAmount: Boolean = false) {
        previewJob?.cancel()
        val body = state.value.draft.body()
        previewJob = viewModelScope.launch {
            delay(PREVIEW_PAUSE)
            try {
                val preview = repository.call { source.estimate(body) }
                state.update { now ->
                    var draft = now.draft
                    preview.endsAt?.let { draft = draft.copy(endsAt = it) }
                    if (preview.periodOnly != null && draft.product == "views") draft = draft.copy(product = "period")
                    if (proposeAmount) preview.price?.let { price -> draft = draft.copy(amount = price.amount) }
                    now.copy(preview = preview, draft = draft)
                }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { /* La stima è un aiuto: se non arriva, il passo si controlla comunque andando avanti. */ }
        }
    }

    /** Va avanti solo se il server accetta i campi fino a questo passo; dall'ultimo salva. */
    fun next() {
        val now = state.value
        if (now.busy) return
        state.update { it.copy(busy = true, errors = FormErrors.None) }
        viewModelScope.launch {
            try {
                if (now.step < STEPS) {
                    val preview = repository.call { source.estimate(now.draft.body(now.step)) }
                    state.update { it.copy(step = it.step + 1, preview = preview) }
                    saved[KEY_STEP] = state.value.step
                } else {
                    val created = repository.call { source.createCampaign(now.draft.body()) }
                    saved.remove<String>(KEY_DRAFT)
                    state.update { it.copy(created = created) }
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, WizardDraft.FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    /** Indietro di un passo; falso dal primo (si esce). */
    fun previous(): Boolean {
        if (state.value.step <= 1) return false
        state.update { it.copy(step = it.step - 1, errors = FormErrors.None) }
        saved[KEY_STEP] = state.value.step
        return true
    }

    fun openPicker(picker: WizardPicker) {
        state.update { it.copy(picker = picker, sponsors = emptyList(), events = emptyList()) }
        search("")
    }

    fun closePicker() { pickerJob?.cancel(); state.update { it.copy(picker = null, pickerBusy = false) } }

    fun search(q: String) {
        val picker = state.value.picker ?: return
        val draft = state.value.draft
        pickerJob?.cancel()
        state.update { it.copy(pickerBusy = true) }
        pickerJob = viewModelScope.launch {
            delay(SEARCH_PAUSE)
            try {
                when (picker) {
                    WizardPicker.Sponsor -> {
                        val found = repository.call { source.sponsors(draft.sponsorType, q) }
                        state.update { it.copy(sponsors = found, pickerBusy = false) }
                    }
                    WizardPicker.VenueFilter -> {
                        val found = repository.call { source.sponsors("venue", q, all = true) }
                        state.update { it.copy(sponsors = found, pickerBusy = false) }
                    }
                    WizardPicker.Event -> {
                        val params = buildList {
                            add("sponsor_type" to draft.sponsorType)
                            draft.venueId?.let { add("venue_id" to it.toString()) }
                            draft.organizerId?.let { add("organizer_id" to it.toString()) }
                            draft.venueFilter?.let { add("venue_filter" to it.toString()) }
                            q.trim().takeIf { it.isNotEmpty() }?.let { add("q" to it) }
                        }
                        val found = repository.call { source.events(params) }.data
                        state.update { it.copy(events = found, pickerBusy = false) }
                    }
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(pickerBusy = false, errors = FormErrors(general = requestFailureMessage(e))) } }
        }
    }

    fun consumeCreated() = state.update { it.copy(created = null) }

    private fun persist() { saved[KEY_DRAFT] = promoJson.encodeToString(WizardDraft.serializer(), state.value.draft) }

    companion object {
        const val KEY_EVENT = "event"
        const val KEY_STEP = "step"
        const val KEY_DRAFT = "wizardDraft"
        const val STEPS = 4
        private const val PREVIEW_PAUSE = 300L
        private const val SEARCH_PAUSE = 300L
    }
}

@Composable
internal fun CampaignWizardScreen(model: CampaignWizardViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    BackHandler { if (!model.previous()) context.back() }
    LaunchedEffect(state.created) {
        state.created?.let { created ->
            model.consumeCreated()
            context.navController.popBackStack()
            if (created.kind == "grant") context.navController.navigate(GrantFormRoute(created.id)) else context.navController.navigate(CampaignDetailRoute(created.id))
        }
    }
    ManagementScaffold(
        title = stringResource(R.string.editorial_promo_wizard_title),
        padding = context.padding,
        onBack = { if (!model.previous()) context.back() },
        subtitle = stringResource(R.string.editorial_promo_wizard_step, state.step, CampaignWizardViewModel.STEPS),
        busy = state.busy,
    ) {
        LoadState(state.start, onRetry = model::start) { choices ->
            WizardStepContent(state, choices, model)
        }
    }
    when (state.picker) {
        WizardPicker.Event -> PromoEventPickerDialog(state.events, state.pickerBusy, model::search, model::chooseEvent, model::closePicker)
        WizardPicker.Sponsor, WizardPicker.VenueFilter -> SponsorPickerDialog(state.sponsors, state.pickerBusy, model::search, model::chooseSponsor, model::closePicker)
        null -> Unit
    }
}

@Composable
private fun WizardStepContent(state: WizardUiState, choices: PromoChoices, model: CampaignWizardViewModel) {
    val draft = state.draft
    val errors = state.errors
    val zone = promoZone(state.preview.timezone)
    val general = errors.general ?: if (errors.fields.isNotEmpty()) stringResource(R.string.editorial_promo_fix_fields) else null
    general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    when (state.step) {
        1 -> {
            Text(stringResource(R.string.editorial_promo_step_sponsor), style = MaterialTheme.typography.titleLarge)
            PromoRadioGroup(stringResource(R.string.editorial_promo_field_sponsor_type), choices.sponsorTypes, draft.sponsorType, model::chooseSponsorType, errors.field("sponsor_type"))
            if (!draft.external) {
                Text(draft.sponsorLabel.ifBlank { stringResource(R.string.editorial_promo_sponsor_none) }, style = MaterialTheme.typography.titleMedium)
                (errors.field("venue_id") ?: errors.field("organizer_id"))?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedButton(onClick = { model.openPicker(WizardPicker.Sponsor) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (draft.sponsorType == "organizer") R.string.editorial_promo_choose_organizer else R.string.editorial_promo_choose_venue))
                }
            }
            Text(stringResource(R.string.editorial_promo_advertiser_title), style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.editorial_promo_advertiser_lead), color = Muted, style = MaterialTheme.typography.bodySmall)
            FormTextField(draft.advertiserName, { v -> model.edit { it.copy(advertiserName = v) } }, stringResource(R.string.editorial_promo_field_advertiser), errors, "advertiser_name",
                singleLine = true, help = stringResource(R.string.editorial_promo_advertiser_help))
            FormTextField(draft.advertiserEmail, { v -> model.edit { it.copy(advertiserEmail = v) } }, stringResource(R.string.editorial_promo_field_advertiser_email), errors, "advertiser_email",
                singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
            FormTextField(draft.advertiserUrl, { v -> model.edit { it.copy(advertiserUrl = v) } }, stringResource(R.string.editorial_promo_field_advertiser_url), errors, "advertiser_url",
                singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
        }
        2 -> {
            Text(stringResource(R.string.editorial_promo_step_what), style = MaterialTheme.typography.titleLarge)
            if (!draft.external) PromoRadioGroup(stringResource(R.string.editorial_promo_field_scope), choices.scopes, draft.scope, model::chooseScope, errors.field("scope"))
            if (draft.external) {
                Text(stringResource(R.string.editorial_promo_venue_filter), style = MaterialTheme.typography.titleMedium)
                Text(draft.venueFilterLabel.ifBlank { stringResource(R.string.editorial_promo_venue_filter_none) }, color = Muted)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { model.openPicker(WizardPicker.VenueFilter) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_choose_venue)) }
                    if (draft.venueFilter != null) TextButton(onClick = model::clearVenueFilter, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_venue_filter_clear)) }
                }
            }
            if (draft.effectiveScope == "event") {
                Text(stringResource(R.string.editorial_promo_field_event), style = MaterialTheme.typography.titleMedium)
                Text(stringResource(if (draft.external) R.string.editorial_promo_event_external_help else R.string.editorial_promo_event_sponsor_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                Text(draft.eventTitle.ifBlank { stringResource(R.string.editorial_promo_event_none_chosen) })
                errors.field("event_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedButton(onClick = { model.openPicker(WizardPicker.Event) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_event_pick)) }
            } else {
                state.preview.allEvents?.let { all ->
                    Text(pluralStringResourceSafe(all.count), style = MaterialTheme.typography.titleMedium)
                    all.events.forEach { event -> SponsorshipEventLine(event.asSponsorshipEvent(), promoZone(event.timezone)) }
                }
            }
        }
        3 -> {
            Text(stringResource(R.string.editorial_promo_step_where), style = MaterialTheme.typography.titleLarge)
            PromoRadioGroup(stringResource(R.string.editorial_promo_field_placement), choices.placements, draft.placement, model::choosePlacement, errors.field("placement"))
            state.preview.placementLead?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            Text(stringResource(R.string.editorial_promo_period_title), style = MaterialTheme.typography.titleMedium)
            state.preview.periodLead?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            CityDateTimeField(stringResource(R.string.editorial_promo_field_starts), cityLocalDateTime(draft.startsAt, zone), zone, null, { v -> model.chooseStart(v?.let { cityIso(it, zone) }) }, errors.field("starts_at"))
            ChoiceField(stringResource(R.string.editorial_promo_field_months), choices.months.options(), draft.months, model::chooseMonths, errors.field("months"),
                emptyLabel = stringResource(R.string.editorial_promo_months_custom))
            CityDateTimeField(stringResource(R.string.editorial_promo_field_ends), cityLocalDateTime(draft.endsAt, zone), zone, null, { v -> model.chooseEnd(v?.let { cityIso(it, zone) }) }, errors.field("ends_at"))
            PromoRadioGroup(stringResource(R.string.editorial_promo_field_product), choices.products, draft.product, model::chooseProduct,
                errors.field("product") ?: state.preview.periodOnly, disabled = if (state.preview.periodOnly != null) setOf("views") else emptySet())
            if (draft.product == "views") {
                NumberField(draft.impressionsGoal, model::editGoal, stringResource(R.string.editorial_promo_field_goal_bought), errors, "impressions_goal", help = stringResource(R.string.editorial_promo_goal_wizard_help))
                PromoRadioGroup(stringResource(R.string.editorial_promo_field_pacing), choices.pacings, draft.pacing, { v -> model.edit { it.copy(pacing = v) } }, errors.field("pacing"))
            }
            state.preview.estimate?.let { estimate ->
                Surface(color = sponsorshipColor(estimateColor(estimate.status)).copy(alpha = 0.12f), shape = MaterialTheme.shapes.medium) {
                    Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(estimate.heading, style = MaterialTheme.typography.titleMedium)
                        Text(estimate.description)
                    }
                }
            }
            if (state.preview.occupants.isNotEmpty()) {
                Text(stringResource(R.string.editorial_promo_occupants_title), style = MaterialTheme.typography.titleMedium)
                state.preview.occupants.forEach { unit ->
                    Text("${unit.name} · ${promoPeriod(unit.startsAt, unit.endsAt, zone)}" + (unit.goal?.let { " · " + stringResource(R.string.editorial_promo_occupant_goal, it) } ?: ""),
                        style = MaterialTheme.typography.bodySmall)
                }
            }
        }
        else -> {
            Text(stringResource(R.string.editorial_promo_step_payment), style = MaterialTheme.typography.titleLarge)
            Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.editorial_promo_field_complimentary))
                    Text(stringResource(R.string.editorial_promo_complimentary_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
                Switch(checked = draft.complimentary, onCheckedChange = { v -> model.edit { it.copy(complimentary = v) } })
            }
            if (!draft.complimentary) {
                FormTextField(draft.amount, { v -> model.edit { it.copy(amount = v) } }, stringResource(R.string.editorial_promo_field_amount), errors, "amount", singleLine = true,
                    help = state.preview.priceHint, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                if (draft.effectiveScope == "event") {
                    FormTextField(draft.invoiceReference, { v -> model.edit { it.copy(invoiceReference = v) } }, stringResource(R.string.editorial_promo_field_invoice), errors, "invoice_reference", singleLine = true)
                } else {
                    CityDateTimeField(stringResource(R.string.editorial_promo_field_paid_at), cityLocalDateTime(draft.paidAt, zone), zone, null, { v -> model.edit { it.copy(paidAt = v?.let { local -> cityIso(local, zone) }) } },
                        errors.field("paid_at"), clearable = true)
                    FormTextField(draft.paymentMethod, { v -> model.edit { it.copy(paymentMethod = v) } }, stringResource(R.string.editorial_promo_field_method), errors, "payment_method", singleLine = true)
                    FormTextField(draft.paymentReference, { v -> model.edit { it.copy(paymentReference = v) } }, stringResource(R.string.editorial_promo_field_reference), errors, "payment_reference", singleLine = true)
                }
            }
            FormTextField(draft.notes, { v -> model.edit { it.copy(notes = v) } },
                stringResource(if (draft.complimentary) R.string.editorial_promo_field_complimentary_reason else R.string.editorial_promo_field_notes), errors, "notes", minLines = 3)
            PromoRadioGroup(stringResource(R.string.editorial_promo_field_wizard_status), listOf(
                it.fabiodalez.incitta.data.editorial.PromoChoice("active", stringResource(R.string.editorial_promo_status_active)),
                it.fabiodalez.incitta.data.editorial.PromoChoice("draft", stringResource(R.string.editorial_promo_status_draft)),
            ), draft.status, { v -> model.edit { it.copy(status = v) } }, errors.field("status"))
            Text(stringResource(R.string.editorial_promo_advanced_title), style = MaterialTheme.typography.titleMedium)
            NumberField(draft.priority, { v -> model.edit { it.copy(priority = v) } }, stringResource(R.string.editorial_promo_field_priority), errors, "priority", help = stringResource(R.string.editorial_promo_priority_help))
            NumberField(draft.weight, { v -> model.edit { it.copy(weight = v) } }, stringResource(R.string.editorial_promo_field_weight), errors, "weight", help = stringResource(R.string.editorial_promo_weight_help))
            WizardSummary(state, choices, zone)
        }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (state.step > 1) OutlinedButton(enabled = !state.busy, onClick = { model.previous() }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_previous)) }
        Button(enabled = !state.busy, onClick = model::next, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
            Text(stringResource(if (state.step < CampaignWizardViewModel.STEPS) R.string.editorial_promo_next else R.string.editorial_promo_create))
        }
    }
}

/** Il riepilogo in chiaro prima di salvare, come la vista del pannello. */
@Composable
private fun WizardSummary(state: WizardUiState, choices: PromoChoices, zone: java.time.ZoneId) {
    val draft = state.draft
    Text(stringResource(R.string.editorial_promo_summary_title), style = MaterialTheme.typography.titleMedium)
    val what = if (draft.effectiveScope == "all") pluralStringResourceSafe(state.preview.allEvents?.count ?: 0) else draft.eventTitle
    val lines = listOfNotNull(
        stringResource(R.string.editorial_promo_summary_sponsor, draft.sponsorLabel.ifBlank { draft.advertiserName }),
        stringResource(R.string.editorial_promo_summary_what, what),
        choices.placements.firstOrNull { it.value == draft.placement }?.label?.let { stringResource(R.string.editorial_promo_summary_where, it) },
        stringResource(R.string.editorial_promo_summary_when, promoPeriod(draft.startsAt, draft.endsAt, zone)),
        if (draft.product == "views") stringResource(R.string.editorial_promo_summary_views, draft.impressionsGoal) else stringResource(R.string.editorial_promo_summary_period),
        if (draft.complimentary) stringResource(R.string.editorial_promo_summary_free) else stringResource(R.string.editorial_promo_summary_amount, draft.amount),
        stringResource(if (draft.status == "active") R.string.editorial_promo_summary_active else R.string.editorial_promo_summary_draft),
    )
    lines.forEach { Text("• $it") }
}

@Composable
private fun pluralStringResourceSafe(count: Int): String = androidx.compose.ui.res.pluralStringResource(R.plurals.editorial_promo_all_events_count, count, count)

private fun estimateColor(status: String): String = when (status) {
    "danger" -> "danger"
    "warning" -> "warning"
    "success" -> "success"
    else -> "info"
}

/** La scelta di un committente (o del locale che filtra gli eventi di un esterno), con la ricerca. */
@Composable
internal fun SponsorPickerDialog(sponsors: List<SponsorChoice>, busy: Boolean, onSearch: (String) -> Unit, onChoose: (SponsorChoice) -> Unit, onDismiss: () -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.editorial_promo_sponsor_pick)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                OutlinedTextField(query, { query = it.take(120); onSearch(query) }, label = { Text(stringResource(R.string.editorial_promo_sponsor_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                if (!busy && sponsors.isEmpty()) Text(stringResource(R.string.editorial_promo_sponsor_empty), color = Muted)
                sponsors.forEach { sponsor ->
                    TextButton(onClick = { onChoose(sponsor) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(sponsor.label, modifier = Modifier.fillMaxWidth())
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}
