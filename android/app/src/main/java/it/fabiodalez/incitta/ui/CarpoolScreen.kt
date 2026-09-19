package it.fabiodalez.incitta.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*

@Composable
internal fun CarpoolScreen(session: Session?, padding: PaddingValues, initialRoute: String, onBack: () -> Unit, onLogin: (String) -> Unit, onVerify: (String) -> Unit, onDestination: (String) -> Unit) {
    val context=LocalContext.current
    val store=remember {LocalStore(context)}
    val api=remember(session?.token) {session?.token?.let {CarpoolApi(ApiClient(store.installationId),it,store)}}
    var route by remember(session?.token,initialRoute) {mutableStateOf(initialRoute)}
    val history=remember(session?.token,initialRoute) {mutableStateListOf<String>()}
    fun navigate(path: String) {if(route!=path) {history.add(route);route=path}}
    fun back() {if(history.isEmpty()) onBack() else route=history.removeAt(history.lastIndex)}
    BackHandler {back()}
    var data by remember(route,session?.token) {mutableStateOf<JsonObject?>(null)}
    var busy by remember {mutableStateOf(false)}
    var error by remember(route) {mutableStateOf<String?>(null)}
    var refresh by remember {mutableIntStateOf(0)}
    val scope=rememberCoroutineScope()
    val currentRoute by rememberUpdatedState(route)
    val identity=route
    val uri=LocalUriHandler.current
    val parts=route.substringBefore('?').split('/')
    val endpoint=if(parts.first()=="create") "occurrences/${parts[1]}/offer" else if(parts.first()=="edit") "offers/${parts[1]}" else route
    suspend fun load() {
        if(api==null) return
        val response=if(route.startsWith("inbox")) api.notifications(route.substringAfter("cursor=","").takeIf {it.isNotBlank()}) else api.get(endpoint)
        if(currentRoute==identity) data=if(route.startsWith("inbox")) response else response.obj("data")
    }
    LaunchedEffect(route,session?.token,refresh) {
        if(parts.first()=="chats" && parts.size==2) return@LaunchedEffect
        data=null;error=null;busy=true
        try {load()} catch(e: CancellationException) {throw e} catch(e: Exception) {error=requestFailureMessage(e)} finally {busy=false}
    }
    fun mutate(path: String,body: JsonObject=cpData(), stay: Boolean=false) {
        if(busy || api==null) return
        scope.launch {
            busy=true;error=null
            try {
                val result=api.change(path,body).obj("data");CommunityUpdates.changed()
                if(currentRoute!=identity) return@launch
                val destination=when(result.text("entity")) {"offer"->"offers/${result.number("id")}";"request"->"requests/${result.number("id")}";"case"->"cases/${result.number("id")}";else->null}
                if(!stay && destination!=null && destination!=route) navigate(destination) else load()
            } catch(e: CancellationException) {throw e} catch(e: Exception) {error=requestFailureMessage(e)} finally {busy=false}
        }
    }
    Column(Modifier.fillMaxSize().padding(padding)) {
        Row(Modifier.fillMaxWidth().padding(start=12.dp,end=12.dp,top=16.dp),horizontalArrangement=Arrangement.SpaceBetween,verticalAlignment=androidx.compose.ui.Alignment.CenterVertically) {
            CpButton("back") {back()}; Text(cpText("title"),style=MaterialTheme.typography.titleLarge)
        }
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal=12.dp),horizontalArrangement=Arrangement.spacedBy(8.dp)) {
            listOf("mine" to "me","messages" to "chats","notice_summary" to "inbox","support" to "cases","requirements" to "requirements").forEach { (key,path) ->
                TextButton(onClick={navigate(path)}) {Text(if(key=="notice_summary") androidx.compose.ui.res.stringResource(it.fabiodalez.incitta.R.string.community_inbox) else cpText(key))}
            }
        }
        if(session==null) {
            Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(16.dp)) {Text(cpText("free"));Text(cpText("onboarding"));CpButton("login"){onLogin(route)}}
        } else if(parts.first()=="chats" && parts.size==2 && api!=null) {
            CpChat(api,parts[1].toLong(),onRequest={navigate("requests/$it")},onChanged={CommunityUpdates.changed()})
        } else Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),verticalArrangement=Arrangement.spacedBy(16.dp)) {
            if(busy) LinearProgressIndicator(Modifier.fillMaxWidth())
            error?.let {Text(it,color=MaterialTheme.colorScheme.error);CpButton("retry",!busy) {refresh++}}
            data?.let {row ->
                val access=row.obj("access")
                if((parts.first() in listOf("occurrences","create","edit") || route=="requirements") && !access.flag("eligible")) {
                    CpGate(access,busy,{onVerify(route)},{onLogin(route)},onTerms={navigate("terms")},onEnable={mutate("actions/declare",it,true)},onSupport={navigate("cases")})
                } else when(parts.first()) {
                    "terms" -> {Text(cpText("terms"),style=MaterialTheme.typography.headlineMedium);Text(row.text("terms"))}
                    "requirements" -> {
                        Text(cpText("verified"));Text(cpText("adult_status"));CpButton("terms") {navigate("terms")}
                        CpCheck("push",access.flag("push_enabled")) {mutate("actions/preferences",cpData("push_enabled" to it),true)}
                        Text(cpText("adult_revoke_help"));var confirm by remember {mutableStateOf(false)}
                        CpCheck("adult_revoke",confirm) {confirm=it};CpButton("adult_revoke",confirm && !busy) {mutate("actions/revoke-adult",cpData("confirm" to true),true)}
                    }
                    "create","edit" -> {Text(row.text("event_title",row.obj("offer").text("event_title")),style=MaterialTheme.typography.headlineMedium);Text(row.text("event_date"));CpOfferForm(row,if(parts.first()=="edit")row.obj("offer") else null,busy) {mutate("actions/"+if(parts.first()=="edit")"update" else "offer",it)}}
                    "occurrences" -> {
                        Text(row.text("event_title"),style=MaterialTheme.typography.headlineMedium);Text(row.text("event_date"));CpButton("offer") {navigate("create/${row.number("occurrence_id")}")}
                        var filter by remember {mutableStateOf(false)};CpButton("filter") {filter=!filter}
                        if(filter) CpFilters {query->navigate("occurrences/${row.number("occurrence_id")}?$query")}
                        if(row.rows("offers").isEmpty()) Text(cpText("empty_offers"))
                        row.rows("offers").forEach {offer -> CpOffer(offer,{navigate("offers/${offer.number("id")}")},{navigate("drivers/${offer.obj("driver").number("id")}/reviews")})}
                        CpPaging(row,route,::navigate)
                        if(row.rows("wanted").isNotEmpty()) {
                            Text(cpText("wanted"),style=MaterialTheme.typography.titleLarge)
                            row.rows("wanted").forEach {wanted ->
                                Text("${wanted.text("name")} · ${wanted.text("zone")} · ${wanted.text("leg_label")}")
                                Text(cpText("requested_seats","count" to wanted.number("seats")))
                                row.rows("my_offers").forEach {offer -> OutlinedButton(onClick={mutate("discovery/suggest",cpData("search_id" to wanted.number("id"),"offer_id" to offer.number("id")),true)},enabled=!busy) {Text(cpText("suggest")+" · "+offer.text("departure_label"))} }
                            }
                            CpPaging(row,route,::navigate,"wanted_page","wanted_has_more")
                        }
                        HorizontalDivider();CpSearch(row,busy) {mutate("discovery/search",it)}
                    }
                    "offers" -> {
                        val offer=row.obj("offer")
                        CpOffer(offer,null,{navigate("drivers/${offer.obj("driver").number("id")}/reviews")})
                        CpButton("event") {offer.text("event_url").takeIf{it.isNotBlank()}?.let(onDestination)}
                        CpButton("map") {offer.text("map_url").takeIf{it.startsWith("https://www.google.com/maps/search/?") }?.let(uri::openUri)}
                        offer.text("note").takeIf{it.isNotBlank()}?.let {Text(it)}
                        Text(offer.text("accessibility_label"));Text(offer.text("accessibility_note"));Text(cpText("accessibility_disclaimer"),style=MaterialTheme.typography.bodySmall)
                        if(offer.flag("is_own")) {
                            if(offer.text("status") in listOf("draft","open","closed")) {
                                CpButton("update",!busy) {navigate("edit/${offer.number("id")}")}
                                val action=when(offer.text("status")) {"draft"->"publish";"open"->"close";else->"reopen"}
                                var declaration by remember {mutableStateOf(false)}
                                if(action=="publish") CpCheck("driver_declaration",declaration) {declaration=it}
                                CpButton(action,!busy && (action!="publish" || declaration)) {mutate("actions/$action",cpData("offer_id" to offer.number("id"),"driver_declaration" to declaration),true)}
                                var cancel by remember {mutableStateOf(false)}
                                CpButton("cancel",!busy) {cancel=true}
                                if(cancel) AlertDialog(onDismissRequest={cancel=false},text={Text(cpText("confirm_cancel"))}, confirmButton={CpButton("cancel",!busy){cancel=false;mutate("actions/cancel",cpData("offer_id" to offer.number("id")),true)}},dismissButton={CpButton("back"){cancel=false}})
                            }
                            var templateName by remember {mutableStateOf("")};CpField("template_name",templateName,80,{templateName=it});CpButton("template",templateName.isNotBlank() && !busy) {mutate("discovery/template",cpData("offer_id" to offer.number("id"),"name" to templateName),true)}
                        } else if(offer.flag("can_request") && row.rows("requests").none {it.text("status") in listOf("pending","accepted")}) {
                            CpRequestForm(offer,busy) {mutate("actions/request",it)}
                        }
                        row.rows("requests").forEach {ride -> CpRide(ride,busy,::navigate) {action,payload->mutate(action,payload,true)}}
                        CpPaging(row,route,::navigate)
                        CpReport(cpData("offer_id" to offer.number("id")),busy) {mutate("reports",it)}
                    }
                    "requests" -> {
                        val offer=row.obj("offer");val ride=row.obj("ride")
                        CpOffer(offer,{navigate("offers/${offer.number("id")}")},{navigate("drivers/${offer.obj("driver").number("id")}/reviews")})
                        CpRide(ride,busy,::navigate) {action,payload->mutate(action,payload,true)}
                        if(ride.flag("can_review")) CpRideReview(ride,busy) {action,payload->mutate("requests/${ride.number("id")}/review/$action",payload,true)}
                        if(ride.flag("can_feedback")) CpFeedback(ride,busy) {mutate("discovery/feedback",it,true)}
                        CpReport(cpData("request_id" to ride.number("id")),busy) {mutate("reports",it)}
                    }
                    "me" -> {
                        Text(cpText("mine"),style=MaterialTheme.typography.headlineMedium)
                        if(row.rows("offers").isEmpty() && row.rows("requests").isEmpty()) Text(cpText("empty_mine"))
                        Text(cpText("offered"),style=MaterialTheme.typography.titleLarge)
                        row.rows("offers").forEach {offer->CpOffer(offer,{navigate("offers/${offer.number("id")}")},{navigate("drivers/${offer.obj("driver").number("id")}/reviews")})};CpPaging(row,route,::navigate)
                        Text(cpText("requested"),style=MaterialTheme.typography.titleLarge)
                        row.rows("requests").forEach {ride->CpOffer(ride.obj("offer"),{navigate("requests/${ride.number("id")}")},null);Text(ride.text("status_label"))};CpPaging(row,route,::navigate,"requests_page","requests_has_more")
                        Text(cpText("searches"),style=MaterialTheme.typography.titleLarge)
                        row.rows("searches").forEach {search->Text(search.text("event_title")+" · "+search.text("zone"));Text(search.text("leg_label"));CpButton(if(search.flag("active"))"search_stop" else "search_start",!busy){mutate("discovery/toggle-search",cpData("search_id" to search.number("id"),"active" to !search.flag("active")),true)}}
                        CpPaging(row,route,::navigate,"searches_page","searches_has_more")
                        if(row.rows("templates").isNotEmpty()) Text(cpText("templates"),style=MaterialTheme.typography.titleLarge)
                        row.rows("templates").forEach {template->Text(template.text("name"));CpButton("delete",!busy){mutate("discovery/delete-template",cpData("template_id" to template.number("id")),true)}}
                    }
                    "chats" -> {
                        Text(cpText("messages"),style=MaterialTheme.typography.headlineMedium)
                        CpButton(if(row.flag("archived"))"active_chats" else "archived_chats") {navigate("chats?archived="+if(row.flag("archived"))"0" else "1")}
                        if(row.rows("chats").isEmpty()) Text(cpText("empty_messages"))
                        row.rows("chats").forEach {chat-> Text(chat.obj("offer").text("event_title"));Text(chat.obj("offer").text("zone")+" · "+chat.obj("ride").obj("requester").text("name"));if(chat.number("unread")>0)Text(cpText("notice_summary","count" to chat.number("unread")));CpButton("open_chat",chat.flag("readable")){navigate("chats/${chat.number("id")}")};HorizontalDivider()};CpPaging(row,route,::navigate)
                    }
                    "cases" -> {
                        if(parts.size==1) {Text(cpText("cases"),style=MaterialTheme.typography.headlineMedium);if(row.rows("cases").isEmpty()) Text(cpText("empty_cases"));row.rows("cases").forEach {case->OutlinedButton(onClick={navigate("cases/${case.number("id")}")}) {Text(cpText("case","id" to case.number("id"))+" · "+cpText("CarpoolCaseStatus.${case.text("status")}"))}};CpPaging(row,route,::navigate)}
                        else {Text(cpText("case","id" to row.number("case_id")),style=MaterialTheme.typography.headlineMedium);Text(row.text("case_status"));Text(row.text("body"));row.rows("messages").forEach {Text(it.text("body"));Text(it.text("created_at"),style=MaterialTheme.typography.labelSmall);HorizontalDivider()};if(row.rows("messages").size==50) CpButton("older"){navigate("cases/${row.number("case_id")}?before=${row.rows("messages").first().number("id")}")};var reply by remember {mutableStateOf("")};CpField("reply",reply,2000,{reply=it},true);CpButton("send",!busy && reply.trim().length>=10){mutate("cases/${row.number("case_id")}",cpData("body" to reply),true);reply=""}}
                    }
                    "drivers" -> {Text(row.text("driver_name"),style=MaterialTheme.typography.headlineMedium);Text(cpText("reviews.period"));val summary=row.obj("summary");if(summary.number("count")>0)Text(cpText("reviews.summary","average" to summary.text("average"),"count" to summary.number("count"))) else Text(cpText("reviews.none"));row.rows("reviews").forEach {review->Text(review.text("name"),style=MaterialTheme.typography.titleMedium);Text("★ "+review.text("rating")+" / 5");Text(review.text("body"));CpReport(cpData("review_id" to review.number("id")),busy){mutate("reports",it)};HorizontalDivider()};CpPaging(row,route,::navigate)}
                    "inbox" -> {
                        val meta=row.obj("meta");CpButton("read_all",!busy){scope.launch {try{api?.readNotifications(meta.number("watermark"));CommunityUpdates.changed();load()}catch(e:Exception){if(e is CancellationException)throw e;error=requestFailureMessage(e)}}}
                        row.rows("data").forEach {notice->val payload=notice.obj("data");Text(payload.text("title"),style=MaterialTheme.typography.titleMedium);Text(payload.text("body"));Text(notice.text("created_at"),style=MaterialTheme.typography.labelSmall)
                            OutlinedButton(onClick={scope.launch{try{api?.readNotification(notice.text("id"));CommunityUpdates.changed();onDestination(payload.text("url"))}catch(e:Exception){if(e is CancellationException)throw e;error=requestFailureMessage(e)}}}){Text(cpText("notifications.open"))};HorizontalDivider()}
                        meta.text("next_cursor").takeIf{it.isNotBlank()}?.let {cursor->CpButton("older"){navigate("inbox?cursor=$cursor")}}
                    }
                }
            }
        }
    }
}

@Composable
internal fun CpGate(access: JsonObject, busy: Boolean, onVerify: () -> Unit, onLogin: () -> Unit, onTerms: () -> Unit, onEnable: (JsonObject) -> Unit, onSupport: () -> Unit) {
    Text(cpText("requirements"),style=MaterialTheme.typography.headlineMedium);Text(cpText("free"));Text(cpText("onboarding"))
    when(access.text("reason")) {
        "login"->CpButton("login",action=onLogin)
        "email","whatsapp"->CpButton(if(access.text("reason")=="email")"email" else "whatsapp",action=onVerify)
        "suspended"->{Text(cpText("suspended"));CpButton("support",action=onSupport)}
        else->{var adult by remember{mutableStateOf(false)};var terms by remember{mutableStateOf(false)};CpCheck("adult",adult){adult=it};CpCheck("terms_accept",terms){terms=it};CpButton("terms",action=onTerms);CpPrimaryButton("enable",adult&&terms&&!busy){onEnable(cpData("adult" to adult,"terms" to terms,"version" to access.text("terms_version")))}}
    }
}

@Composable
internal fun CpOffer(offer: JsonObject, onOpen: (() -> Unit)?, onReviews: (() -> Unit)?) {
    HorizontalDivider();Text(offer.text("event_title"),style=MaterialTheme.typography.titleLarge);Text(offer.text("zone")+" · "+offer.text("leg_label"));Text(offer.text("departure_label"));Text(offer.obj("driver").text("name"),style=MaterialTheme.typography.titleMedium)
    if(offer.obj("driver").flag("verified")) Text(cpText("verified"),style=MaterialTheme.typography.labelMedium)
    Text(cpText("available","count" to offer.number("available"))+" · "+offer.text("status_label"))
    if(onReviews!=null && offer["reviews"] is JsonObject) {val summary=offer.obj("reviews");TextButton(onClick=onReviews){Text(if(summary.number("count")==0L)cpText("reviews.title") else cpText("reviews.summary","average" to summary.text("average"),"count" to summary.number("count")))}}
    if(onOpen!=null) CpButton("open_details",action=onOpen)
}

@Composable
internal fun CpPaging(data: JsonObject,route: String, navigate: (String)->Unit, pageKey: String="page", moreKey: String="has_more") {
    val page=data.number(pageKey).coerceAtLeast(1)
    fun target(p: Long): String {val query=route.substringAfter('?',"").split('&').filter {it.isNotBlank() && !it.startsWith("$pageKey=")};return route.substringBefore('?')+"?"+(query+"$pageKey=$p").joinToString("&")}
    Row(horizontalArrangement=Arrangement.spacedBy(12.dp)) {if(page>1)CpButton("back"){navigate(target(page-1))};if(data.flag(moreKey))CpButton("older"){navigate(target(page+1))}}
}
