package it.fabiodalez.incitta.notifications

import android.content.Context
import android.content.Intent
import it.fabiodalez.incitta.data.LocalStore
import it.fabiodalez.incitta.data.management.NOTICE_HOST
import it.fabiodalez.incitta.data.management.NOTICE_SCHEME
import java.security.MessageDigest

/**
 * La prova che un intent arriva da una notifica di questa app.
 *
 * Gli avvisi a chi gestisce (P12) e quelli dell'account (Q3) aprono un
 * indirizzo interno, `incitta://gestione/…`, che fuori dall'app non deve
 * valere: porta gli id e non gli slug, e salta la scelta del soggetto.
 * `MainActivity` è esportata (la vogliono il launcher, gli App Link e i
 * ritorni da browser e Stripe), quindi un'altra app installata può mandarle
 * un intent esplicito con quell'indirizzo; l'id utente che lo accompagnava
 * (`notification_user_id`) si indovina, perché gli id sono in fila.
 *
 * La notifica di sistema e la casella dell'app mettono nell'intent anche il
 * segreto dell'installazione (`LocalStore.notificationLinkSecret`), che
 * un'altra app non legge: né dalle preferenze cifrate né dal `PendingIntent`
 * immutabile. Senza il segreto giusto un indirizzo interno non si apre; gli
 * App Link e gli altri collegamenti restano come prima.
 */
internal object NotificationLinkProof {
    const val EXTRA_USER = "notification_user_id"
    const val EXTRA_PROOF = "notification_proof"

    /** Segna l'intent di una notifica per la persona collegata. */
    fun stamp(context: Context, intent: Intent, userId: Long): Intent =
        intent.putExtra(EXTRA_USER, userId).putExtra(EXTRA_PROOF, LocalStore(context).notificationLinkSecret())

    /**
     * R14: se l'intent porta la prova di una notifica di questa app per la
     * persona collegata. Un indirizzo del pannello della redazione
     * (`/admin/…`) si apre nell'area nativa solo così; senza prova va nel
     * browser, dove vale l'accesso del sito.
     */
    fun proven(context: Context, intent: Intent): Boolean {
        val store = LocalStore(context)
        val claimed = if (intent.hasExtra(EXTRA_USER)) intent.getLongExtra(EXTRA_USER, -1) else null
        return notificationProofMatches(claimed, intent.getStringExtra(EXTRA_PROOF), store.readSession()?.user?.id) { store.notificationLinkSecret() }
    }

    /** Se l'indirizzo dell'intent si può aprire, con la sessione di adesso. */
    fun accepts(context: Context, intent: Intent): Boolean {
        val store = LocalStore(context)
        val claimed = if (intent.hasExtra(EXTRA_USER)) intent.getLongExtra(EXTRA_USER, -1) else null
        return notificationLinkAllowed(intent.data?.toString(), claimed, intent.getStringExtra(EXTRA_PROOF), store.readSession()?.user?.id) { store.notificationLinkSecret() }
    }
}

/** `incitta://gestione/…`: gli indirizzi interni della gestione, tutti, non solo quelli delle aree di oggi. */
internal fun isInternalManagementLink(uri: String?): Boolean {
    if (uri == null) return false
    val scheme = uri.substringBefore(':', "")
    if (!scheme.equals(NOTICE_SCHEME, ignoreCase = true)) return false
    val host = uri.substringAfter("://", "").substringBefore('/').substringBefore('?').substringBefore('#').substringAfterLast('@').substringBefore(':')
    return host.equals(NOTICE_HOST, ignoreCase = true)
}

/**
 * La regola di `MainActivity`, senza Android per poterla provare.
 *
 * - Un intent che dice di venire da una notifica per un'altra persona non si
 *   apre, come prima.
 * - Un indirizzo interno della gestione si apre solo con la persona giusta e
 *   il segreto dell'installazione.
 * - Tutto il resto (App Link, ritorni dal browser, collegamenti aperti da
 *   un'altra app) si tratta come un collegamento qualunque.
 */
internal fun notificationLinkAllowed(uri: String?, claimedUser: Long?, proof: String?, sessionUser: Long?, secret: () -> String): Boolean {
    if (claimedUser != null && claimedUser != sessionUser) return false
    if (!isInternalManagementLink(uri)) return true
    return notificationProofMatches(claimedUser, proof, sessionUser, secret)
}

/** La persona della notifica è quella collegata e il segreto dell'installazione è quello giusto. */
internal fun notificationProofMatches(claimedUser: Long?, proof: String?, sessionUser: Long?, secret: () -> String): Boolean {
    if (claimedUser == null || sessionUser == null || claimedUser != sessionUser || proof.isNullOrEmpty()) return false
    return MessageDigest.isEqual(proof.toByteArray(Charsets.UTF_8), secret().toByteArray(Charsets.UTF_8))
}
