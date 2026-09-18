package it.fabiodalez.incitta.data

import java.io.ByteArrayInputStream
import java.io.InputStream
import org.junit.Assert.*
import org.junit.Test

class CommunityAvatarInputTest {
    private val limit = 2 * 1024 * 1024

    @Test fun oversizedProviderStopsAfterTheSentinelByte() {
        var consumed = 0
        val unbounded = object : InputStream() {
            override fun read(): Int { consumed++; return 42 }
        }
        assertEquals(limit + 1, unbounded.readAvatarBytes().size)
        assertEquals(limit + 1, consumed)
    }

    @Test fun exactLimitAndEmptyFilesAreReadWithoutTruncatingValidBytes() {
        val valid = ByteArray(limit) { (it % 251).toByte() }
        assertArrayEquals(valid, ByteArrayInputStream(valid).readAvatarBytes())
        assertArrayEquals(byteArrayOf(), ByteArrayInputStream(byteArrayOf()).readAvatarBytes())
    }

    @Test fun ShortReadsAndZeroLengthChunksCannotLoseDataOrLoopForever() {
        val expected = ByteArray(37) { it.toByte() }
        val source = object : ByteArrayInputStream(expected) {
            private var zero = true
            override fun read(bytes: ByteArray, offset: Int, length: Int): Int {
                if (zero) { zero = false; return 0 }
                return super.read(bytes, offset, minOf(length, 3))
            }
        }
        assertArrayEquals(expected, source.readAvatarBytes())
    }
}
