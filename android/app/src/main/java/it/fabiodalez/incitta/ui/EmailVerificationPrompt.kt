package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R

/**
 * L'invito a confermare l'email quando il server risponde 403
 * `EMAIL_VERIFICATION_REQUIRED`. È lo stesso schema dei commenti e della
 * community: il messaggio sta sopra (quello del server), qui ci sono solo il
 * reinvio della conferma e «ho confermato», che rimette la persona davanti
 * all'azione invece di bloccarla su uno stato vecchio del profilo.
 */
@Composable
internal fun EmailVerificationPrompt(busy: Boolean, onResend: () -> Unit, onConfirmed: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        OutlinedButton(onClick = onResend, enabled = !busy, shape = ControlShape, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.email_verification_resend)) }
        TextButton(onClick = onConfirmed, enabled = !busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.email_verification_done)) }
    }
}
