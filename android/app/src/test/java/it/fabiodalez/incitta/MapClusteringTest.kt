package it.fabiodalez.incitta

import it.fabiodalez.incitta.ui.clusterMapPoints
import org.junit.Assert.assertEquals
import org.junit.Test

class MapClusteringTest {
    @Test fun nearbyMarkersMergeEvenAcrossCellBoundaries() {
        assertEquals(listOf(listOf(0, 1), listOf(2)), clusterMapPoints(listOf(63f to 10f, 65f to 10f, 200f to 10f), 64f))
    }

    @Test fun zoomSeparatesVenuesWithoutLosingThem() {
        val points = listOf(0f to 0f, 30f to 0f, 60f to 0f)
        assertEquals(listOf(listOf(0, 1, 2)), clusterMapPoints(points, 64f))
        assertEquals(listOf(listOf(0), listOf(1), listOf(2)), clusterMapPoints(points.map { it.first * 3 to it.second }, 64f))
    }

    @Test fun densityAndOffscreenCoordinatesDoNotChangeGrouping() {
        val points = listOf(-2f to -5f, 5f to 4f, 120f to 200f)
        assertEquals(clusterMapPoints(points, 64f), clusterMapPoints(points.map { it.first * 3 to it.second * 3 }, 192f))
        assertEquals(emptyList<List<Int>>(), clusterMapPoints(emptyList(), 64f))
    }
}
