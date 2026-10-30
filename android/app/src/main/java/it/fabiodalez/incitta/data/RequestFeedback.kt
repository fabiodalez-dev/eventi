package it.fabiodalez.incitta.data

import java.io.IOException
import java.net.SocketTimeoutException
import javax.net.ssl.SSLException

@PublishedApi
internal class ApiPayloadException(cause: Throwable) : Exception("La risposta del server non è compatibile con questa versione dell'app. Aggiorna l'app e riprova.", cause)

internal fun requestFailureMessage(error: Throwable): String = friendlyRequestFailureMessage(error)

/** Il codice con cui il server rifiuta un'azione finché l'indirizzo email non è confermato. */
internal const val EMAIL_VERIFICATION_REQUIRED = "EMAIL_VERIFICATION_REQUIRED"

/**
 * Vero quando il server risponde 403 `EMAIL_VERIFICATION_REQUIRED`: prenotare,
 * spedire un biglietto, chiedere il pass Wallet o scrivere a un locale
 * richiedono l'indirizzo confermato, come sul sito. Non è un guasto né un
 * divieto: la schermata mostra il messaggio del server e offre il reinvio
 * della conferma.
 */
internal fun requiresEmailVerification(error: Throwable): Boolean =
    error is ApiException && error.status == 403 && error.problem?.code == EMAIL_VERIFICATION_REQUIRED

/**
 * Un 429 che il server ha spiegato: un codice suo diverso dal limite
 * generico, oppure un errore su un campo. Il tetto giornaliero di un
 * recapito, per esempio, non passa in un minuto e non dipende da chi scrive:
 * «attendi un minuto» farebbe riprovare per ore.
 */
private fun explainedRateLimit(error: ApiException): String? {
    val problem = error.problem ?: return null
    val field = problem.fields.values.firstOrNull()?.firstOrNull()
    if (!field.isNullOrBlank()) return field
    return problem.message?.takeIf { it.isNotBlank() && problem.code != null && problem.code != RATE_LIMITED }
}

/** Il codice del limite di frequenza generico, quello che si risolve aspettando. */
private const val RATE_LIMITED = "RATE_LIMITED"

private fun friendlyRequestFailureMessage(error: Throwable): String = when {
    error is ApiException && error.status == 401 -> "La sessione è scaduta. Esci dal profilo e accedi di nuovo."
    error is ApiException && error.status == 429 -> explainedRateLimit(error) ?: "Troppe richieste. Attendi un minuto e riprova."
    error is ApiException && error.status >= 500 -> "Il server è temporaneamente non disponibile. Riprova tra poco."
    requiresEmailVerification(error) -> (error as ApiException).problem?.message?.takeIf { it.isNotBlank() } ?: "Conferma il tuo indirizzo email per continuare."
    error is ApiException -> error.message ?: "Richiesta non riuscita. Riprova."
    error is ApiPayloadException -> error.message!!
    error is SSLException || error.cause is SSLException -> "Connessione sicura non riuscita. Verifica data e ora del telefono e riprova con un'altra rete."
    error is SocketTimeoutException || error.cause is SocketTimeoutException -> "Il server non ha risposto in tempo. Riprova."
    error is IOException -> "Non riesco a raggiungere il server. Controlla Wi-Fi o rete mobile e riprova."
    else -> "Richiesta non riuscita. Riprova."
}

internal fun shouldShowOffline(error: Throwable, hasCachedEvents: Boolean): Boolean =
    hasCachedEvents && error is IOException
