package it.fabiodalez.incitta.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LocalStoreTest {
    private val context = ApplicationProvider.getApplicationContext<Context>()

    @Test
    fun tokenIsRoundTrippedThroughAndroidKeystoreAndClearedOnLogout() {
        context.getSharedPreferences("incitta", Context.MODE_PRIVATE).edit().clear().commit()
        val store = LocalStore(context)
        val session = Session("secret-token", User(7, "Ada", "ada@example.test"), "2026-12-01T00:00:00Z")

        store.writeSession(session)

        assertEquals(session, store.readSession())
        val raw = context.getSharedPreferences("incitta", Context.MODE_PRIVATE).getString("session", "")
        check(raw != null && !raw.contains("secret-token"))

        store.clearSession()
        assertNull(store.readSession())
    }

    @Test
    fun guestWishlistIsDeviceLocalAndSetBased() {
        val store = LocalStore(context)
        store.setGuestSaved(setOf(12, 12, 44))

        assertEquals(setOf(12L, 44L), store.guestSavedIds())
    }

    @Test
    fun privacyChoiceIsAbsentUntilExplicitlyAcceptedOrRefused() {
        context.getSharedPreferences("incitta", Context.MODE_PRIVATE).edit().clear().commit()
        val store = LocalStore(context)

        assertNull(store.privacyConsent())
        store.setPrivacyConsent(false)
        assertEquals(false, store.privacyConsent())
        store.setPrivacyConsent(true)
        assertEquals(true, store.privacyConsent())
    }
}
