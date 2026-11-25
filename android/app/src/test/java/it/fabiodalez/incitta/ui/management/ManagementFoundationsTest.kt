package it.fabiodalez.incitta.ui.management

import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.ApiProblem
import it.fabiodalez.incitta.data.management.ManagedOrganizer
import it.fabiodalez.incitta.data.management.ManagedVenue
import it.fabiodalez.incitta.data.management.ManagementLanding
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.cityIso
import it.fabiodalez.incitta.ui.management.common.cityLocalDateTime
import it.fabiodalez.incitta.ui.management.common.pickerDate
import it.fabiodalez.incitta.ui.management.common.pickerMillis
import it.fabiodalez.incitta.ui.management.collaborators.CollaboratorsRoute
import it.fabiodalez.incitta.ui.management.ticketing.TicketingDatesRoute
import java.io.IOException
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
import org.junit.Assert.*
import org.junit.Test

/* Le parti pure dell'area di gestione: menu delle aree, atterraggio salvato, errori dei moduli, date nel fuso della città. */
class ManagementFoundationsTest {
    private fun venue(vararg permissions: String) = ManagedVenue(id = 1, role = "owner", permissions = permissions.associateWith { true }).toSubject()

    @Test fun onlyBuiltAndAllowedAreasAppear() {
        /* La regola, qualunque siano le aree già fatte: compaiono quelle con una schermata e permesse, nell'ordine del menu. */
        val subjects = listOf(
            venue("ticketing_manage", "events_create", "profile_update", "comments_moderate", "reviews_reply"),
            venue("events_create"),
            venue(),
            ManagedOrganizer(id = 3, permissions = mapOf("ticketing_manage" to true)).toSubject(),
        )
        subjects.forEach { subject ->
            listOf(false, true).forEach { checkin ->
                assertEquals(ManagementArea.entries.filter { it.implemented && it.allowedFor(subject, checkin) }, availableAreas(subject, hasCheckinDates = checkin))
            }
        }
        /* «Collaboratori e accesso» c'è per ogni persona del locale (dentro c'è almeno «Il tuo accesso»), mai per un'organizzazione. */
        assertTrue(ManagementArea.Collaborators in availableAreas(venue(), hasCheckinDates = false))
        assertFalse(ManagementArea.Collaborators in availableAreas(subjects.last(), hasCheckinDates = false))
        /* Chi controlla gli ingressi di qualche data vede la biglietteria anche senza gestirla. */
        assertTrue(ManagementArea.Ticketing in availableAreas(venue(), hasCheckinDates = true))
        assertFalse(ManagementArea.Ticketing in availableAreas(venue(), hasCheckinDates = false))
        assertFalse(ManagementArea.Profile in availableAreas(venue("events_create"), hasCheckinDates = false))
    }

    @Test fun everyAreaOfThePlanExistsAndTheBuiltOnesHaveTheirEntry() {
        assertEquals(13, ManagementArea.entries.size)
        /* Le aree si accendono con i loro pacchetti: qui si controllano quelle già fatte. */
        assertSame(TicketingDatesRoute, ManagementArea.Ticketing.entry)
        assertTrue(ManagementArea.Profile.implemented)
        assertTrue(ManagementArea.Events.implemented)
        assertEquals(it.fabiodalez.incitta.ui.management.feedback.FeedbackRoute(), ManagementArea.Feedback.entry)
        assertSame(CollaboratorsRoute, ManagementArea.Collaborators.entry)
        assertSame(it.fabiodalez.incitta.ui.management.calendarimport.CalendarImportRoute, ManagementArea.Import.entry)
        assertEquals(it.fabiodalez.incitta.ui.management.registration.ApplicationsRoute(), ManagementArea.Registration.entry)
        /* Le date stanno dentro l'evento, come nei pannelli: si aprono dalla scheda, senza una voce nel menu. */
        assertNull(ManagementArea.Dates.entry)
        assertEquals(it.fabiodalez.incitta.ui.management.analytics.AnalyticsRoute(), ManagementArea.Analytics.entry)
        assertEquals(it.fabiodalez.incitta.ui.management.sponsorships.SponsorshipsRoute(), ManagementArea.Sponsorships.entry)
        assertEquals(it.fabiodalez.incitta.ui.management.social.SocialStudioRoute(), ManagementArea.Social.entry)
        assertSame(it.fabiodalez.incitta.ui.management.home.ManagementHomeRoute, ManagementArea.Overview.entry)
        assertEquals(it.fabiodalez.incitta.ui.management.notifications.ManagementNoticesRoute(), ManagementArea.Notifications.entry)
    }

    @Test fun permissionsDecideTheFutureAreasToo() {
        assertTrue(ManagementArea.Profile.allowedFor(venue("profile_update"), false))
        assertFalse(ManagementArea.Profile.allowedFor(venue(), false))
        assertTrue(ManagementArea.Feedback.allowedFor(venue("reviews_reply"), false))
        assertFalse(ManagementArea.Import.allowedFor(ManagedOrganizer(id = 3, permissions = mapOf("import_manage" to true)).toSubject(), false))
        assertTrue(ManagementArea.Import.allowedFor(venue("import_manage"), false))
    }

    @Test fun theLandingSurvivesAsText() {
        listOf(ManagementLanding.TicketingDates, ManagementLanding.Subject("venue:12"), ManagementLanding.Subject("organizer:3")).forEach {
            assertEquals(it, decodeLanding(encodeLanding(it)))
        }
        assertNull(decodeLanding("altro"))
    }

    @Test fun serverFieldErrorsGoUnderTheirFieldAndTheRestOnTop() {
        val error = ApiException(422, ApiProblem("Dati non validi", "VALIDATION_FAILED", mapOf(
            "email" to listOf("Indirizzo non valido."),
            "practical_details.entrance_notes" to listOf("Troppo lungo."),
            "segreto" to listOf("Campo che il modulo non mostra."),
        )))
        val errors = FormErrors.from(error, listOf("email", "practical_details"))
        assertEquals("Indirizzo non valido.", errors.field("email"))
        assertEquals("Troppo lungo.", errors.field("practical_details"))
        assertEquals("Campo che il modulo non mostra.", errors.general)
        assertNull(errors.field("phone"))
    }

    @Test fun errorsWithoutFieldsStayOnTopWithTheUsualMessage() {
        val onlyFields = FormErrors.from(ApiException(422, ApiProblem(fields = mapOf("email" to listOf("Obbligatoria.")))), listOf("email"))
        assertNull(onlyFields.general)
        assertEquals("Obbligatoria.", onlyFields.field("email"))
        assertEquals("Non riesco a raggiungere il server. Controlla Wi-Fi o rete mobile e riprova.", FormErrors.from(IOException(), listOf("email")).general)
        assertEquals("La sessione è scaduta. Esci dal profilo e accedi di nuovo.", FormErrors.from(ApiException(401, null), listOf("email")).general)
        val unknown = FormErrors.from(ApiException(422, ApiProblem(fields = mapOf("nome" to listOf("Obbligatorio.")))), emptyList())
        assertEquals("Obbligatorio.", unknown.general)
        assertTrue(unknown.fields.isEmpty())
    }

    @Test fun datesAreReadAndWrittenInTheCityZoneNotThePhones() {
        val rome = ZoneId.of("Europe/Rome")
        assertEquals(LocalDateTime.of(2026, 12, 5, 21, 0), cityLocalDateTime("2026-12-05T20:00:00Z", rome))
        assertEquals(LocalDateTime.of(2026, 12, 5, 21, 0), cityLocalDateTime("2026-12-05T15:00:00-05:00", rome))
        assertEquals("2026-12-05T21:00:00+01:00", cityIso(LocalDateTime.of(2026, 12, 5, 21, 0), rome))
        assertEquals("2026-07-05T21:00:00+02:00", cityIso(LocalDateTime.of(2026, 7, 5, 21, 0), rome))
        /* Ora doppia di fine ottobre: la prima delle due. */
        assertEquals("2026-10-25T02:30:00+02:00", cityIso(LocalDateTime.of(2026, 10, 25, 2, 30), rome))
        assertNull(cityLocalDateTime("", rome))
        assertNull(cityLocalDateTime("domani sera", rome))
    }

    @Test fun thePickerDayIsTheSameDayEverywhere() {
        val day = LocalDate.of(2026, 3, 29)
        assertEquals(day, pickerDate(pickerMillis(day)))
    }
}
