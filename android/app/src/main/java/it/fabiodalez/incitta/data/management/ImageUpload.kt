package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.readAtMost
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.io.InputStream
import kotlin.math.max
import kotlin.math.roundToInt

/*
 * Prima di caricare una locandina, un logo o una foto del locale l'app riduce
 * il lato lungo a 2400 px e ricomprime in JPEG qualità 85: una foto da
 * telefono scende sotto 1,5 MB, la quota del server e la rete ringraziano, e
 * il server riceve già la misura che conserverebbe (`media.max_original_width`).
 * La ricompressione toglie anche i dati EXIF, compresa la posizione.
 *
 * La parte di calcolo (misure, limiti) è pura e si prova senza Android; la
 * decodifica vera passa da `ImageCodec`, che sul telefono è `AndroidImageCodec`.
 */

/** Un'immagine pronta per il campo multipart `image`. */
internal class PreparedImage(val bytes: ByteArray, val width: Int, val height: Int) {
    val mime: String get() = ImageUpload.MIME
    val fileName: String get() = "image.jpg"
}

/** Le misure lette dall'intestazione, senza decodificare i pixel. `rotation` viene dall'EXIF. */
internal data class ImageBounds(val width: Int, val height: Int, val rotation: Int = 0)

/** Come decodificare: `sampleSize` è la riduzione in lettura (potenza di 2), poi si scala alla misura finale. */
internal data class ImagePlan(val sampleSize: Int, val width: Int, val height: Int, val rotation: Int) {
    /** Le misure dopo la rotazione, cioè come le vedrà chi guarda. */
    val shownWidth: Int get() = if (rotation % 180 == 0) width else height
    val shownHeight: Int get() = if (rotation % 180 == 0) height else width
}

internal interface ImageCodec {
    /** `null` se i byte non sono un'immagine che il telefono sa leggere. */
    fun bounds(bytes: ByteArray): ImageBounds?
    /** `null` se la decodifica non riesce (memoria, file rovinato). */
    fun reencode(bytes: ByteArray, plan: ImagePlan, quality: Int): ByteArray?
}

internal class ImageUploadException(val reason: Reason) : Exception(reason.name) {
    enum class Reason { Unreadable, NotAnImage, TooLarge, TooManyPixels }
}

internal class ImageUpload(private val codec: ImageCodec) {
    fun prepare(source: InputStream): PreparedImage {
        val bytes = try { source.readAtMost(MAX_SOURCE_BYTES) } catch (_: java.io.IOException) { throw ImageUploadException(ImageUploadException.Reason.Unreadable) }
        return prepare(bytes)
    }

    fun prepare(bytes: ByteArray): PreparedImage {
        if (bytes.isEmpty()) throw ImageUploadException(ImageUploadException.Reason.Unreadable)
        if (bytes.size > MAX_SOURCE_BYTES) throw ImageUploadException(ImageUploadException.Reason.TooLarge)
        val bounds = codec.bounds(bytes)?.takeIf { it.width > 0 && it.height > 0 }
            ?: throw ImageUploadException(ImageUploadException.Reason.NotAnImage)
        if (bounds.width.toLong() * bounds.height.toLong() > MAX_PIXELS) throw ImageUploadException(ImageUploadException.Reason.TooManyPixels)
        val plan = plan(bounds)
        val encoded = codec.reencode(bytes, plan, QUALITY) ?: throw ImageUploadException(ImageUploadException.Reason.Unreadable)
        if (encoded.size > MAX_UPLOAD_BYTES) throw ImageUploadException(ImageUploadException.Reason.TooLarge)
        return PreparedImage(encoded, plan.shownWidth, plan.shownHeight)
    }

    companion object {
        const val MIME = "image/jpeg"
        /** Il lato lungo massimo, lo stesso di `config/media.php` → `max_original_width`. */
        const val MAX_SIDE = 2400
        const val QUALITY = 85
        /** Il tetto del server (`config/media.php` → `max_upload_bytes`). */
        const val MAX_UPLOAD_BYTES = 12 * 1024 * 1024
        /** Quanto si legge al massimo dal telefono: l'originale può superare il tetto, la copia ridotta no. */
        const val MAX_SOURCE_BYTES = 40 * 1024 * 1024
        /** Lo stesso tetto di `config/media.php` → `max_pixels`. */
        const val MAX_PIXELS = 50_000_000L

        /** Le misure finali: il lato lungo a 2400 al massimo, mai ingrandire, proporzioni conservate. */
        fun plan(bounds: ImageBounds): ImagePlan {
            val longSide = max(bounds.width, bounds.height)
            val scale = if (longSide > MAX_SIDE) MAX_SIDE.toDouble() / longSide else 1.0
            val width = (bounds.width * scale).roundToInt().coerceAtLeast(1)
            val height = (bounds.height * scale).roundToInt().coerceAtLeast(1)
            return ImagePlan(sampleSize(bounds.width, bounds.height, width, height), width, height, normalizedRotation(bounds.rotation))
        }

        /** La riduzione più forte in lettura che lascia l'immagine almeno grande quanto la misura finale. */
        fun sampleSize(width: Int, height: Int, targetWidth: Int, targetHeight: Int): Int {
            var sample = 1
            while (width / (sample * 2) >= targetWidth && height / (sample * 2) >= targetHeight) sample *= 2
            return sample
        }

        private fun normalizedRotation(degrees: Int): Int = ((degrees % 360) + 360) % 360 / 90 * 90
    }
}

/** La decodifica vera, con `BitmapFactory`, la rotazione EXIF (le immagini specchiate restano come sono) e lo sfondo bianco per la trasparenza. */
internal object AndroidImageCodec : ImageCodec {
    override fun bounds(bytes: ByteArray): ImageBounds? {
        val options = android.graphics.BitmapFactory.Options().apply { inJustDecodeBounds = true }
        android.graphics.BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)
        if (options.outWidth <= 0 || options.outHeight <= 0) return null
        val rotation = runCatching {
            when (android.media.ExifInterface(ByteArrayInputStream(bytes)).getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL)) {
                android.media.ExifInterface.ORIENTATION_ROTATE_90 -> 90
                android.media.ExifInterface.ORIENTATION_ROTATE_180 -> 180
                android.media.ExifInterface.ORIENTATION_ROTATE_270 -> 270
                else -> 0
            }
        }.getOrDefault(0)
        return ImageBounds(options.outWidth, options.outHeight, rotation)
    }

    override fun reencode(bytes: ByteArray, plan: ImagePlan, quality: Int): ByteArray? {
        val options = android.graphics.BitmapFactory.Options().apply { inSampleSize = plan.sampleSize }
        val decoded = try {
            android.graphics.BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)
        } catch (_: OutOfMemoryError) { null } ?: return null
        var bitmap = decoded
        try {
            if (bitmap.width != plan.width || bitmap.height != plan.height) {
                bitmap = android.graphics.Bitmap.createScaledBitmap(decoded, plan.width, plan.height, true).also { if (it !== decoded) decoded.recycle() }
            }
            if (plan.rotation != 0) {
                val matrix = android.graphics.Matrix().apply { postRotate(plan.rotation.toFloat()) }
                val rotated = android.graphics.Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
                if (rotated !== bitmap) bitmap.recycle()
                bitmap = rotated
            }
            /* Il JPEG non ha trasparenza: un logo PNG trasparente diventerebbe nero. */
            if (bitmap.hasAlpha()) {
                val flat = android.graphics.Bitmap.createBitmap(bitmap.width, bitmap.height, android.graphics.Bitmap.Config.ARGB_8888)
                android.graphics.Canvas(flat).apply { drawColor(android.graphics.Color.WHITE); drawBitmap(bitmap, 0f, 0f, null) }
                bitmap.recycle()
                bitmap = flat
            }
            return ByteArrayOutputStream().use { out ->
                if (!bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, quality, out)) null else out.toByteArray()
            }
        } catch (_: OutOfMemoryError) {
            return null
        } finally {
            bitmap.recycle()
        }
    }
}

/**
 * Legge l'immagine scelta con il selettore di sistema e la prepara. Da
 * chiamare fuori dal thread principale. Un file illeggibile o un permesso
 * revocato diventano `Unreadable`, non un errore di rete.
 */
internal fun prepareImage(context: android.content.Context, uri: android.net.Uri, codec: ImageCodec = AndroidImageCodec): PreparedImage {
    val stream = try { context.contentResolver.openInputStream(uri) }
        catch (_: java.io.IOException) { null } catch (_: SecurityException) { null }
        ?: throw ImageUploadException(ImageUploadException.Reason.Unreadable)
    return stream.use { ImageUpload(codec).prepare(it) }
}
