package it.fabiodalez.incitta.ui.management.ticketing

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.ManagedCampaign
import it.fabiodalez.incitta.data.ManagedDate
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import java.text.NumberFormat
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Currency
import java.util.Locale
import kotlinx.serialization.json.*

/**
 * «Scrivi a chi ha prenotato»: oggetto, testo e lista d'attesa facoltativa.
 * L'invio chiede conferma; il server risponde con il numero di destinatari o
 * con l'errore del tetto giornaliero, che la schermata mostra così com'è.
 * Oggetto e testo sopravvivono alla rotazione.
 */
@Composable internal fun AttendeeMessageForm(busy: Boolean, dailyLimit: Int, onSend: (String, String, Boolean, () -> Unit) -> Unit) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    var subject by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    var waitlisted by rememberSaveable { mutableStateOf(false) }
    var confirm by remember { mutableStateOf(false) }
    TextButton(onClick = { expanded = !expanded }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(if (expanded) R.string.management_message_hide else R.string.management_message_title))
    }
    if (!expanded) return
    Text(stringResource(R.string.management_message_hint, dailyLimit), color = Muted)
    OutlinedTextField(subject, { subject = it.take(160) }, enabled = !busy, singleLine = true, label = { Text(stringResource(R.string.management_message_subject)) }, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(body, { body = it.take(2000) }, enabled = !busy, minLines = 4, label = { Text(stringResource(R.string.management_message_body)) },
        supportingText = { Text(stringResource(R.string.management_message_length, body.length)) }, modifier = Modifier.fillMaxWidth())
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(waitlisted, enabled = !busy, role = Role.Checkbox) { waitlisted = it },
        verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = waitlisted, onCheckedChange = null, enabled = !busy)
        Text(stringResource(R.string.management_message_waitlisted), modifier = Modifier.padding(start = 8.dp))
    }
    Button(enabled = !busy && subject.isNotBlank() && body.isNotBlank(), onClick = { confirm = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_message_send))
    }
    if (confirm) ConfirmDialog(
        title = stringResource(R.string.management_message_send),
        text = stringResource(if (waitlisted) R.string.management_message_confirm_waitlisted else R.string.management_message_confirm),
        confirmLabel = stringResource(R.string.management_message_send),
        onConfirm = { confirm = false; onSend(subject, body, waitlisted) { subject = ""; body = ""; waitlisted = false } },
        onDismiss = { confirm = false },
    )
}

/**
 * «Aggiungi una prenotazione» per chi prenota al telefono o al banco: un
 * nome per riga, email e telefono facoltativi, nota interna. Non crea account.
 */
@Composable internal fun GuestBookingForm(busy: Boolean, onSave: (String, String, String, String, () -> Unit) -> Unit) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    var names by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    var phone by rememberSaveable { mutableStateOf("") }
    var note by rememberSaveable { mutableStateOf("") }
    TextButton(onClick = { expanded = !expanded }, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(if (expanded) R.string.management_guest_hide else R.string.management_guest_title))
    }
    if (!expanded) return
    Text(stringResource(R.string.management_guest_hint), color = Muted)
    OutlinedTextField(names, { names = it.take(3000) }, enabled = !busy, minLines = 3, label = { Text(stringResource(R.string.management_guest_names)) }, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(email, { email = it.take(255) }, enabled = !busy, singleLine = true, label = { Text(stringResource(R.string.management_guest_email)) },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth())
    OutlinedTextField(phone, { phone = it.take(40) }, enabled = !busy, singleLine = true, label = { Text(stringResource(R.string.management_guest_phone)) },
        supportingText = { Text(stringResource(R.string.management_guest_phone_hint)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth())
    OutlinedTextField(note, { note = it.take(500) }, enabled = !busy, label = { Text(stringResource(R.string.management_guest_note)) }, modifier = Modifier.fillMaxWidth())
    Button(enabled = !busy && names.isNotBlank(), onClick = { onSave(names, email, phone, note) { names = ""; email = ""; phone = ""; note = "" } }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_guest_save))
    }
}

/**
 * Le visualizzazioni della campagna: con un obiettivo una barra, «1.240 di
 * 3.000 visualizzazioni» e lo stato del ritmo; a periodo solo il numero.
 */
@Composable internal fun CampaignProgress(campaign: ManagedCampaign) {
    val delivered = campaign.delivered ?: return
    val goal = campaign.goal
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.management_views), color = Muted)
        if (goal != null && goal > 0) {
            Text(stringResource(R.string.management_views_of_goal, managementCount(delivered), managementCount(goal)), style = MaterialTheme.typography.headlineSmall)
            LinearProgressIndicator(progress = { campaign.progress ?: 0f }, modifier = Modifier.fillMaxWidth().heightIn(min = 8.dp))
            pacingStateLabel(campaign.pacingState)?.let { Text(stringResource(it), style = MaterialTheme.typography.titleMedium) }
        } else {
            Text(pluralStringResource(R.plurals.management_views_delivered, delivered.coerceAtMost(Int.MAX_VALUE.toLong()).toInt(), managementCount(delivered)), style = MaterialTheme.typography.headlineSmall)
        }
        Text(stringResource(R.string.management_views_help), color = Muted)
    }
}

/** L'etichetta dello stato del ritmo mandato dal server; `null` per uno stato che questa versione non conosce. */
internal fun pacingStateLabel(state: String?): Int? = when (state) {
    "on_track" -> R.string.management_pacing_on_track
    "slightly_behind" -> R.string.management_pacing_slightly_behind
    "behind" -> R.string.management_pacing_behind
    "ahead" -> R.string.management_pacing_ahead
    "at_risk" -> R.string.management_pacing_at_risk
    "completed" -> R.string.management_pacing_completed
    "missed" -> R.string.management_pacing_missed
    else -> null
}

/** Un numero intero all'italiana: 1.240. */
internal fun managementCount(value: Long): String = NumberFormat.getIntegerInstance(Locale.ITALIAN).format(value)

@Composable internal fun MetricLine(label: Int, value: String?) {
    Column { Text(stringResource(label), color = Muted); Text(value ?: stringResource(R.string.management_not_available), style = MaterialTheme.typography.headlineSmall) }
}
internal fun manifestClock(value: String): String = runCatching { OffsetDateTime.parse(value).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)) }.getOrDefault(value)
internal fun managementDate(value: String): String = runCatching { OffsetDateTime.parse(value).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)) }.getOrDefault(value)
internal fun managementMoney(cents: Long?, currency: String): String? = cents?.let {
    NumberFormat.getCurrencyInstance(Locale.ITALIAN).apply { this.currency = runCatching { Currency.getInstance(currency) }.getOrDefault(Currency.getInstance("EUR")) }.format(java.math.BigDecimal.valueOf(it, 2))
}

/**
 * «Informazioni pratiche e costi» della data. Si manda sempre tutto: il
 * server sostituisce entrambe le parti, e un campo vuoto (`null`) significa
 * «usa quello dell'evento o del locale».
 */
@Composable internal fun DecisionDetailsForm(date: ManagedDate, busy: Boolean, onSave: (JsonObject) -> Unit) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    var practical by remember { mutableStateOf(date.practical.mapValues { (_, v) -> (v as? JsonPrimitive)?.contentOrNull.orEmpty() }) }
    var costs by remember { mutableStateOf(date.costs.mapValues { (_, v) -> (v as? JsonPrimitive)?.contentOrNull.orEmpty() }) }
    TextButton(onClick = { expanded = !expanded }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_edit_details)) }
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
    Button(enabled = !busy && costs.values.all(::validManagementCost), onClick = { onSave(decisionDetailsBody(practical, costs)) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(R.string.management_save))
    }
}

/** Il corpo di `PATCH management/dates/{id}/details`: vuoto = `null` = eredita; la virgola dei decimali diventa punto. */
internal fun decisionDetailsBody(practical: Map<String, String>, costs: Map<String, String>): JsonObject = buildJsonObject {
    put("practical_details", buildJsonObject { practical.forEach { (key, value) -> put(key, value.takeIf { it.isNotBlank() }?.let(::JsonPrimitive) ?: JsonNull) } })
    put("cost_breakdown", buildJsonObject { costs.forEach { (key, value) -> put(key, value.takeIf { it.isNotBlank() }?.replace(',', '.')?.let(::JsonPrimitive) ?: JsonNull) } })
}

internal fun validManagementCost(value: String): Boolean = value.isBlank() || (value.matches(Regex("\\d{1,6}([.,]\\d{1,2})?")) && (value.replace(',', '.').toBigDecimalOrNull()?.let { it <= java.math.BigDecimal("100000") } == true))

@Composable private fun ChoiceField(label: Int, value: String, options: List<Pair<String, Int>>, onChange: (String) -> Unit) {
    Text(stringResource(label), style = MaterialTheme.typography.titleMedium)
    options.forEach { (key, title) -> FilterChip(value == key, { onChange(key) }, label = { Text(stringResource(title)) }, modifier = Modifier.heightIn(min = 48.dp)) }
}

/** Vero se il telefono ha una rete che può raggiungere internet. */
internal fun isOnline(context: android.content.Context): Boolean {
    val manager = context.getSystemService(android.content.Context.CONNECTIVITY_SERVICE) as? android.net.ConnectivityManager ?: return false
    val capabilities = manager.getNetworkCapabilities(manager.activeNetwork) ?: return false
    return capabilities.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_INTERNET)
        && capabilities.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_VALIDATED)
}
