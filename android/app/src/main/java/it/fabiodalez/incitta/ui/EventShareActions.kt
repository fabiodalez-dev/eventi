package it.fabiodalez.incitta.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Email
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedIconButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R

@Composable
internal fun EventShareActions(title: String, url: String, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val chooserTitle = stringResource(R.string.event_share)
    fun send() {
        context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, title)
            putExtra(Intent.EXTRA_TEXT, "$title\n$url")
        }, chooserTitle))
    }
    fun open(uri: Uri, email: Boolean = false) {
        try {
            context.startActivity(Intent(if (email) Intent.ACTION_SENDTO else Intent.ACTION_VIEW, uri))
        } catch (_: ActivityNotFoundException) {
            send()
        }
    }
    val tint = Color(0xFFFAF8F4)
    val border = BorderStroke(1.dp, Color(0xFF777571))
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedIconButton(onClick = { send() }, modifier = Modifier.size(48.dp), shape = ControlShape, border = border) {
            Icon(Icons.Outlined.Share, stringResource(R.string.event_share), tint = tint)
        }
        OutlinedIconButton(onClick = { open(Uri.parse("https://wa.me/").buildUpon().appendQueryParameter("text", "$title $url").build()) }, modifier = Modifier.size(48.dp), shape = ControlShape, border = border) {
            Icon(painterResource(R.drawable.ic_whatsapp), stringResource(R.string.event_share_whatsapp), modifier = Modifier.size(22.dp), tint = tint)
        }
        OutlinedIconButton(onClick = { open(Uri.parse("https://t.me/share/url").buildUpon().appendQueryParameter("url", url).appendQueryParameter("text", title).build()) }, modifier = Modifier.size(48.dp), shape = ControlShape, border = border) {
            Icon(painterResource(R.drawable.ic_telegram), stringResource(R.string.event_share_telegram), modifier = Modifier.size(22.dp), tint = tint)
        }
        OutlinedIconButton(onClick = { open(Uri.parse("mailto:?subject=${Uri.encode(title)}&body=${Uri.encode("$title\n\n$url")}"), email = true) }, modifier = Modifier.size(48.dp), shape = ControlShape, border = border) {
            Icon(Icons.Outlined.Email, stringResource(R.string.event_share_email), tint = tint)
        }
    }
}
