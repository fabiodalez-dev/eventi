package it.fabiodalez.incitta.ui.management.common

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ImageUploadException

/**
 * Il selettore di foto di sistema: nessun permesso da chiedere, la persona
 * sceglie una sola immagine e l'app vede solo quella. Restituisce la funzione
 * che apre il selettore. La preparazione (`prepareImage`) e l'invio li fa il
 * ViewModel della schermata che lo usa (locandina in P1, profilo in P4).
 */
@Composable
internal fun rememberImagePicker(onPicked: (Uri) -> Unit): () -> Unit {
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let(onPicked) }
    return { launcher.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }
}

/** Il testo per un'immagine che non si può caricare, da mostrare sotto il campo. */
internal fun imageErrorText(reason: ImageUploadException.Reason): Int = when (reason) {
    ImageUploadException.Reason.Unreadable -> R.string.management_image_unreadable
    ImageUploadException.Reason.NotAnImage -> R.string.management_image_not_image
    ImageUploadException.Reason.TooLarge -> R.string.management_image_too_large
    ImageUploadException.Reason.TooManyPixels -> R.string.management_image_too_many_pixels
}
