<?php

declare(strict_types=1);
use App\Models\User;
use App\Services\Carpool\CarpoolService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.mysql.database');
if (! $app->environment('testing') || ! preg_match('/(?:^|_)test(?:_|$)/', $database)) {
    fwrite(STDERR, "Refusing a non-test database\n");
    exit(2);
}
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
Carbon::setTestNow($payload['now']);
CarbonImmutable::setTestNow($payload['now']);
echo "READY\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(3);
}
try {
    $user = User::findOrFail($payload['user_id']);
    if ($payload['action'] === 'revoke_phone') {
        $user->forceFill(['whatsapp_verified_at' => null])->save();
        $result = ['id' => $user->id];
    } else {
        $result = app(CarpoolService::class)->execute($user, $payload['action'], $payload['data']);
    }
    echo json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR)."\n";
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
}
