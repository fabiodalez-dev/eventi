package it.fabiodalez.incitta.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.User

/** Identity is visible before tickets and preferences, including with large fonts. */
@Composable
fun AccountIdentity(user: User, onChangeAccount: () -> Unit, enabled: Boolean = true) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.profile_signed_in), color = Acid, style = MaterialTheme.typography.labelLarge)
        user.name?.trim()?.takeIf { it.isNotEmpty() }?.let {
            Text(it, color = Paper, style = MaterialTheme.typography.headlineSmall)
        }
        SelectionContainer {
            Text(user.email, modifier = Modifier.fillMaxWidth(), color = Paper,
                style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        }
        user.roleLabel?.takeIf { it.isNotBlank() }?.let {
            Text(stringResource(R.string.profile_role, it), color = Muted, style = MaterialTheme.typography.bodyMedium)
        }
        OutlinedButton(onClick = onChangeAccount, enabled = enabled,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp), shape = RectangleShape,
            border = BorderStroke(2.dp, Rule)) {
            Text(stringResource(R.string.profile_change_account))
        }
        HorizontalDivider(Modifier.padding(top = 10.dp), color = Rule, thickness = 2.dp)
    }
}
