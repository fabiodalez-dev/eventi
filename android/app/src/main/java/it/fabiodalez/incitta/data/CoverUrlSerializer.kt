package it.fabiodalez.incitta.data

import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.builtins.nullable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.JsonTransformingSerializer

/** Accept the API image variants while preserving cached URL-only venues. */
object CoverUrlSerializer : JsonTransformingSerializer<String?>(String.serializer().nullable) {
    override fun transformDeserialize(element: JsonElement): JsonElement {
        if (element !is JsonObject) return element
        return listOf("card", "full", "thumb").firstNotNullOfOrNull { key ->
            (element[key] as? JsonPrimitive)?.takeIf { it.isString && it.content.isNotBlank() }
        } ?: JsonNull
    }
}
