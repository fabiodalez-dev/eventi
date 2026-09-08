package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.ime
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.Event
import androidx.compose.material.icons.outlined.Map
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.AppTab
import it.fabiodalez.incitta.MainViewModel

@Composable
fun InCittaApp(viewModel: MainViewModel) {
    InCittaTheme {
        val state by viewModel.state.collectAsStateWithLifecycle()
        val snackbar = remember { SnackbarHostState() }
        val imeVisible = WindowInsets.ime.getBottom(LocalDensity.current) > 0

        LaunchedEffect(state.message) {
            state.message?.let {
                snackbar.showSnackbar(it)
                viewModel.clearMessage()
            }
        }

        BackHandler(
            enabled = state.selected != null || state.selectedVenue != null || state.tab != AppTab.EVENTS,
            onBack = viewModel::goBack,
        )

        Scaffold(
            containerColor = Ink,
            snackbarHost = { SnackbarHost(snackbar) },
            bottomBar = {
                if (!imeVisible) androidx.compose.foundation.layout.Column {
                    HorizontalDivider(thickness = 2.dp, color = Paper)
                    BottomAppBar(
                        containerColor = Ink,
                        contentColor = Paper,
                        modifier = Modifier.navigationBarsPadding(),
                    ) {
                        NavItem(state.tab, AppTab.EVENTS, "Eventi", Icons.Outlined.Event, viewModel::selectTab)
                        NavItem(state.tab, AppTab.MAP, "Mappa", Icons.Outlined.Map, viewModel::selectTab)
                        NavItem(state.tab, AppTab.SEARCH, "Cerca", Icons.Outlined.Search, viewModel::selectTab)
                        NavItem(state.tab, AppTab.SAVED, "Salvati", Icons.Outlined.BookmarkBorder, viewModel::selectTab)
                        NavItem(if (state.tab == AppTab.TICKETS) AppTab.ACCOUNT else state.tab, AppTab.ACCOUNT, "Profilo", Icons.Outlined.AccountCircle, viewModel::selectTab)
                    }
                }
            },
        ) { padding ->
            val selectedVenue = state.selectedVenue
            val selected = state.selected

            when {
                state.bookingDate != null -> ReservationScreen(state, padding, viewModel::reserve, viewModel::goBack) { state.bookingDate?.let(viewModel::startReservation) }
                selectedVenue != null -> Box(Modifier.fillMaxSize().padding(padding)) {
                    VenueDetailScreen(
                        venue = selectedVenue,
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
                        related = state.relatedOccurrences,
                        savedIds = state.savedIds,
                        onBack = viewModel::goBack,
                        onSave = viewModel::toggleSaved,
                        onVenue = viewModel::openVenue,
                        onTag = viewModel::browseTag,
                        onOpenEvent = viewModel::open,
                        onReserve = viewModel::startReservation,
                    )
                }

                else -> when (state.tab) {
                    AppTab.EVENTS -> EventsScreen(
                        state,
                        padding,
                        viewModel::open,
                        viewModel::toggleSaved,
                        { viewModel.refresh() },
                        viewModel::refresh,
                        { viewModel.selectTab(AppTab.CALENDAR) },
                        { viewModel.selectTab(AppTab.VENUES) },
                    )
                    AppTab.MAP -> MapScreen(
                        state = state,
                        padding = padding,
                        onMarker = viewModel::previewMarkers,
                        onFilter = viewModel::applyMapFilter,
                        onOpen = viewModel::open,
                        onDismissPreview = viewModel::dismissMapPreview,
                    )
                    AppTab.SEARCH -> SearchScreen(
                        state,
                        padding,
                        viewModel::search,
                        viewModel::open,
                        viewModel::toggleSaved,
                        viewModel::openVenue,
                        viewModel::browseTag,
                        viewModel::clearTagFilter,
                    )
                    AppTab.SAVED -> SavedScreen(state, padding, viewModel::open, viewModel::toggleSaved)
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
                    )
                    AppTab.CALENDAR -> CalendarScreen(state, padding, viewModel::open, viewModel::toggleSaved) { viewModel.selectTab(AppTab.EVENTS) }
                    AppTab.VENUES -> VenuesScreen(state, padding, viewModel::openVenue) { viewModel.selectTab(AppTab.EVENTS) }
                    AppTab.TICKETS -> TicketsScreen(state, padding, viewModel::cancelBooking, viewModel::loadBookings, viewModel::resendBooking) { viewModel.selectTab(AppTab.ACCOUNT) }
                }
            }
        }
        if (state.privacyConsent == null) ConsentOverlay(viewModel::setPrivacyConsent)
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
    NavigationBarItem(
        selected = current == tab,
        onClick = { select(tab) },
        icon = { Icon(icon, contentDescription = null, modifier = Modifier.size(22.dp)) },
        label = { Text(label.uppercase()) },
        colors = NavigationBarItemDefaults.colors(
            selectedIconColor = Ink,
            selectedTextColor = Acid,
            indicatorColor = Acid,
            unselectedIconColor = Muted,
            unselectedTextColor = Muted,
        ),
    )
}
