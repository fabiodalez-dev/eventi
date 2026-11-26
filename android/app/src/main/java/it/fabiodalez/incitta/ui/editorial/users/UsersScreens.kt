package it.fabiodalez.incitta.ui.editorial.users

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.PersonAdd
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialPerson
import it.fabiodalez.incitta.data.editorial.UserChoice
import it.fabiodalez.incitta.data.editorial.UsersOptions
import it.fabiodalez.incitta.data.editorial.WhatsappAttempt
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ActionMenu
import it.fabiodalez.incitta.ui.editorial.common.FilterDefinition
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.ReasonRules
import it.fabiodalez.incitta.ui.editorial.common.RowAction
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Le schermate di R5: l'elenco «Utenti» con schede, filtri e menu della riga,
 * la scheda della persona con modulo, ruoli e azioni, «Nuovo utente», le
 * finestre di conferma, del nome utente e dello storico WhatsApp. Si vede
 * solo quello che `abilities` permette.
 */

/** Una data del server nel fuso della città di lavoro: «26 nov 2026, 14:05». */
internal fun userDate(raw: String?, zone: ZoneId): String? =
    raw?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.ITALIAN)) }.getOrNull() }

/** I filtri del pannello, con le scelte che arrivano da `users/options`. */
@Composable
internal fun userFilters(options: UsersOptions?): List<FilterDefinition> {
    val yesNo = listOf(OptionChoice("1", stringResource(R.string.editorial_users_yes)), OptionChoice("0", stringResource(R.string.editorial_users_no)))
    return buildList {
        options?.let { add(FilterDefinition("registration", stringResource(R.string.editorial_users_filter_registration), it.registrationStates.map { choice -> choice.toOption() })) }
        options?.takeIf { it.cities.isNotEmpty() }?.let { add(FilterDefinition("city", stringResource(R.string.editorial_users_filter_city), it.cities.map { choice -> choice.toOption() })) }
        options?.takeIf { it.cities.isNotEmpty() }?.let { add(FilterDefinition("venue_city", stringResource(R.string.editorial_users_filter_venue_city), it.cities.map { choice -> choice.toOption() })) }
        options?.takeIf { it.venues.isNotEmpty() }?.let { add(FilterDefinition("venue", stringResource(R.string.editorial_users_filter_venue), it.venues.map { choice -> choice.toOption() })) }
        add(FilterDefinition("missing_city", stringResource(R.string.editorial_users_filter_missing_city), yesNo))
        options?.let { add(FilterDefinition("role", stringResource(R.string.editorial_users_filter_role), it.roles.map { choice -> choice.toOption() })) }
        add(FilterDefinition("email_verified", stringResource(R.string.editorial_users_filter_email_verified), yesNo))
        add(FilterDefinition("whatsapp_verified", stringResource(R.string.editorial_users_filter_whatsapp), yesNo))
        add(FilterDefinition("suspended", stringResource(R.string.editorial_users_filter_suspended), yesNo))
        add(
            FilterDefinition(
                "trashed",
                stringResource(R.string.editorial_users_filter_trashed),
                listOf(OptionChoice("with", stringResource(R.string.editorial_users_trashed_with)), OptionChoice("only", stringResource(R.string.editorial_users_trashed_only))),
            ),
        )
    }
}

/** L'elenco: le schede del pannello con i conteggi, la ricerca, i filtri, la riga con il menu «⋮». */
@Composable
internal fun UsersListScreen(
    viewModel: UsersListViewModel,
    padding: PaddingValues,
    canCreate: Boolean,
    zone: ZoneId,
    onBack: () -> Unit,
    onOpen: (EditorialPerson) -> Unit,
    onCreate: () -> Unit,
) {
    val state by viewModel.rows.collectAsState()
    val options by viewModel.options.collectAsState()
    val actions by viewModel.actions.current.collectAsState()
    val tabs = USER_TABS.map { (key, label) -> ListTab(key, stringResource(label)) }
    val labels = UserAction.entries.associate { it.key to stringResource(it.label) }
    PagedListScreen(
        title = stringResource(R.string.editorial_users_title),
        padding = padding,
        state = state,
        onBack = onBack,
        onSearch = viewModel::search,
        onLoadMore = viewModel.list::loadMore,
        onRetry = viewModel.list::retry,
        rowKey = { it.id },
        tabs = tabs,
        onTab = viewModel::selectTab,
        filters = userFilters((options as? Loadable.Ready)?.value),
        onFilters = viewModel::applyFilters,
        searchLabel = stringResource(R.string.editorial_users_search),
        emptyText = stringResource(R.string.editorial_users_empty),
        header = {
            Text(stringResource(R.string.editorial_users_intro), color = Muted, style = MaterialTheme.typography.bodyMedium)
            if (canCreate) {
                Button(onClick = onCreate, modifier = Modifier.heightIn(min = 48.dp)) {
                    Icon(Icons.Outlined.PersonAdd, contentDescription = null)
                    Text(stringResource(R.string.editorial_users_new), Modifier.padding(start = 8.dp))
                }
            }
            actions.message?.let { MessageLine(it.resolve(), onDismiss = viewModel.actions::clearMessage) }
        },
    ) { user ->
        UserRow(user, zone, labels, onOpen = { onOpen(user) }, onAction = { key -> UserAction.of(key)?.let { viewModel.actions.request(it, user) } })
    }
    UserActionDialogs(viewModel.actions, actions, zone)
}

@Composable
private fun MessageLine(text: String, onDismiss: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(text, color = MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
        TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_users_close)) }
    }
}

/** Una persona nell'elenco: nome (o email), stato dell'iscrizione, ruoli, locali, segni di sospensione e cestino. */
@Composable
internal fun UserRow(user: EditorialPerson, zone: ZoneId, labels: Map<String, String>, onOpen: () -> Unit, onAction: (String) -> Unit) {
    val open = stringResource(R.string.editorial_users_action_open)
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth().padding(start = 14.dp, top = 10.dp, bottom = 10.dp, end = 4.dp), verticalAlignment = Alignment.Top) {
            Column(
                Modifier.weight(1f).clickable(onClickLabel = open, role = Role.Button, onClick = onOpen).heightIn(min = 48.dp),
                verticalArrangement = Arrangement.spacedBy(3.dp),
            ) {
                Text(user.name, style = MaterialTheme.typography.titleMedium)
                Text(if (user.hasName) user.email else stringResource(R.string.editorial_users_no_name), color = Muted, style = MaterialTheme.typography.bodySmall)
                val roles = user.roles.joinToString(", ") { it.label }.ifEmpty { stringResource(R.string.editorial_users_role_user) }
                Text(listOf(user.registration.label, roles).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.bodyMedium)
                user.memberships.take(3).forEach { m ->
                    Text(stringResource(R.string.editorial_users_membership, m.venueName, m.cityName ?: "—", m.roleLabel), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
                userDate(user.createdAt, zone)?.let { Text(stringResource(R.string.editorial_users_registered, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
                val flags = listOfNotNull(
                    stringResource(R.string.editorial_users_badge_suspended).takeIf { user.suspended },
                    stringResource(R.string.editorial_users_badge_trashed).takeIf { user.trashed },
                )
                if (flags.isNotEmpty()) Text(flags.joinToString(" · "), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelLarge)
            }
            ActionMenu(
                actions = UserAction.entries.map { it.toRowAction(labels) },
                abilities = user.can,
                onAction = { onAction(it.key) },
                subject = user.name,
            )
        }
    }
}

private fun UserAction.toRowAction(labels: Map<String, String>) = RowAction(key, labels[key] ?: key, destructive)

/** Le finestre delle azioni: conferma, nome utente con motivo, storico WhatsApp. */
@Composable
internal fun UserActionDialogs(actions: UserActions, state: UserActionsState, zone: ZoneId) {
    state.pending?.let { pending ->
        val name = pending.user.name
        if (pending.action == UserAction.Handle) {
            HandleDialog(pending.user, busy = state.busy, error = state.error, onConfirm = { handle, reason -> actions.confirm(handle, reason) }, onDismiss = actions::dismiss)
        } else {
            val (title, text) = when (pending.action) {
                UserAction.Suspend -> stringResource(R.string.editorial_users_confirm_suspend_title, name) to stringResource(R.string.editorial_users_confirm_suspend)
                UserAction.Unsuspend -> stringResource(R.string.editorial_users_confirm_unsuspend_title, name) to stringResource(R.string.editorial_users_confirm_unsuspend)
                UserAction.WhatsappRevoke -> stringResource(R.string.editorial_users_confirm_whatsapp_title) to stringResource(R.string.editorial_users_confirm_whatsapp, name)
                UserAction.Destroy -> stringResource(R.string.editorial_users_confirm_destroy_title, name) to stringResource(R.string.editorial_users_confirm_destroy)
                else -> stringResource(R.string.editorial_users_confirm_restore_title, name) to stringResource(R.string.editorial_users_confirm_restore)
            }
            ConfirmDialog(
                title = title,
                text = listOfNotNull(text, state.error).joinToString("\n\n"),
                confirmLabel = stringResource(pending.action.label),
                onConfirm = { actions.confirm() },
                onDismiss = actions::dismiss,
                destructive = pending.action.destructive,
                enabled = !state.busy,
            )
        }
    }
    state.history?.let { history -> WhatsappHistoryDialog(history, onDismiss = actions::closeHistory, zone = zone) }
}

/** «Cambia nome utente»: il nome nuovo e il motivo (da 5 a 1000 caratteri), come la finestra del pannello. */
@Composable
internal fun HandleDialog(user: EditorialPerson, busy: Boolean, error: String?, onConfirm: (String, String) -> Unit, onDismiss: () -> Unit, rules: ReasonRules = ReasonRules()) {
    var handle by rememberSaveable(user.id) { mutableStateOf(user.communityHandle.orEmpty()) }
    var reason by rememberSaveable(user.id) { mutableStateOf("") }
    val ready = handle.isNotBlank() && handle.length <= MAX_HANDLE && rules.accepts(reason)
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(stringResource(R.string.editorial_users_handle_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(R.string.editorial_users_handle_help))
                OutlinedTextField(handle, { handle = it.take(MAX_HANDLE) }, label = { Text(stringResource(R.string.editorial_users_handle_field)) }, singleLine = true, enabled = !busy, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    reason, { reason = it },
                    label = { Text(stringResource(R.string.editorial_users_handle_reason)) },
                    supportingText = { Text(stringResource(R.string.editorial_reason_help, rules.min, rules.max)) },
                    minLines = 3, enabled = !busy, modifier = Modifier.fillMaxWidth(),
                )
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton(enabled = ready && !busy, onClick = { onConfirm(handle.trim(), reason.trim()) }, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_users_handle_confirm))
            }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) } },
    )
}

private const val MAX_HANDLE = 40

/** Lo storico dei tentativi di verifica: data, ultime quattro cifre, esito, tentativi, scadenza. */
@Composable
internal fun WhatsappHistoryDialog(history: WhatsappHistoryState, onDismiss: () -> Unit, zone: ZoneId) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.editorial_users_history_title)) },
        text = {
            LoadState(history.attempts, onRetry = onDismiss, emptyText = stringResource(R.string.editorial_users_history_empty)) { attempts ->
                LazyColumn(Modifier.heightIn(max = 420.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(attempts, key = WhatsappAttempt::id) { attempt ->
                        Column {
                            Text(stringResource(R.string.editorial_users_history_row, userDate(attempt.createdAt, zone) ?: "—", attempt.phoneMasked), style = MaterialTheme.typography.titleSmall)
                            Text(attempt.statusLabel)
                            Text(stringResource(R.string.editorial_users_history_detail, attempt.attempts, userDate(attempt.expiresAt, zone) ?: "—"), color = Muted, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_users_close)) } },
    )
}

/** La scheda della persona: dati, community, ruoli, credenziali proprie, azioni. */
@Composable
internal fun UserDetailScreen(viewModel: UserDetailViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit, onSignedOut: () -> Unit, onOpenWeb: () -> Unit) {
    val state by viewModel.uiState.collectAsState()
    val actions by viewModel.actions.current.collectAsState()
    LaunchedEffect(state.signedOut) { if (state.signedOut) onSignedOut() }
    val user = (state.user as? Loadable.Ready)?.value
    ManagementScaffold(title = user?.name ?: stringResource(R.string.editorial_users_title), padding = padding, onBack = onBack, subtitle = user?.takeIf { it.hasName }?.email, busy = state.saving || actions.busy) {
        (state.message ?: actions.message)?.let { message ->
            MessageLine(message.resolve(), onDismiss = { viewModel.clearMessage(); viewModel.actions.clearMessage() })
        }
        LoadState(state.user, onRetry = viewModel::load) { loaded ->
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                UserFacts(loaded, zone)
                val canUpdate = loaded.allows("update")
                if (canUpdate) {
                    Text(stringResource(R.string.editorial_users_section_account), style = MaterialTheme.typography.titleLarge)
                    AccountForm(
                        draft = state.draft,
                        options = state.options,
                        errors = state.errors,
                        mode = DraftMode.Update,
                        busy = state.saving,
                        submitLabel = stringResource(R.string.editorial_users_save),
                        onChange = viewModel::editDraft,
                        onSubmit = viewModel::save,
                    )
                } else {
                    Text(stringResource(R.string.editorial_users_read_only), color = Muted)
                }
                if (loaded.allows("roles")) {
                    RolesSection(
                        current = loaded.form?.roles.orEmpty(),
                        selected = state.roles,
                        assignable = loaded.assignableRoles,
                        options = state.options,
                        errors = state.roleErrors,
                        busy = state.saving,
                        onToggle = viewModel::toggleRole,
                        onSave = viewModel::saveRoles,
                    )
                }
                if (viewModel.isSelf && loaded.allows("credentials")) {
                    CredentialsSection(state.credentials, state.credentialErrors, state.saving, viewModel::editCredentials, viewModel::saveCredentials)
                }
                val allowed = allowedActions(loaded)
                if (allowed.isNotEmpty()) {
                    Text(stringResource(R.string.editorial_users_section_actions), style = MaterialTheme.typography.titleLarge)
                    allowed.forEach { action ->
                        OutlinedButton(onClick = { viewModel.actions.request(action, loaded) }, enabled = !actions.busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                            Text(stringResource(action.label), color = if (action.destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
                        }
                    }
                }
                Text(stringResource(R.string.editorial_users_web_only), color = Muted, style = MaterialTheme.typography.bodySmall)
                TextButton(onClick = onOpenWeb, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_users_open_web)) }
            }
        }
    }
    UserActionDialogs(viewModel.actions, actions, zone)
}

/** I dati che si leggono soltanto: iscrizione, conferma dell'email, ultimo accesso, locali, community. */
@Composable
private fun UserFacts(user: EditorialPerson, zone: ZoneId) {
    val never = stringResource(R.string.editorial_users_never)
    val none = stringResource(R.string.editorial_users_none)
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Fact(stringResource(R.string.editorial_users_field_registration), user.registration.label, user.registration.description)
        Fact(stringResource(R.string.editorial_users_field_email_verified), userDate(user.emailVerifiedAt, zone) ?: never)
        Fact(stringResource(R.string.editorial_users_field_last_active), userDate(user.lastActiveAt, zone) ?: never)
        Fact(
            stringResource(R.string.editorial_users_field_venues),
            user.memberships.joinToString("\n") { "${it.venueName} (${it.cityName ?: "—"}) — ${it.roleLabel}" }.ifEmpty { stringResource(R.string.editorial_users_no_venues) },
        )
        if (user.venueCities.isNotEmpty()) Fact(stringResource(R.string.editorial_users_field_venue_cities), user.venueCities.joinToString(", "))
        Text(stringResource(R.string.editorial_users_section_community), style = MaterialTheme.typography.titleLarge)
        Fact(stringResource(R.string.editorial_users_field_handle), user.communityHandle ?: none)
        Fact(stringResource(R.string.editorial_users_field_whatsapp), userDate(user.whatsappVerifiedAt, zone) ?: never)
        Fact(stringResource(R.string.editorial_users_field_whatsapp_phone), user.whatsappMasked ?: none)
        Fact(stringResource(R.string.editorial_users_field_suspended), userDate(user.communitySuspendedAt, zone) ?: none)
        if (user.trashed) Text(stringResource(R.string.editorial_users_badge_trashed), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
private fun Fact(label: String, value: String, help: String? = null) {
    Column(Modifier.semantics(mergeDescendants = true) {}) {
        Text(label, color = Muted, style = MaterialTheme.typography.labelMedium)
        Text(value, style = MaterialTheme.typography.bodyLarge)
        help?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

/** Il modulo «Account»: città, nome, (email e password in creazione), fuso, lingua. */
@Composable
internal fun AccountForm(
    draft: UserDraft,
    options: UsersOptions?,
    errors: FormErrors,
    mode: DraftMode,
    busy: Boolean,
    submitLabel: String,
    onChange: ((UserDraft) -> UserDraft) -> Unit,
    onSubmit: () -> Unit,
    extra: @Composable ColumnScope.() -> Unit = {},
) {
    val resolve: (Int) -> String = LocalContext.current::getString
    FormScaffold(errors = errors, busy = busy, submitLabel = submitLabel, onSubmit = onSubmit) {
        CityField(draft.cityId, options?.cities.orEmpty(), localFieldMessage(errors.field("city_id"), resolve)) { id -> onChange { it.copy(cityId = id) } }
        UserTextField(draft.name, { value -> onChange { it.copy(name = value) } }, stringResource(R.string.editorial_users_field_name), localFieldMessage(errors.field("name"), resolve), enabled = !busy)
        if (mode == DraftMode.Create) {
            UserTextField(draft.email, { value -> onChange { it.copy(email = value) } }, stringResource(R.string.editorial_users_field_email), localFieldMessage(errors.field("email"), resolve), enabled = !busy, keyboard = KeyboardType.Email)
            UserTextField(
                draft.password, { value -> onChange { it.copy(password = value) } }, stringResource(R.string.editorial_users_field_password),
                localFieldMessage(errors.field("password"), resolve) ?: stringResource(R.string.editorial_users_field_password_help), enabled = !busy, password = true,
                isError = errors.field("password") != null,
            )
        }
        TimezoneField(draft.timezone, options?.timezones.orEmpty(), localFieldMessage(errors.field("timezone"), resolve)) { tz -> onChange { it.copy(timezone = tz) } }
        Text(stringResource(R.string.editorial_users_field_locale), style = MaterialTheme.typography.titleSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            (options?.locales ?: listOf("it", "en", "de")).forEach { locale ->
                FilterChip(selected = draft.locale == locale, onClick = { onChange { it.copy(locale = locale) } }, label = { Text(locale) }, modifier = Modifier.heightIn(min = 48.dp))
            }
        }
        localFieldMessage(errors.field("locale"), resolve)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        extra()
    }
}

@Composable
private fun UserTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    message: String?,
    enabled: Boolean,
    keyboard: KeyboardType = KeyboardType.Text,
    password: Boolean = false,
    isError: Boolean = message != null,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        enabled = enabled,
        singleLine = true,
        isError = isError,
        supportingText = message?.let { { Text(it) } },
        visualTransformation = if (password) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(keyboardType = if (password) KeyboardType.Password else keyboard),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** La città di riferimento: facoltativa, da un menu con «Non indicata». */
@Composable
private fun CityField(selected: Long?, cities: List<UserChoice>, error: String?, onSelect: (Long?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val none = stringResource(R.string.editorial_users_field_city_none)
    val current = cities.firstOrNull { it.value == selected?.toString() }?.label ?: none
    val label = stringResource(R.string.editorial_users_field_city)
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        Box {
            OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = "$label: $current" }) {
                Text(current, modifier = Modifier.weight(1f))
                Icon(Icons.Outlined.ExpandMore, contentDescription = null)
            }
            DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                DropdownMenuItem(text = { Text(none) }, onClick = { open = false; onSelect(null) }, modifier = Modifier.heightIn(min = 48.dp))
                cities.forEach { city ->
                    DropdownMenuItem(text = { Text(city.label) }, onClick = { open = false; onSelect(city.value.toLongOrNull()) }, modifier = Modifier.heightIn(min = 48.dp))
                }
            }
        }
        Text(error ?: stringResource(R.string.editorial_users_field_city_help), color = if (error != null) MaterialTheme.colorScheme.error else Muted, style = MaterialTheme.typography.bodySmall)
    }
}

/** Il fuso orario: un pulsante che apre l'elenco dei fusi con la ricerca (sono più di quattrocento). */
@Composable
private fun TimezoneField(selected: String, timezones: List<String>, error: String?, onSelect: (String) -> Unit) {
    var open by rememberSaveable { mutableStateOf(false) }
    val label = stringResource(R.string.editorial_users_field_timezone)
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, style = MaterialTheme.typography.titleSmall)
        OutlinedButton(onClick = { open = true }, enabled = timezones.isNotEmpty(), modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).semantics { contentDescription = "$label: $selected" }) {
            Text(selected, modifier = Modifier.weight(1f))
            Icon(Icons.Outlined.ExpandMore, contentDescription = null)
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
    }
    if (open) {
        var query by rememberSaveable { mutableStateOf("") }
        val shown = remember(query, timezones) { filterTimezones(timezones, query) }
        AlertDialog(
            onDismissRequest = { open = false },
            title = { Text(label) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(query, { query = it }, label = { Text(stringResource(R.string.editorial_users_field_timezone_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    LazyColumn(Modifier.heightIn(max = 360.dp)) {
                        items(shown, key = { it }) { tz ->
                            TextButton(onClick = { open = false; onSelect(tz) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                                Text(tz, modifier = Modifier.fillMaxWidth(), color = if (tz == selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { open = false }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_users_close)) } },
        )
    }
}

/** I fusi che contengono il testo cercato (senza badare a maiuscole, spazi o trattini bassi). */
internal fun filterTimezones(timezones: List<String>, query: String): List<String> {
    val needle = query.trim().lowercase().replace(' ', '_')
    return if (needle.isEmpty()) timezones else timezones.filter { needle in it.lowercase() }
}

/** I ruoli: spunte per quelli che si danno dall'app, gli altri (admin, super admin) in sola lettura. */
@Composable
internal fun RolesSection(
    current: List<String>,
    selected: Set<String>,
    assignable: List<String>,
    options: UsersOptions?,
    errors: FormErrors,
    busy: Boolean,
    onToggle: (String) -> Unit,
    onSave: (() -> Unit)?,
) {
    val choices = RoleChoices.of(current, assignable)
    val label: (String) -> String = { value -> options?.roleLabel(value) ?: value }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.editorial_users_section_roles), style = MaterialTheme.typography.titleLarge)
        Text(stringResource(R.string.editorial_users_roles_help), color = Muted, style = MaterialTheme.typography.bodySmall)
        choices.assignable.forEach { role ->
            Row(
                Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable(enabled = !busy, role = Role.Checkbox) { onToggle(role) },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Checkbox(checked = role in selected, onCheckedChange = null)
                Text(label(role), Modifier.padding(start = 8.dp))
            }
        }
        choices.webOnly.forEach { role -> Text(stringResource(R.string.editorial_users_roles_web, label(role)), color = Muted) }
        if (selected.none { it in assignable } && choices.webOnly.isEmpty()) Text(stringResource(R.string.editorial_users_roles_none), color = Muted, style = MaterialTheme.typography.bodySmall)
        (errors.general ?: errors.fields.values.firstOrNull())?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        onSave?.let { save ->
            Button(onClick = save, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_users_roles_save)) }
        }
    }
}

/** Le proprie email e password, con la conferma: dopo il cambio si rientra nell'app. */
@Composable
private fun CredentialsSection(draft: UserDraft, errors: FormErrors, busy: Boolean, onChange: ((UserDraft) -> UserDraft) -> Unit, onSave: () -> Unit) {
    var confirm by rememberSaveable { mutableStateOf(false) }
    val resolve: (Int) -> String = LocalContext.current::getString
    Text(stringResource(R.string.editorial_users_section_credentials), style = MaterialTheme.typography.titleLarge)
    Text(stringResource(R.string.editorial_users_credentials_help), color = Muted, style = MaterialTheme.typography.bodySmall)
    FormScaffold(errors = errors, busy = busy, submitLabel = stringResource(R.string.editorial_users_credentials_save), onSubmit = { confirm = true }) {
        UserTextField(draft.email, { value -> onChange { it.copy(email = value) } }, stringResource(R.string.editorial_users_field_email), localFieldMessage(errors.field("email"), resolve), enabled = !busy, keyboard = KeyboardType.Email)
        UserTextField(
            draft.password, { value -> onChange { it.copy(password = value) } }, stringResource(R.string.editorial_users_field_password),
            localFieldMessage(errors.field("password"), resolve) ?: stringResource(R.string.editorial_users_field_password_keep), enabled = !busy, password = true,
            isError = errors.field("password") != null,
        )
    }
    if (confirm) {
        ConfirmDialog(
            title = stringResource(R.string.editorial_users_credentials_title),
            text = stringResource(R.string.editorial_users_credentials_help),
            confirmLabel = stringResource(R.string.editorial_users_credentials_save),
            onConfirm = { confirm = false; onSave() },
            onDismiss = { confirm = false },
            destructive = true,
        )
    }
}

/** «Nuovo utente». */
@Composable
internal fun UserCreateScreen(viewModel: UserCreateViewModel, padding: PaddingValues, onBack: () -> Unit, onCreated: (Long) -> Unit) {
    val state by viewModel.uiState.collectAsState()
    LaunchedEffect(state.createdId) { state.createdId?.let { id -> viewModel.consumeCreated(); onCreated(id) } }
    ManagementScaffold(title = stringResource(R.string.editorial_users_new), padding = padding, onBack = onBack, busy = state.saving) {
        LoadState(state.options, onRetry = viewModel::loadOptions) { options ->
            AccountForm(
                draft = state.draft,
                options = options,
                errors = state.errors,
                mode = DraftMode.Create,
                busy = state.saving,
                submitLabel = stringResource(R.string.editorial_users_create),
                onChange = viewModel::edit,
                onSubmit = viewModel::create,
            ) {
                RolesSection(
                    current = emptyList(),
                    selected = state.roles,
                    assignable = options.assignableRoles,
                    options = options,
                    errors = FormErrors(fields = state.errors.fields.filterKeys { it == "roles" || it.startsWith("roles.") }),
                    busy = state.saving,
                    onToggle = viewModel::toggleRole,
                    onSave = null,
                )
            }
        }
    }
}
