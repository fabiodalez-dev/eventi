package it.fabiodalez.incitta.data.management

import java.io.ByteArrayOutputStream
import kotlin.math.max
import kotlin.math.roundToInt

/*
 * Il ritaglio a proporzione fissa di logo (quadrato) e copertina (16:9),
 * decisione 5. Sul sito lo fa l'editor di Filament; nell'app la foto scorre e
 * si ingrandisce sotto una cornice fissa, e si carica solo la parte dentro la
 * cornice. La galleria non si ritaglia, come sul sito.
 *
 * Il calcolo è puro e si prova senza Android: l'immagine riempie sempre la
 * cornice (nessuna banda vuota), lo spostamento non la fa mai uscire, e il
 * rettangolo finale è in pixel dell'immagine.
 */

/** Le proporzioni fisse: larghezza / altezza. */
internal enum class CropShape(val ratio: Float) { Square(1f), Wide(16f / 9f) }

internal data class CropRect(val left: Int, val top: Int, val width: Int, val height: Int)

internal object ImageCrop {
    const val MAX_ZOOM = 4f

    /** La scala che fa coprire la cornice all'immagine intera (lato corto a filo). */
    fun baseScale(imageWidth: Int, imageHeight: Int, frameWidth: Float, frameHeight: Float): Float =
        max(frameWidth / imageWidth, frameHeight / imageHeight)

    /** Lo spostamento consentito su un asse: l'immagine non lascia mai scoperto il bordo della cornice. */
    fun clampOffset(offset: Float, imageSide: Int, scale: Float, frameSide: Float): Float {
        val limit = ((imageSide * scale - frameSide) / 2f).coerceAtLeast(0f)
        return offset.coerceIn(-limit, limit)
    }

    /**
     * Il rettangolo dell'immagine che sta dentro la cornice. `offsetX` e
     * `offsetY` sono lo spostamento del centro dell'immagine rispetto al
     * centro della cornice, in pixel dello schermo.
     */
    fun rect(imageWidth: Int, imageHeight: Int, frameWidth: Float, frameHeight: Float, zoom: Float, offsetX: Float, offsetY: Float): CropRect {
        val scale = baseScale(imageWidth, imageHeight, frameWidth, frameHeight) * zoom.coerceIn(1f, MAX_ZOOM)
        val x = clampOffset(offsetX, imageWidth, scale, frameWidth)
        val y = clampOffset(offsetY, imageHeight, scale, frameHeight)
        val width = (frameWidth / scale).roundToInt().coerceIn(1, imageWidth)
        val height = (frameHeight / scale).roundToInt().coerceIn(1, imageHeight)
        val left = ((imageWidth * scale / 2f - frameWidth / 2f - x) / scale).roundToInt().coerceIn(0, imageWidth - width)
        val top = ((imageHeight * scale / 2f - frameHeight / 2f - y) / scale).roundToInt().coerceIn(0, imageHeight - height)
        return CropRect(left, top, width, height)
    }
}

/**
 * Ritaglia un'immagine già preparata (`prepareImage`: lato lungo 2400 px,
 * rotazione EXIF applicata, JPEG) e la ricomprime. `null` se la decodifica non
 * riesce.
 */
internal fun cropPreparedImage(image: PreparedImage, rect: CropRect, quality: Int = ImageUpload.QUALITY): PreparedImage? {
    val source = try {
        android.graphics.BitmapFactory.decodeByteArray(image.bytes, 0, image.bytes.size)
    } catch (_: OutOfMemoryError) { null } ?: return null
    return try {
        val left = rect.left.coerceIn(0, source.width - 1)
        val top = rect.top.coerceIn(0, source.height - 1)
        val width = rect.width.coerceIn(1, source.width - left)
        val height = rect.height.coerceIn(1, source.height - top)
        val cropped = android.graphics.Bitmap.createBitmap(source, left, top, width, height)
        try {
            val bytes = ByteArrayOutputStream().use { out ->
                if (!cropped.compress(android.graphics.Bitmap.CompressFormat.JPEG, quality, out)) return null
                out.toByteArray()
            }
            PreparedImage(bytes, width, height)
        } finally {
            if (cropped !== source) cropped.recycle()
        }
    } catch (_: OutOfMemoryError) {
        null
    } finally {
        source.recycle()
    }
}
