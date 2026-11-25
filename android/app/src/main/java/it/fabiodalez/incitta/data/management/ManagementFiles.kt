package it.fabiodalez.incitta.data.management

import java.io.File

/*
 * I file che la gestione scarica dal server (CSV dei partecipanti, lista
 * d'ingresso in PDF; decisione 2 del piano) stanno solo nella cache
 * dell'app, in una cartella sua, e passano alle altre app con
 * `ManagementFileProvider`: niente cartella «Download», niente permessi.
 * Contengono nomi e recapiti: si tolgono quando cambia o si chiude la
 * sessione e, a ogni nuovo scaricamento, quelli più vecchi di un'ora.
 */
internal object ManagementFiles {
    const val DIRECTORY = "management-exports"
    const val MAX_AGE_MILLIS = 60 * 60 * 1000L

    fun directory(cacheDir: File): File = File(cacheDir, DIRECTORY)

    /** Toglie i file più vecchi di `maxAgeMillis` (tutti con 0). Restituisce quanti ne ha tolti. */
    fun purge(cacheDir: File, maxAgeMillis: Long = MAX_AGE_MILLIS, now: Long = System.currentTimeMillis()): Int {
        val files = directory(cacheDir).listFiles() ?: return 0
        return files.count { file -> file.isFile && now - file.lastModified() >= maxAgeMillis && file.delete() }
    }
}

/**
 * Il fornitore dei file della gestione. È una classe sua, non il
 * `FileProvider` della libreria: un secondo fornitore con lo stesso nome nel
 * manifest andrebbe in conflitto.
 */
class ManagementFileProvider : androidx.core.content.FileProvider()
