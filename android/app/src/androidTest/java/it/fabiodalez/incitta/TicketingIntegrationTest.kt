package it.fabiodalez.incitta

import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.zxing.BinaryBitmap
import com.google.zxing.MultiFormatReader
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.common.HybridBinarizer
import it.fabiodalez.incitta.data.AppRepository
import it.fabiodalez.incitta.data.ReserveBody
import it.fabiodalez.incitta.ui.ticketQr
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class TicketingIntegrationTest {
    @Test
    fun generatedQrCanBeDecodedExactly() {
        val payload = "aB1234".repeat(10) + "5678"
        val bitmap = ticketQr(payload)
        val pixels = IntArray(bitmap.width * bitmap.height)
        bitmap.getPixels(pixels, 0, bitmap.width, 0, 0, bitmap.width, bitmap.height)
        val source = RGBLuminanceSource(bitmap.width, bitmap.height, pixels)
        assertEquals(payload, MultiFormatReader().decode(BinaryBitmap(HybridBinarizer(source))).text)
    }

    @Test
    fun localApiBookingRetryCancellationAndAccountIsolation() = runBlocking {
        assumeTrue(BuildConfig.API_BASE_URL.startsWith("http://10.0.2.2:"))
        val repo = AppRepository(ApplicationProvider.getApplicationContext())
        repo.login("biglietti@example.test", "DemoTicket-2026!")
        try {
            val existing = repo.bookings().first { it.title == "Padova raccontata: storie tra piazze e portici" }
            assertTrue(repo.bookingAvailability(existing.occurrenceId).open)
            val body = ReserveBody(listOf(it.fabiodalez.incitta.data.AttendeeName("Test", "Android")), UUID.randomUUID().toString(), false, true, mapOf("first_name" to "Test", "last_name" to "Android"))
            val booking = repo.reserve(existing.occurrenceId, body)
            try {
                assertEquals("confirmed", booking.status)
                assertEquals(64, booking.tickets.first().qrPayload?.length)
                assertEquals(booking.id, repo.reserve(existing.occurrenceId, body).id)
            } finally {
                val cancelled = repo.cancelBooking(booking.id, null)
                assertEquals("cancelled", cancelled.status)
                assertNull(cancelled.tickets.first().qrPayload)
            }
            repo.logout()
            repo.login("attesa-ticket@example.test", "DemoTicket-2026!")
            assertTrue(repo.bookings().none { it.id == existing.id })
        } finally {
            repo.logout()
        }
    }
}
