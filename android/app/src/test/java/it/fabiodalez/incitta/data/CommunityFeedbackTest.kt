package it.fabiodalez.incitta.data

import java.io.IOException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CommunityFeedbackTest {
    @Test fun expiredSessionAsksToSignInAgain() {
        assertTrue(communityFailureMessage(ApiException(401, null)).startsWith("La sessione è scaduta"))
    }

    @Test fun rateLimitAndServerFailuresUseTheAppWording() {
        assertTrue(communityFailureMessage(ApiException(429, null)).startsWith("Troppe richieste"))
        assertTrue(communityFailureMessage(ApiException(500, null)).contains("temporaneamente non disponibile"))
    }

    @Test fun validationErrorShowsTheServerFieldMessage() {
        val problem = ApiProblem(message = "The given data was invalid.", fields = mapOf("body" to listOf("Completa il tuo profilo prima di commentare.")))
        assertEquals("Completa il tuo profilo prima di commentare.", communityFailureMessage(ApiException(422, problem)))
    }

    @Test fun inputProblemIsShownVerbatim() {
        assertEquals("Scegli una foto JPG, PNG o WebP fino a 2 MB.", communityFailureMessage(CommunityInputException("Scegli una foto JPG, PNG o WebP fino a 2 MB.")))
    }

    @Test fun rawNetworkFailureNeverLeaksItsTechnicalMessage() {
        val message = communityFailureMessage(IOException("unexpected end of stream on https://private.example"))
        assertEquals(requestFailureMessage(IOException()), message)
        assertTrue(message.contains("Wi-Fi"))
    }

    @Test fun incompatiblePayloadAsksToUpdateTheApp() {
        val error = ApiPayloadException(IllegalArgumentException("field x"))
        assertEquals(error.message, communityFailureMessage(error))
    }
}
