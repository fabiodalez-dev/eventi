<?php

declare(strict_types=1);

namespace App\Support\Moderation;

use App\Models\Report;
use Illuminate\Database\Eloquent\Model;

/**
 * Di che cosa parla una segnalazione, detto a una persona.
 *
 * Il tipo morph contiene un alias breve (`event`, `venue`, …) e non un nome
 * di classe: è la scelta di §3.13 dello schema, e va tradotta invece di
 * mostrare una stringa da programmatori. La usano la colonna «Oggetto» del
 * pannello (`ReportResource`) e l'elenco delle segnalazioni dell'app della
 * redazione: una regola sola per tutte e due.
 *
 * Chi la usa su un elenco carica `reportable` nella query: altrimenti è una
 * query per riga.
 */
final class ReportSubject
{
    /** I soggetti della community: per questi il pannello offre «Modera il soggetto», che apre la pagina Community. */
    public const COMMUNITY_TYPES = ['community_profile', 'community_post', 'community_comment'];

    /** «Evento · Concerto all’alba»: il tipo tradotto e il titolo (o il nome, o l'inizio del testo) del soggetto. */
    public static function describe(Report $report): string
    {
        $label = __('admin.resources.'.$report->reportable_type.'.label');
        $label = $label === 'admin.resources.'.$report->reportable_type.'.label'
            ? $report->reportable_type
            : $label;

        $label = match ($report->reportable_type) {
            'community_profile' => __('community.subject_profile'),
            'community_post' => __('community.subject_post'),
            'community_comment' => __('community.subject_comment'),
            default => $label,
        };
        $subject = $report->reportable;
        $title = $subject instanceof Model
            ? (string) ($subject->getAttribute('title') ?? $subject->getAttribute('name') ?? $subject->getAttribute('display_name') ?? mb_substr((string) $subject->getAttribute('body'), 0, 100) ?: $subject->getKey())
            : (string) $report->reportable_id;

        return $label.' '.__('common.separator').' '.$title;
    }

    /** Vero se la segnalazione riguarda un profilo, un post o un commento della community. */
    public static function isCommunity(Report $report): bool
    {
        return in_array($report->reportable_type, self::COMMUNITY_TYPES, true);
    }
}
