package it.fabiodalez.incitta.data.management

import java.io.ByteArrayInputStream
import java.io.InputStream
import org.junit.Assert.*
import org.junit.Test

/* Riduzione e limiti delle immagini della gestione; la decodifica vera è finta. */
class ImageUploadTest {
    private class FakeCodec(var bounds: ImageBounds?, var output: ByteArray? = ByteArray(1000)) : ImageCodec {
        var plan: ImagePlan? = null
        var quality: Int? = null
        override fun bounds(bytes: ByteArray) = bounds
        override fun reencode(bytes: ByteArray, plan: ImagePlan, quality: Int): ByteArray? { this.plan = plan; this.quality = quality; return output }
    }

    private fun reason(block: () -> Unit): ImageUploadException.Reason =
        try { block(); fail("doveva rifiutare"); throw IllegalStateException() } catch (e: ImageUploadException) { e.reason }

    @Test fun aPhoneLandscapePhotoShrinksToTheLongSideAndJpeg85() {
        val codec = FakeCodec(ImageBounds(4000, 3000))
        val image = ImageUpload(codec).prepare(ByteArray(10))
        assertEquals(ImagePlan(sampleSize = 1, width = 2400, height = 1800, rotation = 0), codec.plan)
        assertEquals(85, codec.quality)
        assertEquals(2400, image.width); assertEquals(1800, image.height)
        assertEquals("image/jpeg", image.mime)
        assertTrue(image.fileName.endsWith(".jpg"))
    }

    @Test fun aPortraitPhotoKeepsItsProportions() {
        val plan = ImageUpload.plan(ImageBounds(3024, 4032))
        assertEquals(1800, plan.width); assertEquals(2400, plan.height)
    }

    @Test fun aSmallImageIsNeverEnlarged() {
        val plan = ImageUpload.plan(ImageBounds(800, 600))
        assertEquals(ImagePlan(1, 800, 600, 0), plan)
    }

    @Test fun aHugeImageIsReducedAlreadyWhileDecoding() {
        assertEquals(2, ImageUpload.plan(ImageBounds(8000, 6000)).sampleSize)
        assertEquals(4, ImageUpload.plan(ImageBounds(12000, 9600)).sampleSize)
        assertEquals(1, ImageUpload.plan(ImageBounds(4000, 3000)).sampleSize)
    }

    @Test fun exifRotationSwapsTheShownSidesOnly() {
        val codec = FakeCodec(ImageBounds(4000, 3000, rotation = 90))
        val image = ImageUpload(codec).prepare(ByteArray(10))
        assertEquals(2400, codec.plan!!.width)
        assertEquals(90, codec.plan!!.rotation)
        assertEquals(1800, image.width); assertEquals(2400, image.height)
        assertEquals(270, ImageUpload.plan(ImageBounds(10, 10, rotation = -90)).rotation)
    }

    @Test fun emptyOrUnknownFilesAreRejectedAsTheUsersToFix() {
        assertEquals(ImageUploadException.Reason.Unreadable, reason { ImageUpload(FakeCodec(ImageBounds(10, 10))).prepare(ByteArray(0)) })
        assertEquals(ImageUploadException.Reason.NotAnImage, reason { ImageUpload(FakeCodec(null)).prepare(ByteArray(10)) })
        assertEquals(ImageUploadException.Reason.NotAnImage, reason { ImageUpload(FakeCodec(ImageBounds(0, 10))).prepare(ByteArray(10)) })
        assertEquals(ImageUploadException.Reason.Unreadable, reason { ImageUpload(FakeCodec(ImageBounds(10, 10), output = null)).prepare(ByteArray(10)) })
    }

    @Test fun limitsFollowTheServer() {
        assertEquals(ImageUploadException.Reason.TooManyPixels, reason { ImageUpload(FakeCodec(ImageBounds(10_000, 5_001))).prepare(ByteArray(10)) })
        ImageUpload(FakeCodec(ImageBounds(10_000, 5_000))).prepare(ByteArray(10))
        assertEquals(ImageUploadException.Reason.TooLarge, reason { ImageUpload(FakeCodec(ImageBounds(10, 10), output = ByteArray(ImageUpload.MAX_UPLOAD_BYTES + 1))).prepare(ByteArray(10)) })
        assertEquals(ImageUploadException.Reason.TooLarge, reason { ImageUpload(FakeCodec(ImageBounds(10, 10))).prepare(ByteArray(ImageUpload.MAX_SOURCE_BYTES + 1)) })
    }

    @Test fun anEndlessSourceStopsAtTheReadLimit() {
        var consumed = 0L
        val endless = object : InputStream() { override fun read(): Int { consumed++; return 1 } }
        assertEquals(ImageUploadException.Reason.TooLarge, reason { ImageUpload(FakeCodec(ImageBounds(10, 10))).prepare(endless) })
        assertEquals(ImageUpload.MAX_SOURCE_BYTES + 1L, consumed)
        assertEquals(10, ImageUpload(FakeCodec(ImageBounds(10, 10))).prepare(ByteArrayInputStream(ByteArray(5))).width)
        val broken = object : InputStream() { override fun read(): Int = throw java.io.IOException("rotto") }
        assertEquals(ImageUploadException.Reason.Unreadable, reason { ImageUpload(FakeCodec(ImageBounds(10, 10))).prepare(broken) })
    }
}
