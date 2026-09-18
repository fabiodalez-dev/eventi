package it.fabiodalez.incitta.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import it.fabiodalez.incitta.data.*
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.serialization.json.JsonObject

@Composable
internal fun CpChat(api:CarpoolApi,id:Long,onRequest:(Long)->Unit,onChanged:()->Unit) {
    var data by remember(api,id){mutableStateOf<JsonObject?>(null)}
    var messages by remember(api,id){mutableStateOf<List<JsonObject>>(emptyList())}
    var text by remember(api,id){mutableStateOf("")}
    var error by remember(api,id){mutableStateOf<String?>(null)}
    var busy by remember{mutableStateOf(false)}
    var previousAvailable by remember(api,id){mutableStateOf(true)}
    var initial by remember(api,id){mutableStateOf(true)}
    var pollRevision by remember(api,id){mutableIntStateOf(0)}
    var read by remember(api,id){mutableLongStateOf(0)}
    var reporting by remember(api,id){mutableStateOf<Long?>(null)}
    val lifecycle=LocalLifecycleOwner.current.lifecycle
    val list=rememberLazyListState()
    val scope=rememberCoroutineScope()
    suspend fun receive(before:Long?=null) {
        val atEnd= !list.canScrollForward
        val response=api.get("chats/$id?"+(if(before!=null)"before=$before" else "after=${messages.lastOrNull()?.number("id") ?: 0}")).obj("data")
        data=response;pollRevision++
        val rows=response.rows("messages")
        if(before!=null || initial) previousAvailable=rows.size==50
        messages=(messages+rows).distinctBy{it.number("id")}.sortedBy{it.number("id")}
        if(before==null && (initial || atEnd) && messages.isNotEmpty()) {
            initial=false
            // A dedicated older-page item precedes messages.
            list.scrollToItem(messages.size)
        }
    }
    LaunchedEffect(api,id,lifecycle) {
        lifecycle.repeatOnLifecycle(androidx.lifecycle.Lifecycle.State.RESUMED) {
            var backoff=5_000L
            while(isActive) {
                try {receive();error=null;backoff=5_000L}
                catch(e:CancellationException){throw e}
                catch(e:Exception){error=requestFailureMessage(e);backoff=(backoff*2).coerceAtMost(60_000);if(e is ApiException && e.status in listOf(401,403,404)){data=null;messages=emptyList()}}
                delay(backoff)
            }
        }
    }
    LaunchedEffect(api,id,lifecycle) {
        lifecycle.repeatOnLifecycle(androidx.lifecycle.Lifecycle.State.RESUMED) {
            snapshotFlow { (if(!list.canScrollForward && list.layoutInfo.visibleItemsInfo.any{it.key==messages.lastOrNull()?.number("id")}) messages.lastOrNull()?.number("id") ?: 0 else 0) to pollRevision }
                .distinctUntilChanged().collect { (last, _) ->
                    if(last>read) try {api.preferences(id,cpData("read_through_id" to last));read=last;onChanged()}
                    catch(e:CancellationException){throw e}catch(_:Exception){ /* A later visible poll retries. */ }
                }
        }
    }
    fun run(action:suspend ()->Unit) {if(busy)return;scope.launch{busy=true;error=null;try{action();onChanged()}catch(e:CancellationException){throw e}catch(e:Exception){error=requestFailureMessage(e)}finally{busy=false}}}
    Column(Modifier.fillMaxSize().padding(horizontal=16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)) {
        data?.let {row ->
            Text(row.obj("offer").text("event_title"),style=MaterialTheme.typography.titleMedium)
            Text(row.obj("offer").text("zone")+" · "+row.obj("offer").text("departure_label"),style=MaterialTheme.typography.bodySmall)
            CpButton("open_details"){onRequest(row.obj("ride").number("id"))}
            var preferences by remember{mutableStateOf(false)}
            TextButton(onClick={preferences=!preferences}){Text(cpText("chat_settings"))}
            if(preferences){CpCheck("muted",row.flag("muted")){run{api.preferences(id,cpData("muted" to it));receive()}};CpCheck("archive_chat",row.flag("archived")){run{api.preferences(id,cpData("archived" to it));receive()}}}
        }
        Text(cpText("chat_context"),style=MaterialTheme.typography.bodySmall)
        error?.let{Text(it,color=MaterialTheme.colorScheme.error);CpButton("retry",!busy){run{receive()}}}
        LazyColumn(Modifier.weight(1f).fillMaxWidth(),state=list,verticalArrangement=Arrangement.spacedBy(12.dp),contentPadding=PaddingValues(vertical=12.dp)) {
            item(key="older") {if(previousAvailable && messages.isNotEmpty()) CpButton("older",!busy){run{receive(messages.first().number("id"))}}}
            items(messages,key={it.number("id")}) {message ->
                Column(Modifier.fillMaxWidth(),horizontalAlignment=if(message.flag("mine"))androidx.compose.ui.Alignment.End else androidx.compose.ui.Alignment.Start) {
                    Column(Modifier.fillMaxWidth(.87f).background(MaterialTheme.colorScheme.surfaceVariant,ControlShape).padding(12.dp)) {
                        Text(message.text("body"));Text(message.text("created_at"),style=MaterialTheme.typography.labelSmall)
                        TextButton(onClick={reporting=message.number("id")}){Text(cpText("report"))}
                    }
                }
            }
        }
        if(data?.flag("writable")==true) {
            OutlinedTextField(text,{if(it.length<=2000)text=it},label={Text(cpText("message"))},modifier=Modifier.fillMaxWidth(),maxLines=4,enabled=!busy)
            CpButton("send",text.isNotBlank()&&!busy){run{api.change("chats/$id/send",cpData("body" to text.trim()));text="";receive();if(messages.isNotEmpty())list.animateScrollToItem(messages.size)}}
        } else Text(cpText("chat_readonly"),modifier=Modifier.padding(vertical=12.dp))
    }
    reporting?.let {messageId ->var body by remember(messageId){mutableStateOf("")};AlertDialog(onDismissRequest={if(!busy)reporting=null},title={Text(cpText("report"))},text={Column{CpField("details",body,2000,{body=it},true);error?.let{Text(it,color=MaterialTheme.colorScheme.error)}}},
        confirmButton={CpButton("send",body.trim().length>=5&&!busy){run{api.change("reports",cpData("request_id" to data?.obj("ride")?.number("id"),"message_id" to messageId,"reason" to "harassment","body" to body.trim()));reporting=null}}},dismissButton={CpButton("back",!busy){reporting=null}})}
}
