package it.fabiodalez.incitta.ui.editorial.catalog

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.FormatListBulleted
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.fromHtml
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.CatalogChoices
import it.fabiodalez.incitta.data.editorial.CatalogKind
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.common.FormErrors
import it.fabiodalez.incitta.ui.management.common.FormTextField
import it.fabiodalez.incitta.ui.management.common.LinkDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.Loadable
import it.fabiodalez.incitta.ui.management.common.RichTextEdits
import it.fabiodalez.incitta.ui.management.common.RichTextField
import it.fabiodalez.incitta.ui.management.profile.AddRowButton
import it.fabiodalez.incitta.ui.management.profile.ChoiceField
import it.fabiodalez.incitta.ui.management.profile.CountedTextField
import it.fabiodalez.incitta.ui.management.profile.RowHeader
import it.fabiodalez.incitta.ui.management.profile.moved
import it.fabiodalez.incitta.ui.management.profile.replaced
import it.fabiodalez.incitta.ui.management.profile.without

/*
 * I pezzi dei moduli del catalogo che si ripetono: il titolo di sezione,
 * l'interruttore con la spiegazione, le due sezioni comuni a categorie, tag,
 * città e pagine («Informazioni utili e domande frequenti», «Anteprima su
 * Google») e il campo Markdown delle pagine con l'anteprima del server
 * (decisione 7). Gli altri campi sono quelli della gestione
 * (`ui/management/common`, `ui/management/profile`), riusati.
 */

@Composable
internal fun CatalogSectionTitle(title: String, help: String? = null) {
    Column(Modifier.padding(top = 8.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        help?.let { Text(it, color = Muted) }
    }
}

/** Un interruttore del modulo con la sua spiegazione (gli `helperText` del pannello). */
@Composable
internal fun CatalogSwitch(label: String, checked: Boolean, onChange: (Boolean) -> Unit, help: String? = null, enabled: Boolean = true) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
        Column(Modifier.weight(1f).padding(end = 12.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(label, style = MaterialTheme.typography.titleSmall)
            help?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
        }
        Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
    }
}

/** Un campo numerico: la tastiera dei numeri, l'errore del server sotto. */
@Composable
internal fun CatalogNumberField(value: String, onChange: (String) -> Unit, label: String, errors: FormErrors, field: String, help: String? = null, decimal: Boolean = false, modifier: Modifier = Modifier.fillMaxWidth()) {
    FormTextField(
        value, onChange, label, errors, field,
        modifier = modifier,
        singleLine = true,
        help = help,
        keyboardOptions = KeyboardOptions(keyboardType = if (decimal) KeyboardType.Decimal else KeyboardType.Number),
    )
}

/**
 * «Informazioni utili e domande frequenti» (`EditorialFields::content`):
 * presentazione, per categorie e tag le presentazioni per città, le domande
 * frequenti (al massimo 30), tutte con il testo formattato comune.
 */
@Composable
internal fun CatalogContentSection(kind: CatalogKind, draft: CatalogDraft, choices: CatalogChoices, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_information), stringResource(R.string.editorial_catalog_section_information_help))
    RichTextField(draft.introduction, { v -> onEdit { it.copy(introduction = v) } }, stringResource(R.string.editorial_catalog_field_introduction), errors, "content_details.introduction")

    if (kind.taxonomy) {
        Text(stringResource(R.string.editorial_catalog_city_intros), style = MaterialTheme.typography.titleMedium)
        errors.fields["content_details.city_introductions"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        draft.cityIntros.forEachIndexed { index, row ->
            val title = stringResource(R.string.editorial_catalog_city_intro_row, index + 1)
            RowHeader(title, index, draft.cityIntros.size, { d -> onEdit { it.copy(cityIntros = it.cityIntros.moved(index, d)) } }, { onEdit { it.copy(cityIntros = it.cityIntros.without(index)) } })
            ChoiceField(
                stringResource(R.string.editorial_catalog_field_city),
                choices.cities.map { it.toOption() },
                row.cityId,
                { v -> onEdit { it.copy(cityIntros = it.cityIntros.replaced(index, row.copy(cityId = v))) } },
                error = errors.field("content_details.city_introductions.$index.city_id"),
            )
            RichTextField(row.text, { v -> onEdit { it.copy(cityIntros = it.cityIntros.replaced(index, row.copy(text = v))) } }, stringResource(R.string.editorial_catalog_field_introduction), errors,
                "content_details.city_introductions.$index.text", minLines = 3)
        }
        AddRowButton(stringResource(R.string.editorial_catalog_city_intro_add), draft.cityIntros.size, choices.cities.size.coerceAtLeast(1)) {
            onEdit { it.copy(cityIntros = it.cityIntros + CityIntroDraftRow()) }
        }
    }

    Text(stringResource(R.string.editorial_catalog_faqs), style = MaterialTheme.typography.titleMedium)
    errors.fields["content_details.faqs"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    draft.faqs.forEachIndexed { index, row ->
        val title = stringResource(R.string.editorial_catalog_faq_row, index + 1)
        RowHeader(title, index, draft.faqs.size, { d -> onEdit { it.copy(faqs = it.faqs.moved(index, d)) } }, { onEdit { it.copy(faqs = it.faqs.without(index)) } })
        FormTextField(row.question, { v -> onEdit { it.copy(faqs = it.faqs.replaced(index, row.copy(question = v))) } }, stringResource(R.string.editorial_catalog_field_question), errors, "content_details.faqs.$index.question")
        RichTextField(row.answer, { v -> onEdit { it.copy(faqs = it.faqs.replaced(index, row.copy(answer = v))) } }, stringResource(R.string.editorial_catalog_field_answer), errors,
            "content_details.faqs.$index.answer", minLines = 3)
    }
    AddRowButton(stringResource(R.string.editorial_catalog_faq_add), draft.faqs.size, choices.limits.faqs) { onEdit { it.copy(faqs = it.faqs + FaqDraftRow()) } }
}

/**
 * «Anteprima su Google» (`EditorialFields::seo`): titolo, descrizione,
 * immagine; visibilità sui motori e contenuto dimostrativo solo per admin e
 * super admin, come nel pannello.
 */
@Composable
internal fun CatalogSeoSection(draft: CatalogDraft, choices: CatalogChoices, admin: Boolean, errors: FormErrors, onEdit: ((CatalogDraft) -> CatalogDraft) -> Unit) {
    CatalogSectionTitle(stringResource(R.string.editorial_catalog_section_seo), stringResource(R.string.editorial_catalog_section_seo_help))
    CountedTextField(draft.seoTitle, { v -> onEdit { it.copy(seoTitle = v) } }, stringResource(R.string.editorial_catalog_field_seo_title), choices.limits.seoTitle, errors, "seo.title", singleLine = true)
    CountedTextField(draft.seoDescription, { v -> onEdit { it.copy(seoDescription = v) } }, stringResource(R.string.editorial_catalog_field_seo_description), choices.limits.seoDescription, errors, "seo.description", minLines = 3)
    FormTextField(draft.seoImage, { v -> onEdit { it.copy(seoImage = v) } }, stringResource(R.string.editorial_catalog_field_seo_image), errors, "seo.image", singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
    // Com'è il risultato su Google: il titolo e la descrizione scritti, o quelli automatici.
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(draft.seoTitle.ifBlank { draft.title.ifBlank { draft.name }.ifBlank { stringResource(R.string.editorial_catalog_automatic) } }, style = MaterialTheme.typography.titleSmall)
        Text(draft.seoDescription.ifBlank { stringResource(R.string.editorial_catalog_automatic) }, color = Muted)
    }
    if (admin) {
        ChoiceField(stringResource(R.string.editorial_catalog_field_indexing), choices.seoIndexing.map { it.toOption() }, draft.seoIndexing, { v -> onEdit { it.copy(seoIndexing = v) } },
            error = errors.field("seo.indexing"))
        CatalogSwitch(stringResource(R.string.editorial_catalog_field_demo), draft.isDemo, { v -> onEdit { it.copy(isDemo = v) } }, help = stringResource(R.string.editorial_catalog_field_demo_help))
    }
}

/**
 * Il corpo Markdown di una pagina (decisione 7): un campo di testo con una
 * barra minima che scrive i segni del Markdown (gli stessi dell'editor
 * comune, tranne il sottolineato, che il Markdown non ha) e «Anteprima», che
 * chiede al server la stessa conversione della pagina pubblicata.
 */
@Composable
internal fun MarkdownField(value: String, onValueChange: (String) -> Unit, label: String, errors: FormErrors, field: String, help: String? = null, onPreview: () -> Unit) {
    var text by remember { mutableStateOf(TextFieldValue(value)) }
    if (text.text != value) text = TextFieldValue(value, TextRange(value.length))
    var linking by remember { mutableStateOf(false) }
    fun update(next: TextFieldValue) {
        text = next
        if (next.text != value) onValueChange(next.text)
    }
    val error = errors.field(field)
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(Modifier.fillMaxWidth()) {
            MarkButton(Icons.Outlined.FormatBold, R.string.management_rich_bold) { update(RichTextEdits.wrap(text, "**")) }
            MarkButton(Icons.Outlined.FormatItalic, R.string.management_rich_italic) { update(RichTextEdits.wrap(text, "*")) }
            MarkButton(Icons.Outlined.Link, R.string.management_rich_link) { linking = true }
            MarkButton(Icons.Outlined.Title, R.string.management_rich_h2) { update(RichTextEdits.prefixLines(text, "## ")) }
            MarkButton(Icons.Outlined.TextFields, R.string.management_rich_h3) { update(RichTextEdits.prefixLines(text, "### ")) }
        }
        Row(Modifier.fillMaxWidth()) {
            MarkButton(Icons.Outlined.FormatQuote, R.string.management_rich_quote) { update(RichTextEdits.prefixLines(text, "> ")) }
            MarkButton(Icons.AutoMirrored.Outlined.FormatListBulleted, R.string.management_rich_bullets) { update(RichTextEdits.prefixLines(text, "- ")) }
            MarkButton(Icons.Outlined.FormatListNumbered, R.string.management_rich_numbers) { update(RichTextEdits.numberLines(text)) }
        }
        OutlinedTextField(
            value = text,
            onValueChange = ::update,
            label = { Text(label) },
            minLines = 10,
            isError = error != null,
            supportingText = (error ?: help)?.let { message -> { Text(message) } },
            modifier = Modifier.fillMaxWidth(),
        )
        OutlinedButton(onClick = onPreview, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Icon(Icons.Outlined.Visibility, contentDescription = null)
            Text(stringResource(R.string.editorial_catalog_preview), Modifier.padding(start = 8.dp))
        }
    }
    if (linking) LinkDialog(
        initialText = text.text.substring(text.selection.min, text.selection.max),
        onDismiss = { linking = false },
        onAdd = { label, url -> linking = false; update(RichTextEdits.link(text, url, label)) },
    )
}

@Composable
private fun MarkButton(icon: ImageVector, label: Int, onClick: () -> Unit) {
    IconButton(onClick = onClick, modifier = Modifier.size(48.dp)) { Icon(icon, contentDescription = stringResource(label)) }
}

/** L'anteprima della pagina: l'HTML che il server ha prodotto dal Markdown, reso come testo formattato. */
@Composable
internal fun MarkdownPreviewDialog(preview: Loadable<String>, onRetry: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.editorial_catalog_preview_title)) },
        text = {
            Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                LoadState(preview, onRetry = onRetry, emptyText = stringResource(R.string.editorial_catalog_preview_empty)) { html ->
                    Text(AnnotatedString.fromHtml(html), style = MaterialTheme.typography.bodyLarge)
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.editorial_catalog_preview_close)) } },
    )
}
