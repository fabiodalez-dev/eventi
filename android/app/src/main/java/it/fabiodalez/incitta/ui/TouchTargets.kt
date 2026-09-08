package it.fabiodalez.incitta.ui

internal fun markerDiameterDp(count: Int): Int = when {
    count >= 50 -> 76
    count >= 10 -> 72
    count > 1 -> 68
    else -> 64
}
