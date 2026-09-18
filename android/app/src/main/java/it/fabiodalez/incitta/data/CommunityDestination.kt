package it.fabiodalez.incitta.data

import java.net.URI

/** Strict allowlist shared by notification taps and web links; IDs are never trusted for authorization. */
internal data class CommunityDestination(val area: String, val route: String) {
    companion object {
        fun parse(url: String, allowedOrigin: String): CommunityDestination? = runCatching {
            val uri = URI(url)
            val allowed = URI(allowedOrigin)
            if (uri.scheme != allowed.scheme || uri.host != allowed.host || uri.port != allowed.port || uri.userInfo != null) return null
            val path = uri.path.orEmpty().trimEnd('/')
            val fixed = mapOf(
                "/passaggi" to CommunityDestination("carpool", "me"),
                "/passaggi/messaggi" to CommunityDestination("carpool", "chats"),
                "/passaggi/assistenza" to CommunityDestination("carpool", "cases"),
                "/passaggi/requisiti" to CommunityDestination("carpool", "requirements"),
                "/passaggi/regole" to CommunityDestination("carpool", "terms"),
                "/avvisi" to CommunityDestination("carpool", "inbox"),
                "/bacheca" to CommunityDestination("social", "feed"),
                "/persone" to CommunityDestination("social", "people"),
                "/persone-che-mi-seguono" to CommunityDestination("social", "followers"),
                "/verifica-whatsapp" to CommunityDestination("social", "whatsapp"),
            )
            fixed[path]?.let { return it }
            Regex("^/passaggi/(offerte|richieste|messaggi|assistenza|date)/([1-9][0-9]*)(/offri)?$").matchEntire(path)?.let { match ->
                val id = match.groupValues[2].toLongOrNull() ?: return null
                val type = match.groupValues[1]
                val creating = match.groupValues[3].isNotEmpty()
                if (creating && type != "date") return null
                val route = when(type) { "offerte" -> "offers/$id"; "richieste" -> "requests/$id"; "messaggi" -> "chats/$id"; "assistenza" -> "cases/$id"; else -> if(creating) "create/$id" else "occurrences/$id" }
                return CommunityDestination("carpool", route)
            }
            Regex("^/passaggi/conducenti/([1-9][0-9]*)/recensioni$").matchEntire(path)?.let {
                val id=it.groupValues[1].toLongOrNull() ?: return null
                return CommunityDestination("carpool", "drivers/$id/reviews")
            }
            Regex("^/bacheca/post/([1-9][0-9]*)$").matchEntire(path)?.let { match ->
                val id = match.groupValues[1].toLongOrNull() ?: return null
                return CommunityDestination("social", "post/$id")
            }
            Regex("^/persone/([a-z0-9_]{3,30})$").matchEntire(path)?.let { return CommunityDestination("social", "profile/${it.groupValues[1]}") }
            null
        }.getOrNull()
    }
}
