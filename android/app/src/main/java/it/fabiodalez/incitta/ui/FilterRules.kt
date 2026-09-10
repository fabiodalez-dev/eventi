package it.fabiodalez.incitta.ui

internal fun removesSearchFilters(before: Map<String, String>, next: Map<String, String>): Boolean =
    next.all { (key, value) ->
        value.isBlank() || key == "sort" || if (key in setOf("categories", "tags", "access")) {
            value.split(',').all { it in before[key].orEmpty().split(',') }
        } else before[key] == value
    }

internal fun withoutSearchPosition(filters: Map<String, String>): Map<String, String> =
    filters - setOf("near", "radius_km", "lat", "lng", "radius") -
        (if (filters["sort"] == "distance") setOf("sort") else emptySet())
