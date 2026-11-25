package it.fabiodalez.incitta.ui.management.social

import android.content.ContentValues
import android.content.Context
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import java.io.File

/**
 * Le grafiche scaricate dal server: nelle immagini del telefono (da Android
 * 10, senza permessi, nella cartella «inCitta» come i QR delle statistiche)
 * oppure condivise dalla cache dell'app. Sotto Android 10 resta solo
 * «Condividi»: salvare lì chiederebbe il permesso di scrivere su tutta la
 * memoria.
 */
internal object SocialImages {
    val canSave: Boolean get() = Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q

    /** Copia il JPEG fra le immagini; `false` se non riesce (la voce a metà si toglie). */
    fun save(context: Context, file: File, name: String): Boolean {
        if (!canSave) return false
        val resolver = context.contentResolver
        val values = ContentValues().apply {
            put(MediaStore.Images.Media.DISPLAY_NAME, "$name.jpg")
            put(MediaStore.Images.Media.MIME_TYPE, "image/jpeg")
            put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/inCitta")
            put(MediaStore.Images.Media.IS_PENDING, 1)
        }
        val uri = runCatching { resolver.insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, values) }.getOrNull() ?: return false
        val written = runCatching {
            resolver.openOutputStream(uri)?.use { output -> file.inputStream().use { input -> input.copyTo(output) } } != null
        }.getOrDefault(false)
        if (!written) {
            runCatching { resolver.delete(uri, null, null) }
            return false
        }
        values.clear()
        values.put(MediaStore.Images.Media.IS_PENDING, 0)
        runCatching { resolver.update(uri, values, null, null) }
        return true
    }

    fun share(context: Context, file: File, mime: String, chooser: String): Boolean = SharedFiles.share(context, file, mime, chooser)
}
