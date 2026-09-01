<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * I tag di §7.5 del piano più altri plausibili, per dimostrare che la
 * tassonomia è estendibile senza deploy.
 *
 * **Idempotente** per la stessa ragione di `CategorySeeder`: l'installer lo
 * esegue in un'operazione che dev'essere ripetibile senza danno (D42, punto 5).
 */
class TagSeeder extends Seeder
{
    /** @var list<string> */
    private const TAGS_FROM_PLAN = [
        'punk', 'hardcore', 'jazz', 'techno', 'house', 'cantautorato', 'open-mic',
        'jam-session', 'benefit', 'fiaccolata', 'assemblea', 'corteo', 'stand-up',
        'presentazione-libro', 'proiezione', 'torneo', 'laboratorio', 'vinyl-market',
    ];

    /** @var list<string> */
    private const EXTRA_TAGS = [
        'rock', 'indie', 'elettronica', 'folk', 'reggae', 'hip-hop', 'cabaret',
        'poesia', 'degustazione', 'mercatino-vintage', 'escursione', 'yoga',
        'teatro-ragazzi', 'quiz-night', 'mostra-fotografica', 'cortometraggi',
        'danza-contemporanea', 'street-food', 'karaoke', 'no-profit',
    ];

    public function run(): void
    {
        foreach ([...self::TAGS_FROM_PLAN, ...self::EXTRA_TAGS] as $name) {
            Tag::query()->firstOrCreate(['name' => $name], [
                'category_id' => null,
                'is_approved' => true,
                'usage_count' => 0,
                'synonyms' => null,
            ]);
        }
    }
}
