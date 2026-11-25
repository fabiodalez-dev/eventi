package it.fabiodalez.incitta.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedCard
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R

/**
 * «Gestisci questo locale?», come la card del sito (`x-claim-card`): l'invito
 * a rivendicare un locale pubblico che nessuno gestisce ancora. Un pulsante
 * vero, non un collegamento in piccolo. Da collegati apre il modulo nativo
 * (P13); un ospite prima accede (decisione 8 del piano).
 */
@Composable
internal fun VenueClaimCard(venueName: String, signedIn: Boolean, modifier: Modifier = Modifier, onLogin: () -> Unit, onClaim: () -> Unit) {
    OutlinedCard(modifier.fillMaxWidth(), border = BorderStroke(1.dp, Acid)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.claim_card_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            Text(stringResource(R.string.claim_card_description, venueName), color = Muted)
            Button(onClick = if (signedIn) onClaim else onLogin, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(if (signedIn) R.string.claim_card_action else R.string.claim_card_login))
            }
        }
    }
}
