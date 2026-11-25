package it.fabiodalez.incitta.ui.management.events

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.EventFormOptions
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.prepareImage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.imageErrorText
import it.fabiodalez.incitta.ui.management.common.pickerDate
import it.fabiodalez.incitta.ui.management.common.pickerMillis
import it.fabiodalez.incitta.ui.management.common.rememberImagePicker
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Il modulo dell'evento nelle tre varianti del piano:
 *
 * - **creazione del locale** (W-EV-07…13): una pagina sola, con in cima
 *   l'import da Facebook e «L'essenziale» (locandina, titolo, giorno con le
 *   scorciatoie e «Si ripete», categoria, prezzo, descrizione), l'anteprima
 *   della card, poi le sezioni facoltative chiuse; «Pubblica» e «Salva come
 *   bozza»;
 * - **modifica del locale** (W-EV-14, W-TK-16): sezioni richiudibili, con
 *   listino, scheda tecnica, informazioni editoriali, organizzatore e SEO;
 * - **organizzazione** (W-EV-26…28): modulo unico con città e locale
 *   principale; in creazione la prima data facoltativa e il luogo.
 */
@Composable
internal fun EventEditorScreen(viewModel: EventEditorViewModel, context: ManagementGraphContext, onFinished: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val androidContext = LocalContext.current
    val scope = rememberCoroutineScope()
    val pickPoster = rememberImagePicker { uri ->
        scope.launch {
            try { viewModel.setPoster(withContext(Dispatchers.IO) { prepareImage(androidContext, uri) }) }
            catch (e: ImageUploadException) { viewModel.posterError(UiText.Res(imageErrorText(e.reason))) }
        }
    }
    LaunchedEffect(state.finishedId) { state.finishedId?.let { viewModel.consumeFinished(); onFinished(it) } }

    ManagementScaffold(
        title = stringResource(if (state.mode.isCreate) R.string.management_events_new_title else R.string.management_events_edit_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = state.subject?.name,
        busy = state.busy,
    ) {
        LoadState(state.options, onRetry = viewModel::load) { options ->
            EditorContent(state, options, viewModel, onPickPoster = pickPoster)
        }
    }
}

@Composable
private fun EditorContent(state: EventEditorUiState, options: EventFormOptions, viewModel: EventEditorViewModel, onPickPoster: () -> Unit) {
    val form = state.form
    val mode = state.mode
    val errors = state.errors
    val zone = state.zone
    val catalog = state.catalog ?: ManagementOptions()
    fun error(key: String): String? = errors.field(key)?.let { if (it == EventEditorViewModel.TITLE_NEEDED) null else it }
    val titleNeeded = errors.fields["title"] == EventEditorViewModel.TITLE_NEEDED

    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        val general = errors.general ?: if (errors.fields.isNotEmpty() && !titleNeeded) stringResource(R.string.management_form_fix) else null
        general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }

        if (mode.isCreate) FacebookSection(state, viewModel, mode.isVenue)

        // L'essenziale
        SectionTitle(stringResource(R.string.management_events_essentials))
        if (mode.isCreate) Text(stringResource(R.string.management_events_essentials_hint), color = Muted)
        PosterField(state, onPickPoster)
        PlainField(form.title, { v -> viewModel.edit { it.copy(title = v) } }, stringResource(R.string.management_events_field_title),
            error = if (titleNeeded) stringResource(R.string.management_events_title_needed) else error("title"),
            help = stringResource(R.string.management_events_title_placeholder))

        if (mode == EventFormMode.VenueCreate) {
            Text(stringResource(R.string.management_events_when), style = MaterialTheme.typography.titleSmall)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                options.shortcuts.forEach { shortcut ->
                    OutlinedButton(onClick = { viewModel.applyShortcut(shortcut.startsAt, shortcut.repeats, shortcut.weekday) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(shortcut.label) }
                }
            }
            CityDateTimeField(stringResource(R.string.management_events_starts_at), form.startsAt, zone, options.city.name, { v -> viewModel.edit { it.copy(startsAt = v) } }, error = error("starts_at"))
            DateWarnings(state)
            RepeatFields(form, options, catalog, error("repeat")) { change -> viewModel.edit(change) }
        }
        if (!mode.isVenue) OrganizerPlace(state, options, viewModel)

        ChoiceField(stringResource(R.string.management_events_field_category), options.categories.map { it.id.toString() to it.name }, form.categoryId?.toString(),
            { v -> viewModel.edit { it.copy(categoryId = v?.toLongOrNull()) } }, error = error("category_id"))
        val priceTypes = catalog.choices("price_types").map { it.value to it.label }.ifEmpty { DEFAULT_PRICE_TYPES }
        ChoiceChips(stringResource(R.string.management_events_field_price_type), priceTypes, form.priceType, { v -> viewModel.edit { it.copy(priceType = v ?: "free") } }, error = error("price_type"))
        if (form.hasAmount) {
            PlainField(form.priceMin, { v -> viewModel.edit { it.copy(priceMin = v) } }, stringResource(R.string.management_events_price_min), error = error("price_min"), keyboard = KeyboardType.Decimal)
            PlainField(form.priceMax, { v -> viewModel.edit { it.copy(priceMax = v) } }, stringResource(R.string.management_events_price_max), error = error("price_max"),
                help = stringResource(R.string.management_events_price_max_hint), keyboard = KeyboardType.Decimal)
        }
        RichTextField(form.description, { v -> viewModel.edit { it.copy(description = v) } }, stringResource(R.string.management_events_field_description), error = error("description"), minLines = 6)

        if (mode == EventFormMode.VenueCreate) LivePreview(form, options, state.posterUrl ?: state.facebook?.photoUrl)

        CollapsibleSection(stringResource(R.string.management_events_more_details), stringResource(R.string.management_events_more_details_hint)) {
            if (mode == EventFormMode.VenueCreate) {
                CityDateTimeField(stringResource(R.string.management_events_ends_at), form.endsAt, zone, options.city.name, { v -> viewModel.edit { it.copy(endsAt = v) } },
                    error = error("ends_at"), clearable = true)
                Text(stringResource(R.string.management_events_ends_hint), color = Muted)
            }
            TagsField(form.tags, state.tagResults, viewModel::searchTags, { v -> viewModel.edit { it.copy(tags = v) } },
                onCreate = if (mode.isVenue) viewModel::createTag else null, error = error("tags"), max = options.limits.tags)
            PlainField(form.ticketUrl, { v -> viewModel.edit { it.copy(ticketUrl = v) } }, stringResource(R.string.management_events_ticket_url),
                error = error("ticket_url"), help = stringResource(R.string.management_events_ticket_url_hint), keyboard = KeyboardType.Uri)
            if (mode.isVenue) {
                PlainField(form.bookingUrl, { v -> viewModel.edit { it.copy(bookingUrl = v) } }, stringResource(R.string.management_events_booking_url),
                    error = error("booking_url"), help = stringResource(R.string.management_events_booking_url_hint), keyboard = KeyboardType.Uri)
                val maxLinks = (catalog.limit("external_links") ?: 8L).toInt()
                Text(stringResource(R.string.management_events_external_links), style = MaterialTheme.typography.titleSmall)
                Text(stringResource(R.string.management_events_external_links_hint, maxLinks), color = Muted)
                RepeaterField(form.externalLinks, { v -> viewModel.edit { it.copy(externalLinks = v) } }, stringResource(R.string.management_events_add_link), maxLinks, { LinkRow() }, error = error("external_links")) { row, update ->
                    PlainField(row.label, { update(row.copy(label = it)) }, stringResource(R.string.management_events_link_label))
                    PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.management_events_link_url), keyboard = KeyboardType.Uri)
                }
            }
        }

        if (mode != EventFormMode.OrganizerEdit) LocationSection(form, options, mode, errors) { change -> viewModel.edit(change) }
        BeforeGoingSection(form, catalog) { change -> viewModel.edit(change) }

        if (mode == EventFormMode.VenueEdit) {
            CollapsibleSection(stringResource(R.string.management_events_tiers), stringResource(R.string.management_events_tiers_hint)) {
                val statuses = options.ticketTierStatuses.map { it.value to it.label }
                RepeaterField(form.tiers, { v -> viewModel.edit { it.copy(tiers = v) } }, stringResource(R.string.management_events_tier_add), options.limits.ticketTiers, { TierRow() }, error = error("ticket_tiers")) { row, update ->
                    PlainField(row.name, { update(row.copy(name = it)) }, stringResource(R.string.management_events_tier_name))
                    PlainField(row.price, { update(row.copy(price = it)) }, stringResource(R.string.management_events_tier_price), keyboard = KeyboardType.Decimal)
                    ChoiceChips(stringResource(R.string.management_events_tier_status), statuses, row.status, { update(row.copy(status = it ?: "available")) })
                    PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.management_events_tier_url), keyboard = KeyboardType.Uri)
                    PlainField(row.note, { update(row.copy(note = it)) }, stringResource(R.string.management_events_tier_note))
                }
            }
            val maxFacts = (catalog.limit("facts") ?: 12L).toInt()
            CollapsibleSection(stringResource(R.string.management_events_facts), stringResource(R.string.management_events_facts_hint, maxFacts)) {
                RepeaterField(form.facts, { v -> viewModel.edit { it.copy(facts = v) } }, stringResource(R.string.management_events_fact_add), maxFacts, { FactRow() }, error = error("facts")) { row, update ->
                    PlainField(row.label, { update(row.copy(label = it)) }, stringResource(R.string.management_events_fact_label))
                    PlainField(row.value, { update(row.copy(value = it)) }, stringResource(R.string.management_events_fact_value))
                }
            }
        }
        if (mode == EventFormMode.VenueEdit || !mode.isVenue) EditorialSection(state, options, viewModel)
        if (mode == EventFormMode.VenueEdit) {
            CollapsibleSection(stringResource(R.string.management_events_seo), stringResource(R.string.management_events_seo_hint)) {
                PlainField(form.seoTitle, { v -> viewModel.edit { it.copy(seoTitle = v) } }, stringResource(R.string.management_events_seo_title), error = error("seo"))
                PlainField(form.seoDescription, { v -> viewModel.edit { it.copy(seoDescription = v) } }, stringResource(R.string.management_events_seo_description), minLines = 3)
                PlainField(form.seoImage, { v -> viewModel.edit { it.copy(seoImage = v) } }, stringResource(R.string.management_events_seo_image), keyboard = KeyboardType.Uri)
            }
        }

        // I pulsanti, come in fondo al modulo del pannello.
        when (mode) {
            EventFormMode.VenueCreate -> {
                Button(enabled = !state.busy, onClick = viewModel::publish, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(if (state.subject?.permissions?.eventsPublishDirectly == true) R.string.management_events_publish else R.string.management_events_publish_to_review))
                }
                OutlinedButton(enabled = !state.busy, onClick = viewModel::saveDraft, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.management_events_save_draft))
                }
            }
            EventFormMode.OrganizerCreate -> Button(enabled = !state.busy, onClick = viewModel::save, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_events_create))
            }
            else -> Button(enabled = !state.busy, onClick = viewModel::save, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.management_events_save))
            }
        }
    }
}

@Composable
private fun PosterField(state: EventEditorUiState, onPick: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.management_events_field_poster), style = MaterialTheme.typography.titleSmall)
        val url = state.posterUrl ?: state.facebook?.photoUrl
        if (url != null) {
            val label = stringResource(R.string.management_events_poster, state.form.title)
            PosterImage(url, Modifier.fillMaxWidth().height(200.dp).semantics { contentDescription = label }, contain = true)
        }
        if (state.pendingPoster != null) Text(stringResource(R.string.management_events_poster_selected), color = MaterialTheme.colorScheme.primary)
        if (state.facebook?.photoUrl != null && state.posterUrl == null && state.pendingPoster == null) Text(stringResource(R.string.management_events_facebook_photo), color = Muted)
        OutlinedButton(enabled = !state.busy, onClick = onPick, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(if (url == null && state.pendingPoster == null) R.string.management_events_poster_choose else R.string.management_events_poster_change))
        }
        Text(stringResource(R.string.management_events_poster_hint), color = Muted)
    }
}

/** «Hai già l'evento su Facebook?»: il link, il pulsante, quello che è stato letto. */
@Composable
private fun FacebookSection(state: EventEditorUiState, viewModel: EventEditorViewModel, open: Boolean) {
    CollapsibleSection(stringResource(R.string.management_events_facebook), stringResource(R.string.management_events_facebook_hint), initiallyOpen = open) {
        PlainField(state.facebookUrl, viewModel::setFacebookUrl, stringResource(R.string.management_events_facebook_url), error = state.errors.field("url"), keyboard = KeyboardType.Uri)
        OutlinedButton(enabled = !state.busy && state.facebookUrl.isNotBlank(), onClick = viewModel::importFacebook, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_events_facebook_load))
        }
        state.facebook?.let { result ->
            val source = result.source
            val parts = listOfNotNull(source.venueName, source.venueAddress, source.dateLabel, source.hosts.takeIf { it.isNotEmpty() }?.joinToString(", "))
            if (parts.isNotEmpty()) Text(stringResource(R.string.management_events_facebook_source, parts.joinToString(" · ")), color = Muted)
            result.locationAddress?.takeIf { it == state.form.locationAddress }?.let { Text(stringResource(R.string.management_events_facebook_location, it), color = MaterialTheme.colorScheme.error) }
        }
    }
}

/** Gli avvisi accanto all'orario: stessa sera in zona e chiusura straordinaria. */
@Composable
private fun DateWarnings(state: EventEditorUiState) {
    val checks = state.checks
    if (checks.sameNight.isNotEmpty()) {
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(stringResource(R.string.management_events_same_night), style = MaterialTheme.typography.titleSmall)
            Text(stringResource(R.string.management_events_same_night_hint, checks.radiusKm.let { if (it % 1.0 == 0.0) it.toInt().toString() else it.toString() }), color = Muted)
            checks.sameNight.forEach { item -> Text(stringResource(R.string.management_events_same_night_row, item.time, item.title, item.place)) }
        }
    }
    checks.closure?.let { closure ->
        val format = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale.ITALIAN)
        fun day(value: String) = runCatching { LocalDate.parse(value.take(10)).format(format) }.getOrDefault(value)
        Text(stringResource(R.string.management_events_closure), style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.error)
        Text(stringResource(R.string.management_events_closure_body, day(closure.from), day(closure.to), closure.note?.let { " ($it)" } ?: ""), color = MaterialTheme.colorScheme.error)
    }
}

/** «Si ripete»: frequenza, giorni, fino al. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun RepeatFields(form: EventForm, options: EventFormOptions, catalog: ManagementOptions, error: String?, edit: ((EventForm) -> EventForm) -> Unit) {
    Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.management_events_repeat), style = MaterialTheme.typography.titleSmall)
            Text(stringResource(R.string.management_events_repeat_hint), color = Muted)
        }
        val repeatLabel = stringResource(R.string.management_events_repeat)
        Switch(checked = form.repeat.enabled, onCheckedChange = { on -> edit { it.copy(repeat = it.repeat.copy(enabled = on)) } },
            modifier = Modifier.semantics { contentDescription = repeatLabel })
    }
    if (!form.repeat.enabled) return
    val frequencies = catalog.choices("recurrence_frequencies").map { it.value to it.label }.ifEmpty { listOf("weekly" to "Ogni settimana") }
    ChoiceField(stringResource(R.string.management_events_repeat_frequency), frequencies, form.repeat.frequency, { v -> edit { it.copy(repeat = it.repeat.copy(frequency = v ?: "weekly")) } }, error = error)
    if (form.repeat.frequency == "weekly" || form.repeat.frequency == "biweekly") {
        MultiChips(stringResource(R.string.management_events_repeat_weekdays), options.weekdays.map { it.value to it.label }, form.repeat.weekdays,
            { v -> edit { it.copy(repeat = it.repeat.copy(weekdays = v)) } }, help = stringResource(R.string.management_events_repeat_weekdays_hint))
    }
    var pick by remember { mutableStateOf(false) }
    Text(stringResource(R.string.management_events_repeat_until), style = MaterialTheme.typography.titleSmall)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(onClick = { pick = true }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(form.repeat.until?.format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ITALIAN)) ?: stringResource(R.string.management_events_repeat_until_pick))
        }
        if (form.repeat.until != null) TextButton(onClick = { edit { it.copy(repeat = it.repeat.copy(until = null)) } }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(R.string.management_events_remove_row))
        }
    }
    Text(stringResource(R.string.management_events_repeat_until_hint), color = Muted)
    if (pick) {
        val picker = rememberDatePickerState(initialSelectedDateMillis = pickerMillis(form.repeat.until ?: form.startsAt?.toLocalDate() ?: LocalDate.now()))
        DatePickerDialog(
            onDismissRequest = { pick = false },
            confirmButton = { TextButton(onClick = { pick = false; picker.selectedDateMillis?.let { millis -> edit { it.copy(repeat = it.repeat.copy(until = pickerDate(millis))) } } }) { Text(stringResource(R.string.management_datetime_done)) } },
            dismissButton = { TextButton(onClick = { pick = false }) { Text(stringResource(R.string.management_cancel)) } },
        ) { DatePicker(state = picker) }
    }
}

/** Città, locale principale e (in creazione) prima data dell'organizzazione. */
@Composable
private fun OrganizerPlace(state: EventEditorUiState, options: EventFormOptions, viewModel: EventEditorViewModel) {
    val form = state.form
    val errors = state.errors
    ChoiceField(stringResource(R.string.management_events_city), options.cities.map { it.id.toString() to it.name }, form.cityId?.toString(),
        { v -> viewModel.edit { it.copy(cityId = v?.toLongOrNull()) } }, error = errors.field("city_id"))
    VenuePickerField(stringResource(R.string.management_events_main_venue), form.venue, state.venueResults.map { it.id to listOfNotNull(it.name, it.city).joinToString(" · ") },
        viewModel::searchVenues, { v -> viewModel.edit { it.copy(venue = v) } }, error = errors.field("venue_id"), help = stringResource(R.string.management_events_main_venue_hint))
    if (state.mode == EventFormMode.OrganizerCreate) {
        val zone = options.cities.firstOrNull { it.id == form.cityId }?.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: state.zone
        val cityName = options.cities.firstOrNull { it.id == form.cityId }?.name ?: options.city.name
        Text(stringResource(R.string.management_events_first_date), style = MaterialTheme.typography.titleSmall)
        CityDateTimeField(stringResource(R.string.management_events_starts_at), form.startsAt, zone, cityName, { v -> viewModel.edit { it.copy(startsAt = v) } }, error = errors.field("starts_at"), clearable = true)
        CityDateTimeField(stringResource(R.string.management_events_ends_at), form.endsAt, zone, cityName, { v -> viewModel.edit { it.copy(endsAt = v) } }, error = errors.field("ends_at"), clearable = true)
        DateWarnings(state)
    }
}

@Composable
private fun LocationSection(form: EventForm, options: EventFormOptions, mode: EventFormMode, errors: FormErrors, edit: ((EventForm) -> EventForm) -> Unit) {
    val hint = if (mode.isVenue) stringResource(R.string.management_events_location_hint, options.venue?.address ?: options.venue?.name.orEmpty())
    else stringResource(R.string.management_events_location_hint_organizer)
    CollapsibleSection(stringResource(R.string.management_events_location), hint, initiallyOpen = form.locationAddress.isNotBlank()) {
        PlainField(form.locationAddress, { v -> edit { it.copy(locationAddress = v, locationLat = "", locationLng = "") } }, stringResource(R.string.management_events_location_address), error = errors.field("custom_location"))
        PlainField(form.locationName, { v -> edit { it.copy(locationName = v) } }, stringResource(R.string.management_events_location_name))
        PlainField(form.locationLat, { v -> edit { it.copy(locationLat = v) } }, stringResource(R.string.management_events_location_lat), keyboard = KeyboardType.Decimal)
        PlainField(form.locationLng, { v -> edit { it.copy(locationLng = v) } }, stringResource(R.string.management_events_location_lng), keyboard = KeyboardType.Decimal)
    }
}

/** «Prima di andare»: fasce d'età, famiglie, tessera, accessibilità, caratteristiche, voci libere, cibo, note. */
@Composable
private fun BeforeGoingSection(form: EventForm, catalog: ManagementOptions, edit: ((EventForm) -> EventForm) -> Unit) {
    CollapsibleSection(stringResource(R.string.management_events_before_going), stringResource(R.string.management_events_before_going_hint)) {
        MultiChips(stringResource(R.string.management_events_age_groups), catalog.choices("age_groups").map { it.value to it.label }, form.ageGroups, { v -> edit { it.copy(ageGroups = v) } })
        val yesNo = listOf("yes" to stringResource(R.string.management_events_yes), "no" to stringResource(R.string.management_events_no))
        ChoiceChips(stringResource(R.string.management_events_stroller), yesNo, form.stroller.ifBlank { null }, { v -> edit { it.copy(stroller = v.orEmpty()) } }, clearable = true)
        ChoiceChips(stringResource(R.string.management_events_changing_table), yesNo, form.changingTable.ifBlank { null }, { v -> edit { it.copy(changingTable = v.orEmpty()) } }, clearable = true)
        ChoiceChips(stringResource(R.string.management_events_kids_area), yesNo, form.kidsArea.ifBlank { null }, { v -> edit { it.copy(kidsArea = v.orEmpty()) } }, clearable = true)
        ChoiceChips(stringResource(R.string.management_events_membership), catalog.choices("membership_requirements").map { it.value to it.label }, form.membership.ifBlank { null },
            { v -> edit { it.copy(membership = v.orEmpty()) } }, clearable = true)
        RichTextField(form.membershipNotes, { v -> edit { it.copy(membershipNotes = v) } }, stringResource(R.string.management_events_membership_notes), minLines = 2)
        ChoiceChips(stringResource(R.string.management_events_accessibility),
            listOf("yes" to stringResource(R.string.management_events_accessible), "no" to stringResource(R.string.management_events_not_accessible)),
            form.accessibility.ifBlank { null }, { v -> edit { it.copy(accessibility = v.orEmpty()) } }, clearable = true)
        RichTextField(form.accessibilityNotes, { v -> edit { it.copy(accessibilityNotes = v) } }, stringResource(R.string.management_events_accessibility_notes), minLines = 2)
        MultiChips(stringResource(R.string.management_events_features), catalog.choices("event_features").map { it.value to it.label },
            form.featureIds.map(Long::toString).toSet(), { v -> edit { it.copy(featureIds = v.mapNotNull(String::toLongOrNull).toSet()) } })
        Text(stringResource(R.string.management_events_practical), style = MaterialTheme.typography.titleSmall)
        val icons = catalog.choices("practical_icons").map { it.value to it.label }
        RepeaterField(form.practical, { v -> edit { it.copy(practical = v) } }, stringResource(R.string.management_events_practical_add),
            (catalog.limit("practical_custom_event") ?: 24L).toInt(), { PracticalRow() }) { row, update ->
            PlainField(row.label, { update(row.copy(label = it)) }, stringResource(R.string.management_events_practical_label))
            ChoiceField(stringResource(R.string.management_events_practical_icon), icons, row.icon, { update(row.copy(icon = it ?: "check-circle")) })
            RichTextField(row.text, { update(row.copy(text = it)) }, stringResource(R.string.management_events_practical_text), minLines = 2, toolbar = false)
        }
        PlainField(form.foodNotes, { v -> edit { it.copy(foodNotes = v) } }, stringResource(R.string.management_events_food_notes), minLines = 2)
        PlainField(form.startNotes, { v -> edit { it.copy(startNotes = v) } }, stringResource(R.string.management_events_start_notes), minLines = 2)
    }
}

/** Informazioni utili e domande frequenti (e, per il locale, nome e sito dell'organizzatore). */
@Composable
private fun EditorialSection(state: EventEditorUiState, options: EventFormOptions, viewModel: EventEditorViewModel) {
    val form = state.form
    fun set(key: String, value: String) = viewModel.edit { it.copy(editorial = it.editorial + (key to value)) }
    CollapsibleSection(stringResource(R.string.management_events_editorial), stringResource(R.string.management_events_editorial_hint)) {
        val labels = EDITORIAL_LABELS
        RichTextField(form.editorial["introduction"].orEmpty(), { set("introduction", it) }, stringResource(R.string.management_events_ed_introduction), minLines = 3)
        ChoiceField(stringResource(R.string.management_events_ed_parking_type), options.parkingTypes.map { it.value to it.label }, form.editorial["parking_type"]?.ifBlank { null }, { set("parking_type", it.orEmpty()) }, allowNone = true)
        listOf("parking_notes", "transit_notes", "entrance_notes").forEach { key ->
            RichTextField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(labels.getValue(key)), minLines = 2, toolbar = false)
        }
        VenuePickerField(stringResource(R.string.management_events_ed_organizer_venue), form.organizerVenue, state.venueResults.map { it.id to it.name }, viewModel::searchVenues,
            { v -> viewModel.edit { it.copy(organizerVenue = v) } }, allowNone = true)
        PlainField(form.editorial["minimum_age"].orEmpty(), { set("minimum_age", it) }, stringResource(R.string.management_events_ed_minimum_age), keyboard = KeyboardType.Number)
        ChoiceField(stringResource(R.string.management_events_ed_attendance_mode), options.attendanceModes.map { it.value to it.label }, form.editorial["attendance_mode"]?.ifBlank { null }, { set("attendance_mode", it.orEmpty()) }, allowNone = true)
        if (form.editorial["attendance_mode"] == "online" || form.editorial["attendance_mode"] == "mixed") {
            PlainField(form.editorial["online_url"].orEmpty(), { set("online_url", it) }, stringResource(R.string.management_events_ed_online_url), keyboard = KeyboardType.Uri, error = state.errors.field("content_details.online_url"))
        }
        ChoiceField(stringResource(R.string.management_events_ed_organizer_type), options.organizerTypes.map { it.value to it.label }, form.editorial["organizer_type"]?.ifBlank { null }, { set("organizer_type", it.orEmpty()) }, allowNone = true)
        if (state.mode == EventFormMode.VenueEdit) {
            PlainField(form.organizerName, { v -> viewModel.edit { it.copy(organizerName = v) } }, stringResource(R.string.management_events_organizer_name), error = state.errors.field("organizer_name"))
            PlainField(form.organizerUrl, { v -> viewModel.edit { it.copy(organizerUrl = v) } }, stringResource(R.string.management_events_organizer_url), keyboard = KeyboardType.Uri, error = state.errors.field("organizer_url"))
        }
        listOf("mandatory_costs", "weather_policy", "minors_policy", "cancellation_policy", "refund_policy", "public_contact", "poster_alt", "poster_caption", "poster_credit").forEach { key ->
            if (key in EventForm.EDITORIAL_PLAIN_KEYS) PlainField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(labels.getValue(key)), minLines = 2)
            else RichTextField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(labels.getValue(key)), minLines = 2, toolbar = false)
        }
        Text(stringResource(R.string.management_events_agenda), style = MaterialTheme.typography.titleSmall)
        RepeaterField(form.agenda, { v -> viewModel.edit { it.copy(agenda = v) } }, stringResource(R.string.management_events_agenda_add), options.limits.agenda, { AgendaRow() },
            error = state.errors.field("content_details.agenda")) { row, update ->
            PlainField(row.title, { update(row.copy(title = it)) }, stringResource(R.string.management_events_agenda_title))
            PlainField(row.whenText, { update(row.copy(whenText = it)) }, stringResource(R.string.management_events_agenda_when))
            // Ora d'inizio e di fine e data collegata (P2), come il ripetitore del pannello.
            CityDateTimeField(stringResource(R.string.management_dates_agenda_starts), AgendaTime.toCity(row.startsAt, state.zone), state.zone, state.subject?.cityName,
                onChange = { update(row.copy(startsAt = AgendaTime.fromCity(it, state.zone))) }, clearable = true)
            CityDateTimeField(stringResource(R.string.management_dates_agenda_ends), AgendaTime.toCity(row.endsAt, state.zone), state.zone, state.subject?.cityName,
                onChange = { update(row.copy(endsAt = AgendaTime.fromCity(it, state.zone))) }, clearable = true)
            if (state.eventDates.isNotEmpty() || row.occurrenceId != null) {
                ChoiceField(stringResource(R.string.management_dates_agenda_date),
                    state.eventDates.map { it.id.toString() to formatEventDate(it.startsAt, state.zone) },
                    row.occurrenceId?.toString(), { update(row.copy(occurrenceId = it?.toLongOrNull())) },
                    allowNone = true, noneLabel = stringResource(R.string.management_dates_agenda_all_dates))
            }
            PlainField(row.speaker, { update(row.copy(speaker = it)) }, stringResource(R.string.management_events_agenda_speaker))
            RichTextField(row.description, { update(row.copy(description = it)) }, stringResource(R.string.management_events_agenda_description), minLines = 2, toolbar = false)
        }
        Text(stringResource(R.string.management_events_faqs), style = MaterialTheme.typography.titleSmall)
        RepeaterField(form.faqs, { v -> viewModel.edit { it.copy(faqs = v) } }, stringResource(R.string.management_events_faq_add), options.limits.faqs, { FaqRow() },
            error = state.errors.field("content_details.faqs")) { row, update ->
            PlainField(row.question, { update(row.copy(question = it)) }, stringResource(R.string.management_events_faq_question))
            RichTextField(row.answer, { update(row.copy(answer = it)) }, stringResource(R.string.management_events_faq_answer), minLines = 2, toolbar = false)
        }
    }
}

/** L'anteprima dal vivo della card (W-EV-11), con i dati del modulo come li vedrà il pubblico. */
@Composable
private fun LivePreview(form: EventForm, options: EventFormOptions, posterUrl: String?) {
    CollapsibleSection(stringResource(R.string.management_events_live_preview), stringResource(R.string.management_events_live_preview_hint), initiallyOpen = true) {
        OutlinedCard(Modifier.fillMaxWidth()) {
            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                PosterImage(posterUrl, Modifier.size(width = 80.dp, height = 100.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(form.title.ifBlank { stringResource(R.string.management_events_preview_untitled) }, style = MaterialTheme.typography.titleMedium)
                    Text(form.startsAt?.format(DateTimeFormatter.ofPattern("EEE d/M/yyyy, HH:mm", Locale.ITALIAN)) ?: stringResource(R.string.management_events_preview_no_date), color = Muted)
                    Text(form.locationName.ifBlank { form.locationAddress }.ifBlank { options.venue?.name.orEmpty() }, color = Muted)
                    val price = when {
                        form.priceType == "free" -> stringResource(R.string.management_events_preview_free)
                        form.hasAmount && form.priceMin.isNotBlank() -> stringResource(R.string.management_events_price_from, form.priceMin)
                        else -> null
                    }
                    price?.let { Text(it, color = Muted) }
                    options.categories.firstOrNull { it.id == form.categoryId }?.let { StatusBadge(it.name) }
                }
            }
        }
        if (form.description.isNotBlank()) Text(RichText.plain(form.description).take(240), color = Muted)
    }
}

private val DEFAULT_PRICE_TYPES = listOf("free" to "Ingresso libero", "donation" to "Offerta libera", "ticket" to "Biglietto", "membership" to "Con tessera", "unknown" to "Da definire")

private val EDITORIAL_LABELS = mapOf(
    "parking_notes" to R.string.management_events_ed_parking_notes,
    "transit_notes" to R.string.management_events_ed_transit_notes,
    "entrance_notes" to R.string.management_events_ed_entrance_notes,
    "mandatory_costs" to R.string.management_events_ed_mandatory_costs,
    "weather_policy" to R.string.management_events_ed_weather_policy,
    "minors_policy" to R.string.management_events_ed_minors_policy,
    "cancellation_policy" to R.string.management_events_ed_cancellation_policy,
    "refund_policy" to R.string.management_events_ed_refund_policy,
    "public_contact" to R.string.management_events_ed_public_contact,
    "poster_alt" to R.string.management_events_ed_poster_alt,
    "poster_caption" to R.string.management_events_ed_poster_caption,
    "poster_credit" to R.string.management_events_ed_poster_credit,
)
