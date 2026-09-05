package it.fabiodalez.incitta.ui

import android.app.Activity
import androidx.compose.foundation.shape.ZeroCornerSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
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

val Ink = Color(0xFF0B0B0B)
val Paper = Color(0xFFF5F5F0)
val Acid = Color(0xFFCCFF00)
val Muted = Color(0xFFA3A39D)
val Rule = Color(0xFF383838)
val Danger = Color(0xFFFF5A5F)

private val Archivo = FontFamily(Font(R.font.archivo_semibold, FontWeight.SemiBold))

private val Colors = darkColorScheme(
    primary = Acid,
    onPrimary = Ink,
    background = Ink,
    onBackground = Paper,
    surface = Ink,
    onSurface = Paper,
    surfaceVariant = Color(0xFF171717),
    onSurfaceVariant = Muted,
    error = Danger,
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

private val SquareShapes = Shapes(
    extraSmall = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    small = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    medium = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    large = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
    extraLarge = androidx.compose.foundation.shape.RoundedCornerShape(ZeroCornerSize),
)

@Composable
fun InCittaTheme(content: @Composable () -> Unit) {
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = false
            WindowCompat.getInsetsController(window, view).isAppearanceLightNavigationBars = false
        }
    }
    MaterialTheme(colorScheme = Colors, typography = Typography, shapes = SquareShapes, content = content)
}
