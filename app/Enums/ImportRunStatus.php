<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * L'esito di un'esecuzione di import, scritto in `import_sources.last_status`.
 *
 * Sono tre e non due perché «riuscito» e «fallito» non descrivono il caso più
 * frequente: un calendario di duecento voci in cui tre non si lasciano
 * interpretare. Quella non è un'esecuzione fallita — le altre centonovantasette
 * date sono entrate — ma non è nemmeno pulita, e chi sorveglia le sorgenti deve
 * distinguerla a colpo d'occhio.
 */
enum ImportRunStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.import_run_status.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
