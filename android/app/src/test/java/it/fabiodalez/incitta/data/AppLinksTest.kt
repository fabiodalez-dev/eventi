package it.fabiodalez.incitta.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AppLinksTest {
    @Test fun lasciaAlSitoFileEModuli() {
        listOf(
            "eventi/sagra/3/calendario.ics",
            "eventi/sagra/3/locandina.pdf",
            "eventi/categoria/musica/calendario.ics",
            "eventi/sagra/segnala",
            "locali/circolo/calendario.ics",
            "locali/circolo/programma.pdf",
            "locali/circolo/segnala",
            "locali/circolo/rivendica",
            "organizzatori/aurora/calendario.ics",
            "eventi/2026-11-06",
        ).forEach { assertTrue(it, isWebOnlyLink(it.split("/"))) }
    }

    @Test fun tieneNellAppLePagineCheHa() {
        listOf(
            "eventi/sagra",
            "eventi/sagra/3",
            "eventi/segnala",
            "eventi/oggi",
            "eventi/tag/jazz",
            "locali/circolo",
            "locali/rivendica",
            "organizzatori/aurora",
            "mappa",
        ).forEach { assertFalse(it, isWebOnlyLink(it.split("/"))) }
    }

    @Test fun nonRimandaMaiAllAppStessa() {
        val self = "it.fabiodalez.incitta"
        assertEquals("org.mozilla.firefox", pickBrowser("org.mozilla.firefox", listOf(self, "org.mozilla.firefox"), self))
        assertEquals("com.android.chrome", pickBrowser(self, listOf(self, "com.android.chrome"), self))
        // Senza un browser predefinito il sistema indica il proprio selettore, che non è un browser.
        assertEquals("com.android.chrome", pickBrowser("android", listOf("com.android.chrome"), self))
        assertNull(pickBrowser(null, listOf(self), self))
    }
}
