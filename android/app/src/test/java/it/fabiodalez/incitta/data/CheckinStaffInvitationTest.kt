package it.fabiodalez.incitta.data

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class CheckinStaffInvitationTest {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; isLenient = true }

    @Test fun `pending invitation is read from the status field`() {
        val staff = json.decodeFromString<List<CheckinStaff>>(
            """[{"id":1,"name":"a@example.org","email":"a@example.org","status":"accepted"},{"id":2,"name":"b@example.org","email":"b@example.org","status":"pending"}]""",
        )
        assertFalse(staff[0].pending)
        assertTrue(staff[1].pending)
        assertEquals("b@example.org", staff[1].email)
    }

    @Test fun `older servers without status count as accepted staff`() {
        val member = json.decodeFromString<CheckinStaff>("""{"id":7,"name":"Ada","email":"ada@example.org"}""")
        assertFalse(member.pending)
    }
}
