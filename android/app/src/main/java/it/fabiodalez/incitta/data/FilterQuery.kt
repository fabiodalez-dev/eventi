package it.fabiodalez.incitta.data

import java.net.URLEncoder

/** One wire format for facet previews, search results and map markers. */
internal fun filterQuery(filters: Map<String, String>, query: String): String {
    val parameters = (filters + ("q" to query)).toMutableMap()
    // Laravel treats a single boundary as one day, not an unbounded interval.
    if (!parameters["to"].isNullOrBlank() && parameters["from"].isNullOrBlank()) {
        parameters["from"] = parameters.getValue("to")
    }
    return parameters.entries.joinToString("&") { (key, value) ->
        val normalized = if (key == "price") when (value) {
            "max10" -> "max:10"
            "max20" -> "max:20"
            else -> value
        } else value
        "${URLEncoder.encode(key, "UTF-8")}=${URLEncoder.encode(normalized, "UTF-8")}"
    }
}
