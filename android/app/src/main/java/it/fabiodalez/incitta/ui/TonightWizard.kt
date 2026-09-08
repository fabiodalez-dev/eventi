package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.selection.toggleable
import androidx.compose.ui.semantics.Role
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import java.net.URLEncoder

@Serializable
internal data class TonightOption(val id: Long, val name: String)
@Serializable
internal data class TonightResult(val occurrence: Occurrence, val reasons: List<String>, val practical: List<Fact> = emptyList(), val content_details: JsonElement? = null)
@Serializable
internal data class TonightPayload(val categories: List<TonightOption> = emptyList(), val zones: List<String> = emptyList(), val municipalities: List<String> = emptyList(), val neighborhood_municipality: String? = null, val results: List<TonightResult> = emptyList())

@Composable
fun TonightWizard(session: Session?, savedIds: Set<Long>, onBack: () -> Unit, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit, onAll: () -> Unit) {
    val context = LocalContext.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    var step by rememberSaveable { mutableIntStateOf(1) }
    var whenValue by rememberSaveable { mutableStateOf("tonight") }
    var zone by rememberSaveable { mutableStateOf("") }
    var municipality by rememberSaveable { mutableStateOf("Padova") }
    var budget by rememberSaveable { mutableStateOf("") }
    var selected by rememberSaveable(session?.user?.id) { mutableStateOf(arrayListOf<Long>()) }
    var payload by remember(session?.user?.id) { mutableStateOf(TonightPayload()) }
    val listState = rememberLazyListState()
    LaunchedEffect(step) { listState.scrollToItem(0) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var retry by remember { mutableIntStateOf(0) }
    val hasNeighborhoods = municipality == payload.neighborhood_municipality
    fun previous() { step = if (step == 3 && !hasNeighborhoods) 1 else step - 1 }
    BackHandler(step > 1) { previous() }
    LaunchedEffect(step, retry, session?.token) {
        loading = true
        error = null
        try {
            val path = "tonight?step=${if (step == 6) 3 else 1}&municipality=${URLEncoder.encode(municipality, "UTF-8")}&when=$whenValue&zone=${URLEncoder.encode(zone, "UTF-8")}&budget=$budget" + selected.joinToString("") { "&categories%5B%5D=$it" }
            payload = api.get<ApiEnvelope<TonightPayload>>(path, session?.token).data
        } catch (e: Exception) {
            if (e is CancellationException) throw e
            error = requestFailureMessage(e)
        } finally { loading = false }
    }
    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 22.dp), state = listState, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        item {
            TextButton(onClick = { if (step > 1) previous() else onBack() }) { Text(stringResource(R.string.tonight_back)) }
            if (step < 6) Text(stringResource(R.string.tonight_steps, if (!hasNeighborhoods && step > 2) step - 1 else step, if (hasNeighborhoods) 5 else 4), color = Acid)
            Text(stringResource(R.string.tonight_title), style = MaterialTheme.typography.headlineLarge)
            Text(stringResource(R.string.tonight_lead), modifier = Modifier.padding(top = 12.dp), color = Muted)
        }
        if (loading) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        error?.let { message -> item { Text(message); TextButton(onClick = { retry++ }) { Text(stringResource(R.string.tonight_retry)) } } }
        if (!loading && error == null) {
            if (step == 1) {
                item { ChoiceMenu(stringResource(R.string.tonight_municipality), municipality,
                    payload.municipalities.take(1).map { it to it } + listOf("" to stringResource(R.string.tonight_everywhere)) + payload.municipalities.drop(1).map { it to it }, searchable = true) { municipality = it; zone = "" } }
            } else if (step == 2) {
                item { ChoiceMenu(stringResource(R.string.tonight_zone), zone, listOf("" to stringResource(R.string.tonight_any_zone)) + payload.zones.map { it to it }, searchable = true) { zone = it } }
            } else if (step == 3) {
                item {
                    ChoiceMenu(stringResource(R.string.tonight_when), whenValue,
                        listOf("tonight" to stringResource(R.string.tonight_evening), "starting_soon" to stringResource(R.string.tonight_soon))) { whenValue = it }
                }
            } else if (step == 4) {
                item {
                    ChoiceMenu(stringResource(R.string.tonight_budget), budget,
                        listOf("" to stringResource(R.string.tonight_any_budget), "0" to stringResource(R.string.tonight_free)) + listOf("10", "20", "30", "50").map { it to stringResource(R.string.tonight_up_to, it) }) { budget = it }
                    Text(stringResource(R.string.tonight_budget_help), color = Muted, modifier = Modifier.padding(top = 12.dp))
                }
            } else if (step == 5) {
                item { Text(stringResource(R.string.tonight_categories), style = MaterialTheme.typography.headlineMedium); Text(stringResource(R.string.tonight_categories_help), color = Muted) }
                items(payload.categories, key = { it.id }) { category ->
                    Row(Modifier.fillMaxWidth().heightIn(min = 56.dp).toggleable(value = category.id in selected, role = Role.Checkbox, onValueChange = { checked -> selected = ArrayList(if (checked) (selected + category.id).distinct() else selected - category.id) }), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Checkbox(category.id in selected, onCheckedChange = null)
                        Text(category.name, modifier = Modifier.weight(1f).padding(top = 12.dp))
                    }
                }
            } else {
                item { Text(stringResource(R.string.tonight_order), color = Muted) }
                if (payload.results.isEmpty()) item { Text(stringResource(R.string.tonight_empty)) }
                items(payload.results, key = { it.occurrence.occurrenceId }) { result ->
                    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        result.reasons.forEach { Text(it, color = Acid) }
                        EventRow(result.occurrence, result.occurrence.occurrenceId in savedIds, onOpen, onSave)
                        var expanded by remember { mutableStateOf(false) }
                        TextButton(onClick = { expanded = !expanded }) { Text(stringResource(R.string.editorial_before_going)) }
                        if (expanded) result.practical.forEach { fact ->
                            Text(fact.label, style = MaterialTheme.typography.titleMedium)
                            Text(fact.value)
                        }
                        HorizontalDivider()
                    }
                }
            }
            item {
                Button(onClick = { step = if (step == 6) 1 else if (step == 1 && !hasNeighborhoods) 3 else step + 1 }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) {
                    Text(stringResource(if (step == 6) R.string.tonight_edit else if (step == 5) R.string.tonight_find else R.string.tonight_next))
                }
                if (step == 6) TextButton(onClick = onAll, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) { Text(stringResource(R.string.tonight_all)) }
                Spacer(Modifier.height(20.dp))
            }
        }
    }
}

@Composable
private fun ChoiceMenu(label: String, value: String, options: List<Pair<String, String>>, searchable: Boolean = false, onChange: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    var search by remember { mutableStateOf("") }
    Column {
        Text(label, style = MaterialTheme.typography.titleMedium)
        if (searchable) {
            OutlinedTextField(value = search, onValueChange = { search = it }, label = { Text(stringResource(R.string.tonight_search_place)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            Column(Modifier.heightIn(max = 280.dp).verticalScroll(androidx.compose.foundation.rememberScrollState())) {
                options.filter { search.isBlank() || it.second.contains(search, ignoreCase = true) }.forEach { (key, title) ->
                    TextButton(onClick = { onChange(key) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                        RadioButton(selected = key == value, onClick = null)
                        Text(title, modifier = Modifier.weight(1f).padding(start = 12.dp))
                    }
                }
            }
        } else {
        Box {
            OutlinedButton(onClick = { expanded = true }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp)) { Text(options.firstOrNull { it.first == value }?.second ?: value) }
            DropdownMenu(expanded, onDismissRequest = { expanded = false }) {
                options.forEach { (key, title) -> DropdownMenuItem(text = { Text(title) }, onClick = { onChange(key); expanded = false }) }
            }
        }
        }
    }
}
