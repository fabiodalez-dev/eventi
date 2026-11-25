package it.fabiodalez.incitta.ui.management.collaborators

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.CheckinInvitation
import it.fabiodalez.incitta.data.management.VenueAccess
import it.fabiodalez.incitta.data.management.VenueInvitation
import it.fabiodalez.incitta.data.management.VenueMember
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

/** Una data del server (`2026-11-25T21:00:00+01:00`) nel fuso con cui arriva, quello della città: «25 nov 2026». */
internal fun peopleDay(value: String?): String? = value?.let { raw ->
    runCatching { OffsetDateTime.parse(raw).format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ITALIAN)) }.getOrNull()
}

/** Data e ora nel fuso della città: «sab 17 ott 2026, 21:00». */
internal fun peopleDateTime(value: String?): String? = value?.let { raw ->
    runCatching { OffsetDateTime.parse(raw).format(DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)) }.getOrNull()
}

/** «Collaboratori»: «Il tuo accesso» per tutti; elenco, invito e azioni per il referente (W-CO-01…05). */
@Composable
internal fun CollaboratorsScreen(viewModel: CollaboratorsViewModel, context: ManagementGraphContext, onOpenAccess: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var pending by remember { mutableStateOf<Pair<VenueMember, MemberAction>?>(null) }
    ManagementScaffold(
        title = stringResource(R.string.collaborators_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = stringResource(R.string.collaborators_lead),
        busy = state.busy,
    ) {
        ManagementRow(stringResource(R.string.collaborators_your_access), stringResource(R.string.collaborators_your_access_help), onClick = onOpenAccess)
        LoadState(state.access, onRetry = viewModel::load) { access ->
            if (!access.canManageCollaborators) {
                Text(stringResource(R.string.collaborators_only_owner), color = Muted)
                return@LoadState
            }
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Button(onClick = { viewModel.openInvite(true) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.collaborators_invite))
                }
                state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
                state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(
                    value = state.query,
                    onValueChange = viewModel::setQuery,
                    label = { Text(stringResource(R.string.collaborators_search)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = { viewModel.search() }),
                    trailingIcon = {
                        TextButton(onClick = viewModel::search, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collaborators_search_action)) }
                    },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (state.loaded && state.members.isEmpty()) Text(stringResource(R.string.collaborators_empty), color = Muted)
                state.members.forEach { member -> MemberRow(member, enabled = !state.busy, onAction = { action -> pending = member to action }) }
                state.meta.nextPage?.let {
                    OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collaborators_more)) }
                }
            }
        }
    }
    if (state.inviteOpen) InviteSheet(state, viewModel)
    pending?.let { (member, action) ->
        val who = member.name ?: member.email
        ConfirmDialog(
            title = when (action) {
                MemberAction.Resend -> stringResource(R.string.collaborators_resend)
                MemberAction.Withdraw -> stringResource(R.string.collaborators_withdraw)
                MemberAction.Remove -> stringResource(R.string.collaborators_remove_title, who)
            },
            text = when (action) {
                MemberAction.Resend -> stringResource(R.string.collaborators_resend_confirm, state.meta.inviteExpiresDays)
                MemberAction.Withdraw -> stringResource(R.string.collaborators_withdraw_confirm)
                MemberAction.Remove -> stringResource(R.string.collaborators_remove_confirm)
            },
            confirmLabel = when (action) {
                MemberAction.Resend -> stringResource(R.string.collaborators_resend)
                MemberAction.Withdraw -> stringResource(R.string.collaborators_withdraw)
                MemberAction.Remove -> stringResource(R.string.collaborators_remove)
            },
            destructive = action != MemberAction.Resend,
            onConfirm = { pending = null; viewModel.act(member, action) },
            onDismiss = { pending = null },
        )
    }
}

/** Una persona: nome (o «Invito in attesa»), email, ruolo, quando ha accettato o è stata invitata, e le sue azioni. */
@Composable
private fun MemberRow(member: VenueMember, enabled: Boolean, onAction: (MemberAction) -> Unit) {
    val title = (member.name ?: stringResource(R.string.collaborators_pending_name)) + if (member.isMe) " " + stringResource(R.string.collaborators_you) else ""
    val actionsLabel = stringResource(R.string.collaborators_actions_for, member.name ?: member.email)
    Column(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium)
        Text(member.email, color = Muted)
        Text(member.roleLabel.ifBlank { member.role }, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
        val since = if (member.pending) {
            peopleDay(member.invitedAt)?.let { stringResource(R.string.collaborators_pending_since, it) } ?: stringResource(R.string.collaborators_pending)
        } else peopleDay(member.acceptedAt)?.let { stringResource(R.string.collaborators_accepted_on, it) }
        since?.let { Text(it, color = Muted) }
        if (member.canResend || member.canWithdraw || member.canRemove) {
            FlowRow(Modifier.semantics { contentDescription = actionsLabel }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (member.canResend) OutlinedButton(enabled = enabled, onClick = { onAction(MemberAction.Resend) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.collaborators_resend))
                }
                if (member.canWithdraw) OutlinedButton(enabled = enabled, onClick = { onAction(MemberAction.Withdraw) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.collaborators_withdraw), color = MaterialTheme.colorScheme.error)
                }
                if (member.canRemove) OutlinedButton(enabled = enabled, onClick = { onAction(MemberAction.Remove) }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.collaborators_remove), color = MaterialTheme.colorScheme.error)
                }
            }
        }
    }
    HorizontalDivider()
}

/** Il foglio d'invito: nome ed email; il ruolo è sempre collaboratore. Gli errori del server vanno sotto il campo. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun InviteSheet(state: CollaboratorsUiState, viewModel: CollaboratorsViewModel) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    ModalBottomSheet(onDismissRequest = { viewModel.openInvite(false) }, sheetState = sheet) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.collaborators_invite), style = MaterialTheme.typography.titleLarge)
            Text(stringResource(R.string.collaborators_invite_description), color = Muted)
            FormScaffold(
                errors = state.inviteErrors,
                busy = state.inviteBusy,
                submitLabel = stringResource(R.string.collaborators_invite_submit),
                onSubmit = viewModel::invite,
                submitEnabled = state.inviteName.isNotBlank() && state.inviteEmail.isNotBlank(),
            ) {
                FormTextField(state.inviteName, viewModel::setInviteName, stringResource(R.string.collaborators_invite_name), state.inviteErrors, "name", singleLine = true,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next))
                FormTextField(state.inviteEmail, viewModel::setInviteEmail, stringResource(R.string.collaborators_invite_email), state.inviteErrors, "email", singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Done))
                Text(stringResource(R.string.collaborators_invite_limits, state.meta.invitesPerHour, state.meta.invitesPerDay), color = Muted)
            }
            TextButton(onClick = { viewModel.openInvite(false) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.collaborators_invite_close)) }
        }
    }
}

/** «Il tuo accesso» (W-CO-06): ruolo, da quando, e «Lascia il locale», bloccato per l'ultimo referente. */
@Composable
internal fun MyAccessScreen(viewModel: MyAccessViewModel, context: ManagementGraphContext, onOpenCollaborators: () -> Unit, onLeft: (Int) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var confirming by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(state.left) { state.left?.let(onLeft) }
    ManagementScaffold(title = stringResource(R.string.access_title), padding = context.padding, onBack = context::back, busy = state.busy) {
        LoadState(state.access, onRetry = viewModel::load) { access ->
            AccessDetails(access)
            HorizontalDivider()
            Text(stringResource(R.string.access_danger_title), style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.error)
            Text(stringResource(R.string.access_danger_body), color = Muted)
            if (access.isLastOwner) {
                Text(stringResource(R.string.access_last_owner), color = MaterialTheme.colorScheme.error)
                if (access.canManageCollaborators) OutlinedButton(onClick = onOpenCollaborators, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.access_go_collaborators))
                }
            }
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(
                enabled = access.canLeave && !state.busy,
                onClick = { confirming = true },
                colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error, contentColor = MaterialTheme.colorScheme.onError),
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.access_leave)) }
            if (confirming) ConfirmDialog(
                title = stringResource(R.string.access_leave_title, access.venue.name),
                text = stringResource(R.string.access_leave_confirm, access.venue.name),
                confirmLabel = stringResource(R.string.access_leave),
                destructive = true,
                enabled = !state.busy,
                onConfirm = { confirming = false; viewModel.leave() },
                onDismiss = { confirming = false },
            )
        }
    }
}

@Composable
private fun AccessDetails(access: VenueAccess) {
    Text(stringResource(R.string.access_role_title), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.access_role_body, access.venue.name, access.roleLabel.lowercase(Locale.ITALIAN)))
    Text(stringResource(if (access.isOwner) R.string.access_owner_can else R.string.access_editor_can), color = Muted)
    peopleDay(access.memberSince)?.let { Text(stringResource(R.string.access_since, it), color = Muted) }
}

/** «I tuoi inviti» (W-CO-07, W-TK-15): accetta o rifiuta, con la conferma per il rifiuto. */
@Composable
internal fun InvitationsScreen(viewModel: InvitationsViewModel, context: ManagementGraphContext, onBack: () -> Unit, onOutcome: (InvitationOutcome) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var declineVenue by remember { mutableStateOf<VenueInvitation?>(null) }
    var declineCheckin by remember { mutableStateOf<CheckinInvitation?>(null) }
    LaunchedEffect(state.outcome) { state.outcome?.let { viewModel.outcomeHandled(); onOutcome(it) } }
    ManagementScaffold(
        title = stringResource(R.string.invitations_title),
        padding = context.padding,
        onBack = onBack,
        subtitle = stringResource(R.string.invitations_lead),
        busy = state.busy,
    ) {
        state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        LoadState(state.invitations, onRetry = viewModel::load, emptyText = stringResource(R.string.invitations_empty)) { invitations ->
            if (invitations.venues.isNotEmpty()) {
                Text(stringResource(R.string.invitations_venues), style = MaterialTheme.typography.titleLarge)
                invitations.venues.forEach { invitation ->
                    InvitationCard(
                        title = invitation.venue.name,
                        subtitle = invitation.venue.city,
                        lead = stringResource(R.string.invitations_venue_lead, invitation.venue.name, invitation.roleLabel.lowercase(Locale.ITALIAN)),
                        consequences = stringResource(R.string.invitations_venue_consequences),
                        expires = invitation.expiresAt,
                        enabled = !state.busy,
                        onAccept = { viewModel.answerVenue(invitation, accept = true) },
                        onDecline = { declineVenue = invitation },
                    )
                }
            }
            if (invitations.checkin.isNotEmpty()) {
                Text(stringResource(R.string.invitations_checkin), style = MaterialTheme.typography.titleLarge)
                invitations.checkin.forEach { invitation ->
                    val date = peopleDateTime(invitation.occurrence.startsAt) ?: invitation.occurrence.startsAt.orEmpty()
                    InvitationCard(
                        title = invitation.occurrence.title,
                        subtitle = listOfNotNull(date.ifBlank { null }, invitation.occurrence.venue).joinToString(" · "),
                        lead = stringResource(R.string.invitations_checkin_lead, invitation.occurrence.title, date),
                        consequences = stringResource(R.string.invitations_checkin_consequences),
                        expires = invitation.expiresAt,
                        enabled = !state.busy,
                        onAccept = { viewModel.answerCheckin(invitation, accept = true) },
                        onDecline = { declineCheckin = invitation },
                    )
                }
            }
        }
    }
    declineVenue?.let { invitation ->
        ConfirmDialog(
            title = stringResource(R.string.invitations_decline_title),
            text = stringResource(R.string.invitations_decline_venue, invitation.venue.name),
            confirmLabel = stringResource(R.string.invitations_decline),
            destructive = true,
            onConfirm = { declineVenue = null; viewModel.answerVenue(invitation, accept = false) },
            onDismiss = { declineVenue = null },
        )
    }
    declineCheckin?.let { invitation ->
        ConfirmDialog(
            title = stringResource(R.string.invitations_decline_title),
            text = stringResource(R.string.invitations_decline_checkin, invitation.occurrence.title),
            confirmLabel = stringResource(R.string.invitations_decline),
            destructive = true,
            onConfirm = { declineCheckin = null; viewModel.answerCheckin(invitation, accept = false) },
            onDismiss = { declineCheckin = null },
        )
    }
}

@Composable
private fun InvitationCard(
    title: String,
    subtitle: String?,
    lead: String,
    consequences: String,
    expires: String?,
    enabled: Boolean,
    onAccept: () -> Unit,
    onDecline: () -> Unit,
) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            subtitle?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
            Text(lead)
            Text(consequences, color = Muted)
            peopleDay(expires)?.let { Text(stringResource(R.string.invitations_expires, it), color = Muted) }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(enabled = enabled, onClick = onAccept, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.invitations_accept)) }
                OutlinedButton(enabled = enabled, onClick = onDecline, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.invitations_decline)) }
            }
        }
    }
}
