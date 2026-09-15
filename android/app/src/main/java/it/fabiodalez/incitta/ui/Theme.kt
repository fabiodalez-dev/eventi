package it.fabiodalez.incitta.ui

import android.app.Activity
import androidx.compose.foundation.shape.ZeroCornerSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
import it.fabiodalez.incitta.R

val Ink: Color @Composable get() = MaterialTheme.colorScheme.background
val Paper: Color @Composable get() = MaterialTheme.colorScheme.onBackground
val Acid: Color @Composable get() = MaterialTheme.colorScheme.primary
val Muted: Color @Composable get() = MaterialTheme.colorScheme.onSurfaceVariant
val Rule: Color @Composable get() = MaterialTheme.colorScheme.outlineVariant
val Danger: Color @Composable get() = MaterialTheme.colorScheme.error

/*
 * Quale tema e' acceso, dichiarato invece che dedotto.
 *
 * Prima lo si deduceva confrontando il colore di sfondo con una costante
 * scritta a mano (`background == Color(0xFFFCFCFB)`). Funzionava finche'
 * nessuno toccava quel colore: al primo allineamento della palette al sito il
 * confronto e' diventato falso e OGNI titolo del tema chiaro sarebbe tornato
 * maiuscolo, senza che niente segnalasse il perche'.
 *
 * Un tema non si riconosce dal colore che ha: lo dichiara chi lo accende.
 */
private val LocalTemaChiaro = staticCompositionLocalOf { false }

val isLightTheme: Boolean
    @Composable get() = LocalTemaChiaro.current

/*
 * I token che distinguono i due impianti, in un posto solo.
 *
 * Nello scuro la pagina e' un tabellone: tutto sta sullo stesso nero e a
 * separare sono i divisori pieni. Nel chiaro le card sono riquadri staccati su
 * un fondo appena piu' scuro, e a separare sono la superficie e lo spazio.
 * Dichiararlo qui evita che ogni componente se lo ricostruisca con un `if`.
 */
val CardSurface: Color
    @Composable get() = if (isLightTheme) MaterialTheme.colorScheme.surfaceContainerLowest else MaterialTheme.colorScheme.background

val CardShape: androidx.compose.ui.graphics.Shape
    @Composable get() = MaterialTheme.shapes.large

val PosterShape: androidx.compose.ui.graphics.Shape
    @Composable get() = MaterialTheme.shapes.medium

/** Se i divisori pieni del tabellone vanno disegnati: sono il sistema dello scuro. */
val showsRules: Boolean
    @Composable get() = !isLightTheme

private val Archivo = FontFamily(Font(R.font.archivo_semibold, FontWeight.SemiBold))

private val Bricolage = FontFamily(Font(R.font.bricolage_bold, FontWeight.Bold), Font(R.font.bricolage_extrabold, FontWeight.ExtraBold))
private val Manrope = FontFamily(Font(R.font.manrope_regular, FontWeight.Normal), Font(R.font.manrope_bold, FontWeight.Bold))

private val Colors = darkColorScheme(
    primary = Color(0xFFCCFF00), onPrimary = Color(0xFF0B0B0B),
    background = Color(0xFF0B0B0B), onBackground = Color(0xFFF5F5F0),
    surface = Color(0xFF0B0B0B), onSurface = Color(0xFFF5F5F0),
    surfaceVariant = Color(0xFF171717), onSurfaceVariant = Color(0xFFA3A39D),
    outlineVariant = Color(0xFF383838), error = Color(0xFFFF5A5F),
)
/*
 * I valori esatti del tema chiaro del sito, non approssimazioni.
 *
 * Sono gli stessi numeri di `resources/css/light.css`: se qui si scrive
 * #FCFCFB e la' #FAF9F6, l'app e il sito si somigliano senza essere la stessa
 * cosa — e la differenza si vede proprio dove non dovrebbe, cioe' in un
 * riquadro chiaro sopra un fondo chiaro.
 *
 * `surfaceContainerLowest` e' la superficie SOLLEVATA (#FDFCF9): il fondo
 * delle card, appena piu' chiaro della pagina. `surfaceVariant` e' la
 * superficie INCASSATA (#F1F0EC): pannelli e riquadri.
 */
private val LightColors = androidx.compose.material3.lightColorScheme(
    primary = Color(0xFFB54D23), onPrimary = Color(0xFFFAF9F6),
    background = Color(0xFFFAF9F6), onBackground = Color(0xFF262624),
    surface = Color(0xFFFAF9F6), onSurface = Color(0xFF262624),
    surfaceVariant = Color(0xFFF1F0EC), onSurfaceVariant = Color(0xFF686863),
    surfaceContainerLowest = Color(0xFFFDFCF9),
    surfaceContainerHighest = Color(0xFFEAE9E3),
    primaryContainer = Color(0xFFF7E9E1), onPrimaryContainer = Color(0xFF963E1B),
    secondaryContainer = Color(0xFFF7E9E1), onSecondaryContainer = Color(0xFF963E1B),
    outline = Color(0xFF85847E), outlineVariant = Color(0xFFDEDDD7),
    error = Color(0xFFB42318), onError = Color(0xFFFAF9F6),
)

private val Typography = androidx.compose.material3.Typography(
    displayLarge = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 46.sp, lineHeight = 43.sp, letterSpacing = (-1.6).sp),
    displayMedium = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 35.sp, lineHeight = 34.sp, letterSpacing = (-1).sp),
    headlineLarge = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 28.sp, lineHeight = 28.sp, letterSpacing = (-0.5).sp),
    headlineMedium = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 23.sp, lineHeight = 24.sp),
    titleLarge = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 19.sp, lineHeight = 21.sp),
    titleMedium = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, lineHeight = 18.sp),
    bodyLarge = TextStyle(fontFamily = FontFamily.SansSerif, fontSize = 16.sp, lineHeight = 23.sp),
    bodyMedium = TextStyle(fontFamily = FontFamily.SansSerif, fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, letterSpacing = 0.7.sp),
    labelMedium = TextStyle(fontFamily = Archivo, fontWeight = FontWeight.SemiBold, fontSize = 11.sp, letterSpacing = 0.8.sp),
)

private val LightTypography = Typography.copy(
    displayLarge = Typography.displayLarge.copy(fontFamily = Bricolage, fontWeight = FontWeight.ExtraBold),
    displayMedium = Typography.displayMedium.copy(fontFamily = Bricolage, fontWeight = FontWeight.ExtraBold),
    headlineLarge = Typography.headlineLarge.copy(fontFamily = Bricolage, fontWeight = FontWeight.Bold),
    headlineMedium = Typography.headlineMedium.copy(fontFamily = Bricolage, fontWeight = FontWeight.Bold),
    titleLarge = Typography.titleLarge.copy(fontFamily = Bricolage, fontWeight = FontWeight.Bold),
    titleMedium = Typography.titleMedium.copy(fontFamily = Bricolage, fontWeight = FontWeight.Bold),
    bodyLarge = Typography.bodyLarge.copy(fontFamily = Manrope),
    bodyMedium = Typography.bodyMedium.copy(fontFamily = Manrope),
    labelLarge = Typography.labelLarge.copy(fontFamily = Manrope, fontWeight = FontWeight.Bold, letterSpacing = 0.4.sp),
    labelMedium = Typography.labelMedium.copy(fontFamily = Manrope, fontWeight = FontWeight.Bold, letterSpacing = 0.4.sp),
)

private val SquareShapes = Shapes(
    extraSmall = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    small = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    medium = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    large = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    extraLarge = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
)

@Composable
fun InCittaTheme(light: Boolean = false, content: @Composable () -> Unit) {
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = light
            WindowCompat.getInsetsController(window, view).isAppearanceLightNavigationBars = light
        }
    }
    CompositionLocalProvider(LocalTemaChiaro provides light) {
        MaterialTheme(colorScheme = if (light) LightColors else Colors, typography = if (light) LightTypography else Typography, shapes = if (light) CompactShapes else SquareShapes, content = content)
    }
}

/*
 * Nello scuro i titoli sono in maiuscolo — e' il carattere del tabellone; nel
 * chiaro no, perche' Bricolage a peso 700 fa gia' il lavoro che li' fanno le
 * maiuscole.
 */
@Composable
fun eventTitle(text: String): String = if (isLightTheme) text else text.uppercase()

/*
 * I raggi del tema chiaro del sito: due misure, non cinque a caso.
 *
 * Il sito usa 10px per i comandi e 12-18px per i contenitori; qui `small` e'
 * il comando, `large` la card, `extraLarge` il pannello. Prima erano 6dp
 * ovunque: angoli tondi, ma di una tondezza che non diceva niente sulla
 * gerarchia — un pulsante e una scheda avevano la stessa forma.
 */
private val CompactShapes = Shapes(
    extraSmall = androidx.compose.foundation.shape.RoundedCornerShape(8.dp),
    small = androidx.compose.foundation.shape.RoundedCornerShape(10.dp),
    medium = androidx.compose.foundation.shape.RoundedCornerShape(14.dp),
    large = androidx.compose.foundation.shape.RoundedCornerShape(18.dp),
    extraLarge = androidx.compose.foundation.shape.RoundedCornerShape(24.dp),
)

val ControlShape: androidx.compose.ui.graphics.Shape
    @Composable get() = MaterialTheme.shapes.small
