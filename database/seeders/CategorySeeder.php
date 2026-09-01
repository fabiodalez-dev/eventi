<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Le 14 categorie di §7.4 del piano, con i tre flag che governano il motore
 * temporale copiati fedelmente dalla tabella: `default_duration_minutes`
 * (§8.3), `supports_ongoing` (§8.4) e `is_nightlife` (§8.2).
 *
 * **Idempotente**, come gli altri seeder che l'installer esegue (D42, punto 5):
 * la corrispondenza è sul nome, quindi rieseguirlo non duplica niente e non
 * riscrive le modifiche fatte dalla redazione. Serve perché ogni operazione
 * dell'installazione dev'essere ripetibile senza danno — un secondo clic sul
 * pulsante non deve produrre ventotto categorie.
 */
class CategorySeeder extends Seeder
{
    /**
     * @var list<array{name: string, icon: string, color: string, duration: int|null, ongoing: bool, nightlife: bool}>
     */
    private const CATEGORIES = [
        ['name' => 'Musica dal vivo', 'icon' => 'musical-note', 'color' => '#7C3AED', 'duration' => 180, 'ongoing' => true, 'nightlife' => true],
        ['name' => 'DJ set / Nightlife', 'icon' => 'sparkles', 'color' => '#DB2777', 'duration' => 300, 'ongoing' => true, 'nightlife' => true],
        ['name' => 'Teatro e danza', 'icon' => 'ticket', 'color' => '#B91C1C', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Cinema', 'icon' => 'film', 'color' => '#4338CA', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Arte e mostre', 'icon' => 'paint-brush', 'color' => '#C2410C', 'duration' => null, 'ongoing' => false, 'nightlife' => false],
        ['name' => 'Libri e presentazioni', 'icon' => 'book-open', 'color' => '#0F766E', 'duration' => 90, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Politica e attivismo', 'icon' => 'megaphone', 'color' => '#334155', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Sport', 'icon' => 'trophy', 'color' => '#15803D', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Food e sagre', 'icon' => 'cake', 'color' => '#D97706', 'duration' => 300, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Mercatini', 'icon' => 'shopping-bag', 'color' => '#CA8A04', 'duration' => 480, 'ongoing' => false, 'nightlife' => false],
        ['name' => 'Corsi e workshop', 'icon' => 'wrench-screwdriver', 'color' => '#0891B2', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Bambini e famiglie', 'icon' => 'face-smile', 'color' => '#DB2777', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Comunità e assemblee', 'icon' => 'user-group', 'color' => '#1D4ED8', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Altro', 'icon' => 'ellipsis-horizontal', 'color' => '#6B7280', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $category) {
            Category::query()->firstOrCreate(['name' => $category['name']], [
                'icon' => $category['icon'],
                'color' => $category['color'],
                'sort_order' => $index,
                'is_active' => true,
                'parent_id' => null,
                'default_duration_minutes' => $category['duration'],
                'supports_ongoing' => $category['ongoing'],
                'is_nightlife' => $category['nightlife'],
            ]);
        }
    }
}
