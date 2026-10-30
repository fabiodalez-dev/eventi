package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import java.net.URLEncoder

@Serializable
internal data class ContactSettings(val enabled: Boolean = false, val guests: Boolean = false, val url: String = "")
@Serializable
internal data class ContactBody(val message: String)

@Composable
internal fun PublicContactSection(type: String, slug: String, session: Session?, onLogin: () -> Unit) {
    val context = LocalContext.current
    val uri = LocalUriHandler.current
    val api = remember { ApiClient(LocalStore(context).installationId) }
    val endpoint = "$type/${URLEncoder.encode(slug, "UTF-8")}/contact"
    var settings by remember(type, slug) { mutableStateOf<ContactSettings?>(null) }
    var message by rememberSaveable(type, slug, session?.user?.id) { mutableStateOf("") }
    var feedback by remember(type, slug) { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    /*
     * Come sul sito: con l'email non confermata al posto del modulo c'è
     * l'invito a confermarla. Parte dal profilo salvato e si accende anche
     * col 403 `EMAIL_VERIFICATION_REQUIRED`, se il profilo era vecchio.
     */
    var needsVerification by remember(type, slug, session?.user?.id, session?.user?.emailVerified) { mutableStateOf(session?.user?.emailVerified == false) }
    var verificationMessage by remember(type, slug, session?.user?.id) { mutableStateOf<String?>(null) }
    val verificationSent = stringResource(R.string.email_verification_sent)
    val scope = rememberCoroutineScope()
    LaunchedEffect(endpoint) {
        try { settings = api.get<ApiEnvelope<ContactSettings>>(endpoint).data }
        catch (e: CancellationException) { throw e }
        catch (_: Exception) { settings = null }
    }
    val contact = settings ?: return
    if (!contact.enabled) return
    Column(Modifier.fillMaxWidth().padding(vertical = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("CONTATTA", style = MaterialTheme.typography.titleLarge)
        if (session == null) {
            Button(onClick = onLogin) { Text("Accedi per inviare un messaggio") }
            if (contact.guests) OutlinedButton(onClick = { uri.openUri(contact.url) }) { Text("Apri il modulo con verifica anti-spam") }
        } else if (needsVerification) {
            Text(verificationMessage ?: stringResource(R.string.contact_verify_required))
            feedback?.let { Text(it) }
            EmailVerificationPrompt(busy, onResend = {
                scope.launch {
                    busy = true
                    try {
                        api.post<ApiEnvelope<ApiMessage>, Map<String, String>>("auth/verification/resend", emptyMap(), session.token)
                        feedback = verificationSent
                    } catch (e: CancellationException) { throw e }
                    catch (e: Exception) { feedback = requestFailureMessage(e) }
                    finally { busy = false }
                }
            }, onConfirmed = { needsVerification = false; feedback = null })
        } else {
            OutlinedTextField(message, { if (it.length <= 5000) message = it }, enabled = !busy,
                label = { Text("Il tuo messaggio") }, minLines = 4, modifier = Modifier.fillMaxWidth())
            Text("Nome, email e messaggio saranno inviati al destinatario per risponderti.", style = MaterialTheme.typography.bodySmall)
            Button(enabled = !busy && message.trim().length in 10..5000, onClick = {
                scope.launch {
                    busy = true; feedback = null
                    try {
                        api.post<ApiEnvelope<ApiMessage>, ContactBody>(endpoint, ContactBody(message.trim()), session.token)
                        message = ""; feedback = "Messaggio inviato. Riceverai l’eventuale risposta alla tua email."
                    } catch (e: CancellationException) { throw e }
                    catch (e: Exception) {
                        // Il messaggio del server diventa il testo dell'invito, non un errore in più sotto.
                        if (requiresEmailVerification(e)) { verificationMessage = requestFailureMessage(e); needsVerification = true }
                        else feedback = requestFailureMessage(e)
                    }
                    finally { busy = false }
                }
            }) { Text("Invia messaggio") }
            feedback?.let { Text(it) }
        }
    }
}
