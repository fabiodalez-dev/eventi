package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CatalogChoices
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.data.editorial.CatalogRecord
import it.fabiodalez.incitta.data.management.OptionChoice
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormScaffold
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.resolve
import it.fabiodalez.incitta.ui.management.common.valueOrNull
import it.fabiodalez.incitta.ui.management.profile.AddRowButton
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.profile.CountedTextField
import it.fabiodalez.incitta.ui.management.profile.HourField
import it.fabiodalez.incitta.ui.management.profile.SearchableChoiceField
import it.fabiodalez.incitta.ui.management.profile.VenueLocationPicker
import it.fabiodalez.incitta.ui.management.profile.replaced
import it.fabiodalez.incitta.ui.management.profile.without

/*
 * R11 — il modulo di una voce del catalogo, nuova o salvata, con le sezioni
 * del modulo del pannello per ognuna delle cinque voci. Salvare manda tutti
 * i campi della voce (come il modulo di Filament); il server convalida con
 * le stesse regole e risponde con il modulo salvato. Per le città salvare
 * chiede la password recente: la richiesta compare da sola sul 423
 * (`repository.call`). Eliminare è definitivo e resta sul sito (decisione
 * 4): «Elimina sul sito» apre la voce nel pannello.
 */
@Composable
internal fun CatalogFormScreen(
    viewModel: CatalogFormViewModel,
    padding: PaddingValues,
    admin: Boolean,
    onBack: () -> Unit,
    onOpenWeb: (String) -> Unit,
) {
    val kind = viewModel.kind
    val draft by viewModel.draft.collectAsState()
    val state by viewModel.state.collectAsState()
    val choicesState by viewModel.choices.collectAsState()
    val context = LocalContext.current
    // Gli errori trovati sul telefono vanno sotto i campi come quelli del server.
    val errors = if (state.problems.isEmpty()) state.errors else FormErrors(fields = state.problems.mapValues { context.getString(it.value) })
    val record = state.record.valueOrNull
    val title = when {
        viewModel.isNew -> stringResource(catalogNewLabel(kind))
        else -> record?.label ?: draft.name.ifBlank { draft.title }.ifBlank { stringResource(catalogTitle(kind)) }
    }
    val snackbar = remember { SnackbarHostState() }
    val message = state.message?.resolve()
    LaunchedEffect(message) {
        if (message != null) { snackbar.showSnackbar(message); viewModel.clearMessage() }
    }

    Box(Modifier.fillMaxSize()) {
        ManagementScaffold(title = title, padding = padding, onBack = onBack, busy = state.busy) {
            LoadState(state.record, onRetry = { viewModel.load() }) { _ ->
                LoadState(choicesState, onRetry = viewModel::loadChoices) { choices ->
                    if (state.conflict) ConflictBanner(onReload = { viewModel.load() })
                    if (kind == CatalogKind.Cities) Text(stringResource(R.string.editorial_catalog_city_warning), color = MaterialTheme.colorScheme.error)
                    FormScaffold(
                        errors = errors,
                        busy = state.busy,
                        submitLabel = stringResource(if (viewModel.isNew) R.string.editorial_catalog_create else R.string.editorial_catalog_save),
                        onSubmit = { viewModel.save(admin) },
                    ) {
                        when (kind) {
                            CatalogKind.Categories -> CategoryFields(draft, choices, record, errors, viewModel::edit)
                            CatalogKind.Tags -> TagFields(draft, choices, errors, viewModel::edit)
                            CatalogKind.Features -> FeatureFields(draft, choices, errors, viewModel::edit)
                            CatalogKind.Cities -> CityFields(draft, choices, errors, viewModel::edit)
                            CatalogKind.Pages -> PageFields(draft, errors, viewModel::edit, viewModel::preview)
                        }
                        if (kind.hasContent) {
                            CatalogContentSection(kind, draft, choices, errors, viewModel::edit)
                            CatalogSeoSection(draft, choices, admin, errors, viewModel::edit)
                        }
                    }
                    record?.takeIf { kind == CatalogKind.Pages && it.isPublished && it.slug.isNotBlank() }?.let { page ->
                        OutlinedButton(onClick = { onOpenWeb("pagine/" + page.slug) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                            Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
                            Text(stringResource(R.string.editorial_catalog_open_page), Modifier.padding(start = 8.dp))
                        }
                    }
                    if (record?.allows("delete_on_web") == true && record.webPath.isNotBlank()) {
                        OutlinedButton(onClick = { onOpenWeb(record.webPath) }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                            Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null)
                            Text(stringResource(R.string.editorial_catalog_action_delete_web), Modifier.padding(start = 8.dp))
                        }
                        Text(stringResource(R.string.editorial_catalog_delete_web_help), color = Muted)
                    } else if (record?.isSystem == true) {
                        Text(stringResource(R.string.editorial_catalog_system_no_delete), color = Muted)
                    }
                }
            }
        }
        SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter).padding(padding))
    }

    state.preview?.let { MarkdownPreviewDialog(it, onRetry = viewModel::preview, onDismiss = viewModel::closePreview) }
}

@Composable
private fun ConflictBanner(onReload: () -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.editorial_catalog_conflict), color = MaterialTheme.colorScheme.onErrorContainer)
            Button(onClick = onReload, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_catalog_reload)) }
        }
    }
}

@Composable
private fun SlugField(draft: CatalogDraft, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit, help: Int = R.string.editorial_catalog_field_slug_help) {
    FormTextField(draft.slug, { v -> onEdit { it.copy(slug = v) } }, stringResource(R.string.editorial_catalog_field_slug), errors, "slug", singleLine = true,
        help = stringResource(help), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
}

/** Le sezioni «Generale» e «Regole del tempo» di `CategoryResource`. */
@Composable
private fun CategoryFields(draft: CatalogDraft, choices: CatalogChoices, record: CatalogRecord?, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_general))
    FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_catalog_field_name), errors, "name", singleLine = true)
    SlugField(draft, errors, onEdit)
    // Non può essere padre di sé stessa: nel pannello non è fra le scelte.
    val parents = choices.categories.filter { it.value != record?.id?.toString() }.map { it.toOption() }
    ChoiceField(stringResource(R.string.editorial_catalog_field_parent), parents, draft.parentId, { v -> onEdit { it.copy(parentId = v) } },
        error = errors.field("parent_id"), emptyLabel = stringResource(R.string.editorial_catalog_none))
    FormTextField(draft.icon, { v -> onEdit { it.copy(icon = v) } }, stringResource(R.string.editorial_catalog_field_icon), errors, "icon", singleLine = true)
    FormTextField(draft.color, { v -> onEdit { it.copy(color = v) } }, stringResource(R.string.editorial_catalog_field_color), errors, "color", singleLine = true,
        help = stringResource(R.string.editorial_catalog_field_color_help))
    CatalogNumberField(draft.sortOrder, { v -> onEdit { it.copy(sortOrder = v) } }, stringResource(R.string.editorial_catalog_field_sort_order), errors, "sort_order")
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_active), draft.isActive, { v -> onEdit { it.copy(isActive = v) } })

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_temporal), stringResource(R.string.editorial_catalog_section_temporal_help))
    CatalogNumberField(draft.defaultDuration, { v -> onEdit { it.copy(defaultDuration = v) } }, stringResource(R.string.editorial_catalog_field_duration), errors, "default_duration_minutes",
        help = stringResource(R.string.editorial_catalog_field_duration_help))
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_ongoing), draft.supportsOngoing, { v -> onEdit { it.copy(supportsOngoing = v) } }, help = stringResource(R.string.editorial_catalog_field_ongoing_help))
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_nightlife), draft.isNightlife, { v -> onEdit { it.copy(isNightlife = v) } }, help = stringResource(R.string.editorial_catalog_field_nightlife_help))
}

/** La sezione «Generale» di `TagResource`, con i sinonimi come etichette. */
@Composable
private fun TagFields(draft: CatalogDraft, choices: CatalogChoices, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_general))
    FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_catalog_field_name), errors, "name", singleLine = true)
    SlugField(draft, errors, onEdit)
    ChoiceField(stringResource(R.string.editorial_catalog_field_category), choices.categories.map { it.toOption() }, draft.categoryId, { v -> onEdit { it.copy(categoryId = v) } },
        error = errors.field("category_id"), emptyLabel = stringResource(R.string.editorial_catalog_none))
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_approved), draft.isApproved, { v -> onEdit { it.copy(isApproved = v) } })
    SynonymsField(draft.synonyms, errors.field("synonyms")) { v -> onEdit { it.copy(synonyms = v) } }
}

/** I sinonimi come il campo a etichette del pannello: si scrive, «Aggiungi», ogni etichetta si toglie con la sua ×. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SynonymsField(values: List<String>, error: String?, onChange: (List<String>) -> Unit) {
    var text by rememberSaveable { mutableStateOf("") }
    fun add() {
        val clean = text.trim()
        if (clean.isNotEmpty() && clean !in values) onChange(values + clean)
        text = ""
    }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.editorial_catalog_field_synonyms), style = MaterialTheme.typography.titleSmall)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            values.forEach { synonym ->
                InputChip(
                    selected = false,
                    onClick = { onChange(values - synonym) },
                    label = { Text(synonym) },
                    trailingIcon = { Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.editorial_catalog_synonym_remove, synonym)) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(text, { text = it }, label = { Text(stringResource(R.string.editorial_catalog_synonym_new)) }, singleLine = true,
                isError = error != null, supportingText = error?.let { { Text(it) } }, modifier = Modifier.weight(1f))
            OutlinedButton(onClick = ::add, enabled = text.isNotBlank(), modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_catalog_synonym_add)) }
        }
    }
}

/** «Caratteristica riutilizzabile» di `EventFeatureResource`. */
@Composable
private fun FeatureFields(draft: CatalogDraft, choices: CatalogChoices, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_feature), stringResource(R.string.editorial_catalog_section_feature_help))
    FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_catalog_field_visible_name), errors, "name", singleLine = true)
    ChoiceField(stringResource(R.string.editorial_catalog_field_group), choices.featureGroups.map { it.toOption() }, draft.group, { v -> onEdit { it.copy(group = v) } }, error = errors.field("group"))
    ChoiceField(stringResource(R.string.editorial_catalog_field_icon), choices.featureIcons.map { it.toOption() }, draft.featureIcon, { v -> onEdit { it.copy(featureIcon = v) } }, error = errors.field("icon"))
    CountedTextField(draft.description, { v -> onEdit { it.copy(description = v) } }, stringResource(R.string.editorial_catalog_field_feature_description), 500, errors, "description",
        help = stringResource(R.string.editorial_catalog_field_feature_description_help), minLines = 2)
    CatalogNumberField(draft.sortOrder, { v -> onEdit { it.copy(sortOrder = v) } }, stringResource(R.string.editorial_catalog_field_order), errors, "sort_order")
    CatalogSwitch(
        stringResource(R.string.editorial_catalog_field_available), draft.isActive, { v -> onEdit { it.copy(isActive = v) } },
        help = if (draft.isSystem) stringResource(R.string.editorial_catalog_field_available_system) else null,
        enabled = !draft.isSystem,
    )
}

/** Le sezioni «Identità», «Territorio», «Tempo» e «Funzioni» di `CityResource`. */
@Composable
private fun CityFields(draft: CatalogDraft, choices: CatalogChoices, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_identity))
    FormTextField(draft.name, { v -> onEdit { it.copy(name = v) } }, stringResource(R.string.editorial_catalog_field_name), errors, "name", singleLine = true)
    SlugField(draft, errors, onEdit, help = R.string.editorial_catalog_field_city_slug_help)
    FormTextField(draft.provinceCode, { v -> onEdit { it.copy(provinceCode = v) } }, stringResource(R.string.editorial_catalog_field_province_code), errors, "province_code", singleLine = true)
    FormTextField(draft.provinceName, { v -> onEdit { it.copy(provinceName = v) } }, stringResource(R.string.editorial_catalog_field_province_name), errors, "province_name", singleLine = true)
    FormTextField(draft.region, { v -> onEdit { it.copy(region = v) } }, stringResource(R.string.editorial_catalog_field_region), errors, "region", singleLine = true)
    FormTextField(draft.countryCode, { v -> onEdit { it.copy(countryCode = v) } }, stringResource(R.string.editorial_catalog_field_country), errors, "country_code", singleLine = true)

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_geography), stringResource(R.string.editorial_catalog_section_geography_help))
    val lat = draft.centerLat.replace(',', '.').toDoubleOrNull()
    val lng = draft.centerLng.replace(',', '.').toDoubleOrNull()
    VenueLocationPicker(
        lat = lat,
        lng = lng,
        address = "",
        onPick = { pickedLat, pickedLng -> onEdit { it.copy(centerLat = pickedLat.toString(), centerLng = pickedLng.toString()) } },
        title = stringResource(R.string.editorial_catalog_map_title),
        help = stringResource(R.string.editorial_catalog_map_help),
        missing = stringResource(R.string.editorial_catalog_map_missing),
        pickedZoom = draft.defaultZoom.toDoubleOrNull() ?: 12.0,
    )
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CatalogNumberField(draft.centerLat, { v -> onEdit { it.copy(centerLat = v) } }, stringResource(R.string.editorial_catalog_field_lat), errors, "center_lat", decimal = true, modifier = Modifier.weight(1f))
        CatalogNumberField(draft.centerLng, { v -> onEdit { it.copy(centerLng = v) } }, stringResource(R.string.editorial_catalog_field_lng), errors, "center_lng", decimal = true, modifier = Modifier.weight(1f))
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CatalogNumberField(draft.defaultZoom, { v -> onEdit { it.copy(defaultZoom = v) } }, stringResource(R.string.editorial_catalog_field_zoom), errors, "default_zoom", modifier = Modifier.weight(1f))
        CatalogNumberField(draft.radiusKm, { v -> onEdit { it.copy(radiusKm = v) } }, stringResource(R.string.editorial_catalog_field_radius), errors, "radius_km", modifier = Modifier.weight(1f))
    }
    Text(stringResource(R.string.editorial_catalog_field_bounds), style = MaterialTheme.typography.titleSmall)
    Text(stringResource(R.string.editorial_catalog_field_bounds_help), color = Muted, style = MaterialTheme.typography.bodySmall)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CatalogNumberField(draft.boundsNorth, { v -> onEdit { it.copy(boundsNorth = v) } }, stringResource(R.string.editorial_catalog_north), errors, "bounds.north", decimal = true, modifier = Modifier.weight(1f))
        CatalogNumberField(draft.boundsSouth, { v -> onEdit { it.copy(boundsSouth = v) } }, stringResource(R.string.editorial_catalog_south), errors, "bounds.south", decimal = true, modifier = Modifier.weight(1f))
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        CatalogNumberField(draft.boundsEast, { v -> onEdit { it.copy(boundsEast = v) } }, stringResource(R.string.editorial_catalog_east), errors, "bounds.east", decimal = true, modifier = Modifier.weight(1f))
        CatalogNumberField(draft.boundsWest, { v -> onEdit { it.copy(boundsWest = v) } }, stringResource(R.string.editorial_catalog_west), errors, "bounds.west", decimal = true, modifier = Modifier.weight(1f))
    }

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_temporal), stringResource(R.string.editorial_catalog_section_city_temporal_help))
    SearchableChoiceField(stringResource(R.string.editorial_catalog_field_timezone), choices.timezones, draft.timezone, { v -> onEdit { it.copy(timezone = v) } }, error = errors.field("timezone"))
    HourField(stringResource(R.string.editorial_catalog_field_cutoff), draft.nightCutoff, { v -> onEdit { it.copy(nightCutoff = v) } })
    Text(errors.field("night_cutoff_time") ?: stringResource(R.string.editorial_catalog_field_cutoff_help),
        color = if (errors.field("night_cutoff_time") != null) MaterialTheme.colorScheme.error else Muted, style = MaterialTheme.typography.bodySmall)
    CatalogNumberField(draft.startingSoon, { v -> onEdit { it.copy(startingSoon = v) } }, stringResource(R.string.editorial_catalog_field_soon), errors, "starting_soon_minutes",
        help = stringResource(R.string.editorial_catalog_field_soon_help))
    ChoiceField(stringResource(R.string.editorial_catalog_field_locale), choices.locales.map { OptionChoice(it, it) }, draft.locale, { v -> onEdit { it.copy(locale = v) } },
        error = errors.field("locale"))

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_features), stringResource(R.string.editorial_catalog_section_features_help))
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_active), draft.isActive, { v -> onEdit { it.copy(isActive = v) } })
    draft.settings.forEachIndexed { index, row ->
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FormTextField(row.key, { v -> onEdit { it.copy(settings = it.settings.replaced(index, row.copy(key = v))) } }, stringResource(R.string.editorial_catalog_field_setting_key), errors,
                "settings.$index.key", singleLine = true, modifier = Modifier.weight(1f))
            Switch(checked = row.enabled, onCheckedChange = { v -> onEdit { it.copy(settings = it.settings.replaced(index, row.copy(enabled = v))) } })
            IconButton(onClick = { onEdit { it.copy(settings = it.settings.without(index)) } }, modifier = Modifier.size(48.dp)) {
                Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.editorial_catalog_setting_remove, row.key.ifBlank { (index + 1).toString() }))
            }
        }
    }
    AddRowButton(stringResource(R.string.editorial_catalog_setting_add), draft.settings.size, Int.MAX_VALUE) { onEdit { it.copy(settings = it.settings + FlagDraftRow()) } }
}

/** Le sezioni «Contenuto», «Pubblicazione» e «SEO» di `PageResource`. */
@Composable
private fun PageFields(draft: CatalogDraft, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit, onPreview: () -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_content))
    FormTextField(draft.title, { v -> onEdit { it.copy(title = v) } }, stringResource(R.string.editorial_catalog_field_title), errors, "title", singleLine = true)
    SlugField(draft, errors, onEdit, help = R.string.editorial_catalog_field_page_slug_help)
    CountedTextField(draft.excerpt, { v -> onEdit { it.copy(excerpt = v) } }, stringResource(R.string.editorial_catalog_field_excerpt), 500, errors, "excerpt",
        help = stringResource(R.string.editorial_catalog_field_excerpt_help), minLines = 2)
    MarkdownField(draft.body, { v -> onEdit { it.copy(body = v) } }, stringResource(R.string.editorial_catalog_field_body), errors, "body",
        help = stringResource(R.string.editorial_catalog_field_body_help), onPreview = onPreview)

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_publication))
    CatalogSwitch(stringResource(R.string.editorial_catalog_field_published), draft.isPublished, { v -> onEdit { it.copy(isPublished = v) } },
        help = stringResource(R.string.editorial_catalog_field_published_help))
    CatalogNumberField(draft.sortOrder, { v -> onEdit { it.copy(sortOrder = v) } }, stringResource(R.string.editorial_catalog_field_sort_order), errors, "sort_order",
        help = stringResource(R.string.editorial_catalog_field_page_order_help))

    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_page_seo))
    FormTextField(draft.seoTitleColumn, { v -> onEdit { it.copy(seoTitleColumn = v) } }, stringResource(R.string.editorial_catalog_field_page_seo_title), errors, "seo_title", singleLine = true,
        help = stringResource(R.string.editorial_catalog_automatic_if_empty))
    CountedTextField(draft.seoDescriptionColumn, { v -> onEdit { it.copy(seoDescriptionColumn = v) } }, stringResource(R.string.editorial_catalog_field_page_seo_description), 500, errors, "seo_description",
        help = stringResource(R.string.editorial_catalog_automatic_if_empty), minLines = 2)
}
