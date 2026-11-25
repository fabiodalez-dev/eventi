<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Event;
use App\Models\User;
use App\Rules\RealImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Un evento letto da Facebook in attesa di diventare un evento vero, per
 * l'app.
 *
 * Nel pannello i dati letti restano nello stato del modulo Livewire fino al
 * salvataggio. L'app invece compila il suo modulo e lo manda dopo: i dati di
 * Facebook restano qui, in cache, sotto un gettone legato alla persona e al
 * soggetto, e la creazione li riprende per la provenienza (`source_ref`) e
 * per la foto di copertina, che si scarica solo allora: nessun file
 * temporaneo resta sul disco per un modulo mai salvato.
 */
final class FacebookImportDrafts
{
    private const TTL_SECONDS = 6 * 3600;

    /** @param array<string, mixed> $source */
    public function put(User $user, string $subjectKey, array $source): string
    {
        $token = Str::random(40);
        Cache::put($this->key($token), ['user' => (int) $user->getKey(), 'subject' => $subjectKey, 'source' => $source], self::TTL_SECONDS);

        return $token;
    }

    /**
     * I dati letti, se il gettone è di questa persona per questo soggetto;
     * altrimenti un elenco vuoto (come un modulo compilato a mano).
     *
     * @return array<string, mixed>
     */
    public function get(User $user, string $subjectKey, ?string $token): array
    {
        if ($token === null || $token === '') {
            return [];
        }
        $stored = Cache::get($this->key($token));
        if (! is_array($stored) || ($stored['user'] ?? null) !== (int) $user->getKey() || ($stored['subject'] ?? null) !== $subjectKey || ! is_array($stored['source'] ?? null)) {
            return [];
        }

        return $stored['source'];
    }

    public function forget(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Cache::forget($this->key($token));
        }
    }

    /**
     * La foto di copertina diventa la locandina dell'evento appena creato,
     * se non ne ha già una. Un errore non ferma la creazione: si dice e
     * basta, come nel pannello («la foto non è stata importata»).
     *
     * @param  array<string, mixed>  $source
     */
    public function attachPhoto(Event $event, array $source): bool
    {
        $url = $source['cover']['url'] ?? null;
        if (! is_string($url) || $url === '' || $event->getFirstMedia('poster') !== null) {
            return false;
        }
        $path = null;
        try {
            [$bytes, $extension] = app(FacebookEventImport::class)->download($url);
            $path = tempnam(sys_get_temp_dir(), 'fb-poster-');
            if ($path === false) {
                return false;
            }
            file_put_contents($path, $bytes);
            $file = new UploadedFile($path, 'evento-facebook.'.$extension, null, null, true);
            Validator::make(['photo' => $file], ['photo' => [new RealImage]])->validate();
            $event->addMedia($path)->usingFileName('evento-facebook.'.$extension)->toMediaCollection('poster');

            return true;
        } catch (\Throwable $error) {
            report($error);

            return false;
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function key(string $token): string
    {
        return 'management-facebook-import:'.hash('sha256', $token);
    }
}
