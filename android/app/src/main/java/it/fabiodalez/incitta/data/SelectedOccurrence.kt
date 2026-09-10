package it.fabiodalez.incitta.data

/** Keep date-specific actions and practical information aligned with the tapped date. */
internal fun EventDetail.selectOccurrence(occurrence: Occurrence, actualVenue: Venue?): EventDetail {
    require(occurrence.eventId == id) { "Occurrence does not belong to event" }
    val selected = occurrences.find { it.occurrenceId == occurrence.occurrenceId } ?: occurrence
    return copy(
        venue = actualVenue,
        occurrences = listOf(selected),
        url = selected.dateUrl ?: occurrence.dateUrl ?: url,
        contentDetails = selected.contentDetails ?: contentDetails,
    )
}
