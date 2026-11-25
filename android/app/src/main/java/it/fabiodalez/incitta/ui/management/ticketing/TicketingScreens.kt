package it.fabiodalez.incitta.ui.management.ticketing

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.CheckinStaff
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.data.management.CheckinQueue
import it.fabiodalez.incitta.ui.Acid
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.Paper
import it.fabiodalez.incitta.ui.SecureCaptureActivity
import it.fabiodalez.incitta.ui.SecureWindow
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve

/** Le letture in attesa, uguali in ogni schermata della biglietteria, con «Riprova le letture». */
@Composable
private fun PendingCheckins(queue: CheckinQueue, busy: Boolean, onRetry: () -> Unit) {
    if (queue.pending.isEmpty()) return
    Text(stringResource(R.string.management_pending, queue.pending.size), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.management_pending_help), color = Muted)
    OutlinedButton(enabled = !busy, onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry_reads)) }
}

/** Elenco delle date gestite o da controllare, e le campagne. */
@Composable
internal fun TicketingDatesScreen(viewModel: TicketingDatesViewModel, context: ManagementGraphContext, onOpenDate: (Long) -> Unit, onOpenCampaign: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val queue by viewModel.checkins.collectAsStateWithLifecycle()
    val title = stringResource(if (context.atRoot) R.string.management_title else R.string.management_area_ticketing)
    ManagementScaffold(title = title, padding = context.padding, onBack = context::back, busy = state.busy) {
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        PendingCheckins(queue, state.busy, viewModel::retryCheckins)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(!state.reports, { viewModel.showReports(false) }, label = { Text(stringResource(R.string.management_dates)) }, modifier = Modifier.heightIn(min = 48.dp))
            FilterChip(state.reports, { viewModel.showReports(true) }, label = { Text(stringResource(R.string.management_reports)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
        if (!state.reports) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(!state.past, { viewModel.showPast(false) }, label = { Text(stringResource(R.string.management_upcoming)) }, modifier = Modifier.heightIn(min = 48.dp))
            FilterChip(state.past, { viewModel.showPast(true) }, label = { Text(stringResource(R.string.management_past)) }, modifier = Modifier.heightIn(min = 48.dp))
        }
        OutlinedButton(enabled = !state.busy, onClick = viewModel::refresh, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_refresh)) }
        if (!state.busy && state.loaded && (if (state.reports) state.campaigns.isEmpty() else state.dates.isEmpty())) Text(stringResource(R.string.management_empty), color = Muted)
        if (state.reports) state.campaigns.forEach { item ->
            TextButton(onClick = { onOpenCampaign(item.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) { Text("#${item.id} · ${item.title}") }
            HorizontalDivider()
        } else state.dates.forEach { item ->
            TextButton(onClick = { onOpenDate(item.id) }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                Column(Modifier.fillMaxWidth()) {
                    Text(item.title, style = MaterialTheme.typography.titleMedium)
                    Text(managementDate(item.startsAt), color = Muted)
                    Text(stringResource(if (item.canManage) R.string.management_manager_role else R.string.management_staff_role), color = Muted)
                }
            }
            HorizontalDivider()
        }
        state.nextPage?.let { OutlinedButton(enabled = !state.busy, onClick = viewModel::more, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_more)) } }
    }
}

/** La scheda di una data: controllo ingressi per chi può, e per chi gestisce staff, messaggio, prenotazione al banco, informazioni e costi. */
@Composable
internal fun TicketingDateScreen(viewModel: TicketingDateViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val queue by viewModel.checkins.collectAsStateWithLifecycle()
    val android = LocalContext.current
    val texts = ScanTexts(stringResource(R.string.management_offline_unknown), stringResource(R.string.management_offline_used))
    val scanPrompt = stringResource(R.string.management_scan_prompt)
    val scanner = rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let { viewModel.addCode(it, isOnline(android), texts) } }
    var removeStaff by remember { mutableStateOf<CheckinStaff?>(null) }
    val selected = state.current
    ManagementScaffold(title = selected?.title ?: stringResource(R.string.management_date_title), padding = context.padding, onBack = context::back, busy = state.busy) {
        state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        state.message?.let { Text(it.resolve(), color = Acid) }
        PendingCheckins(queue, state.busy, viewModel::retryCheckins)
        LoadState(state.date, onRetry = viewModel::load) { date ->
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                DateHeader(date, state.busy, viewModel::refresh)
                if (date.canCheckIn) CheckinBlock(date, state, queue, onScan = {
                    scanner.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt(scanPrompt).setBeepEnabled(false).setOrientationLocked(false).setCaptureActivity(SecureCaptureActivity::class.java))
                }, onManual = { viewModel.addCode(it, isOnline(android), texts) }, onRefreshList = viewModel::refreshManifest)
                if (date.canManage) ManagerBlock(date, state, viewModel, onRemoveStaff = { removeStaff = it })
            }
        }
    }
    removeStaff?.let { member ->
        ConfirmDialog(
            title = stringResource(R.string.management_revoke),
            text = stringResource(R.string.management_revoke_confirm, member.email),
            confirmLabel = stringResource(R.string.management_revoke),
            onConfirm = { removeStaff = null; viewModel.removeStaff(member) },
            onDismiss = { removeStaff = null },
            destructive = true,
            enabled = !state.busy,
        )
    }
}

@Composable
private fun DateHeader(date: ManagedDate, busy: Boolean, onRefresh: () -> Unit) {
    Text(managementDate(date.startsAt))
    date.venue?.let { Text(it, color = Muted) }
    OutlinedButton(enabled = !busy, onClick = onRefresh, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_refresh)) }
}

@Composable
private fun CheckinBlock(date: ManagedDate, state: TicketingDateUiState, queue: CheckinQueue, onScan: () -> Unit, onManual: (String) -> Unit, onRefreshList: () -> Unit) {
    // Codici e nomi del check-in: niente screenshot né anteprima tra le app recenti.
    SecureWindow()
    Text(stringResource(R.string.management_checkin), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_online_only), color = Muted)
    val list = state.manifest?.takeIf { it.occurrenceId == date.id }
    Text(
        if (list != null) stringResource(R.string.management_offline_loaded, manifestClock(list.generatedAt), list.size)
        else stringResource(R.string.management_offline_missing),
        color = if (list != null) Paper else MaterialTheme.colorScheme.error,
        style = MaterialTheme.typography.titleSmall,
    )
    state.manifestError?.let { Text(stringResource(R.string.management_offline_refresh_failed, it), color = Muted) }
    OutlinedButton(enabled = !state.manifestBusy, onClick = onRefreshList, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_offline_refresh))
    }
    queue.pending.filter { it.occurrenceId == date.id }.take(20).forEach { read ->
        val name = list?.lookup(read.code)?.name
        Text(if (name != null) stringResource(R.string.management_offline_waiting_name, name) else stringResource(R.string.management_offline_waiting_code, read.code.take(6)), color = Muted)
    }
    Button(enabled = !state.busy, onClick = onScan, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.management_scan)) }
    var manual by rememberSaveable(date.id) { mutableStateOf("") }
    OutlinedTextField(manual, { manual = it.take(64) }, label = { Text(stringResource(R.string.management_manual)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
    OutlinedButton(enabled = !state.busy && manual.isNotBlank(), onClick = { onManual(manual); manual = "" }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_check_code))
    }
    queue.outcomes.filter { it.occurrenceId == date.id }.take(8).forEach { outcome ->
        Text(stringResource(if (outcome.accepted) R.string.management_accepted else R.string.management_rejected, outcome.message), color = if (outcome.accepted) Acid else MaterialTheme.colorScheme.error)
    }
}

@Composable
private fun ManagerBlock(date: ManagedDate, state: TicketingDateUiState, viewModel: TicketingDateViewModel, onRemoveStaff: (CheckinStaff) -> Unit) {
    val busy = state.busy
    HorizontalDivider()
    Text(stringResource(R.string.management_capacity), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_attended, date.statistics["checked_in"] ?: 0))
    Text(date.availability?.remaining?.let { stringResource(R.string.ticket_remaining, it) } ?: stringResource(R.string.management_capacity_unknown))
    HorizontalDivider()
    Text(stringResource(R.string.management_staff), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_staff_help), color = Muted)
    date.staff.forEach { member ->
        Text(if (member.pending) stringResource(R.string.management_staff_pending, member.email) else member.email)
        TextButton(enabled = !busy, onClick = { onRemoveStaff(member) }, modifier = Modifier.heightIn(min = 48.dp)) {
            Text(stringResource(if (member.pending) R.string.management_withdraw else R.string.management_revoke))
        }
    }
    var email by rememberSaveable(date.id) { mutableStateOf("") }
    OutlinedTextField(email, { email = it.take(255) }, label = { Text(stringResource(R.string.management_email)) }, singleLine = true,
        keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Email), modifier = Modifier.fillMaxWidth())
    OutlinedButton(enabled = !busy && email.isNotBlank(), onClick = { viewModel.inviteStaff(email) { email = "" } }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_assign))
    }
    HorizontalDivider()
    key(date.id) {
        AttendeeMessageForm(busy, state.messageDailyLimit) { subject, body, waitlisted, sent -> viewModel.sendMessage(subject, body, waitlisted, sent) }
    }
    HorizontalDivider()
    key(date.id) {
        GuestBookingForm(busy) { names, email, phone, note, saved -> viewModel.addGuestBooking(names, email, phone, note, saved) }
    }
    HorizontalDivider()
    key(date) { DecisionDetailsForm(date, busy, viewModel::saveDetails) }
}

/** La scheda di una campagna, in sola lettura. */
@Composable
internal fun CampaignScreen(viewModel: CampaignViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val campaign = (state as? it.fabiodalez.incitta.ui.management.common.Loadable.Ready)?.value
    ManagementScaffold(title = campaign?.title ?: stringResource(R.string.management_campaign_title), padding = context.padding, onBack = context::back) {
        LoadState(state, onRetry = viewModel::load) { item ->
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Text("${managementDate(item.startsAt)} — ${managementDate(item.endsAt)}", color = Muted)
                CampaignProgress(item)
                item.economics?.let { economics ->
                    MetricLine(R.string.management_spend, managementMoney(economics.spendCents, economics.currency))
                    MetricLine(R.string.management_bookings, economics.bookings?.toString())
                    MetricLine(R.string.management_attendances, economics.attendances?.toString())
                    MetricLine(R.string.management_per_booking, managementMoney(economics.perBookingCents, economics.currency))
                    MetricLine(R.string.management_per_attendance, managementMoney(economics.perAttendanceCents, economics.currency))
                }
                Text(stringResource(R.string.management_economics_help), color = Muted)
            }
        }
    }
}
