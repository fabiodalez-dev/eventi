package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.AuthPayload
import kotlinx.serialization.json.Json
import org.junit.Assert.*
import org.junit.Test

/* Q2: la bozza dei moduli sul telefono, il ritorno dopo l'accesso e l'invio automatico solo dopo una registrazione. */
class ApplicationDraftsTest {
    internal class MemoryVault : DraftVault {
        var value: String? = null
        override fun read(): String? = value
        override fun write(value: String?) { this.value = value }
    }

    internal class MemoryFiles : DraftFiles {
        val files = mutableMapOf<String, ByteArray>()
        override fun write(name: String, bytes: ByteArray) { files[name] = bytes }
        override fun read(name: String): ByteArray? = files[name]
        override fun delete(name: String) { files.remove(name) }
        override fun clear() = files.clear()
    }

    private var clock = 1_000_000L
    /* L'account collegato secondo le bozze: `null` da ospite. */
    private var signedIn: Long? = null
    private val vault = MemoryVault()
    private val files = MemoryFiles()
    private val drafts = ApplicationDrafts(vault, files, owner = { signedIn }) { clock }

    @Test fun eachFormHasItsOwnDraftAndItSurvivesANewInstance() {
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo\"}", "marta@esempio.it")
        drafts.save(DraftScopes.claim("osteria"), "{\"message\":\"Sono io\"}", "")
        drafts.save(DraftScopes.ORGANIZER, "{\"name\":\"Collettivo\"}", "")

        val reopened = ApplicationDrafts(vault, files) { clock }
        assertEquals("{\"venueName\":\"Circolo\"}", reopened.load(DraftScopes.VENUE)?.fields)
        assertEquals("marta@esempio.it", reopened.load(DraftScopes.VENUE)?.email)
        assertEquals("{\"message\":\"Sono io\"}", reopened.load("claim:osteria")?.fields)
        assertNull(reopened.load("claim:altro"))
        assertEquals("{\"name\":\"Collettivo\"}", reopened.load(DraftScopes.ORGANIZER)?.fields)
    }

    @Test fun theImageStaysInAFileAndGoesAwayWithTheDraft() {
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(12) { 7 }, 800, 600))
        val image = drafts.image(DraftScopes.VENUE)
        assertEquals(800, image?.width)
        assertEquals(12, image?.bytes?.size)
        assertEquals(1, files.files.size)
        /* Nel quaderno solo il nome del file, non i byte. */
        assertFalse(vault.value.orEmpty().contains("BwcH"))

        drafts.remove(DraftScopes.VENUE)
        assertNull(drafts.load(DraftScopes.VENUE))
        assertTrue(files.files.isEmpty())
        assertNull(vault.value)
    }

    @Test fun aRegistrationOfTheSameAddressSendsTheDraftByItself() {
        drafts.save(DraftScopes.VENUE, "{}", "Marta@Esempio.it")
        drafts.awaitAccount(DraftScopes.VENUE, "Marta@Esempio.it")
        assertEquals(AwaitingAccount(DraftScopes.VENUE, "Marta@Esempio.it"), drafts.awaiting())

        signedIn = 7
        assertEquals(DraftScopes.VENUE, drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = true))
        assertNull(drafts.awaiting())
        assertTrue(drafts.takeAutoSend(DraftScopes.VENUE))
        /* Una volta sola. */
        assertFalse(drafts.takeAutoSend(DraftScopes.VENUE))
        assertNotNull(drafts.load(DraftScopes.VENUE))
    }

    @Test fun anAccountThatAlreadyExistedReopensTheFormWithoutSending() {
        drafts.save(DraftScopes.ORGANIZER, "{}", "marta@esempio.it")
        drafts.awaitAccount(DraftScopes.ORGANIZER, "marta@esempio.it")
        signedIn = 7
        assertEquals(DraftScopes.ORGANIZER, drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        assertFalse(drafts.takeAutoSend(DraftScopes.ORGANIZER))

        /* Un altro account, o un'email non confermata: niente invio automatico. */
        drafts.awaitAccount(DraftScopes.ORGANIZER, "marta@esempio.it")
        assertEquals(DraftScopes.ORGANIZER, drafts.afterSignIn("altro@esempio.it", emailVerified = true, registration = true))
        assertFalse(drafts.takeAutoSend(DraftScopes.ORGANIZER))
        drafts.awaitAccount(DraftScopes.ORGANIZER, "marta@esempio.it")
        drafts.afterSignIn("marta@esempio.it", emailVerified = false, registration = true)
        assertFalse(drafts.takeAutoSend(DraftScopes.ORGANIZER))
    }

    @Test fun signingInFromTheFormComesBackToIt() {
        assertNull(drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        drafts.save(DraftScopes.claim("osteria"), "{}", "")
        drafts.returnAfterSignIn(DraftScopes.claim("osteria"))
        assertEquals("claim:osteria", drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        assertNull(drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))
        assertFalse(drafts.takeAutoSend("claim:osteria"))
    }

    @Test fun signingOutClearsEverythingAndOldDraftsExpire() {
        drafts.save(DraftScopes.VENUE, "{}", "marta@esempio.it")
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(4), 800, 600))
        drafts.clear()
        assertNull(vault.value)
        assertTrue(files.files.isEmpty())

        drafts.save(DraftScopes.ORGANIZER, "{}", "")
        drafts.awaitAccount(DraftScopes.ORGANIZER, "marta@esempio.it")
        clock += ApplicationDrafts.MAX_AGE_MILLIS
        assertNull(drafts.load(DraftScopes.ORGANIZER))
        assertNull(drafts.awaiting())
    }

    @Test fun aDraftWrittenByAnAccountIsNotSeenByAnother() {
        signedIn = 7
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo di Marta\"}", "")
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(4), 800, 600))
        assertEquals(7L, drafts.load(DraftScopes.VENUE)?.ownerId)

        /* La sessione cade senza logout ed entra un altro account: il modulo non ha bozze, né immagine. */
        signedIn = 8
        assertNull(drafts.load(DraftScopes.VENUE))
        assertNull(drafts.image(DraftScopes.VENUE))
        assertNull(drafts.sendKey(DraftScopes.VENUE))
        /* Se scrive, la sua bozza prende il posto di quella di prima, senza ereditarne niente. */
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo di Bruno\"}", "")
        assertEquals(8L, drafts.load(DraftScopes.VENUE)?.ownerId)
        assertNull(drafts.image(DraftScopes.VENUE))
        assertTrue(files.files.isEmpty())
        signedIn = 7
        assertNull(drafts.load(DraftScopes.VENUE))
    }

    @Test fun theAutoSendLeavesOnlyForTheAccountThatSignedIn() {
        drafts.save(DraftScopes.VENUE, "{}", "marta@esempio.it")
        drafts.awaitAccount(DraftScopes.VENUE, "marta@esempio.it")
        signedIn = 7
        drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = true)

        /* L'app si chiude prima di aprire il modulo; poi entra un altro account. */
        signedIn = 8
        assertFalse(drafts.takeAutoSend(DraftScopes.VENUE))
        signedIn = 7
        assertFalse(drafts.takeAutoSend(DraftScopes.VENUE))
    }

    @Test fun closingTheSessionDropsTheAccountDraftsAndKeepsTheGuestOnes() {
        /* L'ospite scrive un'organizzazione e sceglie «Ho già un account». */
        drafts.save(DraftScopes.ORGANIZER, "{\"name\":\"Collettivo\"}", "")
        drafts.returnAfterSignIn(DraftScopes.ORGANIZER)
        /* Una bozza rimasta da un account di prima, con l'immagine. */
        signedIn = 5
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo\"}", "")
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(4), 800, 600))
        signedIn = null

        /* `login` passa da `clearAuthenticatedState`, poi `acceptSession` con il nuovo account. */
        drafts.forgetOthers(null)
        assertTrue(files.files.isEmpty())
        signedIn = 7
        drafts.forgetOthers(7)
        assertEquals(DraftScopes.ORGANIZER, drafts.afterSignIn("marta@esempio.it", emailVerified = true, registration = false))

        /* Al primo accesso la bozza dell'ospite resta, e diventa dell'account. */
        assertEquals("{\"name\":\"Collettivo\"}", drafts.load(DraftScopes.ORGANIZER)?.fields)
        assertEquals(7L, drafts.load(DraftScopes.ORGANIZER)?.ownerId)
        signedIn = 5
        assertNull(drafts.load(DraftScopes.VENUE))

        /* La sessione si chiude da sola (un 401): con lei va via la bozza dell'account. */
        signedIn = null
        drafts.forgetOthers(null)
        assertNull(drafts.load(DraftScopes.ORGANIZER))
        assertNull(vault.value)
    }

    @Test fun theSendKeyStaysUntilTheContentChanges() {
        signedIn = 7
        assertNull(drafts.sendKey(DraftScopes.VENUE))
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo\"}", "")
        val key = drafts.sendKey(DraftScopes.VENUE)
        assertNotNull(key)
        assertTrue(Regex("^[A-Za-z0-9._:-]{16,128}$").matches(key!!))
        assertEquals(key, drafts.sendKey(DraftScopes.VENUE))
        assertEquals(key, ApplicationDrafts(vault, files, owner = { signedIn }) { clock }.sendKey(DraftScopes.VENUE))

        /* Lo stesso contenuto salvato di nuovo: stessa chiave. */
        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo\"}", "")
        assertEquals(key, drafts.sendKey(DraftScopes.VENUE))

        drafts.save(DraftScopes.VENUE, "{\"venueName\":\"Circolo del Ponte\"}", "")
        val edited = drafts.sendKey(DraftScopes.VENUE)
        assertNotEquals(key, edited)
        drafts.saveImage(DraftScopes.VENUE, PreparedImage(ByteArray(4), 800, 600))
        assertNotEquals(edited, drafts.sendKey(DraftScopes.VENUE))
    }

    @Test fun aDamagedBookStartsAgainEmpty() {
        vault.value = "non è json"
        assertNull(drafts.load(DraftScopes.VENUE))
        assertNull(vault.value)
    }

    @Test fun scopesMapToTheirScreens() {
        assertEquals(ManagementDestination.Registration(RegistrationForm.Venue), DraftScopes.destination(DraftScopes.VENUE))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Organizer), DraftScopes.destination(DraftScopes.ORGANIZER))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Applications), DraftScopes.destination(DraftScopes.APPLICATIONS))
        assertEquals(ManagementDestination.Registration(RegistrationForm.Claim, "osteria-ponte"), DraftScopes.destination("claim:osteria-ponte"))
        assertNull(DraftScopes.destination("claim:../altro"))
        assertNull(DraftScopes.destination("boh"))
        assertEquals("claim:osteria", DraftScopes.of(ManagementDestination.Registration(RegistrationForm.Claim, "osteria")))
        assertEquals(DraftScopes.VENUE, DraftScopes.of(ManagementDestination.Registration(RegistrationForm.Venue)))
    }

    @Test fun theExchangeSaysWhetherItClosesARegistration() {
        val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
        val user = "{\"id\":7,\"name\":\"Marta\",\"email\":\"marta@esempio.it\",\"email_verified\":true}"
        assertTrue(json.decodeFromString(AuthPayload.serializer(), "{\"token\":\"t\",\"registration\":true,\"user\":$user}").registration)
        /* Un server di prima non lo manda: vale come un accesso qualunque. */
        assertFalse(json.decodeFromString(AuthPayload.serializer(), "{\"token\":\"t\",\"user\":$user}").registration)
    }
}
