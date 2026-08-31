<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\ImportReport;
use App\Enums\ImportRunStatus;
use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un'esecuzione di import già avvenuta: che cosa ha fatto e come è finita.
 *
 * È il registro che permette di rispondere a «da quando non arriva più
 * niente», domanda che le colonne `last_*` della sorgente non sanno affrontare
 * perché conservano solo l'ultima risposta.
 */
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'import_source_id',
        'status',
        'created_count',
        'updated_count',
        'unchanged_count',
        'excluded_count',
        'cancelled_count',
        'error_count',
        'message',
    ];

    /** @return BelongsTo<ImportSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ImportSource::class, 'import_source_id');
    }

    /**
     * La riga che descrive un resoconto. Sta qui e non in `ImportRunner`
     * perché la traduzione da resoconto a colonne è una proprietà di questa
     * tabella: chi un giorno aggiungerà un contatore lo aggiunge in un punto.
     */
    public static function fromReport(ImportSource $source, ImportRunStatus $status, ImportReport $report, ?string $message): self
    {
        $counts = $report->toArray();

        return new self([
            'import_source_id' => $source->getKey(),
            'status' => $status->value,
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'unchanged_count' => $counts['unchanged'],
            'excluded_count' => $counts['excluded'],
            'cancelled_count' => $counts['cancelled'],
            'error_count' => $counts['errors'],
            'message' => $message,
        ]);
    }

    /**
     * L'esito come enum quando lo si riconosce. La colonna resta una stringa
     * libera come `import_sources.last_status`: è lo stesso contratto, e un
     * valore scritto da un driver futuro non deve far esplodere un elenco.
     */
    public function runStatus(): ?ImportRunStatus
    {
        // `tryFrom` e gia la difesa: restituisce null su un valore che l'enum
        // non conosce, quindi uno stato scritto da un driver futuro non fa
        // esplodere l'elenco che lo legge.
        return ImportRunStatus::tryFrom($this->status);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'excluded_count' => 'integer',
            'cancelled_count' => 'integer',
            'error_count' => 'integer',
        ];
    }
}
