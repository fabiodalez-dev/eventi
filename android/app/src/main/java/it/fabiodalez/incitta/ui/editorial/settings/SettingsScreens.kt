package it.fabiodalez.incitta.ui.editorial.settings

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.ArrowDownward
import androidx.compose.material.icons.outlined.ArrowUpward
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CacheSettingsView
import it.fabiodalez.incitta.data.editorial.ConsentCategoryInfo
import it.fabiodalez.incitta.data.editorial.ConsentCategoryValue
import it.fabiodalez.incitta.data.editorial.ConsentScriptRow
import it.fabiodalez.incitta.data.editorial.CookieLimits
import it.fabiodalez.incitta.data.editorial.CookieRow
import it.fabiodalez.incitta.data.editorial.EditorialDesk
import it.fabiodalez.incitta.data.editorial.EmailText
import it.fabiodalez.incitta.data.editorial.EmailTextVariable
import it.fabiodalez.incitta.data.editorial.MailSettingsView
import it.fabiodalez.incitta.data.editorial.NewsletterFields
import it.fabiodalez.incitta.data.editorial.NewsletterSettingsView
import it.fabiodalez.incitta.data.editorial.RedirectLimits
import it.fabiodalez.incitta.data.editorial.RedirectOptions
import it.fabiodalez.incitta.data.editorial.RedirectRow
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.feedback.feedbackDate
import java.time.ZoneId

/*
 * Le schermate della configurazione tecnica (R13). Ogni schermata ha una
 * versione senza ViewModel (stato e azioni come parametri), che è quella
 * che i test strumentati disegnano.
 */

// ----------------------------------------------------------------- in comune

@Composable
private fun Message(text: UiText?) {
    text?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
}

@Composable
private fun ErrorText(text: String?) {
    text?.let { Text(it, color = MaterialTheme.colorScheme.error) }
}

@Composable
private fun InfoCard(content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp), content = content)
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
}

/** Una riga «etichetta: valore» in sola lettura. */
@Composable
private fun Field(label: String, value: String?) {
    Column {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Muted)
        Text(value?.takeIf { it.isNotBlank() } ?: stringResource(R.string.config_none))
    }
}

/** La pagina del pannello per quello che dall'app non si scrive (decisione 5). */
@Composable
private fun WebOnly(text: String, onWeb: () -> Unit) {
    Text(text, color = Muted)
    OutlinedButton(onClick = onWeb, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
        Text(stringResource(R.string.config_edit_on_web), Modifier.padding(start = 8.dp))
    }
}

@Composable
private fun yesNo(value: Boolean): String = stringResource(if (value) R.string.config_yes else R.string.config_no)

/** Il nome di una finalità: quello del server, o quello dell'app se l'elenco non è ancora arrivato. */
@Composable
internal fun cookieCategoryLabel(value: String, categories: List<ConsentCategoryInfo>): String =
    categories.firstOrNull { it.value == value }?.label?.takeIf { it.isNotBlank() } ?: when (value) {
        ConsentCategoryValue.NECESSARY -> stringResource(R.string.config_cookie_necessary)
        ConsentCategoryValue.STATISTICS -> stringResource(R.string.config_cookie_statistics)
        ConsentCategoryValue.MARKETING -> stringResource(R.string.config_cookie_marketing)
        else -> value
    }

/** I pulsanti «Inserisci» dei segnaposti, come sopra ogni campo del pannello. */
@Composable
private fun VariableChips(variables: List<EmailTextVariable>, onInsert: (EmailTextVariable) -> Unit) {
    if (variables.isEmpty()) return
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(variables, key = { it.name }) { variable ->
            AssistChip(
                onClick = { onInsert(variable) },
                label = { Text(stringResource(R.string.config_texts_insert, variable.label.ifBlank { variable.name }, variable.token)) },
                modifier = Modifier.heightIn(min = 48.dp),
            )
        }
    }
}

// --------------------------------------------------------------------- menu

@Composable
internal fun SettingsHub(desk: EditorialDesk?, title: String, padding: PaddingValues, onBack: () -> Unit, onRetry: () -> Unit, onOpen: (SettingsSection) -> Unit) {
    ManagementScaffold(title = title, padding = padding, onBack = onBack, subtitle = stringResource(R.string.config_hub_intro)) {
        if (desk == null) {
            LoadState<Unit>(Loadable.Loading, onRetry = onRetry) {}
        } else {
            val sections = settingsSections(desk.can)
            sections.forEach { section -> ManagementRow(stringResource(section.title), stringResource(section.help), onClick = { onOpen(section) }) }
            if (sections.isEmpty()) Text(stringResource(R.string.config_nothing_allowed), color = Muted)
        }
    }
}

// ------------------------------------------------------------ testi email

@Composable
internal fun EmailTextsContent(
    state: EmailTextsUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onGroup: (String) -> Unit,
    onOpen: (String) -> Unit,
    onAskReset: () -> Unit,
    onDismissReset: () -> Unit,
    onReset: () -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.config_texts_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.config_texts_lead)) {
        Message(state.message)
        LoadState(state.texts, onRetry = onRetry) { texts ->
            val groupsLabel = stringResource(R.string.config_texts_groups)
            Row(Modifier.horizontalScroll(rememberScrollState()).semantics { contentDescription = groupsLabel }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                texts.groups.forEach { group ->
                    FilterChip(selected = group.key == state.group, onClick = { onGroup(group.key) }, label = { Text(group.label) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
            val group = texts.groups.firstOrNull { it.key == state.group } ?: texts.groups.firstOrNull()
            group?.let { SectionTitle(it.label) }
            group?.texts?.forEach { text -> EmailTextRow(text, onOpen) }
            OutlinedButton(onClick = onAskReset, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.config_texts_reset_all)) }
        }
    }
    if (state.confirmingReset) {
        ConfirmDialog(
            title = stringResource(R.string.config_texts_reset_all),
            text = listOfNotNull(stringResource(R.string.config_texts_reset_confirm), state.resetError).joinToString("\n\n"),
            confirmLabel = stringResource(R.string.config_texts_reset_all),
            onConfirm = onReset,
            onDismiss = onDismissReset,
            destructive = true,
            enabled = !state.resetting,
        )
    }
}

@Composable
private fun EmailTextRow(text: EmailText, onOpen: (String) -> Unit) {
    val open = stringResource(R.string.config_texts_open, text.label)
    Column(
        Modifier.fillMaxWidth().heightIn(min = 56.dp).clickable(role = Role.Button, onClickLabel = open) { onOpen(text.key) }.padding(vertical = 8.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(text.label, style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
            Text(stringResource(if (text.modified) R.string.config_texts_modified else R.string.config_texts_original), style = MaterialTheme.typography.labelSmall,
                color = if (text.modified) MaterialTheme.colorScheme.primary else Muted)
        }
        Text(text.value, maxLines = 2, color = Muted)
    }
    HorizontalDivider()
}

@Composable
internal fun EmailTextEditor(
    state: EmailTextEditUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onValue: (String) -> Unit,
    onInsert: (EmailTextVariable) -> Unit,
    onRestore: () -> Unit,
    onSave: () -> Unit,
) {
    val text = (state.text as? Loadable.Ready)?.value
    ManagementScaffold(title = text?.label ?: stringResource(R.string.config_texts_title), padding = padding, onBack = onBack, busy = state.saving) {
        Message(state.message)
        LoadState(state.text, onRetry = onRetry, emptyText = stringResource(R.string.config_texts_not_found)) { current ->
            val unknown = state.unknown
            val errors = if (unknown.isNotEmpty()) state.errors.copy(fields = state.errors.fields + (EmailTextEditViewModel.FIELD_VALUE to stringResource(R.string.config_texts_unknown, unknown.joinToString(", ")))) else state.errors
            FormScaffold(errors = errors, busy = state.saving, submitLabel = stringResource(R.string.config_save), onSubmit = onSave, submitEnabled = state.canSave) {
                VariableChips(current.variables, onInsert)
                FormTextField(
                    value = state.value,
                    onValueChange = onValue,
                    label = stringResource(R.string.config_texts_field),
                    errors = errors,
                    field = EmailTextEditViewModel.FIELD_VALUE,
                    minLines = current.rows.coerceIn(2, 6),
                    help = stringResource(R.string.config_texts_field_help),
                )
                InfoCard {
                    Text(stringResource(R.string.config_texts_preview), style = MaterialTheme.typography.labelMedium, color = Muted)
                    if (state.previewLoading) {
                        val loading = stringResource(R.string.config_texts_preview_loading)
                        LinearProgressIndicator(Modifier.fillMaxWidth().semantics { contentDescription = loading })
                    }
                    Text(state.preview)
                }
                var showOriginal by rememberSaveable { mutableStateOf(false) }
                TextButton(onClick = { showOriginal = !showOriginal }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(if (showOriginal) R.string.config_texts_hide_original else R.string.config_texts_show_original))
                }
                if (showOriginal) Text(current.default, color = Muted)
                OutlinedButton(onClick = onRestore, enabled = state.value != current.default && !state.saving, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.config_texts_restore))
                }
            }
        }
    }
}

// ------------------------------------------------------------------ posta

@Composable
internal fun MailSettingsContent(
    state: MailUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onAskTest: () -> Unit,
    onDismissTest: () -> Unit,
    onSendTest: () -> Unit,
    onWeb: () -> Unit,
) {
    val mail = (state.mail as? Loadable.Ready)?.value
    ManagementScaffold(title = stringResource(R.string.config_mail_title), padding = padding, onBack = onBack, busy = state.testing) {
        state.result?.let { result ->
            InfoCard {
                Text(result.title, style = MaterialTheme.typography.titleSmall, color = if (result.sent) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error)
                if (result.detail.isNotBlank()) SelectionContainer { Text(result.detail) }
            }
        }
        ErrorText(state.error)
        LoadState(state.mail, onRetry = onRetry) { current -> MailDetails(current) }
        if (mail != null) {
            Button(onClick = onAskTest, enabled = !state.testing, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.config_mail_test)) }
            WebOnly(stringResource(R.string.config_mail_web_only), onWeb)
        }
    }
    if (state.confirmingTest && mail != null) {
        val recipient = mail.recipient?.takeIf { it.isNotBlank() }
        ConfirmDialog(
            title = stringResource(R.string.config_mail_test),
            text = recipient?.let { stringResource(R.string.config_mail_test_confirm, it) } ?: stringResource(R.string.config_mail_test_confirm_no_recipient),
            confirmLabel = stringResource(R.string.config_mail_test_send),
            onConfirm = onSendTest,
            onDismiss = onDismissTest,
            enabled = !state.testing && recipient != null,
        )
    }
}

@Composable
private fun MailDetails(mail: MailSettingsView) {
    InfoCard {
        Text(mail.status.title, style = MaterialTheme.typography.titleSmall, color = if (mail.status.custom) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
        if (mail.status.detail.isNotBlank()) Text(mail.status.detail, color = Muted)
        if (mail.staleCheck) Text(stringResource(R.string.config_mail_stale), color = MaterialTheme.colorScheme.error)
    }
    SectionTitle(stringResource(R.string.config_mail_server))
    Field(stringResource(R.string.config_mail_host), mail.host)
    Field(stringResource(R.string.config_mail_port), mail.port?.toString())
    Field(stringResource(R.string.config_mail_encryption), mail.encryptionLabel)
    Field(stringResource(R.string.config_mail_username), mail.username)
    Field(stringResource(R.string.config_mail_password), stringResource(if (mail.hasPassword) R.string.config_password_saved else R.string.config_password_missing))
    SectionTitle(stringResource(R.string.config_mail_sender))
    Field(stringResource(R.string.config_mail_from_address), mail.fromAddress)
    Field(stringResource(R.string.config_mail_from_name), mail.fromName)
    SectionTitle(stringResource(R.string.config_mail_activation))
    Field(stringResource(R.string.config_mail_enabled), yesNo(mail.enabled))
    Text(stringResource(if (mail.verified) R.string.config_mail_verified else R.string.config_mail_not_verified), color = Muted)
}

// ------------------------------------------------------------- newsletter

@Composable
internal fun NewsletterContent(
    state: NewsletterUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onEdit: ((NewsletterDraft) -> NewsletterDraft) -> Unit,
    onText: (String, String) -> Unit,
    onInsert: (String, EmailTextVariable) -> Unit,
    onSave: () -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.config_newsletter_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.config_newsletter_lead), busy = state.saving) {
        Message(state.message)
        LoadState(state.view, onRetry = onRetry) { view -> NewsletterForm(state, view, onEdit, onText, onInsert, onSave) }
    }
}

@Composable
private fun NewsletterForm(
    state: NewsletterUiState,
    view: NewsletterSettingsView,
    onEdit: ((NewsletterDraft) -> NewsletterDraft) -> Unit,
    onText: (String, String) -> Unit,
    onInsert: (String, EmailTextVariable) -> Unit,
    onSave: () -> Unit,
) {
    val draft = state.draft
    val problems = state.problems
    val local = buildMap {
        if ("time" in problems) put("time", stringResource(R.string.config_newsletter_time_invalid))
        if ("max_items" in problems) put("max_items", stringResource(R.string.config_newsletter_max_items_invalid, view.limits.maxItemsMin, view.limits.maxItemsMax))
    }
    val errors = state.errors.copy(fields = local + state.errors.fields)
    FormScaffold(errors = errors, busy = state.saving, submitLabel = stringResource(R.string.config_newsletter_save), onSubmit = onSave, submitEnabled = state.canSave) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.config_newsletter_enabled), style = MaterialTheme.typography.titleSmall)
                Text(stringResource(R.string.config_newsletter_enabled_help), color = Muted)
            }
            Switch(checked = draft.enabled, onCheckedChange = { checked -> onEdit { it.copy(enabled = checked) } })
        }
        Text(stringResource(R.string.config_newsletter_weekday), style = MaterialTheme.typography.labelMedium)
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            view.weekdays.forEach { day ->
                FilterChip(selected = draft.weekday == day.value, onClick = { onEdit { it.copy(weekday = day.value) } }, label = { Text(day.label) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
        errors.field("weekday")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        FormTextField(draft.time, { value -> onEdit { it.copy(time = value) } }, stringResource(R.string.config_newsletter_time), errors, "time", singleLine = true,
            help = stringResource(R.string.config_newsletter_time_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
        FormTextField(draft.maxItems, { value -> onEdit { it.copy(maxItems = value.filter(Char::isDigit).take(3)) } }, stringResource(R.string.config_newsletter_max_items), errors, "max_items",
            singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
        SectionTitle(stringResource(R.string.config_newsletter_texts))
        Text(stringResource(R.string.config_newsletter_texts_help), color = Muted)
        NewsletterFields.texts.forEach { field ->
            val info = view.texts[field]
            val value = draft.text(field)
            if (field == NewsletterFields.LINE) VariableChips(info?.variables.orEmpty()) { onInsert(field, it) }
            FormTextField(
                value = value,
                onValueChange = { onText(field, it) },
                label = stringResource(newsletterFieldLabel(field)),
                errors = errors,
                field = field,
                minLines = if (field == NewsletterFields.LINE) 4 else 1,
                help = stringResource(R.string.config_newsletter_count, value.length, info?.max ?: 0),
            )
        }
    }
}

private fun newsletterFieldLabel(field: String): Int = when (field) {
    NewsletterFields.SUBJECT -> R.string.config_newsletter_subject
    NewsletterFields.HEADING -> R.string.config_newsletter_heading
    NewsletterFields.LINE -> R.string.config_newsletter_line
    else -> R.string.config_newsletter_action
}

// ----------------------------------------------------- script di consenso

@Composable
internal fun ScriptsList(
    state: PagedState<ConsentScriptRow>,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onOpen: (Long) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onWeb: () -> Unit,
) {
    PagedListScreen(
        title = stringResource(R.string.config_scripts_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        searchLabel = stringResource(R.string.config_scripts_search),
        emptyText = stringResource(R.string.config_scripts_empty),
        header = {
            Text(stringResource(R.string.config_scripts_lead), color = Muted)
            WebOnly(stringResource(R.string.config_scripts_web_only), onWeb)
        },
    ) { script ->
        val open = stringResource(R.string.config_scripts_open, script.name)
        Column(
            Modifier.fillMaxWidth().heightIn(min = 56.dp).clickable(role = Role.Button, onClickLabel = open) { onOpen(script.id) }.padding(vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(script.name, style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                Text(stringResource(if (script.enabled) R.string.config_scripts_enabled else R.string.config_scripts_disabled), style = MaterialTheme.typography.labelSmall,
                    color = if (script.enabled) MaterialTheme.colorScheme.primary else Muted)
            }
            Text(script.categoryLabel.ifBlank { script.category }, color = Muted)
        }
        HorizontalDivider()
    }
}

@Composable
internal fun ScriptDetail(state: Loadable<ConsentScriptRow>, padding: PaddingValues, onBack: () -> Unit, onRetry: () -> Unit, onWeb: () -> Unit) {
    val script = (state as? Loadable.Ready)?.value
    ManagementScaffold(title = script?.name ?: stringResource(R.string.config_scripts_title), padding = padding, onBack = onBack) {
        LoadState(state, onRetry = onRetry) { current ->
            Field(stringResource(R.string.config_cookies_category), current.categoryLabel.ifBlank { current.category })
            Field(stringResource(R.string.config_scripts_enabled), yesNo(current.enabled))
            Text(stringResource(R.string.config_scripts_order, current.sortOrder), color = Muted)
            Field(stringResource(R.string.config_scripts_src), current.src)
            Text(stringResource(R.string.config_scripts_code), style = MaterialTheme.typography.labelMedium, color = Muted)
            val code = current.code
            if (code.isNullOrBlank()) Text(stringResource(R.string.config_scripts_no_code))
            else InfoCard { SelectionContainer { Text(code, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall) } }
            WebOnly(stringResource(R.string.config_scripts_web_only), onWeb)
        }
    }
}

// ------------------------------------------------------------------ cookie

@Composable
internal fun CookiesList(
    state: PagedState<CookieRow>,
    ui: CookiesUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onTab: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onAdd: () -> Unit,
    onMove: (CookieRow, Int) -> Unit,
) {
    val values = ui.categories.map { it.value }.ifEmpty { ConsentCategoryValue.all }
    val tabs = values.map { ListTab(it, cookieCategoryLabel(it, ui.categories)) }
    val reorderable = cookiesReorderable(state)
    PagedListScreen(
        title = stringResource(R.string.config_cookies_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        tabs = tabs,
        onTab = onTab,
        searchLabel = stringResource(R.string.config_cookies_search),
        emptyText = stringResource(R.string.config_cookies_empty),
        header = {
            Text(stringResource(R.string.config_cookies_lead), color = Muted)
            ui.categories.firstOrNull { it.value == state.query.tab }?.description?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
            Message(ui.message)
            ErrorText(ui.error)
            Button(onClick = onAdd, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.Outlined.Add, contentDescription = null)
                Text(stringResource(R.string.config_cookies_add), Modifier.padding(start = 8.dp))
            }
            if (!reorderable && state.items.size > 1) Text(stringResource(R.string.config_cookies_reorder_hint), style = MaterialTheme.typography.bodySmall, color = Muted)
        },
    ) { cookie ->
        val index = state.items.indexOf(cookie)
        Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(cookie.name, style = MaterialTheme.typography.titleSmall)
                Text(cookie.provider?.takeIf { it.isNotBlank() } ?: stringResource(R.string.config_cookies_own), color = Muted)
                Text(cookie.purpose)
                cookie.duration?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.config_cookies_duration_value, it), style = MaterialTheme.typography.bodySmall, color = Muted) }
            }
            if (reorderable) {
                IconButton(onClick = { onMove(cookie, -1) }, enabled = index > 0 && !ui.reordering, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.ArrowUpward, contentDescription = stringResource(R.string.config_cookies_move_up, cookie.name))
                }
                IconButton(onClick = { onMove(cookie, 1) }, enabled = index < state.items.lastIndex && !ui.reordering, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Outlined.ArrowDownward, contentDescription = stringResource(R.string.config_cookies_move_down, cookie.name))
                }
            }
        }
        HorizontalDivider()
    }
}

@Composable
internal fun CookieAddDialog(ui: CookiesUiState, onEdit: ((CookieDraft) -> CookieDraft) -> Unit, onSave: () -> Unit, onDismiss: () -> Unit) {
    val draft = ui.draft ?: return
    val values = ui.categories.map { it.value }.ifEmpty { ConsentCategoryValue.all }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.config_cookies_add)) },
        text = {
            Column(Modifier.heightIn(max = 520.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ui.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                Text(stringResource(R.string.config_cookies_category), style = MaterialTheme.typography.labelMedium)
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    values.forEach { value ->
                        FilterChip(selected = draft.category == value, onClick = { onEdit { it.copy(category = value) } }, label = { Text(cookieCategoryLabel(value, ui.categories)) }, modifier = Modifier.heightIn(min = 48.dp))
                    }
                }
                ui.errors.field("category")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                FormTextField(draft.name, { v -> onEdit { it.copy(name = v.take(CookieLimits.NAME_MAX)) } }, stringResource(R.string.config_cookies_name), ui.errors, "name", singleLine = true,
                    help = stringResource(R.string.config_cookies_name_help))
                FormTextField(draft.provider, { v -> onEdit { it.copy(provider = v.take(CookieLimits.PROVIDER_MAX)) } }, stringResource(R.string.config_cookies_provider), ui.errors, "provider", singleLine = true,
                    help = stringResource(R.string.config_cookies_provider_help))
                FormTextField(draft.purpose, { v -> onEdit { it.copy(purpose = v.take(CookieLimits.PURPOSE_MAX)) } }, stringResource(R.string.config_cookies_purpose), ui.errors, "purpose")
                FormTextField(draft.duration, { v -> onEdit { it.copy(duration = v.take(CookieLimits.DURATION_MAX)) } }, stringResource(R.string.config_cookies_duration), ui.errors, "duration", singleLine = true,
                    help = stringResource(R.string.config_cookies_duration_help))
            }
        },
        confirmButton = { TextButton(onClick = onSave, enabled = draft.valid && !ui.saving) { Text(stringResource(R.string.config_cookies_add)) } },
        dismissButton = { TextButton(onClick = onDismiss, enabled = !ui.saving) { Text(stringResource(R.string.management_cancel)) } },
    )
}

// ------------------------------------------------------ indirizzi cambiati

@Composable
internal fun RedirectsList(
    state: PagedState<RedirectRow>,
    padding: PaddingValues,
    zone: ZoneId,
    canCreate: Boolean,
    onBack: () -> Unit,
    onSearch: (String) -> Unit,
    onSort: (String?) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onAdd: () -> Unit,
) {
    val sorts = listOf(
        null to stringResource(R.string.config_redirects_sort_recent),
        RedirectsViewModel.SORT_LAST_HIT_OLDEST to stringResource(R.string.config_redirects_sort_oldest),
        RedirectsViewModel.SORT_HITS_MOST to stringResource(R.string.config_redirects_sort_most),
        RedirectsViewModel.SORT_HITS_LEAST to stringResource(R.string.config_redirects_sort_least),
    )
    PagedListScreen(
        title = stringResource(R.string.config_redirects_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.id },
        searchLabel = stringResource(R.string.config_redirects_search),
        emptyText = stringResource(R.string.config_redirects_empty),
        header = {
            Text(stringResource(R.string.config_redirects_lead), color = Muted)
            if (canCreate) Button(onClick = onAdd, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Icon(Icons.Outlined.Add, contentDescription = null)
                Text(stringResource(R.string.config_redirects_add), Modifier.padding(start = 8.dp))
            }
            val sortLabel = stringResource(R.string.config_redirects_sort)
            LazyRow(Modifier.semantics { contentDescription = sortLabel }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(sorts, key = { it.first ?: "default" }) { (value, label) ->
                    FilterChip(selected = state.query.sort == value, onClick = { onSort(value) }, label = { Text(label) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
        },
    ) { redirect ->
        Column(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(redirect.fromPath, style = MaterialTheme.typography.titleSmall)
            Text("→ " + redirect.toPath)
            Text(listOfNotNull(redirect.city?.name ?: stringResource(R.string.config_redirects_all_cities), redirect.statusLabel.ifBlank { redirect.status.toString() },
                if (redirect.isWildcard) stringResource(R.string.config_redirects_wildcard_badge) else null).joinToString(" · "), color = Muted)
            Text(
                stringResource(R.string.config_redirects_hits, redirect.hits) + " · " +
                    (feedbackDate(redirect.lastHitAt, zone)?.let { stringResource(R.string.config_redirects_last_hit, it) } ?: stringResource(R.string.config_redirects_never)),
                style = MaterialTheme.typography.bodySmall, color = Muted,
            )
        }
        HorizontalDivider()
    }
}

@Composable
internal fun RedirectNewForm(state: RedirectNewUiState, padding: PaddingValues, onBack: () -> Unit, onRetry: () -> Unit, onEdit: ((RedirectDraft) -> RedirectDraft) -> Unit, onSave: () -> Unit) {
    ManagementScaffold(title = stringResource(R.string.config_redirects_add), padding = padding, onBack = onBack, busy = state.saving) {
        LoadState(state.options, onRetry = onRetry) { options -> RedirectFields(state, options, onEdit, onSave) }
    }
}

@Composable
private fun RedirectFields(state: RedirectNewUiState, options: RedirectOptions, onEdit: ((RedirectDraft) -> RedirectDraft) -> Unit, onSave: () -> Unit) {
    val draft = state.draft
    val local = if (!draft.targetValid) mapOf("to_path" to stringResource(R.string.config_redirects_to_invalid)) else emptyMap()
    val errors = state.errors.copy(fields = local + state.errors.fields)
    FormScaffold(errors = errors, busy = state.saving, submitLabel = stringResource(R.string.config_redirects_add), onSubmit = onSave, submitEnabled = draft.valid && !state.saving) {
        FormTextField(draft.fromPath, { v -> onEdit { it.copy(fromPath = v.take(RedirectLimits.PATH_MAX)) } }, stringResource(R.string.config_redirects_from), errors, "from_path",
            singleLine = true, help = stringResource(R.string.config_redirects_from_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
        FormTextField(draft.toPath, { v -> onEdit { it.copy(toPath = v.take(RedirectLimits.PATH_MAX)) } }, stringResource(R.string.config_redirects_to), errors, "to_path",
            singleLine = true, help = stringResource(R.string.config_redirects_to_help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
        CityChoice(options, draft.cityId, onSelect = { id -> onEdit { it.copy(cityId = id) } })
        errors.field("city_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(stringResource(R.string.config_redirects_status), style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            options.statuses.forEach { status ->
                FilterChip(selected = draft.status == status.value, onClick = { onEdit { it.copy(status = status.value) } }, label = { Text(status.label) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
        errors.field("status")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.config_redirects_wildcard), style = MaterialTheme.typography.titleSmall)
                Text(stringResource(R.string.config_redirects_wildcard_help), color = Muted)
            }
            Switch(checked = draft.wildcard, onCheckedChange = { checked -> onEdit { it.copy(wildcard = checked) } })
        }
    }
}

@Composable
private fun CityChoice(options: RedirectOptions, selected: Long?, onSelect: (Long?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val all = stringResource(R.string.config_redirects_all_cities)
    val current = options.cities.firstOrNull { it.id == selected }?.name ?: all
    val label = stringResource(R.string.config_redirects_city)
    Text(label, style = MaterialTheme.typography.labelMedium)
    Box {
        OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = "$label: $current" }) {
            Text(current, modifier = Modifier.weight(1f))
            Icon(Icons.Outlined.ExpandMore, contentDescription = null)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(text = { Text(all) }, onClick = { open = false; onSelect(null) }, modifier = Modifier.heightIn(min = 48.dp))
            options.cities.forEach { city ->
                DropdownMenuItem(text = { Text(city.name) }, onClick = { open = false; onSelect(city.id) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
    }
    Text(stringResource(R.string.config_redirects_city_help), style = MaterialTheme.typography.bodySmall, color = Muted)
}

// ------------------------------------------------------------------- cache

@Composable
internal fun CacheContent(
    state: CacheUiState,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onAskClear: () -> Unit,
    onDismissClear: () -> Unit,
    onClear: () -> Unit,
    onVerify: () -> Unit,
    onWeb: () -> Unit,
) {
    ManagementScaffold(title = stringResource(R.string.config_cache_title), padding = padding, onBack = onBack, subtitle = stringResource(R.string.config_cache_intro), busy = state.busy) {
        state.message?.let { Text(it.resolve(), color = if (state.redisOk == false) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary) }
        ErrorText(state.error)
        LoadState(state.cache, onRetry = onRetry) { cache -> CacheDetails(cache) }
        if (state.cache is Loadable.Ready) {
            Button(onClick = onAskClear, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.config_cache_clear)) }
            OutlinedButton(onClick = onVerify, enabled = !state.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.config_cache_verify)) }
            WebOnly(stringResource(R.string.config_cache_web_only), onWeb)
        }
    }
    if (state.confirmingClear) {
        ConfirmDialog(
            title = stringResource(R.string.config_cache_clear),
            text = stringResource(R.string.config_cache_clear_confirm),
            confirmLabel = stringResource(R.string.config_cache_clear_action),
            onConfirm = onClear,
            onDismiss = onDismissClear,
            enabled = !state.busy,
        )
    }
}

@Composable
private fun CacheDetails(cache: CacheSettingsView) {
    SectionTitle(stringResource(R.string.config_cache_server))
    Text(stringResource(R.string.config_cache_server_detected, cache.environment.server))
    Field(stringResource(R.string.config_cache_redis_extension), stringResource(if (cache.environment.redisExtension) R.string.config_cache_available else R.string.config_cache_unavailable))
    SectionTitle(stringResource(R.string.config_cache_pages))
    Field(stringResource(R.string.config_cache_managed), yesNo(cache.managed))
    Field(stringResource(R.string.config_cache_enabled), yesNo(cache.enabled))
    Field(stringResource(R.string.config_cache_store), cache.storeLabel.ifBlank { cache.store })
    Text(stringResource(R.string.config_cache_ttl, cache.ttlMinutes))
    SectionTitle(stringResource(R.string.config_cache_redis))
    Text(stringResource(R.string.config_cache_redis_host, cache.redis.host, cache.redis.port, cache.redis.database))
    Field(stringResource(R.string.config_mail_username), cache.redis.username)
    Field(stringResource(R.string.config_mail_password), stringResource(if (cache.redis.hasPassword) R.string.config_password_saved else R.string.config_password_missing))
    Field(stringResource(R.string.config_cache_redis_tls), yesNo(cache.redis.tls))
}
