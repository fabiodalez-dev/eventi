package it.fabiodalez.incitta.ui

import android.app.AlertDialog
import android.view.ContextThemeWrapper
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp

/** Android's single-choice dialog, including radio selection, dismissal and accessibility. */
@Composable
internal fun NativeChoicePicker(
    title: String,
    value: String,
    options: List<Pair<String, String>>,
    enabled: Boolean = true,
    onSelect: (String) -> Unit,
) {
    val context = LocalContext.current
    var open by remember { mutableStateOf(false) }
    val latestSelect by rememberUpdatedState(onSelect)
    val light = isLightTheme
    OutlinedButton(onClick = { open = true }, enabled = enabled, shape = ControlShape,
        modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(options.firstOrNull { it.first == value }?.second.orEmpty())
    }
    if (open) DisposableEffect(context, title, value, options, light) {
        val themed = ContextThemeWrapper(context, if (light) android.R.style.Theme_Material_Light_Dialog_Alert else android.R.style.Theme_Material_Dialog_Alert)
        val dialog = AlertDialog.Builder(themed)
            .setTitle(title)
            .setSingleChoiceItems(options.map { it.second }.toTypedArray(), options.indexOfFirst { it.first == value }) { _, index ->
                latestSelect(options[index].first)
                open = false
            }
            .setNegativeButton(android.R.string.cancel) { _, _ -> open = false }
            .setOnDismissListener { open = false }
            .create()
        dialog.show()
        onDispose { dialog.dismiss() }
    }
}
