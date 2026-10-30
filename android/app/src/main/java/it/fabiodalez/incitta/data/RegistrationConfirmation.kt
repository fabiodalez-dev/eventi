package it.fabiodalez.incitta.data

/**
 * La registrazione che non rivela se un indirizzo è già registrato (D64).
 *
 * `POST /v1/auth/register` risponde sempre 202 «Ti abbiamo scritto», senza
 * token: per un indirizzo nuovo arriva il collegamento che conferma ed entra,
 * per uno già registrato «hai già un account». L'app mostra «controlla la
 * posta» e completa l'accesso quando l'utente apre il collegamento, con lo
 * stesso scambio PKCE del collegamento di accesso: il verificatore resta in
 * questo telefono, e lo scambio lascia valida la password appena scelta.
 */

/** Il codice con cui il server rifiuta la password di un account mai confermato. */
internal const val EMAIL_CONFIRMATION_PENDING = "EMAIL_CONFIRMATION_PENDING"

/**
 * Vero quando l'accesso con password trova l'account registrato e mai
 * confermato: il server non dà il token e rimanda il collegamento. Lo vede
 * solo chi conosce la password; per tutti gli altri resta il 401 di sempre.
 */
internal fun awaitsEmailConfirmation(error: Throwable): Boolean =
    error is ApiException && error.status == 403 && error.problem?.code == EMAIL_CONFIRMATION_PENDING

/**
 * Lo stato della schermata «controlla la posta»: l'indirizzo a cui è partito
 * il messaggio e da dove si è arrivati, che decide come si reinvia.
 * La registrazione si reinvia rifacendola con gli stessi dati (il server
 * riconosce la password giusta e riparte il collegamento), l'accesso
 * rifacendo l'accesso.
 */
data class PendingEmailConfirmation(val email: String, val fromLogin: Boolean = false)
