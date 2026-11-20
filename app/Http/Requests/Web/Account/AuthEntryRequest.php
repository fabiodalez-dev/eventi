<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

final class AuthEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Nessuna regola sul ritorno: queste sono pagine da aprire, non moduli da
     * respingere. Un `intended` troppo lungo (l'indirizzo intero di una
     * ricerca con molti filtri) o scritto come elenco faceva fallire la
     * validazione, e la GET rimandava indietro invece di mostrare l'accesso,
     * la registrazione o il collegamento. Un ritorno che non va bene si
     * scarta qui sotto, come quelli verso altri siti.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function rememberDestination(bool $freshEntry = false): void
    {
        $destination = $this->input('intended');

        // Only absolute URLs on this origin can become a post-login redirect.
        if (is_string($destination)
            && strlen($destination) <= 2048
            && str_starts_with($destination, $this->getSchemeAndHttpHost().'/')
            && ! preg_match('/[\\\\\x00-\x20\x7f]/', $destination)) {
            $this->session()->put('url.intended', $destination);
            $this->session()->put('auth.entry_destination', $destination);
        } elseif ($freshEntry && ! $this->session()->has('errors')) {
            // A fresh generic login must not reuse an abandoned comments flow.
            if ($this->session()->get('url.intended') === $this->session()->get('auth.entry_destination')) {
                $this->session()->forget('url.intended');
            }
            $this->session()->forget('auth.entry_destination');
        }
    }
}
