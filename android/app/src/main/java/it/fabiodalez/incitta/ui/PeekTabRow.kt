package it.fabiodalez.incitta.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.rememberScrollState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp

/** Natural-width labels; reserve a visible fragment of the next overflowing tab. */
@Composable
internal fun PeekTabRow(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    BoxWithConstraints(modifier.fillMaxWidth()) {
        val viewport = with(LocalDensity.current) { maxWidth.roundToPx() }
        val gap = with(LocalDensity.current) { 8.dp.roundToPx() }
        val peek = with(LocalDensity.current) { 28.dp.roundToPx() }
        Layout(content, Modifier.horizontalScroll(rememberScrollState())) { children, constraints ->
            val items = children.map { it.measure(constraints.copy(minWidth = 0, maxWidth = Constraints.Infinity, minHeight = 0)) }
            val natural = items.sumOf { it.width } + gap * (items.size - 1).coerceAtLeast(0)
            var prefix = 0
            var used = 0
            if (natural > viewport) {
                for (item in items) {
                    if (used + item.width + gap > viewport - peek) break
                    used += item.width + gap
                    prefix++
                }
            }
            val extra = if (prefix > 0) (viewport - peek - used).coerceAtLeast(0) else 0
            layout(natural + extra, items.maxOfOrNull { it.height } ?: 0) {
                var x = 0
                items.forEachIndexed { index, item ->
                    item.placeRelative(x, 0)
                    x += item.width + gap
                    if (index == prefix - 1) x += extra
                }
            }
        }
    }
}
