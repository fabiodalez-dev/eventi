package it.fabiodalez.incitta.ui.management.profile

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.RichMarkup
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.data.management.VenueProfile
import it.fabiodalez.incitta.data.management.VenueSection
import it.fabiodalez.incitta.data.management.BEFORE_GOING_KEYS
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull

/*
 * Le schermate del profilo (P4): l'indice delle sezioni del locale con una
 * riga di stato ciascuna, la schermata di una sezione, «Avvisa chi ti segue»,
 * gli strumenti da distribuire e il profilo dell'organizzazione.
 */

internal fun sectionTitle(section: VenueSection): Int = when (section) {
    VenueSection.Description -> R.string.profile_section_description
    VenueSection.Where -> R.string.profile_section_where
    VenueSection.Hours -> R.string.profile_section_hours
    VenueSection.Contacts -> R.string.profile_section_contacts
    VenueSection.Transit -> R.string.profile_section_transit
    VenueSection.Accessibility -> R.string.profile_section_accessibility
    VenueSection.Card -> R.string.profile_section_card
    VenueSection.BeforeGoing -> R.string.profile_section_before_going
    VenueSection.Editorial -> R.string.profile_section_editorial
    VenueSection.Seo -> R.string.profile_section_seo
    VenueSection.ContactForm -> R.string.profile_section_contact_form
}

/** La riga di stato di una sezione nell'indice: cosa c'è adesso, o «Da compilare». */
internal fun sectionStatus(section: VenueSection, profile: VenueProfile): UiText {
    val empty = UiText.Res(R.string.profile_to_fill)
    fun joined(parts: List<String?>) = parts.filterNot { it.isNullOrBlank() }.joinToString(" · ").takeIf { it.isNotEmpty() }?.let { UiText.Raw(it) } ?: empty
    return when (section) {
        VenueSection.Description -> (profile.shortDescription?.takeIf { it.isNotBlank() } ?: RichMarkup.plain(profile.description).takeIf { it.isNotBlank() })
            ?.let { UiText.Raw(it.take(90) + if (it.length > 90) "…" else "") } ?: empty
        VenueSection.Where -> joined(listOf(profile.address, profile.zone, profile.municipality))
        VenueSection.Hours -> if (profile.openingHours.isEmpty() && profile.closures.isEmpty() && profile.capacity == null) empty
            else UiText.Plural(R.plurals.profile_status_hours, profile.openingHours.size, listOf(profile.openingHours.size))
        VenueSection.Contacts -> joined(listOf(profile.phone, profile.email, profile.website) + profile.socials.map { it.name })
        VenueSection.Transit -> if (profile.transit.isEmpty()) empty else UiText.Plural(R.plurals.profile_status_rows, profile.transit.size, listOf(profile.transit.size))
        VenueSection.Accessibility -> profile.accessibility.count { it.value != null }.let { if (it == 0) empty else UiText.Plural(R.plurals.profile_status_rows, it, listOf(it)) }
        VenueSection.Card -> if (profile.info.isEmpty() && !profile.requiresMembership) empty else UiText.Plural(R.plurals.profile_status_rows, profile.info.size, listOf(profile.info.size))
        VenueSection.BeforeGoing -> profile.contentDetails.filterKeys { it in BEFORE_GOING_KEYS }.count { (_, value) -> filled(value) }
            .let { if (it == 0) empty else UiText.Plural(R.plurals.profile_status_rows, it, listOf(it)) }
        VenueSection.Editorial -> {
            val faqs = (profile.contentDetails["faqs"] as? JsonArray)?.size ?: 0
            val intro = RichMarkup.plain((profile.contentDetails["introduction"] as? JsonPrimitive)?.contentOrNull)
            when {
                faqs > 0 -> UiText.Plural(R.plurals.profile_status_faqs, faqs, listOf(faqs))
                intro.isNotBlank() -> UiText.Raw(intro.take(90))
                else -> empty
            }
        }
        VenueSection.Seo -> profile.seo.title?.takeIf { it.isNotBlank() }?.let { UiText.Raw(it) } ?: UiText.Res(R.string.profile_status_seo_auto)
        VenueSection.ContactForm -> if (profile.contact.mode == "disabled") UiText.Res(R.string.profile_status_contact_off)
            else UiText.Raw(profile.contact.modes.firstOrNull { it.value == profile.contact.mode }?.label ?: profile.contact.mode)
    }
}

/* Un valore di `content_details` compilato: né nullo, né testo vuoto, né elenco vuoto. */
private fun filled(value: JsonElement): Boolean = when (value) {
    is JsonNull -> false
    is JsonPrimitive -> value.content.isNotBlank()
    is JsonArray -> value.isNotEmpty()
    is JsonObject -> value.isNotEmpty()
}

/** Dalla voce «Profilo» del menu: il locale o l'organizzazione aperti. */
@Composable
internal fun ProfileEntryScreen(context: ManagementGraphContext) {
    val subject = currentSubject(context.repository)
    LaunchedEffect(subject?.key) {
        if (subject == null || !subject.permissions.profileUpdate) return@LaunchedEffect
        val route: Any = if (subject.kind == SubjectKind.Venue) VenueProfileRoute(subject.id) else OrganizerProfileRoute(subject.id)
        context.navController.navigate(route) { popUpTo(ProfileRoute) { inclusive = true } }
    }
    if (subject == null || !subject.permissions.profileUpdate) {
        ManagementScaffold(title = stringResource(R.string.management_area_profile), padding = context.padding, onBack = context::back) {
            Text(stringResource(if (subject == null) R.string.profile_no_subject else R.string.profile_not_allowed), color = Muted)
        }
    }
}

@Composable
internal fun VenueProfileIndexScreen(viewModel: VenueProfileViewModel, context: ManagementGraphContext) {
    val state by viewModel.profile.collectAsStateWithLifecycle()
    val uriHandler = LocalUriHandler.current
    /* Si ricarica a ogni ritorno da una sezione: le righe di stato restano vere. */
    LaunchedEffect(Unit) { viewModel.load() }
    val profile = (state as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.profile_venue_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = profile?.let { stringResource(R.string.profile_identity, it.name, it.type.label) },
    ) {
        LoadState(state, onRetry = viewModel::load) { venue ->
            Text(stringResource(R.string.profile_venue_lead), color = Muted)
            Column {
                ManagementRow(stringResource(R.string.profile_section_description), sectionStatus(VenueSection.Description, venue).resolve(), { open(context, venue, VenueSection.Description) })
                ManagementRow(stringResource(R.string.profile_section_images), stringResource(R.string.profile_section_images_status), { context.navController.navigate(VenueImagesRoute(venue.id)) })
                listOf(VenueSection.Where, VenueSection.Hours, VenueSection.Contacts, VenueSection.Transit, VenueSection.Accessibility, VenueSection.Card,
                    VenueSection.BeforeGoing, VenueSection.Editorial, VenueSection.Seo, VenueSection.ContactForm).forEach { section ->
                    ManagementRow(stringResource(sectionTitle(section)), sectionStatus(section, venue).resolve(), { open(context, venue, section) })
                }
                val notice = venue.notices.latest
                ManagementRow(
                    stringResource(R.string.profile_section_notice),
                    notice?.let { stringResource(R.string.profile_status_notice_latest, it.kindLabel) } ?: stringResource(R.string.profile_status_notice_none),
                    { context.navController.navigate(VenueNoticeRoute(venue.id)) },
                )
                ManagementRow(stringResource(R.string.profile_section_tools), stringResource(R.string.profile_section_tools_status), { context.navController.navigate(VenueToolsRoute(venue.id)) })
            }
            OutlinedButton(onClick = { uriHandler.openUri(venue.publicUrl) }, enabled = venue.publicUrl.isNotBlank(), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.profile_open_public))
            }
        }
    }
}

private fun open(context: ManagementGraphContext, venue: VenueProfile, section: VenueSection) {
    context.navController.navigate(VenueSectionRoute(venue.id, section.name))
}

@Composable
internal fun VenueSectionScreen(viewModel: VenueSectionViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    ManagementScaffold(title = stringResource(sectionTitle(viewModel.section)), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.profile, onRetry = viewModel::load) { profile ->
            val draft = state.draft ?: return@LoadState
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.profile_save), onSubmit = viewModel::save) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                VenueSectionFields(viewModel.section, profile, draft, state.options, state.errors, state.busy, viewModel::edit, viewModel::resendContact)
            }
        }
    }
}

private val noticeDay = DateTimeFormatter.ofPattern("EEEE d MMMM", Locale.ITALIAN)

@Composable
internal fun VenueNoticeScreen(viewModel: VenueNoticeViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var confirming by remember { mutableStateOf(false) }
    ManagementScaffold(title = stringResource(R.string.profile_section_notice), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.profile, onRetry = viewModel::load) { profile ->
            val zone = runCatching { ZoneId.of(profile.city?.timezone.orEmpty()) }.getOrDefault(ZoneId.of("Europe/Rome"))
            val notices = profile.notices
            Text(stringResource(R.string.profile_notice_lead, notices.minDaysBetween), color = Muted)
            Text(pluralStringResource(R.plurals.profile_notice_followers, notices.followers, notices.followers), color = Muted)
            notices.latest?.let { latest ->
                val day = cityLocalDateTime(latest.createdAt, zone)?.format(noticeDay) ?: ""
                Text(stringResource(R.string.profile_notice_latest, day, latest.body), style = MaterialTheme.typography.bodyMedium)
            }
            val next = cityLocalDateTime(notices.nextAvailableAt, zone)
            next?.let { Text(stringResource(R.string.profile_notice_next, it.format(noticeDay)), color = MaterialTheme.colorScheme.error) }
            state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.profile_notice_submit), onSubmit = { confirming = true },
                submitEnabled = state.body.isNotBlank() && next == null) {
                ChoiceField(stringResource(R.string.profile_notice_kind), profile.choices.noticeKinds.asChoices(), state.kind, viewModel::setKind, state.errors.field("kind"))
                CountedTextField(state.body, viewModel::setBody, stringResource(R.string.profile_notice_body), profile.limit("notice_body", 500), state.errors, "body",
                    stringResource(R.string.profile_notice_body_help), minLines = 4)
                DayField(stringResource(R.string.profile_notice_until), state.until, viewModel::setUntil, state.errors.field("until"),
                    clearLabel = stringResource(R.string.profile_notice_until_clear), emptyText = stringResource(R.string.profile_notice_until_none), minDate = LocalDate.now(zone))
            }
        }
    }
    if (confirming) {
        val days = (state.profile as? Loadable.Ready)?.value?.notices?.minDaysBetween ?: 7
        ConfirmDialog(
            title = stringResource(R.string.profile_notice_confirm_title),
            text = stringResource(R.string.profile_notice_confirm_text, days),
            confirmLabel = stringResource(R.string.profile_notice_submit),
            onConfirm = { confirming = false; viewModel.publish() },
            onDismiss = { confirming = false },
        )
    }
}

@Composable
internal fun VenueToolsScreen(viewModel: VenueProfileViewModel, context: ManagementGraphContext) {
    val state by viewModel.profile.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { viewModel.load() }
    var copied by remember { mutableStateOf(false) }
    ManagementScaffold(title = stringResource(R.string.profile_section_tools), padding = context.padding, onBack = context::back) {
        if (copied) Text(stringResource(R.string.profile_copied), color = MaterialTheme.colorScheme.primary)
        LoadState(state, onRetry = viewModel::load) { venue ->
            CopyShareBlock(stringResource(R.string.profile_tools_public), stringResource(R.string.profile_tools_public_help), venue.publicUrl, openable = true) { copied = true }
            HorizontalDivider()
            CopyShareBlock(stringResource(R.string.profile_tools_calendar), stringResource(R.string.profile_tools_calendar_help), venue.calendarUrl, openable = false) { copied = true }
            HorizontalDivider()
            CopyShareBlock(stringResource(R.string.profile_tools_widget), stringResource(R.string.profile_tools_widget_help), venue.widget.code, openable = false) { copied = true }
        }
    }
}

@Composable
internal fun OrganizerProfileScreen(viewModel: OrganizerProfileViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var copied by remember { mutableStateOf(false) }
    val uriHandler = LocalUriHandler.current
    val profile = (state.profile as? Loadable.Ready)?.value
    ManagementScaffold(title = stringResource(R.string.profile_organizer_title), padding = context.padding, onBack = context::back, subtitle = profile?.name, busy = state.busy) {
        LoadState(state.profile, onRetry = viewModel::load) { organizer ->
            val draft = state.draft ?: return@LoadState
            Text(stringResource(R.string.profile_organizer_lead), color = Muted)
            FormScaffold(errors = state.errors, busy = state.busy, submitLabel = stringResource(R.string.profile_save), onSubmit = viewModel::save) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                Text(stringResource(R.string.profile_organizer_presentation), style = MaterialTheme.typography.titleLarge)
                RichTextField(draft.description, { v -> viewModel.edit { it.copy(description = v) } }, stringResource(R.string.profile_organizer_presentation_help), state.errors, "description", minLines = 8)
                FormTextField(draft.website, { v -> viewModel.edit { it.copy(website = v) } }, stringResource(R.string.profile_organizer_website), state.errors, "website", singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Uri))
                FormTextField(draft.email, { v -> viewModel.edit { it.copy(email = v) } }, stringResource(R.string.profile_organizer_email), state.errors, "email", singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Email))
                Text(stringResource(R.string.profile_section_contact_form), style = MaterialTheme.typography.titleLarge)
                ContactFormFields(organizer.contact, draft.contactMode, draft.contactEmail, state.errors, state.busy,
                    { v -> viewModel.edit { it.copy(contactMode = v) } }, { v -> viewModel.edit { it.copy(contactEmail = v) } }, viewModel::resendContact)
            }
            HorizontalDivider()
            OutlinedButton(onClick = { uriHandler.openUri(organizer.publicUrl) }, enabled = organizer.publicUrl.isNotBlank(), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.profile_open_public))
            }
            if (copied) Text(stringResource(R.string.profile_copied), color = MaterialTheme.colorScheme.primary)
            CopyShareBlock(stringResource(R.string.profile_tools_calendar), stringResource(R.string.profile_tools_calendar_help), organizer.calendarUrl, openable = false) { copied = true }
        }
    }
}
