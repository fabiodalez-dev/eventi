package it.fabiodalez.incitta.ui

internal fun markerDiameterDp(count: Int): Int = when {
    count >= 50 -> 64
    count >= 10 -> 60
    count > 1 -> 56
    else -> 48
}
