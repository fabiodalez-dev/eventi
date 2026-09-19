<?php

declare(strict_types=1);

namespace App\Http\Controllers\Carpool;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carpool\CarpoolEvidenceRequest;
use App\Models\CarpoolCase;
use App\Services\Carpool\CommunitySafety;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarpoolEvidenceController extends Controller
{
    public function __invoke(CarpoolEvidenceRequest $request, CarpoolCase $case, CommunitySafety $safety): View|StreamedResponse
    {
        $result = $safety->inspect($request->user(), $case, $request->validated('reason'), $request->boolean('export'));
        if ($request->boolean('export')) {
            return response()->streamDownload(static function () use ($result): void {
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, 'case-'.$case->id.'-'.now()->format('Ymd-His').'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return view('carpool.evidence', ['evidence' => $result]);
    }
}
