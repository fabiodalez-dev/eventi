package it.fabiodalez.incitta.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.data.SponsoredBanner

@Composable
fun SponsoredEventBanner(banner: SponsoredBanner, onImpression: () -> Unit, onOpen: () -> Unit) {
    LaunchedEffect(banner.id) { onImpression() }
    val gray = remember { ColorFilter.colorMatrix(ColorMatrix().apply { setToSaturation(0f) }) }
    var imageFailed by remember(banner.image) { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().background(Paper).clickable(role = Role.Button, onClickLabel = "Apri evento sponsorizzato", onClick = onOpen).padding(12.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(64.dp).background(Ink), contentAlignment = Alignment.Center) {
            if (banner.image == null || imageFailed) Text("inCittà", color = Acid, style = MaterialTheme.typography.labelLarge)
            else AsyncImage(banner.image, null, Modifier.fillMaxSize(), contentScale = ContentScale.Fit, colorFilter = gray, onError = { imageFailed = true })
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(banner.title, Modifier.weight(1f), color = Ink, style = MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text("AD", color = Ink, style = MaterialTheme.typography.labelSmall)
            }
            Text(listOf(banner.dateLabel, banner.price).filter { it.isNotBlank() }.joinToString(" · "), color = Ink, style = MaterialTheme.typography.bodySmall)
            Text(listOf(banner.place, banner.category).filter { it.isNotBlank() }.joinToString(" · "), color = Ink, style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text("Sponsorizzato da ${banner.advertiser}", color = Ink, style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}
