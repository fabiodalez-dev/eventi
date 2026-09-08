package it.fabiodalez.incitta.data

import java.io.IOException
import java.net.SocketTimeoutException
import javax.net.ssl.SSLException

@PublishedApi
internal class ApiPayloadException(cause: Throwable) : Exception("La risposta del server non è compatibile con questa versione dell'app. Aggiorna l'app e riprova.", cause)

internal fun requestFailureMessage(error: Throwable): String = when {
    error is ApiException && error.status == 401 -> "La sessione è scaduta. Esci dal profilo e accedi di nuovo."
    error is ApiException && error.status == 429 -> "Troppe richieste. Attendi un minuto e riprova."
    error is ApiException && error.status >= 500 -> "Il server è temporaneamente non disponibile. Riprova tra poco."
    error is ApiException -> error.message ?: "Richiesta non riuscita. Riprova."
    error is ApiPayloadException -> error.message!!
    error is SSLException || error.cause is SSLException -> "Connessione sicura non riuscita. Verifica data e ora del telefono e riprova con un'altra rete."
    error is SocketTimeoutException || error.cause is SocketTimeoutException -> "Il server non ha risposto in tempo. Riprova."
    error is IOException -> "Non riesco a raggiungere il server. Controlla Wi-Fi o rete mobile e riprova."
    else -> "Richiesta non riuscita. Riprova."
}

internal fun shouldShowOffline(error: Throwable, hasCachedEvents: Boolean): Boolean =
    hasCachedEvents && error is IOException
