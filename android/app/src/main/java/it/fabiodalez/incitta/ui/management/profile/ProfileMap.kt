package it.fabiodalez.incitta.ui.management.profile

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.ui.Ink
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.isLightTheme
import org.maplibre.android.MapLibre
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style

/*
 * Il punto del locale sulla mappa (W-PR-03). Sul sito il segnaposto si
 * trascina; sul telefono trascinare un segnaposto piccolo con il dito è
 * scomodo, quindi si sposta la mappa sotto un segnaposto fermo al centro e si
 * conferma con un pulsante. Il risultato è lo stesso: latitudine e
 * longitudine, con l'indirizzo scritto nei campi sotto.
 */

private const val STYLE_LIGHT = "https://tiles.openfreemap.org/styles/positron"
private const val STYLE_DARK = "https://tiles.openfreemap.org/styles/dark"
/* Il centro di Padova, come il segnaposto del pannello quando il locale non ha ancora coordinate. */
private val FALLBACK = LatLng(45.4064, 11.8768)

/**
 * `title`, `help`, `missing` e `pickedZoom` li cambia chi sceglie un punto
 * che non è la porta di un locale (il centro di una città nella redazione,
 * R11); senza, il selettore è quello del locale.
 */
@Composable
internal fun VenueLocationPicker(
    lat: Double?,
    lng: Double?,
    address: String,
    title: String? = null,
    help: String? = null,
    missing: String? = null,
    pickedZoom: Double = 17.0,
    onPick: (Double, Double) -> Unit,
) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val light = isLightTheme
    var map by remember { mutableStateOf<MapLibreMap?>(null) }
    val start = remember { if (lat != null && lng != null) LatLng(lat, lng) else FALLBACK }
    val zoom = if (lat != null && lng != null) pickedZoom else 13.0
    val mapView = remember(light) {
        MapLibre.getInstance(context)
        MapView(context).apply {
            onCreate(null)
            /* Dentro una pagina che scorre: chi tocca la mappa sposta la mappa, non la pagina. */
            setOnTouchListener { view, event ->
                if (event.actionMasked == android.view.MotionEvent.ACTION_DOWN) view.parent?.requestDisallowInterceptTouchEvent(true)
                false
            }
            getMapAsync { ready ->
                ready.cameraPosition = CameraPosition.Builder().target(start).zoom(zoom).build()
                ready.uiSettings.apply {
                    isLogoEnabled = false
                    isAttributionEnabled = true
                    isRotateGesturesEnabled = false
                    isTiltGesturesEnabled = false
                    isCompassEnabled = false
                }
                ready.setStyle(Style.Builder().fromUri(if (light) STYLE_LIGHT else STYLE_DARK))
                map = ready
            }
        }
    }
    DisposableEffect(mapView, lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            mapView.onDestroy()
        }
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(title ?: stringResource(R.string.profile_map), style = MaterialTheme.typography.titleMedium)
        Text(help ?: stringResource(R.string.profile_map_help), color = Muted)
        Box(Modifier.fillMaxWidth().height(260.dp).background(Ink)) {
            AndroidView(factory = { mapView }, modifier = Modifier.fillMaxSize())
            /* La punta del segnaposto sul centro della mappa. */
            Icon(
                Icons.Filled.Place,
                contentDescription = stringResource(R.string.profile_map_marker),
                tint = MaterialTheme.colorScheme.error,
                modifier = Modifier.align(Alignment.Center).size(44.dp).offset(y = (-22).dp),
            )
        }
        Button(
            enabled = map != null,
            onClick = { map?.cameraPosition?.target?.let { onPick(round7(it.latitude), round7(it.longitude)) } },
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) { Text(stringResource(R.string.profile_map_set)) }
        Text(
            when {
                lat == null || lng == null -> missing ?: stringResource(R.string.profile_map_missing)
                address.isNotBlank() -> stringResource(R.string.profile_map_saved, address)
                else -> stringResource(R.string.profile_map_saved_no_address)
            },
            color = Muted,
        )
    }
}

/** Sette decimali, come la colonna del database (circa un centimetro). */
internal fun round7(value: Double): Double = Math.round(value * 10_000_000.0) / 10_000_000.0
