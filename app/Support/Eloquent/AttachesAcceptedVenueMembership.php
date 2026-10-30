<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

/**
 * Una riga di `venue_user` scritta senza dire niente su `accepted_at` nasce
 * **accettata**.
 *
 * L'invito da accettare è uno solo, e lo scrive `InviteVenueMemberAction`
 * passando `accepted_at => null` in modo esplicito. Tutte le altre strade
 * — la richiesta di accreditamento approvata, la redazione che aggiunge chi
 * l'ha chiesto, i seeder, i test — danno l'accesso a chi lo ha voluto, e
 * prima di D64 nessuna scriveva la colonna: senza questo valore predefinito
 * ognuna produrrebbe un invito in sospeso, cioè una persona fuori dal locale
 * che crede di esserci.
 *
 * Si guarda il record **già composto** (dopo la fusione degli attributi per
 * id con quelli comuni), così `attach([$id => ['accepted_at' => null]])` e
 * `attach($id, ['accepted_at' => null])` restano entrambi inviti.
 */
trait AttachesAcceptedVenueMembership
{
    /**
     * @param  mixed  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @param  bool  $hasTimestamps
     * @return array<string, mixed>
     */
    protected function formatAttachRecord($key, $value, $attributes, $hasTimestamps): array
    {
        $record = parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps);

        if (! array_key_exists('accepted_at', $record)) {
            $record['accepted_at'] = $this->parent->freshTimestamp();
        }

        return $record;
    }
}
