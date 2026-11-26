package it.fabiodalez.incitta.ui.management.registration

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ApplicationChoice
import it.fabiodalez.incitta.data.management.ApplicationSent
import it.fabiodalez.incitta.data.management.CatalogueVenue
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.MyApplication
import it.fabiodalez.incitta.data.management.VenueApplicationDraft
import it.fabiodalez.incitta.data.management.prepareImage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.rememberImagePicker
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/*
 * P13: «Le mie richieste» e i tre moduli, con le card, gli stati e i testi
 * della pagina `/le-mie-richieste` e dei moduli `/registra-il-tuo-locale`,
 * `/locali/{locale}/rivendica`, `/registra-la-tua-organizzazione`.
 *
 * Q2 (W-AP-02): i tre moduli si aprono anche all'ospite. In fondo, come sul
 * sito, l'email e la password dell'account da creare («Crea l'account e
 * invia») oppure «Ho già un account»; dopo la registrazione «Controlla la
 * posta» prende il posto del modulo, con lo stesso testo che l'indirizzo sia
 * nuovo o già registrato (D64).
 */

/**
 * Quello che i moduli chiedono a chi li apre: il margine dello `Scaffold`,
 * l'email dell'account (`null` per l'ospite), l'uscita e l'accesso di «Ho già
 * un account». Lo danno la gestione (`registrationGraph`) e l'ingresso
 * dell'ospite (`GuestRegistrationScreen`).
 */
internal class RegistrationChrome(
    val padding: PaddingValues,
    val accountEmail: String?,
    val onExit: () -> Unit,
    val onSignIn: () -> Unit = {},
)

/** Dove porta una card o la fine di un modulo: le schermate le apre il grafo. */
internal sealed interface RegistrationLink {
    data object NewVenue : RegistrationLink
    data object NewOrganizer : RegistrationLink
    data class Claim(val slug: String) : RegistrationLink
    data object Applications : RegistrationLink
    /* Dopo l'invio automatico della bozza (Q2): «Le mie richieste» al posto del modulo. */
    data object SentFromDraft : RegistrationLink
}

/** Il giorno di una data del server, nel fuso del telefono: «17 ottobre 2026». */
internal fun applicationDay(value: String?): String? = value?.let { raw ->
    runCatching { OffsetDateTime.parse(raw).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMMM yyyy", Locale.ITALIAN)) }.getOrNull()
}

/** La scheda pubblica di un locale sul sito (la stessa che apre il sito per una rivendicazione in valutazione). */
private fun venuePage(slug: String): String = BuildConfig.API_BASE_URL.substringBefore("/api/") + "/locali/" + slug

@Composable
internal fun ApplicationsScreen(
    viewModel: ApplicationsViewModel,
    chrome: RegistrationChrome,
    onBack: () -> Unit,
    onOpen: (RegistrationLink) -> Unit,
    onOpenSubject: (String) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val uriHandler = LocalUriHandler.current
    var discarding by remember { mutableStateOf<MyApplication?>(null) }
    LaunchedEffect(state.openSubject) { state.openSubject?.let { viewModel.subjectOpened(); onOpenSubject(it) } }
    ManagementScaffold(
        title = stringResource(R.string.applications_title),
        padding = chrome.padding,
        onBack = onBack,
        subtitle = stringResource(R.string.applications_lead),
        busy = state.busy,
    ) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (state.items is Loadable.Ready) NewRequestButtons(onOpen)
        when (val items = state.items) {
            Loadable.Empty -> {
                Text(stringResource(R.string.applications_empty_title), style = MaterialTheme.typography.titleLarge)
                Text(stringResource(R.string.applications_empty), color = Muted)
                NewRequestButtons(onOpen)
            }
            else -> LoadState(items, onRetry = viewModel::load) { list ->
                list.forEach { application ->
                    ApplicationCard(
                        application = application,
                        enabled = !state.busy,
                        onConfirm = { viewModel.confirm(application) },
                        onDiscard = { discarding = application },
                        onOpenPanel = { viewModel.openPanel(application) },
                        onOpenUrl = { url -> uriHandler.openUri(url) },
                        onRetry = { onOpen(retryLink(application)) },
                    )
                }
                if (state.nextPage != null) {
                    OutlinedButton(onClick = viewModel::loadMore, enabled = !state.loadingMore, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.applications_more))
                    }
                }
            }
        }
    }
    discarding?.let { application ->
        ConfirmDialog(
            title = stringResource(R.string.applications_discard_title),
            text = stringResource(R.string.applications_discard_text, application.name),
            confirmLabel = stringResource(R.string.applications_discard),
            destructive = true,
            onConfirm = { discarding = null; viewModel.discard(application) },
            onDismiss = { discarding = null },
        )
    }
}

private fun retryLink(application: MyApplication): RegistrationLink {
    val retry = application.retry
    return when {
        retry?.form == "organizer" || (retry == null && application.kind == "organizer") -> RegistrationLink.NewOrganizer
        retry?.form == "claim" && !retry.venueSlug.isNullOrBlank() -> RegistrationLink.Claim(retry.venueSlug)
        else -> RegistrationLink.NewVenue
    }
}

@Composable
private fun NewRequestButtons(onOpen: (RegistrationLink) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
        Button(onClick = { onOpen(RegistrationLink.NewVenue) }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
            Text(stringResource(R.string.applications_new_venue))
        }
        OutlinedButton(onClick = { onOpen(RegistrationLink.NewOrganizer) }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
            Text(stringResource(R.string.applications_new_organizer))
        }
    }
}

/** Una card come quella del sito: nome, tipo di richiesta, stato, date, «cosa succede adesso» e il pulsante giusto. */
@Composable
private fun ApplicationCard(
    application: MyApplication,
    enabled: Boolean,
    onConfirm: () -> Unit,
    onDiscard: () -> Unit,
    onOpenPanel: () -> Unit,
    onOpenUrl: (String) -> Unit,
    onRetry: () -> Unit,
) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(application.name, style = MaterialTheme.typography.titleMedium)
                    Text(application.kindLabel, color = Muted)
                }
                StateBadge(application)
            }
            val sent = applicationDay(application.createdAt)?.let { stringResource(R.string.applications_sent_on, it) }
            val decided = applicationDay(application.reviewedAt)?.takeIf { application.state == STATE_APPROVED || application.state == STATE_REJECTED }
                ?.let { stringResource(R.string.applications_decided_on, it) }
            listOfNotNull(sent, decided).joinToString(" · ").takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
            application.summary?.let { Text(it, color = Muted) }
            Text(application.next)
            when (application.state) {
                STATE_AWAITING -> if (application.canConfirm) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(enabled = enabled, onClick = onConfirm, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_confirm)) }
                        OutlinedButton(
                            enabled = enabled,
                            onClick = onDiscard,
                            border = BorderStroke(1.dp, MaterialTheme.colorScheme.error),
                            modifier = Modifier.heightIn(min = 48.dp),
                        ) { Text(stringResource(R.string.applications_discard), color = MaterialTheme.colorScheme.error) }
                    }
                }
                STATE_APPROVED -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (application.subject != null) {
                        Button(enabled = enabled, onClick = onOpenPanel, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_open_panel)) }
                    }
                    application.publicUrl?.takeIf { it.isNotBlank() }?.let { url ->
                        OutlinedButton(onClick = { onOpenUrl(url) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_open_public)) }
                    }
                }
                STATE_REJECTED -> OutlinedButton(onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_new_request)) }
                else -> if (application.kind == "claim") {
                    application.venueSlug?.takeIf { it.isNotBlank() }?.let { slug ->
                        OutlinedButton(onClick = { onOpenUrl(venuePage(slug)) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_open_venue)) }
                    }
                }
            }
        }
    }
}

@Composable
private fun StateBadge(application: MyApplication) {
    val color = when (application.state) {
        STATE_AWAITING -> MaterialTheme.colorScheme.error
        STATE_APPROVED -> MaterialTheme.colorScheme.primary
        STATE_PENDING -> MaterialTheme.colorScheme.tertiary
        else -> Muted
    }
    val label = stringResource(R.string.applications_state_badge, application.stateLabel)
    Surface(
        shape = MaterialTheme.shapes.small,
        border = BorderStroke(1.dp, color),
        color = MaterialTheme.colorScheme.surface,
        modifier = Modifier.semantics { contentDescription = label },
    ) {
        Text(application.stateLabel, color = color, style = MaterialTheme.typography.labelLarge, modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp))
    }
}

/** Le richieste dell'account in cima ai moduli: da confermare (con i due pulsanti) e in valutazione. */
@Composable
private fun PendingApplications(state: ApplicationFormUi<*>, onConfirm: (MyApplication) -> Unit, onDiscard: (MyApplication) -> Unit) {
    var discarding by remember { mutableStateOf<MyApplication?>(null) }
    state.notice?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
    state.noticeError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    state.awaiting.forEach { application ->
        OutlinedCard(Modifier.fillMaxWidth(), border = BorderStroke(1.dp, MaterialTheme.colorScheme.error)) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.applications_awaiting_title), style = MaterialTheme.typography.titleMedium)
                Text(stringResource(R.string.applications_awaiting_body, application.name))
                if (application.canConfirm) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(enabled = !state.busy, onClick = { onConfirm(application) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_confirm)) }
                        OutlinedButton(enabled = !state.busy, onClick = { discarding = application }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.applications_discard)) }
                    }
                }
            }
        }
    }
    state.inReview.forEach { application ->
        Text(stringResource(R.string.applications_in_review, application.name, applicationDay(application.createdAt).orEmpty()), color = Muted)
    }
    discarding?.let { application ->
        ConfirmDialog(
            title = stringResource(R.string.applications_discard_title),
            text = stringResource(R.string.applications_discard_text, application.name),
            confirmLabel = stringResource(R.string.applications_discard),
            destructive = true,
            onConfirm = { discarding = null; onDiscard(application) },
            onDismiss = { discarding = null },
        )
    }
}

/** Un gruppo del modulo con titolo e descrizione, come le card del sito. */
@Composable
private fun FormSection(title: String, description: String?, content: @Composable ColumnScope.() -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            description?.let { Text(it, color = Muted) }
            content()
        }
    }
}

/**
 * «Tu, referente»: nome, ruolo, telefono e l'email. Da collegati è quella
 * dell'account, che non si cambia qui; da ospite (`email` nullo) si scrive,
 * ed è anche l'indirizzo dell'account da creare.
 */
@Composable
private fun ContactSection(
    name: String,
    role: String,
    phone: String,
    email: String?,
    errors: FormErrors,
    onName: (String) -> Unit,
    onRole: (String) -> Unit,
    onPhone: (String) -> Unit,
    guestEmail: String = "",
    onGuestEmail: (String) -> Unit = {},
    extra: @Composable ColumnScope.() -> Unit = {},
) {
    FormSection(stringResource(R.string.application_contact_title), stringResource(R.string.application_contact_description)) {
        FormTextField(name, onName, stringResource(R.string.application_contact_name) + " *", errors, "contact_name", singleLine = true)
        FormTextField(role, onRole, stringResource(R.string.application_contact_role), errors, "contact_role", singleLine = true, help = stringResource(R.string.application_contact_role_hint))
        FormTextField(phone, onPhone, stringResource(R.string.application_contact_phone), errors, "contact_phone", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
        if (email == null) {
            FormTextField(
                guestEmail, onGuestEmail, stringResource(R.string.application_contact_email) + " *", errors, EMAIL_FIELD, singleLine = true,
                help = stringResource(R.string.application_guest_email_hint),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            )
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(stringResource(R.string.application_contact_email), style = MaterialTheme.typography.labelLarge)
                Text(email, color = Muted)
                Text(stringResource(R.string.application_signed_in_as, email), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
        extra()
    }
}

/**
 * «Cosa succede dopo»: i passi numerati. Da collegati senza quello della
 * conferma dell'email (nell'app è già confermata); da ospite è il primo.
 */
@Composable
private fun NextSteps(steps: List<Int>, guest: Boolean = false) {
    val shown = if (guest) listOf(R.string.application_next_confirm) + steps else steps
    FormSection(stringResource(R.string.application_next_title), null) {
        shown.forEachIndexed { index, step -> Text(stringResource(R.string.application_step, index + 1, stringResource(step)), color = Muted) }
    }
}

/** Gli errori del server più quelli trovati qui prima di creare l'account, sotto i loro campi. */
@Composable
private fun shownErrors(state: ApplicationFormUi<*>): FormErrors {
    if (state.required.isEmpty()) return state.errors
    val context = LocalContext.current
    return state.errors.copy(fields = state.errors.fields + state.required.mapValues { (_, text) -> context.getString(text, CLAIM_MESSAGE_MIN, MIN_PASSWORD) })
}

/** Gli eventi di una volta dei moduli: l'accesso di «Ho già un account» e «Le mie richieste» dopo l'invio automatico. */
@Composable
private fun FormEvents(
    state: ApplicationFormUi<*>,
    onSignInOpened: () -> Unit,
    onApplicationsOpened: () -> Unit,
    chrome: RegistrationChrome,
    onOpen: (RegistrationLink) -> Unit,
) {
    LaunchedEffect(state.signIn) { if (state.signIn) { onSignInOpened(); chrome.onSignIn() } }
    LaunchedEffect(state.landOnApplications) { if (state.landOnApplications) { onApplicationsOpened(); onOpen(RegistrationLink.SentFromDraft) } }
}

/** Da ospite, in cima come sul sito: chi ha già un account accede e ritrova quello che ha scritto. */
@Composable
private fun GuestSignInCard(enabled: Boolean, onSignIn: () -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.application_login_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.application_login_description), color = Muted)
            OutlinedButton(onClick = onSignIn, enabled = enabled, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_login_action)) }
        }
    }
}

/** «Il tuo account»: la password dell'account da creare, mai salvata sul telefono, e di nuovo «Ho già un account». */
@Composable
private fun AccountSection(state: ApplicationFormUi<*>, errors: FormErrors, onPassword: (String) -> Unit, onConfirmation: (String) -> Unit, onSignIn: () -> Unit) {
    FormSection(stringResource(R.string.application_account_title), stringResource(R.string.application_account_description)) {
        PasswordField(state.password, onPassword, stringResource(R.string.application_password) + " *", errors, PASSWORD_FIELD, help = stringResource(R.string.application_password_hint, MIN_PASSWORD))
        PasswordField(state.passwordConfirmation, onConfirmation, stringResource(R.string.application_password_confirmation) + " *", errors, CONFIRMATION_FIELD)
        Text(stringResource(R.string.application_login_title), color = Muted)
        TextButton(onClick = onSignIn, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.application_login_action)) }
    }
}

@Composable
private fun PasswordField(value: String, onChange: (String) -> Unit, label: String, errors: FormErrors, field: String, help: String? = null) {
    val error = errors.field(field)
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = true,
        isError = error != null,
        supportingText = (error ?: help)?.let { text -> { Text(text) } },
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, autoCorrectEnabled = false),
        modifier = Modifier.fillMaxWidth(),
    )
}

/**
 * «Controlla la posta» al posto del modulo, dopo «Crea l'account e invia».
 * Lo stesso testo che l'indirizzo sia nuovo o già registrato (D64): la
 * differenza la vede solo chi legge la casella.
 */
@Composable
private fun CheckEmailCard(state: ApplicationFormUi<*>, onResend: () -> Unit, onEdit: () -> Unit, onExit: () -> Unit) {
    val email = state.awaitingEmail ?: return
    OutlinedCard(Modifier.fillMaxWidth(), border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.application_check_email_title), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.application_check_email_body, email))
            Text(stringResource(R.string.application_check_email_phone), color = Muted)
            state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (state.resent) Text(stringResource(R.string.application_check_email_resent), color = MaterialTheme.colorScheme.primary)
            if (state.canResend) {
                Button(onClick = onResend, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_check_email_resend)) }
            } else {
                Text(stringResource(R.string.application_check_email_no_resend), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
            OutlinedButton(onClick = onEdit, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_check_email_edit)) }
            TextButton(onClick = onExit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_sent_events)) }
        }
    }
}

/** Mentre la bozza parte da sola, dopo la conferma dell'email. */
@Composable
private fun AutoSendingNotice(state: ApplicationFormUi<*>) {
    if (state.autoSending) Text(stringResource(R.string.application_auto_sending), color = MaterialTheme.colorScheme.primary)
}

/** Dopo l'invio, al posto del modulo: com'è andata e cosa succede adesso. */
@Composable
private fun SentCard(
    sent: ApplicationSent,
    steps: List<Int>,
    secondaryLabel: String,
    onSecondary: () -> Unit,
    onEvents: () -> Unit,
    onApplications: () -> Unit,
) {
    val title = when (sent.state) {
        "verify" -> R.string.application_sent_verify_title
        "organizer" -> R.string.application_sent_organizer_title
        else -> R.string.application_sent_queued_title
    }
    OutlinedCard(Modifier.fillMaxWidth(), border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(title), style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            sent.message.takeIf { it.isNotBlank() }?.let { Text(it) }
            steps.forEachIndexed { index, step -> Text(stringResource(R.string.application_step, index + 1, stringResource(step)), color = Muted) }
            Button(onClick = onEvents, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_sent_events)) }
            OutlinedButton(onClick = onSecondary, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(secondaryLabel) }
            TextButton(onClick = onApplications, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.application_sent_mine)) }
        }
    }
}

/** «Crea l'account e invia» da ospite, il pulsante di sempre da collegati. */
@Composable
private fun submitLabel(state: ApplicationFormUi<*>, signedIn: Int): String =
    stringResource(if (state.guest) R.string.application_guest_submit else signedIn)

private val VENUE_STEPS = listOf(R.string.application_next_review, R.string.application_next_panel, R.string.application_next_publish)
private val CLAIM_STEPS = listOf(R.string.application_claim_next_review, R.string.application_claim_next_panel)
private val ORGANIZER_STEPS = listOf(R.string.application_organizer_next_review, R.string.application_organizer_next_panel)

@Composable
internal fun RegisterVenueScreen(
    viewModel: RegisterVenueViewModel,
    chrome: RegistrationChrome,
    onBack: () -> Unit,
    onOpen: (RegistrationLink) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val catalogue by viewModel.catalogue.collectAsStateWithLifecycle()
    val image by viewModel.image.collectAsStateWithLifecycle()
    val androidContext = LocalContext.current
    val scope = rememberCoroutineScope()
    val pickImage = rememberImagePicker { uri ->
        scope.launch {
            try { viewModel.setImage(withContext(Dispatchers.IO) { prepareImage(androidContext, uri) }) }
            catch (e: ImageUploadException) { viewModel.imageFailed(e.reason) }
        }
    }
    val email = state.options.valueOrNull?.accountEmail?.ifBlank { null } ?: chrome.accountEmail
    FormEvents(state, viewModel::signInOpened, viewModel::applicationsOpened, chrome, onOpen)
    ManagementScaffold(
        title = stringResource(R.string.register_venue_title),
        padding = chrome.padding,
        onBack = onBack,
        subtitle = stringResource(R.string.register_venue_lead),
        busy = state.busy,
    ) {
        PendingApplications(state, viewModel::confirm, viewModel::discard)
        AutoSendingNotice(state)
        val sent = state.sent
        if (sent != null) {
            SentCard(
                sent, VENUE_STEPS, stringResource(R.string.application_sent_another),
                onSecondary = { viewModel.another { resetVenueDraft(it) } },
                onEvents = chrome.onExit,
                onApplications = { onOpen(RegistrationLink.Applications) },
            )
            return@ManagementScaffold
        }
        if (state.awaitingEmail != null) {
            CheckEmailCard(state, viewModel::resend, viewModel::editAgain, chrome.onExit)
            return@ManagementScaffold
        }
        if (state.guest) GuestSignInCard(!state.busy, viewModel::signInInstead)
        CatalogueSearch(catalogue, viewModel::setQuery, viewModel::search, onClaim = { onOpen(RegistrationLink.Claim(it.slug)) })
        val draft = state.draft
        val errors = shownErrors(state)
        FormScaffold(errors = errors, busy = state.busy, submitLabel = submitLabel(state, R.string.application_submit), onSubmit = viewModel::submit, submitEnabled = state.options !is Loadable.Loading) {
            FormSection(stringResource(R.string.register_venue_section), stringResource(R.string.register_venue_section_description)) {
                FormTextField(draft.venueName, { v -> viewModel.update { it.copy(venueName = v) } }, stringResource(R.string.register_venue_name) + " *", errors, "venue_name", singleLine = true)
                TypePicker(draft.type, state.options.valueOrNull?.types.orEmpty(), errors.field("type")) { v -> viewModel.update { it.copy(type = v) } }
            }
            FormSection(stringResource(R.string.register_where_section), stringResource(R.string.register_where_description)) {
                FormTextField(draft.address, { v -> viewModel.update { it.copy(address = v) } }, stringResource(R.string.register_address) + " *", errors, "address", singleLine = true, help = stringResource(R.string.register_address_hint))
                FormTextField(draft.postalCode, { v -> viewModel.update { it.copy(postalCode = v.filter(Char::isDigit).take(5)) } }, stringResource(R.string.register_postal_code), errors, "postal_code", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                FormTextField(draft.municipality, { v -> viewModel.update { it.copy(municipality = v) } }, stringResource(R.string.register_municipality), errors, "municipality", singleLine = true)
            }
            FormSection(stringResource(R.string.register_public_section), stringResource(R.string.register_public_description)) {
                FormTextField(draft.website, { v -> viewModel.update { it.copy(website = v) } }, stringResource(R.string.register_website), errors, "website", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
                FormTextField(draft.publicEmail, { v -> viewModel.update { it.copy(publicEmail = v) } }, stringResource(R.string.register_public_email), errors, "public_email", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email))
                FormTextField(draft.publicPhone, { v -> viewModel.update { it.copy(publicPhone = v) } }, stringResource(R.string.register_public_phone), errors, "public_phone", singleLine = true, help = stringResource(R.string.register_public_phone_hint), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                FormTextField(draft.instagram, { v -> viewModel.update { it.copy(instagram = v) } }, stringResource(R.string.register_instagram), errors, "instagram", singleLine = true, help = stringResource(R.string.register_social_hint), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
                FormTextField(draft.facebook, { v -> viewModel.update { it.copy(facebook = v) } }, stringResource(R.string.register_facebook), errors, "facebook", singleLine = true, help = stringResource(R.string.register_social_hint), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
            }
            FormSection(stringResource(R.string.register_space_section), stringResource(R.string.register_space_description)) {
                FormTextField(draft.capacity, { v -> viewModel.update { it.copy(capacity = v.filter(Char::isDigit).take(6)) } }, stringResource(R.string.register_capacity), errors, "capacity", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                FormTextField(draft.description, { v -> viewModel.update { it.copy(description = v.take(2000)) } }, stringResource(R.string.register_description), errors, "description", minLines = 4, help = stringResource(R.string.register_description_hint))
            }
            FormSection(stringResource(R.string.register_image_section), stringResource(R.string.register_image_description)) {
                Text(stringResource(R.string.register_image_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
                image.image?.let { Text(stringResource(R.string.register_image_chosen, it.width, it.height)) }
                (image.error?.let { stringResource(it) } ?: errors.field("image"))?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = pickImage, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(stringResource(if (image.image == null) R.string.register_image_pick else R.string.register_image_change))
                    }
                    if (image.image != null) {
                        TextButton(onClick = viewModel::removeImage, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.register_image_remove)) }
                    }
                }
            }
            ContactSection(
                draft.contactName, draft.contactRole, draft.contactPhone, email, errors,
                onName = { v -> viewModel.update { it.copy(contactName = v) } },
                onRole = { v -> viewModel.update { it.copy(contactRole = v) } },
                onPhone = { v -> viewModel.update { it.copy(contactPhone = v) } },
                guestEmail = state.email, onGuestEmail = viewModel::setEmail,
            ) {
                FormTextField(draft.message, { v -> viewModel.update { it.copy(message = v.take(5000)) } }, stringResource(R.string.register_message), errors, "message", minLines = 4, help = stringResource(R.string.register_message_hint))
            }
            if (state.guest) AccountSection(state, errors, viewModel::setPassword, viewModel::setPasswordConfirmation, viewModel::signInInstead)
            NextSteps(VENUE_STEPS, guest = state.guest)
            Text(stringResource(R.string.application_privacy_note), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

/* «Scrivi un'altra richiesta»: restano solo i dati del referente. */
private fun resetVenueDraft(draft: VenueApplicationDraft) =
    VenueApplicationDraft(contactName = draft.contactName, contactRole = draft.contactRole, contactPhone = draft.contactPhone, municipality = draft.municipality)

/** «Il tuo locale è già nel catalogo?»: si cerca per nome e si rivendica invece di creare un doppione. */
@Composable
private fun CatalogueSearch(state: CatalogueUi, onQuery: (String) -> Unit, onSearch: () -> Unit, onClaim: (CatalogueVenue) -> Unit) {
    FormSection(stringResource(R.string.register_catalogue_title), stringResource(R.string.register_catalogue_description)) {
        OutlinedTextField(
            value = state.query,
            onValueChange = { onQuery(it.take(80)) },
            label = { Text(stringResource(R.string.register_catalogue_label)) },
            placeholder = { Text(stringResource(R.string.register_catalogue_placeholder)) },
            singleLine = true,
            isError = state.tooShort,
            supportingText = if (state.tooShort) { { Text(stringResource(R.string.register_catalogue_short)) } } else null,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { onSearch() }),
            modifier = Modifier.fillMaxWidth(),
        )
        OutlinedButton(onClick = onSearch, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.register_catalogue_submit)) }
        state.results?.let { results ->
            LoadState(results, onRetry = onSearch, emptyText = stringResource(R.string.register_catalogue_none)) { venues ->
                venues.forEach { venue ->
                    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(venue.name, style = MaterialTheme.typography.titleMedium)
                        venue.place?.let { Text(it, color = Muted) }
                        if (venue.claimable) {
                            val label = stringResource(R.string.register_catalogue_claim_named, venue.name)
                            OutlinedButton(onClick = { onClaim(venue) }, modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = label }) {
                                Text(stringResource(R.string.register_catalogue_claim))
                            }
                        } else {
                            Text(stringResource(R.string.register_catalogue_managed), color = Muted, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    HorizontalDivider()
                }
            }
        }
    }
}

/** Il tipo di locale: facoltativo, «Non saprei» lascia decidere alla redazione. */
@Composable
private fun TypePicker(value: String, types: List<ApplicationChoice>, error: String?, onChange: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val unknown = stringResource(R.string.register_venue_type_unknown)
    val current = types.firstOrNull { it.value == value }?.label ?: unknown
    val label = stringResource(R.string.register_venue_type)
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.labelLarge)
        Box {
            OutlinedButton(
                onClick = { open = true },
                enabled = types.isNotEmpty(),
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = "$label: $current" },
            ) {
                Text(current, modifier = Modifier.weight(1f))
                Icon(Icons.Outlined.ExpandMore, contentDescription = null)
            }
            DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                DropdownMenuItem(text = { Text(unknown) }, onClick = { open = false; onChange("") }, modifier = Modifier.heightIn(min = 48.dp))
                types.forEach { type ->
                    DropdownMenuItem(text = { Text(type.label) }, onClick = { open = false; onChange(type.value) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
    }
}

@Composable
internal fun ClaimVenueScreen(
    viewModel: ClaimVenueViewModel,
    chrome: RegistrationChrome,
    onBack: () -> Unit,
    onOpen: (RegistrationLink) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val target by viewModel.target.collectAsStateWithLifecycle()
    val uriHandler = LocalUriHandler.current
    val email = state.options.valueOrNull?.accountEmail?.ifBlank { null } ?: chrome.accountEmail
    FormEvents(state, viewModel::signInOpened, viewModel::applicationsOpened, chrome, onOpen)
    val venue = target.valueOrNull
    ManagementScaffold(
        title = stringResource(R.string.claim_title),
        padding = chrome.padding,
        onBack = onBack,
        subtitle = venue?.let { stringResource(R.string.claim_lead, it.name) },
        busy = state.busy,
    ) {
        PendingApplications(state, viewModel::confirm, viewModel::discard)
        AutoSendingNotice(state)
        LoadState(target, onRetry = viewModel::loadTarget) { claimed ->
            val sent = state.sent
            if (sent != null) {
                SentCard(
                    sent, CLAIM_STEPS, stringResource(R.string.claim_back, claimed.name),
                    onSecondary = { uriHandler.openUri(venuePage(claimed.slug)) },
                    onEvents = chrome.onExit,
                    onApplications = { onOpen(RegistrationLink.Applications) },
                )
                return@LoadState
            }
            if (state.awaitingEmail != null) {
                CheckEmailCard(state, viewModel::resend, viewModel::editAgain, chrome.onExit)
                return@LoadState
            }
            if (state.guest) GuestSignInCard(!state.busy, viewModel::signInInstead)
            val draft = state.draft
            val errors = shownErrors(state)
            FormScaffold(errors = errors, busy = state.busy, submitLabel = submitLabel(state, R.string.claim_submit), onSubmit = viewModel::submit) {
                FormSection(stringResource(R.string.claim_venue_section), stringResource(R.string.claim_venue_description)) {
                    Text(claimed.name, style = MaterialTheme.typography.headlineSmall)
                    claimed.summary.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
                }
                ContactSection(
                    draft.contactName, draft.contactRole, draft.contactPhone, email, errors,
                    onName = { v -> viewModel.update { it.copy(contactName = v) } },
                    onRole = { v -> viewModel.update { it.copy(contactRole = v) } },
                    onPhone = { v -> viewModel.update { it.copy(contactPhone = v) } },
                    guestEmail = state.email, onGuestEmail = viewModel::setEmail,
                )
                FormSection(stringResource(R.string.claim_verify_title), stringResource(R.string.claim_verify_description)) {
                    FormTextField(draft.message, { v -> viewModel.update { it.copy(message = v.take(5000)) } }, stringResource(R.string.claim_message) + " *", errors, "message", minLines = 5)
                }
                if (state.guest) AccountSection(state, errors, viewModel::setPassword, viewModel::setPasswordConfirmation, viewModel::signInInstead)
                NextSteps(CLAIM_STEPS, guest = state.guest)
                Text(stringResource(R.string.claim_privacy_note), color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
internal fun RegisterOrganizerScreen(
    viewModel: RegisterOrganizerViewModel,
    chrome: RegistrationChrome,
    onBack: () -> Unit,
    onOpen: (RegistrationLink) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val email = state.options.valueOrNull?.accountEmail?.ifBlank { null } ?: chrome.accountEmail
    FormEvents(state, viewModel::signInOpened, viewModel::applicationsOpened, chrome, onOpen)
    ManagementScaffold(
        title = stringResource(R.string.register_organizer_title),
        padding = chrome.padding,
        onBack = onBack,
        subtitle = stringResource(R.string.register_organizer_lead),
        busy = state.busy,
    ) {
        PendingApplications(state, viewModel::confirm, viewModel::discard)
        AutoSendingNotice(state)
        val sent = state.sent
        if (sent != null) {
            SentCard(
                sent, ORGANIZER_STEPS, stringResource(R.string.application_sent_another),
                onSecondary = { viewModel.another { it.copy(name = "", website = "", message = "") } },
                onEvents = chrome.onExit,
                onApplications = { onOpen(RegistrationLink.Applications) },
            )
            return@ManagementScaffold
        }
        if (state.awaitingEmail != null) {
            CheckEmailCard(state, viewModel::resend, viewModel::editAgain, chrome.onExit)
            return@ManagementScaffold
        }
        if (state.guest) GuestSignInCard(!state.busy, viewModel::signInInstead)
        val draft = state.draft
        val errors = shownErrors(state)
        FormScaffold(errors = errors, busy = state.busy, submitLabel = submitLabel(state, R.string.application_submit), onSubmit = viewModel::submit) {
            FormSection(stringResource(R.string.register_organizer_section), stringResource(R.string.register_organizer_section_description)) {
                FormTextField(draft.name, { v -> viewModel.update { it.copy(name = v) } }, stringResource(R.string.register_organizer_name) + " *", errors, "venue_name", singleLine = true)
                FormTextField(draft.website, { v -> viewModel.update { it.copy(website = v) } }, stringResource(R.string.register_organizer_website), errors, "website", singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
                FormTextField(draft.message, { v -> viewModel.update { it.copy(message = v.take(5000)) } }, stringResource(R.string.register_organizer_message), errors, "message", minLines = 4, help = stringResource(R.string.register_organizer_message_hint))
            }
            ContactSection(
                draft.contactName, draft.contactRole, draft.contactPhone, email, errors,
                onName = { v -> viewModel.update { it.copy(contactName = v) } },
                onRole = { v -> viewModel.update { it.copy(contactRole = v) } },
                onPhone = { v -> viewModel.update { it.copy(contactPhone = v) } },
                guestEmail = state.email, onGuestEmail = viewModel::setEmail,
            )
            if (state.guest) AccountSection(state, errors, viewModel::setPassword, viewModel::setPasswordConfirmation, viewModel::signInInstead)
            NextSteps(ORGANIZER_STEPS, guest = state.guest)
            Text(stringResource(R.string.application_privacy_note), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}
