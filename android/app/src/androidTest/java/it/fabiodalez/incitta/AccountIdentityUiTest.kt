package it.fabiodalez.incitta

import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.User
import it.fabiodalez.incitta.ui.AccountIdentity
import it.fabiodalez.incitta.ui.InCittaTheme
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class AccountIdentityUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()

    @Test fun emailIsVisibleWithNoNameAndLargeFontsOnANarrowPhone() {
        var changed = false
        compose.runOnIdle { compose.activity.setContent {
            InCittaTheme {
                CompositionLocalProvider(LocalDensity provides Density(LocalDensity.current.density, 1.6f)) {
                    Column(Modifier.width(280.dp)) {
                        AccountIdentity(User(1, email = "nome.cognome.molto.lungo@example.test", roleLabel = "Amministratore"), { changed = true })
                    }
                }
            }
        } }
        compose.onNodeWithText("nome.cognome.molto.lungo@example.test").assertIsDisplayed()
        compose.onNodeWithText("Profilo: Amministratore").assertIsDisplayed()
        compose.onNodeWithText(compose.activity.getString(R.string.profile_change_account)).performClick()
        assertTrue(changed)
    }

    @Test fun changingAccountsRemovesTheOldIdentityAndRole() {
        val user = mutableStateOf(User(1, "Fabio", "admin@example.test", roleLabel = "Amministratore"))
        compose.runOnIdle { compose.activity.setContent { InCittaTheme { AccountIdentity(user.value, {}) } } }
        compose.onNodeWithText("admin@example.test").assertIsDisplayed()
        compose.runOnIdle { user.value = User(2, "Giulia", "giulia@example.test") }
        compose.onNodeWithText("giulia@example.test").assertIsDisplayed()
        compose.onNodeWithText("admin@example.test").assertDoesNotExist()
        compose.onNodeWithText("Profilo: Amministratore").assertDoesNotExist()
    }
}
