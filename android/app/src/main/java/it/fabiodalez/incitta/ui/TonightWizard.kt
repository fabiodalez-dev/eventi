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

@Serializable
internal data class TonightOption(val id: Long, val name: String, val slug: String = "")
@Serializable
internal data class TonightResult(val occurrence: Occurrence, val reasons: List<String>, val practical: List<Fact> = emptyList(), val content_details: JsonElement? = null)
@Serializable
internal data class TonightPayload(val categories: List<TonightOption> = emptyList(), val zones: List<String> = emptyList(), val municipalities: List<String> = emptyList(), val neighborhood_municipality: String? = null, val results: List<TonightResult> = emptyList(), val soon_minutes: Int = 180)

@Composable
fun TonightWizard(session: Session?, onBack: () -> Unit, onResults: (Map<String, String>, String) -> Unit) {
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
    val timeSummary = stringResource(if (whenValue == "tonight") R.string.tonight_evening else R.string.tonight_soon)
    val placeSummary = municipality.ifBlank { stringResource(R.string.tonight_everywhere) }
    val budgetSummary = if (budget == "0") stringResource(R.string.tonight_free) else if (budget.isNotBlank()) stringResource(R.string.tonight_up_to, budget) else ""
    fun previous() { step = if (step == 3 && !hasNeighborhoods) 1 else step - 1 }
    BackHandler(step > 1) { previous() }
    LaunchedEffect(retry, session?.token) {
        loading = true
        error = null
        try {
            val path = "tonight?step=1"
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
                        listOf("tonight" to stringResource(R.string.tonight_evening), "starting_soon" to stringResource(R.string.tonight_soon)),
                        descriptions = mapOf("tonight" to stringResource(R.string.tonight_evening_help), "starting_soon" to stringResource(R.string.tonight_soon_help, payload.soon_minutes))) { whenValue = it }
                }
            } else if (step == 4) {
                item {
                    ChoiceMenu(stringResource(R.string.tonight_budget), budget,
                        listOf("" to stringResource(R.string.tonight_any_budget), "0" to stringResource(R.string.tonight_free)) + listOf("10", "20", "30", "50").map { it to stringResource(R.string.tonight_up_to, it) }) { budget = it }
                    Text(stringResource(R.string.tonight_budget_help), color = Muted, modifier = Modifier.padding(top = 12.dp))
                }
            } else if (step == 5) {
                item { Text(stringResource(R.string.tonight_categories), style = MaterialTheme.typography.headlineMedium); Text(stringResource(R.string.tonight_categories_help), color = Muted) }
                items(payload.categories.chunked(2)) { row ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        row.forEach { category ->
                            val checked = category.id in selected
                            Surface(modifier = Modifier.weight(1f), color = Ink, contentColor = Paper, shape = androidx.compose.ui.graphics.RectangleShape,
                                border = androidx.compose.foundation.BorderStroke(2.dp, if (checked) Acid else Rule)) {
                                Column(Modifier.heightIn(min = 96.dp).toggleable(value = checked, role = Role.Checkbox, onValueChange = { enabled -> selected = ArrayList(if (enabled) (selected + category.id).distinct() else selected - category.id) }).padding(12.dp)) {
                                    Checkbox(checked, onCheckedChange = null)
                                    Text(category.name, style = MaterialTheme.typography.bodyMedium)
                                }
                            }
                        }
                        if (row.size == 1) Spacer(Modifier.weight(1f))
                    }
                }
            }
            item {
                Button(onClick = {
                    if (step == 5) {
                        val categories = payload.categories.filter { it.id in selected }
                        val filters = mapOf("preset" to whenValue, "municipality" to municipality, "zone" to (if (hasNeighborhoods) zone else ""), "budget" to budget, "categories" to categories.joinToString(",") { it.slug }, "discovery" to "1").filterValues { it.isNotBlank() }
                        val summary = listOf(timeSummary, placeSummary, if (hasNeighborhoods) zone else "", budgetSummary, categories.joinToString(", ") { it.name }).filter { it.isNotBlank() }.joinToString(" · ")
                        onResults(filters, summary)
                    } else step = if (step == 1 && !hasNeighborhoods) 3 else step + 1
                }, modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp), shape = androidx.compose.ui.graphics.RectangleShape, colors = ButtonDefaults.buttonColors(containerColor = Acid, contentColor = Ink)) {
                    Text(stringResource(if (step == 5) R.string.tonight_find else R.string.tonight_next))
                }
                Spacer(Modifier.height(20.dp))
            }
        }
    }
}

@Composable
private fun ChoiceMenu(label: String, value: String, options: List<Pair<String, String>>, searchable: Boolean = false, descriptions: Map<String, String> = emptyMap(), onChange: (String) -> Unit) {
    var search by remember { mutableStateOf("") }
    Column {
        Text(label, style = MaterialTheme.typography.titleMedium)
        if (searchable) {
            OutlinedTextField(value = search, onValueChange = { search = it }, label = { Text(stringResource(R.string.tonight_search_place)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
        }
            Column(Modifier.fillMaxWidth().heightIn(max = if (searchable) 360.dp else 620.dp).verticalScroll(androidx.compose.foundation.rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                options.filter { search.isBlank() || it.second.contains(search, ignoreCase = true) }.forEach { (key, title) ->
                    OutlinedButton(onClick = { onChange(key) }, modifier = Modifier.fillMaxWidth().heightIn(min = 64.dp), shape = androidx.compose.ui.graphics.RectangleShape, border = androidx.compose.foundation.BorderStroke(2.dp, if (key == value) Acid else Rule), colors = ButtonDefaults.outlinedButtonColors(contentColor = Paper)) {
                        RadioButton(selected = key == value, onClick = null)
                        Column(Modifier.weight(1f).padding(start = 12.dp)) {
                            Text(title, style = MaterialTheme.typography.bodyLarge)
                            descriptions[key]?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = Muted) }
                        }
                    }
                }
            }
    }
}
