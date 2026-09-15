package it.fabiodalez.incitta.ui

import android.graphics.Color
import android.util.DisplayMetrics
import org.junit.Assert.assertTrue
import org.junit.Test

class MapMarkerRenderingTest {
    @Test fun singleAndGroupedVenuesKeepTheirNumberInBothThemesAndDensities() {
        for (light in listOf(false, true)) {
            for (scale in listOf(1f, 3f)) {
                for (count in listOf(1, 2, 12, 100)) {
                    val metrics = DisplayMetrics().apply {
                        density = scale
                        densityDpi = (160 * scale).toInt()
                    }
                    val bitmap = mapMarkerBitmap(count, metrics, light)
                    val center = bitmap.width / 2
                    val radius = (12 * scale).toInt()
                    val textColor = if (light) Color.rgb(250, 249, 246) else Color.rgb(11, 11, 11)
                    val hasText = (center - radius..center + radius).any { x ->
                        (center - radius..center + radius).any { y -> bitmap.getPixel(x, y) == textColor }
                    }
                    assertTrue("Missing count=$count light=$light density=$scale", hasText)
                    bitmap.recycle()
                }
            }
        }
    }
}
