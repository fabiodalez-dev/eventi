package it.fabiodalez.incitta.ui.management.common

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SubjectKind
import it.fabiodalez.incitta.ui.Muted

/*
 * Lo scheletro di ogni schermata della gestione: indietro, titolo, riga
 * sotto il titolo e, per chi gestisce più di un locale o di un'organizzazione,
 * il selettore del soggetto (W-NV-02). Il contenuto scorre in una colonna sola,
 * come la gestione di prima: gli elenchi sono corti e paginati dal server.
 *
 * Arrivano con i loro pacchetti, e non stanno qui: `RichTextField` (testo con
 * grassetto, corsivo ed elenchi per la descrizione dell'evento, P1) ed
 * `EventPickerSheet` (scelta di un evento con locandina, titolo, data e
 * locale, per statistiche, sponsorizzazioni e grafiche social, P5).
 */
@Composable
internal fun ManagementScaffold(
    title: String,
    padding: PaddingValues,
    onBack: () -> Unit,
    subtitle: String? = null,
    subjects: List<ManagementSubject> = emptyList(),
    selectedKey: String? = null,
    onSelectSubject: (ManagementSubject) -> Unit = {},
    busy: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        IconButton(onClick = onBack, modifier = Modifier.size(48.dp)) {
            Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = stringResource(R.string.management_back))
        }
        Text(title, style = MaterialTheme.typography.headlineLarge)
        subtitle?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
        if (subjects.size > 1) SubjectSelector(subjects, selectedKey, onSelectSubject)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        content()
    }
}

/** Il menu dei locali e delle organizzazioni gestite; si apre dal nome di quello aperto. */
@Composable
internal fun SubjectSelector(subjects: List<ManagementSubject>, selectedKey: String?, onSelect: (ManagementSubject) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val current = subjects.firstOrNull { it.key == selectedKey } ?: subjects.first()
    val switchLabel = stringResource(R.string.management_subject_switch)
    Box {
        OutlinedButton(
            onClick = { open = true },
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = switchLabel },
        ) {
            Text(stringResource(R.string.management_subject_current, current.name), modifier = Modifier.weight(1f))
            Icon(Icons.Outlined.ExpandMore, contentDescription = null)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            subjects.forEach { subject ->
                DropdownMenuItem(
                    text = {
                        Column {
                            Text(subject.name, style = MaterialTheme.typography.titleMedium)
                            Text(stringResource(subjectKindLabel(subject.kind)) + (subject.cityName?.let { " · $it" } ?: ""), color = Muted)
                        }
                    },
                    onClick = { open = false; onSelect(subject) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
    }
}

internal fun subjectKindLabel(kind: SubjectKind): Int = when (kind) {
    SubjectKind.Venue -> R.string.management_kind_venue
    SubjectKind.Organizer -> R.string.management_kind_organizer
}

/** «Referente del locale», «Membro dell'organizzazione»…; `null` per un ruolo che questa versione non conosce. */
internal fun subjectRoleLabel(subject: ManagementSubject): Int? = when (subject.kind) {
    SubjectKind.Venue -> when (subject.role) { "owner" -> R.string.management_role_venue_owner; "editor" -> R.string.management_role_venue_editor; else -> null }
    SubjectKind.Organizer -> when (subject.role) { "owner" -> R.string.management_role_organizer_owner; "member" -> R.string.management_role_organizer_member; else -> null }
}

/** Una voce di menu da 56 dp con titolo e descrizione, come le righe del profilo. */
@Composable
internal fun ManagementRow(title: String, description: String?, onClick: () -> Unit, enabled: Boolean = true) {
    TextButton(onClick = onClick, enabled = enabled, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.Start) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            description?.let { Text(it, color = Muted) }
        }
    }
    HorizontalDivider()
}
