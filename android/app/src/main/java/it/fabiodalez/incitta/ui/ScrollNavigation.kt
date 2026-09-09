package it.fabiodalez.incitta.ui

/** Compose scroll deltas are positive towards the top of the page. */
internal class ScrollNavigation(private val threshold: Float) {
    private var movement = 0f

    fun scroll(delta: Float): Boolean? {
        if (!delta.isFinite() || delta == 0f) return null
        movement = (movement - delta).coerceAtLeast(0f)
        return when {
            movement >= threshold -> true
            movement <= threshold / 4 -> false
            else -> null
        }
    }
}
