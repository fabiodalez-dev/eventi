package it.fabiodalez.incitta.data.editorial

import it.fabiodalez.incitta.data.management.SocialBatchItem
import it.fabiodalez.incitta.data.management.SocialCard
import it.fabiodalez.incitta.data.management.SocialFormatInfo
import it.fabiodalez.incitta.data.management.SocialLabel
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/*
 * R9 — il Social della redazione. Le serate, il calendario, il giorno e i
 * pacchetti hanno la forma dello studio del locale (P10, `SocialCard`,
 * `SocialCalendar`, `SocialDay`, `SocialBatchSummary` di `data/management`),
 * perché il server le scrive con la stessa classe (`SocialStudioPayload`).
 * Qui ci sono solo le forme in più della redazione: la città, il pacchetto
 * con il testo automatico, lo storico delle pubblicazioni, le impostazioni.
 * Ogni campo ha un valore predefinito: un server più nuovo non rompe la
 * lettura.
 */

@Serializable
internal data class SocialCityRef(val id: Long = 0, val slug: String = "", val name: String = "")

/**
 * Un pacchetto della redazione: quello dello studio, più il testo che
 * uscirebbe pubblicandolo (una didascalia per parte del carosello, come
 * «Anteprima del testo automatico» della pagina) e se è già nello storico.
 */
@Serializable
internal data class EditorialSocialBatch(
    val id: String = "",
    val date: String = "",
    val format: SocialFormatInfo = SocialFormatInfo(),
    val count: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val timezone: String? = null,
    val caption: String = "",
    val items: List<SocialBatchItem> = emptyList(),
    val city: SocialCityRef? = null,
    val captions: List<String> = emptyList(),
    @SerialName("in_history") val inHistory: Boolean = false,
)

/** Una riga dello storico delle pubblicazioni (R-SO-03, R-SO-04). */
@Serializable
internal data class SocialPublicationRow(
    val id: Long = 0,
    @SerialName("created_at") val createdAt: String? = null,
    val platform: String = "",
    @SerialName("platform_label") val platformLabel: String = "",
    /* La parte del carosello, da 1. */
    val part: Int = 1,
    val status: SocialLabel = SocialLabel(),
    @SerialName("scheduled_at") val scheduledAt: String? = null,
    val timezone: String? = null,
    val error: String? = null,
    @SerialName("batch_id") val batchId: String? = null,
    /* Il pacchetto tolto dalla pulizia notturna: niente immagini, niente «Riprova». */
    @SerialName("batch_gone") val batchGone: Boolean = false,
    val abilities: JsonElement? = null,
)

@Serializable
internal data class SocialResultMeta(val message: String? = null, val warning: String? = null, val changed: Boolean? = null)

/** La risposta di una scrittura: il dato aggiornato e il messaggio da mostrare. */
@Serializable
internal data class SocialResult<T>(val data: T, val meta: SocialResultMeta = SocialResultMeta())

@Serializable
internal data class SocialTodayMeta(val date: String = "", val label: String = "", val timezone: String? = null, val city: SocialCityRef? = null)

/** «Social di oggi» (R-DB-10): le serate di oggi della città. */
@Serializable
internal data class SocialToday(val data: List<SocialCard> = emptyList(), val meta: SocialTodayMeta = SocialTodayMeta())

@Serializable
internal data class SocialGraphicOptions(
    val monochrome: Boolean = true,
    @SerialName("show_address") val showAddress: Boolean = true,
    @SerialName("show_price") val showPrice: Boolean = true,
    @SerialName("image_fit") val imageFit: String = "contain",
)

/** I campi del modulo delle impostazioni che l'app legge e scrive: nessun segreto (decisione 5). */
@Serializable
internal data class SocialSettingsValues(
    @SerialName("app_id") val appId: String? = null,
    @SerialName("city_id") val cityId: Long? = null,
    @SerialName("page_id") val pageId: String? = null,
    @SerialName("instagram_id") val instagramId: String? = null,
    @SerialName("graph_version") val graphVersion: String = "v25.0",
    @SerialName("facebook_enabled") val facebookEnabled: Boolean = false,
    @SerialName("instagram_enabled") val instagramEnabled: Boolean = false,
    @SerialName("telegram_chat_id") val telegramChatId: String? = null,
    @SerialName("telegram_enabled") val telegramEnabled: Boolean = false,
    val automatic: Boolean = false,
    @SerialName("graphic_options") val graphicOptions: SocialGraphicOptions = SocialGraphicOptions(),
    @SerialName("publish_time") val publishTime: String = "09:00",
    val caption: String = "",
)

/** Che cosa è salvato e verificato: dei segreti solo sì o no. */
@Serializable
internal data class SocialSettingsStatus(
    val exists: Boolean = false,
    @SerialName("app_secret_saved") val appSecretSaved: Boolean = false,
    @SerialName("access_token_saved") val accessTokenSaved: Boolean = false,
    @SerialName("telegram_bot_token_saved") val telegramBotTokenSaved: Boolean = false,
    @SerialName("meta_verified_at") val metaVerifiedAt: String? = null,
    @SerialName("telegram_verified_at") val telegramVerifiedAt: String? = null,
)

@Serializable
internal data class SocialSettingsCity(val id: Long = 0, val name: String = "")

@Serializable
internal data class SocialCaptionToken(val token: String = "", val label: String = "")

@Serializable
internal data class SocialSettingsLimits(@SerialName("caption_max") val captionMax: Int = 2200, @SerialName("telegram_caption_max") val telegramCaptionMax: Int = 1024)

/** `GET E/social/settings`. */
@Serializable
internal data class SocialSettings(
    val values: SocialSettingsValues = SocialSettingsValues(),
    val status: SocialSettingsStatus = SocialSettingsStatus(),
    val cities: List<SocialSettingsCity> = emptyList(),
    val tokens: List<SocialCaptionToken> = emptyList(),
    @SerialName("callback_url") val callbackUrl: String = "",
    val limits: SocialSettingsLimits = SocialSettingsLimits(),
    @SerialName("web_path") val webPath: String = "admin/social-settings",
)

/** «Collega con Meta»: l'indirizzo del sito da aprire nel browser (decisione 14). */
@Serializable
internal data class SocialMetaLink(
    val url: String = "",
    @SerialName("callback_url") val callbackUrl: String = "",
    @SerialName("return_path") val returnPath: String = "admin/social-settings",
)
