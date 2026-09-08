@file:Suppress("DEPRECATION")

package it.fabiodalez.incitta.ui

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.filled.Bookmark
import androidx.compose.material.icons.automirrored.outlined.Accessible
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Email
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.LocationOn
import androidx.compose.material.icons.outlined.Map
import androidx.compose.material.icons.outlined.Phone
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.net.toUri
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.EventFilter
import it.fabiodalez.incitta.data.MapMarker
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Price
import it.fabiodalez.incitta.data.Tag
import it.fabiodalez.incitta.data.Venue
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.delay
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.maplibre.android.MapLibre
import org.maplibre.android.annotations.IconFactory
import org.maplibre.android.annotations.MarkerOptions
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLngBounds
import androidx.core.view.doOnLayout
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import kotlin.math.roundToInt

private const val MAP_STYLE = "https://tiles.openfreemap.org/styles/dark"

@Composable
fun CompleteEventDetailScreen(
    detail: EventDetail,
    related: List<Occurrence>,
    savedIds: Set<Long>,
    onBack: () -> Unit,
    onSave: (Long) -> Unit,
    onVenue: (Venue) -> Unit,
    onTag: (Tag) -> Unit,
    onOpenEvent: (Occurrence) -> Unit,
    onReserve: (Occurrence) -> Unit,
    onOrganizer: (String) -> Unit = {},
) {
    val context = LocalContext.current
    val scrollState = rememberScrollState()
    var posterLightbox by remember(detail.id) { mutableStateOf(false) }
    LaunchedEffect(detail.id) { scrollState.scrollTo(0) }
    Box(Modifier.fillMaxSize()) {
        Surface(Modifier.fillMaxSize(), color = Ink, contentColor = Paper) {
            Column(Modifier.fillMaxSize().statusBarsPadding()) {
            DetailBar("IN CITTÀ / EVENTO", onBack) {
                detail.occurrences.firstOrNull()?.let { occurrence ->
                    IconButton(onClick = { onSave(occurrence.occurrenceId) }) {
                        Icon(
                            if (occurrence.occurrenceId in savedIds) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder,
                            if (occurrence.occurrenceId in savedIds) "Rimuovi dai salvati" else "Salva questa data",
                            tint = Ink,
                        )
                    }
                }
            }
            Column(Modifier.fillMaxSize().verticalScroll(scrollState)) {
                val posterUrl = detail.poster?.full ?: detail.poster?.card
                val posterRatio = if ((detail.poster?.width ?: 0) > 0 && (detail.poster?.height ?: 0) > 0) {
                    detail.poster!!.width!!.toFloat() / detail.poster.height!!.toFloat()
                } else 3f / 4f
                key(detail.id) {
                    EventPoster(
                        url = posterUrl,
                        revealKey = detail.id,
                        modifier = Modifier.fillMaxWidth().aspectRatio(posterRatio),
                        onClick = { posterLightbox = true },
                    )
                }
                Column(Modifier.padding(18.dp)) {
                    Eyebrow(detail.category?.name ?: "EVENTO")
                    Text(detail.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                    detail.subtitle?.takeIf(String::isNotBlank)?.let {
                        Text(it, color = Muted, style = androidx.compose.material3.MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 8.dp))
                    }
                    if (detail.verificationStatus != null) {
                        Text("INFORMAZIONI VERIFICATE", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium, modifier = Modifier.padding(top = 12.dp))
                    }
                }

                DetailSection("TUTTE LE DATE") {
                    if (detail.occurrences.isEmpty()) Text("Nessuna data futura disponibile.", color = Muted)
                    detail.occurrences.forEachIndexed { index, occurrence ->
                        if (index > 0) HorizontalDivider(Modifier.padding(vertical = 14.dp), color = Rule)
                        OccurrenceDateBlock(detail, occurrence, occurrence.occurrenceId in savedIds, onSave)
                        if (occurrence.bookingEnabled) {
                            Button(onClick = { onReserve(occurrence) }, modifier = Modifier.fillMaxWidth().padding(top = 10.dp), shape = androidx.compose.ui.graphics.RectangleShape) {
                                Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.ticket_reserve))
                            }
                        }
                    }
                }

                EditorialInformation(detail.contentDetails)
                detail.description?.takeIf(String::isNotBlank)?.let { description ->
                    DetailSection("DESCRIZIONE") {
                        Text(
                            text = description,
                            style = androidx.compose.material3.MaterialTheme.typography.bodyLarge,
                            maxLines = Int.MAX_VALUE,
                            overflow = TextOverflow.Clip,
                            softWrap = true,
                        )
                    }
                }

                if (detail.facts.isNotEmpty() || detail.ageRestriction != null || detail.language != null || detail.isOutdoor) {
                    DetailSection("INFORMAZIONI") {
                        detail.facts.forEach { LabeledValue(it.label, it.value) }
                        detail.ageRestriction?.let { LabeledValue("Età", it) }
                        detail.language?.let { LabeledValue("Lingua", languageName(it)) }
                        LabeledValue("Spazio", if (detail.isOutdoor) "All'aperto" else "Al coperto")
                    }
                }

                DetailSection("PREZZO") {
                    Text(priceText(detail.price), style = androidx.compose.material3.MaterialTheme.typography.headlineMedium, color = Acid)
                    detail.price?.notes?.let { Text(it, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                    if (detail.tiers.isNotEmpty()) {
                        HorizontalDivider(Modifier.padding(vertical = 14.dp), color = Rule)
                        detail.tiers.forEach { tier ->
                            val amount = tier.price?.let { formatEuro(it) } ?: "Prezzo non indicato"
                            LabeledValue(tier.name, "$amount · ${tierStatus(tier.status)}")
                            tier.note?.let { Text(it, color = Muted, style = androidx.compose.material3.MaterialTheme.typography.bodyMedium) }
                            tier.url?.let { url -> SmallLink("ACQUISTA") { openUrl(context, url) } }
                        }
                    }
                    val bookingUrl = detail.booking.url ?: detail.price?.ticketUrl
                    if (bookingUrl != null) {
                        Spacer(Modifier.height(12.dp))
                        ActionButton(if (detail.booking.required) "PRENOTAZIONE OBBLIGATORIA" else "BIGLIETTI", Icons.AutoMirrored.Outlined.OpenInNew) { openUrl(context, bookingUrl) }
                    }
                    detail.booking.phone?.let { phone -> SmallLink("PRENOTA AL TELEFONO · $phone") { dial(context, phone) } }
                }

                detail.venue?.takeIf { (detail.contentDetails as? JsonObject)?.get("attendance_mode")?.jsonPrimitive?.contentOrNull != "online" }?.let { venue ->
                    DetailSection("DOVE") {
                        Text(venue.name.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.headlineMedium, modifier = Modifier.clickable { onVenue(venue) })
                        venue.type?.let { Text(it.replace('_', ' ').uppercase(), color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium) }
                        venue.description?.let { Text(it, modifier = Modifier.padding(top = 10.dp)) }
                        Text(address(venue), color = Muted, modifier = Modifier.padding(top = 10.dp))
                        venue.phone?.let { SmallLink(it) { dial(context, it) } }
                        venue.email?.let { SmallLink(it) { openUrl(context, "mailto:$it") } }
                        venue.website?.let { SmallLink("VAI AL SITO") { openUrl(context, it) } }
                        Spacer(Modifier.height(12.dp))
                        OutlinedButton(
                            onClick = { onVenue(venue) },
                            modifier = Modifier.fillMaxWidth().height(50.dp),
                            shape = androidx.compose.ui.graphics.RectangleShape,
                            border = BorderStroke(2.dp, Acid),
                        ) { Text("SCOPRI IL LOCALE") }
                        if (venue.lat != null && venue.lng != null) {
                            Spacer(Modifier.height(14.dp))
                            InteractiveMap(
                                points = listOf(MapPoint(venue.lat, venue.lng, venue.name)),
                                modifier = Modifier.fillMaxWidth().height(250.dp),
                                zoom = 15.5,
                            ) { onVenue(venue) }
                            MapCaption("${venue.name} · TOCCA IL PUNTO PER APRIRE IL LOCALE")
                            Spacer(Modifier.height(10.dp))
                            ActionButton("INDICAZIONI", Icons.Outlined.Map) { directions(context, venue.lat, venue.lng, venue.name) }
                        }
                    }
                    AccessibilitySection(venue)
                }

                if (detail.venue == null && detail.customLocation != null && (detail.contentDetails as? JsonObject)?.get("attendance_mode")?.jsonPrimitive?.contentOrNull != "online") {
                    val place = detail.customLocation
                    DetailSection("DOVE") {
                        Text((place.name ?: "LUOGO DELL'EVENTO").uppercase(), style = androidx.compose.material3.MaterialTheme.typography.headlineMedium)
                        Text(listOfNotNull(place.address, place.municipality).joinToString(" · "), color = Muted, modifier = Modifier.padding(top = 8.dp))
                        if (place.lat != null && place.lng != null) {
                            Spacer(Modifier.height(14.dp))
                            InteractiveMap(listOf(MapPoint(place.lat, place.lng, place.name ?: detail.title)), Modifier.fillMaxWidth().height(250.dp), 15.5) {
                                directions(context, place.lat, place.lng, place.name ?: detail.title)
                            }
                            MapCaption("${place.name ?: detail.title} · TOCCA IL PUNTO PER LE INDICAZIONI")
                            Spacer(Modifier.height(10.dp))
                            ActionButton("INDICAZIONI", Icons.Outlined.Map) { directions(context, place.lat, place.lng, place.name ?: detail.title) }
                        }
                    }
                }

                if (detail.tags.isNotEmpty()) {
                    DetailSection("TAG") {
                        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            detail.tags.forEach { tag -> TagButton(tag) { onTag(tag) } }
                        }
                    }
                }

                if (detail.organizer.name != null || detail.externalLinks.isNotEmpty()) {
                    DetailSection("LINK E ORGANIZZATORE") {
                        detail.organizer.name?.let { name ->
                            LabeledValue("Organizza", name)
                            if (detail.organizer.slug != null) SmallLink("TUTTI GLI EVENTI DELL'ORGANIZZATORE") { onOrganizer(detail.organizer.slug) }
                            else if (detail.organizer.hostFallback && detail.venue != null) SmallLink("TUTTI GLI EVENTI DEL LOCALE") { onVenue(detail.venue) }
                            else detail.organizer.url?.let { url -> SmallLink("SITO DELL'ORGANIZZATORE") { openUrl(context, url) } }
                        }
                        detail.externalLinks.forEach { link -> SmallLink(link.label.uppercase()) { openUrl(context, link.url) } }
                    }
                }

                if (related.isNotEmpty()) {
                    DetailSection("EVENTI SIMILI") {
                        related.forEach { event -> CompactEventRow(event, event.occurrenceId in savedIds, onOpenEvent, onSave) }
                    }
                }

                Column(Modifier.padding(18.dp)) {
                    ActionButton("CONDIVIDI", Icons.Outlined.Share) { share(context, detail.title, detail.url ?: "https://eventi.fabiodalez.it/eventi/${detail.slug}") }
                    Spacer(Modifier.height(10.dp))
                    OutlinedButton(
                        onClick = { openUrl(context, "https://eventi.fabiodalez.it/eventi/${detail.slug}/segnala") },
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                        shape = androidx.compose.ui.graphics.RectangleShape,
                        border = BorderStroke(2.dp, Rule),
                    ) { Text("SEGNALA UN ERRORE") }
                    Spacer(Modifier.height(24.dp))
                }
                }
            }
        }
        if (posterLightbox) {
            PosterLightbox(
                url = detail.poster?.full ?: detail.poster?.card,
                title = detail.title,
                onClose = { posterLightbox = false },
            )
        }
    }
}

@Composable
private fun OccurrenceDateBlock(detail: EventDetail, occurrence: Occurrence, saved: Boolean, onSave: (Long) -> Unit) {
    val context = LocalContext.current
    Text(fullDate(occurrence.startsAt), style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
    occurrence.venue?.name?.let { Text(it, color = Muted) }
    Text(timeRange(occurrence), color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelLarge, modifier = Modifier.padding(top = 5.dp))
    occurrence.statusNote?.let { Text(it, color = Muted, modifier = Modifier.padding(top = 6.dp)) }
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(
            onClick = { addToCalendar(context, detail, occurrence) },
            modifier = Modifier.weight(1f),
            shape = androidx.compose.ui.graphics.RectangleShape,
            border = BorderStroke(2.dp, Rule),
        ) { Text("CALENDARIO", fontSize = 11.sp) }
        Button(
            onClick = { onSave(occurrence.occurrenceId) },
            modifier = Modifier.weight(1f),
            shape = androidx.compose.ui.graphics.RectangleShape,
            colors = ButtonDefaults.buttonColors(containerColor = if (saved) Acid else Paper, contentColor = Ink),
        ) { Text(if (saved) "SALVATA" else "SALVA", fontSize = 11.sp) }
    }
}

@Composable
fun VenueDetailScreen(
    venue: Venue,
    events: List<Occurrence>,
    pastEvents: List<Occurrence>,
    savedIds: Set<Long>,
    onBack: () -> Unit,
    onOpenEvent: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
) {
    val context = LocalContext.current
    val scrollState = rememberScrollState()
    LaunchedEffect(venue.id, venue.slug) { scrollState.scrollTo(0) }
    Surface(Modifier.fillMaxSize(), color = Ink, contentColor = Paper) {
        Column(Modifier.fillMaxSize().statusBarsPadding()) {
            DetailBar("IN CITTÀ / LOCALE", onBack)
            Column(Modifier.fillMaxSize().verticalScroll(scrollState)) {
                RichImage(venue.cover, Modifier.fillMaxWidth().aspectRatio(16f / 10f))
                Column(Modifier.padding(18.dp)) {
                    Eyebrow(venue.type?.replace('_', ' ') ?: "LOCALE")
                    Text(venue.name.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                    Text(address(venue), color = Muted, modifier = Modifier.padding(top = 10.dp))
                    venue.description?.let { Text(it, style = androidx.compose.material3.MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(top = 16.dp)) }
                    if (venue.requiresMembership) {
                        Text("TESSERA RICHIESTA", color = Ink, modifier = Modifier.padding(top = 14.dp).background(Acid).padding(10.dp), style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
                        venue.membershipNotes?.let { Text(it, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                    }
                }
                DetailSection("CONTATTI") {
                    venue.phone?.let { SmallLink(it) { dial(context, it) } }
                    venue.email?.let { SmallLink(it) { openUrl(context, "mailto:$it") } }
                    venue.website?.let { SmallLink("VAI AL SITO") { openUrl(context, it) } }
                    venue.socials.forEach { (name, url) -> SmallLink(name.uppercase()) { openUrl(context, url) } }
                }
                if (venue.lat != null && venue.lng != null) {
                    DetailSection("MAPPA") {
                        InteractiveMap(listOf(MapPoint(venue.lat, venue.lng, venue.name)), Modifier.fillMaxWidth().height(280.dp), 15.5) {
                            directions(context, venue.lat, venue.lng, venue.name)
                        }
                        MapCaption("${venue.name} · TOCCA IL PUNTO PER LE INDICAZIONI")
                        Spacer(Modifier.height(10.dp))
                        ActionButton("INDICAZIONI", Icons.Outlined.Map) { directions(context, venue.lat, venue.lng, venue.name) }
                    }
                }
                AccessibilitySection(venue)
                openingHours(venue)?.takeIf { it.isNotEmpty() }?.let { hours ->
                    DetailSection("ORARI") { hours.forEach { (day, value) -> LabeledValue(day, value) } }
                }
                EditorialInformation(venue.contentDetails)
                if (venue.transit.isNotEmpty()) {
                    DetailSection("COME ARRIVARE") { venue.transit.forEach { LabeledValue(it.mode.replace('_', ' '), it.text) } }
                }
                if (venue.info.isNotEmpty() || venue.capacity != null) {
                    DetailSection("INFORMAZIONI DEL LOCALE") {
                        venue.capacity?.let { LabeledValue("Capienza", "$it persone") }
                        venue.info.forEach { LabeledValue(it.label, it.value) }
                    }
                }
                DetailSection("TUTTI GLI EVENTI") {
                    if (events.isEmpty()) Text("Nessun evento futuro in calendario.", color = Muted)
                    events.forEach { event -> CompactEventRow(event, event.occurrenceId in savedIds, onOpenEvent, onSave) }
                }
                if (pastEvents.isNotEmpty()) {
                    DetailSection("EVENTI PASSATI") {
                        pastEvents.forEach { event -> CompactEventRow(event, event.occurrenceId in savedIds, onOpenEvent, onSave) }
                    }
                }
                Spacer(Modifier.height(28.dp))
            }
        }
    }
}

@Composable
fun VenuesScreen(state: AppUiState, padding: PaddingValues, onVenue: (Venue) -> Unit, onBack: () -> Unit) {
    var query by remember { mutableStateOf("") }
    var selectedType by remember { mutableStateOf<String?>(null) }
    val canonicalTypes = listOf(
        "associazione", "teatro", "bar", "centro_sociale", "circolo", "club",
        "pub", "cinema", "libreria", "galleria", "spazio_pubblico", "ristorante", "altro",
    )
    val types = (canonicalTypes + state.venues.mapNotNull(Venue::type)).distinct()
    val filtered = state.venues.filter { venue ->
        val needle = query.trim().lowercase(Locale.ITALIAN)
        val matchesText = needle.isBlank() || listOfNotNull(
            venue.name, venue.description, venue.shortDescription, venue.address,
            venue.zone, venue.municipality, venue.type?.replace('_', ' '),
        ).any { it.lowercase(Locale.ITALIAN).contains(needle) }
        matchesText && (selectedType == null || venue.type == selectedType)
    }
    LazyColumn(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding())) {
        item { ScreenHeader("LOCALI", "POSTI DOVE SUCCEDE QUALCOSA", onBack) }
        item {
            Column(Modifier.padding(horizontal = 18.dp, vertical = 14.dp)) {
                OutlinedTextField(
                    value = query,
                    onValueChange = { query = it },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    label = { Text("FILTRA PER NOME, ZONA O INDIRIZZO") },
                    leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                    shape = androidx.compose.ui.graphics.RectangleShape,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedTextColor = Paper,
                        unfocusedTextColor = Paper,
                        focusedBorderColor = Acid,
                        unfocusedBorderColor = Rule,
                        focusedLabelColor = Acid,
                        unfocusedLabelColor = Muted,
                        cursorColor = Acid,
                    ),
                )
                Row(
                    Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(top = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    VenueTypeFilter("TUTTI", selectedType == null) { selectedType = null }
                    types.forEach { type ->
                        VenueTypeFilter(venueTypeLabel(type), selectedType == type) { selectedType = type }
                    }
                }
                Text("${filtered.size} LOCALI", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium, modifier = Modifier.padding(top = 12.dp))
            }
        }
        if (filtered.isEmpty()) {
            item { Text("Nessun locale corrisponde ai filtri.", color = Muted, modifier = Modifier.padding(24.dp)) }
        }
        items(filtered, key = { it.slug ?: it.id ?: it.name }) { venue ->
            Row(Modifier.fillMaxWidth().height(132.dp).clickable { onVenue(venue) }) {
                RichImage(venue.cover, Modifier.width(118.dp).fillMaxHeight())
                Column(Modifier.weight(1f).padding(14.dp)) {
                    Eyebrow(venue.type?.let(::venueTypeLabel) ?: "LOCALE")
                    Text(venue.name.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Spacer(Modifier.weight(1f))
                    Text("${venue.municipality ?: "PADOVA"} · ${venue.upcomingOccurrences ?: 0} EVENTI", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                }
            }
            HorizontalDivider(color = Rule)
        }
    }
}

@Composable
private fun VenueTypeFilter(label: String, selected: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        shape = androidx.compose.ui.graphics.RectangleShape,
        colors = ButtonDefaults.buttonColors(
            containerColor = if (selected) Acid else Ink,
            contentColor = if (selected) Ink else Paper,
        ),
        border = BorderStroke(2.dp, if (selected) Acid else Rule),
        contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp),
    ) { Text(label, style = androidx.compose.material3.MaterialTheme.typography.labelMedium) }
}

@Composable
fun CalendarScreen(state: AppUiState, padding: PaddingValues, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit, onBack: () -> Unit) {
    val grouped = state.occurrences.groupBy { it.startsAt.take(10) }.toSortedMap()
    LazyColumn(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding())) {
        item { ScreenHeader("CALENDARIO", "LE PROSSIME DATE IN CITTÀ", onBack) }
        item { CalendarSubscriptionPanel(initiallyExpanded = true) }
        if (grouped.isEmpty()) item { Text("Nessuna data disponibile.", color = Muted, modifier = Modifier.padding(24.dp)) }
        grouped.forEach { (_, events) ->
            item {
                Text(fullDate(events.first().startsAt), color = Ink, modifier = Modifier.fillMaxWidth().background(Acid).padding(12.dp, 9.dp), style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
            }
            items(events, key = { it.occurrenceId }) { event -> CompactEventRow(event, event.occurrenceId in state.savedIds, onOpen, onSave) }
        }
    }
}

@Composable
fun MapScreen(
    state: AppUiState,
    padding: PaddingValues,
    onMarker: (List<MapMarker>) -> Unit,
    onFilter: (EventFilter) -> Unit,
    onOpen: (Occurrence) -> Unit,
    onDismissPreview: () -> Unit,
) {
    Column(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding())) {
        val venueCount = state.mapMarkers.distinctBy { "%.5f:%.5f".format(Locale.US, it.lat, it.lng) }.size
        ScreenHeader("MAPPA", "${mapFilterLabel(state.mapFilter)} · $venueCount LUOGHI · ${state.mapMarkers.size} APPUNTAMENTI")
        Row(
            Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 9.dp),
            horizontalArrangement = Arrangement.spacedBy(7.dp),
        ) {
            FilterLabel("OGGI", state.mapFilter == EventFilter.TODAY) { onFilter(EventFilter.TODAY) }
            FilterLabel("DOMANI", state.mapFilter == EventFilter.TOMORROW) { onFilter(EventFilter.TOMORROW) }
            FilterLabel("WEEKEND", state.mapFilter == EventFilter.WEEKEND) { onFilter(EventFilter.WEEKEND) }
            FilterLabel("GRATIS", state.mapFilter == EventFilter.FREE) { onFilter(EventFilter.FREE) }
            FilterLabel("TUTTI", state.mapFilter == EventFilter.ALL) { onFilter(EventFilter.ALL) }
        }
        if (state.isMapLoading && state.mapMarkers.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("CARICAMENTO MAPPA…", color = Muted) }
        } else if (state.mapMarkers.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("NESSUN APPUNTAMENTO PER QUESTO FILTRO", color = Muted) }
        } else {
            val points = state.mapMarkers.map { MapPoint(it.lat, it.lng, it.title, it) }
            Box(Modifier.fillMaxWidth().weight(1f)) {
                key(state.mapFilter, state.mapMarkers.map(MapMarker::id)) {
                    InteractiveMap(points, Modifier.fillMaxSize(), 11.2) { group ->
                        onMarker(group.mapNotNull(MapPoint::payload))
                    }
                }
                if (state.mapPreviewEvents.isNotEmpty()) {
                    MapVenuePreview(
                        events = state.mapPreviewEvents,
                        total = state.mapPreviewTotal,
                        onOpen = onOpen,
                        onDismiss = onDismissPreview,
                        modifier = Modifier.align(Alignment.BottomCenter).padding(12.dp),
                    )
                }
            }
            Text("NUMERO = LUOGHI · PUNTO = EVENTI DEL LOCALE · © OPENFREEMAP · © OPENSTREETMAP", color = Ink, modifier = Modifier.fillMaxWidth().background(Acid).padding(12.dp), style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
        }
    }
}

@Composable
private fun MapVenuePreview(events: List<Occurrence>, total: Int, onOpen: (Occurrence) -> Unit, onDismiss: () -> Unit, modifier: Modifier = Modifier) {
    val venueName = events.firstOrNull()?.venue?.name ?: "LUOGO EVENTO"
    Surface(modifier.fillMaxWidth().heightIn(max = 390.dp), color = Ink, contentColor = Paper, border = BorderStroke(2.dp, Acid)) {
        Column(Modifier.verticalScroll(rememberScrollState()).padding(14.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text("EVENTI IN QUESTO LUOGO", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                    Text(venueName.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
                    Text("$total APPUNTAMENTI", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                }
                IconButton(onClick = onDismiss) { Icon(Icons.Outlined.Close, "Chiudi anteprima") }
            }
            events.forEach { event ->
                HorizontalDivider(Modifier.padding(vertical = 9.dp), color = Rule)
                Column(Modifier.fillMaxWidth().clickable { onOpen(event) }.padding(vertical = 3.dp)) {
                    Text(event.category?.name?.uppercase() ?: "EVENTO", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                    Text(event.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleMedium)
                    Text("${fullDate(event.startsAt)} · ${timeRange(event)}", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                    Text(priceText(event.price).uppercase(), color = Acid, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
                    Text("APRI EVENTO →", modifier = Modifier.align(Alignment.End).padding(top = 5.dp), style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                }
            }
            if (total > events.size) {
                Text("MOSTRATI I PRIMI ${events.size} DI $total APPUNTAMENTI", color = Muted, modifier = Modifier.padding(top = 8.dp))
            }
        }
    }
}

@Composable
fun ConsentOverlay(onChoice: (Boolean) -> Unit) {
    val context = LocalContext.current
    Box(Modifier.fillMaxSize().background(Color(0xDD000000)), contentAlignment = Alignment.BottomCenter) {
        Column(Modifier.fillMaxWidth().background(Paper).padding(20.dp).padding(bottom = 20.dp)) {
            Text("DUE PAROLE SU COSA SALVIAMO", color = Ink, style = androidx.compose.material3.MaterialTheme.typography.headlineMedium)
            Text(
                "L'app conserva sul dispositivo sessione, preferenze e date salvate. Mappe, immagini e link esterni possono inviare dati tecnici ai rispettivi fornitori. Consulta le informative per sapere quali dati sono usati e come modificare la scelta.",
                color = Color(0xFF353532),
                modifier = Modifier.padding(top = 12.dp),
            )
            Row(Modifier.fillMaxWidth().padding(top = 14.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { onChoice(true) }, modifier = Modifier.weight(1f).height(50.dp), shape = androidx.compose.ui.graphics.RectangleShape, colors = ButtonDefaults.buttonColors(containerColor = Acid, contentColor = Ink)) { Text("ACCETTA") }
                OutlinedButton(onClick = { onChoice(false) }, modifier = Modifier.weight(1f).height(50.dp), shape = androidx.compose.ui.graphics.RectangleShape, border = BorderStroke(2.dp, Ink)) { Text("RIFIUTA", color = Ink) }
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                TextButton(onClick = { openUrl(context, "https://eventi.fabiodalez.it/pagine/privacy") }) { Text("PRIVACY", color = Ink) }
                TextButton(onClick = { openUrl(context, "https://eventi.fabiodalez.it/pagine/cookie") }) { Text("COOKIE POLICY", color = Ink) }
            }
        }
    }
}

private data class MapPoint(val lat: Double, val lng: Double, val title: String, val payload: MapMarker? = null)

@Suppress("DEPRECATION")
@Composable
private fun InteractiveMap(
    points: List<MapPoint>,
    modifier: Modifier,
    zoom: Double,
    onMarker: (List<MapPoint>) -> Unit = {},
) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val mapView = remember(points) {
        MapLibre.getInstance(context)
        MapView(context).apply {
            onCreate(null)
            getMapAsync { map ->
                val center = points.firstOrNull()?.let { LatLng(it.lat, it.lng) } ?: LatLng(45.4064, 11.8768)
                map.setMaxZoomPreference(19.0)
                map.cameraPosition = CameraPosition.Builder().target(center).zoom(zoom).build()
                map.uiSettings.apply {
                    isCompassEnabled = true
                    isLogoEnabled = false
                    isAttributionEnabled = false
                    isZoomGesturesEnabled = true
                    isScrollGesturesEnabled = true
                    isRotateGesturesEnabled = false
                    isTiltGesturesEnabled = false
                }
                map.setStyle(Style.Builder().fromUri(MAP_STYLE)) {
                    val markerGroups = mutableMapOf<Long, List<MapPoint>>()
                    val groupPositions = mutableMapOf<Long, LatLng>()
                    val groupVenueCounts = mutableMapOf<Long, Int>()
                    val iconFactory = IconFactory.getInstance(context)

                    fun cellSize(currentZoom: Double): Double = when {
                        currentZoom < 12.5 -> 0.018
                        currentZoom < 14.5 -> 0.0045
                        currentZoom < 16.0 -> 0.0008
                        else -> 0.00004
                    }

                    fun renderMarkers() {
                        map.clear()
                        markerGroups.clear()
                        groupPositions.clear()
                        groupVenueCounts.clear()
                        val cell = cellSize(map.cameraPosition.zoom)
                        val venueGroups = points.groupBy { point ->
                            "%.5f:%.5f".format(Locale.US, point.lat, point.lng)
                        }
                        val spatialGroups = venueGroups.values.groupBy { venueGroup ->
                            val point = venueGroup.first()
                            (point.lat / cell).roundToInt() to (point.lng / cell).roundToInt()
                        }
                        spatialGroups.values.forEach { venueGroupCluster ->
                            val group = venueGroupCluster.flatten()
                            val position = LatLng(group.map(MapPoint::lat).average(), group.map(MapPoint::lng).average())
                            val venueCount = venueGroupCluster.size
                            val icon = iconFactory.fromBitmap(mapMarkerBitmap(venueCount))
                            val marker = map.addMarker(MarkerOptions().position(position).icon(icon))
                            markerGroups[marker.id] = group
                            groupPositions[marker.id] = position
                            groupVenueCounts[marker.id] = venueCount
                        }
                    }

                    map.setOnMarkerClickListener { marker ->
                        val group = markerGroups[marker.id].orEmpty()
                        val position = groupPositions[marker.id]
                        val venueCount = groupVenueCounts[marker.id] ?: 0
                        if (venueCount > 1 && map.cameraPosition.zoom < 14.1 && position != null) {
                            map.animateCamera(
                                CameraUpdateFactory.newLatLngZoom(position, (map.cameraPosition.zoom + 2.0).coerceAtMost(14.2)),
                                350,
                            )
                        } else if (group.isNotEmpty()) {
                            onMarker(group)
                        }
                        true
                    }
                    map.addOnCameraIdleListener(::renderMarkers)
                    renderMarkers()
                    this@apply.doOnLayout {
                        val locations = points.map { LatLng(it.lat, it.lng) }.distinctBy { it.latitude to it.longitude }
                        if (locations.size > 1) {
                            map.moveCamera(CameraUpdateFactory.newLatLngBounds(LatLngBounds.Builder().includes(locations).build(), (48 * context.resources.displayMetrics.density).toInt()))
                        } else if (locations.size == 1) {
                            map.moveCamera(CameraUpdateFactory.newLatLngZoom(locations.first(), zoom.coerceAtLeast(14.0)))
                        }
                    }
                }
            }
        }
    }
    DisposableEffect(mapView, lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                Lifecycle.Event.ON_DESTROY -> mapView.onDestroy()
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            mapView.onDestroy()
        }
    }
    AndroidView(factory = { mapView }, modifier = modifier.background(Color(0xFF242424)))
}

private fun mapMarkerBitmap(count: Int): Bitmap {
    val size = when {
        count >= 50 -> 60
        count >= 10 -> 54
        count > 1 -> 48
        else -> 42
    }
    val center = size / 2f
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(bitmap)
    val paint = Paint(Paint.ANTI_ALIAS_FLAG)

    if (count == 1) {
        paint.style = Paint.Style.STROKE
        paint.strokeWidth = 3f
        paint.color = android.graphics.Color.argb(95, 204, 255, 0)
        canvas.drawCircle(center, center, 18f, paint)
        paint.style = Paint.Style.FILL
        paint.color = android.graphics.Color.rgb(204, 255, 0)
        canvas.drawCircle(center, center, 10f, paint)
        paint.style = Paint.Style.STROKE
        paint.strokeWidth = 3f
        paint.color = android.graphics.Color.rgb(11, 11, 11)
        canvas.drawCircle(center, center, 10f, paint)
    } else {
        paint.style = Paint.Style.FILL
        paint.color = android.graphics.Color.rgb(204, 255, 0)
        canvas.drawCircle(center, center, center - 3f, paint)
        paint.style = Paint.Style.STROKE
        paint.strokeWidth = 4f
        paint.color = android.graphics.Color.rgb(11, 11, 11)
        canvas.drawCircle(center, center, center - 3f, paint)
        paint.style = Paint.Style.FILL
        paint.color = android.graphics.Color.rgb(11, 11, 11)
        paint.textAlign = Paint.Align.CENTER
        paint.typeface = android.graphics.Typeface.DEFAULT_BOLD
        paint.textSize = if (count >= 100) 16f else 18f
        val baseline = center - (paint.ascent() + paint.descent()) / 2f
        canvas.drawText(if (count > 99) "99+" else count.toString(), center, baseline, paint)
    }
    return bitmap
}

@Composable
private fun AccessibilitySection(venue: Venue) {
    val enabled = venue.accessibility.filterValues { it }
    if (enabled.isEmpty()) return
    DetailSection("ACCESSIBILITÀ") {
        enabled.keys.forEach { key ->
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.AutoMirrored.Outlined.Accessible, null, tint = Acid)
                Text(accessibilityLabel(key).uppercase(), modifier = Modifier.padding(start = 10.dp), fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun DetailBar(title: String, onBack: () -> Unit, action: @Composable () -> Unit = {}) {
    Row(Modifier.fillMaxWidth().height(58.dp).background(Acid), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = onBack, modifier = Modifier.size(56.dp)) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Indietro", tint = Ink) }
        Text(title, color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
        action()
    }
}

@Composable
private fun ScreenHeader(title: String, subtitle: String, onBack: (() -> Unit)? = null) {
    Column(Modifier.fillMaxWidth().statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().height(64.dp), verticalAlignment = Alignment.CenterVertically) {
            if (onBack != null) IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Indietro") }
            Column(Modifier.padding(horizontal = if (onBack == null) 18.dp else 4.dp)) {
                Text(title, style = androidx.compose.material3.MaterialTheme.typography.headlineLarge)
                Text(subtitle, color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
            }
        }
        HorizontalDivider(thickness = 2.dp, color = Paper)
    }
}

@Composable
private fun DetailSection(title: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 18.dp)) {
        Text(title, color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
        HorizontalDivider(Modifier.padding(top = 8.dp, bottom = 14.dp), thickness = 2.dp, color = Paper)
        content()
    }
}

@Composable
private fun CompactEventRow(event: Occurrence, saved: Boolean, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    Row(Modifier.fillMaxWidth().height(104.dp).clickable { onOpen(event) }, verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f).padding(vertical = 12.dp)) {
            Text("${shortDate(event.startsAt)} · ${if (event.isAllDay) "TUTTO IL GIORNO" else clock(event.startsAt)}", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
            Text(event.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        IconButton(onClick = { onSave(event.occurrenceId) }, modifier = Modifier.background(if (saved) Acid else Ink)) {
            Icon(if (saved) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder, if (saved) "Rimuovi" else "Salva", tint = if (saved) Ink else Paper)
        }
    }
    HorizontalDivider(color = Rule)
}

@Composable
private fun RichImage(url: String?, modifier: Modifier, contentScale: ContentScale = ContentScale.Crop) {
    val matrix = remember { ColorMatrix().apply { setToSaturation(0f) } }
    Box(modifier.background(Color(0xFF202020)).clipToBounds()) {
        if (url != null) AsyncImage(url, null, Modifier.fillMaxSize(), contentScale = contentScale, colorFilter = ColorFilter.colorMatrix(matrix))
        else Text("IN CITTÀ", color = Acid, modifier = Modifier.align(Alignment.Center), style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
    }
}

@Composable
private fun EventPoster(url: String?, revealKey: Any, modifier: Modifier, onClick: () -> Unit) {
    var loaded by remember(url, revealKey) { mutableStateOf(false) }
    var revealColor by remember(url, revealKey) { mutableStateOf(false) }
    LaunchedEffect(loaded, url, revealKey) {
        if (loaded && url != null) {
            delay(320)
            revealColor = true
        }
    }
    val saturation by animateFloatAsState(
        targetValue = if (revealColor) 1f else 0f,
        animationSpec = tween(durationMillis = 1500),
        label = "poster-color-reveal",
    )
    val matrix = remember(saturation) { ColorMatrix().apply { setToSaturation(saturation) } }
    Box(modifier.background(Color(0xFF202020)).clipToBounds().clickable(enabled = url != null, onClick = onClick)) {
        if (url != null) {
            AsyncImage(
                model = url,
                contentDescription = "Locandina, tocca per ingrandire",
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Fit,
                colorFilter = ColorFilter.colorMatrix(matrix),
                onSuccess = { loaded = true },
            )
        } else {
            Text("IN CITTÀ", color = Acid, modifier = Modifier.align(Alignment.Center), style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
        }
    }
}

@Composable
private fun PosterLightbox(url: String?, title: String, onClose: () -> Unit) {
    if (url == null) return
    BackHandler(onBack = onClose)
    Box(
        Modifier.fillMaxSize().background(Color(0xF7000000)).clickable(onClick = onClose).statusBarsPadding(),
        contentAlignment = Alignment.Center,
    ) {
        AsyncImage(
            model = url,
            contentDescription = "Locandina completa di $title",
            modifier = Modifier.fillMaxSize().padding(horizontal = 12.dp, vertical = 70.dp).clickable { },
            contentScale = ContentScale.Fit,
        )
        Row(
            Modifier.align(Alignment.TopCenter).fillMaxWidth().background(Acid).padding(start = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(title.uppercase(), color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f), style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
            IconButton(onClick = onClose, modifier = Modifier.size(58.dp)) {
                Icon(Icons.Outlined.Close, contentDescription = "Chiudi locandina", tint = Ink)
            }
        }
    }
}

@Composable private fun Eyebrow(text: String) = Text(text.uppercase(), color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelLarge, modifier = Modifier.padding(bottom = 7.dp))

@Composable
private fun LabeledValue(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.Top) {
        Text(label.uppercase(), color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium, modifier = Modifier.width(112.dp))
        Text(value, modifier = Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun TagButton(tag: Tag, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, shape = androidx.compose.ui.graphics.RectangleShape, border = BorderStroke(2.dp, Rule)) { Text("#${tag.name}") }
}

@Composable
private fun SmallLink(text: String, onClick: () -> Unit) {
    TextButton(onClick = onClick, contentPadding = PaddingValues(vertical = 5.dp)) {
        Text(text.uppercase(), color = Paper, modifier = Modifier.weight(1f))
        Icon(Icons.AutoMirrored.Outlined.OpenInNew, null, tint = Acid, modifier = Modifier.size(18.dp))
    }
}

@Composable
private fun ActionButton(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth().height(52.dp), shape = androidx.compose.ui.graphics.RectangleShape, colors = ButtonDefaults.buttonColors(containerColor = Paper, contentColor = Ink)) {
        Text(text, modifier = Modifier.weight(1f))
        Icon(icon, null)
    }
}

private fun priceText(price: Price?): String = when (price?.type) {
    "free" -> "Ingresso gratuito"
    "donation" -> price.notes ?: "Offerta libera"
    "ticket", "paid" -> when {
        price.min != null && price.max != null && price.min != price.max -> "${formatEuro(price.min)} – ${formatEuro(price.max)}"
        price.min != null -> formatEuro(price.min)
        price.max != null -> "Fino a ${formatEuro(price.max)}"
        else -> "A pagamento"
    }
    "membership" -> price.notes ?: "Ingresso con tessera"
    else -> price?.notes ?: "Prezzo non indicato"
}

private fun formatEuro(value: Double): String = if (value % 1.0 == 0.0) "${value.toInt()} €" else String.format(Locale.ITALIAN, "%.2f €", value)
private fun tierStatus(value: String): String = when (value) { "sold_out" -> "Esaurito"; "not_on_sale" -> "Non in vendita"; else -> "Disponibile" }
private fun accessibilityLabel(key: String): String = when (key) {
    "step_free_entrance" -> "Ingresso senza scalini"
    "accessible_toilets" -> "Servizi igienici accessibili"
    "reserved_seating" -> "Posti riservati"
    "tactile_path" -> "Percorso tattile"
    "assistance_on_request" -> "Assistenza su richiesta"
    "guide_dog_allowed" -> "Cani guida ammessi"
    else -> key.replace('_', ' ')
}
private fun venueTypeLabel(value: String): String = when (value) {
    "centro_sociale" -> "CENTRO SOCIALE"
    "spazio_pubblico" -> "SPAZIO PUBBLICO"
    else -> value.replace('_', ' ').uppercase(Locale.ITALIAN)
}
private fun languageName(value: String): String = when (value.lowercase()) { "it" -> "Italiano"; "en" -> "Inglese"; else -> value.uppercase() }
private fun address(venue: Venue): String = listOfNotNull(venue.address, venue.addressExtra, venue.postalCode, venue.municipality).joinToString(" ").ifBlank { venue.municipality ?: "Indirizzo non indicato" }
private fun openingHours(venue: Venue): List<Pair<String, String>>? {
    val root = venue.openingHours as? JsonObject ?: return null
    val labels = mapOf("mon" to "Lunedì", "tue" to "Martedì", "wed" to "Mercoledì", "thu" to "Giovedì", "fri" to "Venerdì", "sat" to "Sabato", "sun" to "Domenica")
    return labels.mapNotNull { (key, label) ->
        val intervals = root[key] as? JsonArray ?: return@mapNotNull null
        val value = intervals.mapNotNull { element ->
            val row = runCatching { element.jsonObject }.getOrNull() ?: return@mapNotNull null
            val open = row["open"]?.jsonPrimitive?.contentOrNull ?: return@mapNotNull null
            val close = row["close"]?.jsonPrimitive?.contentOrNull ?: return@mapNotNull null
            "$open–$close"
        }.joinToString(", ")
        if (value.isBlank()) null else label to value
    }
}

private val fullFormatter = DateTimeFormatter.ofPattern("EEEE d MMMM yyyy", Locale.ITALIAN)
private val shortFormatter = DateTimeFormatter.ofPattern("EEE d MMM", Locale.ITALIAN)
private val clockFormatter = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)
private fun parsed(value: String): OffsetDateTime? = runCatching { OffsetDateTime.parse(value) }.getOrNull()
private fun fullDate(value: String): String = parsed(value)?.format(fullFormatter)?.replaceFirstChar { it.uppercase() } ?: value.take(10)
private fun shortDate(value: String): String = parsed(value)?.format(shortFormatter)?.uppercase() ?: value.take(10)
private fun clock(value: String): String = parsed(value)?.format(clockFormatter) ?: "--:--"
private fun timeRange(event: Occurrence): String {
    if (event.isAllDay) return "Tutto il giorno"
    val end = event.endsAt ?: event.effectiveEndsAt
    val prefix = "Dalle ${clock(event.startsAt)}"
    return if (end == null) prefix else "$prefix · ${if (event.endsAtEstimated) "fine stimata " else "fino alle "}${clock(end)}"
}

private fun addToCalendar(context: android.content.Context, detail: EventDetail, occurrence: Occurrence) {
    openGoogleCalendar(
        context = context,
        event = occurrence,
        title = detail.title,
        description = detail.description ?: detail.shortDescription,
        location = (occurrence.venue ?: detail.venue)?.let { "${it.name}, ${address(it)}" } ?: detail.customLocation?.let { listOfNotNull(it.name, it.address, it.municipality).joinToString(", ") },
        url = detail.url ?: "https://eventi.fabiodalez.it/eventi/${detail.slug}",
    )
}

@Composable
private fun MapCaption(text: String) {
    Column(Modifier.fillMaxWidth().background(Acid).padding(10.dp)) {
        Text(text.uppercase(), color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
        Text("© OPENFREEMAP · © OPENSTREETMAP", color = Ink, fontSize = 10.sp)
    }
}

private fun mapFilterLabel(filter: EventFilter): String = when (filter) {
    EventFilter.TODAY -> "OGGI"
    EventFilter.TOMORROW -> "DOMANI"
    EventFilter.WEEKEND -> "WEEKEND"
    EventFilter.FREE -> "GRATIS"
    EventFilter.ALL -> "TUTTI"
}

private fun share(context: android.content.Context, title: String, url: String) {
    context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_SUBJECT, title)
        putExtra(Intent.EXTRA_TEXT, "$title\n$url")
    }, "Condividi evento"))
}
private fun openUrl(context: android.content.Context, url: String) { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri())) } }
private fun dial(context: android.content.Context, phone: String) = openUrl(context, "tel:${phone.filter { it.isDigit() || it == '+' }}")
private fun directions(context: android.content.Context, lat: Double, lng: Double, label: String) {
    val encoded = URLEncoder.encode(label, StandardCharsets.UTF_8.toString())
    openUrl(context, "geo:$lat,$lng?q=$lat,$lng($encoded)")
}
