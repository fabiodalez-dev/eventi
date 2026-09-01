<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le voci di accessibilità di un locale (`venues.accessibility`).
 *
 * `accessibility` era un JSON libero, e un JSON libero sull'accessibilità è
 * peggio di un campo assente: chi ha bisogno di sapere se si entra senza
 * scalini non può leggere una frase diversa per ogni locale, e §11.3 chiede un
 * **filtro**, che su testo libero non esiste. Le voci sono queste sei e non
 * altre; quello che non ci sta si scrive nella descrizione del locale.
 *
 * Ogni voce ha tre stati e non due: presente, assente, **non dichiarata**. La
 * differenza conta più qui che altrove — «non sappiamo» non è «no», e
 * mostrarli allo stesso modo manderebbe qualcuno a sbattere contro uno
 * scalino. Nel JSON esistono solo le chiavi dichiarate.
 */
enum AccessibilityFeature: string
{
    case StepFreeEntrance = 'step_free_entrance';
    case AccessibleToilets = 'accessible_toilets';
    case ReservedSeating = 'reserved_seating';
    case TactilePath = 'tactile_path';
    case AssistanceOnRequest = 'assistance_on_request';
    case GuideDogAllowed = 'guide_dog_allowed';

    public function label(): string
    {
        return __('enums.accessibility_feature.'.$this->value);
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
