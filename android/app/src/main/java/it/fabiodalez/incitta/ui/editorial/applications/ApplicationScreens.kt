package it.fabiodalez.incitta.ui.editorial.applications

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.ApplicationDetail
import it.fabiodalez.incitta.data.editorial.ApplicationRow
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.management.ManagementFiles
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListQuery
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListController
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.editorial.common.encodeEditorialFilterParam
import it.fabiodalez.incitta.ui.editorial.common.parseEditorialFilterParam
import it.fabiodalez.incitta.ui.editorial.common.takeEditorialListTarget
import it.fabiodalez.incitta.ui.editorial.venues.ApplicationDetailRoute
import it.fabiodalez.incitta.ui.editorial.venues.FlowRowActions
import it.fabiodalez.incitta.ui.editorial.venues.StatusLabel
import it.fabiodalez.incitta.ui.editorial.venues.SwitchRow
import it.fabiodalez.incitta.ui.editorial.venues.VenueDetailRoute
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import java.io.File
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/*
 * Le richieste di iscrizione (R-AP-01…06): la coda con le schede per stato
 * (si apre su «In attesa», come il pannello), i filtri tipo di richiesta e
 * tipo di locale, il segnale «possibile doppione»; la pagina di una
 * richiesta da leggere dall'alto in basso, con le note interne, «Approva»,
 * «Rifiuta» (motivo, avviso facoltativo, messaggio) ed «Elimina».
 */

private val dayFormat = DateTimeFormatter.ofPattern("d/M/yyyy HH:mm", Locale.ITALIAN)

/** Una decisione in attesa di conferma. */
internal sealed interface ApplicationDecision {
    val id: Long
    val name: String
    data class Approve(override val id: Long, override val name: String, val kind: String) : ApplicationDecision
    data class Reject(override val id: Long, override val name: String, val canNotify: Boolean, val email: String?) : ApplicationDecision
    data class Delete(override val id: Long, override val name: String) : ApplicationDecision
}

internal data class ApplicationListUiState(
    val decision: ApplicationDecision? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val kinds: List<OptionChoice> = emptyList(),
    val types: List<OptionChoice> = emptyList(),
)

internal class ApplicationListViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    /* Aperta da un riquadro del riepilogo (`ListHandoff`): la scheda e i filtri diventano i criteri salvati, una volta sola. */
    private val start: ListQuery = run {
        saved.takeEditorialListTarget(LIST)?.let { target ->
            saved[KEY_TAB] = target.tab ?: DEFAULT_TAB
            saved[KEY_FILTERS] = target.filterParam
        }
        ListQuery(tab = saved.get<String>(KEY_TAB)?.takeIf { it.isNotBlank() } ?: DEFAULT_TAB, filters = parseEditorialFilterParam(saved[KEY_FILTERS]))
    }
    val list = PagedListController(viewModelScope, start) { query, page ->
        repository.call { source.applications(query.params(page)) }
    }
    private val state = MutableStateFlow(ApplicationListUiState())
    val uiState: StateFlow<ApplicationListUiState> = state.asStateFlow()

    init {
        list.refresh()
        viewModelScope.launch {
            val options = repository.options()
            state.update { it.copy(kinds = options?.choices("application_kinds").orEmpty(), types = options?.choices("venue_types").orEmpty()) }
        }
    }

    fun selectTab(tab: String?) { saved[KEY_TAB] = tab ?: ALL; list.selectTab(tab ?: ALL) }
    fun applyFilters(filters: Map<String, String>) { saved[KEY_FILTERS] = encodeEditorialFilterParam(filters); list.applyFilters(filters) }

    fun ask(decision: ApplicationDecision) = state.update { it.copy(decision = decision, error = null, message = null) }
    fun dismiss() = state.update { it.copy(decision = null, error = null) }

    fun approve() = decide { decision -> repository.call { source.approveApplication(decision.id) }.message }

    fun reject(notes: String, notify: Boolean, message: String?) =
        decide { decision -> repository.call { source.rejectApplication(decision.id, notes, notify, message) }.let { listOfNotNull(it.message, it.detail).joinToString(" ") } }

    private fun decide(call: suspend (ApplicationDecision) -> String) {
        val decision = state.value.decision ?: return
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val message = call(decision)
                state.update { it.copy(decision = null, message = message) }
                list.refresh()
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        /* Il nome dell'elenco per il riepilogo (`target.area`). */
        const val LIST = "applications"
        const val KEY_TAB = "tab"
        const val KEY_FILTERS = "application_filters"
        const val DEFAULT_TAB = "pending"
        const val ALL = "all"
    }
}

@Composable
internal fun ApplicationListScreen(model: ApplicationListViewModel, context: EditorialGraphContext) {
    val list by model.list.current.collectAsStateWithLifecycle()
    val state by model.uiState.collectAsStateWithLifecycle()
    ApplicationListContent(
        state = list,
        ui = state,
        padding = context.padding,
        onBack = context::back,
        onSearch = model.list::search,
        onTab = model::selectTab,
        onFilters = model::applyFilters,
        onLoadMore = model.list::loadMore,
        onRetry = model.list::retry,
        onOpen = { context.navController.navigate(it) },
        onDecision = model::ask,
    )
    DecisionDialog(state.decision, state.busy, state.error, onApprove = model::approve, onReject = model::reject, onDelete = {}, onDismiss = model::dismiss)
}

/** La coda senza ViewModel: la provano i test strumentati. */
@Composable
internal fun ApplicationListContent(
    state: PagedState<ApplicationRow>,
    ui: ApplicationListUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onTab: (String?) -> Unit,
    onFilters: (Map<String, String>) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (ApplicationDetailRoute) -> Unit,
    onDecision: (ApplicationDecision) -> Unit,
) {
    PagedListScreen(
        title = stringResource(R.string.editorial_applications_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        tabs = listOf(
            ListTab("pending", stringResource(R.string.editorial_venues_tab_pending)),
            ListTab("approved", stringResource(R.string.editorial_venues_tab_approved_f)),
            ListTab("rejected", stringResource(R.string.editorial_venues_tab_rejected)),
            ListTab(ApplicationListViewModel.ALL, stringResource(R.string.editorial_venues_tab_all)),
        ),
        onTab = onTab,
        filters = listOf(
            FilterDefinition("kind", stringResource(R.string.editorial_application_kind), ui.kinds),
            FilterDefinition("type", stringResource(R.string.editorial_venue_type), ui.types),
        ).filter { it.options.isNotEmpty() },
        onFilters = onFilters,
        searchLabel = stringResource(R.string.editorial_applications_search),
        emptyText = stringResource(R.string.editorial_applications_empty),
        header = { ui.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) } },
    ) { row ->
        val abilities = abilitiesOf(row.abilities)
        Row(
            Modifier.fillMaxWidth().clickable(role = Role.Button) { onOpen(ApplicationDetailRoute(row.id)) }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(row.venueName, style = MaterialTheme.typography.titleMedium)
                Text(listOf(row.kind.label, row.contactName).filter { it.isNotBlank() }.joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    StatusLabel(row.status)
                    if (row.possibleDuplicate) Text(stringResource(R.string.editorial_application_duplicate), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelLarge)
                }
            }
            ActionMenu(
                listOf(RowAction("approve", stringResource(R.string.editorial_venue_approve)), RowAction("reject", stringResource(R.string.editorial_venue_reject), destructive = true)),
                abilities,
                onAction = { action ->
                    onDecision(if (action.key == "approve") ApplicationDecision.Approve(row.id, row.venueName, row.kind.value) else ApplicationDecision.Reject(row.id, row.venueName, canNotify = true, email = null))
                },
                subject = row.venueName,
            )
        }
        HorizontalDivider()
    }
}

/** Le finestre di «Approva», «Rifiuta» ed «Elimina», uguali dalla coda e dalla pagina. */
@Composable
internal fun DecisionDialog(
    decision: ApplicationDecision?,
    busy: Boolean,
    error: String?,
    onApprove: () -> Unit,
    onReject: (String, Boolean, String?) -> Unit,
    onDelete: () -> Unit,
    onDismiss: () -> Unit,
) {
    when (decision) {
        is ApplicationDecision.Approve -> ConfirmDialog(
            title = stringResource(R.string.editorial_application_approve_title, decision.name),
            text = stringResource(when (decision.kind) {
                "claim" -> R.string.editorial_application_approve_claim
                "organizer" -> R.string.editorial_application_approve_organizer
                else -> R.string.editorial_application_approve_venue
            }) + (error?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.editorial_venue_approve),
            enabled = !busy,
            onConfirm = onApprove,
            onDismiss = onDismiss,
        )
        is ApplicationDecision.Reject -> RejectApplicationDialog(decision, busy, error, onReject, onDismiss)
        is ApplicationDecision.Delete -> ConfirmDialog(
            title = stringResource(R.string.editorial_application_delete_title, decision.name),
            text = stringResource(R.string.editorial_application_delete_text) + (error?.let { "\n\n$it" } ?: ""),
            confirmLabel = stringResource(R.string.editorial_application_delete),
            destructive = true,
            enabled = !busy,
            onConfirm = onDelete,
            onDismiss = onDismiss,
        )
        null -> Unit
    }
}

@Composable
private fun RejectApplicationDialog(decision: ApplicationDecision.Reject, busy: Boolean, error: String?, onReject: (String, Boolean, String?) -> Unit, onDismiss: () -> Unit) {
    var notes by rememberSaveable { mutableStateOf("") }
    var notify by rememberSaveable { mutableStateOf(false) }
    var message by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_application_reject_title, decision.name)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(notes, { notes = it.take(NOTES_MAX) }, label = { Text(stringResource(R.string.editorial_application_reject_reason)) },
                    supportingText = { Text(stringResource(R.string.editorial_application_reject_reason_help)) }, minLines = 3, modifier = Modifier.fillMaxWidth())
                SwitchRow(
                    stringResource(R.string.editorial_application_reject_notify),
                    if (decision.canNotify) decision.email?.let { stringResource(R.string.editorial_application_reject_notify_help, it) } else stringResource(R.string.editorial_application_reject_notify_unavailable),
                    notify,
                ) { if (decision.canNotify) notify = it }
                if (notify) {
                    OutlinedTextField(message, { message = it.take(MESSAGE_MAX) }, label = { Text(stringResource(R.string.editorial_application_reject_message)) },
                        supportingText = { Text(stringResource(R.string.editorial_application_reject_message_help)) }, minLines = 3, modifier = Modifier.fillMaxWidth())
                }
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && notes.isNotBlank(), onClick = { onReject(notes, notify, message.takeIf { notify }) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_venue_reject), color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

private const val NOTES_MAX = 5000
private const val MESSAGE_MAX = 2000

internal data class ApplicationDetailUiState(
    val application: Loadable<ApplicationDetail> = Loadable.Loading,
    val notes: String? = null,
    val decision: ApplicationDecision? = null,
    val busy: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val image: File? = null,
    val deleted: Boolean = false,
)

internal class ApplicationDetailViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    private val saved: SavedStateHandle,
) : ViewModel() {
    val applicationId: Long = saved.get<Long>(KEY_APPLICATION) ?: 0L
    private val state = MutableStateFlow(ApplicationDetailUiState(notes = saved[KEY_NOTES]))
    val uiState: StateFlow<ApplicationDetailUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch {
            try {
                val detail = repository.call { source.application(applicationId) }
                state.update { it.copy(application = Loadable.Ready(detail), notes = it.notes ?: detail.notes.orEmpty()) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(application = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** L'immagine allegata sta sul disco privato del server: si scarica con il token nella cache dell'app. */
    fun loadImage(cacheDir: File) {
        val detail = (state.value.application as? Loadable.Ready)?.value ?: return
        if (!detail.hasImage || state.value.image != null) return
        viewModelScope.launch {
            try {
                ManagementFiles.purge(cacheDir)
                val file = repository.call { source.applicationImage(applicationId, File(ManagementFiles.directory(cacheDir), "richiesta-$applicationId")) }
                state.update { it.copy(image = file.file) }
            } catch (e: CancellationException) { throw e }
            catch (_: Exception) { /* Senza immagine la pagina resta leggibile. */ }
        }
    }

    fun editNotes(value: String) { saved[KEY_NOTES] = value; state.update { it.copy(notes = value, message = null) } }

    fun saveNotes() = write { repository.call { source.saveApplicationNotes(applicationId, state.value.notes) }.let { it.application to it.message } }

    fun ask(decision: ApplicationDecision) = state.update { it.copy(decision = decision, error = null, message = null) }
    fun dismiss() = state.update { it.copy(decision = null, error = null) }

    fun approve() = write { repository.call { source.approveApplication(applicationId) }.let { it.application to listOfNotNull(it.message, it.detail).joinToString(" ") } }

    fun reject(notes: String, notify: Boolean, message: String?) =
        write { repository.call { source.rejectApplication(applicationId, notes, notify, message) }.let { it.application to listOfNotNull(it.message, it.detail).joinToString(" ") } }

    fun delete() {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                repository.call { source.deleteApplication(applicationId) }
                state.update { it.copy(decision = null, deleted = true) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    private fun write(call: suspend () -> Pair<ApplicationDetail, String>) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, error = null) }
        viewModelScope.launch {
            try {
                val (detail, message) = call()
                saved.remove<String>(KEY_NOTES)
                state.update { it.copy(application = Loadable.Ready(detail), notes = detail.notes.orEmpty(), decision = null, message = message) }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(error = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object {
        const val KEY_APPLICATION = "applicationId"
        const val KEY_NOTES = "notes"
    }
}

@Composable
internal fun ApplicationDetailScreen(model: ApplicationDetailViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    val android = LocalContext.current
    val uriHandler = LocalUriHandler.current
    val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
    LaunchedEffect(state.deleted) { if (state.deleted) context.back() }
    val loaded = (state.application as? Loadable.Ready)?.value
    LaunchedEffect(loaded?.hasImage) { if (loaded?.hasImage == true) model.loadImage(android.cacheDir) }
    ManagementScaffold(title = loaded?.venueName ?: stringResource(R.string.editorial_application_title), padding = context.padding, onBack = context::back,
        subtitle = loaded?.let { stringResource(R.string.editorial_application_subtitle, it.kind.label, it.status.label) }, busy = state.busy) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        if (state.decision == null) state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        LoadState(state.application, onRetry = model::load) { detail ->
            val can = abilitiesOf(detail.abilities)
            FlowRowActions {
                if (can["approve"] == true) Button(onClick = { model.ask(ApplicationDecision.Approve(detail.id, detail.venueName, detail.kind.value)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venue_approve))
                }
                if (can["reject"] == true) OutlinedButton(onClick = { model.ask(ApplicationDecision.Reject(detail.id, detail.venueName, detail.account != null, detail.account?.email)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venue_reject), color = MaterialTheme.colorScheme.error)
                }
                if (can["destroy"] == true) TextButton(onClick = { model.ask(ApplicationDecision.Delete(detail.id, detail.venueName)) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_application_delete), color = MaterialTheme.colorScheme.error)
                }
            }

            Section(stringResource(R.string.editorial_application_outcome), detail.outcomeHint)
            Line(R.string.editorial_venue_status, detail.status.label)
            Line(R.string.editorial_application_kind, detail.kind.label)
            Line(R.string.editorial_application_received, cityLocalDateTime(detail.createdAt, zone)?.format(dayFormat))
            Line(R.string.editorial_application_queued, cityLocalDateTime(detail.queuedAt, zone)?.format(dayFormat) ?: stringResource(R.string.editorial_venues_none))
            Line(R.string.editorial_application_reviewed, detail.reviewedBy?.let { name -> stringResource(R.string.editorial_application_reviewed_by, name, cityLocalDateTime(detail.reviewedAt, zone)?.format(dayFormat).orEmpty()) }
                ?: stringResource(R.string.editorial_application_not_reviewed))
            detail.linked?.let { linked ->
                val label = stringResource(if (linked.kind == "organizer") R.string.editorial_application_linked_organizer else R.string.editorial_application_linked_venue)
                when {
                    linked.kind == "venue" -> TextButton(onClick = { context.navController.navigate(VenueDetailRoute(linked.id)) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("$label: ${linked.name}") }
                    linked.url != null -> TextButton(onClick = { runCatching { uriHandler.openUri(linked.url) } }, modifier = Modifier.heightIn(min = 48.dp)) { Text("$label: ${linked.name}") }
                    else -> Line(if (linked.kind == "organizer") R.string.editorial_application_linked_organizer else R.string.editorial_application_linked_venue, linked.name)
                }
            }
            if (detail.duplicates.isNotEmpty()) {
                Text(stringResource(R.string.editorial_application_duplicate), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.titleMedium)
                Text(stringResource(R.string.editorial_application_duplicate_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                detail.duplicates.forEach { duplicate ->
                    when (duplicate.kind) {
                        "application" -> TextButton(onClick = { context.navController.navigate(ApplicationDetailRoute(duplicate.id)) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(duplicate.label) }
                        "venue" -> TextButton(onClick = { context.navController.navigate(VenueDetailRoute(duplicate.id)) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(duplicate.label) }
                        else -> Text("• " + duplicate.label)
                    }
                }
            }

            Section(stringResource(if (detail.kind.value == "organizer") R.string.editorial_application_section_organizer else R.string.editorial_application_section_venue), null)
            Line(R.string.editorial_application_name, detail.venueName)
            if (detail.kind.value != "organizer") {
                Line(R.string.editorial_venue_type, detail.type?.label)
                Line(R.string.editorial_venue_address, detail.address ?: stringResource(R.string.editorial_venues_none))
            }
            Line(R.string.editorial_venue_postal_code, detail.postalCode)
            Line(R.string.editorial_venue_municipality, detail.municipality)
            Line(R.string.editorial_venue_capacity, detail.capacity)
            detail.description?.takeIf { it.isNotBlank() }?.let { Line(R.string.editorial_application_description, RichText.summary(it)) }
            Column {
                Text(detail.messageLabel, color = Muted, style = MaterialTheme.typography.bodySmall)
                Text(detail.message?.let { RichText.summary(it) }?.takeIf { it.isNotBlank() } ?: stringResource(R.string.editorial_venues_none))
            }

            detail.publicContacts?.let { contacts ->
                Section(stringResource(R.string.editorial_application_public_contacts), stringResource(R.string.editorial_application_public_contacts_help))
                Line(R.string.editorial_venue_website, contacts.website ?: stringResource(R.string.editorial_venues_none))
                Line(R.string.editorial_application_public_email, contacts.publicEmail ?: stringResource(R.string.editorial_venues_none))
                Line(R.string.editorial_application_public_phone, contacts.publicPhone ?: stringResource(R.string.editorial_venues_none))
                Line(R.string.editorial_application_instagram, contacts.instagram ?: stringResource(R.string.editorial_venues_none))
                Line(R.string.editorial_application_facebook, contacts.facebook ?: stringResource(R.string.editorial_venues_none))
            }

            if (detail.hasImage) {
                Section(stringResource(R.string.editorial_application_image), stringResource(R.string.editorial_application_image_help))
                state.image?.let { file ->
                    AsyncImage(model = file, contentDescription = stringResource(R.string.editorial_application_image_alt), contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxWidth().heightIn(max = 220.dp).background(MaterialTheme.colorScheme.surfaceVariant))
                } ?: Text(stringResource(R.string.editorial_application_image_loading), color = Muted)
            }

            Section(stringResource(R.string.editorial_application_contact), stringResource(R.string.editorial_application_contact_help))
            Line(R.string.editorial_application_contact_name, detail.contactName)
            Line(R.string.editorial_application_contact_role, detail.contactRole ?: stringResource(R.string.editorial_venues_none))
            Line(R.string.editorial_application_contact_email, detail.contactEmail)
            Line(R.string.editorial_application_contact_phone, detail.contactPhone ?: stringResource(R.string.editorial_venues_none))
            Line(R.string.editorial_application_account, detail.account?.let { "${it.name} · ${it.email}" } ?: stringResource(R.string.editorial_application_no_account))
            detail.account?.let { Line(R.string.editorial_application_account_state, stringResource(if (it.verified) R.string.editorial_application_account_verified else R.string.editorial_application_account_unverified)) }

            Section(stringResource(R.string.editorial_application_notes), stringResource(R.string.editorial_application_notes_help))
            if (can["notes"] == true) {
                OutlinedTextField(state.notes.orEmpty(), { model.editNotes(it.take(detail.notesMax)) }, label = { Text(stringResource(R.string.editorial_application_notes)) },
                    supportingText = { Text(stringResource(R.string.editorial_venues_counter, state.notes.orEmpty().length, detail.notesMax)) }, minLines = 4, modifier = Modifier.fillMaxWidth())
                Button(onClick = model::saveNotes, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_application_save_notes)) }
            } else Text(detail.notes ?: stringResource(R.string.editorial_venues_none))
        }
    }
    DecisionDialog(state.decision, state.busy, state.error, onApprove = model::approve, onReject = model::reject, onDelete = model::delete, onDismiss = model::dismiss)
}

@Composable
private fun Section(title: String, help: String?) {
    Spacer(Modifier.height(4.dp))
    Text(title, style = MaterialTheme.typography.titleLarge)
    help?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
}

@Composable
private fun Line(label: Int, value: String?) {
    if (value.isNullOrBlank()) return
    Column {
        Text(stringResource(label), color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(value)
    }
}
