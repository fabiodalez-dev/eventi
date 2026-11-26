package it.fabiodalez.incitta.ui.editorial.common

import android.content.Context
import androidx.activity.compose.BackHandler
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.editorial.EditorialLockReason
import it.fabiodalez.incitta.data.editorial.EditorialRepository
import it.fabiodalez.incitta.data.editorial.PasswordPrompt
import it.fabiodalez.incitta.ui.Muted
import it.fabiodalez.incitta.ui.findActivity
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Il blocco del telefono con `androidx.biometric`: impronta, volto o, in
 * mancanza, il PIN o la sequenza del telefono (`DEVICE_CREDENTIAL`).
 * Se il telefono non ha nessun blocco impostato l'area si apre con la
 * password dell'account (decisione 2).
 */
internal object PhoneLock {
    private const val AUTHENTICATORS = BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL

    fun available(context: Context): Boolean =
        runCatching { BiometricManager.from(context).canAuthenticate(AUTHENTICATORS) == BiometricManager.BIOMETRIC_SUCCESS }.getOrDefault(false)

    /** Mostra il blocco del telefono; `onResult(true)` solo quando riesce. Annullare o sbagliare troppe volte dà `false`. */
    fun prompt(activity: FragmentActivity, title: String, subtitle: String, onResult: (Boolean) -> Unit) {
        val prompt = BiometricPrompt(activity, ContextCompat.getMainExecutor(activity), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) = onResult(true)
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) = onResult(false)
            /* Un tentativo sbagliato: il prompt resta aperto e si riprova lì. */
        })
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle(title)
            .setSubtitle(subtitle)
            .setAllowedAuthenticators(AUTHENTICATORS)
            .build()
        runCatching { prompt.authenticate(info) }.onFailure { onResult(false) }
    }
}

/**
 * La porta dell'area della redazione, sopra tutte le sue schermate.
 *
 * - Chiusa (all'ingresso, dopo 15 minuti in secondo piano o senza tocchi):
 *   copre l'area con la pagina «La redazione è chiusa» e chiede il blocco del
 *   telefono; poi, se il server non è sbloccato da meno di 12 ore, la
 *   password dell'account. Senza blocco del telefono, solo la password.
 * - Aperta: il contenuto, con il conto dell'inattività a ogni tocco.
 * - In ogni momento: la richiesta della password quando una chiamata
 *   risponde 423 (`EditorialUnlockGuard`), che poi ripete la chiamata.
 *
 * `phoneLock` si può sostituire nei test (dove il blocco del telefono non
 * c'è): riceve il contesto e il callback dell'esito.
 */
@Composable
internal fun UnlockGate(
    repository: EditorialRepository,
    modifier: Modifier = Modifier,
    /* Indietro mentre l'area è chiusa: si esce dalla redazione, non si naviga dietro la pagina chiusa. */
    onLockedBack: () -> Unit = {},
    phoneLockAvailable: (Context) -> Boolean = PhoneLock::available,
    phoneLock: (Context, String, String, (Boolean) -> Unit) -> Unit = { context, title, subtitle, done ->
        val activity = context.findActivity() as? FragmentActivity
        if (activity == null) done(false) else PhoneLock.prompt(activity, title, subtitle, done)
    },
    content: @Composable () -> Unit,
) {
    val lock = repository.localLock
    /* Entrando: un'area rimasta aperta oltre il limite d'inattività si chiude subito, prima del primo disegno. */
    remember(lock) { lock.enter() }
    val locked by lock.locked.collectAsState()
    val prompt by repository.guard.prompt.collectAsState()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val lifecycleOwner = LocalLifecycleOwner.current

    /* In secondo piano il tempo conta da quando si esce; tornando si chiude se è passato troppo. */
    DisposableEffect(lifecycleOwner, lock) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_STOP -> lock.onBackground()
                Lifecycle.Event.ON_START -> lock.onForeground()
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }
    /*
     * Uscendo dall'area (Home, Profilo, un altro account) si chiude: il
     * repository resta in memoria finché il token è lo stesso, e senza questo
     * si rientrava senza il blocco del telefono anche ore dopo. Una rotazione
     * ricrea l'attività ma non esce dall'area: lì resta com'è.
     */
    DisposableEffect(lock, context) {
        onDispose { if (context.findActivity()?.isChangingConfigurations != true) lock.leave() }
    }
    /* Senza tocchi: un controllo subito e poi ogni 30 secondi, che basta per un limite di 15 minuti. */
    LaunchedEffect(lock) {
        while (true) {
            lock.checkIdle()
            delay(IDLE_CHECK_MILLIS)
        }
    }

    val title = stringResource(R.string.editorial_unlock_phone_title)
    val subtitle = stringResource(R.string.editorial_unlock_phone_subtitle)
    val hasPhoneLock = remember(context) { phoneLockAvailable(context) }
    var asking by remember { mutableStateOf(false) }
    fun unlock() {
        if (asking) return
        asking = true
        if (hasPhoneLock) {
            phoneLock(context, title, subtitle) { ok ->
                if (ok) scope.launch { try { repository.openAfterPhoneLock() } finally { asking = false } } else asking = false
            }
        } else {
            scope.launch { try { repository.openWithPassword() } finally { asking = false } }
        }
    }

    Box(
        modifier.fillMaxSize().pointerInput(lock) {
            awaitPointerEventScope {
                while (true) {
                    awaitPointerEvent(PointerEventPass.Initial)
                    lock.touch()
                }
            }
        },
    ) {
        /*
         * Il contenuto resta composto sotto la pagina chiusa, così riaprendo
         * si torna dov'eri; mentre è chiusa non si vede, non si tocca e lo
         * screen reader non lo legge.
         */
        Box(Modifier.fillMaxSize().then(if (locked) Modifier.clearAndSetSemantics {} else Modifier)) { content() }
        if (locked) {
            /* All'ingresso il blocco del telefono si apre da solo; dopo un annullamento resta il pulsante. */
            LaunchedEffect(Unit) { unlock() }
            BackHandler { onLockedBack() }
            Surface(Modifier.fillMaxSize()) { LockedScreen(hasPhoneLock, busy = asking, onUnlock = ::unlock) }
        }
    }
    prompt?.let { current ->
        PasswordDialog(current, onSubmit = repository.guard::submit, onDismiss = repository.guard::dismiss)
    }
}

@Composable
private fun LockedScreen(hasPhoneLock: Boolean, busy: Boolean, onUnlock: () -> Unit) {
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Outlined.Lock, contentDescription = null, modifier = Modifier.size(48.dp))
        Text(stringResource(R.string.editorial_locked_title), style = MaterialTheme.typography.headlineMedium)
        Text(stringResource(if (hasPhoneLock) R.string.editorial_locked_text else R.string.editorial_locked_text_password), color = Muted)
        Button(onClick = onUnlock, enabled = !busy, modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp)) {
            Text(stringResource(R.string.editorial_unlock))
        }
    }
}

/**
 * La password dell'account, per lo sblocco delle 12 ore o per un'azione
 * pericolosa. Il testo scritto non si salva (né nello stato salvato né
 * altrove): una rotazione lo svuota.
 */
@Composable
internal fun PasswordDialog(prompt: PasswordPrompt, onSubmit: (String) -> Unit, onDismiss: () -> Unit) {
    var password by remember { mutableStateOf("") }
    val submit = { if (password.isNotEmpty() && !prompt.busy) { onSubmit(password); password = "" } }
    AlertDialog(
        onDismissRequest = { if (!prompt.busy) onDismiss() },
        icon = { Icon(Icons.Outlined.Lock, contentDescription = null) },
        title = { Text(stringResource(R.string.editorial_password_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(stringResource(if (prompt.reason == EditorialLockReason.PasswordRequired) R.string.editorial_password_required else R.string.editorial_password_locked))
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text(stringResource(R.string.editorial_password_field)) },
                    singleLine = true,
                    enabled = !prompt.busy,
                    isError = prompt.error != null,
                    supportingText = prompt.error?.let { message -> { Text(message, color = MaterialTheme.colorScheme.error) } },
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done, autoCorrectEnabled = false),
                    keyboardActions = KeyboardActions(onDone = { submit() }),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (prompt.busy) {
                    val checking = stringResource(R.string.editorial_password_checking)
                    LinearProgressIndicator(Modifier.fillMaxWidth().semantics { contentDescription = checking })
                }
            }
        },
        confirmButton = {
            TextButton(onClick = submit, enabled = password.isNotEmpty() && !prompt.busy, modifier = Modifier.heightIn(min = 48.dp)) {
                Text(stringResource(R.string.editorial_password_submit))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !prompt.busy, modifier = Modifier.heightIn(min = 48.dp)) { Text(stringResource(R.string.editorial_cancel)) }
        },
    )
}

private const val IDLE_CHECK_MILLIS = 30_000L
