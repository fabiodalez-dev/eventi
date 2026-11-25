package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.Poster
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/*
 * Gli eventi fra cui scegliere nel foglio «Scegli un evento»
 * (`ui/management/common/EventPickerSheet`): la stessa voce del selettore
 * dei pannelli, con locandina, titolo, la prossima data (o l'ultima, se sono
 * tutte passate), quante altre ne restano e il locale; divise in «Prossimi»
 * e «Passati». La lettura la fa il server (`App\Queries\EventChoices`), con la
 * query degli eventi che chi guarda può vedere in quella pagina: le
 * statistiche la chiedono a `GET S/analytics/events`, sponsorizzazioni e
 * grafiche social la chiederanno al loro endpoint con la stessa forma.
 */
@Serializable
internal data class EventChoice(
    val id: Long = 0,
    val title: String = "",
    val poster: Poster? = null,
    /* ISO in UTC; si mostra nel fuso della città dell'evento (`timezone`). */
    @SerialName("starts_at") val startsAt: String? = null,
    val timezone: String? = null,
    val past: Boolean = false,
    val more: Int = 0,
    val venue: String? = null,
)

@Serializable
internal data class EventChoiceGroups(
    val upcoming: List<EventChoice> = emptyList(),
    val past: List<EventChoice> = emptyList(),
) {
    val isEmpty: Boolean get() = upcoming.isEmpty() && past.isEmpty()
}
