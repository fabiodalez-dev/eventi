package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.serialization.json.*

@Composable
internal fun CommunityWhatsapp(data: JsonObject, busy: Boolean, token: String?, change: (String, JsonObject, String) -> Unit, onProfile: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val incoming by it.fabiodalez.incitta.community.WhatsappAutofill.received.collectAsState()
    var phone by rememberSaveable { mutableStateOf("") }
    var code by rememberSaveable(data.text("challenge_id")) { mutableStateOf("") }
    var autofilled by rememberSaveable(data.text("challenge_id")) { mutableStateOf(false) }
    var revoke by remember { mutableStateOf(false) }
    LaunchedEffect(incoming, data.text("challenge_id")) {
        if (!autofilled && token != null) it.fabiodalez.incitta.community.WhatsappAutofill.take(context, token, data.text("challenge_id"))?.let { code = it; autofilled = true }
    }
    Text(stringResource(R.string.community_whatsapp), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(if(data.flag("exempt")) R.string.community_wa_exempt else R.string.community_wa_lead), color = Muted)
    if(data.flag("verified")) {
        Text(stringResource(R.string.community_verified), color = Acid)
        Button(onClick = onProfile) { Text(stringResource(R.string.community_profile)) }
        TextButton(onClick = { revoke = true }) { Text(stringResource(R.string.community_wa_revoke)) }
    } else if(!data.flag("available")) Text(stringResource(R.string.community_wa_unavailable), color = Muted)
    else {
        if(data.text("challenge_id").isNotBlank()) {
            Text(stringResource(R.string.community_wa_sent), color = Acid)
            if(autofilled) Text(stringResource(R.string.community_wa_autofilled), color = Acid)
            OutlinedTextField(code, { code = it.filter(Char::isDigit).take(6) }, label = { Text(stringResource(R.string.community_wa_code)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword), singleLine = true, modifier = Modifier.fillMaxWidth())
            Button(enabled = !busy && code.length == 6, onClick = { change("whatsapp/confirm", buildJsonObject { put("challenge_id", data.text("challenge_id")); put("code", code) }, "POST") }) { Text(stringResource(R.string.community_wa_confirm)) }
        }
        if(data.flag("autofill_available")) Text(stringResource(R.string.community_wa_autofill_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        OutlinedTextField(phone, { phone = it.take(30) }, label = { Text(stringResource(R.string.community_wa_phone)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), singleLine = true, modifier = Modifier.fillMaxWidth())
        Button(enabled = !busy && phone.startsWith('+'), onClick = { change("whatsapp", buildJsonObject { put("phone", phone) }, "POST") }) { Text(stringResource(R.string.community_wa_send)) }
    }
    if(revoke) AlertDialog(onDismissRequest = { revoke = false }, title = { Text(stringResource(R.string.community_wa_revoke)) }, text = { Text(stringResource(R.string.community_wa_revoke_help)) }, confirmButton = {
        TextButton(enabled = !busy, onClick = { revoke = false; change("whatsapp", buildJsonObject {}, "DELETE") }) { Text(stringResource(R.string.community_wa_revoke)) }
    }, dismissButton = { TextButton(onClick = { revoke = false }) { Text(stringResource(R.string.community_cancel)) } })
}

@Composable
internal fun CommunityProfileEditor(data: JsonObject, busy: Boolean, checkHandle: suspend (String) -> JsonObject, save: (JsonObject, android.net.Uri?) -> Unit) {
    val profile = data.obj("profile")
    var name by rememberSaveable(profile) { mutableStateOf(profile.text("display_name").ifBlank { data.obj("suggested").text("display_name") }) }
    var handle by rememberSaveable(profile) { mutableStateOf(profile.text("handle")) }
    var handleStatus by remember { mutableStateOf<String?>(null) }
    val freeLabel = stringResource(R.string.community_handle_free)
    val checkingLabel = stringResource(R.string.community_handle_checking)
    LaunchedEffect(handle) {
        handleStatus = null
        if(handle.length >= 3) {
            kotlinx.coroutines.delay(350)
            handleStatus = checkingLabel
            try {
                val answer = checkHandle(handle).obj("data")
                handleStatus = if(answer.flag("available")) freeLabel else answer.text("reason")
            } catch(e: kotlinx.coroutines.CancellationException) { throw e }
            catch(_: Exception) { handleStatus = null }
        }
    }
    var optional by rememberSaveable { mutableStateOf(false) }
    var venueQuery by rememberSaveable { mutableStateOf("") }
    var bio by rememberSaveable(profile) { mutableStateOf(profile.text("bio")) }
    var visibility by rememberSaveable(profile) { mutableStateOf(profile.text("visibility", "members")) }
    var indexable by rememberSaveable(profile) { mutableStateOf(profile.flag("indexable")) }
    var venues by rememberSaveable(data, stateSaver = Saver<Set<Long>, ArrayList<Long>>(save = { ArrayList(it) }, restore = { it.toSet() })) {
        mutableStateOf((data["venue_ids"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.longOrNull }?.toSet() ?: emptySet())
    }
    var avatar by rememberSaveable { mutableStateOf<android.net.Uri?>(null) }
    val photoPicker = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.GetContent()) { avatar = it }
    var city by rememberSaveable(profile) { mutableLongStateOf(profile.number("city_id")) }
    var removeAvatar by rememberSaveable(profile) { mutableStateOf(false) }
    Text(stringResource(R.string.community_profile), style = MaterialTheme.typography.titleLarge)
    OutlinedTextField(name, { name = it.take(80) }, label = { Text(stringResource(R.string.community_name)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
    OutlinedTextField(handle, { handle = it.lowercase().filter { c -> c in 'a'..'z' || c.isDigit() || c == '_' }.take(40) }, label = { Text(stringResource(R.string.community_handle)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
    handleStatus?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
    TextButton(onClick = { optional = !optional }) { Text(stringResource(R.string.community_optional_profile)) }
    if(optional) {
    OutlinedTextField(bio, { bio = it.take(500) }, label = { Text(stringResource(R.string.community_bio)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
    OutlinedButton(enabled = !busy, onClick = { photoPicker.launch("image/*") }) { Text(stringResource(if (avatar == null) R.string.community_photo else R.string.community_photo_selected)) }
    Text(stringResource(R.string.community_city), style = MaterialTheme.typography.titleMedium)
    CommunityCheck(stringResource(R.string.community_no_city), city == 0L) { city = 0 }
    data.rows("cities").forEach { item -> CommunityCheck(item.text("name"), city == item.number("id")) { city = item.number("id") } }
    }
    Text(stringResource(R.string.community_visibility), style = MaterialTheme.typography.titleMedium)
    listOf("public" to R.string.community_public, "members" to R.string.community_members, "private" to R.string.community_private_profile).forEach { (value, label) ->
        CommunityCheck(stringResource(label), visibility == value) { visibility = value }
    }
    if(visibility == "public") CommunityCheck(stringResource(R.string.community_index), indexable) { indexable = it }
    if(optional) {
    Text(stringResource(R.string.community_venues), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.community_venues_help), color = Muted)
    OutlinedTextField(venueQuery, { venueQuery = it }, label = { Text(stringResource(R.string.community_venue_search)) }, modifier = Modifier.fillMaxWidth())
    data.rows("venues").filter { it.number("id") in venues || it.text("name").contains(venueQuery, ignoreCase = true) }.forEach { venue -> CommunityCheck(venue.text("name"), venue.number("id") in venues) { checked -> venues = if(checked) venues + venue.number("id") else venues - venue.number("id") } }
    if(profile.text("avatar_url").isNotBlank()) CommunityCheck(stringResource(R.string.community_remove_avatar), removeAvatar) { removeAvatar = it }
    }
    Button(enabled = !busy && name.isNotBlank() && handle.length >= 3, onClick = {
        save(buildJsonObject { put("display_name", name); put("handle", handle); put("bio", bio); put("visibility", visibility); put("indexable", visibility == "public" && indexable); put("venue_ids", JsonArray(venues.map(::JsonPrimitive))); put("remove_avatar", removeAvatar); put("city_id", if (city == 0L) JsonNull else JsonPrimitive(city)) }, avatar)
    }) { Text(stringResource(R.string.community_save)) }
}

@Composable
internal fun CommunityPublicationEditor(data: JsonObject, verified: Boolean, busy: Boolean, verify: () -> Unit, save: (JsonObject) -> Unit) {
    var public by rememberSaveable(data) { mutableStateOf(data.text("visibility") == "public") }
    var body by rememberSaveable(data) { mutableStateOf(data.text("body")) }

    Text(stringResource(R.string.community_save_privacy), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.community_privacy_help), color = Muted)
    CommunityCheck(stringResource(R.string.community_private_save), !public) { public = false }
    if(verified) {
        CommunityCheck(stringResource(R.string.community_public_save), public) { public = true }
        if(public) {


            OutlinedTextField(body, { body = it.take(500) }, label = { Text(stringResource(R.string.community_body)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
        }
    } else {
        Text(stringResource(R.string.community_verify_required), color = Muted)
        TextButton(onClick = verify) { Text(stringResource(R.string.community_whatsapp)) }
    }
    Button(enabled = !busy && (!public || verified), onClick = { save(buildJsonObject { put("visibility", if(public) "public" else "private"); put("body", body); put("intent", "recommend") }) }) { Text(stringResource(R.string.community_save)) }
}
