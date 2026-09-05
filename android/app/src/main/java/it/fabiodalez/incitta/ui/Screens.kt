package it.fabiodalez.incitta.ui

import android.content.Intent
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.filled.Bookmark
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.LocationOn
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.net.toUri
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.data.EventDetail
import it.fabiodalez.incitta.data.EventFilter
import it.fabiodalez.incitta.data.Occurrence
import it.fabiodalez.incitta.data.Tag
import it.fabiodalez.incitta.data.Venue
import java.time.OffsetDateTime
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import java.util.Locale

@Composable
fun EventsScreen(
    state: AppUiState,
    padding: PaddingValues,
    onOpen: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
    onRefresh: () -> Unit,
    onFilter: (EventFilter) -> Unit,
    onCalendar: () -> Unit,
    onVenues: () -> Unit,
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()),
    ) {
        item { BrandHeader() }
        if (state.isOffline) item { StatusStrip("MODALITÀ OFFLINE · ULTIMO AGGIORNAMENTO DISPONIBILE") }
        item { Ticker() }
        item {
            Column(Modifier.padding(horizontal = 18.dp, vertical = 22.dp)) {
                Text("STASERA\nIN CITTÀ", style = androidx.compose.material3.MaterialTheme.typography.displayLarge)
                Spacer(Modifier.height(10.dp))
                Text("PADOVA · EVENTI VERIFICATI E POSTI DOVE ANDARE", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
            }
        }
        item {
            Row(
                Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 18.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                FilterLabel("TUTTI", state.eventFilter == EventFilter.ALL) { onFilter(EventFilter.ALL) }
                FilterLabel("OGGI", state.eventFilter == EventFilter.TODAY) { onFilter(EventFilter.TODAY) }
                FilterLabel("DOMANI", state.eventFilter == EventFilter.TOMORROW) { onFilter(EventFilter.TOMORROW) }
                FilterLabel("WEEKEND", state.eventFilter == EventFilter.WEEKEND) { onFilter(EventFilter.WEEKEND) }
                FilterLabel("GRATIS", state.eventFilter == EventFilter.FREE) { onFilter(EventFilter.FREE) }
                FilterLabel("CALENDARIO", false, onCalendar)
                FilterLabel("LOCALI", false, onVenues)
                IconButton(onClick = onRefresh, modifier = Modifier.size(48.dp).background(Paper)) {
                    Icon(Icons.Outlined.Refresh, contentDescription = "Aggiorna", tint = Ink)
                }
            }
        }
        if (state.isLoading && state.occurrences.isEmpty()) {
            item { LoadingBlock() }
        } else if (state.occurrences.isEmpty()) {
            item { EmptyBlock("NESSUN EVENTO IN CARTELLONE", "Torna più tardi: la città sta ancora preparando la serata.") }
        } else {
            val featured = state.occurrences.first()
            item {
                Spacer(Modifier.height(24.dp))
                FeatureCard(featured, featured.occurrenceId in state.savedIds, onOpen, onSave)
                SectionTitle("IN PROGRAMMA", "${state.occurrences.size} APPUNTAMENTI")
            }
            items(state.occurrences.drop(1), key = Occurrence::occurrenceId) { occurrence ->
                EventRow(occurrence, occurrence.occurrenceId in state.savedIds, onOpen, onSave)
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
}

@Composable
fun SearchScreen(
    state: AppUiState,
    padding: PaddingValues,
    onSearch: (String) -> Unit,
    onOpen: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
    onVenue: (Venue) -> Unit,
    onTag: (Tag) -> Unit,
    onClearTag: () -> Unit,
) {
    var query by remember { mutableStateOf("") }
    LazyColumn(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding())) {
        item { BrandHeader(compact = true) }
        item {
            Column(Modifier.padding(18.dp)) {
                Text("CERCA", style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                Spacer(Modifier.height(16.dp))
                OutlinedTextField(
                    value = query,
                    onValueChange = { query = it; onSearch(it) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    label = { Text("EVENTO, LUOGO, CATEGORIA") },
                    shape = RectangleShape,
                    leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                    colors = fieldColors(),
                )
                Text("Scrivi almeno due caratteri. La ricerca include eventi e locali di Padova.", color = Muted, modifier = Modifier.padding(top = 10.dp))
                state.activeTag?.let { tag ->
                    Button(
                        onClick = onClearTag,
                        modifier = Modifier.padding(top = 14.dp),
                        shape = RectangleShape,
                        colors = ButtonDefaults.buttonColors(containerColor = Acid, contentColor = Ink),
                    ) {
                        Text("#${tag.name.uppercase()}")
                        Icon(Icons.Outlined.Close, contentDescription = "Rimuovi filtro tag", modifier = Modifier.padding(start = 8.dp).size(18.dp))
                    }
                }
            }
        }
        if (state.isSearching) item { LoadingBlock() }
        if ((query.length >= 2 || state.activeTag != null) && !state.isSearching && state.searchResults.isEmpty() && state.searchVenues.isEmpty()) {
            item { EmptyBlock("NESSUN RISULTATO", "Prova un genere, il nome di un locale o una parola più breve.") }
        }
        val venues = when {
            state.activeTag != null -> emptyList()
            query.length >= 2 -> state.searchVenues
            else -> state.venues
        }
        if (state.searchTags.isNotEmpty()) {
            item {
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    state.searchTags.forEach { tag -> FilterLabel("#${tag.name}", false) { onTag(tag) } }
                }
            }
        }
        if (venues.isNotEmpty()) item { SectionTitle("LOCALI", "${venues.size} RISULTATI") }
        items(venues, key = { "venue-${it.id}-${it.slug}" }) { venue -> VenueResultRow(venue, onVenue) }
        if (state.searchResults.isNotEmpty()) item {
            SectionTitle(
                state.activeTag?.let { "EVENTI · #${it.name.uppercase()}" } ?: "EVENTI",
                "${state.searchResults.size} RISULTATI",
            )
        }
        items(state.searchResults, key = Occurrence::occurrenceId) { occurrence ->
            EventRow(occurrence, occurrence.occurrenceId in state.savedIds, onOpen, onSave)
        }
    }
}

@Composable
fun SavedScreen(
    state: AppUiState,
    padding: PaddingValues,
    onOpen: (Occurrence) -> Unit,
    onSave: (Long) -> Unit,
) {
    val context = LocalContext.current
    var mode by remember { mutableIntStateOf(0) }
    val items = state.savedOccurrences
    val initialMonth = remember(items) {
        items.firstOrNull()?.let { date(it.startsAt)?.let { value -> YearMonth.from(value) } } ?: YearMonth.now()
    }
    var calendarMonth by remember(items) { mutableStateOf(initialMonth) }
    var selectedDate by remember(calendarMonth) { mutableStateOf<LocalDate?>(null) }
    val listState = rememberLazyListState()
    val calendarItems = items.filter { event ->
        val day = date(event.startsAt)?.toLocalDate()
        day != null && YearMonth.from(day) == calendarMonth && (selectedDate == null || day == selectedDate)
    }
    val grouped = calendarItems.groupBy { it.startsAt.take(10) }.toSortedMap()

    LaunchedEffect(mode) { listState.scrollToItem(0) }
    LazyColumn(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()), state = listState) {
        item { BrandHeader(compact = true) }
        item {
            Column(Modifier.padding(18.dp)) {
                Text("SALVATI", style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                Spacer(Modifier.height(8.dp))
                Text(
                    if (state.session == null) "RESTANO SU QUESTO DISPOSITIVO · ACCEDI PER SINCRONIZZARLI"
                    else "SINCRONIZZATI CON ${state.session.user.email.uppercase()}",
                    color = Acid,
                    style = androidx.compose.material3.MaterialTheme.typography.labelMedium,
                )
                Row(Modifier.fillMaxWidth().padding(top = 18.dp)) {
                    ModeButton("LISTA", mode == 0, Modifier.weight(1f)) { mode = 0 }
                    ModeButton("CALENDARIO", mode == 1, Modifier.weight(1f)) { mode = 1 }
                }
            }
        }
        if (state.isLoading) item { LoadingBlock() }
        if (!state.isLoading && items.isEmpty() && mode == 0) {
            item { EmptyBlock("LA LISTA È VUOTA", "Tocca il segnalibro su un evento per ritrovarlo qui.") }
        }
        if (mode == 0) {
            items(items, key = Occurrence::occurrenceId) { occurrence ->
                EventRow(occurrence, true, onOpen, onSave)
            }
        } else {
            item(key = "saved-calendar-grid-$calendarMonth") {
                SavedMonthCalendar(
                    month = calendarMonth,
                    events = items,
                    selectedDate = selectedDate,
                    onPrevious = { calendarMonth = calendarMonth.minusMonths(1) },
                    onNext = { calendarMonth = calendarMonth.plusMonths(1) },
                    onSelect = { selectedDate = if (selectedDate == it) null else it },
                )
            }
            if (!state.isLoading && items.isEmpty()) {
                item { EmptyBlock("NESSUN EVENTO NEL CALENDARIO", "Salva una data per vederla evidenziata nel mese e aggiungerla a Google Calendar.") }
            }
            if (calendarItems.isEmpty() && items.isNotEmpty()) {
                item { EmptyBlock("NESSUN SALVATO IN QUESTO PERIODO", "Cambia mese oppure seleziona un altro giorno.") }
            }
            grouped.forEach { (_, events) ->
                item(key = "saved-day-${events.first().startsAt.take(10)}") {
                    Text(
                        formatFullDate(events.first().startsAt),
                        color = Ink,
                        modifier = Modifier.fillMaxWidth().background(Acid).padding(12.dp, 9.dp),
                        style = androidx.compose.material3.MaterialTheme.typography.labelLarge,
                    )
                }
                items(events, key = { "saved-calendar-${it.occurrenceId}" }) { event ->
                    SavedCalendarRow(
                        event = event,
                        onOpen = { onOpen(event) },
                        onGoogleCalendar = { openGoogleCalendar(context, event) },
                    )
                }
            }
        }
    }
}

@Composable
private fun SavedMonthCalendar(
    month: YearMonth,
    events: List<Occurrence>,
    selectedDate: LocalDate?,
    onPrevious: () -> Unit,
    onNext: () -> Unit,
    onSelect: (LocalDate) -> Unit,
) {
    val eventCounts = events.mapNotNull { event -> date(event.startsAt)?.toLocalDate() }
        .groupingBy { it }
        .eachCount()
    val first = month.atDay(1)
    val cells = List(42) { index ->
        val day = index - (first.dayOfWeek.value - 1) + 1
        if (day in 1..month.lengthOfMonth()) month.atDay(day) else null
    }
    val monthLabel = month.format(DateTimeFormatter.ofPattern("MMMM yyyy", Locale.ITALIAN)).uppercase()

    Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 8.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onPrevious, modifier = Modifier.size(48.dp)) { Text("←", fontSize = 24.sp) }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("IL MIO CALENDARIO", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                Text(monthLabel, style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
            }
            TextButton(onClick = onNext, modifier = Modifier.size(48.dp)) { Text("→", fontSize = 24.sp) }
        }
        Row(Modifier.fillMaxWidth().padding(top = 8.dp)) {
            listOf("L", "M", "M", "G", "V", "S", "D").forEach { label ->
                Box(Modifier.weight(1f).height(28.dp), contentAlignment = Alignment.Center) {
                    Text(label, color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
                }
            }
        }
        cells.chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth()) {
                week.forEach { day ->
                    val count = day?.let(eventCounts::get) ?: 0
                    val selected = day != null && day == selectedDate
                    Box(
                        Modifier.weight(1f).aspectRatio(1f)
                            .padding(2.dp)
                            .background(if (selected) Acid else if (count > 0) Color(0xFF2A330F) else Color.Transparent)
                            .clickable(enabled = day != null) { day?.let(onSelect) },
                        contentAlignment = Alignment.Center,
                    ) {
                        if (day != null) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Text(day.dayOfMonth.toString(), color = if (selected) Ink else Paper, fontWeight = if (count > 0) FontWeight.Bold else FontWeight.Normal)
                                if (count > 0) {
                                    Text("$count", color = if (selected) Ink else Acid, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                    }
                }
            }
        }
        Text("TOCCA UN GIORNO PER FILTRARE · TOCCALO ANCORA PER VEDERE TUTTO IL MESE", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelSmall, modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
private fun SavedCalendarRow(event: Occurrence, onOpen: () -> Unit, onGoogleCalendar: () -> Unit) {
    Column(Modifier.fillMaxWidth().clickable(onClick = onOpen).padding(horizontal = 18.dp, vertical = 14.dp)) {
        Text(formatTime(event), color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
        Text(event.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 3.dp))
        Text(event.venue?.name ?: "Luogo da verificare", color = Muted, modifier = Modifier.padding(top = 4.dp))
        OutlinedButton(
            onClick = onGoogleCalendar,
            modifier = Modifier.fillMaxWidth().padding(top = 10.dp).height(46.dp),
            shape = RectangleShape,
            border = BorderStroke(2.dp, Acid),
        ) {
            Icon(Icons.Outlined.CalendarMonth, contentDescription = null, tint = Acid)
            Text("APRI IN GOOGLE CALENDAR", modifier = Modifier.padding(start = 8.dp))
        }
    }
    HorizontalDivider(color = Rule)
}

@Composable
fun AccountScreen(
    state: AppUiState,
    padding: PaddingValues,
    onLogin: (String, String) -> Unit,
    onRegister: (String, String, String) -> Unit,
    onMagic: (String) -> Unit,
    onLogout: () -> Unit,
    onDeleteAccount: (String) -> Unit,
    onTickets: () -> Unit,
) {
    if (state.session != null) {
        var deletePassword by remember { mutableStateOf("") }
        var deleteArmed by remember { mutableStateOf(false) }
        Column(
            Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()).verticalScroll(rememberScrollState()),
        ) {
            BrandHeader(compact = true)
            Column(Modifier.padding(18.dp)) {
                Text("IL TUO PROFILO", style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                Button(onClick = onTickets, modifier = Modifier.fillMaxWidth().padding(top = 18.dp), shape = RectangleShape) { Text(androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.ticket_title)) }
                Spacer(Modifier.height(28.dp))
                MetaLabel("ACCOUNT")
                Text(state.session.user.name?.ifBlank { null } ?: "LETTORE IN CITTÀ", style = androidx.compose.material3.MaterialTheme.typography.headlineLarge)
                Text(state.session.user.email, color = Muted, modifier = Modifier.padding(top = 6.dp))
                HorizontalDivider(Modifier.padding(vertical = 24.dp), thickness = 2.dp, color = Rule)
                Text("I salvataggi appartengono esclusivamente a questo account. Uscendo, quelli sincronizzati non vengono mostrati a un altro utente del dispositivo.")
                Spacer(Modifier.height(28.dp))
                OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth().height(52.dp), shape = RectangleShape, border = BorderStroke(2.dp, Paper)) {
                    Text("ESCI DA QUESTO DISPOSITIVO")
                }
                HorizontalDivider(Modifier.padding(vertical = 28.dp), thickness = 2.dp, color = Rule)
                Text("ZONA CRITICA", color = Danger, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
                Text("La cancellazione rimuove account, salvati, follow, dispositivi e notifiche. Non può essere annullata.", color = Muted, modifier = Modifier.padding(top = 8.dp, bottom = 14.dp))
                if (deleteArmed) {
                    AuthField(deletePassword, { deletePassword = it }, "PASSWORD CORRENTE", password = true)
                    Spacer(Modifier.height(10.dp))
                    Button(
                        onClick = { onDeleteAccount(deletePassword) },
                        enabled = deletePassword.isNotBlank() && !state.isAuthenticating,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        shape = RectangleShape,
                        colors = ButtonDefaults.buttonColors(containerColor = Danger, contentColor = Ink),
                    ) { Text("CONFERMA CANCELLAZIONE") }
                } else {
                    TextButton(onClick = { deleteArmed = true }, modifier = Modifier.fillMaxWidth().height(52.dp)) {
                        Text("CANCELLA IL MIO ACCOUNT", color = Danger)
                    }
                }
            }
        }
        return
    }

    var mode by remember { mutableIntStateOf(0) }
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    Column(
        Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()).verticalScroll(rememberScrollState()).imePadding(),
    ) {
        BrandHeader(compact = true)
        Column(Modifier.padding(18.dp)) {
            Text("ENTRA IN CITTÀ", style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
            Text("Sincronizza i tuoi eventi senza perdere quelli salvati come ospite.", color = Muted, modifier = Modifier.padding(top = 8.dp, bottom = 20.dp))
            Row(Modifier.fillMaxWidth()) {
                ModeButton("ACCEDI", mode == 0, Modifier.weight(1f)) { mode = 0 }
                ModeButton("REGISTRATI", mode == 1, Modifier.weight(1f)) { mode = 1 }
            }
            Spacer(Modifier.height(18.dp))
            if (mode == 1) {
                AuthField(name, { name = it }, "NOME (FACOLTATIVO)")
                Spacer(Modifier.height(10.dp))
            }
            AuthField(email, { email = it }, "EMAIL")
            Spacer(Modifier.height(10.dp))
            AuthField(password, { password = it }, "PASSWORD", password = true)
            Spacer(Modifier.height(16.dp))
            Button(
                onClick = { if (mode == 0) onLogin(email, password) else onRegister(name, email, password) },
                enabled = email.isNotBlank() && password.length >= 8 && !state.isAuthenticating,
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RectangleShape,
                colors = ButtonDefaults.buttonColors(containerColor = Acid, contentColor = Ink, disabledContainerColor = Rule),
            ) {
                if (state.isAuthenticating) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                else Text(if (mode == 0) "ACCEDI" else "CREA ACCOUNT")
            }
            Spacer(Modifier.height(22.dp))
            HorizontalDivider(thickness = 2.dp, color = Rule)
            TextButton(onClick = { if (email.isNotBlank()) onMagic(email) }, modifier = Modifier.fillMaxWidth().height(52.dp)) {
                Text("INVIAMI UN LINK DI ACCESSO", color = Paper)
            }
            Text("Il link è monouso. Non rivela se l’indirizzo è già registrato.", color = Muted, style = androidx.compose.material3.MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
fun EventDetailScreen(
    detail: EventDetail,
    savedIds: Set<Long>,
    onBack: () -> Unit,
    onSave: (Long) -> Unit,
) {
    val context = LocalContext.current
    val occurrence = detail.occurrences.firstOrNull()
    androidx.compose.material3.Surface(
        modifier = Modifier.fillMaxSize(),
        color = Ink,
        contentColor = Paper,
    ) {
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        Row(
            Modifier.fillMaxWidth().height(58.dp).background(Acid),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack, modifier = Modifier.size(56.dp)) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Indietro", tint = Ink) }
            Text("IN CITTÀ / EVENTO", color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
            occurrence?.let {
                IconButton(onClick = { onSave(it.occurrenceId) }, modifier = Modifier.size(56.dp)) {
                    Icon(if (it.occurrenceId in savedIds) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder, "Salva", tint = Ink)
                }
            }
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            PosterImage(detail.poster?.full ?: detail.poster?.card, Modifier.fillMaxWidth().aspectRatio(4f / 3f))
            Column(Modifier.padding(18.dp)) {
                MetaLabel(detail.category?.name ?: "EVENTO")
                Text(detail.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.displayMedium)
                detail.subtitle?.let { Text(it, color = Muted, style = androidx.compose.material3.MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 8.dp)) }
                HorizontalDivider(Modifier.padding(vertical = 20.dp), thickness = 2.dp, color = Paper)
                occurrence?.let {
                    FactLine(Icons.Outlined.CalendarMonth, formatFullDate(it.startsAt))
                    FactLine(Icons.Outlined.Schedule, formatTime(it))
                }
                detail.venue?.let {
                    FactLine(Icons.Outlined.LocationOn, listOfNotNull(it.name, it.address, it.municipality).joinToString(" · "))
                }
                PriceLabel(detail.price)
                detail.description?.let {
                    HorizontalDivider(Modifier.padding(vertical = 20.dp), color = Rule)
                    Text("COSA SAPERE", style = androidx.compose.material3.MaterialTheme.typography.labelLarge, color = Acid)
                    Text(it, style = androidx.compose.material3.MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(top = 10.dp))
                }
                Spacer(Modifier.height(24.dp))
                detail.price?.ticketUrl?.let { url ->
                    PrimaryAction("BIGLIETTI", Icons.AutoMirrored.Outlined.OpenInNew) { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri())) }
                    Spacer(Modifier.height(10.dp))
                }
                PrimaryAction("CONDIVIDI", Icons.Outlined.Share) {
                    context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply {
                        type = "text/plain"
                        putExtra(Intent.EXTRA_TEXT, detail.url ?: "https://eventi.fabiodalez.it/eventi/${detail.slug}")
                    }, "Condividi evento"))
                }
                Spacer(Modifier.height(32.dp))
            }
        }
    }
    }
}

@Composable
private fun BrandHeader(compact: Boolean = false) {
    Row(
        Modifier.fillMaxWidth().statusBarsPadding().height(if (compact) 64.dp else 74.dp).padding(horizontal = 18.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("IN", style = androidx.compose.material3.MaterialTheme.typography.headlineLarge, color = Acid)
        Text("CITTÀ", style = androidx.compose.material3.MaterialTheme.typography.headlineLarge)
        Spacer(Modifier.weight(1f))
        Column(horizontalAlignment = Alignment.End) {
            Text("PADOVA", style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
            Text("VENETO / IT", color = Muted, fontSize = 10.sp)
        }
    }
    HorizontalDivider(thickness = 2.dp, color = Paper)
}

@Composable
private fun Ticker() {
    Row(Modifier.fillMaxWidth().height(34.dp).background(Acid).padding(horizontal = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("●  ORA IN CITTÀ", color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
        Spacer(Modifier.weight(1f))
        Text("EVENTI · LUOGHI · PERSONE", color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun StatusStrip(text: String) {
    Text(text, color = Ink, modifier = Modifier.fillMaxWidth().background(Paper).padding(10.dp), style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
}

@Composable
internal fun FilterLabel(text: String, selected: Boolean = false, onClick: () -> Unit) {
    Box(
        Modifier.height(48.dp)
            .background(if (selected) Acid else Ink)
            .clickable(onClick = onClick)
            .semantics { role = Role.Button; this.selected = selected }
            .padding(horizontal = 16.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = if (selected) Ink else Paper, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
    }
}

@Composable
private fun FeatureCard(event: Occurrence, saved: Boolean, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    Column(Modifier.fillMaxWidth().clickable { onOpen(event) }) {
        Box {
            PosterImage(event.poster?.full ?: event.poster?.card, Modifier.fillMaxWidth().aspectRatio(16f / 9f))
            Text(event.category?.name?.uppercase() ?: "EVENTO", color = Ink, modifier = Modifier.align(Alignment.TopStart).background(Acid).padding(horizontal = 12.dp, vertical = 8.dp), style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
            SaveButton(saved, Modifier.align(Alignment.TopEnd)) { onSave(event.occurrenceId) }
        }
        Row(Modifier.fillMaxWidth().background(Paper).padding(14.dp), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f)) {
                Text(formatDay(event.startsAt), color = Ink, style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
                Text(event.title.uppercase(), color = Ink, style = androidx.compose.material3.MaterialTheme.typography.headlineLarge, maxLines = 3, overflow = TextOverflow.Ellipsis)
                Text(event.venue?.name?.uppercase() ?: "PADOVA", color = Color(0xFF555550), style = androidx.compose.material3.MaterialTheme.typography.labelMedium, modifier = Modifier.padding(top = 8.dp))
            }
            Text(
                if (event.isAllDay) "TUTTO IL GIORNO" else formatClock(event.startsAt),
                color = Ink,
                style = androidx.compose.material3.MaterialTheme.typography.labelLarge,
                modifier = Modifier.padding(start = 10.dp, top = 4.dp),
                maxLines = 2,
            )
        }
    }
}

@Composable
private fun VenueResultRow(venue: Venue, onOpen: (Venue) -> Unit) {
    Row(
        Modifier.fillMaxWidth().height(126.dp).clickable { onOpen(venue) },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PosterImage(venue.cover, Modifier.width(112.dp).fillMaxHeight())
        Column(Modifier.weight(1f).padding(14.dp)) {
            Text(venue.type?.replace('_', ' ')?.uppercase() ?: "LOCALE", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
            Text(venue.name.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(listOfNotNull(venue.address, venue.municipality).joinToString(" · ").uppercase(), color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium, maxLines = 1)
        }
    }
    HorizontalDivider(thickness = 1.dp, color = Rule)
}

@Composable
private fun SectionTitle(title: String, detail: String) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 22.dp), verticalAlignment = Alignment.Bottom) {
        Text(title, style = androidx.compose.material3.MaterialTheme.typography.headlineMedium, modifier = Modifier.weight(1f))
        Text(detail, color = Muted, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
    }
    HorizontalDivider(thickness = 2.dp, color = Paper)
}

@Composable
private fun EventRow(event: Occurrence, saved: Boolean, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit) {
    Row(
        Modifier.fillMaxWidth().height(190.dp).clickable { onOpen(event) },
    ) {
        PosterImage(event.poster?.card ?: event.poster?.thumb, Modifier.width(116.dp).fillMaxHeight())
        Column(Modifier.weight(1f).padding(14.dp)) {
            Text("${formatDay(event.startsAt)} · ${if (event.isAllDay) "TUTTO IL GIORNO" else formatClock(event.startsAt)}", color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
            Text(event.title.uppercase(), style = androidx.compose.material3.MaterialTheme.typography.titleLarge, maxLines = 3, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 5.dp))
            event.shortDescription?.takeIf(String::isNotBlank)?.let {
                Text(it, color = Muted, maxLines = 2, overflow = TextOverflow.Ellipsis, style = androidx.compose.material3.MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 5.dp))
            }
            Spacer(Modifier.weight(1f))
            Text(event.venue?.name?.uppercase() ?: "PADOVA", color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis, style = androidx.compose.material3.MaterialTheme.typography.labelMedium)
        }
        SaveButton(saved, Modifier.align(Alignment.Top)) { onSave(event.occurrenceId) }
    }
    HorizontalDivider(thickness = 1.dp, color = Rule)
}

@Composable
private fun SaveButton(saved: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    IconButton(
        onClick = onClick,
        modifier = modifier.size(52.dp).background(if (saved) Acid else Ink).semantics { role = Role.Button },
    ) {
        Icon(if (saved) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder, if (saved) "Rimuovi dai salvati" else "Salva", tint = if (saved) Ink else Paper)
    }
}

@Composable
private fun PosterImage(url: String?, modifier: Modifier) {
    val matrix = remember { ColorMatrix().apply { setToSaturation(0f) } }
    Box(modifier.background(Color(0xFF202020)).clipToBounds()) {
        if (url != null) {
            AsyncImage(
                model = url,
                contentDescription = null,
                modifier = Modifier.fillMaxSize(),
                contentScale = ContentScale.Crop,
                colorFilter = ColorFilter.colorMatrix(matrix),
            )
        } else {
            Text("IN CITTÀ", color = Acid, modifier = Modifier.align(Alignment.Center), style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
        }
    }
}

@Composable
private fun EmptyBlock(title: String, body: String) {
    Column(Modifier.fillMaxWidth().padding(32.dp, 52.dp)) {
        Text(title, style = androidx.compose.material3.MaterialTheme.typography.headlineMedium)
        Text(body, color = Muted, modifier = Modifier.padding(top = 10.dp))
    }
}

@Composable
private fun LoadingBlock() {
    Box(Modifier.fillMaxWidth().height(180.dp), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = Acid, strokeWidth = 3.dp)
    }
}

@Composable
private fun MetaLabel(text: String) {
    Text(text.uppercase(), color = Acid, style = androidx.compose.material3.MaterialTheme.typography.labelLarge, modifier = Modifier.padding(bottom = 7.dp))
}

@Composable
private fun FactLine(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.Top) {
        Icon(icon, null, tint = Acid, modifier = Modifier.size(22.dp))
        Text(text.uppercase(), modifier = Modifier.padding(start = 12.dp), style = androidx.compose.material3.MaterialTheme.typography.titleMedium)
    }
}

@Composable
private fun PriceLabel(price: it.fabiodalez.incitta.data.Price?) {
    val text = when (price?.type) {
        "free" -> "INGRESSO GRATUITO"
        "paid" -> listOfNotNull(price.min, price.max).joinToString("–") { "€ ${it.toInt()}" }.ifBlank { "A PAGAMENTO" }
        else -> price?.notes
    } ?: return
    Text(text.uppercase(), color = Ink, modifier = Modifier.padding(top = 12.dp).background(Acid).padding(horizontal = 12.dp, vertical = 8.dp), style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
}

@Composable
private fun PrimaryAction(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth().height(54.dp), shape = RectangleShape, colors = ButtonDefaults.buttonColors(containerColor = Paper, contentColor = Ink)) {
        Text(text, modifier = Modifier.weight(1f))
        Icon(icon, null)
    }
}

@Composable
private fun ModeButton(text: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        modifier = modifier.height(48.dp),
        shape = RectangleShape,
        colors = ButtonDefaults.buttonColors(containerColor = if (selected) Acid else Rule, contentColor = if (selected) Ink else Paper),
    ) { Text(text) }
}

@Composable
private fun AuthField(value: String, change: (String) -> Unit, label: String, password: Boolean = false) {
    OutlinedTextField(
        value = value,
        onValueChange = change,
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        label = { Text(label) },
        shape = RectangleShape,
        visualTransformation = if (password) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
        colors = fieldColors(),
    )
}

@Composable
private fun fieldColors() = TextFieldDefaults.colors(
    focusedContainerColor = Ink,
    unfocusedContainerColor = Ink,
    focusedTextColor = Paper,
    unfocusedTextColor = Paper,
    focusedIndicatorColor = Acid,
    unfocusedIndicatorColor = Rule,
    focusedLabelColor = Acid,
    unfocusedLabelColor = Muted,
    cursorColor = Acid,
)

private val dayFormatter = DateTimeFormatter.ofPattern("EEE d MMM", Locale.ITALIAN)
private val fullDateFormatter = DateTimeFormatter.ofPattern("EEEE d MMMM yyyy", Locale.ITALIAN)
private val clockFormatter = DateTimeFormatter.ofPattern("HH:mm", Locale.ITALIAN)

private fun date(value: String): OffsetDateTime? = runCatching { OffsetDateTime.parse(value) }.getOrNull()
private fun formatDay(value: String): String = date(value)?.format(dayFormatter)?.uppercase() ?: value.take(10)
private fun formatFullDate(value: String): String = date(value)?.format(fullDateFormatter)?.uppercase() ?: value.take(10)
private fun formatClock(value: String): String = date(value)?.format(clockFormatter) ?: "--:--"
private fun formatTime(event: Occurrence): String = if (event.isAllDay) "TUTTO IL GIORNO" else "ORE ${formatClock(event.startsAt)}"
