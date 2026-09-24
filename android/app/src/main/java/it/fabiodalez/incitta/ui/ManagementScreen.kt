package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import java.text.NumberFormat
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Currency
import java.util.Locale

@Composable
internal fun ManagementScreen(session: Session?, padding: PaddingValues, client: ApiClient? = null, onBack: () -> Unit) {
    if (session == null) { LaunchedEffect(Unit) { onBack() }; return }
    val context = LocalContext.current
    val store = remember(session.user.id) { LocalStore(context) }
    val api = remember(session.token) { ManagementApi(client ?: ApiClient(store.installationId), session.token) }
    val outbox = remember(session.token) { CheckinOutbox(session.user.id, store::readCheckins, store::writeCheckins, api::checkIn) }
    val scope = rememberCoroutineScope()
    var reports by remember { mutableStateOf(false) }
    var past by remember { mutableStateOf(false) }
    var dates by remember { mutableStateOf(emptyList<ManagedDate>()) }
    var campaigns by remember { mutableStateOf(emptyList<ManagedCampaign>()) }
    var date by remember { mutableStateOf<ManagedDate?>(null) }
    var campaign by remember { mutableStateOf<ManagedCampaign?>(null) }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var pending by remember { mutableStateOf(outbox.pending()) }
    var outcomes by remember { mutableStateOf(emptyList<CheckinOutcome>()) }
    var removeStaff by remember { mutableStateOf<CheckinStaff?>(null) }
    val saved = stringResource(R.string.management_saved)
    val scanPrompt = stringResource(R.string.management_scan_prompt)
    val invalidCode = stringResource(R.string.management_invalid_code)
    val queueFull = stringResource(R.string.management_queue_full)
    suspend fun flush() {
        outbox.flush { result ->
            if (result != null) outcomes = (listOf(result) + outcomes).take(30)
            pending = outbox.pending()
        }
        pending = outbox.pending()
    }
    fun action(block: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true; error = null; message = null
            try { block() }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) { error = requestFailureMessage(e) }
            finally { busy = false }
        }
    }
    suspend fun load(page: Int = 1) {
        if (reports) {
            val response = api.campaigns(page)
            campaigns = if (page == 1) response.data else (campaigns + response.data).distinctBy { it.id }
            nextPage = response.meta?.nextPage
        } else {
            val response = api.dates(past, page)
            dates = if (page == 1) response.data else (dates + response.data).distinctBy { it.id }
            nextPage = response.meta?.nextPage
        }
    }
    LaunchedEffect(reports, past) {
        busy = true; error = null; nextPage = null; dates = emptyList(); campaigns = emptyList()
        try { load() } catch (e: CancellationException) { throw e } catch (e: Exception) { error = requestFailureMessage(e) }
        finally { busy = false }
    }
    LaunchedEffect(session.token) {
        while (true) {
            try { flush() } catch (e: CancellationException) { throw e } catch (_: Exception) { pending = outbox.pending() }
            delay(15000)
        }
    }
    fun addCode(value: String) {
        val current = date ?: return
        val code = value.trim()
        if (!code.matches(Regex("[A-Za-z0-9]{64}"))) { error = invalidCode; return }
        if (pending.size >= 200) { error = queueFull; return }
        action { outbox.add(current.id, code); pending = outbox.pending(); flush() }
    }
    val scanner = rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let(::addCode) }
    fun back() { if (date != null || campaign != null) { date = null; campaign = null; error = null; message = null } else onBack() }
    BackHandler { back() }
    Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        TextButton(onClick = ::back) { Text(stringResource(R.string.ticket_back)) }
        Text(stringResource(R.string.management_title), style = MaterialTheme.typography.headlineLarge)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        message?.let { Text(it, color = Acid) }
        if (pending.isNotEmpty()) {
            Text(stringResource(R.string.management_pending, pending.size), style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.management_pending_help), color = Muted)
            OutlinedButton(enabled = !busy, onClick = { action { flush() } }) { Text(stringResource(R.string.management_retry_reads)) }
        }
        val selected = date
        val selectedCampaign = campaign
        when {
            selected != null -> {
                Text(selected.title, style = MaterialTheme.typography.titleLarge)
                Text(managementDate(selected.startsAt)); selected.venue?.let { Text(it, color = Muted) }
                OutlinedButton(enabled = !busy, onClick = { action { date = api.date(selected.id) } }) { Text(stringResource(R.string.management_refresh)) }
                if (selected.canCheckIn) {
                    Text(stringResource(R.string.management_checkin), style = MaterialTheme.typography.titleLarge)
                    Text(stringResource(R.string.management_online_only), color = Muted)
                    Button(enabled = !busy, onClick = {
                        scanner.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt(scanPrompt).setBeepEnabled(false).setOrientationLocked(false))
                    }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.management_scan)) }
                    var manual by remember(selected.id) { mutableStateOf("") }
                    OutlinedTextField(manual, { manual = it.take(64) }, label = { Text(stringResource(R.string.management_manual)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedButton(enabled = !busy && manual.isNotBlank(), onClick = { addCode(manual); manual = "" }) { Text(stringResource(R.string.management_check_code)) }
                    outcomes.filter { it.occurrenceId == selected.id }.take(8).forEach { outcome ->
                        Text(stringResource(if (outcome.accepted) R.string.management_accepted else R.string.management_rejected, outcome.message), color = if (outcome.accepted) Acid else MaterialTheme.colorScheme.error)
                    }
                }
                if (selected.canManage) {
                    HorizontalDivider()
                    Text(stringResource(R.string.management_capacity), style = MaterialTheme.typography.titleLarge)
                    Text(stringResource(R.string.management_attended, selected.statistics["checked_in"] ?: 0))
                    Text(selected.availability?.remaining?.let { stringResource(R.string.ticket_remaining, it) } ?: stringResource(R.string.management_capacity_unknown))
                    HorizontalDivider()
                    Text(stringResource(R.string.management_staff), style = MaterialTheme.typography.titleLarge)
                    Text(stringResource(R.string.management_staff_help), color = Muted)
                    selected.staff.forEach { member ->
                        Text("${member.name} · ${member.email}")
                        TextButton(enabled = !busy, onClick = { removeStaff = member }) { Text(stringResource(R.string.management_revoke)) }
                    }
                    var email by remember(selected.id) { mutableStateOf("") }
                    OutlinedTextField(email, { email = it.take(255) }, label = { Text(stringResource(R.string.management_email)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
                    OutlinedButton(enabled = !busy && email.isNotBlank(), onClick = { action { api.staff(selected.id, email.trim(), false); date = api.date(selected.id); email = ""; message = saved } }) { Text(stringResource(R.string.management_assign)) }
                    HorizontalDivider()
                    key(selected) { DecisionDetailsForm(selected, busy) { body -> action { api.details(selected.id, body); date = api.date(selected.id); message = saved } } }
                }
            }
            selectedCampaign != null -> {
                Text(selectedCampaign.title, style = MaterialTheme.typography.titleLarge)
                Text("${managementDate(selectedCampaign.startsAt)} — ${managementDate(selectedCampaign.endsAt)}", color = Muted)
                selectedCampaign.economics?.let { economics ->
                    MetricLine(R.string.management_spend, managementMoney(economics.spendCents, economics.currency))
                    MetricLine(R.string.management_bookings, economics.bookings?.toString())
                    MetricLine(R.string.management_attendances, economics.attendances?.toString())
                    MetricLine(R.string.management_per_booking, managementMoney(economics.perBookingCents, economics.currency))
                    MetricLine(R.string.management_per_attendance, managementMoney(economics.perAttendanceCents, economics.currency))
                }
                Text(stringResource(R.string.management_economics_help), color = Muted)
            }
            else -> {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(!reports, { if (!busy) reports = false }, label = { Text(stringResource(R.string.management_dates)) })
                    FilterChip(reports, { if (!busy) reports = true }, label = { Text(stringResource(R.string.management_reports)) })
                }
                if (!reports) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(!past, { if (!busy) past = false }, label = { Text(stringResource(R.string.management_upcoming)) })
                    FilterChip(past, { if (!busy) past = true }, label = { Text(stringResource(R.string.management_past)) })
                }
                OutlinedButton(enabled = !busy, onClick = { action { load() } }) { Text(stringResource(R.string.management_refresh)) }
                if (!busy && (if (reports) campaigns.isEmpty() else dates.isEmpty())) Text(stringResource(R.string.management_empty), color = Muted)
                if (reports) campaigns.forEach { item ->
                    TextButton(enabled = !busy, onClick = { action { campaign = api.campaign(item.id) } }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) { Text("#${item.id} · ${item.title}") }
                    HorizontalDivider()
                } else dates.forEach { item ->
                    TextButton(enabled = !busy, onClick = { action { date = api.date(item.id) } }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                        Column(Modifier.fillMaxWidth()) {
                            Text(item.title, style = MaterialTheme.typography.titleMedium)
                            Text(managementDate(item.startsAt), color = Muted)
                            Text(stringResource(if (item.canManage) R.string.management_manager_role else R.string.management_staff_role), color = Muted)
                        }
                    }
                    HorizontalDivider()
                }
                nextPage?.let { page -> OutlinedButton(enabled = !busy, onClick = { action { load(page) } }) { Text(stringResource(R.string.management_more)) } }
            }
        }
    }
    removeStaff?.let { member -> AlertDialog(onDismissRequest = { removeStaff = null },
        title = { Text(stringResource(R.string.management_revoke)) }, text = { Text(stringResource(R.string.management_revoke_confirm, member.email)) },
        confirmButton = { TextButton(enabled = !busy, onClick = { val id = date?.id ?: return@TextButton; removeStaff = null; action { api.staff(id, member.email, true); date = api.date(id); message = saved } }) { Text(stringResource(R.string.management_revoke)) } },
        dismissButton = { TextButton(onClick = { removeStaff = null }) { Text(stringResource(R.string.management_cancel)) } }) }
}

@Composable private fun MetricLine(label: Int, value: String?) {
    Column { Text(stringResource(label), color = Muted); Text(value ?: stringResource(R.string.management_not_available), style = MaterialTheme.typography.headlineSmall) }
}
private fun managementDate(value: String): String = runCatching { OffsetDateTime.parse(value).atZoneSameInstant(java.time.ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)) }.getOrDefault(value)
internal fun managementMoney(cents: Long?, currency: String): String? = cents?.let {
    NumberFormat.getCurrencyInstance(Locale.ITALIAN).apply { this.currency = runCatching { Currency.getInstance(currency) }.getOrDefault(Currency.getInstance("EUR")) }.format(java.math.BigDecimal.valueOf(it, 2))
}

@Composable private fun DecisionDetailsForm(date: ManagedDate, busy: Boolean, onSave: (JsonObject) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    var practical by remember { mutableStateOf(date.practical.mapValues { (_, v) -> (v as? JsonPrimitive)?.contentOrNull.orEmpty() }) }
    var costs by remember { mutableStateOf(date.costs.mapValues { (_, v) -> (v as? JsonPrimitive)?.contentOrNull.orEmpty() }) }
    TextButton(onClick = { expanded = !expanded }) { Text(stringResource(R.string.management_edit_details)) }
    if (!expanded) return
    Text(stringResource(R.string.management_inherit), color = Muted)
    ChoiceField(R.string.management_accessibility, practical["accessibility"].orEmpty(), listOf("" to R.string.management_inherit_value, "yes" to R.string.management_yes, "no" to R.string.management_no, "unknown" to R.string.management_unknown)) { practical = practical + ("accessibility" to it) }
    ChoiceField(R.string.management_membership, practical["membership"].orEmpty(), listOf("" to R.string.management_inherit_value, "required" to R.string.management_required, "not_required" to R.string.management_not_required)) { practical = practical + ("membership" to it) }
    listOf("entrance_notes" to R.string.management_entrance, "membership_notes" to R.string.management_membership_notes, "parking_notes" to R.string.management_parking, "transit_notes" to R.string.management_transit, "food_notes" to R.string.management_food, "start_notes" to R.string.management_start).forEach { (key, label) ->
        OutlinedTextField(practical[key].orEmpty(), { practical = practical + (key to it.take(2000)) }, enabled = !busy, label = { Text(stringResource(label)) }, modifier = Modifier.fillMaxWidth())
    }
    Text(stringResource(R.string.management_costs), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.management_costs_help, date.currency), color = Muted)
    listOf("admission" to R.string.management_admission, "drink" to R.string.management_drink, "membership" to R.string.management_membership_cost, "other" to R.string.management_other).forEach { (key, label) ->
        OutlinedTextField(costs[key].orEmpty(), { costs = costs + (key to it.take(10)) }, enabled = !busy, label = { Text(stringResource(label)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), isError = !validManagementCost(costs[key].orEmpty()), modifier = Modifier.fillMaxWidth())
    }
    Button(enabled = !busy && costs.values.all(::validManagementCost), onClick = {
        onSave(buildJsonObject {
            put("practical_details", buildJsonObject { practical.forEach { (key, value) -> put(key, value.takeIf { it.isNotBlank() }?.let(::JsonPrimitive) ?: JsonNull) } })
            put("cost_breakdown", buildJsonObject { costs.forEach { (key, value) -> put(key, value.takeIf { it.isNotBlank() }?.replace(',', '.')?.let(::JsonPrimitive) ?: JsonNull) } })
        })
    }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.management_save)) }
}
internal fun validManagementCost(value: String): Boolean = value.isBlank() || (value.matches(Regex("\\d{1,6}([.,]\\d{1,2})?")) && (value.replace(',', '.').toBigDecimalOrNull()?.let { it <= java.math.BigDecimal("100000") } == true))
@Composable private fun ChoiceField(label: Int, value: String, options: List<Pair<String, Int>>, onChange: (String) -> Unit) {
    Text(stringResource(label), style = MaterialTheme.typography.titleMedium)
    options.forEach { (key, title) -> FilterChip(value == key, { onChange(key) }, label = { Text(stringResource(title)) }) }
}
