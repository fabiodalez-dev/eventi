<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventFeature;
use App\Models\EventOccurrence;
use Illuminate\Database\Seeder;

class BeforeGoingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('La demo Prima di andare si crea solo in locale o nei test.');
        }
        $this->call(EventFeatureSeeder::class);
        $source = Event::query()->where('status', 'published')->where('is_demo', false)->firstOrFail();
        $demo = Event::query()->firstOrNew(['slug' => 'demo-prima-di-andare']);
        if (! $demo->exists) {
            $demo->fill($source->only(['city_id', 'venue_id', 'category_id', 'created_by']));
            $demo->fill(['title' => 'Demo: una serata da organizzare insieme', 'slug' => 'demo-prima-di-andare', 'description' => 'Una scheda dimostrativa per provare le informazioni pratiche: ingresso, accessibilità, servizi e indicazioni personalizzate. I dati sono esempi di compilazione, non condizioni reali del locale.', 'status' => 'published', 'price_type' => 'free', 'is_demo' => true, 'content_details' => [
                'membership' => 'required', 'membership_notes' => 'Tessera associativa annuale: 10 €. Richiedila prima dell’evento. Esempio dimostrativo.',
                'accessibility' => 'yes', 'accessibility_notes' => 'Ingresso laterale senza gradini, personale disponibile all’accoglienza. Esempio dimostrativo.',
                'feature_ids' => EventFeature::query()->whereIn('slug', ['accesso-in-coppia', 'bagno-accessibile', 'interprete-lis', 'area-tranquilla', 'prenotazione-obbligatoria', 'guardaroba-disponibile', 'pagamenti-elettronici', 'parcheggio-biciclette', 'acqua-potabile-disponibile', 'opzioni-vegetariane', 'posti-a-sedere', 'minori-accompagnati'])->pluck('id')->all(),
                'practical_custom' => [['label' => 'Porta una borraccia', 'icon' => 'beaker', 'text' => 'Puoi riempirla gratuitamente al punto acqua.'], ['label' => 'Ingresso dal cortile', 'icon' => 'map-pin', 'text' => 'Segui le indicazioni all’ingresso principale.']],
            ]]);
            $demo->save();
            EventOccurrence::factory()->create(['event_id' => $demo->id, 'starts_at' => now()->addDays(7)->setTime(18, 30), 'ends_at' => now()->addDays(7)->setTime(21, 30)]);
        }
        $this->command->info('Evento demo #'.$demo->id.' — /admin/events/'.$demo->slug.'/edit');
    }
}
