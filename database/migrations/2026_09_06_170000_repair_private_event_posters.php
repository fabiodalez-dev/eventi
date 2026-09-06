<?php

declare(strict_types=1);

use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

return new class extends Migration
{
    public function up(): void
    {
        // Only event posters accidentally uploaded to Filament's default disk.
        // Never expose other private files (tickets, backups, documents).
        Media::query()->where('model_type', (new Event)->getMorphClass())
            ->where('collection_name', 'poster')->where('disk', 'local')
            ->each(function (Media $media): void {
                $source = Storage::disk('local');
                $target = Storage::disk('public');
                $original = $media->getPathRelativeToRoot();
                $directory = dirname($original);

                if ($directory !== (string) $media->getKey() || ! $source->exists($original)) {
                    throw new RuntimeException("Cannot safely repair poster {$media->getKey()}");
                }

                foreach ($source->allFiles($directory) as $path) {
                    $bytes = $source->get($path);
                    if ($target->exists($path) && $target->get($path) !== $bytes) {
                        throw new RuntimeException("Conflicting poster file: {$path}");
                    }
                    if (! $target->put($path, $bytes, 'public')) {
                        throw new RuntimeException("Could not copy poster: {$path}");
                    }
                }

                $media->disk = 'public';
                if ($media->conversions_disk === 'local') {
                    $media->conversions_disk = 'public';
                }
                $media->save();
                // Keep the private originals as a recovery copy.
            });
    }

    public function down(): void
    {
        // Data repair: do not make working image URLs inaccessible again.
    }
};
