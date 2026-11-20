package it.fabiodalez.incitta.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.AdmissionTicket
import it.fabiodalez.incitta.data.Booking
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Il biglietto come sul sito (`x-ticket-pass`): fascia con «Biglietto 1 di 2» e
 * stato, locandina e titolo, data in grande, luogo, intestatario; sotto una
 * perforazione tratteggiata, la matrice col numero e, quando chi lo possiede lo
 * apre, il QR su fondo bianco in entrambi i temi.
 */
@Composable
internal fun TicketPass(booking: Booking, ticket: AdmissionTicket, position: Int, total: Int, status: String, showQr: Boolean, qrDescription: String) {
    val valid = ticket.status == "valid"
    val starts = remember(booking.startsAt) { booking.startsAt?.let { ticketPassTime(it) } }
    Column(Modifier.fillMaxWidth().border(2.dp, Paper, ControlShape)) {
        Row(
            Modifier.fillMaxWidth().background(if (valid) Paper else MaterialTheme.colorScheme.surfaceVariant).padding(horizontal = 14.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            val band = if (total > 1) stringResource(R.string.ticket_pass_band_of, position, total) else stringResource(R.string.ticket_pass_band)
            Text(band.uppercase(), color = if (valid) Ink else Muted, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold, letterSpacing = 1.2.sp)
            Text(status.uppercase(), color = if (valid) Ink else Muted, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold)
        }
        Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            PosterImage(booking.poster?.thumb ?: booking.poster?.card ?: booking.poster?.full, Modifier.width(64.dp).aspectRatio(3f / 4f))
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(booking.title.uppercase(), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
                booking.organizer?.takeIf(String::isNotBlank)?.let { Text(stringResource(R.string.ticket_pass_organizer, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
        }
        HorizontalDivider(thickness = 2.dp, color = Paper)
        starts?.let { date ->
            Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1.7f)) {
                    PassLabel(stringResource(R.string.ticket_pass_date))
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Text(date.dayOfMonth.toString(), fontSize = 38.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 38.sp, softWrap = false)
                        Column {
                            Text(date.format(DateTimeFormatter.ofPattern("MMMM", Locale.ITALIAN)).uppercase(), fontWeight = FontWeight.Bold, style = MaterialTheme.typography.bodyMedium)
                            Text(date.format(DateTimeFormatter.ofPattern("EEEE yyyy", Locale.ITALIAN)), color = Muted, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                Column(Modifier.weight(1f)) {
                    PassLabel(stringResource(R.string.ticket_pass_time))
                    Text(date.format(DateTimeFormatter.ofPattern("HH:mm")), fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            HorizontalDivider(thickness = 1.dp, color = Rule)
        }
        booking.venue?.let { venue ->
            Column(Modifier.fillMaxWidth().padding(14.dp)) {
                PassLabel(stringResource(R.string.ticket_pass_place))
                Text(venue, fontWeight = FontWeight.Bold)
                booking.address?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            }
            HorizontalDivider(thickness = 1.dp, color = Rule)
        }
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            PassLabel(stringResource(R.string.ticket_pass_holder))
            Text(ticket.attendeeName, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
        }
        Perforation()
        Column(
            Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.surfaceVariant).padding(16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            if (showQr && ticket.qrPayload != null) {
                val bitmap = remember(ticket.qrPayload) { ticketQr(ticket.qrPayload) }
                Box(Modifier.background(Color.White).padding(10.dp)) {
                    Image(bitmap.asImageBitmap(), qrDescription, modifier = Modifier.widthIn(max = 260.dp).fillMaxWidth().aspectRatio(1f))
                }
            }
            PassLabel(stringResource(R.string.ticket_pass_number))
            Text(ticket.id.toString().padStart(6, '0'), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 2.sp)
            Text(stringResource(R.string.ticket_pass_booking, booking.id), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun PassLabel(text: String) {
    Text(text.uppercase(), color = Muted, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.ExtraBold, letterSpacing = 1.2.sp, modifier = Modifier.padding(bottom = 2.dp))
}

/** La perforazione fra corpo e matrice: un filo tratteggiato da bordo a bordo. */
@Composable
private fun Perforation() {
    val color = Paper
    Canvas(Modifier.fillMaxWidth().height(2.dp)) {
        drawLine(color, Offset(0f, size.height / 2), Offset(size.width, size.height / 2), strokeWidth = size.height, pathEffect = PathEffect.dashPathEffect(floatArrayOf(14f, 10f)))
    }
}

private fun ticketPassTime(value: String): ZonedDateTime? = runCatching {
    OffsetDateTime.parse(value).atZoneSameInstant(ZoneId.of("Europe/Rome"))
}.getOrNull()
