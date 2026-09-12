<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsExternalLinks;
use App\Casts\AsFacts;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\MembershipRequirement;
use App\Enums\PriceType;
use App\Enums\VerificationStatus;
use App\Models\Concerns\HasEditorialContent;
use App\Models\Concerns\HasImageVariants;
use App\Models\Concerns\HasSafeEditorContent;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Event extends Model implements HasMedia
{
    use HasSafeEditorContent;

    protected $with = ['organizer'];

    use HasEditorialContent;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasImageVariants;
    use HasSlug;
    use InteractsWithMedia;
    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'venue_id',
        'organizer_id',
        'category_id',
        'created_by',
        'organizer_name',
        'organizer_url',
        'title',
        'slug',
        'subtitle',
        'description',
        'short_description',
        'poster',
        'gallery',
        'price_type',
        'price_min',
        'price_max',
        'currency',
        'price_notes',
        'ticket_url',
        'booking_required',
        'booking_url',
        'booking_phone',
        'age_restriction',
        'language',
        'is_outdoor',
        'custom_location',
        'external_links',
        'facts',
        'source',
        'source_ref',
        'verification_status',
        'status',
        'rejection_reason',
        'is_featured',
        'featured_until',
        'editorial_score',
        'published_at',
        'seo',
        'views_count',
        'saves_count',
    ];

    /**
     * Le colonne su cui `/cerca` interroga (§11.1).
     *
     * Il driver `database` di Scout non costruisce alcun indice esterno: legge
     * queste chiavi come nomi di colonna e le confronta direttamente. Titolo,
     * sottotitolo, riassunto e organizzatore vanno per `LIKE`, così che
     * "concer" trovi "concerto"; la descrizione, che è un testo lungo, passa
     * per l'indice full-text dichiarato dall'attributo — su un `LONGTEXT` un
     * `LIKE '%…%'` leggerebbe l'intera tabella a ogni ricerca.
     *
     * @return array<string, string|null>
     */
    #[SearchUsingFullText(['description'])]
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'short_description' => $this->short_description,
            'organizer_name' => $this->organizer_name,
            'description' => $this->description,
        ];
    }

    /**
     * Lo slug è unico per città, non globalmente: la stessa serata può esistere
     * a Padova e a Vicenza con lo stesso titolo. Il vincolo corrispondente è
     * l'indice unico `(city_id, slug)` della tabella.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate()
            ->extraScope(fn (Builder $query) => $query->where('city_id', $this->city_id));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('event')
            ->logOnly(['status', 'verification_status', 'published_at', 'rejection_reason', 'is_featured', 'editorial_score'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('poster')->singleFile();
        $this->addMediaCollection('gallery');
    }

    /**
     * Le sei conversioni di §12.1 — `thumb`, `card`, `full`, ognuna in WebP e
     * in AVIF — valgono per tutte le raccolte di questo modello: sono
     * immagini, e un'immagine si serve nello stesso modo ovunque compaia.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->registerImageVariants();
        foreach (['schema-square' => [1200, 1200], 'schema-landscape' => [1600, 1200], 'schema-wide' => [1920, 1080]] as $name => [$width, $height]) {
            $this->addMediaConversion($name)->performOnCollections('poster')->queued()
                ->fit(Fit::Fill, $width, $height)->background('#f5f5f5')->format('webp')->quality(85);
        }
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'event_tag');
    }

    /**
     * Le campagne sponsorizzate su questo evento (§sponsorizzazioni).
     *
     * Sono piu' d'una perche' lo stesso evento puo' essere sponsorizzato in
     * momenti e collocazioni diversi — ed e' anche il motivo per cui vivono
     * in una tabella loro invece che in due colonne qui: hanno un committente,
     * un importo e uno storico che appartengono alla campagna, non all'evento.
     *
     * **Da non confondere con `is_featured`**, che e' la scelta gratuita della
     * redazione. Le due cose si somigliano a schermo e sono opposte nella
     * sostanza: una si guadagna, l'altra si compra, e solo la seconda va
     * dichiarata a chi guarda.
     *
     * @return HasMany<Sponsorship, $this>
     */
    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class);
    }

    /** @return HasMany<EventOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /**
     * Le fasce di prezzo (§ «Biglietti e fasce di prezzo» del disegno).
     *
     * Comprende sia il listino dell'evento sia quelli delle singole date: a
     * distinguerli è `App\Support\TicketTiers`, che è l'unico posto in cui la
     * regola di risoluzione è scritta.
     *
     * @return HasMany<TicketTier, $this>
     */
    public function ticketTiers(): HasMany
    {
        return $this->hasMany(TicketTier::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<EventRecurrence, $this> */
    public function recurrences(): HasMany
    {
        return $this->hasMany(EventRecurrence::class);
    }

    /** @return HasManyThrough<Lineup, EventOccurrence, $this> */
    public function lineups(): HasManyThrough
    {
        return $this->hasManyThrough(Lineup::class, EventOccurrence::class, 'event_id', 'occurrence_id');
    }

    /** @return HasMany<EventViewDaily, $this> */
    public function dailyViews(): HasMany
    {
        return $this->hasMany(EventViewDaily::class);
    }

    /** @return HasMany<EventSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(EventSubmission::class);
    }

    /** @return HasMany<Promotion, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * Gli eventi che il pubblico può ancora **leggere**: pubblicati e
     * archiviati (§14.5).
     *
     * La differenza con `published()` è tutta qui: quello decide chi entra
     * nelle liste, questo chi ha ancora una pagina. Un evento archiviato è
     * uscito dagli elenchi ma non dal sito — è arrivato in fondo alla propria
     * vita utile, non è stato ritirato — e restituire 404 su un indirizzo che
     * ha ricevuto visite per mesi butterebbe via ciò che §11.9 chiama il
     * valore organico dell'archivio.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeReadable(Builder $query): void
    {
        $query->whereIn('status', [EventStatus::Published, EventStatus::Archived]);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfStatus(Builder $query, EventStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeInCity(Builder $query, City|int $city): void
    {
        $query->where('city_id', $city instanceof City ? $city->getKey() : $city);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfCategory(Builder $query, Category|int $category): void
    {
        $query->where('category_id', $category instanceof Category ? $category->getKey() : $category);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeAtVenue(Builder $query, Venue|int $venue): void
    {
        $query->where('venue_id', $venue instanceof Venue ? $venue->getKey() : $venue);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeOfSource(Builder $query, EventSource $source): void
    {
        $query->where('source', $source);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeFree(Builder $query): void
    {
        $query->where('price_type', PriceType::Free);
    }

    /**
     * @param  Builder<Event>  $query
     */
    public function scopeWithTag(Builder $query, Tag|int $tag): void
    {
        $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($tag instanceof Tag ? $tag->getKey() : $tag));
    }

    public function membershipRequirement(): ?MembershipRequirement
    {
        $value = $this->content_details['membership'] ?? null;
        if (blank($value)) {
            $value = $this->venue?->getAttribute('content_details')['membership'] ?? null;
        }

        return (is_string($value) ? MembershipRequirement::tryFrom($value) : null)
            ?? ($this->price_type === PriceType::Membership ? MembershipRequirement::Required : null);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'source' => EventSource::class,
            'verification_status' => VerificationStatus::class,
            'status' => EventStatus::class,
            'gallery' => 'array',
            'custom_location' => 'array',
            'external_links' => AsExternalLinks::class,
            'facts' => AsFacts::class,
            'seo' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'booking_required' => 'boolean',
            'is_outdoor' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'editorial_score' => 'integer',
            'scheduled_publish_at' => 'immutable_datetime',
            'publication_scheduled_by' => 'integer',
            'published_at' => 'datetime',
            'views_count' => 'integer',
            'saves_count' => 'integer',
        ];
    }
}
