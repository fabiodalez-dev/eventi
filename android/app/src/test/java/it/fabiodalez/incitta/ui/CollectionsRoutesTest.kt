package it.fabiodalez.incitta.ui

import it.fabiodalez.incitta.data.CollectionsRoutes
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CollectionsRoutesTest {
    private val token = "AbCdEfGhIjKlMnOpQrStUvWx"

    @Test fun `shared collection path yields its token`() {
        assertEquals(token, CollectionsRoutes.tokenFrom(listOf("raccolte", token)))
    }

    @Test fun `other paths and malformed tokens are ignored`() {
        assertNull(CollectionsRoutes.tokenFrom(listOf("raccolte")))
        assertNull(CollectionsRoutes.tokenFrom(listOf("eventi", token)))
        assertNull(CollectionsRoutes.tokenFrom(listOf("raccolte", "corto")))
        assertNull(CollectionsRoutes.tokenFrom(listOf("raccolte", "$token/x")))
        assertNull(CollectionsRoutes.tokenFrom(listOf("raccolte", token, "extra")))
        assertNull(CollectionsRoutes.tokenFrom(listOf("raccolte", "../../me/collections")))
    }

    @Test fun `token validation matches the server pattern`() {
        assertTrue(CollectionsRoutes.validToken(token))
        assertFalse(CollectionsRoutes.validToken(token + "A"))
        assertFalse(CollectionsRoutes.validToken("AbCdEfGhIjKlMnOpQrStUv-x"))
    }
}
