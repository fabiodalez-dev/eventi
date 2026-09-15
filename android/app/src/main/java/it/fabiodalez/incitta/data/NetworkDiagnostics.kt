package it.fabiodalez.incitta.data

import android.app.AlertDialog
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.widget.ScrollView
import android.widget.TextView
import it.fabiodalez.incitta.BuildConfig
import okhttp3.*
import java.io.IOException
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Proxy
import java.time.Instant
import java.util.concurrent.atomic.AtomicLong

/** Local, bounded, debug-only trace. Never collect headers, bodies or query values. */
internal object NetworkDiagnostics {
    private val sequence = AtomicLong()
    private val lines = ArrayDeque<String>()
    private val failures = ArrayDeque<String>()
    private var context: Context? = null

    @Synchronized fun initialize(context: Context) {
        if (!BuildConfig.DEBUG || this.context != null) return
        this.context = context.applicationContext
        val saved = context.getSharedPreferences("network_diagnostics", Context.MODE_PRIVATE).getString("log", "").orEmpty()
        lines.addAll(saved.lines().filter(String::isNotBlank).takeLast(180))
        failures.addAll(context.getSharedPreferences("network_diagnostics", Context.MODE_PRIVATE).getString("failures", "").orEmpty().lines().filter(String::isNotBlank).takeLast(12))
        record("START ${BuildConfig.VERSION_NAME} Android ${android.os.Build.VERSION.RELEASE}")
    }

    @Synchronized fun record(message: String) {
        if (!BuildConfig.DEBUG) return
        lines.addLast("${Instant.now()} $message")
        while (lines.size > 180) lines.removeFirst()
        if ("FAILED" in message || "APPLICATION ERROR" in message) {
            failures.addLast(lines.last())
            while (failures.size > 12) failures.removeFirst()
        }
        context?.getSharedPreferences("network_diagnostics", Context.MODE_PRIVATE)?.edit()?.putString("log", lines.joinToString("\n"))?.putString("failures", failures.joinToString("\n"))?.apply()
    }

    // Share the connection pool across screens and background calendar refreshes.
    // Authorization remains request-local: no cookies or account state in this client.
    private val sharedClient: OkHttpClient by lazy {
        OkHttpClient.Builder().apply {
            if (BuildConfig.DEBUG) eventListenerFactory { Trace() }
        }.build()
    }
    fun client(): OkHttpClient = sharedClient

    private fun network(): String = runCatching {
        val manager = context?.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        val active = manager?.activeNetwork
        val caps = manager?.getNetworkCapabilities(active)
        val transports = listOf(NetworkCapabilities.TRANSPORT_WIFI to "Wi-Fi", NetworkCapabilities.TRANSPORT_CELLULAR to "mobile", NetworkCapabilities.TRANSPORT_VPN to "VPN", NetworkCapabilities.TRANSPORT_ETHERNET to "Ethernet")
            .filter { caps?.hasTransport(it.first) == true }.joinToString("+") { it.second }
        "network=${transports.ifBlank { "none" }} validated=${caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)}"
    }.getOrDefault("network=unavailable")

    private class Trace : EventListener() {
        private val id = sequence.incrementAndGet()
        private val start = System.nanoTime()
        private var stage = "start"
        private fun event(value: String) { record("#$id +${(System.nanoTime() - start) / 1_000_000}ms $value") }
        override fun callStart(call: Call) {
            val url = call.request().url
            event("${call.request().method} ${url.scheme}://${url.host}:${url.port}${safePath(url.encodedPath)} ${network()}")
        }
        override fun dnsStart(call: Call, domainName: String) { stage = "DNS"; event("DNS start") }
        override fun dnsEnd(call: Call, domainName: String, inetAddressList: List<InetAddress>) { event("DNS OK serverAddresses=${inetAddressList.joinToString { it.hostAddress.orEmpty() }}") }
        override fun connectStart(call: Call, inetSocketAddress: InetSocketAddress, proxy: Proxy) { stage = "connect"; event("CONNECT server=${inetSocketAddress.address?.hostAddress}:${inetSocketAddress.port} proxy=${proxy.type()}") }
        override fun secureConnectStart(call: Call) { stage = "TLS"; event("TLS start") }
        override fun secureConnectEnd(call: Call, handshake: Handshake?) { event("TLS OK ${handshake?.tlsVersion}") }
        override fun connectionAcquired(call: Call, connection: Connection) { event("CONNECTION ${connection.protocol()}") }
        override fun responseHeadersStart(call: Call) { stage = "response headers" }
        override fun responseHeadersEnd(call: Call, response: Response) { event("HTTP ${response.code}${if (response.code >= 400) " FAILED" else ""}") }
        override fun responseBodyStart(call: Call) { stage = "response body" }
        override fun responseBodyEnd(call: Call, byteCount: Long) { event("BODY bytes=$byteCount") }
        override fun connectFailed(call: Call, inetSocketAddress: InetSocketAddress, proxy: Proxy, protocol: Protocol?, ioe: IOException) { event("CONNECT FAILED ${diagnosticCause(ioe)}") }
        override fun callFailed(call: Call, ioe: IOException) {
            event("FAILED stage=$stage ${diagnosticCause(ioe)}")
            ioe.stackTrace.take(4).forEach { event("  at $it") }
        }
        override fun callEnd(call: Call) { event("END") }
    }

    @Synchronized fun report(): String = "inCittà ${BuildConfig.VERSION_NAME}\n${network()}\nSolo diagnostica tecnica, senza token, password, contenuti o valori dei filtri.\n\nULTIMI ERRORI\n" + failures.joinToString("\n") + "\n\nRICHIESTE RECENTI\n" + lines.joinToString("\n")

    fun show(context: Context) {
        if (!BuildConfig.DEBUG) return
        val report = report()
        val text = TextView(context).apply { this.text = report; textSize = 12f; typeface = android.graphics.Typeface.MONOSPACE; setTextIsSelectable(true); setPadding(24, 16, 24, 16) }
        AlertDialog.Builder(context).setTitle("Diagnostica rete")
            .setView(ScrollView(context).apply { addView(text) })
            .setPositiveButton("Condividi") { _, _ -> context.startActivity(Intent.createChooser(Intent(Intent.ACTION_SEND).apply { type = "text/plain"; putExtra(Intent.EXTRA_TEXT, report) }, "Condividi diagnostica")) }
            .setNeutralButton("Copia") { _, _ -> (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("Diagnostica rete", report)) }
            .setNegativeButton("Chiudi", null).show()
    }
}

internal fun safePath(path: String): String = path.split('/').mapIndexed { index, segment ->
    if (index <= 3 || segment in setOf("facets", "reviews", "saved", "content-preferences", "occurrences", "calendar", "export", "google", "devices", "notification-preferences", "notification-interests")) segment else "…"
}.joinToString("/")

/** Exception types are precise; arbitrary messages can contain credentials or response bodies. */
internal fun diagnosticCause(error: Throwable): String {
    val seen = mutableSetOf<Throwable>()
    return generateSequence(error) { it.cause }.takeWhile { seen.add(it) }.take(8).joinToString(" -> ") {
        val message = if (it is IOException) sanitizeNetworkMessage(it.message.orEmpty()) else ""
        "${it.javaClass.name}${if (message.isBlank()) "" else ": $message"}"
    }
}

internal fun sanitizeNetworkMessage(message: String): String = Regex(
    "(?i)\\b(ECONNREFUSED|ECONNRESET|ETIMEDOUT|ENETUNREACH|EHOSTUNREACH|EPIPE|EACCES|EPERM|ENETDOWN|unexpected end of stream|connection reset|connection refused|socket closed|timeout|timed out|unable to resolve host|failed to connect|broken pipe|network is unreachable|trust anchor|certificate expired|certificate not yet valid|hostname not verified|stream was reset|CANCEL|REFUSED_STREAM|PROTOCOL_ERROR|INTERNAL_ERROR|NO_ERROR|HTTP_1_1_REQUIRED)\\b"
).findAll(message).map { it.value }.distinct().joinToString("; ")
