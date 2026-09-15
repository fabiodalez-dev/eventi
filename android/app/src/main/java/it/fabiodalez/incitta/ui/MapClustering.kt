package it.fabiodalez.incitta.ui

import kotlin.math.floor

/** Group overlapping screen-space targets, including across grid boundaries. */
internal fun clusterMapPoints(points: List<Pair<Float, Float>>, spacing: Float): List<List<Int>> {
    require(spacing > 0)
    val parents = IntArray(points.size) { it }
    fun root(index: Int): Int {
        var current = index
        while (parents[current] != current) {
            parents[current] = parents[parents[current]]
            current = parents[current]
        }
        return current
    }
    val cells = mutableMapOf<Pair<Int, Int>, MutableList<Int>>()
    points.forEachIndexed { index, (x, y) ->
        val column = floor(x / spacing).toInt()
        val row = floor(y / spacing).toInt()
        for (dx in -1..1) for (dy in -1..1) {
            cells[column + dx to row + dy]?.forEach { other ->
                val deltaX = x - points[other].first
                val deltaY = y - points[other].second
                if (deltaX * deltaX + deltaY * deltaY < spacing * spacing) {
                    parents[root(index)] = root(other)
                }
            }
        }
        cells.getOrPut(column to row) { mutableListOf() }.add(index)
    }
    return points.indices.groupBy(::root).values.toList()
}
