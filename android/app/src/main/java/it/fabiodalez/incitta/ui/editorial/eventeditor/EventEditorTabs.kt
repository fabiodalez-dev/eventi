package it.fabiodalez.incitta.ui.editorial.eventeditor

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ArrowDownward
import androidx.compose.material.icons.outlined.ArrowUpward
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorEventOptions
import it.fabiodalez.incitta.data.management.LabeledValue
import it.fabiodalez.incitta.data.management.NamedRef
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.PosterImage
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.events.AgendaRow
import it.fabiodalez.incitta.ui.management.events.AgendaTime
import it.fabiodalez.incitta.ui.management.events.ChoiceChips
import it.fabiodalez.incitta.ui.management.events.ChoiceField
import it.fabiodalez.incitta.ui.management.events.EventForm
import it.fabiodalez.incitta.ui.management.events.FactRow
import it.fabiodalez.incitta.ui.management.events.FaqRow
import it.fabiodalez.incitta.ui.management.events.LinkRow
import it.fabiodalez.incitta.ui.management.events.PlainField
import it.fabiodalez.incitta.ui.management.events.RepeaterField
import it.fabiodalez.incitta.ui.management.events.TagsField
import it.fabiodalez.incitta.ui.management.events.TierRow
import it.fabiodalez.incitta.ui.management.events.VenuePickerField
import it.fabiodalez.incitta.ui.management.events.formatEventDate
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Le otto schede del modulo evento della redazione. I campi in comune con la
 * gestione (testo formattato, tag, scelte, ripetitori, «Prima di andare»)
 * sono gli stessi componenti di `ui/management/events`.
 */

private fun List<LabeledValue>.pairs(): List<Pair<String, String>> = map { it.value to it.label }

@Composable
private fun SectionTitle(text: String) = Text(text, style = MaterialTheme.typography.titleLarge)

@Composable
private fun SwitchRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit, enabled: Boolean = true, help: String? = null) {
    Column {
        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(label, modifier = Modifier.weight(1f))
            Switch(checked = checked, onCheckedChange = onChange, enabled = enabled, modifier = Modifier.semantics { contentDescription = label })
        }
        help?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

/* ——— Cosa ——— */

@Composable
internal fun WhatTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel, pickPoster: () -> Unit, pickGallery: () -> Unit) {
    val draft = state.draft
    val form = draft.base
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        PlainField(form.title, { v -> viewModel.editBase { it.copy(title = v) } }, stringResource(R.string.editorial_ee_title), error = errors.field("title"))
        PlainField(draft.subtitle, { v -> viewModel.edit { it.copy(subtitle = v) } }, stringResource(R.string.editorial_ee_subtitle), error = errors.field("subtitle"))
        PlainField(draft.slug, { v -> viewModel.edit { it.copy(slug = v) } }, stringResource(R.string.editorial_ee_slug), error = errors.field("slug"), help = stringResource(R.string.editorial_ee_slug_hint))
        PlainField(draft.shortDescription, { v -> viewModel.edit { it.copy(shortDescription = v) } }, stringResource(R.string.editorial_ee_short_description),
            error = errors.field("short_description"), help = stringResource(R.string.editorial_ee_short_description_hint, options.limits.shortDescription), minLines = 2)
        RichTextField(form.description, { v -> viewModel.editBase { it.copy(description = v) } }, stringResource(R.string.editorial_ee_description), error = errors.field("description"), minLines = 6)
        ChoiceField(stringResource(R.string.editorial_ee_category), options.categories.map { it.id.toString() to it.name }, form.categoryId?.toString(),
            { v -> viewModel.editBase { it.copy(categoryId = v?.toLongOrNull()) } }, error = errors.field("category_id"))
        TagsField(form.tags, state.tagResults, viewModel::searchTags, { v -> viewModel.editBase { it.copy(tags = v) } },
            onCreate = viewModel::createTag, error = errors.field("tags"), max = options.limits.tags)
        PlainField(draft.ageRestriction, { v -> viewModel.edit { it.copy(ageRestriction = v) } }, stringResource(R.string.editorial_ee_age_restriction), error = errors.field("age_restriction"))
        PlainField(draft.language, { v -> viewModel.edit { it.copy(language = v) } }, stringResource(R.string.editorial_ee_language), error = errors.field("language"),
            help = stringResource(R.string.editorial_ee_language_hint))

        SectionTitle(stringResource(R.string.editorial_ee_media))
        if (state.creating) {
            Text(stringResource(R.string.editorial_ee_images_after_create), color = Muted)
        } else {
            Text(stringResource(R.string.editorial_ee_poster), style = MaterialTheme.typography.titleSmall)
            val poster = state.poster?.card ?: state.poster?.full
            val posterLabel = stringResource(R.string.editorial_ee_poster_description)
            if (poster != null) PosterImage(poster, Modifier.size(width = 120.dp, height = 160.dp).semantics { contentDescription = posterLabel })
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = pickPoster, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (poster == null) R.string.editorial_ee_poster_pick else R.string.editorial_ee_poster_replace))
                }
                if (poster != null) TextButton(onClick = viewModel::removePoster, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_ee_poster_remove))
                }
            }
            Text(stringResource(R.string.editorial_ee_gallery), style = MaterialTheme.typography.titleSmall)
            state.gallery.forEachIndexed { index, photo ->
                val label = stringResource(R.string.editorial_ee_gallery_photo, index + 1)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PosterImage(photo.thumb ?: photo.card ?: photo.full, Modifier.size(64.dp).semantics { contentDescription = label })
                    Text(label, modifier = Modifier.weight(1f))
                    IconButton(onClick = { viewModel.moveGalleryPhoto(photo.id, -1) }, enabled = index > 0 && !state.busy, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.ArrowUpward, contentDescription = stringResource(R.string.editorial_ee_gallery_up))
                    }
                    IconButton(onClick = { viewModel.moveGalleryPhoto(photo.id, 1) }, enabled = index < state.gallery.lastIndex && !state.busy, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.ArrowDownward, contentDescription = stringResource(R.string.editorial_ee_gallery_down))
                    }
                    IconButton(onClick = { viewModel.removeGalleryPhoto(photo.id) }, enabled = !state.busy, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.editorial_ee_gallery_remove))
                    }
                }
            }
            OutlinedButton(onClick = pickGallery, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_gallery_add)) }
        }
        PlainField(draft.videoUrl, { v -> viewModel.edit { it.copy(videoUrl = v) } }, stringResource(R.string.editorial_ee_video_url), error = errors.field("video_url"),
            help = stringResource(R.string.editorial_ee_video_url_hint), keyboard = KeyboardType.Uri)
    }
}

/* ——— Quando ——— */

@Composable
internal fun WhenTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel, links: EditorLinks) {
    val draft = state.draft
    val zone = state.zone
    val city = state.cityName
    var confirmGenerate by rememberSaveable { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        SectionTitle(stringResource(R.string.editorial_ee_dates))
        if (state.creating) {
            Text(stringResource(R.string.editorial_ee_dates_hint, city ?: zone.id), color = Muted)
            RepeaterField(draft.newDates, { v -> viewModel.edit { it.copy(newDates = v) } }, stringResource(R.string.editorial_ee_date_add), options.limits.newDates, { NewDateRow() },
                error = state.errors.field("occurrences")) { row, update ->
                CityDateTimeField(stringResource(R.string.editorial_ee_starts_at), row.startsAt, zone, city, { update(row.copy(startsAt = it)) })
                CityDateTimeField(stringResource(R.string.editorial_ee_ends_at), row.endsAt, zone, city, { update(row.copy(endsAt = it)) }, clearable = true)
                CityDateTimeField(stringResource(R.string.editorial_ee_doors_at), row.doorsAt, zone, city, { update(row.copy(doorsAt = it)) }, clearable = true)
                SwitchRow(stringResource(R.string.editorial_ee_all_day), row.allDay, { update(row.copy(allDay = it)) })
            }
        } else {
            Text(stringResource(R.string.editorial_ee_dates_after_create), color = Muted)
            if (state.values?.abilities?.dates == true) {
                OutlinedButton(onClick = { links.onDates(state.values.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_open_dates)) }
            }
        }

        SectionTitle(stringResource(R.string.editorial_ee_recurrences))
        Text(stringResource(R.string.editorial_ee_recurrences_hint), color = Muted)
        RepeaterField(draft.recurrences, { v -> viewModel.edit { it.copy(recurrences = v) } }, stringResource(R.string.editorial_ee_recurrence_add), 20, { RecurrenceRow() },
            error = state.errors.field("recurrences")) { row, update ->
            PlainField(row.rrule, { update(row.copy(rrule = it)) }, stringResource(R.string.editorial_ee_rrule))
            CityDateTimeField(stringResource(R.string.editorial_ee_until), row.until, zone, city, { update(row.copy(until = it)) }, clearable = true)
            PlainField(row.exdates, { update(row.copy(exdates = it)) }, stringResource(R.string.editorial_ee_exdates), help = stringResource(R.string.editorial_ee_exdates_hint), minLines = 3)
            row.generatedUntil?.let { generated ->
                cityLocalDateTime(generated, zone)?.let { Text(stringResource(R.string.editorial_ee_generated_until, it.format(DATE_TIME)), color = Muted) }
            }
        }
        if (state.values?.abilities?.generate == true) {
            OutlinedButton(onClick = { confirmGenerate = true }, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_ee_generate))
            }
        }
    }
    if (confirmGenerate) {
        AlertDialog(
            onDismissRequest = { confirmGenerate = false },
            title = { Text(stringResource(R.string.editorial_ee_generate)) },
            text = { Text(stringResource(R.string.editorial_ee_generate_confirm)) },
            confirmButton = { TextButton(onClick = { confirmGenerate = false; viewModel.generate() }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_generate)) } },
            dismissButton = { TextButton(onClick = { confirmGenerate = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_cancel)) } },
        )
    }
}

/* ——— Dove ——— */

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun WhereTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel) {
    val draft = state.draft
    val form = draft.base
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        ChoiceField(stringResource(R.string.editorial_ee_city), options.cities.map { it.id.toString() to it.name }, form.cityId?.toString(),
            { v -> viewModel.selectCity(v?.toLongOrNull()) }, error = errors.field("city_id"))
        VenuePickerField(stringResource(R.string.editorial_ee_venue), form.venue, state.venueResults.map { it.id to listOfNotNull(it.name, it.address).joinToString(" · ") },
            { q -> viewModel.searchVenues(q) }, { v -> viewModel.selectVenue(v?.let { NamedRef(it.id, state.venueResults.firstOrNull { r -> r.id == it.id }?.name ?: it.name) }) },
            error = errors.field("venue_id"), help = stringResource(R.string.editorial_ee_venue_hint), allowNone = true)
        if (form.venue == null) {
            Text(stringResource(R.string.editorial_ee_place), style = MaterialTheme.typography.titleSmall)
            PlainField(form.locationName, { v -> viewModel.editBase { it.copy(locationName = v) } }, stringResource(R.string.editorial_ee_place_name), error = errors.field("custom_location"))
            PlainField(form.locationAddress, { v -> viewModel.editBase { it.copy(locationAddress = v) } }, stringResource(R.string.editorial_ee_place_address))
            PlainField(form.locationLat, { v -> viewModel.editBase { it.copy(locationLat = v) } }, stringResource(R.string.editorial_ee_lat), keyboard = KeyboardType.Decimal)
            PlainField(form.locationLng, { v -> viewModel.editBase { it.copy(locationLng = v) } }, stringResource(R.string.editorial_ee_lng), keyboard = KeyboardType.Decimal)
        }
        SwitchRow(stringResource(R.string.editorial_ee_outdoor), draft.isOutdoor, { v -> viewModel.edit { it.copy(isOutdoor = v) } })

        SectionTitle(stringResource(R.string.editorial_ee_organizer_section))
        VenuePickerField(stringResource(R.string.editorial_ee_organizer), draft.organizer, state.organizerResults.map { it.id to it.name }, viewModel::searchOrganizers,
            { v -> viewModel.edit { it.copy(organizer = v?.let { ref -> NamedRef(ref.id, state.organizerResults.firstOrNull { o -> o.id == ref.id }?.name ?: ref.name) }) } },
            error = errors.field("organizer_id"), help = stringResource(R.string.editorial_ee_organizer_hint), allowNone = true)
        Text(stringResource(R.string.editorial_ee_co_organizers), style = MaterialTheme.typography.titleSmall)
        Text(stringResource(R.string.editorial_ee_co_organizers_hint), color = Muted)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            draft.coOrganizers.forEach { org ->
                InputChip(
                    selected = true,
                    onClick = { viewModel.edit { it.copy(coOrganizers = it.coOrganizers - org) } },
                    label = { Text(org.name) },
                    trailingIcon = { Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.editorial_ee_remove, org.name)) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        VenuePickerField(stringResource(R.string.editorial_ee_co_organizer_add), null, state.organizerResults.filter { r -> draft.coOrganizers.none { it.id == r.id } }.map { it.id to it.name },
            viewModel::searchOrganizers, { v ->
                v?.let { ref -> viewModel.edit { it.copy(coOrganizers = it.coOrganizers + NamedRef(ref.id, state.organizerResults.firstOrNull { o -> o.id == ref.id }?.name ?: ref.name)) } }
            }, error = errors.field("organizers"))
        PlainField(form.organizerName, { v -> viewModel.editBase { it.copy(organizerName = v) } }, stringResource(R.string.editorial_ee_organizer_name), error = errors.field("organizer_name"))
        PlainField(form.organizerUrl, { v -> viewModel.editBase { it.copy(organizerUrl = v) } }, stringResource(R.string.editorial_ee_organizer_url), error = errors.field("organizer_url"), keyboard = KeyboardType.Uri)
    }
}

/* ——— Biglietti ——— */

@Composable
internal fun TicketsTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel, links: EditorLinks) {
    val draft = state.draft
    val form = draft.base
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        ChoiceField(stringResource(R.string.editorial_ee_price_type), options.priceTypes.pairs(), form.priceType, { v -> viewModel.editBase { it.copy(priceType = v ?: "unknown") } },
            error = errors.field("price_type"))
        if (draft.hasAmount) {
            PlainField(form.priceMin, { v -> viewModel.editBase { it.copy(priceMin = v) } }, stringResource(R.string.editorial_ee_price_min), error = errors.field("price_min"), keyboard = KeyboardType.Decimal)
            PlainField(form.priceMax, { v -> viewModel.editBase { it.copy(priceMax = v) } }, stringResource(R.string.editorial_ee_price_max), error = errors.field("price_max"), keyboard = KeyboardType.Decimal)
        }
        PlainField(draft.priceNotes, { v -> viewModel.edit { it.copy(priceNotes = v) } }, stringResource(R.string.editorial_ee_price_notes), error = errors.field("price_notes"))
        if (draft.hasAmount) PlainField(draft.currency, { v -> viewModel.edit { it.copy(currency = v) } }, stringResource(R.string.editorial_ee_currency), error = errors.field("currency"))
        PlainField(form.ticketUrl, { v -> viewModel.editBase { it.copy(ticketUrl = v) } }, stringResource(R.string.editorial_ee_ticket_url), error = errors.field("ticket_url"), keyboard = KeyboardType.Uri)
        SwitchRow(stringResource(R.string.editorial_ee_booking_required), draft.bookingRequired, { v -> viewModel.edit { it.copy(bookingRequired = v) } })
        if (draft.bookingRequired) {
            PlainField(form.bookingUrl, { v -> viewModel.editBase { it.copy(bookingUrl = v) } }, stringResource(R.string.editorial_ee_booking_url), error = errors.field("booking_url"), keyboard = KeyboardType.Uri)
            PlainField(draft.bookingPhone, { v -> viewModel.edit { it.copy(bookingPhone = v) } }, stringResource(R.string.editorial_ee_booking_phone), error = errors.field("booking_phone"), keyboard = KeyboardType.Phone)
        }
        if (form.priceType in setOf("free", "ticket", "membership")) {
            SectionTitle(stringResource(R.string.editorial_ee_ticketing))
            Text(stringResource(R.string.editorial_ee_ticketing_hint), color = Muted)
            val values = state.values
            if (values == null || values.dateRefs.isEmpty()) {
                Text(stringResource(R.string.editorial_ee_ticketing_no_dates), color = Muted)
            } else {
                if (!values.ticketingEnabled) Text(stringResource(R.string.editorial_ee_ticketing_disabled), color = Muted)
                values.dateRefs.forEach { date ->
                    val path = date.ticketingPath ?: return@forEach
                    OutlinedButton(onClick = { links.onOpenInApp(path) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.editorial_ee_ticketing_manage, date.startsAt?.let { formatEventDate(it, state.zone) }.orEmpty()))
                    }
                }
            }
        }
        if (draft.hasAmount) {
            SectionTitle(stringResource(R.string.editorial_ee_tiers))
            Text(stringResource(R.string.editorial_ee_tiers_hint), color = Muted)
            RepeaterField(form.tiers, { v -> viewModel.editBase { it.copy(tiers = v) } }, stringResource(R.string.management_events_tier_add), options.limits.ticketTiers, { TierRow() },
                error = errors.field("ticket_tiers")) { row, update ->
                PlainField(row.name, { update(row.copy(name = it)) }, stringResource(R.string.management_events_tier_name))
                PlainField(row.price, { update(row.copy(price = it)) }, stringResource(R.string.management_events_tier_price), keyboard = KeyboardType.Decimal)
                ChoiceChips(stringResource(R.string.management_events_tier_status), options.ticketTierStatuses.pairs(), row.status, { update(row.copy(status = it ?: "available")) })
                PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.management_events_tier_url), keyboard = KeyboardType.Uri)
                PlainField(row.note, { update(row.copy(note = it)) }, stringResource(R.string.management_events_tier_note))
            }
        }
    }
}

/* ——— Contenuti e SEO ——— */

private val EDITORIAL_TEXT_LABELS = listOf(
    "parking_notes" to R.string.management_events_ed_parking_notes,
    "transit_notes" to R.string.management_events_ed_transit_notes,
    "entrance_notes" to R.string.management_events_ed_entrance_notes,
)

private val POLICY_LABELS = listOf(
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

@Composable
internal fun ContentTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel) {
    val draft = state.draft
    val form = draft.base
    val errors = state.errors
    val zone = state.zone
    fun set(key: String, value: String) = viewModel.editBase { it.copy(editorial = it.editorial + (key to value)) }
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        SectionTitle(stringResource(R.string.editorial_ee_information))
        RichTextField(form.editorial["introduction"].orEmpty(), { set("introduction", it) }, stringResource(R.string.management_events_ed_introduction), minLines = 3)
        ChoiceField(stringResource(R.string.management_events_ed_parking_type), options.parkingTypes.pairs(), form.editorial["parking_type"]?.ifBlank { null }, { set("parking_type", it.orEmpty()) }, allowNone = true)
        EDITORIAL_TEXT_LABELS.forEach { (key, label) -> RichTextField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(label), minLines = 2, toolbar = false) }
        VenuePickerField(stringResource(R.string.management_events_ed_organizer_venue), form.organizerVenue, state.approvedVenueResults.map { it.id to it.name },
            { q -> viewModel.searchVenues(q, approved = true) }, { v -> viewModel.editBase { it.copy(organizerVenue = v) } }, allowNone = true)
        PlainField(form.editorial["minimum_age"].orEmpty(), { set("minimum_age", it) }, stringResource(R.string.management_events_ed_minimum_age), keyboard = KeyboardType.Number,
            error = errors.field("content_details.minimum_age"))
        ChoiceField(stringResource(R.string.management_events_ed_attendance_mode), options.attendanceModes.pairs(), form.editorial["attendance_mode"]?.ifBlank { null }, { set("attendance_mode", it.orEmpty()) }, allowNone = true)
        if (form.editorial["attendance_mode"] == "online" || form.editorial["attendance_mode"] == "mixed") {
            PlainField(form.editorial["online_url"].orEmpty(), { set("online_url", it) }, stringResource(R.string.management_events_ed_online_url), keyboard = KeyboardType.Uri,
                error = errors.field("content_details.online_url"))
        }
        ChoiceField(stringResource(R.string.management_events_ed_organizer_type), options.organizerTypes.pairs(), form.editorial["organizer_type"]?.ifBlank { null }, { set("organizer_type", it.orEmpty()) }, allowNone = true)
        POLICY_LABELS.forEach { (key, label) ->
            if (key in EventForm.EDITORIAL_PLAIN_KEYS) PlainField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(label), minLines = 2)
            else RichTextField(form.editorial[key].orEmpty(), { set(key, it) }, stringResource(label), minLines = 2, toolbar = false)
        }
        Text(stringResource(R.string.management_events_agenda), style = MaterialTheme.typography.titleSmall)
        RepeaterField(form.agenda, { v -> viewModel.editBase { it.copy(agenda = v) } }, stringResource(R.string.management_events_agenda_add), options.limits.agenda, { AgendaRow() },
            error = errors.field("content_details.agenda")) { row, update ->
            PlainField(row.title, { update(row.copy(title = it)) }, stringResource(R.string.management_events_agenda_title))
            PlainField(row.whenText, { update(row.copy(whenText = it)) }, stringResource(R.string.management_events_agenda_when))
            CityDateTimeField(stringResource(R.string.management_dates_agenda_starts), AgendaTime.toCity(row.startsAt, zone), zone, state.cityName,
                onChange = { update(row.copy(startsAt = AgendaTime.fromCity(it, zone))) }, clearable = true)
            CityDateTimeField(stringResource(R.string.management_dates_agenda_ends), AgendaTime.toCity(row.endsAt, zone), zone, state.cityName,
                onChange = { update(row.copy(endsAt = AgendaTime.fromCity(it, zone))) }, clearable = true)
            val dates = state.values?.dateRefs.orEmpty()
            if (dates.isNotEmpty() || row.occurrenceId != null) {
                ChoiceField(stringResource(R.string.management_dates_agenda_date), dates.map { it.id.toString() to (it.startsAt?.let { iso -> formatEventDate(iso, zone) } ?: "#${it.id}") },
                    row.occurrenceId?.toString(), { update(row.copy(occurrenceId = it?.toLongOrNull())) }, allowNone = true, noneLabel = stringResource(R.string.management_dates_agenda_all_dates))
            }
            PlainField(row.speaker, { update(row.copy(speaker = it)) }, stringResource(R.string.management_events_agenda_speaker))
            RichTextField(row.description, { update(row.copy(description = it)) }, stringResource(R.string.management_events_agenda_description), minLines = 2, toolbar = false)
        }
        Text(stringResource(R.string.management_events_faqs), style = MaterialTheme.typography.titleSmall)
        RepeaterField(form.faqs, { v -> viewModel.editBase { it.copy(faqs = v) } }, stringResource(R.string.management_events_faq_add), options.limits.faqs, { FaqRow() },
            error = errors.field("content_details.faqs")) { row, update ->
            PlainField(row.question, { update(row.copy(question = it)) }, stringResource(R.string.management_events_faq_question))
            RichTextField(row.answer, { update(row.copy(answer = it)) }, stringResource(R.string.management_events_faq_answer), minLines = 2, toolbar = false)
        }

        SectionTitle(stringResource(R.string.editorial_ee_seo))
        PlainField(form.seoTitle, { v -> viewModel.editBase { it.copy(seoTitle = v) } }, stringResource(R.string.editorial_ee_seo_title), error = errors.field("seo"))
        PlainField(form.seoDescription, { v -> viewModel.editBase { it.copy(seoDescription = v) } }, stringResource(R.string.editorial_ee_seo_description), minLines = 3)
        PlainField(form.seoImage, { v -> viewModel.editBase { it.copy(seoImage = v) } }, stringResource(R.string.editorial_ee_seo_image), keyboard = KeyboardType.Uri)
        if (options.can.admin) {
            ChoiceChips(stringResource(R.string.editorial_ee_seo_indexing), options.seoIndexing.pairs(), draft.seoIndexing, { v -> viewModel.edit { it.copy(seoIndexing = v ?: "automatic") } })
            SwitchRow(stringResource(R.string.editorial_ee_demo), draft.isDemo, { v -> viewModel.edit { it.copy(isDemo = v) } }, help = stringResource(R.string.editorial_ee_demo_hint))
        }
    }
}

/* ——— Extra ——— */

@Composable
internal fun ExtraTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel) {
    val form = state.draft.base
    val errors = state.errors
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        SectionTitle(stringResource(R.string.editorial_ee_facts))
        Text(stringResource(R.string.editorial_ee_facts_hint, options.limits.facts), color = Muted)
        RepeaterField(form.facts, { v -> viewModel.editBase { it.copy(facts = v) } }, stringResource(R.string.management_events_fact_add), options.limits.facts, { FactRow() },
            error = errors.field("facts")) { row, update ->
            PlainField(row.label, { update(row.copy(label = it)) }, stringResource(R.string.management_events_fact_label))
            PlainField(row.value, { update(row.copy(value = it)) }, stringResource(R.string.management_events_fact_value))
        }
        SectionTitle(stringResource(R.string.editorial_ee_links))
        Text(stringResource(R.string.editorial_ee_links_hint, options.limits.externalLinks), color = Muted)
        RepeaterField(form.externalLinks, { v -> viewModel.editBase { it.copy(externalLinks = v) } }, stringResource(R.string.management_events_add_link), options.limits.externalLinks, { LinkRow() },
            error = errors.field("external_links")) { row, update ->
            PlainField(row.label, { update(row.copy(label = it)) }, stringResource(R.string.management_events_link_label))
            PlainField(row.url, { update(row.copy(url = it)) }, stringResource(R.string.management_events_link_url), keyboard = KeyboardType.Uri)
        }
    }
}

/* ——— Pubblicazione ——— */

private val DATE_TIME: DateTimeFormatter = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)

@Composable
internal fun PublishTab(state: EventEditorUiState, options: EditorEventOptions, viewModel: EventEditorViewModel) {
    val draft = state.draft
    val errors = state.errors
    val zone = state.zone
    val values = state.values
    var scheduling by rememberSaveable { mutableStateOf(false) }
    var scheduleAt by rememberSaveable { mutableStateOf<String?>(null) }
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        ChoiceChips(stringResource(R.string.editorial_ee_status), options.statuses.pairs(), draft.status, { v -> viewModel.edit { it.copy(status = v ?: "draft") } }, error = errors.field("status"))
        if (values != null) {
            OutlinedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    val scheduled = cityLocalDateTime(values.scheduledPublishAt, zone)
                    Text(
                        if (scheduled != null) stringResource(R.string.editorial_ee_scheduled, scheduled.format(DATE_TIME)) else stringResource(R.string.editorial_ee_not_scheduled),
                    )
                    if (scheduled != null && values.status.value == "pending") Text(stringResource(R.string.editorial_ee_scheduled_pending), color = Muted)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (values.abilities.schedule) OutlinedButton(onClick = { scheduling = true }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                            Text(stringResource(R.string.editorial_ee_schedule))
                        }
                        if (values.abilities.unschedule) TextButton(onClick = viewModel::unschedule, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                            Text(stringResource(R.string.editorial_ee_unschedule))
                        }
                    }
                }
            }
        }
        ChoiceField(stringResource(R.string.editorial_ee_source), options.sources.pairs(), draft.source, { v -> viewModel.edit { it.copy(source = v ?: "manual") } }, error = errors.field("source"))
        ChoiceField(stringResource(R.string.editorial_ee_verification), options.verificationStatuses.pairs(), draft.verificationStatus,
            { v -> viewModel.edit { it.copy(verificationStatus = v ?: "unverified") } }, error = errors.field("verification_status"))
        Text(stringResource(R.string.editorial_ee_verification_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
        PlainField(draft.editorialScore, { v -> viewModel.edit { it.copy(editorialScore = v) } }, stringResource(R.string.editorial_ee_score), error = errors.field("editorial_score"),
            help = stringResource(R.string.editorial_ee_score_hint), keyboard = KeyboardType.Number)
        SwitchRow(stringResource(R.string.editorial_ee_featured), draft.isFeatured, { v -> viewModel.edit { it.copy(isFeatured = v) } }, enabled = options.can.feature,
            help = if (options.can.feature) null else stringResource(R.string.editorial_ee_featured_locked))
        if (draft.isFeatured) {
            CityDateTimeField(stringResource(R.string.editorial_ee_featured_until), draft.featuredUntil, zone, state.cityName, { v -> viewModel.edit { it.copy(featuredUntil = v) } },
                error = errors.field("featured_until"), enabled = options.can.feature, clearable = true)
        }
        PlainField(draft.rejectionReason, { v -> viewModel.edit { it.copy(rejectionReason = v) } }, stringResource(R.string.editorial_ee_rejection_reason),
            error = errors.field("rejection_reason"), minLines = 2)
    }
    if (scheduling) {
        val chosen = scheduleAt?.let { runCatching { LocalDateTime.parse(it) }.getOrNull() }
        AlertDialog(
            onDismissRequest = { scheduling = false },
            title = { Text(stringResource(R.string.editorial_ee_schedule)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stringResource(R.string.editorial_ee_schedule_hint), color = Muted)
                    CityDateTimeField(stringResource(R.string.editorial_ee_schedule_at), chosen, zone, state.cityName, { v -> scheduleAt = v?.toString() })
                }
            },
            confirmButton = {
                TextButton(enabled = chosen != null, onClick = { chosen?.let { viewModel.schedule(cityIso(it, zone)) }; scheduling = false }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_ee_schedule_confirm))
                }
            },
            dismissButton = { TextButton(onClick = { scheduling = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_ee_cancel)) } },
        )
    }
}
