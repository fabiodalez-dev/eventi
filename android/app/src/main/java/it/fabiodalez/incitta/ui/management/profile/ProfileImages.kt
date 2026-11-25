package it.fabiodalez.incitta.ui.management.profile

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ArrowDownward
import androidx.compose.material.icons.outlined.ArrowUpward
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.management.CropShape
import it.fabiodalez.incitta.data.management.ImageCrop
import it.fabiodalez.incitta.data.management.ImageUploadException
import it.fabiodalez.incitta.data.management.PreparedImage
import it.fabiodalez.incitta.data.management.VenueImageSlot
import it.fabiodalez.incitta.data.management.cropPreparedImage
import it.fabiodalez.incitta.data.management.prepareImage
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.common.ConfirmDialog
import it.fabiodalez.incitta.ui.management.common.LoadState
import it.fabiodalez.incitta.ui.management.common.ManagementScaffold
import it.fabiodalez.incitta.ui.management.common.imageErrorText
import it.fabiodalez.incitta.ui.management.common.rememberImagePicker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/*
 * Logo, copertina e galleria (W-PR-02), sugli endpoint `V/media` delle
 * fondamenta. Logo e copertina passano dal ritaglio a proporzione fissa
 * (decisione 5); le foto della galleria si caricano intere, come sul sito, e
 * si riordinano con «sposta prima» e «sposta dopo».
 */

/** Cosa si sta caricando: un posto fisso o una foto della galleria. */
private sealed interface ImageTarget {
    data class Slot(val slot: VenueImageSlot) : ImageTarget
    data object Gallery : ImageTarget
}

@Composable
internal fun VenueImagesScreen(viewModel: VenueImagesViewModel, context: ManagementGraphContext) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val galleryMax = state.galleryMax
    val android = LocalContext.current
    val scope = rememberCoroutineScope()
    var target by remember { mutableStateOf<ImageTarget?>(null) }
    var preparing by remember { mutableStateOf(false) }
    var cropping by remember { mutableStateOf<Pair<VenueImageSlot, PreparedImage>?>(null) }
    var removing by remember { mutableStateOf<Pair<String, () -> Unit>?>(null) }
    val texts = ImageErrorTexts(
        unreadable = stringResource(R.string.management_image_unreadable),
        notImage = stringResource(R.string.management_image_not_image),
        tooLarge = stringResource(R.string.management_image_too_large),
        tooManyPixels = stringResource(R.string.management_image_too_many_pixels),
    )
    val pick = rememberImagePicker { uri ->
        val chosen = target ?: return@rememberImagePicker
        val key = when (chosen) { is ImageTarget.Slot -> chosen.slot.segment; ImageTarget.Gallery -> VenueImagesViewModel.GALLERY }
        preparing = true
        scope.launch {
            try {
                val prepared = withContext(Dispatchers.IO) { prepareImage(android, uri) }
                when (chosen) {
                    is ImageTarget.Slot -> cropping = chosen.slot to prepared
                    ImageTarget.Gallery -> viewModel.addToGallery(prepared)
                }
            } catch (e: ImageUploadException) {
                viewModel.showError(key, texts.of(e.reason))
            } finally { preparing = false }
        }
    }
    ManagementScaffold(title = stringResource(R.string.profile_section_images), padding = context.padding, onBack = context::back, busy = state.busy || preparing) {
        if (preparing) Text(stringResource(R.string.profile_image_preparing), color = Muted)
        LoadState(state.images, onRetry = viewModel::load) { images ->
            SlotCard(
                title = stringResource(R.string.profile_logo), help = stringResource(R.string.profile_logo_help), url = images.logo,
                square = true, busy = state.busy || preparing, error = state.errors[VenueImageSlot.Logo.segment],
                onPick = { target = ImageTarget.Slot(VenueImageSlot.Logo); pick() },
                onRemove = { removing = VenueImageSlot.Logo.segment to { viewModel.remove(VenueImageSlot.Logo) } },
            )
            SlotCard(
                title = stringResource(R.string.profile_cover), help = stringResource(R.string.profile_cover_help), url = images.cover,
                square = false, busy = state.busy || preparing, error = state.errors[VenueImageSlot.Cover.segment],
                onPick = { target = ImageTarget.Slot(VenueImageSlot.Cover); pick() },
                onRemove = { removing = VenueImageSlot.Cover.segment to { viewModel.remove(VenueImageSlot.Cover) } },
            )
            Text(stringResource(R.string.profile_gallery), style = MaterialTheme.typography.titleLarge)
            Text(pluralStringResource(R.plurals.profile_gallery_help, galleryMax, galleryMax), color = Muted)
            state.errors[VenueImagesViewModel.GALLERY]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (images.gallery.isEmpty()) Text(stringResource(R.string.profile_gallery_empty), color = Muted)
            images.gallery.forEachIndexed { index, photo ->
                val position = index + 1
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AsyncImage(
                        model = photo.url,
                        contentDescription = stringResource(R.string.profile_gallery_photo, position),
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.size(96.dp, 72.dp).background(MaterialTheme.colorScheme.surfaceVariant),
                    )
                    Spacer(Modifier.weight(1f))
                    IconButton(enabled = index > 0 && !state.busy, onClick = { viewModel.move(photo.id, -1) }, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.ArrowUpward, contentDescription = stringResource(R.string.profile_gallery_up, position))
                    }
                    IconButton(enabled = index < images.gallery.lastIndex && !state.busy, onClick = { viewModel.move(photo.id, 1) }, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.ArrowDownward, contentDescription = stringResource(R.string.profile_gallery_down, position))
                    }
                    IconButton(enabled = !state.busy, onClick = { removing = VenueImagesViewModel.GALLERY to { viewModel.removeFromGallery(photo.id) } }, modifier = Modifier.size(48.dp)) {
                        Icon(Icons.Outlined.Delete, contentDescription = stringResource(R.string.profile_gallery_remove, position))
                    }
                }
            }
            val full = images.gallery.size >= galleryMax
            Button(enabled = !full && !state.busy && !preparing, onClick = { target = ImageTarget.Gallery; pick() }, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
                Text(stringResource(R.string.profile_gallery_add))
            }
            if (full) Text(stringResource(R.string.profile_gallery_full), color = Muted)
        }
    }
    cropping?.let { (slot, image) ->
        val failed = stringResource(R.string.profile_crop_failed)
        CropDialog(
            image = image,
            shape = if (slot == VenueImageSlot.Logo) CropShape.Square else CropShape.Wide,
            onDismiss = { cropping = null },
            onCrop = { rect ->
                cropping = null
                preparing = true
                scope.launch {
                    val cropped = withContext(Dispatchers.Default) { cropPreparedImage(image, rect) }
                    preparing = false
                    if (cropped == null) viewModel.showError(slot.segment, failed) else viewModel.upload(slot, cropped)
                }
            },
        )
    }
    removing?.let { (_, action) ->
        ConfirmDialog(
            title = stringResource(R.string.profile_image_remove_title),
            text = stringResource(R.string.profile_image_remove_text),
            confirmLabel = stringResource(R.string.profile_image_remove),
            destructive = true,
            onConfirm = { removing = null; action() },
            onDismiss = { removing = null },
        )
    }
}

private class ImageErrorTexts(val unreadable: String, val notImage: String, val tooLarge: String, val tooManyPixels: String) {
    fun of(reason: ImageUploadException.Reason): String = when (imageErrorText(reason)) {
        R.string.management_image_not_image -> notImage
        R.string.management_image_too_large -> tooLarge
        R.string.management_image_too_many_pixels -> tooManyPixels
        else -> unreadable
    }
}

@Composable
private fun SlotCard(title: String, help: String, url: String?, square: Boolean, busy: Boolean, error: String?, onPick: () -> Unit, onRemove: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        Text(help, color = Muted)
        if (url != null) {
            AsyncImage(
                model = url,
                contentDescription = title,
                contentScale = ContentScale.Crop,
                modifier = (if (square) Modifier.size(120.dp) else Modifier.fillMaxWidth().aspectRatio(16f / 9f)).background(MaterialTheme.colorScheme.surfaceVariant),
            )
        } else Text(stringResource(R.string.profile_image_none), color = Muted)
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(enabled = !busy, onClick = onPick, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(if (url == null) R.string.profile_image_choose else R.string.profile_image_replace))
            }
            if (url != null) OutlinedButton(enabled = !busy, onClick = onRemove, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.profile_image_remove)) }
        }
    }
}

/**
 * Il ritaglio: la foto si sposta con un dito e si ingrandisce con due sotto
 * una cornice fissa della proporzione giusta. Il rettangolo finale lo calcola
 * `ImageCrop`, in pixel della foto preparata.
 */
@Composable
private fun CropDialog(image: PreparedImage, shape: CropShape, onDismiss: () -> Unit, onCrop: (it.fabiodalez.incitta.data.management.CropRect) -> Unit) {
    val bitmap = remember(image) { runCatching { BitmapFactory.decodeByteArray(image.bytes, 0, image.bytes.size) }.getOrNull() }
    var frame by remember { mutableStateOf(IntSize.Zero) }
    var zoom by remember { mutableFloatStateOf(1f) }
    var offsetX by remember { mutableFloatStateOf(0f) }
    var offsetY by remember { mutableFloatStateOf(0f) }
    val frameLabel = stringResource(R.string.profile_crop_frame)
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxWidth().padding(12.dp)) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(stringResource(R.string.profile_crop_title), style = MaterialTheme.typography.titleLarge)
                Text(stringResource(R.string.profile_crop_help), color = Muted)
                if (bitmap == null) {
                    Text(stringResource(R.string.profile_crop_failed), color = MaterialTheme.colorScheme.error)
                } else {
                    val imageBitmap = remember(bitmap) { bitmap.asImageBitmap() }
                    Box(
                        Modifier.fillMaxWidth().aspectRatio(shape.ratio).clipToBounds().background(Color.Black)
                            .border(2.dp, MaterialTheme.colorScheme.primary)
                            .onSizeChanged { frame = it }
                            .semantics { contentDescription = frameLabel }
                            .pointerInput(bitmap, frame) {
                                detectTransformGestures { _, pan, gestureZoom, _ ->
                                    if (frame.width == 0) return@detectTransformGestures
                                    zoom = (zoom * gestureZoom).coerceIn(1f, ImageCrop.MAX_ZOOM)
                                    val scale = ImageCrop.baseScale(bitmap.width, bitmap.height, frame.width.toFloat(), frame.height.toFloat()) * zoom
                                    offsetX = ImageCrop.clampOffset(offsetX + pan.x, bitmap.width, scale, frame.width.toFloat())
                                    offsetY = ImageCrop.clampOffset(offsetY + pan.y, bitmap.height, scale, frame.height.toFloat())
                                }
                            },
                    ) {
                        if (frame.width > 0) {
                            val base = ImageCrop.baseScale(bitmap.width, bitmap.height, frame.width.toFloat(), frame.height.toFloat())
                            val density = androidx.compose.ui.platform.LocalDensity.current
                            /* L'immagine disegnata alla scala di copertura e poi ingrandita e spostata come vuole chi ritaglia. */
                            Image(
                                bitmap = imageBitmap,
                                contentDescription = null,
                                contentScale = ContentScale.FillBounds,
                                modifier = Modifier.align(Alignment.Center)
                                    .requiredSize(with(density) { (bitmap.width * base).toDp() }, with(density) { (bitmap.height * base).toDp() })
                                    .graphicsLayer { scaleX = zoom; scaleY = zoom; translationX = offsetX; translationY = offsetY },
                            )
                        }
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.align(Alignment.End)) {
                    TextButton(onClick = onDismiss, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.management_cancel)) }
                    Button(
                        enabled = bitmap != null && frame.width > 0,
                        onClick = {
                            val source = bitmap ?: return@Button
                            onCrop(ImageCrop.rect(source.width, source.height, frame.width.toFloat(), frame.height.toFloat(), zoom, offsetX, offsetY))
                        },
                        modifier = Modifier.heightIn(min = 48.dp),
                    ) { Text(stringResource(R.string.profile_crop_use)) }
                }
            }
        }
    }
}
