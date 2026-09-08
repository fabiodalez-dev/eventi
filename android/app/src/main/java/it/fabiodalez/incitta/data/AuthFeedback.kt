package it.fabiodalez.incitta.data

import it.fabiodalez.incitta.R
import java.io.IOException

/** Never reveal whether an email belongs to an account. */
internal fun loginFailureMessage(error: Throwable): Int = when {
    error is ApiException && error.status in listOf(401, 422) -> R.string.auth_invalid_credentials
    error is ApiException && error.status == 429 -> R.string.auth_rate_limited
    error is ApiException && error.status >= 500 -> R.string.auth_server_unavailable
    error is IOException -> R.string.auth_connection_failed
    else -> R.string.auth_failed
}

internal fun authValidationMessage(email: String, password: String, registering: Boolean, magic: Boolean = false): Int? = when {
    email.isBlank() -> R.string.auth_email_required
    !Regex("^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$").matches(email.trim()) -> R.string.auth_email_invalid
    !magic && password.isEmpty() -> R.string.auth_password_required
    !magic && registering && password.length < 8 -> R.string.auth_password_short
    else -> null
}
