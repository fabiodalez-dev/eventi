package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.EditorialVenueOverview
import it.fabiodalez.incitta.data.editorial.EditorialVenuesSource
import it.fabiodalez.incitta.data.requestFailureMessage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.EditorialGraphContext
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.RichText
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
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
 * La scheda di un locale in sola lettura (R-VN-06), come `ViewVenue` del
 * pannello: testata con copertina, stato e verifica, la moderazione in
 * chiaro (approva, verifica, sospendi, rifiuta), le informazioni, la
 * descrizione, i prossimi eventi e chi lo gestisce. Da qui si va alla
 * modifica, alle immagini, alle persone, agli eventi, alla cronologia; si
 * accende il ticketing e si elimina (password recente).
 */

internal data class VenueDetailUiState(
    val venue: Loadable<EditorialVenueOverview> = Loadable.Loading,
    val pending: PendingVenueAction? = null,
    val confirmDelete: Boolean = false,
    val busy: Boolean = false,
    val actionError: String? = null,
    val message: String? = null,
    val deleted: Boolean = false,
)

internal class VenueDetailViewModel(
    private val repository: EditorialRepository,
    private val source: EditorialVenuesSource,
    saved: SavedStateHandle,
) : ViewModel() {
    val venueId: Long = saved.get<Long>(KEY_VENUE) ?: 0L
    private val state = MutableStateFlow(VenueDetailUiState())
    val uiState: StateFlow<VenueDetailUiState> = state.asStateFlow()

    init { load() }

    fun load() {
        if (state.value.venue !is Loadable.Ready) state.update { it.copy(venue = Loadable.Loading) }
        viewModelScope.launch {
            try { state.update { it.copy(venue = Loadable.Ready(repository.call { source.venue(venueId) })) } }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { if (state.value.venue !is Loadable.Ready) state.update { it.copy(venue = Loadable.Failed(requestFailureMessage(e))) } }
        }
    }

    /** Tornando dalla modifica, dalle persone o dalle immagini: si rilegge solo se la scheda c'era già. */
    fun refreshIfLoaded() { if (state.value.venue is Loadable.Ready) load() }

    fun ask(action: VenueAction) {
        val venue = (state.value.venue as? Loadable.Ready)?.value ?: return
        state.update { it.copy(pending = PendingVenueAction(venue.id, venue.name, action, venue.isVerified), actionError = null, message = null) }
    }

    fun dismiss() = state.update { it.copy(pending = null, confirmDelete = false, actionError = null) }

    fun confirm(reason: String) {
        val pending = state.value.pending ?: return
        write {
            val result = repository.call { runVenueAction(source, pending, reason) }
            state.update { it.copy(pending = null, venue = Loadable.Ready(result.venue), message = result.message) }
        }
    }

    /** «Abilita il ticketing»: solo admin e super admin (`abilities.ticketing`). */
    fun setTicketing(enabled: Boolean) = write {
        val venue = repository.call { source.setTicketing(venueId, enabled) }
        state.update { it.copy(venue = Loadable.Ready(venue)) }
    }

    fun askDelete() = state.update { it.copy(confirmDelete = true, actionError = null) }

    fun delete() = write {
        repository.call { source.deleteVenue(venueId) }
        state.update { it.copy(confirmDelete = false, deleted = true) }
    }

    private fun write(block: suspend () -> Unit) {
        if (state.value.busy) return
        state.update { it.copy(busy = true, actionError = null) }
        viewModelScope.launch {
            try { block() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { state.update { it.copy(actionError = requestFailureMessage(e)) } }
            finally { state.update { it.copy(busy = false) } }
        }
    }

    companion object { const val KEY_VENUE = "venueId" }
}

@Composable
internal fun VenueDetailScreen(model: VenueDetailViewModel, context: EditorialGraphContext) {
    val state by model.uiState.collectAsStateWithLifecycle()
    LaunchedEffect(state.deleted) { if (state.deleted) context.back() }
    /* Si rilegge tornando dalla modifica, dalle persone o dalle immagini. */
    LaunchedEffect(Unit) { model.refreshIfLoaded() }
    val nav = context.navController
    val zone = context.repository.current.value?.city?.zone ?: ZoneId.of("Europe/Rome")
    VenueDetailContent(
        state = state,
        zone = zone,
        padding = context.padding,
        onBack = context::back,
        onRetry = model::load,
        onAction = model::ask,
        onTicketing = model::setTicketing,
        onDelete = model::askDelete,
        onEdit = { nav.navigate(VenueEditRoute(model.venueId)) },
        onImages = { nav.navigate(VenueImagesEditorialRoute(model.venueId)) },
        onMembers = { nav.navigate(VenueMembersRoute(model.venueId)) },
        onEvents = { nav.navigate(VenueEventsRoute(model.venueId)) },
        onActivity = { nav.navigate(VenueActivityRoute(model.venueId)) },
    )
    state.pending?.let { VenueActionDialog(it, state.busy, state.actionError, model::confirm, model::dismiss) }
    if (state.confirmDelete) {
        val name = (state.venue as? Loadable.Ready)?.value?.name.orEmpty()
        ConfirmDialog(
            title = stringResource(R.string.editorial_venue_delete_title, name),
            text = stringResource(R.string.editorial_venue_delete_text),
            confirmLabel = stringResource(R.string.editorial_venue_delete),
            destructive = true,
            enabled = !state.busy,
            onConfirm = model::delete,
            onDismiss = model::dismiss,
        )
    }
}

private val upcomingFormat = DateTimeFormatter.ofPattern("EEE d/M/yyyy, HH:mm", Locale.ITALIAN)

/** La scheda senza ViewModel: la provano i test strumentati. */
@Composable
internal fun VenueDetailContent(
    state: VenueDetailUiState,
    zone: ZoneId,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onAction: (VenueAction) -> Unit,
    onTicketing: (Boolean) -> Unit,
    onDelete: () -> Unit,
    onEdit: () -> Unit,
    onImages: () -> Unit,
    onMembers: () -> Unit,
    onEvents: () -> Unit,
    onActivity: () -> Unit,
) {
    val uriHandler = LocalUriHandler.current
    val venue = (state.venue as? Loadable.Ready)?.value
    ManagementScaffold(title = venue?.name ?: stringResource(R.string.editorial_venue_title), padding = padding, onBack = onBack, subtitle = venue?.subtitle, busy = state.busy) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        if (state.pending == null) state.actionError?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        LoadState(state.venue, onRetry = onRetry) { loaded ->
            val can = abilitiesOf(loaded.abilities)
            if (loaded.coverUrl != null) {
                AsyncImage(model = loaded.coverUrl, contentDescription = null, contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxWidth().aspectRatio(3f / 2f).background(MaterialTheme.colorScheme.surfaceVariant))
            } else Text(stringResource(R.string.editorial_venue_no_cover), color = Muted)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                StatusLabel(loaded.status)
                Text(stringResource(if (loaded.isVerified) R.string.editorial_venue_verified else R.string.editorial_venue_not_verified), color = Muted)
            }
            Text(pluralStringResource(R.plurals.editorial_venue_events_count, loaded.eventsCount, loaded.eventsCount), color = Muted)

            /* La moderazione in chiaro, nell'ordine dell'intestazione del pannello: approva, verifica, sospendi, rifiuta. */
            FlowRowActions {
                if (can["approve"] == true) Button(onClick = { onAction(VenueAction.Approve) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_approve)) }
                if (can["verify"] == true) OutlinedButton(onClick = { onAction(VenueAction.Verify) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (loaded.isVerified) R.string.editorial_venue_unverify else R.string.editorial_venue_verify))
                }
                if (can["suspend"] == true) OutlinedButton(onClick = { onAction(VenueAction.Suspend) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venue_suspend), color = MaterialTheme.colorScheme.error)
                }
                if (can["reject"] == true) OutlinedButton(onClick = { onAction(VenueAction.Reject) }, enabled = !state.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venue_reject), color = MaterialTheme.colorScheme.error)
                }
            }
            if (can["update"] == true) Button(onClick = onEdit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_edit)) }
            loaded.siteUrl?.let { url ->
                OutlinedButton(onClick = { runCatching { uriHandler.openUri(url) } }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_venue_open_site)) }
            }
            Text(stringResource(R.string.editorial_venue_hint), color = Muted, style = MaterialTheme.typography.bodySmall)

            Text(stringResource(R.string.editorial_venue_info), style = MaterialTheme.typography.titleLarge)
            Fact(R.string.editorial_venue_status, loaded.status.label)
            Fact(R.string.editorial_venue_type, loaded.type.label)
            Fact(R.string.editorial_venue_city, loaded.city?.name)
            Fact(R.string.editorial_venue_address, listOfNotNull(loaded.address, loaded.addressExtra, loaded.postalCode, loaded.municipality).filter { it.isNotBlank() }.joinToString(", "))
            Fact(R.string.editorial_venue_phone, loaded.phone)
            Fact(R.string.editorial_venue_email, loaded.email)
            Fact(R.string.editorial_venue_website, loaded.website)
            Fact(R.string.editorial_venue_capacity, loaded.capacity?.toString())
            Fact(R.string.editorial_venue_plan, loaded.plan.label)
            Fact(R.string.editorial_venue_auto_publish, stringResource(if (loaded.autoPublish) R.string.editorial_venues_yes else R.string.editorial_venues_no))
            Fact(R.string.editorial_venue_rejection_reason, loaded.rejectionReason)

            if (can["ticketing"] == true) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.editorial_venue_ticketing), style = MaterialTheme.typography.titleMedium)
                        Text(stringResource(R.string.editorial_venue_ticketing_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                    Switch(checked = loaded.ticketingEnabled, onCheckedChange = onTicketing, enabled = !state.busy)
                }
            }

            Text(stringResource(R.string.editorial_venue_description), style = MaterialTheme.typography.titleLarge)
            val description = RichText.summary(loaded.description)
            Text(description.ifBlank { stringResource(R.string.editorial_venue_no_description) }, color = if (description.isBlank()) Muted else MaterialTheme.colorScheme.onSurface)

            Text(stringResource(R.string.editorial_venue_upcoming), style = MaterialTheme.typography.titleLarge)
            if (loaded.upcoming.isEmpty()) Text(stringResource(R.string.editorial_venue_no_upcoming), color = Muted)
            loaded.upcoming.forEach { event ->
                val start = cityLocalDateTime(event.startsAt, zone)?.format(upcomingFormat).orEmpty()
                Column(Modifier.padding(vertical = 4.dp)) {
                    Text(event.title, style = MaterialTheme.typography.titleSmall)
                    Text(listOf(start, event.category.orEmpty(), event.status.label).filter { it.isNotBlank() }.joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }

            Text(stringResource(R.string.editorial_venue_members), style = MaterialTheme.typography.titleLarge)
            if (loaded.members.isEmpty()) Text(stringResource(R.string.editorial_venue_no_members), color = Muted)
            loaded.members.forEach { member ->
                val role = listOfNotNull(member.role, if (member.pending) stringResource(R.string.editorial_venue_member_pending) else null).joinToString(" · ")
                Text(stringResource(R.string.editorial_venue_member_line, member.name, role))
            }

            HorizontalDivider()
            if (can["update"] == true) ManagementRow(stringResource(R.string.editorial_venue_images), stringResource(R.string.editorial_venue_images_help), onImages)
            if (can["members"] == true) ManagementRow(stringResource(R.string.editorial_venue_members_manage), stringResource(R.string.editorial_venue_members_manage_help), onMembers)
            ManagementRow(stringResource(R.string.editorial_venue_events), stringResource(R.string.editorial_venue_events_help), onEvents)
            ManagementRow(stringResource(R.string.editorial_venue_activity), stringResource(R.string.editorial_venue_activity_help), onActivity)
            if (can["destroy"] == true) {
                OutlinedButton(onClick = onDelete, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_venue_delete), color = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun FlowRowActions(content: @Composable FlowRowScope.() -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp), content = content)
}

@Composable
private fun Fact(label: Int, value: String?) {
    if (value.isNullOrBlank()) return
    Column {
        Text(stringResource(label), color = Muted, style = MaterialTheme.typography.bodySmall)
        Text(value, style = MaterialTheme.typography.bodyMedium)
    }
}
