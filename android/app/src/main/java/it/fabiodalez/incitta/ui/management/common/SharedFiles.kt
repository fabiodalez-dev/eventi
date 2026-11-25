package it.fabiodalez.incitta.ui.management.common

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File

/*
 * «Apri con» e «Condividi» per un file scaricato dalla gestione (decisione 2):
 * il file resta nella cache dell'app e l'altra app riceve solo il permesso
 * di leggerlo, a tempo. Lo usano la biglietteria (CSV e PDF) e chi verrà
 * dopo (statistiche, programma della settimana).
 */
internal object SharedFiles {
    fun uri(context: Context, file: File): Uri = FileProvider.getUriForFile(context, context.packageName + ".management.files", file)

    /** Apre il file con un'app scelta dalla persona; `false` se sul telefono non c'è nessuna app che lo apra. */
    fun open(context: Context, file: File, mime: String, chooserTitle: String): Boolean {
        val uri = uri(context, file)
        val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        return try { context.startActivity(Intent.createChooser(view, chooserTitle)); true } catch (_: ActivityNotFoundException) { false }
    }

    /** Il foglio di condivisione di Android con il file allegato. */
    fun share(context: Context, file: File, mime: String, chooserTitle: String): Boolean {
        val uri = uri(context, file)
        val send = Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri)
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION).apply { clipData = ClipData.newRawUri(file.name, uri) }
        return try { context.startActivity(Intent.createChooser(send, chooserTitle)); true } catch (_: ActivityNotFoundException) { false }
    }
}
