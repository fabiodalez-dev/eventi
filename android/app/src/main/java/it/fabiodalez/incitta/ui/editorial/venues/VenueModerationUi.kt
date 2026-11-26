package it.fabiodalez.incitta.ui.editorial.venues

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ValueLabel
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog

/*
 * La moderazione di un locale (R-VN-07…10), uguale dalla riga dell'elenco e
 * dalla scheda: approva e verifica con una conferma, rifiuta e sospendi con
 * il motivo obbligatorio. Le azioni compaiono solo se `abilities` le
 * permette, con le stesse condizioni di stato del pannello.
 */

/** Le azioni di moderazione, con i nomi delle abilità del server. */
internal enum class VenueAction(val key: String) { Approve("approve"), Verify("verify"), Suspend("suspend"), Reject("reject") }

@Composable
internal fun venueRowActions(verified: Boolean): List<RowAction> = listOf(
    RowAction(VenueAction.Approve.key, stringResource(R.string.editorial_venue_approve)),
    RowAction(VenueAction.Verify.key, stringResource(if (verified) R.string.editorial_venue_unverify else R.string.editorial_venue_verify)),
    RowAction(VenueAction.Suspend.key, stringResource(R.string.editorial_venue_suspend), destructive = true),
    RowAction(VenueAction.Reject.key, stringResource(R.string.editorial_venue_reject), destructive = true),
)

/** Un'azione in attesa di conferma: su quale locale e quale. */
internal data class PendingVenueAction(val venueId: Long, val venueName: String, val action: VenueAction, val verified: Boolean)

/**
 * Le finestre di conferma della moderazione. `onConfirm` riceve il motivo
 * (vuoto per approva e verifica).
 */
@Composable
internal fun VenueActionDialog(pending: PendingVenueAction, busy: Boolean, error: String?, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    /* Il pannello chiede il motivo senza un minimo: qui basta un carattere, fino al tetto della colonna. */
    val rules = ReasonRules(min = 1, max = REASON_MAX)
    when (pending.action) {
        VenueAction.Approve -> ConfirmDialog(
            title = stringResource(R.string.editorial_venue_approve_title, pending.venueName),
            text = stringResource(R.string.editorial_venue_approve_text),
            confirmLabel = stringResource(R.string.editorial_venue_approve),
            enabled = !busy,
            onConfirm = { onConfirm("") },
            onDismiss = onDismiss,
        )
        VenueAction.Verify -> ConfirmDialog(
            title = stringResource(if (pending.verified) R.string.editorial_venue_unverify else R.string.editorial_venue_verify),
            text = stringResource(if (pending.verified) R.string.editorial_venue_unverify_text else R.string.editorial_venue_verify_text, pending.venueName),
            confirmLabel = stringResource(if (pending.verified) R.string.editorial_venue_unverify else R.string.editorial_venue_verify),
            enabled = !busy,
            onConfirm = { onConfirm("") },
            onDismiss = onDismiss,
        )
        VenueAction.Suspend -> ReasonDialog(
            title = stringResource(R.string.editorial_venue_suspend_title, pending.venueName),
            text = stringResource(R.string.editorial_venue_suspend_text),
            confirmLabel = stringResource(R.string.editorial_venue_suspend),
            fieldLabel = stringResource(R.string.editorial_venue_suspend_reason),
            rules = rules,
            destructive = true,
            busy = busy,
            error = error,
            onConfirm = onConfirm,
            onDismiss = onDismiss,
        )
        VenueAction.Reject -> ReasonDialog(
            title = stringResource(R.string.editorial_venue_reject_title, pending.venueName),
            text = stringResource(R.string.editorial_venue_reject_text),
            confirmLabel = stringResource(R.string.editorial_venue_reject),
            fieldLabel = stringResource(R.string.editorial_venue_reject_reason),
            rules = rules,
            destructive = true,
            busy = busy,
            error = error,
            onConfirm = onConfirm,
            onDismiss = onDismiss,
        )
    }
}

/* `rejection_reason` è una colonna `TEXT`. */
internal const val REASON_MAX = 65535

/** Lo stato del locale come etichetta colorata: approvato verde, in attesa ambra, sospeso o rifiutato rosso. */
@Composable
internal fun StatusLabel(status: ValueLabel, modifier: Modifier = Modifier) {
    val color = when (status.value) {
        "approved", "published" -> Color(0xFF1B7F3B)
        "pending" -> Color(0xFF9A5B00)
        "rejected", "suspended", "cancelled" -> MaterialTheme.colorScheme.error
        else -> MaterialTheme.colorScheme.onSurfaceVariant
    }
    Text(
        status.label.ifBlank { status.value },
        color = color,
        style = MaterialTheme.typography.labelLarge,
        modifier = modifier.background(MaterialTheme.colorScheme.surfaceVariant, MaterialTheme.shapes.small).padding(horizontal = 8.dp, vertical = 2.dp),
    )
}
