package it.fabiodalez.incitta.ui.management.profile

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ClosureDraft
import it.fabiodalez.incitta.data.management.ContactSettings
import it.fabiodalez.incitta.data.management.FactDraft
import it.fabiodalez.incitta.data.management.FaqDraft
import it.fabiodalez.incitta.data.management.HourDraft
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.management.PracticalDraft
import it.fabiodalez.incitta.data.management.SocialDraft
import it.fabiodalez.incitta.data.management.TransitDraft
import it.fabiodalez.incitta.data.management.VenueProfile
import it.fabiodalez.incitta.data.management.VenueProfileDraft
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.resolve

/*
 * Il contenuto di ogni sezione della scheda del locale, campo per campo come
 * la pagina «Il tuo locale». Le scelte chiuse vengono dal server (`choices`
 * della scheda, `GET /management/options`), mai scritte qui a mano.
 */

@Composable
internal fun VenueSectionFields(
    section: VenueSection,
    profile: VenueProfile,
    draft: VenueProfileDraft,
    options: ManagementOptions,
    errors: FormErrors,
    busy: Boolean,
    onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit,
    onResendContact: () -> Unit,
) {
    when (section) {
        VenueSection.Description -> {
            CountedTextField(draft.shortDescription, { v -> onEdit { it.copy(shortDescription = v) } }, stringResource(R.string.profile_short_description),
                profile.limit("short_description", 500), errors, "short_description", stringResource(R.string.profile_short_description_help), minLines = 2)
            RichTextField(draft.description, { v -> onEdit { it.copy(description = v) } }, stringResource(R.string.profile_description), errors, "description", minLines = 8)
        }
        VenueSection.Where -> WhereFields(profile, draft, errors, onEdit)
        VenueSection.Hours -> HoursFields(profile, draft, errors, onEdit)
        VenueSection.Contacts -> ContactsFields(profile, draft, errors, onEdit)
        VenueSection.Transit -> {
            val max = profile.limit("transit", 6)
            Text(stringResource(R.string.profile_transit_help, max), color = Muted)
            errors.field("transit")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            val modes = options.choices("transit_modes")
            draft.transit.forEachIndexed { index, row ->
                val title = stringResource(R.string.profile_transit_row, index + 1)
                RowHeader(title, index, draft.transit.size, { d -> onEdit { it.copy(transit = it.transit.moved(index, d)) } }, { onEdit { it.copy(transit = it.transit.without(index)) } })
                ChoiceField(stringResource(R.string.profile_transit_mode), modes, row.mode, { v -> onEdit { it.copy(transit = it.transit.replaced(index, row.copy(mode = v))) } })
                OutlinedTextField(row.text, { v -> onEdit { it.copy(transit = it.transit.replaced(index, row.copy(text = v))) } }, label = { Text(stringResource(R.string.profile_transit_text)) },
                    supportingText = { Text(stringResource(R.string.profile_counter, row.text.length, profile.limit("transit_text", 200))) }, modifier = Modifier.fillMaxWidth())
                HorizontalDivider()
            }
            AddRowButton(stringResource(R.string.profile_transit_add), draft.transit.size, max) {
                onEdit { it.copy(transit = it.transit + TransitDraft(mode = modes.firstOrNull()?.value ?: "bus")) }
            }
        }
        VenueSection.Accessibility -> {
            Text(stringResource(R.string.profile_accessibility_help), color = Muted)
            errors.field("accessibility")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            options.choices("accessibility_features").forEach { feature ->
                TriStateField(feature.label, draft.accessibility[feature.value], { value ->
                    onEdit { it.copy(accessibility = if (value == null) it.accessibility - feature.value else it.accessibility + (feature.value to value)) }
                })
            }
        }
        VenueSection.Card -> {
            val max = profile.limit("info", 12)
            Text(stringResource(R.string.profile_info), style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.profile_info_help, max), color = Muted)
            errors.field("info")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            draft.info.forEachIndexed { index, row ->
                val title = stringResource(R.string.profile_info_row, index + 1)
                RowHeader(title, index, draft.info.size, { d -> onEdit { it.copy(info = it.info.moved(index, d)) } }, { onEdit { it.copy(info = it.info.without(index)) } })
                OutlinedTextField(row.label, { v -> onEdit { it.copy(info = it.info.replaced(index, row.copy(label = v))) } }, label = { Text(stringResource(R.string.profile_info_label)) },
                    singleLine = true, supportingText = { Text(stringResource(R.string.profile_counter, row.label.length, profile.limit("info_label", 40))) }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(row.value, { v -> onEdit { it.copy(info = it.info.replaced(index, row.copy(value = v))) } }, label = { Text(stringResource(R.string.profile_info_value)) },
                    supportingText = { Text(stringResource(R.string.profile_counter, row.value.length, profile.limit("info_value", 120))) }, modifier = Modifier.fillMaxWidth())
                HorizontalDivider()
            }
            AddRowButton(stringResource(R.string.profile_info_add), draft.info.size, max) { onEdit { it.copy(info = it.info + FactDraft()) } }
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.profile_requires_membership), modifier = Modifier.weight(1f))
                Switch(draft.requiresMembership, { v -> onEdit { it.copy(requiresMembership = v) } })
            }
            RichTextField(draft.membershipNotes, { v -> onEdit { it.copy(membershipNotes = v) } }, stringResource(R.string.profile_membership_notes), errors, "membership_notes", minLines = 3)
        }
        VenueSection.BeforeGoing -> BeforeGoingFields(profile, draft, options, errors, onEdit)
        VenueSection.Editorial -> EditorialFields(profile, draft, errors, onEdit)
        VenueSection.Seo -> {
            Text(stringResource(R.string.profile_seo_lead), color = Muted)
            CountedTextField(draft.seoTitle, { v -> onEdit { it.copy(seoTitle = v) } }, stringResource(R.string.profile_seo_title), profile.limit("seo_title", 180), errors, "seo.title", singleLine = true)
            CountedTextField(draft.seoDescription, { v -> onEdit { it.copy(seoDescription = v) } }, stringResource(R.string.profile_seo_description), profile.limit("seo_description", 400), errors, "seo.description", minLines = 3)
            FormTextField(draft.seoImage, { v -> onEdit { it.copy(seoImage = v) } }, stringResource(R.string.profile_seo_image), errors, "seo.image", singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
            errors.field("seo")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        }
        VenueSection.ContactForm -> ContactFormFields(profile.contact, draft.contactMode, draft.contactEmail, errors, busy,
            { v -> onEdit { it.copy(contactMode = v) } }, { v -> onEdit { it.copy(contactEmail = v) } }, onResendContact)
    }
}

@Composable
private fun WhereFields(profile: VenueProfile, draft: VenueProfileDraft, errors: FormErrors, onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit) {
    val address = listOf(draft.address, draft.municipality).filter { it.isNotBlank() }.joinToString(", ")
    VenueLocationPicker(draft.lat, draft.lng, address) { lat, lng -> onEdit { it.copy(lat = lat, lng = lng) } }
    listOf("lat", "lng").firstNotNullOfOrNull(errors::field)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    FormTextField(draft.address, { v -> onEdit { it.copy(address = v) } }, stringResource(R.string.profile_address), errors, "address", singleLine = true)
    FormTextField(draft.addressExtra, { v -> onEdit { it.copy(addressExtra = v) } }, stringResource(R.string.profile_address_extra), errors, "address_extra", singleLine = true,
        help = stringResource(R.string.profile_address_extra_help))
    FormTextField(draft.postalCode, { v -> onEdit { it.copy(postalCode = v) } }, stringResource(R.string.profile_postal_code), errors, "postal_code", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
    SearchableChoiceField(stringResource(R.string.profile_municipality), profile.choices.municipalities, draft.municipality,
        { v -> onEdit { it.copy(municipality = v, zone = if (v == profile.choices.districtMunicipality) it.zone else "") } }, errors.field("municipality"))
    if (draft.needsDistrict(profile.choices.districtMunicipality)) {
        ChoiceField(stringResource(R.string.profile_district), profile.choices.districts.map { OptionChoice(it, it) }, draft.zone, { v -> onEdit { it.copy(zone = v) } }, errors.field("zone"))
        Text(stringResource(R.string.profile_district_help), color = Muted)
    }
}

@Composable
private fun HoursFields(profile: VenueProfile, draft: VenueProfileDraft, errors: FormErrors, onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit) {
    val days = profile.choices.weekdays.asChoices()
    Text(stringResource(R.string.profile_hours), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.profile_hours_help), color = Muted)
    errors.fields["opening_hours"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.hours.forEachIndexed { index, row ->
        val title = stringResource(R.string.profile_hours_row, index + 1)
        RowHeader(title, index, draft.hours.size, { d -> onEdit { it.copy(hours = it.hours.moved(index, d)) } }, { onEdit { it.copy(hours = it.hours.without(index)) } })
        ChoiceField(stringResource(R.string.profile_hours_day), days, row.day, { v -> onEdit { it.copy(hours = it.hours.replaced(index, row.copy(day = v))) } })
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            HourField(stringResource(R.string.profile_hours_from), row.open, { v -> onEdit { it.copy(hours = it.hours.replaced(index, row.copy(open = v))) } }, Modifier.weight(1f))
            HourField(stringResource(R.string.profile_hours_to), row.close, { v -> onEdit { it.copy(hours = it.hours.replaced(index, row.copy(close = v))) } }, Modifier.weight(1f))
        }
        errors.field("opening_hours.$index")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        HorizontalDivider()
    }
    AddRowButton(stringResource(R.string.profile_hours_add), draft.hours.size, profile.limit("opening_hours", 28)) {
        /* La riga nuova riprende il giorno dopo e l'orario dell'ultima: si compila una settimana in pochi tocchi. */
        onEdit { it.copy(hours = it.hours + nextHourRow(it.hours)) }
    }

    Text(stringResource(R.string.profile_closures), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.profile_closures_help), color = Muted)
    errors.fields["closures"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.closures.forEachIndexed { index, row ->
        val title = stringResource(R.string.profile_closures_row, index + 1)
        RowHeader(title, index, draft.closures.size, { d -> onEdit { it.copy(closures = it.closures.moved(index, d)) } }, { onEdit { it.copy(closures = it.closures.without(index)) } })
        DayField(stringResource(R.string.profile_closures_from), row.from, { v -> onEdit { it.copy(closures = it.closures.replaced(index, row.copy(from = v, to = if (row.to.isBlank() || row.to < v) v else row.to))) } })
        DayField(stringResource(R.string.profile_closures_to), row.to, { v -> onEdit { it.copy(closures = it.closures.replaced(index, row.copy(to = v))) } })
        OutlinedTextField(row.note, { v -> onEdit { it.copy(closures = it.closures.replaced(index, row.copy(note = v))) } }, label = { Text(stringResource(R.string.profile_closures_note)) },
            placeholder = { Text(stringResource(R.string.profile_closures_note_hint)) }, singleLine = true,
            supportingText = { Text(stringResource(R.string.profile_counter, row.note.length, profile.limit("closure_note", 120))) }, modifier = Modifier.fillMaxWidth())
        errors.field("closures.$index")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        HorizontalDivider()
    }
    AddRowButton(stringResource(R.string.profile_closures_add), draft.closures.size, profile.limit("closures", 20)) { onEdit { it.copy(closures = it.closures + ClosureDraft()) } }

    FormTextField(draft.capacity, { v -> onEdit { it.copy(capacity = v.filter(Char::isDigit)) } }, stringResource(R.string.profile_capacity), errors, "capacity", singleLine = true,
        help = stringResource(R.string.profile_capacity_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
}

/** La fascia oraria da aggiungere: il giorno dopo l'ultima, con lo stesso orario. */
internal fun nextHourRow(rows: List<HourDraft>): HourDraft {
    val last = rows.lastOrNull() ?: return HourDraft()
    val week = listOf("mon", "tue", "wed", "thu", "fri", "sat", "sun")
    val next = week.getOrNull((week.indexOf(last.day) + 1) % week.size) ?: "mon"
    return HourDraft(next, last.open, last.close)
}

@Composable
private fun ContactsFields(profile: VenueProfile, draft: VenueProfileDraft, errors: FormErrors, onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit) {
    FormTextField(draft.phone, { v -> onEdit { it.copy(phone = v) } }, stringResource(R.string.profile_phone), errors, "phone", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
    FormTextField(draft.email, { v -> onEdit { it.copy(email = v) } }, stringResource(R.string.profile_email), errors, "email", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
    FormTextField(draft.website, { v -> onEdit { it.copy(website = v) } }, stringResource(R.string.profile_website), errors, "website", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
    Text(stringResource(R.string.profile_socials), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.profile_socials_help), color = Muted)
    errors.fields["socials"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.socials.forEachIndexed { index, row ->
        val title = stringResource(R.string.profile_social_row, index + 1)
        RowHeader(title, index, draft.socials.size, { d -> onEdit { it.copy(socials = it.socials.moved(index, d)) } }, { onEdit { it.copy(socials = it.socials.without(index)) } })
        OutlinedTextField(row.name, { v -> onEdit { it.copy(socials = it.socials.replaced(index, row.copy(name = v))) } }, label = { Text(stringResource(R.string.profile_social_name)) },
            singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(row.url, { v -> onEdit { it.copy(socials = it.socials.replaced(index, row.copy(url = v))) } }, label = { Text(stringResource(R.string.profile_social_url)) },
            singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
        errors.field("socials.$index")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        HorizontalDivider()
    }
    AddRowButton(stringResource(R.string.profile_socials_add), draft.socials.size, profile.limit("socials", 12)) { onEdit { it.copy(socials = it.socials + SocialDraft()) } }
}

@Composable
private fun BeforeGoingFields(profile: VenueProfile, draft: VenueProfileDraft, options: ManagementOptions, errors: FormErrors, onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit) {
    Text(stringResource(R.string.profile_before_lead), color = Muted)
    Text(stringResource(R.string.profile_before_ages), style = MaterialTheme.typography.titleSmall)
    @OptIn(ExperimentalLayoutApi::class)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.choices("age_groups").forEach { age ->
            val on = age.value in draft.ageGroups
            FilterChip(on, { onEdit { it.copy(ageGroups = if (on) it.ageGroups - age.value else it.ageGroups + age.value) } }, label = { Text(age.label) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
    errors.field("content_details.age_groups")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    YesNoField(stringResource(R.string.profile_before_stroller), draft.stroller, { v -> onEdit { it.copy(stroller = v) } })
    YesNoField(stringResource(R.string.profile_before_changing_table), draft.changingTable, { v -> onEdit { it.copy(changingTable = v) } })
    YesNoField(stringResource(R.string.profile_before_kids_area), draft.kidsArea, { v -> onEdit { it.copy(kidsArea = v) } })
    ChoiceField(stringResource(R.string.profile_before_membership), options.choices("membership_requirements"), draft.membership, { v -> onEdit { it.copy(membership = v) } },
        errors.field("content_details.membership"), emptyLabel = stringResource(R.string.profile_unspecified))
    YesNoField(stringResource(R.string.profile_before_wheelchair), draft.wheelchair, { v -> onEdit { it.copy(wheelchair = v) } },
        yes = stringResource(R.string.profile_before_accessible), no = stringResource(R.string.profile_before_not_accessible))
    RichTextField(draft.beforeMembershipNotes, { v -> onEdit { it.copy(beforeMembershipNotes = v) } }, stringResource(R.string.profile_before_membership_notes), errors, "content_details.membership_notes", minLines = 3)
    RichTextField(draft.accessibilityNotes, { v -> onEdit { it.copy(accessibilityNotes = v) } }, stringResource(R.string.profile_before_accessibility_notes), errors, "content_details.accessibility_notes", minLines = 3)

    FeaturePicker(options.choices("event_features"), draft.featureIds, { ids -> onEdit { it.copy(featureIds = ids) } }, errors.field("content_details.feature_ids"))

    val max = profile.limit("practical_custom", 12)
    val icons = options.choices("practical_icons")
    Text(stringResource(R.string.profile_before_custom), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.profile_before_custom_help), color = Muted)
    errors.fields["content_details.practical_custom"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.practical.forEachIndexed { index, row ->
        val title = row.label.ifBlank { stringResource(R.string.profile_before_custom_row, index + 1) }
        RowHeader(title, index, draft.practical.size, { d -> onEdit { it.copy(practical = it.practical.moved(index, d)) } }, { onEdit { it.copy(practical = it.practical.without(index)) } })
        OutlinedTextField(row.label, { v -> onEdit { it.copy(practical = it.practical.replaced(index, row.copy(label = v))) } }, label = { Text(stringResource(R.string.profile_before_custom_label)) },
            singleLine = true, modifier = Modifier.fillMaxWidth())
        ChoiceField(stringResource(R.string.profile_before_custom_icon), icons, row.icon, { v -> onEdit { it.copy(practical = it.practical.replaced(index, row.copy(icon = v))) } })
        RichTextField(row.text, { v -> onEdit { it.copy(practical = it.practical.replaced(index, row.copy(text = v))) } }, stringResource(R.string.profile_before_custom_text), errors,
            "content_details.practical_custom.$index", minLines = 2)
        HorizontalDivider()
    }
    AddRowButton(stringResource(R.string.profile_before_custom_add), draft.practical.size, max) { onEdit { it.copy(practical = it.practical + PracticalDraft()) } }

    CountedTextField(draft.foodNotes, { v -> onEdit { it.copy(foodNotes = v) } }, stringResource(R.string.profile_before_food), 2000, errors, "content_details.food_notes", minLines = 3)
    CountedTextField(draft.startNotes, { v -> onEdit { it.copy(startNotes = v) } }, stringResource(R.string.profile_before_start), 2000, errors, "content_details.start_notes", minLines = 3)
}

/** Le caratteristiche del catalogo: si cerca per nome e si tocca per scegliere. */
@Composable
private fun FeaturePicker(features: List<OptionChoice>, selected: List<Long>, onChange: (List<Long>) -> Unit, error: String?) {
    var query by rememberSaveable { mutableStateOf("") }
    Text(stringResource(R.string.profile_before_features), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.profile_before_features_help), color = Muted)
    OutlinedTextField(query, { query = it }, label = { Text(stringResource(R.string.profile_before_features_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
    val needle = query.trim().lowercase()
    val chosen = features.filter { it.value.toLongOrNull() in selected }
    val shown = (chosen + features.filter { needle.isNotEmpty() && it.label.lowercase().contains(needle) }).distinctBy { it.value }.ifEmpty { features.take(12) }
    @OptIn(ExperimentalLayoutApi::class)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        shown.forEach { feature ->
            val id = feature.value.toLongOrNull() ?: return@forEach
            val on = id in selected
            FilterChip(on, { onChange(if (on) selected - id else selected + id) }, label = { Text(feature.label) }, modifier = Modifier.heightIn(min = 48.dp))
        }
    }
    error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
}

@Composable
private fun EditorialFields(profile: VenueProfile, draft: VenueProfileDraft, errors: FormErrors, onEdit: ((VenueProfileDraft) -> VenueProfileDraft) -> Unit) {
    Text(stringResource(R.string.profile_editorial_lead), color = Muted)
    RichTextField(draft.introduction, { v -> onEdit { it.copy(introduction = v) } }, stringResource(R.string.profile_editorial_intro), errors, "content_details.introduction")
    ChoiceField(stringResource(R.string.profile_editorial_parking), profile.choices.parkingTypes.asChoices(), draft.parkingType, { v -> onEdit { it.copy(parkingType = v) } },
        errors.field("content_details.parking_type"), emptyLabel = stringResource(R.string.profile_unspecified))
    RichTextField(draft.parkingNotes, { v -> onEdit { it.copy(parkingNotes = v) } }, stringResource(R.string.profile_editorial_parking_notes), errors, "content_details.parking_notes", minLines = 3)
    RichTextField(draft.transitNotes, { v -> onEdit { it.copy(transitNotes = v) } }, stringResource(R.string.profile_editorial_transit_notes), errors, "content_details.transit_notes", minLines = 3)
    RichTextField(draft.entranceNotes, { v -> onEdit { it.copy(entranceNotes = v) } }, stringResource(R.string.profile_editorial_entrance_notes), errors, "content_details.entrance_notes", minLines = 3)
    Text(stringResource(R.string.profile_editorial_faqs), style = MaterialTheme.typography.titleMedium)
    errors.fields["content_details.faqs"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.faqs.forEachIndexed { index, row ->
        val title = stringResource(R.string.profile_editorial_faq_row, index + 1)
        RowHeader(title, index, draft.faqs.size, { d -> onEdit { it.copy(faqs = it.faqs.moved(index, d)) } }, { onEdit { it.copy(faqs = it.faqs.without(index)) } })
        OutlinedTextField(row.question, { v -> onEdit { it.copy(faqs = it.faqs.replaced(index, row.copy(question = v))) } }, label = { Text(stringResource(R.string.profile_editorial_question)) },
            modifier = Modifier.fillMaxWidth())
        RichTextField(row.answer, { v -> onEdit { it.copy(faqs = it.faqs.replaced(index, row.copy(answer = v))) } }, stringResource(R.string.profile_editorial_answer), errors,
            "content_details.faqs.$index", minLines = 3)
        HorizontalDivider()
    }
    AddRowButton(stringResource(R.string.profile_editorial_faqs_add), draft.faqs.size, profile.limit("faqs", 30)) { onEdit { it.copy(faqs = it.faqs + FaqDraft()) } }
}

/** Il modulo di contatto: uguale per locale e organizzazione, come `ContactFields` nei pannelli. */
@Composable
internal fun ContactFormFields(
    contact: ContactSettings,
    mode: String,
    email: String,
    errors: FormErrors,
    busy: Boolean,
    onMode: (String) -> Unit,
    onEmail: (String) -> Unit,
    onResend: () -> Unit,
) {
    Text(stringResource(R.string.profile_contact_lead), color = Muted)
    Text(stringResource(R.string.profile_contact_mode), style = MaterialTheme.typography.titleSmall)
    contact.modes.forEach { option ->
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            RadioButton(selected = mode == option.value, onClick = { onMode(option.value) })
            Text(option.label, modifier = Modifier.weight(1f))
        }
    }
    errors.field("contact_mode")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    contact.note?.let { Text(it, color = Muted) }
    if (mode == "members" || mode == "everyone" || email.isNotBlank()) {
        FormTextField(email, onEmail, stringResource(R.string.profile_contact_email), errors, "contact_email", singleLine = true,
            help = stringResource(R.string.profile_contact_email_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
    }
    contactStatus(contact)?.let { Text(it.resolve(), color = if (contact.confirmed) MaterialTheme.colorScheme.onSurface else Muted) }
    if (contact.pending && contact.canResend) {
        OutlinedButton(enabled = !busy, onClick = onResend, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.profile_contact_resend)) }
    }
}
