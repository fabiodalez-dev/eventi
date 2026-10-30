package it.fabiodalez.incitta.data

import java.net.URI

/**
 * Il collegamento di conferma che la pagina del sito riporta nell'app
 * (`incitta://auth/verify-email?url=…`) quando l'email è stata aperta nel
 * browser senza sessione.
 *
 * L'app lo rimanda a `auth/verify-email` col proprio token solo se punta allo
 * stesso sito dell'API e alla rotta della conferma, firma compresa. La firma
 * la giudica il server; qui si evita soltanto di inoltrare insieme al token
 * indirizzi che un'altra app potrebbe aver infilato nell'intent.
 */
internal fun isEmailConfirmationLink(url: String, apiBaseUrl: String): Boolean {
    val link = runCatching { URI(url) }.getOrNull() ?: return false
    val api = runCatching { URI(apiBaseUrl) }.getOrNull() ?: return false
    val scheme = link.scheme ?: return false
    val host = link.host ?: return false
    return scheme.equals(api.scheme, ignoreCase = true) && host.equals(api.host, ignoreCase = true) && link.port == api.port &&
        link.rawPath.orEmpty().contains("/email/verifica/") && link.rawQuery.orEmpty().split('&').any { it.startsWith("signature=") }
}
