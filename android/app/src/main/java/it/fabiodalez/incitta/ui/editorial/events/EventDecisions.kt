package it.fabiodalez.incitta.ui.editorial.events

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CancelImpact
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.cityIso
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Le decisioni su un evento, con le stesse conferme del pannello: un sì per
 * pubblicare, togliere, duplicare, eliminare e ripristinare; il motivo per
 * rifiutare; la nota facoltativa e il conteggio di chi riceverà l'avviso per
 * annullare; la scadenza facoltativa per l'evidenza. Le usano l'elenco e la
 * scheda.
 */

/** Le azioni della riga, nell'ordine del menu del pannello (`EventModeration::rowActions` più «Unisci» ed «Elimina»). */
internal object EventActionKeys {
    const val OPEN = "view"
    const val PUBLISH = "publish"
    const val UNPUBLISH = "unpublish"
    const val REJECT = "reject"
    const val CANCEL = "cancel"
    const val FEATURE = "feature"
    const val DUPLICATE = "duplicate"
    const val MERGE = "merge"
    const val DESTROY = "destroy"
    const val RESTORE = "restore"
    val ROW = listOf(OPEN, PUBLISH, UNPUBLISH, REJECT, CANCEL, FEATURE, DUPLICATE, MERGE, DESTROY, RESTORE)
    val DESTRUCTIVE = setOf(REJECT, CANCEL, DESTROY)
}

/** Le voci del menu di una riga, con le etichette che dipendono dallo stato (evidenza, programmazione). */
internal fun eventRowActions(label: (String) -> String): List<RowAction> =
    EventActionKeys.ROW.map { key -> RowAction(key, label(key), destructive = key in EventActionKeys.DESTRUCTIVE) }

@Composable
internal fun eventActionLabel(key: String, featured: Boolean, approvesSchedule: Boolean = false): String = when (key) {
    EventActionKeys.OPEN -> stringResource(R.string.editorial_events_action_open)
    EventActionKeys.PUBLISH -> stringResource(if (approvesSchedule) R.string.editorial_events_action_approve_schedule else R.string.editorial_events_action_publish)
    EventActionKeys.UNPUBLISH -> stringResource(R.string.editorial_events_action_unpublish)
    EventActionKeys.REJECT -> stringResource(R.string.editorial_events_action_reject)
    EventActionKeys.CANCEL -> stringResource(R.string.editorial_events_action_cancel)
    EventActionKeys.FEATURE -> stringResource(if (featured) R.string.editorial_events_action_unfeature else R.string.editorial_events_action_feature)
    EventActionKeys.DUPLICATE -> stringResource(R.string.editorial_events_action_duplicate)
    EventActionKeys.MERGE -> stringResource(R.string.editorial_events_action_merge)
    EventActionKeys.DESTROY -> stringResource(R.string.editorial_events_action_destroy)
    EventActionKeys.RESTORE -> stringResource(R.string.editorial_events_action_restore)
    else -> key
}

/** Il motivo di un rifiuto: obbligatorio, fino al tetto della colonna (il pannello non ne mette altri). */
internal val EVENT_REJECT_RULES = ReasonRules(min = 1, max = 16000)

/** La nota di un annullamento: facoltativa, al massimo 255 caratteri come la colonna delle date. */
internal const val CANCEL_NOTE_MAX = 255

/**
 * La conferma della decisione `key` sull'evento. `impact` dà i numeri di
 * «Annulla l'evento» quando si conoscono (dalla scheda); dall'elenco il
 * testo dice che i numeri sono nella scheda.
 */
@Composable
internal fun EventDecisionDialog(
    key: String,
    featured: Boolean,
    approvesSchedule: Boolean,
    zone: ZoneId,
    cityName: String?,
    impact: CancelImpact?,
    busy: Boolean,
    onDecision: (EventDecision) -> Unit,
    onDismiss: () -> Unit,
) {
    val confirm = stringResource(R.string.editorial_events_confirm)
    when (key) {
        EventActionKeys.PUBLISH -> ConfirmDialog(
            title = stringResource(R.string.editorial_events_publish_title),
            text = stringResource(if (approvesSchedule) R.string.editorial_events_approve_text else R.string.editorial_events_publish_text),
            confirmLabel = eventActionLabel(key, featured, approvesSchedule),
            onConfirm = { onDecision(EventDecision.Publish) }, onDismiss = onDismiss, enabled = !busy,
        )
        EventActionKeys.UNPUBLISH -> ConfirmDialog(
            title = stringResource(R.string.editorial_events_unpublish_title),
            text = stringResource(R.string.editorial_events_unpublish_text),
            confirmLabel = confirm, onConfirm = { onDecision(EventDecision.Unpublish) }, onDismiss = onDismiss, enabled = !busy,
        )
        EventActionKeys.REJECT -> ReasonDialog(
            title = stringResource(R.string.editorial_events_reject_title),
            text = stringResource(R.string.editorial_events_reject_text),
            confirmLabel = stringResource(R.string.editorial_events_action_reject),
            onConfirm = { onDecision(EventDecision.Reject(it)) }, onDismiss = onDismiss,
            rules = EVENT_REJECT_RULES, destructive = true, busy = busy,
            fieldLabel = stringResource(R.string.editorial_events_reject_field),
        )
        EventActionKeys.CANCEL -> CancelEventDialog(impact, busy, onConfirm = { onDecision(EventDecision.Cancel(it)) }, onDismiss = onDismiss)
        EventActionKeys.FEATURE -> if (featured) {
            ConfirmDialog(
                title = stringResource(R.string.editorial_events_unfeature_title),
                text = stringResource(R.string.editorial_events_unfeature_text),
                confirmLabel = confirm, onConfirm = { onDecision(EventDecision.Feature(false, null)) }, onDismiss = onDismiss, enabled = !busy,
            )
        } else {
            FeatureDialog(zone, cityName, busy, onConfirm = { until -> onDecision(EventDecision.Feature(true, until?.let { cityIso(it, zone) })) }, onDismiss = onDismiss)
        }
        EventActionKeys.DUPLICATE -> ConfirmDialog(
            title = stringResource(R.string.editorial_events_duplicate_title),
            text = stringResource(R.string.editorial_events_duplicate_text),
            confirmLabel = confirm, onConfirm = { onDecision(EventDecision.Duplicate) }, onDismiss = onDismiss, enabled = !busy,
        )
        EventActionKeys.DESTROY -> ConfirmDialog(
            title = stringResource(R.string.editorial_events_destroy_title),
            text = stringResource(R.string.editorial_events_destroy_text),
            confirmLabel = stringResource(R.string.editorial_events_action_destroy),
            onConfirm = { onDecision(EventDecision.Destroy) }, onDismiss = onDismiss, destructive = true, enabled = !busy,
        )
        EventActionKeys.RESTORE -> ConfirmDialog(
            title = stringResource(R.string.editorial_events_restore_title),
            text = stringResource(R.string.editorial_events_restore_text),
            confirmLabel = confirm, onConfirm = { onDecision(EventDecision.Restore) }, onDismiss = onDismiss, enabled = !busy,
        )
        else -> onDismiss()
    }
}

/** Il testo della conferma di «Annulla l'evento», con i numeri se si conoscono. */
@Composable
internal fun cancelText(impact: CancelImpact?): String = when {
    impact == null -> stringResource(R.string.editorial_events_cancel_text_generic)
    impact.upcomingDates == 0 -> stringResource(R.string.editorial_events_cancel_text_none)
    else -> stringResource(R.string.editorial_events_cancel_text, impact.upcomingDates, impact.savedBy)
}

@Composable
private fun CancelEventDialog(impact: CancelImpact?, busy: Boolean, onConfirm: (String?) -> Unit, onDismiss: () -> Unit) {
    var note by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_events_cancel_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(cancelText(impact))
                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it.take(CANCEL_NOTE_MAX) },
                    label = { Text(stringResource(R.string.editorial_events_cancel_note)) },
                    enabled = !busy,
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth(),
                )
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = { onConfirm(note.trim().ifBlank { null }) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_events_cancel_confirm), color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = {
            TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_close)) }
        },
    )
}

@Composable
private fun FeatureDialog(zone: ZoneId, cityName: String?, busy: Boolean, onConfirm: (LocalDateTime?) -> Unit, onDismiss: () -> Unit) {
    var until by remember { mutableStateOf<LocalDateTime?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_events_feature_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_events_feature_text))
                CityDateTimeField(
                    label = stringResource(R.string.editorial_events_feature_until),
                    value = until, zone = zone, cityName = cityName,
                    onChange = { until = it }, enabled = !busy, clearable = true,
                )
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = { onConfirm(until) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_events_action_feature))
            }
        },
        dismissButton = {
            TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_events_close)) }
        },
    )
}

private val momentFormat = DateTimeFormatter.ofPattern("EEE d MMM yyyy, HH:mm", Locale.ITALIAN)

/** «sab 22 nov 2026, 21:00» nel fuso della città; il testo così com'è se non si legge. */
internal fun editorialMoment(iso: String?, zone: ZoneId): String? {
    if (iso.isNullOrBlank()) return null
    return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(momentFormat) }.getOrDefault(iso)
}
