package it.fabiodalez.incitta.ui.editorial.analytics

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.SeoAuditRow
import it.fabiodalez.incitta.data.editorial.SeoCodeProblem
import it.fabiodalez.incitta.data.editorial.SeoPage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.resolve
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter

/*
 * «SEO e qualità del catalogo» (R-ST-04, R-ST-05), nello stesso ordine della
 * pagina del pannello: il modulo di Search Console, «Come si collega», gli
 * strumenti esterni (si aprono nel browser), il controllo del catalogo con le
 * schede «Da sistemare» e «Tutti». I testi arrivano dal server, gli stessi
 * del pannello.
 */

@Composable
internal fun SeoScreen(viewModel: SeoViewModel, padding: PaddingValues, onBack: () -> Unit) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val audit by viewModel.audit.current.collectAsStateWithLifecycle()
    val uri = LocalUriHandler.current
    SeoContent(
        state = state,
        audit = audit,
        padding = padding,
        onBack = onBack,
        onRetry = viewModel::load,
        onCity = viewModel::selectCity,
        onCode = viewModel::setCode,
        onSave = viewModel::save,
        onTab = viewModel::selectTab,
        onLoadMore = viewModel.audit::loadMore,
        onRetryAudit = viewModel.audit::retry,
        onOpenUrl = { url -> runCatching { uri.openUri(url) } },
        onMessageShown = viewModel::consumeMessage,
    )
}

@Composable
internal fun SeoContent(
    state: SeoUiState,
    audit: PagedState<SeoAuditRow>,
    padding: PaddingValues,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onCity: (Long) -> Unit,
    onCode: (String) -> Unit,
    onSave: () -> Unit,
    onTab: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRetryAudit: () -> Unit,
    onOpenUrl: (String) -> Unit,
    onMessageShown: () -> Unit = {},
) {
    val page = (state.page as? Loadable.Ready)?.value
    val listState = rememberLazyListState()
    val latestLoadMore by rememberUpdatedState(onLoadMore)
    LaunchedEffect(listState) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .distinctUntilChanged()
            .filter { (last, total) -> last != null && total > 0 && last >= total - 3 }
            .collect { latestLoadMore() }
    }
    LazyColumn(
        Modifier.fillMaxSize().padding(padding).padding(horizontal = 18.dp),
        state = listState,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item(key = "seo-head") {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Spacer(Modifier.height(4.dp))
                IconButton(onClick = onBack, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = stringResource(R.string.management_back))
                }
                Text(page?.title?.ifBlank { null } ?: stringResource(R.string.editorial_analytics_seo_title), style = MaterialTheme.typography.headlineLarge)
                if (page == null) LoadState(state.page, onRetry = onRetry) {}
            }
        }
        if (page != null) {
            item(key = "seo-console") { SearchConsoleForm(page, state, onCity, onCode, onSave, onMessageShown) }
            item(key = "seo-steps") { SeoStepsSection(page) }
            item(key = "seo-tools") { SeoToolsSection(page, onOpenUrl) }
            item(key = "seo-audit-head") {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(page.audit.heading, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                    Text(page.audit.help, color = Muted, style = MaterialTheme.typography.bodySmall)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        page.audit.tabs.forEach { tab ->
                            val count = audit.tabCounts[tab.key]
                            FilterChip(
                                selected = audit.query.tab == tab.key,
                                onClick = { onTab(tab.key) },
                                label = { Text(if (count != null) stringResource(R.string.editorial_list_tab_count, tab.label, count) else tab.label) },
                                modifier = Modifier.heightIn(min = 48.dp),
                            )
                        }
                    }
                }
            }
            items(audit.items, key = { "seo-row-" + it.key }) { row -> SeoAuditItem(row, page.audit.complete, onOpenUrl) }
            item(key = "seo-audit-footer") {
                when {
                    audit.loading -> {
                        val label = stringResource(R.string.editorial_list_loading_more)
                        LinearProgressIndicator(Modifier.fillMaxWidth().padding(vertical = 12.dp).semantics { contentDescription = label })
                    }
                    audit.error != null -> Column(Modifier.padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(audit.error, color = MaterialTheme.colorScheme.error)
                        OutlinedButton(onClick = onRetryAudit, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_retry)) }
                    }
                    audit.isEmpty -> Column(Modifier.padding(vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(page.audit.empty, style = MaterialTheme.typography.titleMedium)
                        Text(page.audit.complete, color = Muted)
                    }
                    else -> Spacer(Modifier.height(24.dp))
                }
            }
        }
    }
}

/** Il modulo «Collega Google Search Console»: città e codice, con le regole del campo del pannello. */
@Composable
private fun SearchConsoleForm(page: SeoPage, state: SeoUiState, onCity: (Long) -> Unit, onCode: (String) -> Unit, onSave: () -> Unit, onMessageShown: () -> Unit) {
    val console = page.searchConsole
    Surface(shape = MaterialTheme.shapes.medium, tonalElevation = 1.dp, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(console.title, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
            Text(console.help, color = Muted, style = MaterialTheme.typography.bodySmall)
            Text(console.cityLabel.ifBlank { stringResource(R.string.editorial_seo_city) }, style = MaterialTheme.typography.titleSmall)
            Column {
                console.cities.forEach { city ->
                    Row(
                        Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = state.cityId == city.id, role = Role.RadioButton, enabled = !state.saving) { onCity(city.id) },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        RadioButton(selected = state.cityId == city.id, onClick = null)
                        Spacer(Modifier.width(8.dp))
                        Text(if (city.isActive) city.name else stringResource(R.string.editorial_seo_inactive, city.name))
                    }
                }
            }
            state.errors.field(SeoViewModel.FIELD_CITY)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            val problem = state.codeProblem
            val fieldError = state.errors.field(SeoViewModel.FIELD_CODE) ?: when (problem) {
                SeoCodeProblem.TooLong -> stringResource(R.string.editorial_seo_code_too_long, console.max)
                SeoCodeProblem.Invalid -> stringResource(R.string.editorial_seo_code_invalid)
                null -> null
            }
            OutlinedTextField(
                value = state.code,
                onValueChange = onCode,
                label = { Text(console.codeLabel) },
                supportingText = { Text(fieldError ?: console.codeHelp, color = if (fieldError != null) MaterialTheme.colorScheme.error else Muted) },
                isError = fieldError != null,
                singleLine = true,
                enabled = !state.saving,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                modifier = Modifier.fillMaxWidth(),
            )
            state.errors.general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            state.message?.let { message ->
                Text(message.resolve(), color = MaterialTheme.colorScheme.primary)
                LaunchedEffect(message) { kotlinx.coroutines.delay(4000); onMessageShown() }
            }
            Button(onClick = onSave, enabled = state.canSave, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(if (state.saving) stringResource(R.string.editorial_seo_saving) else console.saveLabel)
            }
        }
    }
}

/** «Come si collega»: i quattro passi, con l'indirizzo del sito e della sitemap da copiare. */
@Composable
private fun SeoStepsSection(page: SeoPage) {
    val context = LocalContext.current
    var copied by remember { mutableStateOf<String?>(null) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(page.steps.heading, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        page.steps.items.forEachIndexed { index, step ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("${index + 1}. ${step.text}")
                step.code?.let { code ->
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(code, fontFamily = FontFamily.Monospace, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                        TextButton(onClick = { if (copyText(context, step.text, code)) copied = code }, modifier = Modifier.heightIn(min = 48.dp)) {
                            Text(stringResource(if (copied == code) R.string.editorial_seo_copied else R.string.editorial_seo_copy))
                        }
                    }
                }
            }
        }
    }
}

/** «Strumenti esterni»: si aprono nel browser, come le schede nuove del pannello. */
@Composable
private fun SeoToolsSection(page: SeoPage, onOpenUrl: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(page.tools.heading, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(page.tools.description, color = Muted, style = MaterialTheme.typography.bodySmall)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            page.tools.links.forEach { tool ->
                OutlinedButton(onClick = { onOpenUrl(tool.url) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(tool.label) }
            }
        }
    }
}

/** Una riga del controllo: il titolo apre la pagina pubblica, sotto i problemi trovati o «a posto». */
@Composable
internal fun SeoAuditItem(row: SeoAuditRow, complete: String, onOpenUrl: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        val openLabel = stringResource(R.string.editorial_seo_open_event, row.title)
        TextButton(
            onClick = { onOpenUrl(row.url) },
            modifier = Modifier.heightIn(min = 48.dp).semantics { contentDescription = openLabel },
            contentPadding = PaddingValues(0.dp),
        ) { Text(row.title, style = MaterialTheme.typography.titleMedium) }
        if (row.issues.isEmpty()) {
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Outlined.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                Text(complete.ifBlank { stringResource(R.string.editorial_seo_complete) }, color = MaterialTheme.colorScheme.primary)
            }
        } else {
            row.issues.forEach { issue ->
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.semantics(mergeDescendants = true) {}) {
                    Icon(Icons.Outlined.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                    Text(issue)
                }
            }
        }
        HorizontalDivider()
    }
}

private fun copyText(context: Context, label: String, text: String): Boolean {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return false
    clipboard.setPrimaryClip(ClipData.newPlainText(label, text))
    return true
}
