package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import kotlinx.serialization.json.*
import it.fabiodalez.incitta.data.*

@Composable
internal fun CpButton(key: String, enabled: Boolean = true, action: () -> Unit) {
    OutlinedButton(onClick=action, enabled=enabled, modifier=Modifier.heightIn(min=48.dp)) { Text(cpText(key)) }
}
@Composable
internal fun CpField(key: String, value: String, limit: Int, onChange: (String) -> Unit, multiline: Boolean = false) {
    OutlinedTextField(value, { if(it.length <= limit) onChange(it) }, label={Text(cpText(key))}, singleLine=!multiline, minLines=if(multiline) 3 else 1, modifier=Modifier.fillMaxWidth())
}
@Composable
internal fun CpCheck(key: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment=androidx.compose.ui.Alignment.CenterVertically) { Checkbox(checked, onChange); Text(cpText(key), modifier=Modifier.weight(1f)) }
}
@Composable
internal fun CpChoice(key: String, value: String, options: List<Pair<String,String>>, onChange: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    Text(cpText(key), style=MaterialTheme.typography.labelLarge)
    Box { OutlinedButton(onClick={expanded=true}, modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)) { Text(cpText(options.firstOrNull { it.first == value }?.second ?: options.first().second)) }
        DropdownMenu(expanded, {expanded=false}) { options.forEach { (id,label) -> DropdownMenuItem(text={Text(cpText(label))}, onClick={onChange(id);expanded=false}) } } }
}
@Composable
internal fun CpSeats(value: Int, onChange: (Int) -> Unit, maximum: Int = 8) {
    Row(verticalAlignment=androidx.compose.ui.Alignment.CenterVertically) {
        Text(cpText("seats"), modifier=Modifier.weight(1f))
        OutlinedButton(onClick={onChange(value-1)}, enabled=value>1, modifier=Modifier.sizeIn(minWidth=48.dp,minHeight=48.dp)) { Text("−") }
        Text(value.toString(), modifier=Modifier.padding(12.dp))
        OutlinedButton(onClick={onChange(value+1)}, enabled=value<maximum, modifier=Modifier.sizeIn(minWidth=48.dp,minHeight=48.dp)) { Text("+") }
    }
}
@Composable
internal fun CpDateTime(key: String, value: String, onChange: (String) -> Unit) {
    val context=LocalContext.current
    val parsed=runCatching { LocalDateTime.parse(value) }.getOrElse { LocalDateTime.now().plusHours(1).withSecond(0).withNano(0) }
    Text(cpText(key), style=MaterialTheme.typography.labelLarge)
    OutlinedButton(onClick={ android.app.DatePickerDialog(context, {_,y,m,d ->
        android.app.TimePickerDialog(context,{_,h,min -> onChange(LocalDateTime.of(y,m+1,d,h,min).toString())},parsed.hour,parsed.minute,true).show()
    },parsed.year,parsed.monthValue-1,parsed.dayOfMonth).show() },modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)) {
        Text(parsed.format(DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm")))
    }
}
internal val cpAccessibility = listOf("not_specified", "folding_chair", "wheelchair_space").map { it to "RideAccessibility.$it" }
internal val cpLegs = listOf("outbound", "return").map { it to "RideLeg.$it" }
internal fun cpData(vararg values: Pair<String, Any?>): JsonObject = buildJsonObject { values.forEach { (key,value) ->
    put(key, when(value) { null -> JsonNull; is JsonElement -> value; is Boolean -> JsonPrimitive(value); is Number -> JsonPrimitive(value); else -> JsonPrimitive(value.toString()) })
} }
internal fun cpLocal(value: String, zone: String): String = runCatching { java.time.Instant.parse(value).atZone(java.time.ZoneId.of(zone.ifBlank { "Europe/Rome" })).toLocalDateTime().withSecond(0).withNano(0).toString() }.getOrDefault("")

@Composable
internal fun CpOfferForm(data: JsonObject, offer: JsonObject?, busy: Boolean, submit: (JsonObject) -> Unit) {
    var zone by remember(offer) { mutableStateOf(offer?.text("zone").orEmpty()) }
    var departure by remember(offer) { mutableStateOf(offer?.text("departure_local") ?: cpLocal(data.text("starts_at"), data.text("timezone"))) }
    var seats by remember(offer) { mutableIntStateOf(offer?.number("capacity")?.toInt() ?: 1) }
    var leg by remember(offer) { mutableStateOf(offer?.text("leg") ?: "outbound") }
    var accessibility by remember(offer) { mutableStateOf(offer?.text("accessibility") ?: "not_specified") }
    var accessibilityNote by remember(offer) { mutableStateOf(offer?.text("accessibility_note").orEmpty()) }
    var note by remember(offer) { mutableStateOf(offer?.text("note").orEmpty()) }
    var stops by remember(offer) { mutableStateOf((offer?.get("stops") as? JsonArray)?.map { it.jsonPrimitive.content }.orEmpty()) }
    var declaration by remember { mutableStateOf(false) }
    val templates=data.rows("templates")
    if(offer==null && templates.isNotEmpty()) {
        var expanded by remember { mutableStateOf(false) }
        Box { CpButton("templates") { expanded=true }; DropdownMenu(expanded,{expanded=false}) { templates.forEach { template -> DropdownMenuItem(text={Text(template.text("name"))},onClick={
            val settings=template.obj("settings");zone=settings.text("zone");seats=settings.number("capacity").toInt().coerceIn(1,8);accessibility=settings.text("accessibility","not_specified");note=settings.text("note");accessibilityNote=settings.text("accessibility_note");stops=(settings["stops"] as? JsonArray)?.map { it.jsonPrimitive.content }.orEmpty();expanded=false
        }) } } }
    }
    if(offer==null) CpChoice("seek",leg,cpLegs) {leg=it}
    CpField(if(leg=="return") "return_zone" else "zone",zone,120,{zone=it})
    CpDateTime("departure",departure) {departure=it}
    CpSeats(seats,{seats=it});Text(cpText("seat_help"),style=MaterialTheme.typography.bodySmall)
    CpChoice("accessibility",accessibility,cpAccessibility) {accessibility=it}
    Text(cpText("accessibility_disclaimer"),style=MaterialTheme.typography.bodySmall)
    CpField("accessibility_note",accessibilityNote,300,{accessibilityNote=it})
    Text(cpText("stops")); (0..2).forEach { index -> CpField("stop",stops.getOrNull(index).orEmpty(),120,{value -> stops=List(3){if(it==index)value else stops.getOrNull(it).orEmpty()} }) }
    CpField("note",note,500,{note=it},true)
    if(offer==null) CpCheck("driver_declaration",declaration) {declaration=it}
    fun payload(draft: Boolean) = cpData("occurrence_id" to data.number("occurrence_id"), "leg" to leg, "zone" to zone.trim(), "departure_at" to departure, "capacity" to seats, "accessibility" to accessibility, "accessibility_note" to accessibilityNote, "note" to note, "stops" to JsonArray(stops.filter {it.isNotBlank()}.map(::JsonPrimitive)), "driver_declaration" to declaration, "draft" to draft, "offer_id" to offer?.number("id"), "revision" to offer?.number("revision"))
    CpButton(if(offer==null) "create" else "update", !busy && zone.isNotBlank() && departure.isNotBlank() && (offer!=null || declaration)) {submit(payload(false))}
    if(offer==null) CpButton("draft",!busy && zone.isNotBlank() && declaration) {submit(payload(true))}
}
