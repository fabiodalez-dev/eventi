package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Icon
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.platform.LocalContext
import androidx.compose.material3.TextButton
import it.fabiodalez.incitta.R
import kotlinx.serialization.json.*

@Composable
fun EditorialInformation(value: JsonElement?) {
    val details = value as? JsonObject ?: return
    val context = LocalContext.current
    fun text(key: String) = (details[key] as? JsonPrimitive)?.contentOrNull?.takeIf { it.isNotBlank() }
    val fields = listOf(
        "introduction" to R.string.editorial_intro,
        "parking_notes" to R.string.editorial_parking,
        "transit_notes" to R.string.editorial_transit,
        "entrance_notes" to R.string.editorial_entrance,
        "accessibility_notes" to R.string.editorial_accessibility,
        "membership_notes" to R.string.editorial_membership,
        "mandatory_costs" to R.string.editorial_mandatory_costs,
        "weather_policy" to R.string.editorial_weather,
        "minors_policy" to R.string.editorial_minors,
        "cancellation_policy" to R.string.editorial_cancellation,
        "refund_policy" to R.string.editorial_refund,
        "public_contact" to R.string.editorial_contact,
        "poster_caption" to R.string.editorial_caption,
        "poster_credit" to R.string.editorial_credit,
    )
    val practical = details["practical_items"] as? JsonArray
    Column(Modifier.padding(horizontal = 22.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        if (practical != null) {
            if (practical.isNotEmpty()) Text(stringResource(R.string.editorial_before_going), style = MaterialTheme.typography.titleLarge)
            practical.forEach { element ->
                val item = element as? JsonObject ?: return@forEach
                val label = (item["label"] as? JsonPrimitive)?.contentOrNull ?: return@forEach
                val icon = (item["icon"] as? JsonPrimitive)?.contentOrNull
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Icon(practicalIcon(icon), contentDescription = null, modifier = Modifier.size(22.dp), tint = MaterialTheme.colorScheme.primary)
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(label, style = MaterialTheme.typography.titleMedium)
                        (item["text"] as? JsonPrimitive)?.contentOrNull?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodyLarge) }
                    }
                }
            }
        } else {
        Text(stringResource(R.string.editorial_before_going), style = MaterialTheme.typography.titleLarge)
        val membershipLabel = when (text("membership")) {
            "required" -> R.string.membership_required
            "not_required" -> R.string.membership_not_required
            else -> null
        }
        membershipLabel?.let { Text(stringResource(it), style = MaterialTheme.typography.bodyLarge) }
        text("minimum_age")?.let { Text(stringResource(R.string.editorial_minimum_age) + ": " + it) }
        when (text("parking_type")) {
            "free" -> Text(stringResource(R.string.editorial_parking_free))
            "paid" -> Text(stringResource(R.string.editorial_parking_paid))
            "none" -> Text(stringResource(R.string.editorial_parking_none))
        }
        }
        text("attendance_mode")?.takeIf { it != "offline" }?.let {
            Text(stringResource(if (it == "online") R.string.editorial_online else R.string.editorial_mixed))
            text("online_url")?.let { url ->
                val uri = android.net.Uri.parse(url)
                if (uri.scheme in listOf("https", "http")) {
                    TextButton(onClick = { runCatching { context.startActivity(android.content.Intent(android.content.Intent.ACTION_VIEW, uri)) } }) {
                        Text(stringResource(R.string.editorial_online_info))
                    }
                }
            }
        }
        if (practical == null && text("accessibility") in listOf("yes", "no")) Text(stringResource(R.string.editorial_accessibility) + ": " + stringResource(when (text("accessibility")) {
            "yes" -> R.string.editorial_yes
            "no" -> R.string.editorial_no
            else -> R.string.editorial_unspecified
        }))
        fields.filter { practical == null || it.first in listOf("introduction", "poster_caption", "poster_credit") }.forEach { (key, label) ->
            text(key)?.let {
                Text(stringResource(label), style = MaterialTheme.typography.titleMedium)
                Text(it, style = MaterialTheme.typography.bodyLarge)
            }
        }
        (details["agenda"] as? JsonArray)?.takeIf { it.isNotEmpty() }?.let { agenda ->
            Text(stringResource(R.string.editorial_agenda), style = MaterialTheme.typography.titleLarge)
            agenda.forEach { element ->
                (element as? JsonObject)?.let { item ->
                    listOf("when", "title", "speaker", "description").forEach { key ->
                        (item[key] as? JsonPrimitive)?.contentOrNull?.takeIf { it.isNotBlank() }?.let { Text(it) }
                    }
                }
            }
        }
        (details["faqs"] as? JsonArray)?.takeIf { it.isNotEmpty() }?.let { faqs ->
            Text(stringResource(R.string.editorial_faq), style = MaterialTheme.typography.titleLarge)
            faqs.forEach { element ->
                (element as? JsonObject)?.let { faq ->
                    Text((faq["question"] as? JsonPrimitive)?.contentOrNull.orEmpty(), style = MaterialTheme.typography.titleMedium)
                    Text((faq["answer"] as? JsonPrimitive)?.contentOrNull.orEmpty())
                }
            }
        }
    }
}

private fun practicalIcon(name: String?) = when (name) {
    "identification" -> Icons.Outlined.Badge
    "user-group", "users" -> Icons.Outlined.People
    "heart" -> Icons.Outlined.FavoriteBorder
    "hand-raised" -> Icons.Outlined.Accessible
    "eye" -> Icons.Outlined.Visibility
    "speaker-wave" -> Icons.Outlined.VolumeUp
    "chat-bubble-left-right" -> Icons.Outlined.ChatBubbleOutline
    "ticket" -> Icons.Outlined.ConfirmationNumber
    "currency-euro" -> Icons.Outlined.Euro
    "credit-card" -> Icons.Outlined.CreditCard
    "map-pin" -> Icons.Outlined.LocationOn
    "truck" -> Icons.Outlined.DirectionsBus
    "arrow-right-on-rectangle" -> Icons.Outlined.Login
    "clock" -> Icons.Outlined.Schedule
    "sun" -> Icons.Outlined.WbSunny
    "cloud" -> Icons.Outlined.Cloud
    "shield-check" -> Icons.Outlined.VerifiedUser
    "sparkles" -> Icons.Outlined.AutoAwesome
    "cake" -> Icons.Outlined.Restaurant
    "beaker" -> Icons.Outlined.LocalDrink
    "shopping-bag" -> Icons.Outlined.ShoppingBag
    "camera" -> Icons.Outlined.CameraAlt
    "device-phone-mobile" -> Icons.Outlined.PhoneAndroid
    "arrow-path" -> Icons.Outlined.Refresh
    "musical-note" -> Icons.Outlined.MusicNote
    "globe-alt" -> Icons.Outlined.Language
    "check-circle" -> Icons.Outlined.CheckCircle
    else -> Icons.Outlined.Info
}
