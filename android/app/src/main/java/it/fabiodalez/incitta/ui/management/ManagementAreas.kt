package it.fabiodalez.incitta.ui.management

import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.management.analytics.AnalyticsRoute
import it.fabiodalez.incitta.ui.management.feedback.FeedbackRoute
import it.fabiodalez.incitta.ui.management.collaborators.CollaboratorsRoute
import it.fabiodalez.incitta.ui.management.profile.ProfileRoute
import it.fabiodalez.incitta.ui.management.events.EventsListRoute
import it.fabiodalez.incitta.ui.management.registration.ApplicationsRoute
import it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDatesRoute
import it.fabiodalez.incitta.ui.management.calendarimport.CalendarImportRoute
import it.fabiodalez.incitta.ui.management.social.SocialStudioRoute

/**
 * Le aree della gestione, una per pacchetto del piano. `entry` è la rotta
 * della prima schermata dell'area: finché manca l'area non compare nel menu.
 * Il pacchetto che la porta tocca solo la sua riga e il suo grafo.
 */
internal enum class ManagementArea(val title: Int, val help: Int, val entry: Any? = null) {
    Overview(R.string.management_area_overview, R.string.management_area_overview_help),
    Events(R.string.management_area_events, R.string.management_area_events_help, EventsListRoute),
    Dates(R.string.management_area_dates, R.string.management_area_dates_help),
    Ticketing(R.string.management_area_ticketing, R.string.management_area_ticketing_help, TicketingDatesRoute),
    Profile(R.string.management_area_profile, R.string.management_area_profile_help, ProfileRoute),
    Analytics(R.string.management_area_analytics, R.string.management_area_analytics_help, AnalyticsRoute()),
    Sponsorships(R.string.management_area_sponsorships, R.string.management_area_sponsorships_help, SponsorshipsRoute()),
    Feedback(R.string.management_area_feedback, R.string.management_area_feedback_help, FeedbackRoute()),
    Collaborators(R.string.management_area_collaborators, R.string.management_area_collaborators_help, CollaboratorsRoute),
    Import(R.string.management_area_import, R.string.management_area_import_help, CalendarImportRoute),
    Social(R.string.management_area_social, R.string.management_area_social_help, SocialStudioRoute()),
    Notifications(R.string.management_area_notifications, R.string.management_area_notifications_help),
    Registration(R.string.management_area_registration, R.string.management_area_registration_help, ApplicationsRoute());

    val implemented: Boolean get() = entry != null

    /** Se il soggetto, secondo i suoi `permissions`, apre quest'area. */
    fun allowedFor(subject: ManagementSubject, hasCheckinDates: Boolean): Boolean {
        val can = subject.permissions
        return when (this) {
            Overview, Events, Dates -> true
            /* La biglietteria è delle date: la vede chi la gestisce e chi controlla gli ingressi di qualche data. */
            Ticketing -> can.ticketingManage || hasCheckinDates
            Profile -> can.profileUpdate
            Analytics -> can.analyticsView
            Sponsorships -> can.sponsorshipsView
            Feedback -> can.commentsModerate || can.reviewsReply
            /* Il locale sì, l'organizzazione no (non ha collaboratori): il referente vi gestisce le persone, tutti «Il tuo accesso». */
            Collaborators -> subject.kind == SubjectKind.Venue
            Import -> can.importManage && subject.kind == SubjectKind.Venue
            Social -> can.socialUse
            Notifications -> true
            /* Registrare un altro locale o un'organizzazione, e seguire le richieste: è della persona, quindi di tutti. */
            Registration -> true
        }
    }
}

/** Le aree da mostrare nel menu del soggetto: solo quelle fatte e permesse, nell'ordine del pannello. */
internal fun availableAreas(subject: ManagementSubject, hasCheckinDates: Boolean): List<ManagementArea> =
    ManagementArea.entries.filter { it.implemented && it.allowedFor(subject, hasCheckinDates) }
