<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Sponsorship;
use App\Models\User;

/**
 * Chi governa le campagne sponsorizzate.
 *
 * **Non il moderatore**, che pure sta piu' in alto di un referente di locale:
 * §3 gli affida la moderazione dei contenuti altrui, e decidere cosa compare a
 * pagamento e con quale priorita' e' una scelta commerciale, non editoriale.
 * Le due cose vanno tenute separate proprio perche' a schermo si somigliano —
 * un evento in evidenza e uno sponsorizzato occupano lo stesso spazio, e la
 * differenza e' chi lo ha deciso e perche'.
 *
 * **Nemmeno il referente del locale**, che sui propri eventi puo' quasi tutto:
 * potersi sponsorizzare da soli significherebbe che il posto in cima si prende
 * invece di comprarlo.
 */
class SponsorshipPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user?->can(Permission::ManageSponsorships->value) ?? false;
    }

    public function view(?User $user, Sponsorship $sponsorship): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSponsorships->value);
    }

    public function update(User $user, Sponsorship $sponsorship): bool
    {
        return $user->can(Permission::ManageSponsorships->value);
    }

    /**
     * Si cancella davvero solo cio' che non e' mai partito. Una campagna che ha
     * gia' raccolto visualizzazioni e' anche una riga di contabilita': resta,
     * eventualmente sospesa. La cancellazione e' comunque morbida
     * (`SoftDeletes`), quindi nulla sparisce dal database.
     */
    public function delete(User $user, Sponsorship $sponsorship): bool
    {
        return $user->can(Permission::ManageSponsorships->value);
    }
}
