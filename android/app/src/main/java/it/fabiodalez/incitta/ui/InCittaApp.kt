package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.height
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.ime
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.Event
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.DarkMode
import androidx.compose.material.icons.outlined.LightMode
import androidx.compose.material.icons.outlined.Map
import androidx.compose.material.icons.outlined.People
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.BottomAppBar
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.NestedScrollConnection
import androidx.compose.ui.input.nestedscroll.NestedScrollSource
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import it.fabiodalez.incitta.AppTab
import it.fabiodalez.incitta.MainViewModel
import it.fabiodalez.incitta.supportsSponsoredBanner

@Composable
fun InCittaApp(viewModel: MainViewModel) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    InCittaTheme(light = state.appearance == "light") {
        var organizerSlug by remember { mutableStateOf<String?>(null) }
        var communityRoute by remember { mutableStateOf<String?>(null) }
        var tonightOpen by remember { mutableStateOf(false) }
        val tonightState = androidx.compose.runtime.saveable.rememberSaveableStateHolder()
        LaunchedEffect(state.tab) { tonightOpen = false; communityRoute = null }
        var promptedCommunity by androidx.compose.runtime.saveable.rememberSaveable(state.session?.user?.id) { mutableStateOf(false) }
        LaunchedEffect(state.session?.user?.emailVerified, state.session?.user?.whatsappPrompted) {
            val person = state.session?.user
            if (person?.emailVerified == true && !person.whatsappVerified && !person.whatsappPrompted && !promptedCommunity) {
                promptedCommunity = true
                tonightOpen = false; organizerSlug = null; communityRoute = "whatsapp"
            }
        }
        val snackbar = remember { SnackbarHostState() }
        val imeVisible = WindowInsets.ime.getBottom(LocalDensity.current) > 0
        val accessibility = androidx.compose.ui.platform.LocalContext.current.getSystemService(android.content.Context.ACCESSIBILITY_SERVICE) as? android.view.accessibility.AccessibilityManager
        val lifecycle = androidx.lifecycle.compose.LocalLifecycleOwner.current.lifecycle
        var lastBannerImpression by remember { mutableStateOf<Long?>(null) }
        val eventList = !tonightOpen && organizerSlug == null && state.tab == AppTab.HOME && state.selected == null && state.selectedVenue == null
        var navigationRevealed by remember(state.tab, state.selected?.slug, state.selectedVenue?.slug, organizerSlug, tonightOpen) { mutableStateOf(false) }
        val threshold = with(LocalDensity.current) { 96.dp.toPx() }
        val navigationRequired = !tonightOpen && !eventList && state.selected == null && state.selectedVenue == null
        val revealNavigation = remember(state.tab, state.selected?.slug, state.selectedVenue?.slug, organizerSlug, tonightOpen, threshold) { object : NestedScrollConnection {
            val navigation = ScrollNavigation(threshold)
            override fun onPostScroll(consumed: Offset, available: Offset, source: NestedScrollSource): Offset {
                if (source == NestedScrollSource.UserInput && consumed.y != 0f) {
                    navigation.scroll(consumed.y)?.let { navigationRevealed = it }
                }
                return Offset.Zero
            }
        } }
        LaunchedEffect(lifecycle, state.session?.token) {
            if (state.session != null) lifecycle.repeatOnLifecycle(androidx.lifecycle.Lifecycle.State.STARTED) {
                while (true) {
                    viewModel.synchronizeAppearance()
                    viewModel.synchronizeSaved()
                    kotlinx.coroutines.delay(30_000)
                }
            }
        }
        val bannerAllowed = remember(eventList, state.session?.user?.id) {
            lastBannerImpression?.let { android.os.SystemClock.elapsedRealtime() - it >= 300_000 } ?: true
        }
        val bannerScreen = eventList && bannerAllowed && !imeVisible
        val excludedEvent = state.selected?.slug

        LaunchedEffect(bannerScreen, state.discoveryFilters, state.eventFilter, excludedEvent, state.selectedVenue?.slug, state.activeTag?.slug, state.session?.user?.id, lifecycle) {
            viewModel.clearSponsoredBanner()
            if (bannerScreen) lifecycle.repeatOnLifecycle(androidx.lifecycle.Lifecycle.State.STARTED) {
                try {
                    viewModel.refreshSponsoredBanner(excludedEvent)
                    kotlinx.coroutines.awaitCancellation()
                } finally { viewModel.clearSponsoredBanner() }
            }
        }
        LaunchedEffect(state.sponsoredBanner?.expiresAt) {
            state.sponsoredBanner?.let {
                val expiry = runCatching { java.time.Instant.parse(it.expiresAt).toEpochMilli() }.getOrDefault(0L)
                kotlinx.coroutines.delay((expiry - System.currentTimeMillis()).coerceAtLeast(0L))
                viewModel.clearSponsoredBanner()
            }
        }

        LaunchedEffect(state.message) {
            state.message?.let {
                snackbar.showSnackbar(it)
                viewModel.clearMessage()
            }
        }

        BackHandler(
            enabled = tonightOpen || organizerSlug != null || state.selected != null || state.selectedVenue != null || state.tab != AppTab.HOME,
            onBack = { if (tonightOpen && state.selected == null && state.selectedVenue == null && state.bookingDate == null) tonightOpen = false else if (organizerSlug != null) organizerSlug = null else viewModel.goBack() },
        )

        // Paint and reserve the system navigation area even when our tabs fade out.
        // Android 15 forces edge-to-edge: navigationBarColor alone cannot protect it.
        AppSafeArea {
        androidx.compose.foundation.layout.Column(Modifier.fillMaxSize()) {
        val headerEvent = state.selected?.takeIf { organizerSlug == null && state.bookingDate == null && state.selectedVenue == null }
        BrandHeader(compact = true, onBack = if (headerEvent != null) viewModel::goBack else null) {
            headerEvent?.occurrences?.firstOrNull()?.let { occurrence ->
                androidx.compose.material3.IconButton(onClick = { viewModel.toggleSaved(occurrence.occurrenceId) }) {
                    Icon(Icons.Outlined.BookmarkBorder, if (occurrence.occurrenceId in state.savedIds) "Rimuovi dai salvati" else "Salva questa data", tint = if (occurrence.occurrenceId in state.savedIds) Acid else Paper)
                }
            }
            androidx.compose.material3.IconButton(onClick = { tonightOpen = false; organizerSlug = null; communityRoute = "feed" }) {
                Icon(androidx.compose.material.icons.Icons.Outlined.People, androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.community_title), tint = Paper)
            }
            HeaderThemeSwitch(state.appearance, viewModel::toggleQuickAppearance)
        }
        if (it.fabiodalez.incitta.BuildConfig.DEBUG) {
            val context = androidx.compose.ui.platform.LocalContext.current
            androidx.compose.material3.TextButton(
                onClick = { it.fabiodalez.incitta.data.NetworkDiagnostics.show(context) },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("DEBUG RETE · APRI / CONDIVIDI LOG", color = Paper) }
        }
        Scaffold(
            modifier = Modifier.nestedScroll(revealNavigation),
            contentWindowInsets = WindowInsets(0, 0, 0, 0),
            containerColor = Ink,
            snackbarHost = { SnackbarHost(snackbar) },
            bottomBar = {
                androidx.compose.animation.AnimatedVisibility(
                    visible = !imeVisible && (navigationRequired || navigationRevealed || accessibility?.isTouchExplorationEnabled == true),
                    enter = androidx.compose.animation.fadeIn(androidx.compose.animation.core.tween(200)),
                    exit = androidx.compose.animation.fadeOut(androidx.compose.animation.core.tween(120)),
                ) {
                    /*
                     * Due impianti per la stessa barra.
                     *
                     * Nello scuro chiude la pagina: un divisore pieno da 2px e
                     * una fascia a tutta larghezza sul nero — e' il tabellone.
                     *
                     * Nel chiaro GALLEGGIA, come sul sito: rientra di dodici
                     * pixel per lato, ha gli angoli tondi e sta su fondo scuro
                     * anche a tema chiaro. E' quello che la fa leggere come un
                     * comando invece che come un piede di pagina, e il motivo
                     * per cui il colore qui e' scritto esplicito invece di
                     * venire dal tema: la barra e' scura in ENTRAMBI i temi, e
                     * chiederlo al tema chiaro darebbe una barra chiara su
                     * fondo chiaro.
                     */
                    val chiaro = isLightTheme
                    androidx.compose.foundation.layout.Column(
                        modifier = if (chiaro) Modifier.padding(horizontal = 12.dp, vertical = 8.dp) else Modifier,
                    ) {
                    if (!chiaro) HorizontalDivider(thickness = 2.dp, color = Paper)
                    BottomAppBar(
                        containerColor = if (chiaro) androidx.compose.ui.graphics.Color(0xFF262624) else Ink,
                        contentColor = if (chiaro) androidx.compose.ui.graphics.Color(0xFFFAF9F6) else Paper,
                        modifier = (if (chiaro) Modifier.clip(androidx.compose.foundation.shape.RoundedCornerShape(20.dp)) else Modifier).height(56.dp),
                        windowInsets = WindowInsets(0, 0, 0, 0),
                        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 0.dp),
                    ) {
                        NavItem(state.tab, AppTab.HOME, "Home", Icons.Outlined.Home, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                        NavItem(state.tab, AppTab.EVENTS, "Eventi", Icons.Outlined.Event, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                        NavItem(state.tab, AppTab.MAP, "Mappa", Icons.Outlined.Map, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                        NavItem(state.tab, AppTab.SEARCH, "Cerca", Icons.Outlined.Search, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                        NavItem(state.tab, AppTab.SAVED, "Salvati", Icons.Outlined.BookmarkBorder, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                        NavItem(if (state.tab == AppTab.TICKETS) AppTab.ACCOUNT else state.tab, AppTab.ACCOUNT, "Profilo", Icons.Outlined.AccountCircle, { tonightOpen = false; organizerSlug = null; viewModel.selectTab(it) })
                    }
                }
                }
            },
        ) { padding ->
            val selectedVenue = state.selectedVenue
            val selected = state.selected

            when {
                tonightOpen && selected == null && selectedVenue == null && state.bookingDate == null -> Box(Modifier.fillMaxSize().padding(padding)) {
                    tonightState.SaveableStateProvider("tonight-${state.session?.user?.id}") {
                    TonightWizard(state.session,
                        onBack = { tonightOpen = false },
                        onResults = { filters, summary -> tonightOpen = false; viewModel.applyDiscovery(filters, summary) })
                    }
                }
                organizerSlug != null -> Box(Modifier.fillMaxSize().padding(padding)) {
                    OrganizerScreen(organizerSlug!!, state.session, state.savedIds,
                        onLogin = { organizerSlug = null; viewModel.selectTab(AppTab.ACCOUNT) },
                        onBack = { organizerSlug = null }, onOrganizer = { organizerSlug = it },
                        onOpen = { organizerSlug = null; viewModel.open(it) }, onSave = viewModel::toggleSaved)
                }
                state.bookingDate != null -> ReservationScreen(state, padding, viewModel::reserve, viewModel::goBack) { state.bookingDate?.let(viewModel::startReservation) }
                communityRoute != null -> androidx.compose.runtime.key(state.session?.token) { CommunityScreen(state.session, padding, state.savedIds, communityRoute!!,
                    onBack = { communityRoute = null }, onLogin = { communityRoute = null; viewModel.selectTab(AppTab.ACCOUNT) },
                    onOpen = { communityRoute = null; viewModel.open(it) }, onSave = viewModel::toggleSaved, onProfileSaved = viewModel::refreshProfile) }
                selectedVenue != null -> Box(Modifier.fillMaxSize().padding(padding)) {
                    VenueDetailScreen(
                        venue = selectedVenue,
                        loadReviews = { page -> viewModel.venueReviews(requireNotNull(selectedVenue.slug), page) },
                        submitReview = { rating, body -> viewModel.submitVenueReview(requireNotNull(selectedVenue.slug), rating, body) },
                        deleteReview = { viewModel.deleteVenueReview(requireNotNull(selectedVenue.slug)) },
                        session = state.session,
                        onLogin = { viewModel.selectTab(AppTab.ACCOUNT) },
                        events = state.venueOccurrences,
                        pastEvents = state.venuePastOccurrences,
                        savedIds = state.savedIds,
                        onBack = viewModel::goBack,
                        onOpenEvent = viewModel::open,
                        onSave = viewModel::toggleSaved,
                    )
                }

                selected != null -> Box(Modifier.fillMaxSize().padding(padding)) {
                    CompleteEventDetailScreen(
                        detail = selected,
                        loadWeather = viewModel::eventWeather,
                        related = state.relatedOccurrences,
                        savedIds = state.savedIds,
                        onBack = viewModel::goBack,
                        onSave = viewModel::toggleSaved,
                        onVenue = viewModel::openVenue,
                        onTag = viewModel::browseTag,
                        onOpenEvent = viewModel::open,
                        onReserve = viewModel::startReservation,
                        onOrganizer = { organizerSlug = it },
                        comments = {
                            EventCommentsSection(
                                slug = selected.slug,
                                userId = state.session?.user?.id,
                                onLogin = { viewModel.loginForComments(selected.slug) },
                                load = { page, thread, replies -> viewModel.eventComments(selected.slug, page, thread, replies) },
                                submit = { body, parent -> viewModel.postComment(selected.slug, body, parent) },
                                react = { id, type -> viewModel.reactComment(selected.slug, id, type) },
                                delete = { id -> viewModel.deleteComment(selected.slug, id) },
                                resend = viewModel::resendCommentConfirmation,
                            )
                        },
                    )
                }

                else -> when (state.tab) {
                    AppTab.HOME -> EventsScreen(
                        state,
                        padding,
                        viewModel::open,
                        viewModel::toggleSaved,
                        { viewModel.refresh() },
                        viewModel::refresh,
                        { viewModel.selectTab(AppTab.CALENDAR) },
                        { viewModel.selectTab(AppTab.VENUES) },
                        inlineBanner = state.sponsoredBanner?.takeIf { bannerScreen && it.validAt() },
                        onOrganizers = { organizerSlug = "" },
                        onTonight = { tonightOpen = true },
                        onBannerImpression = { banner ->
                            lastBannerImpression = android.os.SystemClock.elapsedRealtime()
                            viewModel.bannerMetric(banner, false)
                        },
                        onBannerOpen = { banner ->
                            if (banner.validAt()) {
                                viewModel.bannerMetric(banner, true)
                                banner.occurrenceId?.let(viewModel::openOccurrence) ?: viewModel.openSlug(banner.eventSlug)
                            }
                        },
                    )
                    AppTab.MAP -> MapScreen(
                        state = state,
                        padding = padding,
                        onMarker = viewModel::previewMarkers,
                        onFilter = viewModel::applyMapFilter,
                        onSearchFilters = viewModel::updateMapFilters,
                        onOpen = viewModel::open,
                        onDismissPreview = viewModel::dismissMapPreview,
                    )
                    AppTab.EVENTS, AppTab.SEARCH -> SearchScreen(
                        state,
                        padding,
                        viewModel::search,
                        viewModel::open,
                        viewModel::toggleSaved,
                        viewModel::openVenue,
                        viewModel::browseTag,
                        viewModel::clearTagFilter,
                        onOrganizer = { organizerSlug = it },
                        onEditDiscovery = { tonightOpen = true },
                        onClearDiscovery = viewModel::clearDiscovery,
                        onFilters = viewModel::updateSearchFilters,
                        onLoadMore = viewModel::loadMoreEvents,
                    )
                    AppTab.SAVED -> SavedScreen(state, padding, viewModel::open, viewModel::toggleSaved, onPrivacy = { communityRoute = "save/$it" }) { viewModel.selectTab(AppTab.ACCOUNT) }
                    AppTab.ACCOUNT -> AccountScreen(
                        state = state,
                        padding = padding,
                        onLogin = viewModel::login,
                        onRegister = viewModel::register,
                        onMagic = viewModel::requestMagicLink,
                        onLogout = viewModel::logout,
                        onDeleteAccount = viewModel::deleteAccount,
                        onTickets = { viewModel.selectTab(AppTab.TICKETS) },
                        onClearAuthError = viewModel::clearAuthError,
                        onInterestsSaved = viewModel::interestsChanged,
                        onAppearance = viewModel::setAppearance,
                        onSaved = { viewModel.selectTab(AppTab.SAVED) },
                        onProfileSaved = viewModel::refreshProfile,
                        onCommunity = { communityRoute = "feed" },
                    )
                    AppTab.CALENDAR -> CalendarScreen(state, padding, viewModel::open, viewModel::toggleSaved) { viewModel.selectTab(AppTab.HOME) }
                    AppTab.VENUES -> VenuesScreen(state, padding, viewModel::openVenue) { viewModel.selectTab(AppTab.HOME) }
                    AppTab.TICKETS -> TicketsScreen(state, padding, viewModel::cancelBooking, viewModel::loadBookings, viewModel::resendBooking) { viewModel.selectTab(AppTab.ACCOUNT) }
                }
            }
        }
        }
        if (state.privacyConsent == null) ConsentOverlay(viewModel::setPrivacyConsent)
        }
    }
}

@Composable
internal fun HeaderThemeSwitch(appearance: String, onSwitch: () -> Unit) {
    androidx.compose.material3.IconButton(onClick = onSwitch) {
        Icon(
            if (appearance == "light") Icons.Outlined.DarkMode else Icons.Outlined.LightMode,
            contentDescription = if (appearance == "light") "Passa al tema scuro" else "Passa al tema chiaro",
            tint = Paper,
        )
    }
}

@Composable
internal fun AppSafeArea(content: @Composable () -> Unit) {
    Box(Modifier.fillMaxSize().background(Ink).windowInsetsPadding(WindowInsets.safeDrawing).clipToBounds()) {
        content()
    }
}

@Composable
private fun RowScope.NavItem(
    current: AppTab,
    tab: AppTab,
    label: String,
    icon: ImageVector,
    select: (AppTab) -> Unit,
) {
    /*
     * Nel chiaro la voce attiva e' una pastiglia terracotta con l'icona chiara
     * sopra, dentro una barra scura: gli stessi colori del sito, che qui non
     * possono venire dal tema perche' la barra e' scura mentre il tema e'
     * chiaro. Nello scuro resta il lime su nero.
     */
    val chiaro = isLightTheme
    NavigationBarItem(
        selected = current == tab,
        onClick = { select(tab) },
        icon = { Icon(icon, contentDescription = label, modifier = Modifier.size(24.dp)) },
        colors = if (chiaro) NavigationBarItemDefaults.colors(
            selectedIconColor = androidx.compose.ui.graphics.Color(0xFFFAF9F6),
            selectedTextColor = androidx.compose.ui.graphics.Color(0xFFFAF9F6),
            indicatorColor = androidx.compose.ui.graphics.Color(0xFFB54D23),
            unselectedIconColor = androidx.compose.ui.graphics.Color(0xFFC9C7C1),
            unselectedTextColor = androidx.compose.ui.graphics.Color(0xFFC9C7C1),
        ) else NavigationBarItemDefaults.colors(
            selectedIconColor = Ink,
            selectedTextColor = Acid,
            indicatorColor = Acid,
            unselectedIconColor = Muted,
            unselectedTextColor = Muted,
        ),
    )
}
