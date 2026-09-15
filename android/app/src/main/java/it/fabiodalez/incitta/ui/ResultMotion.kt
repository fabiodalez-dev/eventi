package it.fabiodalez.incitta.ui

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.State
import androidx.compose.runtime.remember

/** Cancellable result transitions; Compose honors the system animator duration scale. */
@Composable
internal fun rememberResultsAlpha(loading: Boolean, identity: Any): State<Float> {
    val alpha = remember { Animatable(1f) }
    val previous = remember { arrayOf(identity) }
    LaunchedEffect(loading, identity) {
        if (loading) {
            alpha.animateTo(0.6f, tween(120, easing = LinearOutSlowInEasing))
        } else {
            if (previous[0] != identity) alpha.snapTo(0.65f)
            previous[0] = identity
            alpha.animateTo(1f, tween(200, easing = LinearOutSlowInEasing))
        }
    }
    return alpha.asState()
}
