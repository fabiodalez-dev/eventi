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
import androidx.compose.ui.layout.LayoutCoordinates
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.currentStateAsState
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.data.SponsoredBanner
import kotlinx.coroutines.delay

/* La regola del sito (configurazione `sponsorship.viewable_ms`): una
   visualizzazione conta quando metà del banner resta sullo schermo per un
   secondo di fila, con l'app in primo piano. Chi scorre veloce non conta. */
internal const val SPONSORED_VIEWABLE_FRACTION = 0.5f
internal const val SPONSORED_VIEWABLE_MILLIS = 1_000L

/** La parte visibile di un elemento, da 0 a 1: area visibile diviso area intera. */
internal fun visibleFraction(visibleWidth: Float, visibleHeight: Float, width: Int, height: Int): Float {
    if (width <= 0 || height <= 0) return 0f
    val visible = visibleWidth.coerceIn(0f, width.toFloat()) * visibleHeight.coerceIn(0f, height.toFloat())
    return (visible / (width.toFloat() * height.toFloat())).coerceIn(0f, 1f)
}

internal fun isViewable(fraction: Float, foreground: Boolean): Boolean = foreground && fraction >= SPONSORED_VIEWABLE_FRACTION

/* `boundsInWindow()` è già tagliato dai contenitori: la lista che scorre, la finestra. */
private fun LayoutCoordinates.visibleFraction(): Float {
    if (!isAttached) return 0f
    val bounds = boundsInWindow()
    return visibleFraction(bounds.width, bounds.height, size.width, size.height)
}

@Composable
fun SponsoredEventBanner(banner: SponsoredBanner, onImpression: () -> Unit, onOpen: () -> Unit) {
    val lifecycleState by LocalLifecycleOwner.current.lifecycle.currentStateAsState()
    var fraction by remember(banner.id, banner.impressionToken) { mutableFloatStateOf(0f) }
    val viewable = isViewable(fraction, lifecycleState.isAtLeast(Lifecycle.State.RESUMED))
    val currentOnImpression by rememberUpdatedState(onImpression)
    // Ricomincia da zero ogni volta che il banner esce dallo schermo o l'app va in secondo piano.
    LaunchedEffect(banner.id, banner.impressionToken, viewable) {
        if (viewable) {
            delay(SPONSORED_VIEWABLE_MILLIS)
            currentOnImpression()
        }
    }
    val gray = remember { ColorFilter.colorMatrix(ColorMatrix().apply { setToSaturation(0f) }) }
    var imageFailed by remember(banner.image) { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().onGloballyPositioned { fraction = it.visibleFraction() }.background(Paper).clickable(role = Role.Button, onClickLabel = "Apri evento sponsorizzato", onClick = onOpen).padding(12.dp),
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
