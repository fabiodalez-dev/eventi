<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CookieDeclaration;
use App\Models\ImportSource;
use App\Models\Redirect;
use App\Support\Import\ImportUrlMask;
use App\Support\SecurityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Il registro di sicurezza delle tabelle di configurazione che pannello e
 * app scrivono con i modelli (revisione di sicurezza dell'ondata 3, punto 3):
 * sorgenti di import, redirect, registro dei cookie. Creazione, modifica e
 * cancellazione, con chi, prima e dopo, dal pannello `/admin`, dall'app della
 * redazione e dalla pagina «Collega il tuo calendario» del locale.
 *
 * Solo quando scrive una persona collegata: i seeder, le migrazioni e i
 * lavori in coda (l'import che aggiorna `last_run_at`) non sono scelte di
 * qualcuno, e di questi modelli si registrano solo i campi del modulo.
 *
 * Mai le credenziali di una sorgente: si dice solo se sono cambiate o tolte,
 * e l'indirizzo di una sorgente segreta entra mascherato (`ImportUrlMask`).
 */
final class ConfigurationAuditObserver
{
    /**
     * Per modello: nome dell'oggetto, i tre eventi, i campi registrati.
     *
     * @var array<class-string<Model>, array{oggetto: string, eventi: array{created: string, updated: string, deleted: string}, campi: list<string>}>
     */
    private const AUDITED = [
        ImportSource::class => [
            'oggetto' => 'import_source',
            'eventi' => ['created' => 'import_sorgente_creata', 'updated' => 'import_sorgente_modificata', 'deleted' => 'import_sorgente_eliminata'],
            'campi' => ['city_id', 'venue_id', 'type', 'url', 'mapping', 'default_category_id', 'is_active'],
        ],
        Redirect::class => [
            'oggetto' => 'redirect',
            'eventi' => ['created' => 'redirect_creato', 'updated' => 'redirect_modificato', 'deleted' => 'redirect_eliminato'],
            'campi' => ['city_id', 'from_path', 'to_path', 'is_wildcard', 'status'],
        ],
        CookieDeclaration::class => [
            'oggetto' => 'cookie_declaration',
            'eventi' => ['created' => 'cookie_creato', 'updated' => 'cookie_modificato', 'deleted' => 'cookie_eliminato'],
            'campi' => ['category', 'name', 'provider', 'purpose', 'duration', 'sort_order'],
        ],
    ];

    public function created(Model $model): void
    {
        $this->write($model, 'created', [], $this->snapshot($model, fn (string $field): mixed => $model->getAttribute($field), $this->hasCredentialsNow($model)));
    }

    public function updated(Model $model): void
    {
        $config = self::AUDITED[$model::class] ?? null;
        if ($config === null) {
            return;
        }

        $changed = array_values(array_intersect($config['campi'], array_keys($model->getChanges())));
        $credentials = $model instanceof ImportSource && array_key_exists('credentials', $model->getChanges());
        if ($changed === [] && ! $credentials) {
            return;
        }

        $before = $this->snapshot($model, fn (string $field): mixed => $model->getOriginal($field), $this->hadCredentials($model), $changed);
        $after = $this->snapshot($model, fn (string $field): mixed => $model->getAttribute($field), $this->hasCredentialsNow($model), $changed);
        $extra = $credentials ? ['credenziali' => filled($model->getAttributes()['credentials'] ?? null) ? 'cambiate' : 'tolte'] : [];

        $this->write($model, 'updated', $before, $after, $extra);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', $this->snapshot($model, fn (string $field): mixed => $model->getAttribute($field), $this->hasCredentialsNow($model)), []);
    }

    /**
     * @param  callable(string): mixed  $read
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    private function snapshot(Model $model, callable $read, bool $hasCredentials, ?array $only = null): array
    {
        $values = [];
        foreach ($only ?? self::AUDITED[$model::class]['campi'] ?? [] as $field) {
            $value = SecurityLog::valore($read($field));
            if ($model instanceof ImportSource && $field === 'url') {
                $value = ImportUrlMask::show(is_string($value) ? $value : null, $hasCredentials);
            }
            $values[$field] = $value;
        }

        return $values;
    }

    private function hadCredentials(Model $model): bool
    {
        return $model instanceof ImportSource && filled($model->getRawOriginal('credentials'));
    }

    private function hasCredentialsNow(Model $model): bool
    {
        return $model instanceof ImportSource && filled($model->getAttributes()['credentials'] ?? null);
    }

    /**
     * @param  'created'|'updated'|'deleted'  $action
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $extra
     */
    private function write(Model $model, string $action, array $before, array $after, array $extra = []): void
    {
        $config = self::AUDITED[$model::class] ?? null;
        if ($config === null || Auth::user() === null) {
            return;
        }

        SecurityLog::configurazione($config['eventi'][$action], array_filter([
            'oggetto' => $config['oggetto'],
            'id' => $model->getKey(),
            'prima' => $before === [] ? null : $before,
            'dopo' => $after === [] ? null : $after,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
