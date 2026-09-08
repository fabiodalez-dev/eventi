package it.fabiodalez.incitta.data

import kotlinx.serialization.builtins.MapSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.*

/** Older PHP responses/caches represented empty maps as [] or null. Never accept nonempty lists. */
private fun emptyObject(element: JsonElement): JsonElement =
    if (element == JsonNull || (element is JsonArray && element.isEmpty())) JsonObject(emptyMap()) else element

object EmptyBooleanMapSerializer : JsonTransformingSerializer<Map<String, Boolean>>(MapSerializer(String.serializer(), Boolean.serializer())) {
    override fun transformDeserialize(element: JsonElement): JsonElement = emptyObject(element)
}

object EmptyStringMapSerializer : JsonTransformingSerializer<Map<String, String>>(MapSerializer(String.serializer(), String.serializer())) {
    override fun transformDeserialize(element: JsonElement): JsonElement = emptyObject(element)
}
