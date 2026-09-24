package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.AppUiState
import it.fabiodalez.incitta.offersWalletPass
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

class WalletPassTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    private fun ticket(status: String = "valid", qr: String? = "ABC123") =
        AdmissionTicket(id = 9, attendeeName = "Mario Rossi", status = status, qrPayload = qr)

    @Test fun unServerCheNonConosceIlWalletVaLettoComeSpento() {
        // Un'app aggiornata contro un server vecchio: il campo manca del tutto.
        assertFalse(json.decodeFromString<ApiEnvelope<WalletFeature>>("""{"data":{}}""").data.googleWallet)
        assertFalse(json.decodeFromString<ApiEnvelope<WalletFeature>>("""{"data":{"google_wallet":false}}""").data.googleWallet)
        assertTrue(json.decodeFromString<ApiEnvelope<WalletFeature>>("""{"data":{"google_wallet":true}}""").data.googleWallet)
    }

    @Test fun leggeLIndirizzoFirmatoDelPass() {
        val pass = json.decodeFromString<ApiEnvelope<WalletPass>>("""{"data":{"save_url":"https://pay.google.com/gp/v/save/abc.def.ghi"}}""").data
        assertEquals("https://pay.google.com/gp/v/save/abc.def.ghi", pass.saveUrl)
    }

    @Test fun senzaDichiarazioneDelServerIlPulsanteNonEsiste() {
        assertFalse(AppUiState().offersWalletPass(ticket()))
        assertTrue(AppUiState(walletEnabled = true).offersWalletPass(ticket()))
    }

    @Test fun nessunPassPerUnBigliettoCheNonEValido() {
        val acceso = AppUiState(walletEnabled = true)
        assertFalse(acceso.offersWalletPass(ticket(status = "cancelled")))
        assertFalse(acceso.offersWalletPass(ticket(status = "waitlisted")))
        assertFalse(acceso.offersWalletPass(ticket(status = "checked_in")))
        assertFalse(acceso.offersWalletPass(ticket(status = "expired")))
    }

    @Test fun senzaCodiceDiIngressoNonCEnienteDaMettereNelPass() {
        // Il server toglie `qr_payload` appena il biglietto smette di valere:
        // il pulsante deve sparire con lui, non un giro dopo.
        assertFalse(AppUiState(walletEnabled = true).offersWalletPass(ticket(qr = null)))
    }
}
