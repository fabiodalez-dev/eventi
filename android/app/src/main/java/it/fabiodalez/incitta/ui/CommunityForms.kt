package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.serialization.json.*

@Composable
internal fun CommunityWhatsapp(data: JsonObject, busy: Boolean, change: (String, JsonObject, String) -> Unit, onProfile: () -> Unit) {
    var phone by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var revoke by remember { mutableStateOf(false) }
    Text(stringResource(R.string.community_whatsapp), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.community_wa_lead), color = Muted)
    if(data.flag("verified")) {
        Text(stringResource(R.string.community_verified), color = Acid)
        Button(onClick = onProfile) { Text(stringResource(R.string.community_profile)) }
        TextButton(onClick = { revoke = true }) { Text(stringResource(R.string.community_wa_revoke)) }
    } else if(!data.flag("available")) Text(stringResource(R.string.community_wa_unavailable), color = Muted)
    else {
        if(data.text("challenge_id").isNotBlank()) {
            Text(stringResource(R.string.community_wa_sent), color = Acid)
            OutlinedTextField(code, { code = it.filter(Char::isDigit).take(6) }, label = { Text(stringResource(R.string.community_wa_code)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword), singleLine = true, modifier = Modifier.fillMaxWidth())
            Button(enabled = !busy && code.length == 6, onClick = { change("whatsapp/confirm", buildJsonObject { put("challenge_id", data.text("challenge_id")); put("code", code) }, "POST"); code = "" }) { Text(stringResource(R.string.community_wa_confirm)) }
        }
        OutlinedTextField(phone, { phone = it.take(30) }, label = { Text(stringResource(R.string.community_wa_phone)) }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), singleLine = true, modifier = Modifier.fillMaxWidth())
        Button(enabled = !busy && phone.startsWith('+'), onClick = { change("whatsapp", buildJsonObject { put("phone", phone) }, "POST") }) { Text(stringResource(R.string.community_wa_send)) }
    }
    if(revoke) AlertDialog(onDismissRequest = { revoke = false }, title = { Text(stringResource(R.string.community_wa_revoke)) }, text = { Text(stringResource(R.string.community_wa_revoke_help)) }, confirmButton = {
        TextButton(enabled = !busy, onClick = { revoke = false; change("whatsapp", buildJsonObject {}, "DELETE") }) { Text(stringResource(R.string.community_wa_revoke)) }
    }, dismissButton = { TextButton(onClick = { revoke = false }) { Text(stringResource(R.string.community_cancel)) } })
}

@Composable
internal fun CommunityProfileEditor(data: JsonObject, busy: Boolean, save: (JsonObject, android.net.Uri?) -> Unit) {
    val profile = data.obj("profile")
    var name by rememberSaveable(profile) { mutableStateOf(profile.text("display_name")) }
    var handle by rememberSaveable(profile) { mutableStateOf(profile.text("handle")) }
    var bio by rememberSaveable(profile) { mutableStateOf(profile.text("bio")) }
    var visibility by rememberSaveable(profile) { mutableStateOf(profile.text("visibility", "members")) }
    var indexable by rememberSaveable(profile) { mutableStateOf(profile.flag("indexable")) }
    var venues by remember(data) { mutableStateOf((data["venue_ids"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.longOrNull }?.toSet() ?: emptySet()) }
    var avatar by remember { mutableStateOf<android.net.Uri?>(null) }
    val photoPicker = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.GetContent()) { avatar = it }
    var city by rememberSaveable(profile) { mutableLongStateOf(profile.number("city_id")) }
    var removeAvatar by remember { mutableStateOf(false) }
    Text(stringResource(R.string.community_profile), style = MaterialTheme.typography.titleLarge)
    OutlinedTextField(name, { name = it.take(80) }, label = { Text(stringResource(R.string.community_name)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
    OutlinedTextField(handle, { handle = it.lowercase().filter { c -> c in 'a'..'z' || c.isDigit() || c == '_' }.take(40) }, label = { Text(stringResource(R.string.community_handle)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
    OutlinedTextField(bio, { bio = it.take(500) }, label = { Text(stringResource(R.string.community_bio)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
    OutlinedButton(enabled = !busy, onClick = { photoPicker.launch("image/*") }) { Text(stringResource(if (avatar == null) R.string.community_photo else R.string.community_photo_selected)) }
    Text(stringResource(R.string.community_city), style = MaterialTheme.typography.titleMedium)
    CommunityCheck(stringResource(R.string.community_no_city), city == 0L) { city = 0 }
    data.rows("cities").forEach { item -> CommunityCheck(item.text("name"), city == item.number("id")) { city = item.number("id") } }
    Text(stringResource(R.string.community_visibility), style = MaterialTheme.typography.titleMedium)
    listOf("public" to R.string.community_public, "members" to R.string.community_members, "private" to R.string.community_private_profile).forEach { (value, label) ->
        CommunityCheck(stringResource(label), visibility == value) { visibility = value }
    }
    if(visibility == "public") CommunityCheck(stringResource(R.string.community_index), indexable) { indexable = it }
    Text(stringResource(R.string.community_venues), style = MaterialTheme.typography.titleMedium)
    Text(stringResource(R.string.community_venues_help), color = Muted)
    data.rows("venues").forEach { venue -> CommunityCheck(venue.text("name"), venue.number("id") in venues) { checked -> venues = if(checked) venues + venue.number("id") else venues - venue.number("id") } }
    if(profile.text("avatar_url").isNotBlank()) CommunityCheck(stringResource(R.string.community_remove_avatar), removeAvatar) { removeAvatar = it }
    Button(enabled = !busy && name.isNotBlank() && handle.length >= 3, onClick = {
        save(buildJsonObject { put("display_name", name); put("handle", handle); put("bio", bio); put("visibility", visibility); put("indexable", visibility == "public" && indexable); put("venue_ids", JsonArray(venues.map(::JsonPrimitive))); put("remove_avatar", removeAvatar); put("city_id", if (city == 0L) JsonNull else JsonPrimitive(city)) }, avatar)
    }) { Text(stringResource(R.string.community_save)) }
}

@Composable
internal fun CommunityPublicationEditor(data: JsonObject, verified: Boolean, busy: Boolean, verify: () -> Unit, save: (JsonObject) -> Unit) {
    var public by remember(data) { mutableStateOf(data.text("visibility") == "public") }
    var body by remember(data) { mutableStateOf(data.text("body")) }
    var intent by remember(data) { mutableStateOf(data.text("intent", "recommend")) }
    Text(stringResource(R.string.community_save_privacy), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.community_privacy_help), color = Muted)
    CommunityCheck(stringResource(R.string.community_private_save), !public) { public = false }
    if(verified) {
        CommunityCheck(stringResource(R.string.community_public_save), public) { public = true }
        if(public) {
            CommunityCheck(stringResource(R.string.community_recommend), intent == "recommend") { intent = "recommend" }
            CommunityCheck(stringResource(R.string.community_attend), intent == "attend") { intent = "attend" }
            OutlinedTextField(body, { body = it.take(500) }, label = { Text(stringResource(R.string.community_body)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
        }
    } else {
        Text(stringResource(R.string.community_verify_required), color = Muted)
        TextButton(onClick = verify) { Text(stringResource(R.string.community_whatsapp)) }
    }
    Button(enabled = !busy && (!public || verified), onClick = { save(buildJsonObject { put("visibility", if(public) "public" else "private"); put("body", body); put("intent", intent) }) }) { Text(stringResource(R.string.community_save)) }
}
