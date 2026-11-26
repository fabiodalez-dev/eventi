<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Actions\UpdateOrganizerProfile;
use App\Enums\ContactMode;
use App\Models\City;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gli organizzatori nella redazione (`OrganizerResource`, solo admin e super
 * admin): elenco, nuovo, modifica. Nessuna eliminazione
 * (`OrganizerPolicy::delete` è sempre no).
 *
 * Il modulo è quello del pannello, campo per campo: nome, città di
 * riferimento, responsabile, collaboratori, descrizione, sito, modulo di
 * contatto, email pubblica, attivo. Presentazione, sito, email e modulo di
 * contatto passano da `UpdateOrganizerProfile`, la stessa regola del profilo
 * dell'organizzatore.
 */
final class EditorialOrganizers
{
    /** Le persone proposte nel selettore del responsabile e dei collaboratori. */
    public const PEOPLE_LIMIT = 50;

    public function __construct(private readonly UpdateOrganizerProfile $profile) {}

    /** @return LengthAwarePaginator<int, Organizer> */
    public function paginate(string $search, int $perPage, int $page): LengthAwarePaginator
    {
        $search = trim($search);

        return Organizer::query()
            ->with(['city', 'owner'])
            ->withCount('users')
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function row(Organizer $organizer, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => (int) $organizer->getKey(),
            'name' => (string) $organizer->name,
            'slug' => (string) $organizer->slug,
            'city' => $organizer->city === null ? null : ['id' => (int) $organizer->city->getKey(), 'name' => (string) $organizer->city->name],
            'owner' => $organizer->owner === null ? null : ['id' => (int) $organizer->owner->getKey(), 'name' => (string) $organizer->owner->name, 'email' => (string) $organizer->owner->email],
            'is_active' => (bool) $organizer->is_active,
            'status_label' => (string) ($organizer->is_active ? __('pannelli-redazione.organizzatori.active') : __('pannelli-redazione.organizzatori.inactive')),
            'abilities' => [
                'update' => $gate->allows('update', $organizer),
                'destroy' => $gate->allows('delete', $organizer),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Organizer $organizer, User $user): array
    {
        $organizer->loadMissing(['city', 'owner', 'users']);

        return [
            ...$this->row($organizer, $user),
            'users' => $organizer->users->map(fn (User $member): array => ['id' => (int) $member->getKey(), 'name' => (string) $member->name, 'email' => (string) $member->email])->values()->all(),
            'description' => $organizer->description,
            'website' => $organizer->website,
            'email' => $organizer->email,
            'contact_mode' => $organizer->contact_mode->value,
            'contact_email' => $organizer->contact_email,
            'contact_confirmed' => $organizer->hasConfirmedContactEmail(),
            'public_url' => $organizer->is_active ? route('organizers.show', $organizer) : null,
        ];
    }

    /**
     * Il nuovo organizzatore. «Attivo» parte spento, come nel pannello.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): Organizer
    {
        $state = $this->validate($input, null);

        return DB::transaction(function () use ($state): Organizer {
            $organizer = new Organizer;
            $organizer->forceFill(['is_active' => false]);

            return $this->save($organizer, $state);
        });
    }

    /** @param  array<string, mixed>  $input */
    public function update(Organizer $organizer, array $input): Organizer
    {
        $state = $this->validate($input, $organizer);

        return DB::transaction(fn (): Organizer => $this->save($organizer, $state));
    }

    /**
     * Le regole dei campi del modulo del pannello. Al nuovo servono nome,
     * città e responsabile; la modifica convalida solo le chiavi ricevute.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Organizer $organizer): array
    {
        $presence = $organizer === null ? 'required' : 'sometimes';
        $validator = Validator::make($input, [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'city_id' => [$presence, 'required', 'integer', Rule::exists('cities', 'id')],
            'owner_id' => [$presence, 'required', 'integer', Rule::exists('users', 'id')],
            'users' => ['sometimes', 'array', 'list'],
            'users.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.UpdateOrganizerProfile::DESCRIPTION_MAX],
            'website' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_mode' => ['sometimes', 'required', Rule::enum(ContactMode::class)],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], [
            'name' => 'Nome',
            'city_id' => 'Città di riferimento',
            'owner_id' => 'Responsabile',
            'users' => 'Collaboratori',
            'description' => 'Descrizione',
            'website' => 'Sito web',
            'email' => 'Email pubblica',
            'contact_mode' => __('contact.mode_question'),
            'contact_email' => __('contact.recipient'),
        ]);
        // Messaggi accesi senza un indirizzo che li riceva: come `ContactFields`, il recapito diventa obbligatorio.
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($input, $organizer): void {
            $mode = array_key_exists('contact_mode', $input) ? $input['contact_mode'] : ($organizer?->contact_mode->value ?? ContactMode::Disabled->value);
            $email = array_key_exists('contact_email', $input) ? $input['contact_email'] : $organizer?->contact_email;
            if (in_array($mode, [ContactMode::Members->value, ContactMode::Everyone->value], true) && blank($email)) {
                $validator->errors()->add('contact_email', __('validation.required', ['attribute' => __('contact.recipient')]));
            }
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * Le persone fra cui scegliere responsabile e collaboratori: il pannello
     * le cerca per email fra tutti gli account.
     *
     * @return Collection<int, User>
     */
    public function people(string $search): Collection
    {
        $search = trim($search);

        return User::query()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $where): Builder => $where
                ->where('email', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('email')
            ->limit(self::PEOPLE_LIMIT)
            ->get(['id', 'name', 'email']);
    }

    /** @return list<array{value: string, label: string}> */
    public static function cityChoices(): array
    {
        return City::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (City $city): array => ['value' => (string) $city->getKey(), 'label' => (string) $city->name])->values()->all();
    }

    /** @param  array<string, mixed>  $state */
    private function save(Organizer $organizer, array $state): Organizer
    {
        foreach (['name', 'city_id', 'owner_id', 'is_active'] as $field) {
            if (array_key_exists($field, $state)) {
                $organizer->setAttribute($field, $state[$field]);
            }
        }
        if (! $organizer->exists) {
            $organizer->save();
        }
        $this->profile->handle($organizer, $state);
        $organizer->save();
        if (array_key_exists('users', $state)) {
            $organizer->users()->sync(array_map('intval', (array) $state['users']));
        }

        return $organizer->refresh();
    }
}
