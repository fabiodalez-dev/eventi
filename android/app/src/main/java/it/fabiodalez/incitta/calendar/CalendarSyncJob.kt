package it.fabiodalez.incitta.calendar

import android.app.job.JobParameters
import android.app.job.JobService
import it.fabiodalez.incitta.data.ApiException
import it.fabiodalez.incitta.data.LocalStore
import kotlinx.coroutines.*

class CalendarSyncJob : JobService() {
    private var work: Job? = null
    override fun onStartJob(params: JobParameters): Boolean {
        work = CoroutineScope(Dispatchers.IO).launch {
            var retry = false
            try {
                if (LocalStore(this@CalendarSyncJob).readSession() == null) NativeCalendar.disconnect(this@CalendarSyncJob)
                else if (NativeCalendar.allowed(this@CalendarSyncJob) && NativeCalendar.enabled(this@CalendarSyncJob)) NativeCalendar.refresh(this@CalendarSyncJob)
            } catch (e: CancellationException) { throw e }
            catch (e: ApiException) {
                retry = e.status != 401
            } catch (_: Exception) { retry = true }
            jobFinished(params, retry)
        }
        return true
    }
    override fun onStopJob(params: JobParameters): Boolean { work?.cancel(); return true }
}
