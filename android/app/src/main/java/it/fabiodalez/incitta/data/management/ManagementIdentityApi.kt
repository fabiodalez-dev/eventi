package it.fabiodalez.incitta.data.management

import it.fabiodalez.incitta.data.ApiClient
import it.fabiodalez.incitta.data.ApiEnvelope
import kotlinx.serialization.json.JsonElement

/** Chi gestisce che cosa, gli elenchi del server e l'interruttore dell'area nuova. */
internal interface ManagementIdentitySource {
    /** `features.management` di `GET /config`: falso anche quando il campo manca. */
    suspend fun managementEnabled(): Boolean
    suspend fun subjects(): ManagementSubjects
    suspend fun options(): ManagementOptions
}

internal class ManagementIdentityApi(private val api: ApiClient, private val token: String) : ManagementIdentitySource {
    /* `config` è pubblico: niente bearer. */
    override suspend fun managementEnabled(): Boolean = managementFeatureEnabled(api.get<ApiEnvelope<JsonElement>>("config").data)
    override suspend fun subjects(): ManagementSubjects = api.get<ApiEnvelope<ManagementSubjects>>("management/subjects", token).data
    override suspend fun options(): ManagementOptions = ManagementOptions.from(api.get<ApiEnvelope<JsonElement>>("management/options", token).data)
}
