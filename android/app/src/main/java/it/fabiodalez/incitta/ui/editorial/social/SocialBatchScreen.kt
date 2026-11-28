package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialSocialBatch
import it.fabiodalez.incitta.data.management.SocialFormatChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.CityDateTimeField
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.SharedFiles
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.social.BatchImage
import it.fabiodalez.incitta.ui.management.social.SectionTitle
import it.fabiodalez.incitta.ui.management.social.SocialFileUse
import it.fabiodalez.incitta.ui.management.social.SocialImages
import it.fabiodalez.incitta.ui.management.social.copyCaption
import it.fabiodalez.incitta.ui.management.social.formatLabel
import it.fabiodalez.incitta.ui.management.social.socialLongDayLabel
import java.time.LocalDate
import java.time.ZoneId

/*
 * Un pacchetto della redazione, come la sezione «Grafiche pronte» della
 * pagina «Social» per l'amministrazione: le immagini da salvare o
 * condividere, lo ZIP, il testo automatico di ogni parte del carosello e
 * «Pubblica o programma».
 */
@Composable
internal fun EditorialSocialBatchScreen(
    viewModel: EditorialSocialBatchViewModel,
    padding: PaddingValues,
    onBack: () -> Unit,
    onOpenHistory: () -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val androidContext = LocalContext.current
    val imageChooser = stringResource(R.string.social_share_chooser)
    val zipChooser = stringResource(R.string.social_zip_share)
    LaunchedEffect(state.file) {
        val pending = state.file ?: return@LaunchedEffect
        when (pending.use) {
            SocialFileUse.SaveImage -> if (SocialImages.save(androidContext, pending.file.file, pending.name)) viewModel.fileHandled(UiText.Res(R.string.social_saved))
                else viewModel.fileHandled(null, UiText.Res(R.string.social_save_failed))
            SocialFileUse.ShareImage -> if (SocialImages.share(androidContext, pending.file.file, "image/jpeg", imageChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.social_share_failed))
            SocialFileUse.ShareZip -> if (SharedFiles.share(androidContext, pending.file.file, "application/zip", zipChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.social_share_failed))
            SocialFileUse.OpenZip -> if (SharedFiles.open(androidContext, pending.file.file, "application/zip", zipChooser)) viewModel.fileHandled(null)
                else viewModel.fileHandled(null, UiText.Res(R.string.management_export_no_app))
        }
    }
    val batch = (state.batch as? Loadable.Ready)?.value
    ManagementScaffold(
        title = stringResource(R.string.social_batch_title),
        padding = padding,
        onBack = onBack,
        subtitle = batch?.let {
            listOfNotNull(
                runCatching { LocalDate.parse(it.date) }.getOrNull()?.let(::socialLongDayLabel),
                stringResource(formatLabel(SocialFormatChoice.from(it.format.value))),
                it.city?.name,
            ).joinToString(" · ")
        },
        busy = state.busy,
    ) {
        state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
        state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
        LoadState(state.batch, onRetry = viewModel::load, emptyText = stringResource(R.string.social_batch_missing)) { loaded ->
            BatchContent(loaded, state, viewModel, onOpenHistory)
        }
    }
}

@Composable
private fun BatchContent(batch: EditorialSocialBatch, state: EditorialBatchUiState, viewModel: EditorialSocialBatchViewModel, onOpenHistory: () -> Unit) {
    val androidContext = LocalContext.current
    val cacheDir = androidContext.cacheDir
    var confirmPublish by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text(stringResource(R.string.social_snapshot), color = Muted)
        val ratio = batch.format.width.toFloat() / batch.format.height.coerceAtLeast(1)
        LazyRow(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            items(batch.items, key = { it.index }) { item ->
                BatchImage(item, ratio, busy = state.busy, downloading = state.downloading == item.index,
                    onSave = { viewModel.image(item, SocialFileUse.SaveImage, cacheDir) },
                    onShare = { viewModel.image(item, SocialFileUse.ShareImage, cacheDir) })
            }
        }
        Text(stringResource(R.string.social_zip), style = MaterialTheme.typography.titleSmall)
        Text(stringResource(R.string.social_zip_hint), color = Muted)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(enabled = !state.busy, onClick = { viewModel.zip(SocialFileUse.ShareZip, cacheDir) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (state.downloading == EditorialSocialBatchViewModel.ZIP) R.string.social_downloading else R.string.social_zip_share))
            }
            OutlinedButton(enabled = !state.busy, onClick = { viewModel.zip(SocialFileUse.OpenZip, cacheDir) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.social_zip_open))
            }
        }
        // Il testo automatico che uscirebbe pubblicando, una didascalia per parte del carosello; senza, la didascalia del pacchetto.
        val captions = batch.captions.ifEmpty { listOfNotNull(batch.caption.takeIf(String::isNotBlank)) }
        captions.forEachIndexed { index, caption ->
            Text(
                if (captions.size > 1) stringResource(R.string.editorial_social_caption_part, index + 1, captions.size) else stringResource(R.string.editorial_social_caption_preview),
                style = MaterialTheme.typography.titleSmall,
            )
            Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
                Text(caption, modifier = Modifier.padding(12.dp))
            }
            OutlinedButton(onClick = { if (copyCaption(androidContext, caption)) viewModel.captionCopied() }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.social_copy_caption))
            }
        }
        HorizontalDivider()
        PublishSection(batch, state, viewModel, onPublish = { confirmPublish = true }, onOpenHistory = onOpenHistory)
    }
    if (confirmPublish) {
        ConfirmDialog(
            title = stringResource(R.string.editorial_social_publish_confirm_title),
            text = stringResource(R.string.editorial_social_publish_confirm),
            confirmLabel = stringResource(R.string.editorial_social_publish),
            onConfirm = { confirmPublish = false; viewModel.publish() },
            onDismiss = { confirmPublish = false },
        )
    }
}

/** «Pubblica o programma» della pagina: data e ora nel fuso della città del pacchetto, «Pubblica», «Programma». */
@Composable
private fun PublishSection(batch: EditorialSocialBatch, state: EditorialBatchUiState, viewModel: EditorialSocialBatchViewModel, onPublish: () -> Unit, onOpenHistory: () -> Unit) {
    val zone = socialZone(batch.timezone) ?: ZoneId.of("Europe/Rome")
    SectionTitle(stringResource(R.string.editorial_social_publication))
    Text(stringResource(R.string.editorial_social_schedule_lead, zone.id), color = Muted)
    CityDateTimeField(
        label = stringResource(R.string.editorial_social_schedule_when),
        value = state.scheduledAt,
        zone = zone,
        cityName = batch.city?.name,
        onChange = viewModel::setScheduledAt,
        error = state.errors.field(EditorialSocialBatchViewModel.FIELD_SCHEDULE),
        enabled = !state.busy,
    )
    Text(stringResource(R.string.editorial_social_schedule_note), color = Muted)
    state.errors.field(EditorialSocialBatchViewModel.FIELD_PUBLISH)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(enabled = !state.busy, onClick = onPublish, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_publish)) }
        OutlinedButton(enabled = !state.busy, onClick = viewModel::schedule, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_schedule)) }
    }
    if (batch.inHistory) {
        Text(stringResource(R.string.editorial_social_in_history), color = Muted)
        TextButton(onClick = onOpenHistory, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_open_history)) }
    }
}
