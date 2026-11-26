package it.fabiodalez.incitta.ui.management.notifications

import androidx.navigation.NavHostController
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.data.management.ManagementRepository
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.PanelPage
import it.fabiodalez.incitta.ui.management.analytics.ShareAnalyticsRoute
import it.fabiodalez.incitta.ui.management.analytics.StatisticsRoute
import it.fabiodalez.incitta.ui.management.analytics.SubjectAnalyticsRoute
import it.fabiodalez.incitta.ui.management.calendarimport.CalendarImportRoute
import it.fabiodalez.incitta.ui.management.collaborators.CollaboratorsRoute
import it.fabiodalez.incitta.ui.management.collaborators.MyAccessRoute
import it.fabiodalez.incitta.ui.management.events.EventEditorRoute
import it.fabiodalez.incitta.ui.management.events.EventOverviewRoute
import it.fabiodalez.incitta.ui.management.events.EventsListRoute
import it.fabiodalez.incitta.ui.management.feedback.CommentsModerationRoute
import it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute
import it.fabiodalez.incitta.ui.management.profile.ProfileRoute
import it.fabiodalez.incitta.ui.management.social.SocialStudioRoute
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipAnalyticsRoute
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
import kotlinx.coroutines.CancellationException

/*
 * La chiusura del giro (P12): una pagina del pannello o un avviso a chi
 * gestisce diventa la schermata nativa del soggetto giusto.
 *
 * Il soggetto si cerca fra quelli che la persona gestisce adesso (l'elenco in
 * memoria, o riletto una volta): se non c'è più, o la gestione nell'app è
 * spenta, si apre l'indirizzo del sito come prima (gli avvisi non ne hanno
 * uno, e allora si resta dove si è). Lo slug di un evento del pannello si
 * traduce nel suo id con il server.
 */

/** Che cosa fare di una `ManagementDestination.Panel`. */
internal sealed interface PanelResolution {
    /** La schermata nativa: `route` sopra la pagina del soggetto, o la pagina stessa se `null`. */
    data class Native(val subject: ManagementSubject, val route: Any?) : PanelResolution
    /** Il sito, con l'indirizzo della pagina. */
    data class Web(val url: String) : PanelResolution
    /** Niente da aprire (un avviso di un soggetto non più gestito). */
    data object Nothing : PanelResolution
}

/** Decide dove porta la destinazione. `enabled` è falso quando la gestione nell'app è spenta (si atterra sull'elenco delle date). */
internal suspend fun resolvePanel(repository: ManagementRepository, destination: ManagementDestination.Panel, enabled: Boolean): PanelResolution {
    val fallback: PanelResolution = destination.webUrl?.let(PanelResolution::Web) ?: PanelResolution.Nothing
    if (!enabled) return fallback
    val subject = try {
        repository.subjects.value?.all()?.firstOrNull(destination.subject::matches)
            ?: repository.loadSubjects().all().firstOrNull(destination.subject::matches)
    } catch (e: CancellationException) { throw e } catch (_: Exception) { null } ?: return fallback
    val key = subject.key
    val route: Any? = when (val page = destination.page) {
        PanelPage.Home -> null
        PanelPage.Events -> EventsListRoute()
        PanelPage.NewEvent -> EventEditorRoute()
        is PanelPage.Event -> {
            val id = page.id ?: page.slug?.let { slug ->
                try { repository.notices?.eventBySlug(subject, slug)?.id?.takeIf { it > 0 } }
                catch (e: CancellationException) { throw e } catch (_: Exception) { null }
            } ?: return fallback
            if (page.edit) EventEditorRoute(id) else EventOverviewRoute(id)
        }
        is PanelPage.Comments -> CommentsModerationRoute(subject = key, event = page.eventId)
        PanelPage.Profile -> ProfileRoute
        PanelPage.Collaborators -> CollaboratorsRoute
        PanelPage.MyAccess -> MyAccessRoute
        PanelPage.CalendarImport -> CalendarImportRoute
        PanelPage.Statistics -> StatisticsRoute(subject = key)
        PanelPage.ShareAnalytics -> ShareAnalyticsRoute(subject = key)
        is PanelPage.SubjectAnalytics -> SubjectAnalyticsRoute(subject = key, type = page.type, id = page.id)
        PanelPage.SponsorshipAnalytics -> SponsorshipAnalyticsRoute(subject = key)
        is PanelPage.CampaignAnalytics -> SponsorshipAnalyticsRoute(subject = key, campaign = page.campaign)
        PanelPage.Sponsorships -> SponsorshipsRoute(subject = key)
        is PanelPage.Social -> SocialStudioRoute(subject = key, event = page.eventId ?: 0)
    }
    return PanelResolution.Native(subject, route)
}

/**
 * Porta alla schermata. Se il soggetto è un altro da quello aperto, la pila
 * ricomincia dalla sua pagina: le schermate di prima parlano di un locale che
 * non è più quello scelto, e la pagina del soggetto legge la scelta solo
 * quando nasce. Con lo stesso soggetto si torna alla sua pagina e si apre la
 * schermata sopra, così «indietro» porta sempre al riepilogo del soggetto.
 */
internal fun openNative(navController: NavHostController, repository: ManagementRepository, resolution: PanelResolution.Native) {
    val sameSubject = repository.selectedSubjectKey == resolution.subject.key
    repository.selectedSubjectKey = resolution.subject.key
    if (!sameSubject || !navController.popBackStack(ManagementHomeRoute, inclusive = false)) {
        navController.navigate(ManagementHomeRoute) { popUpTo(navController.graph.id) { inclusive = true } }
    }
    resolution.route?.let { navController.navigate(it) }
}
