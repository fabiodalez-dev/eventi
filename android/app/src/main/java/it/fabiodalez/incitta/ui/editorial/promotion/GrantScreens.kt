package it.fabiodalez.incitta.ui.editorial.promotion

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialPromotionSource
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.GrantDetail
import it.fabiodalez.incitta.data.editorial.GrantRow
import it.fabiodalez.incitta.data.editorial.PromoChoices
import it.fabiodalez.incitta.data.editorial.SponsorChoice
import it.fabiodalez.incitta.data.editorial.euroOrNull
import it.fabiodalez.incitta.data.editorial.numberOrNull
import it.fabiodalez.incitta.data.editorial.options
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
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
 * Le abilitazioni («Abilitazioni e pagamenti», R-SP-11…12), come
 * `SponsorshipGrantResource`: l'elenco con l'avanzamento e i filtri per
 * locale e organizzatore, il nuovo e la modifica con le sezioni del modulo
 * (regole, consegna, periodo e pagamento). Committente, modalità e
 * collocazione si scelgono solo alla nascita. Nessuna eliminazione.
 * Le date sono nell'ora di Padova, come nel pannello.
 */

internal class GrantListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    @Suppress("UNUSED_PARAMETER") saved: SavedStateHandle,
) : ViewModel() {
    val list = PagedListController(viewModelScope, ListQuery()) { query, page -> repository.call { source.grants(query.params(page)) }.page() }
    private val venues = MutableStateFlow<List<OptionChoice>>(emptyList())
    private val organizers = MutableStateFlow<List<OptionChoice>>(emptyList())
    val venueChoices: StateFlow<List<OptionChoice>> = venues.asStateFlow()
    val organizerChoices: StateFlow<List<OptionChoice>> = organizers.asStateFlow()

    init {
        list.refresh()
        /* Le scelte dei due filtri: tutti i locali e tutti gli organizzatori, come le tendine del pannello. */
        viewModelScope.launch {
            try {
                venues.value = repository.call { source.sponsors("venue", "", all = true) }.map { OptionChoice(it.id.toString(), it.label) }
                organizers.value = repository.call { source.sponsors("organizer", "", all = true) }.map { OptionChoice(it.id.toString(), it.label) }
            } catch (e: CancellationException) { throw e } catch (_: Exception) { /* Senza scelte i filtri restano vuoti; l'elenco si vede lo stesso. */ }
        }
    }
}

@Composable
internal fun GrantListScreen(model: GrantListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val venues by model.venueChoices.collectAsStateWithLifecycle()
    val organizers by model.organizerChoices.collectAsStateWithLifecycle()
    var visited by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (visited) model.list.refresh() else visited = true }
    GrantListContent(list, venues, organizers, context.padding, context::back, model.list::search, model.list::applyFilters, model.list::loadMore, model.list::retry,
        onOpen = { context.navController.navigate(GrantFormRoute(it.id)) }, onNew = { context.navController.navigate(GrantFormRoute(0)) })
}

/** Il contenuto dell'elenco, senza ViewModel: lo provano i test strumentati. */
@Composable
internal fun GrantListContent(
    list: PagedState<GrantRow>,
    venues: List<OptionChoice>,
    organizers: List<OptionChoice>,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (GrantRow) -> Unit,
    onNew: () -> Unit,
) {
    PagedListScreen(
        title = stringResource(R.string.editorial_promo_grants_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = GrantRow::id,
        filters = listOf(
            FilterDefinition("venue", stringResource(R.string.editorial_promo_field_venue), venues, searchable = true),
            FilterDefinition("organizer", stringResource(R.string.editorial_promo_field_organizer), organizers, searchable = true),
        ),
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_promo_grants_search),
        emptyText = stringResource(R.string.editorial_promo_grants_empty),
        header = { Button(onClick = onNew, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_promo_grant_new)) } },
    ) { grant ->
        val zone = promoZone(grant.timezone)
        Column(Modifier.fillMaxWidth().clickable(role = Role.Button) { onOpen(grant) }.padding(vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(grant.sponsor, style = MaterialTheme.typography.titleMedium)
            Text("${grant.sponsorKind} · ${grant.statusLabel} · ${grant.mode.label} · ${grant.placement.label}", color = Muted, style = MaterialTheme.typography.bodySmall)
            Text(promoPeriod(grant.startsAt, grant.endsAt, zone), color = Muted, style = MaterialTheme.typography.bodySmall)
            Text(
                listOfNotNull(
                    grant.amountLabel,
                    if (grant.complimentary) stringResource(R.string.editorial_promo_complimentary_short) else null,
                    if (!grant.enabled) stringResource(R.string.editorial_promo_disabled_short) else null,
                    stringResource(R.string.editorial_promo_grant_numbers, grant.impressions, grant.clicks),
                ).joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
            )
            grant.progress?.let { PromoProgressLine(it) }
        }
        HorizontalDivider()
    }
}

// ----------------------------------------------------------- il modulo

@Serializable
internal data class GrantDraft(
    val sponsorKind: String = "venue",
    val venueId: Long? = null,
    val organizerId: Long? = null,
    val sponsorLabel: String = "",
    val mode: String = "selected",
    val placement: String = "list_top",
    val enabled: Boolean = true,
    val impressionsGoal: String = "",
    val pacing: String = "even",
    val priority: String = "0",
    val weight: String = "1",
    val startsAt: String? = null,
    val endsAt: String? = null,
    val months: String = "",
    val complimentary: Boolean = false,
    val amount: String = "",
    val paidAt: String? = null,
    val paymentMethod: String = "",
    val paymentReference: String = "",
    val notes: String = "",
) {
    /** Il corpo: alla nascita anche committente, modalità e collocazione. */
    fun body(creating: Boolean): JsonObject = buildJsonObject {
        if (creating) {
            venueId?.let { put("venue_id", it) }
            organizerId?.let { put("organizer_id", it) }
            put("mode", mode)
            put("placement", placement)
        }
        put("enabled", enabled)
        put("impressions_goal", numberOrNull(impressionsGoal))
        put("pacing", pacing)
        put("priority", numberOrNull(priority))
        put("weight", numberOrNull(weight))
        startsAt?.let { put("starts_at", it) }
        endsAt?.let { put("ends_at", it) }
        put("complimentary", complimentary)
        if (!complimentary) {
            put("amount", euroOrNull(amount))
            put("paid_at", paidAt?.let { JsonPrimitive(it) } ?: JsonNull)
            put("payment_method", paymentMethod.trim().ifEmpty { null }?.let { JsonPrimitive(it) } ?: JsonNull)
            put("payment_reference", paymentReference.trim().ifEmpty { null }?.let { JsonPrimitive(it) } ?: JsonNull)
        }
        put("notes", notes.takeIf { it.isNotBlank() }?.let { JsonPrimitive(it) } ?: JsonNull)
    }

    companion object {
        fun from(detail: GrantDetail): GrantDraft {
            val form = detail.form
            return GrantDraft(
                sponsorKind = if (form.organizerId != null) "organizer" else "venue", venueId = form.venueId, organizerId = form.organizerId,
                sponsorLabel = form.venueLabel ?: form.organizerLabel.orEmpty(), mode = form.mode, placement = form.placement, enabled = form.enabled,
                impressionsGoal = form.impressionsGoal?.toString().orEmpty(), pacing = form.pacing, priority = form.priority.toString(), weight = form.weight.toString(),
                startsAt = form.startsAt, endsAt = form.endsAt, complimentary = form.complimentary, amount = form.amount.orEmpty(), paidAt = form.paidAt,
                paymentMethod = form.paymentMethod.orEmpty(), paymentReference = form.paymentReference.orEmpty(), notes = form.notes.orEmpty(),
            )
        }

        val FIELDS = listOf("venue_id", "organizer_id", "mode", "placement", "enabled", "impressions_goal", "pacing", "priority", "weight", "starts_at", "ends_at",
            "complimentary", "amount", "paid_at", "payment_method", "payment_reference", "notes")
    }
}

internal data class GrantFormUiState(
    val detail: Loadable<GrantDetail> = Loadable.Loading,
    val draft: GrantDraft? = null,
    val choices: PromoChoices = PromoChoices(),
    val errors: FormErrors = FormErrors.None,
    val busy: Boolean = false,
    val saved: Boolean = false,
    val picking: Boolean = false,
    val sponsors: List<SponsorChoice> = emptyList(),
    val pickerBusy: Boolean = false,
)

internal class GrantFormViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialPromotionSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    var grantId: Long = saved.get<Long>(KEY_ID) ?: 0L
        private set
    private val state = MutableStateFlow(GrantFormUiState(draft = saved.get<String>(KEY_DRAFT)?.let { runCatching { promoJson.decodeFromString(GrantDraft.serializer(), it) }.getOrNull() }))
    val uiState: StateFlow<GrantFormUiState> = state.asStateFlow()
    private var search: Job? = null

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                if (grantId == 0L) {
                    val choices = repository.call { source.grants(listOf("per_page" to "1")) }.choices
                    val now = java.time.LocalDateTime.now(ZONE).withSecond(0).withNano(0)
                    state.update {
                        it.copy(detail = Loadable.Ready(GrantDetail()), choices = choices,
                            draft = it.draft ?: GrantDraft(startsAt = cityIso(now, ZONE), endsAt = cityIso(now.plusMonths(1), ZONE)))
                    }
                } else {
                    val detail = repository.call { source.grant(grantId) }
                    state.update { it.copy(detail = Loadable.Ready(detail), choices = detail.choices, draft = it.draft ?: GrantDraft.from(detail)) }
                }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(detail = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    fun edit(change: (GrantDraft) -> GrantDraft) {
        state.update { it.copy(draft = it.draft?.let(change), saved = false) }
        persist()
    }

    /** La durata in mesi sposta la fine, nell'ora di Padova; una fine scritta a mano la toglie. */
    fun chooseMonths(value: String) = edit { draft ->
        val months = value.toLongOrNull()
        val start = cityLocalDateTime(draft.startsAt, ZONE)
        if (months == null || start == null) draft.copy(months = value) else draft.copy(months = value, endsAt = cityIso(start.plusMonths(months), ZONE))
    }

    /** Gratuita: il pagamento si svuota, come nel pannello. */
    fun chooseComplimentary(value: Boolean) = edit { if (value) it.copy(complimentary = true, amount = "", paidAt = null, paymentMethod = "", paymentReference = "") else it.copy(complimentary = false) }

    fun openPicker() { state.update { it.copy(picking = true, sponsors = emptyList()) }; searchSponsors("") }
    fun closePicker() { search?.cancel(); state.update { it.copy(picking = false, pickerBusy = false) } }

    fun searchSponsors(q: String) {
        val kind = state.value.draft?.sponsorKind ?: "venue"
        search?.cancel()
        state.update { it.copy(pickerBusy = true) }
        search = viewModelScope.launch {
            delay(300)
            try { state.update { it.copy(sponsors = repository.call { source.sponsors(kind, q, all = true) }, pickerBusy = false) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(pickerBusy = false, errors = FormErrors(general = requestFailureMessage(e))) } }
        }
    }

    fun chooseSponsor(sponsor: SponsorChoice) {
        edit { if (it.sponsorKind == "organizer") it.copy(organizerId = sponsor.id, venueId = null, sponsorLabel = sponsor.label) else it.copy(venueId = sponsor.id, organizerId = null, sponsorLabel = sponsor.label) }
        closePicker()
    }

    fun chooseSponsorKind(kind: String) = edit { it.copy(sponsorKind = kind, venueId = null, organizerId = null, sponsorLabel = "") }

    fun save() {
        val draft = state.value.draft ?: return
        if (state.value.busy) return
        val creating = grantId == 0L
        state.update { it.copy(busy = true, errors = FormErrors.None, saved = false) }
        viewModelScope.launch {
            try {
                val detail = repository.call { if (creating) source.createGrant(draft.body(true)) else source.updateGrant(grantId, draft.body(false)) }
                grantId = detail.id
                saved[KEY_ID] = detail.id
                state.update { it.copy(detail = Loadable.Ready(detail), choices = detail.choices, draft = GrantDraft.from(detail), saved = true) }
                persist()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(errors = FormErrors.from(e, GrantDraft.FIELDS)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun persist() { state.value.draft?.let { saved[KEY_DRAFT] = promoJson.encodeToString(GrantDraft.serializer(), it) } }

    companion object {
        const val KEY_ID = "grantId"
        const val KEY_DRAFT = "grantDraft"
        val ZONE: java.time.ZoneId = java.time.ZoneId.of(EDITORIAL_REPORT_ZONE)
    }
}

@Composable
internal fun GrantFormScreen(model: GrantFormViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    val creating = model.grantId == 0L
    ManagementScaffold(
        title = stringResource(if (creating) R.string.editorial_promo_grant_new else R.string.editorial_promo_grant_edit),
        padding = context.padding,
        onBack = context::back,
        subtitle = state.draft?.sponsorLabel,
        busy = state.busy,
    ) {
        LoadState(state.detail, onRetry = model::load) { detail ->
            val draft = state.draft ?: return@LoadState
            if (state.saved) Text(stringResource(R.string.editorial_promo_saved), color = MaterialTheme.colorScheme.primary)
            if (!creating) {
                Text(detail.statusLabel, style = MaterialTheme.typography.titleMedium)
                Text(stringResource(R.string.editorial_promo_grant_numbers, detail.impressions, detail.clicks), color = Muted)
                PromoProgressDetailView(detail.progressDetail)
                HorizontalDivider()
            }
            GrantFields(draft, state.choices, state.errors, state.busy, creating, detail.periodOnly, model)
        }
    }
    if (state.picking) SponsorPickerDialog(state.sponsors, state.pickerBusy, model::searchSponsors, model::chooseSponsor, model::closePicker)
}

@Composable
private fun GrantFields(draft: GrantDraft, choices: PromoChoices, errors: FormErrors, busy: Boolean, creating: Boolean, periodOnly: String?, model: GrantFormViewModel) {
    val zone = GrantFormViewModel.ZONE
    FormScaffold(errors = errors, busy = busy, submitLabel = stringResource(R.string.editorial_promo_save), onSubmit = model::save) {
        Text(stringResource(R.string.editorial_promo_grant_rules), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_promo_grant_rules_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        if (creating) {
            PromoChips(stringResource(R.string.editorial_promo_field_sponsor_kind), listOf(
                it.fabiodalez.incitta.data.editorial.PromoChoice("venue", stringResource(R.string.editorial_promo_field_venue)),
                it.fabiodalez.incitta.data.editorial.PromoChoice("organizer", stringResource(R.string.editorial_promo_field_organizer)),
            ), draft.sponsorKind, model::chooseSponsorKind)
            Text(draft.sponsorLabel.ifBlank { stringResource(R.string.editorial_promo_sponsor_none) })
            (errors.field("venue_id") ?: errors.field("organizer_id"))?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            OutlinedButton(onClick = model::openPicker, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (draft.sponsorKind == "organizer") R.string.editorial_promo_choose_organizer else R.string.editorial_promo_choose_venue))
            }
            ChoiceField(stringResource(R.string.editorial_promo_field_mode), choices.modes.options(), draft.mode, { v -> model.edit { it.copy(mode = v) } }, errors.field("mode"))
            ChoiceField(stringResource(R.string.editorial_promo_field_placement), choices.placements.options(), draft.placement, { v -> model.edit { it.copy(placement = v) } }, errors.field("placement"))
        } else {
            Text(stringResource(R.string.editorial_promo_grant_fixed, draft.sponsorLabel, choices.modes.firstOrNull { it.value == draft.mode }?.label.orEmpty(),
                choices.placements.firstOrNull { it.value == draft.placement }?.label.orEmpty()), color = Muted)
        }
        PromoSwitch(stringResource(R.string.editorial_promo_field_enabled), null, draft.enabled) { v -> model.edit { it.copy(enabled = v) } }

        Text(stringResource(R.string.editorial_promo_grant_delivery), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_promo_grant_delivery_lead), color = Muted, style = MaterialTheme.typography.bodySmall)
        NumberField(draft.impressionsGoal, { v -> model.edit { it.copy(impressionsGoal = v) } }, stringResource(R.string.editorial_promo_field_goal), errors, "impressions_goal",
            help = periodOnly ?: stringResource(R.string.editorial_promo_goal_help))
        ChoiceField(stringResource(R.string.editorial_promo_field_pacing), choices.pacings.options(), draft.pacing, { v -> model.edit { it.copy(pacing = v) } }, errors.field("pacing"))
        NumberField(draft.priority, { v -> model.edit { it.copy(priority = v) } }, stringResource(R.string.editorial_promo_field_priority), errors, "priority", help = stringResource(R.string.editorial_promo_priority_help))
        NumberField(draft.weight, { v -> model.edit { it.copy(weight = v) } }, stringResource(R.string.editorial_promo_field_weight), errors, "weight", help = stringResource(R.string.editorial_promo_weight_help))

        Text(stringResource(R.string.editorial_promo_grant_period), style = MaterialTheme.typography.titleLarge)
        CityDateTimeField(stringResource(R.string.editorial_promo_field_starts), cityLocalDateTime(draft.startsAt, zone), zone, null, { v -> model.edit { it.copy(startsAt = v?.let { local -> cityIso(local, zone) }) } }, errors.field("starts_at"))
        ChoiceField(stringResource(R.string.editorial_promo_field_months), choices.months.options(), draft.months, model::chooseMonths, emptyLabel = stringResource(R.string.editorial_promo_months_custom))
        CityDateTimeField(stringResource(R.string.editorial_promo_field_ends), cityLocalDateTime(draft.endsAt, zone), zone, null, { v -> model.edit { it.copy(endsAt = v?.let { local -> cityIso(local, zone) }, months = "") } }, errors.field("ends_at"))
        PromoSwitch(stringResource(R.string.editorial_promo_field_complimentary), stringResource(R.string.editorial_promo_grant_complimentary_help), draft.complimentary, model::chooseComplimentary)
        if (!draft.complimentary) {
            FormTextField(draft.amount, { v -> model.edit { it.copy(amount = v) } }, stringResource(R.string.editorial_promo_field_amount), errors, "amount", singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            CityDateTimeField(stringResource(R.string.editorial_promo_field_paid_at), cityLocalDateTime(draft.paidAt, zone), zone, null, { v -> model.edit { it.copy(paidAt = v?.let { local -> cityIso(local, zone) }) } },
                errors.field("paid_at"), clearable = true)
            FormTextField(draft.paymentMethod, { v -> model.edit { it.copy(paymentMethod = v) } }, stringResource(R.string.editorial_promo_field_method), errors, "payment_method", singleLine = true)
            FormTextField(draft.paymentReference, { v -> model.edit { it.copy(paymentReference = v) } }, stringResource(R.string.editorial_promo_field_reference), errors, "payment_reference", singleLine = true)
        }
        FormTextField(draft.notes, { v -> model.edit { it.copy(notes = v) } }, stringResource(R.string.editorial_promo_field_notes), errors, "notes", minLines = 3)
    }
}

/** Un interruttore con la riga di aiuto, alto 48 dp. */
@Composable
internal fun PromoSwitch(label: String, help: String?, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label)
            help?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
        Switch(checked = checked, onCheckedChange = onChange)
    }
}
