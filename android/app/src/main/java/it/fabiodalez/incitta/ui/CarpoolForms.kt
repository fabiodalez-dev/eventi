package it.fabiodalez.incitta.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import it.fabiodalez.incitta.data.*
import kotlinx.serialization.json.*
import java.net.URLEncoder

@Composable
internal fun CpRequestForm(offer: JsonObject,busy: Boolean,submit:(JsonObject)->Unit) {
    var seats by remember{mutableIntStateOf(1)};var adult by remember{mutableStateOf(false)};var note by remember{mutableStateOf("")};var stop by remember{mutableIntStateOf(-1)}
    Text(cpText("request_help"));CpSeats(seats,{seats=it},offer.number("available").toInt().coerceIn(1,8));Text(cpText("group_help"),style=MaterialTheme.typography.bodySmall)
    if(seats>1) CpCheck("companions",adult){adult=it}
    val stops=(offer["stops"] as? JsonArray)?.map {it.jsonPrimitive.content}.orEmpty()
    if(stops.isNotEmpty()) {
        Text(cpText("stop_select"));Row(verticalAlignment=androidx.compose.ui.Alignment.CenterVertically){RadioButton(stop==-1,{stop=-1});Text(cpText("origin"))}
        stops.forEachIndexed {i,label->Row(verticalAlignment=androidx.compose.ui.Alignment.CenterVertically){RadioButton(stop==i,{stop=i});Text(label)}}
    }
    CpField("note",note,500,{note=it},true)
    CpButton("request",!busy && (seats==1 || adult)){submit(cpData("offer_id" to offer.number("id"),"revision" to offer.number("revision"),"seats" to seats,"companions_adult" to adult,"note" to note,"stop_index" to stop.takeIf {it>=0}))}
}

@Composable
internal fun CpRide(ride: JsonObject,busy: Boolean,navigate:(String)->Unit,mutate:(String,JsonObject)->Unit) {
    HorizontalDivider();Text(ride.obj("requester").text("name"),style=MaterialTheme.typography.titleMedium);Text(cpText("requested_seats","count" to ride.number("seats")));Text(ride.text("status_label"));ride.text("note").takeIf{it.isNotBlank()}?.let{Text(it)}
    if(ride.flag("can_decide")) Row(horizontalArrangement=Arrangement.spacedBy(8.dp)) {listOf("accept","decline").forEach {action->CpButton(action,!busy){mutate("actions/$action",cpData("request_id" to ride.number("id")))}}}
    if(ride.flag("can_withdraw")) {
        var confirm by remember {mutableStateOf(false)}
        CpButton("withdraw",!busy){confirm=true}
        if(confirm) AlertDialog(onDismissRequest={confirm=false},text={Text(cpText("confirm_withdraw"))},confirmButton={CpButton("withdraw",!busy){confirm=false;mutate("actions/withdraw",cpData("request_id" to ride.number("id")))}},dismissButton={CpButton("back"){confirm=false}})
        if(ride.text("status")=="accepted" && ride.number("seats")>1) {var seats by remember(ride.number("seats")){mutableIntStateOf(ride.number("seats").toInt()-1)};CpSeats(seats,{seats=it},ride.number("seats").toInt()-1);CpButton("reduce",!busy){mutate("actions/reduce",cpData("request_id" to ride.number("id"),"seats" to seats))}}
    }
    if(ride.number("chat_id")>0) CpButton("open_chat"){navigate("chats/${ride.number("chat_id")}")}
    CpButton("open_details"){navigate("requests/${ride.number("id")}")}
}

@Composable
internal fun CpRideReview(ride: JsonObject,busy: Boolean,submit:(String,JsonObject)->Unit) {
    Text(cpText("reviews.title"),style=MaterialTheme.typography.titleLarge);Text(cpText("reviews.optional"))
    if(ride.text("passenger_confirmed_at").isBlank()) {var confirm by remember{mutableStateOf(false)};CpCheck("reviews.confirm_label",confirm){confirm=it};CpButton("reviews.confirm",confirm&&!busy){submit("confirm",cpData("confirm" to true))}}
    else {
        val review=ride.obj("review");var rating by remember(ride.number("review_revision")){mutableIntStateOf(review.number("rating").toInt())};var body by remember(ride.number("review_revision")){mutableStateOf(review.text("body"))}
        Text(cpText("reviews.confirmed"));Text(cpText("reviews.verified_only"),style=MaterialTheme.typography.bodySmall)
        Text(cpText("reviews.rating"));Row { (1..5).forEach {star -> TextButton(onClick={rating=star},modifier=Modifier.weight(1f).heightIn(min=48.dp),enabled=!busy){Text(if(star<=rating)"★" else "☆",style=MaterialTheme.typography.headlineSmall)} } }
        CpField("reviews.body",body,1000,{body=it},true)
        CpButton("reviews.save",rating in 1..5&&!busy){submit("save",cpData("rating" to rating,"body" to body,"revision" to ride.number("review_revision")))}
        if(review.number("id")>0) {var confirm by remember{mutableStateOf(false)};CpButton("reviews.remove",!busy){confirm=true};if(confirm)AlertDialog(onDismissRequest={confirm=false},text={Text(cpText("reviews.remove_confirm"))},confirmButton={CpButton("reviews.remove",!busy){confirm=false;submit("remove",cpData("revision" to ride.number("review_revision")))}},dismissButton={CpButton("back"){confirm=false}})}
    }
}

@Composable
internal fun CpReport(target:JsonObject,busy:Boolean,submit:(JsonObject)->Unit) {
    var open by remember{mutableStateOf(false)};CpButton("report"){open=!open}
    if(open) {var reason by remember{mutableStateOf("other")};var body by remember{mutableStateOf("")};CpChoice("reason",reason,listOf("money","safety","harassment","no_show","accessibility","other").map {it to "reasons.$it"}){reason=it};CpField("details",body,2000,{body=it},true);CpButton("send",body.trim().length>=10&&!busy){submit(buildJsonObject{target.forEach{(k,v)->put(k,v)};put("reason",reason);put("body",body.trim())})}}
}

@Composable
internal fun CpFeedback(ride:JsonObject,busy:Boolean,submit:(JsonObject)->Unit) {
    var open by remember{mutableStateOf(false)};CpButton("feedback"){open=!open}
    if(open) {Text(cpText("feedback_help"));var kind by remember{mutableStateOf("travelled")};var body by remember{mutableStateOf("")};CpChoice("feedback",kind,listOf("travelled","withdrew","no_show","problem").map{it to "RideFeedbackKind.$it"}){kind=it};CpField("note",body,1000,{body=it},true);CpButton("send",!busy){submit(cpData("request_id" to ride.number("id"),"kind" to kind,"body" to body))}}
}

@Composable
internal fun CpFilters(submit:(String)->Unit) {
    var zone by remember{mutableStateOf("")};var leg by remember{mutableStateOf("all")};var seats by remember{mutableIntStateOf(1)};var accessibility by remember{mutableStateOf("not_specified")};var sort by remember{mutableStateOf("departure")}
    CpField("zone",zone,120,{zone=it});CpChoice("seek",leg,listOf("all" to "all")+cpLegs){leg=it};CpSeats(seats,{seats=it});CpChoice("accessibility",accessibility,cpAccessibility){accessibility=it};CpChoice("sort",sort,listOf("departure" to "sort_departure","recent" to "sort_recent")){sort=it}
    CpButton("filter"){submit("zone=${URLEncoder.encode(zone,"UTF-8")}&seats=$seats&accessibility=$accessibility&sort=$sort"+if(leg=="all")"" else "&leg=$leg")}
}

@Composable
internal fun CpSearch(data:JsonObject,busy:Boolean,submit:(JsonObject)->Unit) {
    var open by remember{mutableStateOf(false)};CpButton("search_create"){open=!open}
    if(open) {var leg by remember{mutableStateOf("outbound")};var zone by remember{mutableStateOf("")};var seats by remember{mutableIntStateOf(1)};var access by remember{mutableStateOf("not_specified")};var earliest by remember{mutableStateOf(cpLocal(data.text("starts_at"),data.text("timezone")))};var latest by remember{mutableStateOf(cpLocal(data.text("ends_at"),data.text("timezone")))};var public by remember{mutableStateOf(false)};var alerts by remember{mutableStateOf(true)}
        CpChoice("seek",leg,cpLegs){leg=it};CpField("zone",zone,120,{zone=it});CpSeats(seats,{seats=it});CpChoice("accessibility",access,cpAccessibility){access=it};CpDateTime("earliest",earliest){earliest=it};CpDateTime("latest",latest){latest=it};CpCheck("search_public",public){public=it};CpCheck("search_create",alerts){alerts=it}
        CpButton("save",!busy && earliest.isNotBlank() && latest.isNotBlank()){submit(cpData("occurrence_id" to data.number("occurrence_id"),"leg" to leg,"zone" to zone,"seats" to seats,"accessibility" to access,"earliest_at" to earliest,"latest_at" to latest,"is_public" to public,"alerts_enabled" to alerts))}
    }
}
