package it.fabiodalez.incitta.ui.editorial.community

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CommunitySection
import it.fabiodalez.incitta.data.editorial.DeskCommunityItem
import it.fabiodalez.incitta.data.management.ManagementOptions
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.editorial.common.ListTab
import it.fabiodalez.incitta.ui.editorial.common.PagedListScreen
import it.fabiodalez.incitta.ui.editorial.common.PagedState
import it.fabiodalez.incitta.ui.editorial.common.ReasonDialog
import it.fabiodalez.incitta.ui.editorial.common.abilitiesOf
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementRow
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.UiText
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.feedback.feedbackDate
import java.time.ZoneId

/* Le schermate di «Profili e post» (R7) e il menu dell'area. Le azioni di ogni riga sono solo quelle che le `abilities` del server permettono. */

// --------------------------------------------------------------------- menu

@Composable
internal fun CommunityHubScreen(viewModel: CommunityHubViewModel, title: String, padding: PaddingValues, onBack: () -> Unit, onOpen: (CommunityPage) -> Unit) {
    val desk by viewModel.desk.collectAsStateWithLifecycle()
    ManagementScaffold(title = title, padding = padding, onBack = onBack, subtitle = stringResource(R.string.ed_community_hub_intro)) {
        val current = desk
        if (current == null) {
            LoadState<Unit>(Loadable.Loading, onRetry = viewModel::refresh) {}
        } else {
            val pages = communityPages(current.can)
            pages.forEach { page ->
                val badge = page.badge?.let(current::badge)?.takeIf { it > 0 }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { ManagementRow(stringResource(page.title), stringResource(page.help), onClick = { onOpen(page) }) }
                    if (badge != null) Badge(Modifier.padding(start = 8.dp)) { Text(stringResource(R.string.editorial_badge, badge)) }
                }
            }
            if (pages.isEmpty()) Text(stringResource(R.string.ed_community_nothing_allowed), color = Muted)
        }
    }
}

// ----------------------------------------------------------------- in comune

@Composable
internal fun DeskMessage(text: UiText?) {
    text?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
}

@Composable
internal fun DeskRowCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().border(1.dp, MaterialTheme.colorScheme.outlineVariant).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp), content = content)
}

/** Un'etichetta di stato accanto al nome («In evidenza», «Sospeso», «Nascosto»). */
@Composable
internal fun DeskBadge(text: String, alert: Boolean = false) {
    Surface(
        color = if (alert) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.secondaryContainer,
        contentColor = if (alert) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSecondaryContainer,
        shape = MaterialTheme.shapes.small,
    ) { Text(text, style = MaterialTheme.typography.labelMedium, modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp)) }
}

/** Il messaggio d'errore della finestra del motivo: quello del campo, altrimenti quello generale. */
internal fun reasonError(errors: FormErrors, field: String = "reason"): String? = errors.field(field) ?: errors.general

// ------------------------------------------------------------ profili e post

@Composable
internal fun CommunityModerationScreen(viewModel: CommunityModerationViewModel, padding: PaddingValues, zone: ZoneId, onBack: () -> Unit) {
    val list by viewModel.list.current.collectAsStateWithLifecycle()
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val options by viewModel.options.collectAsStateWithLifecycle()
    CommunityModerationList(list, state, padding, zone, onBack, viewModel::selectSection, viewModel::search, viewModel.list::loadMore, viewModel.list::retry, viewModel::ask)
    state.pending?.let { pending -> CommunityActionDialog(pending, state.busy, state.errors, options, onConfirm = viewModel::confirm, onDismiss = viewModel::dismiss) }
}

@Composable
internal fun CommunityModerationList(
    list: PagedState<DeskCommunityItem>,
    state: CommunityUiState,
    padding: PaddingValues,
    zone: ZoneId,
    onBack: () -> Unit,
    onSection: (String) -> Unit,
    onSearch: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRetry: () -> Unit,
    onAction: (DeskCommunityItem, CommunityAction) -> Unit,
) {
    val section = list.query.tab ?: CommunitySection.PROFILES
    val tabs = listOf(
        ListTab(CommunitySection.PROFILES, stringResource(R.string.ed_community_section_profiles)),
        ListTab(CommunitySection.POSTS, stringResource(R.string.ed_community_section_posts)),
        ListTab(CommunitySection.COMMENTS, stringResource(R.string.ed_community_section_comments)),
        ListTab(CommunitySection.RESTRICTIONS, stringResource(R.string.ed_community_section_restrictions)),
    )
    PagedListScreen(
        title = stringResource(R.string.ed_community_page_title),
        padding = padding,
        state = list,
        onBack = onBack,
        onSearch = onSearch,
        onLoadMore = onLoadMore,
        onRetry = onRetry,
        rowKey = { it.key },
        tabs = tabs,
        onTab = { key -> key?.let(onSection) },
        searchLabel = stringResource(if (section == CommunitySection.POSTS || section == CommunitySection.COMMENTS) R.string.ed_community_search_text else R.string.ed_community_search_name),
        emptyText = stringResource(
            when (section) {
                CommunitySection.POSTS -> R.string.ed_community_empty_posts
                CommunitySection.COMMENTS -> R.string.ed_community_empty_comments
                CommunitySection.RESTRICTIONS -> R.string.ed_community_empty_restrictions
                else -> R.string.ed_community_empty_profiles
            },
        ),
        header = {
            Text(stringResource(R.string.ed_community_reason_help), color = Muted, style = MaterialTheme.typography.bodySmall)
            DeskMessage(state.message)
        },
    ) { item -> CommunityRow(item, zone, enabled = !state.busy, onAction = { action -> onAction(item, action) }) }
}

@Composable
private fun CommunityRow(item: DeskCommunityItem, zone: ZoneId, enabled: Boolean, onAction: (CommunityAction) -> Unit) {
    val actions = communityActions(item, abilitiesOf(item.abilities))
    DeskRowCard {
        when (item.kind) {
            "profile" -> {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(item.displayName.orEmpty(), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    item.handle?.let { Text(stringResource(R.string.ed_community_handle, it), color = Muted) }
                    if (item.featured) DeskBadge(stringResource(R.string.ed_community_featured))
                    if (item.suspended) DeskBadge(stringResource(R.string.ed_community_suspended), alert = true)
                }
                item.bio?.takeIf { it.isNotBlank() }?.let { Text(it) }
                item.visibilityLabel?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
            }
            "restriction" -> {
                Text(item.name.orEmpty(), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                val restrictionZone = item.timezone?.let { runCatching { ZoneId.of(it) }.getOrNull() } ?: zone
                val date = feedbackDate(item.startsAt, restrictionZone) ?: stringResource(R.string.ed_carpool_none)
                Text(stringResource(R.string.ed_community_restricted_occurrence, item.eventTitle.orEmpty(), date))
                Text(stringResource(R.string.ed_community_restricted_occurrence_id, item.occurrenceId), style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            else -> {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(item.author ?: stringResource(R.string.ed_community_member), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                    Text("#${item.id}", color = Muted)
                    if (item.hidden) DeskBadge(stringResource(R.string.ed_community_hidden), alert = true)
                }
                Text(item.body.orEmpty())
                item.eventTitle?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
            }
        }
        if (actions.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            actions.forEach { action ->
                val destructive = action == CommunityAction.Suspend || action == CommunityAction.Hide
                OutlinedButton(onClick = { onAction(action) }, enabled = enabled, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(stringResource(communityActionLabel(action)), color = if (destructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
                }
            }
        }
    }
}

private fun communityActionLabel(action: CommunityAction): Int = when (action) {
    CommunityAction.Feature -> R.string.ed_community_feature
    CommunityAction.Unfeature -> R.string.ed_community_unfeature
    CommunityAction.Suspend -> R.string.ed_community_suspend
    CommunityAction.Unsuspend -> R.string.ed_community_unsuspend
    CommunityAction.Hide -> R.string.ed_community_hide
    CommunityAction.Restore -> R.string.ed_community_restore
    CommunityAction.RemoveRestriction -> R.string.ed_community_remove_restriction
}

@Composable
private fun CommunityActionDialog(pending: CommunityPendingAction, busy: Boolean, errors: FormErrors, options: ManagementOptions?, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    val (title, text) = when (pending.action) {
        CommunityAction.Feature -> R.string.ed_community_feature_title to R.string.ed_community_feature_text
        CommunityAction.Unfeature -> R.string.ed_community_unfeature_title to R.string.ed_community_feature_text
        CommunityAction.Suspend -> R.string.ed_community_suspend_title to R.string.ed_community_suspend_text
        CommunityAction.Unsuspend -> R.string.ed_community_unsuspend_title to R.string.ed_community_unsuspend_text
        CommunityAction.Hide -> R.string.ed_community_hide_title to if (pending.item.kind == "post") R.string.ed_community_hide_post_text else R.string.ed_community_hide_comment_text
        CommunityAction.Restore -> R.string.ed_community_restore_title to R.string.ed_community_restore_text
        CommunityAction.RemoveRestriction -> R.string.ed_community_remove_restriction_title to R.string.ed_community_remove_restriction_text
    }
    ReasonDialog(
        title = stringResource(title),
        text = stringResource(text),
        confirmLabel = stringResource(R.string.ed_community_confirm),
        onConfirm = onConfirm,
        onDismiss = onDismiss,
        rules = reasonRules(options),
        destructive = pending.action == CommunityAction.Suspend || pending.action == CommunityAction.Hide,
        busy = busy,
        error = reasonError(errors),
    )
}

/** Il pulsante che apre una pagina del sito, con l'icona «apri fuori». */
@Composable
internal fun OpenOnWebButton(label: String, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
        Text(label, Modifier.padding(start = 8.dp))
    }
}

/** Una riga che si apre: tutta la superficie è il pulsante, con l'etichetta per il lettore di schermo. */
internal fun Modifier.openable(label: String, onClick: () -> Unit): Modifier =
    this.clickable(onClickLabel = label, role = Role.Button, onClick = onClick).heightIn(min = 48.dp)
