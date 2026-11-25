package it.fabiodalez.incitta.ui.management.sponsorships

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.ManagementSubject
import it.fabiodalez.incitta.data.management.SPONSORSHIP_NOTE_MAX
import it.fabiodalez.incitta.data.management.SponsorshipCampaign
import it.fabiodalez.incitta.data.management.SponsorshipDesk
import it.fabiodalez.incitta.data.management.SponsorshipGrant
import it.fabiodalez.incitta.data.management.SponsorshipOrder
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.EventPickerSheet
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import java.time.ZoneId

/**
 * La pagina «Sponsorizzazioni» (W-SP-01…13): come funziona, il periodo già
 * attivo con cui mettere in evidenza gli eventi, la sponsorizzazione nuova
 * (solo per chi può comprare), richieste e ordini, le schede dei periodi con
 * avanzamento ed eventi, le campagne della redazione, il collegamento alle
 * statistiche. Chi non può comprare vede tutto senza i pulsanti d'acquisto.
 */
@Composable
internal fun SponsorshipsScreen(
    viewModel: SponsorshipsViewModel,
    context: ManagementGraphContext,
    onWizard: (subjectKey: String, eventId: Long) -> Unit,
    onCampaign: (subjectKey: String, campaignId: Long) -> Unit,
    onAnalytics: (subjectKey: String) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = state.subject.valueOrNull
    val uriHandler = LocalUriHandler.current
    var browserFailed by remember { mutableStateOf(false) }

    /* Di ritorno dal browser: se un pagamento era aperto, si chiede al server com'è andata. */
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { viewModel.onResume() }
    LaunchedEffect(state.checkoutUrl) {
        val url = state.checkoutUrl ?: return@LaunchedEffect
        viewModel.consumeCheckout()
        browserFailed = runCatching { uriHandler.openUri(url) }.isFailure
    }
    LaunchedEffect(state.openWizardFor, subject?.key) {
        val event = state.openWizardFor ?: return@LaunchedEffect
        val key = subject?.key ?: return@LaunchedEffect
        viewModel.consumeWizard()
        onWizard(key, event)
    }

    ManagementScaffold(
        title = stringResource(R.string.sponsorships_title),
        padding = context.padding,
        onBack = context::back,
        subtitle = subject?.name,
        busy = state.busy && state.desk is Loadable.Ready,
    ) {
        LoadState(state.subject, onRetry = viewModel::start, emptyText = stringResource(R.string.sponsorships_subject_missing)) { current ->
            state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
            state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
            if (browserFailed) Text(stringResource(R.string.sponsorships_checkout_unavailable), color = MaterialTheme.colorScheme.error)
            LoadState(state.desk, onRetry = viewModel::load) { desk ->
                DeskContent(desk, state, current, viewModel,
                    onWizard = { onWizard(current.key, 0L) },
                    onCampaign = { id -> onCampaign(current.key, id) },
                    onAnalytics = { onAnalytics(current.key) })
            }
        }
    }

    when (val pending = state.confirm) {
        is SponsorshipConfirm.Cancel -> ConfirmDialog(
            stringResource(R.string.sponsorships_order_cancel_title), stringResource(R.string.sponsorships_order_cancel_confirm),
            stringResource(R.string.sponsorships_order_cancel_action), onConfirm = viewModel::confirm, onDismiss = viewModel::dismissConfirm, destructive = true)
        is SponsorshipConfirm.Withdraw -> ConfirmDialog(
            stringResource(R.string.sponsorships_order_withdraw_title), stringResource(R.string.sponsorships_order_withdraw_confirm),
            stringResource(R.string.sponsorships_order_withdraw), onConfirm = viewModel::confirm, onDismiss = viewModel::dismissConfirm, destructive = true)
        is SponsorshipConfirm.Stop -> ConfirmDialog(
            stringResource(R.string.sponsorships_stop_title), stringResource(R.string.sponsorships_stop_confirm, pending.row.event?.title.orEmpty()),
            stringResource(R.string.sponsorships_stop), onConfirm = viewModel::confirm, onDismiss = viewModel::dismissConfirm, destructive = true)
        null -> Unit
    }

    val picker = state.picker
    val desk = state.desk.valueOrNull
    if (picker != null && subject != null && desk != null) {
        // Il selettore unico della gestione: lo stesso delle statistiche.
        val activeLabel = stringResource(R.string.sponsorships_active_on)
        EventPickerSheet(
            search = viewModel::pickerChoices,
            onPick = { choice -> viewModel.activate(choice.id) },
            onDismiss = viewModel::closePicker,
            title = stringResource(R.string.sponsorships_active_title),
            hint = stringResource(R.string.sponsorships_active_lead),
            emptyText = stringResource(R.string.sponsorships_no_events),
            reloadKey = picker.revision,
            enabled = !state.busy,
            pickLabel = stringResource(R.string.sponsorships_activate),
            pickable = { it.id !in picker.active },
            badge = { if (it.id in picker.active) activeLabel else null },
            header = {
                if (desk.selectableGrants.size > 1) GrantChooser(desk, state.grantId, subject.timezone, viewModel::chooseGrant)
                state.message?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.primary) }
                state.error?.let { Text(it.resolve(), color = MaterialTheme.colorScheme.error) }
                if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            },
        )
    }
}

@Composable
private fun DeskContent(
    desk: SponsorshipDesk,
    state: SponsorshipsUiState,
    subject: ManagementSubject,
    viewModel: SponsorshipsViewModel,
    onWizard: () -> Unit,
    onCampaign: (Long) -> Unit,
    onAnalytics: () -> Unit,
) {
    val zone = subject.timezone
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        // Come funziona: tre passi e la strada di pagamento di oggi.
        ElevatedCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(desk.how.title, style = MaterialTheme.typography.titleMedium)
                desk.how.steps.forEachIndexed { index, step -> Text("${index + 1}. $step") }
                if (desk.how.disclosure.isNotBlank()) Text(desk.how.disclosure, color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
        OutlinedButton(onClick = onAnalytics, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_analytics_open)) }

        // Un periodo già attivo con eventi scelti: si mettono in evidenza subito (anche il collaboratore).
        if (desk.selectableGrants.isNotEmpty()) {
            SponsorshipSection(stringResource(R.string.sponsorships_active_title), stringResource(R.string.sponsorships_active_lead))
            GrantChooser(desk, state.grantId, zone, viewModel::chooseGrant)
            Button(enabled = !state.busy, onClick = viewModel::openPicker, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.sponsorships_active_pick))
            }
        }

        // Una sponsorizzazione nuova: solo il titolare.
        val notOwner = desk.notOwner
        if (!desk.canPurchase && notOwner != null) {
            OutlinedCard(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(notOwner.title, style = MaterialTheme.typography.titleMedium)
                    Text(notOwner.body, color = Muted)
                }
            }
        } else if (desk.canPurchase) {
            Button(enabled = !state.busy, onClick = onWizard, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_new)) }
            Text(stringResource(R.string.sponsorships_new_hint), color = Muted, style = MaterialTheme.typography.bodySmall)
        }

        // Richieste e ordini.
        SponsorshipSection(stringResource(R.string.sponsorships_orders_title))
        if (desk.orders.isEmpty()) Text(stringResource(R.string.sponsorships_orders_none), color = Muted)
        desk.orders.forEach { order ->
            OrderCard(order, zone, enabled = !state.busy,
                onRetry = { viewModel.retry(order) }, onCancel = { viewModel.askCancel(order) }, onWithdraw = { viewModel.askWithdraw(order) })
        }

        // Le sponsorizzazioni: una scheda per periodo, poi le campagne della redazione.
        SponsorshipSection(stringResource(R.string.sponsorships_units_title), stringResource(R.string.sponsorships_units_lead))
        if (desk.measures.isNotBlank()) Text(desk.measures, color = Muted, style = MaterialTheme.typography.bodySmall)
        if (desk.grants.isEmpty() && state.campaigns.isEmpty()) {
            Text(stringResource(R.string.sponsorships_units_none), color = Muted)
        } else {
            Row(horizontalArrangement = Arrangement.spacedBy(24.dp)) {
                Box(Modifier.weight(1f)) {
                    SponsorshipMetric(stringResource(R.string.sponsorships_total_views), sponsorshipCount(desk.totals.impressions), stringResource(R.string.sponsorships_total_views_hint))
                }
                Box(Modifier.weight(1f)) {
                    SponsorshipMetric(stringResource(R.string.sponsorships_total_clicks), sponsorshipCount(desk.totals.clicks), stringResource(R.string.sponsorships_total_clicks_hint))
                }
            }
        }
        desk.grants.forEach { grant -> GrantCard(grant, zone, enabled = !state.busy, onStop = viewModel::askStop) }

        if (state.campaigns.isNotEmpty()) {
            SponsorshipSection(stringResource(R.string.sponsorships_singles_title), stringResource(R.string.sponsorships_singles_lead))
            state.campaigns.forEach { campaign -> EditorialCampaignCard(campaign, zone, onOpen = { onCampaign(campaign.id) }) }
            if (state.campaignsNextPage != null) {
                OutlinedButton(enabled = !state.busy, onClick = viewModel::moreCampaigns, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                    Text(stringResource(R.string.sponsorships_more_campaigns))
                }
            }
        }
        OutlinedButton(enabled = !state.busy, onClick = viewModel::load, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_refresh)) }
    }
}

/** I periodi attivi «a scelta»: con più d'uno si sceglie con quale mettere in evidenza. */
@Composable
private fun GrantChooser(desk: SponsorshipDesk, selected: Long?, zone: ZoneId, onChoose: (Long) -> Unit) {
    val label = stringResource(R.string.sponsorships_active_period)
    if (desk.selectableGrants.size == 1) {
        val grant = desk.selectableGrants.first()
        Text(stringResource(R.string.sponsorships_active_until, grant.placement.label, sponsorshipShortDate(grant.endsAt, zone).orEmpty()), color = Muted)
        return
    }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        desk.selectableGrants.forEach { grant ->
            FilterChip(
                selected = grant.id == selected,
                onClick = { onChoose(grant.id) },
                label = { Text(stringResource(R.string.sponsorships_active_until, grant.placement.label, sponsorshipShortDate(grant.endsAt, zone).orEmpty())) },
                modifier = Modifier.heightIn(min = 48.dp),
            )
        }
    }
    Text(label, color = Muted, style = MaterialTheme.typography.bodySmall)
}

/** Un ordine o una richiesta, come card: niente tabelle che scorrono sul telefono. */
@Composable
private fun OrderCard(order: SponsorshipOrder, zone: ZoneId, enabled: Boolean, onRetry: () -> Unit, onCancel: () -> Unit, onWithdraw: () -> Unit) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(stringResource(R.string.sponsorships_order_line, order.placement.label, order.monthsLabel), style = MaterialTheme.typography.titleMedium)
                    Text(order.what, color = Muted)
                    Text(listOfNotNull(sponsorshipMoment(order.createdAt, zone), order.amountLabel.takeIf { it.isNotBlank() }).joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
                SponsorshipBadge(order.status.label, order.status.color)
            }
            order.reason?.let { Text(it, color = Muted) }
            order.hint?.let { Text(it, color = Muted) }
            order.requestNote?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.sponsorships_order_note, it), color = Muted, style = MaterialTheme.typography.bodySmall) }
            if (order.canRetry || order.canCancel || order.canWithdraw) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (order.canRetry) Button(enabled = enabled, onClick = onRetry, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_order_retry)) }
                    if (order.canCancel) OutlinedButton(enabled = enabled, onClick = onCancel, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_order_cancel)) }
                    if (order.canWithdraw) OutlinedButton(enabled = enabled, onClick = onWithdraw, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_order_withdraw)) }
                }
            }
        }
    }
}

/** Quanti eventi mostra una scheda prima di «mostra gli altri», come il pannello. */
private const val GRANT_EVENTS_SHOWN = 5

/** La scheda di un periodo: posizione, stato, periodo e pagamento, avanzamento, eventi in vetrina con «Interrompi». */
@Composable
private fun GrantCard(grant: SponsorshipGrant, zone: ZoneId, enabled: Boolean, onStop: (it.fabiodalez.incitta.data.management.SponsorshipGrantEvent) -> Unit) {
    var open by rememberSaveable(grant.id) { mutableStateOf(false) }
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
                Text(grant.placement.label, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                SponsorshipBadge(grant.statusLabel, "gray")
            }
            Text(stringResource(R.string.sponsorships_grant_period, sponsorshipDay(grant.startsAt, zone).orEmpty(), sponsorshipDay(grant.endsAt, zone).orEmpty(), grant.modeLabel), color = Muted)
            Text(grant.paymentLabel + (grant.paidAt?.let { " " + sponsorshipDay(it, zone).orEmpty() } ?: ""), color = Muted, style = MaterialTheme.typography.bodySmall)
            SponsorshipProgressView(grant.progress)
            Text(stringResource(R.string.sponsorships_grant_events), style = MaterialTheme.typography.titleSmall)
            if (grant.events.isEmpty()) Text(stringResource(R.string.sponsorships_grant_events_none), color = Muted)
            val shown = if (open) grant.events else grant.events.take(GRANT_EVENTS_SHOWN)
            shown.forEach { row ->
                val event = row.event ?: return@forEach
                SponsorshipEventLine(event, zone, trailing = if (row.canStop) {
                    { OutlinedButton(enabled = enabled, onClick = { onStop(row) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_stop)) } }
                } else null) {
                    row.stoppedLabel?.let { SponsorshipBadge(it, "gray") }
                    Text(listOfNotNull(row.numbers, row.shareLabel).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                }
                HorizontalDivider()
            }
            val others = grant.events.size - GRANT_EVENTS_SHOWN
            if (others > 0) {
                OutlinedButton(onClick = { open = !open }, modifier = Modifier.heightIn(min = 48.dp)) {
                    Text(if (open) stringResource(R.string.sponsorships_show_less) else pluralStringResource(R.plurals.sponsorships_show_all, others, others))
                }
            }
        }
    }
}

/** Una campagna della redazione: evento, fase, posizione e fine, avanzamento; si apre la scheda. */
@Composable
private fun EditorialCampaignCard(campaign: SponsorshipCampaign, zone: ZoneId, onOpen: () -> Unit) {
    OutlinedCard(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            campaign.event?.let { event ->
                SponsorshipEventLine(event, zone) {
                    SponsorshipBadge(campaign.phase.label, campaign.phase.color)
                    Text(stringResource(R.string.sponsorships_campaign_ends, campaign.placement.label, sponsorshipMoment(campaign.endsAt, zone).orEmpty()), color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
            campaign.progress?.let { SponsorshipProgressView(it) }
            TextButton(onClick = onOpen, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_open_campaign)) }
        }
    }
}

// ------------------------------------------------ la scelta in quattro passi

/**
 * La sponsorizzazione nuova in quattro passi, come il pannello: cosa
 * (un evento o tutti), dove (la posizione), per quanto (la durata con il
 * prezzo), riepilogo con la nota per la redazione. «Vai al pagamento» apre
 * Stripe nel browser; «Invia la richiesta» manda la richiesta senza pagare.
 */
@Composable
internal fun SponsorshipWizardScreen(
    viewModel: SponsorshipWizardViewModel,
    context: ManagementGraphContext,
    onDone: (subjectKey: String, orderId: Long, sent: Boolean) -> Unit,
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val subject = state.subject.valueOrNull
    val uriHandler = LocalUriHandler.current

    BackHandler(enabled = state.step != WizardStep.What) { viewModel.previous() }
    LaunchedEffect(state.result, subject?.key) {
        val result = state.result ?: return@LaunchedEffect
        val key = subject?.key ?: return@LaunchedEffect
        viewModel.consumeResult()
        when (result) {
            is WizardResult.Checkout -> {
                runCatching { uriHandler.openUri(result.url) }
                onDone(key, result.orderId, false)
            }
            WizardResult.Requested -> onDone(key, 0L, true)
        }
    }

    ManagementScaffold(
        title = stringResource(R.string.sponsorships_wizard_title),
        padding = context.padding,
        onBack = { if (!viewModel.previous()) context.back() },
        subtitle = subject?.name,
        busy = state.busy,
    ) {
        LoadState(state.subject, onRetry = viewModel::start, emptyText = stringResource(R.string.sponsorships_subject_missing)) { current ->
            LoadState(state.catalog, onRetry = viewModel::start) { _ ->
                Text(stringResource(R.string.sponsorships_step_progress, state.step.ordinal + 1), color = Muted)
                val general = state.errors.general ?: state.errors.fields.values.firstOrNull()
                general?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                when (state.step) {
                    WizardStep.What -> StepWhat(state, current.timezone, viewModel)
                    WizardStep.Where -> StepWhere(state, viewModel)
                    WizardStep.Time -> StepTime(state, viewModel)
                    WizardStep.Summary -> StepSummary(state, viewModel)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (state.step != WizardStep.What) OutlinedButton(enabled = !state.busy, onClick = { viewModel.previous() }, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.sponsorships_previous))
                    }
                    if (state.step != WizardStep.Summary) Button(enabled = state.canAdvance && !state.busy, onClick = viewModel::next, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                        Text(stringResource(R.string.sponsorships_next))
                    }
                }
            }
        }
    }
}

/** Una scelta a card con il pallino, come i pulsanti «radio» del pannello. */
@Composable
private fun ChoiceCard(selected: Boolean, title: String, hint: String?, onClick: () -> Unit, extra: @Composable ColumnScope.() -> Unit = {}) {
    OutlinedCard(
        modifier = Modifier.fillMaxWidth().selectable(selected = selected, onClick = onClick, role = Role.RadioButton),
        border = if (selected) CardDefaults.outlinedCardBorder().copy(width = 2.dp, brush = androidx.compose.ui.graphics.SolidColor(MaterialTheme.colorScheme.primary)) else CardDefaults.outlinedCardBorder(),
        shape = RoundedCornerShape(12.dp),
    ) {
        Row(Modifier.padding(14.dp).heightIn(min = 48.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RadioButton(selected = selected, onClick = null)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                hint?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
                extra()
            }
        }
    }
}

@Composable
private fun StepWhat(state: SponsorshipWizardUiState, zone: ZoneId, viewModel: SponsorshipWizardViewModel) {
    SponsorshipSection(stringResource(R.string.sponsorships_step_what), stringResource(R.string.sponsorships_step_what_lead))
    ChoiceCard(state.scope == "event", stringResource(R.string.sponsorships_scope_event), stringResource(R.string.sponsorships_scope_event_hint), onClick = { viewModel.chooseScope("event") })
    ChoiceCard(state.scope == "all", stringResource(R.string.sponsorships_scope_all), stringResource(R.string.sponsorships_scope_all_hint), onClick = { viewModel.chooseScope("all") })
    if (state.scope != "event") return
    state.errors.field("event_id")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    if (state.eventsLoaded && state.eventsTotal == 0) {
        Text(stringResource(R.string.sponsorships_no_events), color = Muted)
        return
    }
    var query by rememberSaveable { mutableStateOf(state.query) }
    OutlinedTextField(query, { query = it.take(80) }, label = { Text(stringResource(R.string.sponsorships_search)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedButton(enabled = !state.eventsBusy, onClick = { viewModel.search(query) }, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_search_action)) }
    if (state.eventsBusy) LinearProgressIndicator(Modifier.fillMaxWidth())
    if (state.eventsLoaded && !state.eventsBusy && state.events.isEmpty()) Text(stringResource(R.string.sponsorships_no_results), color = Muted)
    state.events.forEach { event ->
        val selected = state.event?.id == event.id
        OutlinedCard(
            modifier = Modifier.fillMaxWidth().selectable(selected = selected, onClick = { viewModel.chooseEvent(event) }, role = Role.RadioButton),
            border = if (selected) CardDefaults.outlinedCardBorder().copy(width = 2.dp, brush = androidx.compose.ui.graphics.SolidColor(MaterialTheme.colorScheme.primary)) else CardDefaults.outlinedCardBorder(),
        ) {
            Box(Modifier.padding(12.dp)) {
                SponsorshipEventLine(event, zone, trailing = { RadioButton(selected = selected, onClick = null) }) {
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SponsorshipBadge(stringResource(R.string.sponsorships_published), "success")
                        if (event.alreadySponsored) SponsorshipBadge(stringResource(R.string.sponsorships_already), "warning")
                    }
                }
            }
        }
    }
    if (state.eventsHasMore) OutlinedButton(enabled = !state.eventsBusy, onClick = viewModel::moreEvents, modifier = Modifier.heightIn(min = 48.dp)) {
        Text(stringResource(R.string.sponsorships_more_events))
    }
}

@Composable
private fun StepWhere(state: SponsorshipWizardUiState, viewModel: SponsorshipWizardViewModel) {
    SponsorshipSection(stringResource(R.string.sponsorships_step_where), stringResource(R.string.sponsorships_step_where_lead))
    state.errors.field("placement")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    state.catalog.valueOrNull?.placements?.forEach { format ->
        ChoiceCard(state.placement == format.value, format.label, format.description, onClick = { viewModel.choosePlacement(format.value) }) {
            Text(listOfNotNull(format.rotation, format.fromLabel).joinToString(" · "), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun StepTime(state: SponsorshipWizardUiState, viewModel: SponsorshipWizardViewModel) {
    SponsorshipSection(stringResource(R.string.sponsorships_step_time), stringResource(R.string.sponsorships_step_time_lead))
    state.errors.field("duration")?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    val format = state.format ?: return
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        format.durations.forEach { duration ->
            val selected = state.duration == duration.key
            OutlinedCard(
                modifier = Modifier.widthIn(min = 120.dp).selectable(selected = selected, onClick = { viewModel.chooseDuration(duration.key) }, role = Role.RadioButton),
                border = if (selected) CardDefaults.outlinedCardBorder().copy(width = 2.dp, brush = androidx.compose.ui.graphics.SolidColor(MaterialTheme.colorScheme.primary)) else CardDefaults.outlinedCardBorder(),
            ) {
                Column(Modifier.padding(horizontal = 16.dp, vertical = 10.dp).heightIn(min = 48.dp)) {
                    Text(duration.monthsLabel, style = MaterialTheme.typography.titleMedium)
                    Text(duration.amountLabel, color = Muted)
                }
            }
        }
    }
    if (format.priced) state.catalog.valueOrNull?.priceVat?.takeIf { it.isNotBlank() }?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
}

@Composable
private fun StepSummary(state: SponsorshipWizardUiState, viewModel: SponsorshipWizardViewModel) {
    SponsorshipSection(stringResource(R.string.sponsorships_step_summary))
    val format = state.format
    val duration = state.chosenDuration
    if (format == null || duration == null) return
    SummaryLine(R.string.sponsorships_summary_what, state.event?.title ?: stringResource(R.string.sponsorships_scope_all)) { viewModel.goTo(WizardStep.What) }
    SummaryLine(R.string.sponsorships_summary_where, format.label) { viewModel.goTo(WizardStep.Where) }
    SummaryLine(R.string.sponsorships_summary_time, stringResource(R.string.sponsorships_summary_from_approval, duration.monthsLabel)) { viewModel.goTo(WizardStep.Time) }
    SummaryLine(R.string.sponsorships_summary_price, duration.amountLabel, null)
    SummaryLine(R.string.sponsorships_summary_payment, stringResource(if (state.viaStripe) R.string.sponsorships_payment_online else R.string.sponsorships_payment_request), null)
    if (!state.viaStripe) {
        FormTextField(
            value = state.note,
            onValueChange = viewModel::editNote,
            label = stringResource(R.string.sponsorships_note_label),
            errors = state.errors,
            field = "note",
            minLines = 3,
            enabled = !state.busy,
            help = stringResource(R.string.sponsorships_note_help, state.note.length, SPONSORSHIP_NOTE_MAX),
        )
    }
    Button(enabled = state.canAdvance && !state.busy, onClick = viewModel::submit, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
        Text(stringResource(if (state.viaStripe) R.string.sponsorships_submit_online else R.string.sponsorships_submit_request))
    }
    Text(stringResource(if (state.viaStripe) R.string.sponsorships_submit_online_hint else R.string.sponsorships_submit_request_hint), color = Muted)
}

@Composable
private fun SummaryLine(label: Int, value: String, onEdit: (() -> Unit)?) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(label), color = Muted)
            Text(value, style = MaterialTheme.typography.titleMedium)
        }
        onEdit?.let { edit -> TextButton(onClick = edit, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.sponsorships_choose)) } }
    }
    HorizontalDivider()
}
