package it.fabiodalez.incitta.data

internal object CommunityUpdates {
    val revision = kotlinx.coroutines.flow.MutableStateFlow(0L)
    fun changed() { revision.value += 1 }
}
