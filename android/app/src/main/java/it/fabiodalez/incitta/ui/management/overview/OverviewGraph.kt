package it.fabiodalez.incitta.ui.management.overview

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.BuildConfig
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.managementCounts
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementArea
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.availableAreas
import it.fabiodalez.incitta.ui.management.webOnlyAreas
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import kotlinx.serialization.Serializable

/**
 * P11 — Riepilogo: oggi, prossimi eventi, ultime visite e scorciatoie (W-DB-*).
 *
 * Il riepilogo è la pagina del soggetto (`ManagementHomeRoute`, in
 * `home/`): è lì che si atterra, come nella prima pagina dei pannelli, e lì
 * stanno il selettore e i riquadri (`OverviewContent`). Quest'area aggiunge
 * «Tutte le aree», il menu delle aree che prima era la pagina del soggetto e
 * che resta raggiungibile dal fondo del riepilogo.
 */
@Serializable internal data object ManagementAreasRoute

internal fun NavGraphBuilder.overviewGraph(context: ManagementGraphContext) {
    composable<ManagementAreasRoute> { ManagementAreasScreen(context) }
}

/** Tutte le aree fatte e permesse del soggetto scelto, nell'ordine del pannello; il riepilogo riporta indietro. */
@Composable
private fun ManagementAreasScreen(context: ManagementGraphContext) {
    val subjects by context.repository.subjects.collectAsStateWithLifecycle()
    val subject = subjects?.let { it.find(context.repository.selectedSubjectKey) ?: it.all().firstOrNull() }
    val uriHandler = LocalUriHandler.current
    val hasCheckinDates = (context.session.user.managementCounts()?.checkin ?: 0) > 0
    ManagementScaffold(
        title = stringResource(R.string.overview_all_areas),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
    ) {
        val current = subject ?: run { Text(stringResource(R.string.management_empty), color = Muted); return@ManagementScaffold }
        val areas = availableAreas(current, hasCheckinDates)
        Text(stringResource(R.string.management_home_areas), style = MaterialTheme.typography.titleLarge)
        Column {
            areas.forEach { area ->
                ManagementRow(stringResource(area.title), stringResource(area.help), onClick = {
                    /* Il riepilogo è già sotto questa pagina: ci si torna invece di aprirne un altro. */
                    if (area == ManagementArea.Overview) {
                        if (!context.navController.popBackStack<ManagementHomeRoute>(inclusive = false)) context.navController.navigate(ManagementHomeRoute)
                    } else {
                        area.entry?.let { context.navController.navigate(it) }
                    }
                })
            }
        }
        if (areas.isEmpty()) Text(stringResource(R.string.management_home_no_areas), color = Muted)
        /*
         * Il pannello web solo per le aree permesse che nell'app non ci sono
         * ancora, nominate (P12). Con tutte le aree fatte l'elenco è vuoto e
         * il pulsante non compare.
         */
        val webOnly = webOnlyAreas(current, hasCheckinDates)
        if (webOnly.isNotEmpty()) {
            Spacer(Modifier.height(4.dp))
            Text(stringResource(R.string.management_home_more_web, webOnly.map { stringResource(it.title) }.joinToString(", ")), color = Muted)
            OutlinedButton(
                onClick = { uriHandler.openUri(BuildConfig.API_BASE_URL.substringBefore("/api/") + "/" + current.webPath) },
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text(stringResource(R.string.management_home_open_web)) }
        }
    }
}
