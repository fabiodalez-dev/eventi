<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Actions\Installer\RunInstallationTask;
use App\Enums\InstallerStep;
use App\Enums\InstallerTask;
use App\Exceptions\Installer\TaskFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\AdminStepRequest;
use App\Http\Requests\Installer\ApplicationStepRequest;
use App\Http\Requests\Installer\CityStepRequest;
use App\Http\Requests\Installer\DatabaseStepRequest;
use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallerState;
use App\Services\Installer\InstallLock;
use App\Services\Installer\RequirementsChecker;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Il wizard di installazione (D42).
 *
 * Il controller orchestra e basta: ogni decisione sta in un servizio di
 * `App\Services\Installer`, ogni input passa da una Form Request, ogni testo
 * da `lang/it/installer.php`.
 *
 * **POST-redirect-GET ovunque.** Nessun POST risponde con una pagina: risponde
 * con un rimando. Ricaricare la schermata dopo aver premuto un pulsante non
 * riesegue niente — che durante un'installazione non è un dettaglio di stile,
 * perché il pulsante che si è appena premuto migrava un database.
 */
class InstallerController extends Controller
{
    public function __construct(
        private readonly InstallerState $state,
        private readonly RequirementsChecker $requirements,
        private readonly DatabaseInspector $inspector,
        private readonly InstallLock $lock,
        private readonly EnvWriter $env,
    ) {}

    /** Chi arriva a `/installazione` finisce dove è rimasto. */
    public function index(): RedirectResponse
    {
        return redirect()->to($this->state->firstIncomplete()->url());
    }

    public function requirements(): View
    {
        return view('installer.requirements', [
            'step' => InstallerStep::Requirements,
            'mandatory' => $this->requirements->mandatory(),
            'advisory' => $this->requirements->advisory(),
            'passes' => $this->requirements->passes($this->requirements->all()),
        ]);
    }

    public function storeRequirements(): RedirectResponse
    {
        $requirements = $this->requirements->all();

        if (! $this->requirements->passes($requirements)) {
            return redirect()->to(InstallerStep::Requirements->url())
                ->with('installer_error', __('installer.requirements.blocked'));
        }

        return $this->advance(InstallerStep::Requirements);
    }

    public function database(): View
    {
        return view('installer.database', [
            'step' => InstallerStep::Database,
            'values' => $this->state->get(InstallerStep::Database),
        ]);
    }

    /**
     * Il solo passo con un limite di frequenza (`throttle:10,1`, dichiarato
     * sulla rotta): è un tester di connessioni verso host arbitrari, non
     * autenticato e raggiungibile da chiunque finché l'installazione non è
     * finita. Lasciarlo libero era il difetto peggiore del modello analizzato.
     */
    public function storeDatabase(DatabaseStepRequest $request): RedirectResponse
    {
        $values = $request->values();

        $probe = $this->inspector->probe(
            $values['db_host'],
            (int) $values['db_port'],
            $values['db_database'],
            $values['db_username'],
            $values['db_password'],
        );

        if (! $probe->ok) {
            return redirect()->to(InstallerStep::Database->url())
                ->withInput($request->except('db_password'))
                ->with('installer_error', $probe->message());
        }

        $this->state->put(InstallerStep::Database, $values);

        $redirect = $this->advance(InstallerStep::Database);

        return $probe->databaseCreated
            ? $redirect->with('installer_notice', __('installer.database.created', ['database' => $values['db_database']]))
            : $redirect;
    }

    public function application(Request $request): View
    {
        return view('installer.application', [
            'step' => InstallerStep::Application,
            'values' => $this->state->get(InstallerStep::Application) + [
                'app_url' => $request->getSchemeAndHttpHost(),
                'mail_mailer' => 'log',
            ],
        ]);
    }

    public function storeApplication(ApplicationStepRequest $request): RedirectResponse
    {
        $this->state->put(InstallerStep::Application, $request->values());

        return $this->advance(InstallerStep::Application);
    }

    public function city(): View
    {
        return view('installer.city', [
            'step' => InstallerStep::City,
            'values' => $this->state->get(InstallerStep::City) + [
                'timezone' => 'Europe/Rome',
                'radius_km' => '30',
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function storeCity(CityStepRequest $request): RedirectResponse
    {
        $this->state->put(InstallerStep::City, $request->values());

        return $this->advance(InstallerStep::City);
    }

    public function admin(): View
    {
        return view('installer.admin', [
            'step' => InstallerStep::Admin,
            'values' => $this->state->get(InstallerStep::Admin),
        ]);
    }

    public function storeAdmin(AdminStepRequest $request): RedirectResponse
    {
        $this->state->put(InstallerStep::Admin, $request->values());

        return $this->advance(InstallerStep::Admin);
    }

    public function run(): View
    {
        return view('installer.run', [
            'step' => InstallerStep::Run,
            'tasks' => InstallerTask::ordered(),
            'state' => $this->state,
            'next' => $this->state->nextTask(),
        ]);
    }

    /**
     * Esegue **la prossima** operazione della checklist e torna alla checklist.
     * Una per POST: è ciò che tiene ogni richiesta corta abbastanza da non
     * incontrare il limite di tempo di PHP su un database lento.
     */
    public function storeRun(RunInstallationTask $task): RedirectResponse
    {
        $next = $this->state->nextTask();

        if ($next === null) {
            return $this->finish();
        }

        try {
            $warning = $task($next);
        } catch (TaskFailed $failure) {
            Log::error('Installer: operazione non riuscita', [
                'operazione' => $next->value,
                'dettaglio' => $failure->detail,
            ]);

            return redirect()->to(InstallerStep::Run->url())
                ->with('installer_error', $failure->getMessage())
                ->with('installer_command', $failure->command);
        }

        $this->state->markDone($next);

        if ($warning !== null) {
            $this->rememberWarning($warning);
        }

        return $this->state->nextTask() === null
            ? $this->finish()
            : redirect()->to(InstallerStep::Run->url());
    }

    public function done(): View
    {
        return view('installer.done', [
            'step' => InstallerStep::Done,
            'summary' => $this->state->summary(),
        ]);
    }

    /**
     * Chiude l'installazione: riepilogo in sessione, `chmod 600` sul `.env`,
     * marcatore scritto. Da questo momento l'installer risponde 404 a chiunque
     * tranne che a questa sessione, e solo per la schermata finale.
     */
    private function finish(): RedirectResponse
    {
        $this->state->putSummary($this->buildSummary());
        $this->env->secure();

        try {
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
            $this->lock->write(is_string($latest) ? $latest : null);
        } catch (Throwable $exception) {
            Log::error('Installer: marcatore non scritto', ['errore' => $exception->getMessage()]);

            return redirect()->to(InstallerStep::Run->url())
                ->with('installer_error', __('installer.run.lock_failed', ['path' => $this->lock->path()]))
                ->with('installer_command', 'chmod -R 0775 storage');
        }

        $this->state->complete(InstallerStep::Run);
        $this->state->complete(InstallerStep::Done);
        $this->state->forgetSecrets();

        return redirect()->to(InstallerStep::Done->url());
    }

    /**
     * Cosa mostrare alla fine. Solo dati che si possono mostrare: le password
     * raccolte durante il wizard vengono buttate subito dopo.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(): array
    {
        $application = $this->state->get(InstallerStep::Application);
        $city = $this->state->get(InstallerStep::City);
        $database = $this->state->get(InstallerStep::Database);

        return [
            'app_name' => $application['app_name'] ?? '',
            'app_url' => $application['app_url'] ?? '',
            'mail_mailer' => $application['mail_mailer'] ?? 'log',
            'database' => $database['db_database'] ?? '',
            'city_name' => $city['name'] ?? '',
            'city_slug' => $city['slug'] ?? '',
            'admin_email' => $this->state->value(InstallerStep::Admin, 'email'),
            'image_driver' => $this->requirements->imageDriver(),
            'warnings' => $this->state->summary()['warnings'] ?? [],
        ];
    }

    private function rememberWarning(string $warning): void
    {
        $summary = $this->state->summary();
        $warnings = $summary['warnings'] ?? [];
        $warnings = is_array($warnings) ? $warnings : [];

        if (! in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }

        $summary['warnings'] = $warnings;
        $this->state->putSummary($summary);
    }

    private function advance(InstallerStep $step): RedirectResponse
    {
        $this->state->complete($step);

        $next = $step->next() ?? InstallerStep::Done;

        return redirect()->to($next->url());
    }
}
