<?php

namespace App\Http\Controllers\Web;

use App\Filament\Admin\Pages\SocialSettings;
use App\Http\Controllers\Controller;
use App\Models\SocialConnection;
use App\Services\Social\MetaOAuth;
use App\Services\Social\SocialPublisher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class MetaOAuthController extends Controller
{
    public function connect(Request $request, MetaOAuth $oauth): RedirectResponse
    {
        $this->authorizeAdmin($request);
        try {
            $connection = SocialConnection::firstOrFail();
            $state = Str::random(64);
            $url = $oauth->loginUrl($connection, $state);
            $request->session()->put('meta_oauth', ['user_id' => $request->user()->id, 'state' => $state, 'connection' => $connection->id, 'fingerprint' => $oauth->fingerprint($connection), 'expires' => now()->addMinutes(10)->timestamp]);

            return redirect()->away($url);
        } catch (\Throwable) {
            return redirect(SocialSettings::getUrl())->with('meta_message', 'Salva App ID e App Secret prima di collegare Meta.');
        }
    }

    public function callback(Request $request, MetaOAuth $oauth): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $state = $request->session()->pull('meta_oauth');
        abort_unless(is_array($state) && ($state['user_id'] ?? null) === $request->user()->id && ($state['expires'] ?? 0) > now()->timestamp && is_string($request->query('state')) && hash_equals($state['state'], $request->query('state')), 403);
        try {
            $connection = SocialConnection::findOrFail($state['connection']);
            abort_unless(hash_equals($state['fingerprint'], $oauth->fingerprint($connection)), 403);
            if ($request->has('error') || ! is_string($request->query('code'))) {
                return redirect(SocialSettings::getUrl())->with('meta_message', 'Autorizzazione Meta annullata o non concessa.');
            }
            $pages = $oauth->pages($connection, $request->query('code'));
            if ($pages === []) {
                return redirect(SocialSettings::getUrl())->with('meta_message', 'Nessuna Pagina disponibile. Verifica i permessi concessi e il tuo ruolo sulla Pagina.');
            }
            $request->session()->put('meta_pages', Crypt::encryptString(json_encode(['user_id' => $request->user()->id, 'connection' => $connection->id, 'fingerprint' => $oauth->fingerprint($connection), 'pages' => $pages, 'expires' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR)));

            return redirect()->route('social.meta.pages');
        } catch (\Throwable) {
            return redirect(SocialSettings::getUrl())->with('meta_message', 'Collegamento Meta non completato. Controlla credenziali, URL OAuth e permessi e riprova.');
        }
    }

    public function pages(Request $request): View
    {
        $this->authorizeAdmin($request);
        $data = $this->pendingPages($request);

        return view('filament.social.meta-pages', ['pages' => collect($data['pages'])->map(fn (array $page): array => ['id' => $page['id'], 'name' => $page['name'], 'instagram_id' => $page['instagram_id']])->all()]);
    }

    public function select(Request $request, MetaOAuth $oauth, SocialPublisher $publisher): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate(['page_id' => ['required', 'string', 'regex:/^\d+$/']]);
        $data = $this->pendingPages($request);
        $page = collect($data['pages'])->firstWhere('id', $input['page_id']);
        abort_unless($page !== null, 422);
        $connection = SocialConnection::findOrFail($data['connection']);
        abort_unless(hash_equals($data['fingerprint'], $oauth->fingerprint($connection)), 403);
        $connection->fill(['page_id' => $page['id'], 'instagram_id' => $page['instagram_id'], 'access_token' => $page['access_token'],
            'facebook_enabled' => true, 'instagram_enabled' => $page['instagram_id'] !== null, 'verified_at' => null, 'automatic' => false]);
        $connection->save();
        $request->session()->forget('meta_pages');
        try {
            $publisher->verify($connection);

            return redirect(SocialSettings::getUrl())->with('meta_message', 'Pagina collegata e verificata. Puoi scegliere i canali da abilitare e attivare l’autopost.');
        } catch (\Throwable) {
            return redirect(SocialSettings::getUrl())->with('meta_message', 'Credenziali salvate, ma verifica non riuscita. Controlla i permessi Meta prima di pubblicare.');
        }
    }

    /** @return array{user_id:int,connection:int,fingerprint:string,expires:int,pages:list<array{id:string,name:string,access_token:string,instagram_id:?string}>} */
    private function pendingPages(Request $request): array
    {
        $encrypted = $request->session()->get('meta_pages');
        abort_unless(is_string($encrypted), 419);
        $data = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($data['user_id'] ?? null) === $request->user()->id && ($data['expires'] ?? 0) > now()->timestamp, 419);

        return $data;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'super_admin']), 403);
    }
}
