<?php

namespace App\Console\Commands;

use App\Enums\SocialPublicationStatus as Status;
use App\Jobs\Social\PublishSocial;
use App\Models\SocialPublication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishDueSocial extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Accoda i post social che hanno raggiunto la data di pubblicazione';

    public function handle(): int
    {
        SocialPublication::where('status', Status::Scheduled)->where('scheduled_at', '<=', now())->chunkById(100, function ($publications): void {
            foreach ($publications as $publication) {
                DB::transaction(function () use ($publication): void {
                    $changed = SocialPublication::whereKey($publication->id)->where('status', Status::Scheduled)
                        ->where('scheduled_at', '<=', now())->update(['status' => Status::Queued->value]);
                    if ($changed) {
                        PublishSocial::dispatch($publication->id)->afterCommit();
                    }
                });
            }
        });

        return self::SUCCESS;
    }
}
