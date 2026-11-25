package it.fabiodalez.incitta.ui.management.common

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource

/**
 * Un testo deciso dal ViewModel e risolto dalla schermata: il ViewModel non
 * tiene un `Context`, e un messaggio già tradotto non sopravvivrebbe a un
 * cambio di lingua. Gli errori del server arrivano già in italiano (`Raw`).
 */
internal sealed interface UiText {
    data class Raw(val text: String) : UiText
    data class Res(val id: Int, val args: List<Any> = emptyList()) : UiText
    data class Plural(val id: Int, val count: Int, val args: List<Any> = emptyList()) : UiText
}

@Composable
internal fun UiText.resolve(): String = when (this) {
    is UiText.Raw -> text
    is UiText.Res -> stringResource(id, *args.toTypedArray())
    is UiText.Plural -> pluralStringResource(id, count, *args.toTypedArray())
}
