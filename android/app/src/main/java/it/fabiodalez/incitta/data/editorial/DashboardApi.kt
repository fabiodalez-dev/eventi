package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope

/** Le chiamate del riepilogo della redazione (R1). */
internal interface DashboardSource {
    /** `GET editorial/dashboard?city=<slug>`: le sezioni del riepilogo per la città di lavoro. */
    suspend fun dashboard(city: String?): EditorialDashboard
}

internal class DashboardApi(private val api: ApiClient, private val token: String) : DashboardSource {
    override suspend fun dashboard(city: String?): EditorialDashboard =
        api.get<ApiEnvelope<EditorialDashboard>>(editorialPath("dashboard", city), token).data
}
