package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import it.fabiodalez.incitta.R
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import java.net.URLEncoder

@Composable
internal fun CommunityScreen(session: Session?, padding: PaddingValues, savedIds: Set<Long>, initial: String = "feed",
    onBack: () -> Unit, onLogin: () -> Unit, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit, onProfileSaved: () -> Unit,
    onUnauthorized: () -> Unit = {}, onDestination: (String) -> Unit = {}) {
    val context = LocalContext.current
    val savedMessage = stringResource(R.string.community_saved)
    val errorMessage = stringResource(R.string.community_error)
    val uncertainMessage = stringResource(R.string.community_wa_send_uncertain)
    val blockedMessage = stringResource(R.string.community_blocked)
    val unblockedMessage = stringResource(R.string.community_unblocked)
    val commentDeletedMessage = stringResource(R.string.community_comment_deleted)
    val reportedMessage = stringResource(R.string.community_reported)
    val readAllMessage = stringResource(R.string.community_read_all_done)
    val api = remember(session?.token) { CommunityApi(ApiClient(LocalStore(context).installationId), session?.token) }
    val scope = rememberCoroutineScope()
    var user by remember(session?.token) { mutableStateOf(session?.user) }
    var stack by rememberSaveable(session?.token) { mutableStateOf(listOf(if (session == null) "people" else initial)) }
    val route = stack.last()
    var page by remember(route) { mutableIntStateOf(1) }
    var revision by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var status by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var envelope by remember(route) { mutableStateOf(buildJsonObject {}) }
    var search by rememberSaveable { mutableStateOf("") }
    var submittedSearch by rememberSaveable { mutableStateOf("") }
    var featured by rememberSaveable { mutableStateOf(false) }
    var discover by rememberSaveable { mutableStateOf(false) }
    var eventOrder by rememberSaveable { mutableStateOf(false) }
    var past by rememberSaveable { mutableStateOf(false) }
    var cursors by remember(route) { mutableStateOf(listOf<String?>(null)) }
    var report by remember { mutableStateOf<Pair<String, Long>?>(null) }
    var reportNote by remember { mutableStateOf("") }
    var deleteComment by remember { mutableStateOf<Long?>(null) }
    var blockTarget by remember { mutableStateOf<Pair<Long, Boolean>?>(null) }
    val whatsappCode by it.fabiodalez.incitta.community.WhatsappAutofill.received.collectAsState()
    var successMessage: String? = null
    // Not a success: an outcome to double-check, shown without the success colour.
    var noticeMessage: String? = null
    fun navigate(next: String) { error = null; status = null; notice = null; stack = pushRoute(stack, next) }
    fun back() { if (stack.size > 1) stack = stack.dropLast(1) else onBack() }
    fun mutate(action: suspend () -> Unit) {
        if (busy) return
        scope.launch {
            busy = true; error = null; status = null; notice = null; successMessage = null; noticeMessage = null
            try { action(); revision++; notice = noticeMessage; status = if (noticeMessage != null) null else successMessage ?: savedMessage }
            catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                // A 401 means the token is dead: refreshing the profile lets the repository sign out for the same token.
                if (e is ApiException && e.status == 401) onUnauthorized()
                error = communityFailureMessage(e).ifBlank { errorMessage }
            }
            finally { busy = false }
        }
    }
    BackHandler { back() }
    LaunchedEffect(initial) { if (session != null && stack.last() != initial) navigate(initial) }
    LaunchedEffect(whatsappCode) {
        if (it.fabiodalez.incitta.community.shouldNavigateToWhatsapp(whatsappCode, session?.token, System.currentTimeMillis()) && stack.last() != "whatsapp") navigate("whatsapp")
    }
    LaunchedEffect(session?.token, revision) {
        if (session != null) try { user = api.refreshUser() } catch (e: CancellationException) { throw e } catch (_: Exception) { }
    }
    LaunchedEffect(route, page, revision, submittedSearch, featured, discover, eventOrder, past, cursors) {
        loading = true; error = null
        try {
            val path = when {
                route == "feed" -> "feed?page=$page&scope=${if (discover) "discover" else "following"}&sort=${if(eventOrder) "event" else "recent"}&past=${if(past) 1 else 0}"
                route == "people" -> "people?page=$page&q=${URLEncoder.encode(submittedSearch, "UTF-8")}&featured=${if(featured) 1 else 0}"
                route.startsWith("profile/") -> "people/${route.substringAfter('/') }?page=$page&past=${if(past) 1 else 0}"
                route.startsWith("post/") -> "posts/${route.substringAfter('/')}?page=$page"
                route.startsWith("save/") -> "saved/${route.substringAfter('/')}"
                route == "settings" -> "profile"
                route == "followers" -> "followers?page=$page"
                else -> route
            }
            if (user?.emailVerified == false && route !in listOf("people") && !route.startsWith("profile/") && !route.startsWith("post/")) {
                envelope = buildJsonObject {}
            } else envelope = if (route == "inbox") api.notifications(cursors.last()) else api.get(path)
        } catch (e: CancellationException) { throw e }
        catch (e: Exception) {
            // Same as mutate(): a dead token is handed back to the repository instead of retried forever.
            if (e is ApiException && e.status == 401) onUnauthorized()
            error = communityFailureMessage(e).ifBlank { errorMessage }
        }
        finally { loading = false }
    }
    val data = envelope.obj("data")
    Column(Modifier.fillMaxSize().padding(bottom = padding.calculateBottomPadding()).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            TextButton(onClick = { back() }) { Text(stringResource(R.string.community_back)) }
            TextButton(onClick = { cursors = listOf(null); revision++ }) { Text(stringResource(R.string.community_refresh)) }
        }
        Text(stringResource(R.string.community_title), style = MaterialTheme.typography.headlineLarge)
        PeekTabRow {
            TextButton(onClick = { if (session != null) navigate("feed") else onLogin() }) { Text(stringResource(R.string.community_feed)) }
            TextButton(onClick = { navigate("people") }) { Text(stringResource(R.string.community_people)) }
            TextButton(onClick = { if (session != null) navigate("settings") else onLogin() }) { Text(stringResource(R.string.community_profile)) }
            TextButton(onClick = { if (session != null) navigate("inbox") else onLogin() }) { Text(stringResource(R.string.community_inbox)) }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        status?.let { Text(it, color = Acid) }
        notice?.let { Text(it, color = MaterialTheme.colorScheme.onSurface) }
        if (user?.emailVerified == false) {
            Text(stringResource(R.string.community_email), color = Muted)
            OutlinedButton(enabled = !busy, onClick = { mutate { api.resendEmail() } }) { Text(stringResource(R.string.community_email_send)) }
        }
        if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (envelope.isNotEmpty()) when {
            route == "feed" -> {
                CommunityCheck(stringResource(R.string.community_discover), discover) { discover = it; page = 1 }
                CommunityCheck(stringResource(R.string.community_event_order), eventOrder) { eventOrder = it; page = 1 }
                CommunityCheck(stringResource(R.string.community_past), past) { past = it; page = 1 }
                val posts = envelope.rows("data")
                if (posts.isEmpty()) { Text(stringResource(R.string.community_empty), color = Muted); OutlinedButton(onClick = { navigate("people") }) { Text(stringResource(R.string.community_people)) } }
                posts.forEach { post -> CommunityPostCard(post, api, savedIds, onOpen, onSave, { navigate("profile/$it") }, { navigate("post/$it") }, { navigate("save/$it") }) }
            }
            route == "people" -> {
                OutlinedTextField(search, { search = it.take(80) }, label = { Text(stringResource(R.string.community_search)) }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                Button(onClick = { submittedSearch = search; page = 1; revision++ }) { Text(stringResource(R.string.community_people)) }
                CommunityCheck(stringResource(R.string.community_featured), featured) { featured = it; page = 1 }
                val people = envelope.rows("data")
                if (people.isEmpty()) Text(stringResource(R.string.community_no_people), color = Muted)
                people.forEach { person ->
                    CommunityPerson(person, api) { navigate("profile/${person.text("handle")}") }
                    if (!person.flag("is_own")) OutlinedButton(enabled = !busy, onClick = { if(session == null) onLogin() else mutate { api.change("people/${person.number("user_id")}/follow", method = if(person.flag("is_following")) "DELETE" else "POST") } }) { Text(stringResource(if(person.flag("is_following")) R.string.community_unfollow else R.string.community_follow)) }
                    HorizontalDivider(color = Rule)
                }
            }
            route.startsWith("profile/") -> {
                val profile = data.obj("profile")
                CommunityPerson(profile, api) { }
                Text(stringResource(R.string.community_counts, profile.number("followers_count"), profile.number("following_count")), color = Muted)
                if (profile.flag("is_own")) Button(onClick = { navigate("settings") }) { Text(stringResource(R.string.community_profile)) }
                else {
                    Button(enabled = !busy, onClick = { if (session == null) onLogin() else mutate { api.change("people/${profile.number("user_id")}/follow", method = if(profile.flag("is_following")) "DELETE" else "POST") } }) { Text(stringResource(if(profile.flag("is_following")) R.string.community_unfollow else R.string.community_follow)) }
                    if (session != null) {
                        TextButton(onClick = { blockTarget = profile.number("user_id") to true }, enabled = !busy) { Text(stringResource(R.string.community_block)) }
                        Text(stringResource(R.string.community_block_help), color = Muted, style = MaterialTheme.typography.bodySmall)
                        TextButton(onClick = { report = "community_profile" to profile.number("id") }) { Text(stringResource(R.string.community_report)) }
                    }
                }
                data.rows("venues").takeIf { it.isNotEmpty() }?.let { venues ->
                    Text(stringResource(R.string.community_venues), style = MaterialTheme.typography.titleLarge)
                    val uri = androidx.compose.ui.platform.LocalUriHandler.current
                    venues.forEach { venue -> TextButton(onClick = { val url = venue.text("url"); if (url.startsWith("https://")) uri.openUri(url) }) { Text(venue.text("name")) } }
                }
                CommunityCheck(stringResource(R.string.community_past), past) { past = it; page = 1 }
                data.rows("posts").forEach { post -> CommunityPostCard(post, api, savedIds, onOpen, onSave, { navigate("profile/$it") }, { navigate("post/$it") }, { navigate("save/$it") }) }
            }
            route.startsWith("post/") -> {
                val post = data.obj("post")
                CommunityPostCard(post, api, savedIds, onOpen, onSave, { navigate("profile/$it") }, {}, { navigate("save/$it") })
                if (session != null) TextButton(onClick = { report = "community_post" to post.number("id") }) { Text(stringResource(R.string.community_report)) }
                Text(stringResource(R.string.community_comments), style = MaterialTheme.typography.titleLarge)
                var replyTo by remember(route) { mutableStateOf<Long?>(null) }
                var body by rememberSaveable(route) { mutableStateOf("") }
                if (data.rows("comments").isEmpty()) Text(stringResource(R.string.community_no_comments), color = Muted)
                data.rows("comments").forEach { comment ->
                    Column(Modifier.padding(start = if(comment.number("parent_id") > 0) 18.dp else 0.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        HorizontalDivider(color = Rule)
                        val handle = comment.text("handle")
                        // A hidden author arrives as "Utente" with no handle: it stays plain text, not a dead link.
                        Box(Modifier.heightIn(min = 48.dp).then(if (handle.isNotBlank()) Modifier.clickable(role = Role.Button) { navigate("profile/$handle") } else Modifier), contentAlignment = Alignment.CenterStart) {
                            Text(comment.text("display_name"), style = MaterialTheme.typography.titleSmall)
                        }
                        if (comment.number("parent_id") > 0) Text("${stringResource(R.string.community_reply_to)} #${comment.number("parent_id")}", color = Muted, style = MaterialTheme.typography.labelSmall)
                        Text(comment.text("body"))
                        if(post.flag("can_comment") && comment.number("parent_id") == 0L) TextButton(onClick = { replyTo = comment.number("id") }) { Text(stringResource(R.string.community_reply)) }
                        if(comment.flag("can_delete")) TextButton(enabled = !busy, onClick = { deleteComment = comment.number("id") }) { Text(stringResource(R.string.community_delete_comment)) }
                        if(session != null) TextButton(onClick = { report = "community_comment" to comment.number("id") }) { Text(stringResource(R.string.community_report)) }
                    }
                }
                if(post.flag("can_comment")) {
                    replyTo?.let { Text("${stringResource(R.string.community_reply_to)} #$it"); TextButton(onClick = { replyTo = null }) { Text(stringResource(R.string.community_cancel)) } }
                    OutlinedTextField(body, { body = it.take(1000) }, label = { Text(stringResource(R.string.community_write)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
                    Button(enabled = !busy && body.isNotBlank(), onClick = { mutate { api.change("posts/${post.number("id")}/comments", buildJsonObject { put("body", body); replyTo?.let { put("parent_id", it) } }); body = ""; replyTo = null } }) { Text(stringResource(R.string.community_send)) }
                } else { Text(stringResource(R.string.community_verify_required), color = Muted); Button(onClick = { if(session == null) onLogin() else navigate(if(user?.whatsappVerified == true) "settings" else "whatsapp") }) { Text(stringResource(R.string.community_whatsapp)) } }
            }
            route == "whatsapp" -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                CommunityWhatsapp(data, busy, session?.token, { path, body, method -> mutate {
                    val handshake = if (path == "whatsapp" && method == "POST" && data.flag("autofill_available") && session != null)
                        it.fabiodalez.incitta.community.WhatsappAutofill.begin(context, session.token) else null
                    val payload = if (handshake == null) body else buildJsonObject { body.forEach { (key, value) -> put(key, value) }; put("delivery", "one_tap") }
                    try {
                        val result = api.change(path, payload, method)
                        if (path == "whatsapp" && method == "POST" && whatsappDeliveryUncertain(result.obj("data"))) noticeMessage = uncertainMessage
                        if (handshake != null && session != null) it.fabiodalez.incitta.community.WhatsappAutofill.bind(context, handshake, session.token, result.obj("data").text("challenge_id"))
                        if(path.endsWith("confirm")) { it.fabiodalez.incitta.community.WhatsappAutofill.clear(context); onProfileSaved(); navigate("settings") }
                        if(method == "DELETE") it.fabiodalez.incitta.community.WhatsappAutofill.clear(context)
                    } catch (e: Exception) {
                        if (handshake != null) it.fabiodalez.incitta.community.WhatsappAutofill.clear(context)
                        throw e
                    }
                } }, { navigate("settings") })
                if(!data.flag("verified")) TextButton(enabled = !busy, onClick = { mutate { api.change("whatsapp/skip"); it.fabiodalez.incitta.community.WhatsappAutofill.clear(context); onProfileSaved(); navigate("feed") } }) { Text(stringResource(R.string.community_later)) }
            }
            route == "settings" -> {
                if(!data.flag("verified")) { Text(stringResource(R.string.community_verify_required)); Button(onClick = { navigate("whatsapp") }) { Text(stringResource(R.string.community_whatsapp)) } }
                else CommunityProfileEditor(data, busy) { body, avatar -> mutate { api.profile(body, avatar, context); onProfileSaved() } }
                TextButton(onClick = { navigate("whatsapp") }) { Text(stringResource(R.string.community_whatsapp)) }
                TextButton(onClick = { navigate("followers") }) { Text(stringResource(R.string.community_followers)) }
            }
            route.startsWith("save/") -> CommunityPublicationEditor(data, user?.whatsappVerified == true, busy, { navigate("whatsapp") }) { body -> mutate { api.change("saved/${route.substringAfter('/')}", body, "PUT") } }
            route == "followers" -> {
                Text(stringResource(R.string.community_followers), style = MaterialTheme.typography.titleLarge)
                data.rows("followers").forEach { follower -> Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    TextButton(onClick = { follower.text("handle").takeIf { it.isNotEmpty() }?.let { navigate("profile/$it") } }) { Text(follower.text("display_name")) }
                    TextButton(enabled = !busy, onClick = { blockTarget = follower.number("user_id") to false }) { Text(stringResource(R.string.community_block)) }
                } }
                data.rows("blocks").forEach { block -> Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(block.text("display_name")); TextButton(enabled = !busy, onClick = { mutate { api.change("people/${block.number("user_id")}/block", method = "DELETE"); successMessage = unblockedMessage } }) { Text(stringResource(R.string.community_unblock)) }
                } }
            }
            route == "inbox" -> {
                TextButton(enabled = !busy, onClick = { mutate { api.readNotifications(envelope.obj("meta").number("watermark")); CommunityUpdates.changed(); cursors = listOf(null); successMessage = readAllMessage } }) { Text(stringResource(R.string.community_read_all)) }
                if (envelope.rows("data").isEmpty()) Text(stringResource(R.string.community_no_notifications), color = Muted)
                envelope.rows("data").forEach { notification ->
                    val item = notification.obj("data")
                    Text(item.text("title"), style = MaterialTheme.typography.titleMedium); Text(item.text("body"), color = Muted)
                    val url = item.text("url")
                    val native = when { url.contains("/bacheca/post/") -> url.substringAfterLast('/').toLongOrNull()?.let { "post/$it" }; url.endsWith("/persone-che-mi-seguono") -> "followers"; else -> null }
                    if (native != null) TextButton(onClick = { navigate(native) }) { Text(stringResource(R.string.community_open)) }
                    else if (url.isNotBlank()) TextButton(onClick = { onDestination(url) }) { Text(stringResource(R.string.community_open)) }
                    HorizontalDivider(color = Rule)
                }
            }
        }
        val hasMore = envelope.obj("meta").flag("has_more") || data.flag("has_more")
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            if(page > 1 && route != "inbox") OutlinedButton(onClick = { page-- }) { Text(stringResource(R.string.community_previous)) }
            if(route == "inbox" && cursors.size > 1) OutlinedButton(onClick = { cursors = cursors.dropLast(1) }) { Text(stringResource(R.string.community_previous)) }
            if(hasMore) OutlinedButton(onClick = {
                if(route == "inbox") envelope.obj("meta").text("next_cursor").takeIf { it.isNotBlank() }?.let { cursors = cursors + it } else page++
            }) { Text(stringResource(R.string.community_next)) }
        }
    }
    report?.let { target ->
        fun send(reason: String) = mutate { api.change("reports", buildJsonObject { put("type", target.first); put("id", target.second); put("reason", reason); put("note", reportNote) }); successMessage = reportedMessage; report = null; reportNote = "" }
        fun close() { report = null; reportNote = ""; error = null }
        AlertDialog(onDismissRequest = { close() }, title = { Text(stringResource(R.string.community_report)) }, text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                OutlinedTextField(reportNote, { reportNote = it.take(1000) }, label = { Text(stringResource(R.string.community_report_note)) })
            }
        }, confirmButton = {
            Row {
                TextButton(enabled = !busy, onClick = { send("offensive") }) { Text(stringResource(R.string.community_report_offensive)) }
                TextButton(enabled = !busy, onClick = { send("spam") }) { Text(stringResource(R.string.community_report_spam)) }
            }
        }, dismissButton = { TextButton(onClick = { close() }) { Text(stringResource(R.string.community_cancel)) } })
    }
    deleteComment?.let { id -> AlertDialog(onDismissRequest = { deleteComment = null }, title = { Text(stringResource(R.string.community_delete_comment)) }, text = { Text(stringResource(R.string.community_delete_comment_help)) }, confirmButton = {
        TextButton(enabled = !busy, onClick = { deleteComment = null; mutate { api.change("comments/$id", method = "DELETE"); successMessage = commentDeletedMessage } }) { Text(stringResource(R.string.community_delete_comment)) }
    }, dismissButton = { TextButton(onClick = { deleteComment = null }) { Text(stringResource(R.string.community_cancel)) } }) }
    blockTarget?.let { (id, thenFollowers) -> AlertDialog(onDismissRequest = { blockTarget = null }, title = { Text(stringResource(R.string.community_block)) }, text = { Text(stringResource(R.string.community_block_help)) }, confirmButton = {
        TextButton(enabled = !busy, onClick = { blockTarget = null; mutate { api.change("people/$id/block"); successMessage = blockedMessage; if (thenFollowers) navigate("followers") } }) { Text(stringResource(R.string.community_block)) }
    }, dismissButton = { TextButton(onClick = { blockTarget = null }) { Text(stringResource(R.string.community_cancel)) } }) }
}

@Composable
private fun CommunityPerson(person: JsonObject, api: CommunityApi, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        // Same convention as RichImage: photos are desaturated in the dark theme, in colour in the light one.
        val matrix = remember { ColorMatrix().apply { setToSaturation(0f) } }
        person.text("avatar_url").takeIf { it.isNotBlank() }?.let { AsyncImage(api.avatar(LocalContext.current, it), null, Modifier.size(64.dp), colorFilter = if (isLightTheme) null else ColorFilter.colorMatrix(matrix)) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(person.text("display_name"), style = MaterialTheme.typography.titleLarge)
            Text(stringResource(R.string.community_verified), color = Acid, style = MaterialTheme.typography.labelSmall)
            person.text("bio").takeIf { it.isNotBlank() }?.let { Text(it, color = Muted) }
        }
    }
}

@Composable
private fun CommunityPostCard(post: JsonObject, api: CommunityApi, saved: Set<Long>, onOpen: (Occurrence) -> Unit, onSave: (Long) -> Unit,
    onPerson: (String) -> Unit, onPost: (Long) -> Unit, onPrivacy: (Long) -> Unit) {
    HorizontalDivider(color = Rule)
    val author = post.obj("author")
    CommunityPerson(author, api) { onPerson(author.text("handle")) }
    Text(stringResource(if(post.text("intent") == "attend") R.string.community_attend else R.string.community_recommend), color = Acid)
    post.text("body").takeIf { it.isNotBlank() }?.let { Text(it) }
    val occurrence = remember(post) { post.occurrenceOrNull(api.json) }
    occurrence?.let { EventRow(it, it.occurrenceId in saved, onOpen, onSave) }
    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        TextButton(onClick = { onPost(post.number("id")) }) { Text(stringResource(R.string.community_comments)) }
        if(post.flag("is_own") && occurrence != null) TextButton(onClick = { onPrivacy(occurrence.occurrenceId) }) { Text(stringResource(R.string.community_save_privacy)) }
    }
}

@Composable
internal fun CommunityCheck(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).clickable { onChange(!checked) }, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Checkbox(checked, onCheckedChange = null); Text(label, Modifier.padding(top = 12.dp))
    }
}
