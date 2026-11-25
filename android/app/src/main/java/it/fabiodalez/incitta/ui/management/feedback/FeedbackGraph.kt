package it.fabiodalez.incitta.ui.management.feedback

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import it.fabiodalez.incitta.data.management.ManagementDestination
import it.fabiodalez.incitta.ui.management.ManagementGraphContext
import it.fabiodalez.incitta.ui.management.managementViewModel
import kotlinx.serialization.Serializable

/*
 * P7 — Commenti e recensioni: moderazione e risposte (W-CM-01…04, W-RV-01…03).
 *
 * Le rotte portano il soggetto: per chiave (`venue:12`) dal menu, per tipo e
 * slug da un collegamento del sito (`/gestione/{slug}/event-comments`). `event`
 * restringe ai commenti di un evento (0 = tutti). Tutto finisce nello stato
 * salvato della voce, così dopo la chiusura dell'app la schermata rinasce
 * sullo stesso soggetto.
 */
@Serializable internal data class FeedbackRoute(val subject: String = "")
@Serializable internal data class CommentsModerationRoute(val subject: String = "", val kind: String = "", val slug: String = "", val event: Long = 0)
@Serializable internal data class ReceivedReviewsRoute(val subject: String = "", val kind: String = "", val slug: String = "")

/** La schermata nativa di un collegamento del sito a una delle due pagine. */
internal fun feedbackRouteFor(destination: ManagementDestination.Feedback): Any {
    val kind = destination.kind.name.lowercase()
    return if (destination.reviews) ReceivedReviewsRoute(kind = kind, slug = destination.slug)
    else CommentsModerationRoute(kind = kind, slug = destination.slug, event = destination.eventId ?: 0)
}

internal fun NavGraphBuilder.feedbackGraph(context: ManagementGraphContext) {
    composable<FeedbackRoute> {
        val viewModel = managementViewModel { FeedbackHubViewModel(context.repository, it) }
        FeedbackHubScreen(viewModel, context,
            onComments = { key -> context.navController.navigate(CommentsModerationRoute(subject = key)) },
            onReviews = { key -> context.navController.navigate(ReceivedReviewsRoute(subject = key)) })
    }
    composable<CommentsModerationRoute> {
        val viewModel = managementViewModel { CommentsModerationViewModel(context.repository, it) }
        CommentsModerationScreen(viewModel, context)
    }
    composable<ReceivedReviewsRoute> {
        val viewModel = managementViewModel { ReceivedReviewsViewModel(context.repository, it) }
        ReceivedReviewsScreen(viewModel, context)
    }
}
