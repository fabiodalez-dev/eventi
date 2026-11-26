package it.fabiodalez.incitta.ui.editorial.social

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.SocialSettings
import it.fabiodalez.incitta.data.editorial.SocialSettingsValues
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.social.CheckRow
import it.fabiodalez.incitta.ui.management.social.RadioRow
import it.fabiodalez.incitta.ui.management.social.SectionTitle

/*
 * «Account e pubblicazione social» (R-SO-05…07), come la pagina
 * `SocialSettings` del pannello: lo stato di Meta e Telegram, il modulo
 * senza i segreti (si scrivono solo dal sito, decisione 5), le verifiche e
 * il collegamento con Meta nel browser (decisione 14). Tornando dal browser
 * la schermata rilegge lo stato (`onResume` del grafo).
 */
@Composable
internal fun SocialSettingsScreen(
    viewModel: SocialSettingsViewModel,
    padding: PaddingValues,
    onBack: () -> Unit,
    onOpenWeb: (String) -> Unit,
    onOpenUrl: (String) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    var confirmDisconnect by remember { mutableStateOf(false) }
    LaunchedEffect(state.openUrl) {
        state.openUrl?.let { url ->
            viewModel.consumeOpenUrl()
            onOpenUrl(url)
        }
    }
    ManagementScaffold(
        title = stringResource(R.string.editorial_social_settings_title),
        padding = padding,
        onBack = onBack,
        busy = state.busy,
    ) {
        Text(stringResource(R.string.editorial_social_settings_lead))
        LoadState(state.settings, onRetry = viewModel::load) { settings ->
            val draft = state.draft ?: settings.values
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                state.warning?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
                state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                SecretsNotice(settings, onOpenWeb)
                MetaSection(settings, draft, state.errors, state.busy, viewModel, onDisconnect = { confirmDisconnect = true })
                HorizontalDivider()
                TelegramSection(settings, draft, state.errors, state.busy, viewModel)
                HorizontalDivider()
                AutomaticSection(settings, draft, state.errors, viewModel)
                if (state.dirty) Text(stringResource(R.string.editorial_social_unsaved), color = Muted)
                Button(enabled = !state.busy, onClick = viewModel::save, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.editorial_social_save))
                }
            }
        }
    }
    if (confirmDisconnect) {
        ConfirmDialog(
            title = stringResource(R.string.editorial_social_disconnect_title),
            text = stringResource(R.string.editorial_social_disconnect_confirm),
            confirmLabel = stringResource(R.string.editorial_social_disconnect_meta),
            onConfirm = { confirmDisconnect = false; viewModel.disconnectMeta() },
            onDismiss = { confirmDisconnect = false },
            destructive = true,
        )
    }
}

@Composable
private fun SecretsNotice(settings: SocialSettings, onOpenWeb: (String) -> Unit) {
    Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(stringResource(R.string.editorial_social_secrets_web))
            OutlinedButton(onClick = { onOpenWeb(settings.webPath) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_social_open_settings_web))
            }
        }
    }
}

@Composable
private fun MetaSection(settings: SocialSettings, draft: SocialSettingsValues, errors: FormErrors, busy: Boolean, viewModel: SocialSettingsViewModel, onDisconnect: () -> Unit) {
    val status = settings.status
    SectionTitle(stringResource(R.string.editorial_social_meta_heading))
    Text(stringResource(R.string.editorial_social_meta_intro), color = Muted)
    Text(
        stringResource(
            R.string.editorial_social_meta_status,
            stringResource(if (status.appSecretSaved) R.string.editorial_social_saved else R.string.editorial_social_missing),
            stringResource(if (status.accessTokenSaved) R.string.editorial_social_saved else R.string.editorial_social_missing),
            stringResource(if (status.metaVerifiedAt != null) R.string.editorial_social_verified else R.string.editorial_social_to_verify),
        ),
    )
    SettingsField(stringResource(R.string.editorial_social_field_app_id), draft.appId.orEmpty(), errors.field("app_id"), digits = true) { viewModel.edit(draft.copy(appId = it)) }
    CityChoice(settings, draft, errors, viewModel)
    SettingsField(stringResource(R.string.editorial_social_field_page_id), draft.pageId.orEmpty(), errors.field("page_id"), digits = true) { viewModel.edit(draft.copy(pageId = it)) }
    SettingsField(stringResource(R.string.editorial_social_field_instagram_id), draft.instagramId.orEmpty(), errors.field("instagram_id"), digits = true) { viewModel.edit(draft.copy(instagramId = it)) }
    SettingsField(stringResource(R.string.editorial_social_field_graph_version), draft.graphVersion, errors.field("graph_version")) { viewModel.edit(draft.copy(graphVersion = it)) }
    SwitchRow(stringResource(R.string.editorial_social_field_facebook), draft.facebookEnabled) { viewModel.edit(draft.copy(facebookEnabled = it)) }
    SwitchRow(stringResource(R.string.editorial_social_field_instagram), draft.instagramEnabled) { viewModel.edit(draft.copy(instagramEnabled = it)) }
    errors.field("verify")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    errors.field("meta")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    OutlinedButton(enabled = !busy, onClick = viewModel::verifyMeta, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_verify)) }
    OutlinedButton(enabled = !busy, onClick = viewModel::connectMeta, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_connect_meta)) }
    OutlinedButton(enabled = !busy, onClick = onDisconnect, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(R.string.editorial_social_disconnect_meta), color = MaterialTheme.colorScheme.error)
    }
}

@Composable
private fun TelegramSection(settings: SocialSettings, draft: SocialSettingsValues, errors: FormErrors, busy: Boolean, viewModel: SocialSettingsViewModel) {
    val status = settings.status
    SectionTitle(stringResource(R.string.editorial_social_telegram_heading))
    Text(
        stringResource(
            R.string.editorial_social_telegram_status,
            stringResource(if (status.telegramBotTokenSaved) R.string.editorial_social_saved else R.string.editorial_social_missing),
            settings.values.telegramChatId ?: stringResource(R.string.editorial_social_telegram_no_chat),
            stringResource(if (status.telegramVerifiedAt != null) R.string.editorial_social_telegram_done else R.string.editorial_social_telegram_todo),
        ),
    )
    Text(stringResource(R.string.editorial_social_telegram_help), color = Muted)
    SettingsField(stringResource(R.string.editorial_social_field_telegram_chat), draft.telegramChatId.orEmpty(), errors.field("telegram_chat_id")) { viewModel.edit(draft.copy(telegramChatId = it)) }
    SwitchRow(stringResource(R.string.editorial_social_field_telegram), draft.telegramEnabled) { viewModel.edit(draft.copy(telegramEnabled = it)) }
    errors.field("telegram")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    OutlinedButton(enabled = !busy, onClick = viewModel::verifyTelegram, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_social_verify_telegram)) }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AutomaticSection(settings: SocialSettings, draft: SocialSettingsValues, errors: FormErrors, viewModel: SocialSettingsViewModel) {
    val options = draft.graphicOptions
    SectionTitle(stringResource(R.string.editorial_social_automatic_heading))
    Text(stringResource(R.string.editorial_social_automatic_help), color = Muted)
    SwitchRow(stringResource(R.string.editorial_social_field_automatic), draft.automatic) { viewModel.edit(draft.copy(automatic = it)) }
    CheckRow(stringResource(R.string.social_monochrome), options.monochrome) { viewModel.edit(draft.copy(graphicOptions = options.copy(monochrome = it))) }
    CheckRow(stringResource(R.string.social_address), options.showAddress) { viewModel.edit(draft.copy(graphicOptions = options.copy(showAddress = it))) }
    CheckRow(stringResource(R.string.social_price), options.showPrice) { viewModel.edit(draft.copy(graphicOptions = options.copy(showPrice = it))) }
    Text(stringResource(R.string.social_image_fit), style = MaterialTheme.typography.titleSmall)
    RadioRow(stringResource(R.string.social_contain), options.imageFit != "cover") { viewModel.edit(draft.copy(graphicOptions = options.copy(imageFit = "contain"))) }
    RadioRow(stringResource(R.string.social_cover), options.imageFit == "cover") { viewModel.edit(draft.copy(graphicOptions = options.copy(imageFit = "cover"))) }
    errors.field("graphic_options")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    SettingsField(stringResource(R.string.editorial_social_field_publish_time), draft.publishTime, errors.field("publish_time")) { viewModel.edit(draft.copy(publishTime = it.take(5))) }
    Text(stringResource(R.string.editorial_social_field_caption), style = MaterialTheme.typography.titleSmall)
    Text(stringResource(R.string.editorial_social_caption_help), color = Muted)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        settings.tokens.forEach { token ->
            AssistChip(
                onClick = { viewModel.edit(draft.copy(caption = insertCaptionToken(draft.caption, token.token))) },
                label = { Text("${token.label} ${token.token}") },
                modifier = Modifier.heightIn(min = 48.dp),
            )
        }
    }
    val captionError = errors.field("caption")
    OutlinedTextField(
        value = draft.caption,
        onValueChange = { viewModel.edit(draft.copy(caption = it.take(settings.limits.captionMax))) },
        label = { Text(stringResource(R.string.editorial_social_field_caption)) },
        supportingText = { Text(captionError ?: stringResource(R.string.editorial_social_caption_counter, draft.caption.length, settings.limits.captionMax)) },
        isError = captionError != null,
        minLines = 6,
        modifier = Modifier.fillMaxWidth(),
    )
}

/** Il segnaposto toccato va in fondo al testo, staccato da uno spazio, come i pulsanti sopra il campo della pagina. */
internal fun insertCaptionToken(caption: String, token: String): String =
    if (caption.isEmpty() || caption.last().isWhitespace()) caption + token else "$caption $token"

@Composable
private fun CityChoice(settings: SocialSettings, draft: SocialSettingsValues, errors: FormErrors, viewModel: SocialSettingsViewModel) {
    if (settings.cities.isEmpty()) return
    Text(stringResource(R.string.editorial_social_field_city), style = MaterialTheme.typography.titleSmall)
    Column {
        settings.cities.forEach { city ->
            RadioRow(city.name, draft.cityId == city.id) { viewModel.edit(draft.copy(cityId = city.id)) }
        }
    }
    errors.field("city_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
}

@Composable
private fun SettingsField(label: String, value: String, error: String?, digits: Boolean = false, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = { onChange(if (digits) it.filter(Char::isDigit) else it) },
        label = { Text(label) },
        isError = error != null,
        supportingText = error?.let { { Text(it) } },
        singleLine = true,
        keyboardOptions = if (digits) KeyboardOptions(keyboardType = KeyboardType.Number) else KeyboardOptions.Default,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun SwitchRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(value = checked, role = Role.Switch, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = null)
    }
}
