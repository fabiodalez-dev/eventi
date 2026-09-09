package it.fabiodalez.incitta.ui

/** Compose scroll deltas are positive towards the top of the page. */
internal class ScrollNavigation(private val threshold: Float) {
    private var movement = 0f

    fun scroll(delta: Float): Boolean? {
        if (!delta.isFinite() || delta == 0f) return null
        movement = if (kotlin.math.sign(movement) == kotlin.math.sign(delta)) movement + delta else delta
        if (kotlin.math.abs(movement) < threshold) return null
        val visible = movement > 0
        movement = 0f
        return visible
    }
}
