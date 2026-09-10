<?php

declare(strict_types=1);

namespace App\Jobs\Social;

use App\Enums\SocialPublicationStatus as Status;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialStudio;
use App\Services\Social\TelegramPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PublishSocial implements ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $timeout = 45;

    public function __construct(public int $publicationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('social-'.$this->publicationId))->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(SocialPublisher $publisher): void
    {
        $publication = SocialPublication::findOrFail($this->publicationId);
        if (! in_array($publication->status, [Status::Queued, Status::Processing], true)) {
            return;
        }
        $connection = SocialConnection::findOrFail($publication->social_connection_id);
        $batch = SocialBatch::findOrFail($publication->social_batch_id);
        try {
            if ($publication->platform === 'telegram') {
                $publisher->assertFresh($batch);
                app(TelegramPublisher::class)->publish($publication, $connection, $batch);

                return;
            }
            if (! $connection->verified_at || ! $connection->{$publication->platform.'_enabled'}) {
                throw new \RuntimeException(__('social.not_connected'));
            }
            $publisher->assertFresh($batch);
            $publication->update(['status' => Status::Processing]);
            $ids = $publication->remote_ids ?? [];
            if (isset($ids['page_id']) && ($ids['page_id'] !== $connection->page_id || $ids['instagram_id'] !== $connection->instagram_id)) {
                throw new \RuntimeException(__('social.not_connected'));
            }
            $items = array_slice($batch->items, $publication->part * 10, 10);
            $caption = $batch->options['captions'][$publication->part];
            $instagram = $publication->platform === 'instagram';
            $account = $instagram ? $connection->instagram_id : $connection->page_id;
            $children = $ids['children'] ?? [];
            if (count($children) < count($items)) {
                $i = count($children);
                $url = SocialStudio::imageUrl($batch, $publication->part * 10 + $i);
                $params = $instagram ? ['image_url' => $url, 'is_carousel_item' => count($items) > 1] : ['url' => $url, 'published' => false];
                if ($instagram && count($items) === 1) {
                    $params['caption'] = $caption;
                }
                $response = $publisher->request($connection, 'post', $account.($instagram ? '/media' : '/photos'), $params);
                if (! isset($response['id'])) {
                    throw new \RuntimeException(__('social.verify_failed'));
                }
                $children[] = (string) $response['id'];
                $ids['children'] = $children;
                $publication->update(['remote_ids' => $ids]);
                $this->release(2);

                return;
            }
            if ($instagram) {
                foreach ($children as $child) {
                    $status = $publisher->request($connection, 'get', $child, ['fields' => 'status_code']);
                    if (in_array($status['status_code'] ?? '', ['ERROR', 'EXPIRED'], true)) {
                        throw new \RuntimeException(__('social.verify_failed'));
                    }
                    if (($status['status_code'] ?? '') !== 'FINISHED') {
                        $this->release(10);

                        return;
                    }
                }
                if (! isset($ids['container'])) {
                    $container = count($children) === 1 ? $children[0] : ($publisher->request($connection, 'post', $account.'/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $caption])['id'] ?? null);
                    if (! $container) {
                        throw new \RuntimeException(__('social.verify_failed'));
                    }
                    $ids['container'] = $container;
                    $publication->update(['remote_ids' => $ids]);
                    $this->release(5);

                    return;
                }
                $status = $publisher->request($connection, 'get', $ids['container'], ['fields' => 'status_code']);
                if (in_array($status['status_code'] ?? '', ['ERROR', 'EXPIRED'], true)) {
                    throw new \RuntimeException(__('social.verify_failed'));
                }
                if (($status['status_code'] ?? '') !== 'FINISHED') {
                    $this->release(10);

                    return;
                }
            }
            // Persist before the irreversible request: a timeout or killed worker must NOT publish twice.
            $publication->update(['status' => Status::Uncertain, 'error' => __('social.uncertain_help')]);
            $result = $publisher->request($connection, 'post', $account.($instagram ? '/media_publish' : '/feed'), $instagram ? ['creation_id' => $ids['container']] : ['message' => $caption, 'attached_media' => array_map(fn ($id) => ['media_fbid' => $id], $children)]);
            if (! isset($result['id'])) {
                return;
            }
            $publication->update(['status' => Status::Published, 'external_id' => (string) $result['id'], 'error' => null]);
        } catch (Throwable $e) {
            if ($publication->fresh()->status !== Status::Uncertain) {
                $publication->update(['status' => Status::Failed, 'error' => $e instanceof \RuntimeException ? $e->getMessage() : __('social.verify_failed')]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        SocialPublication::whereKey($this->publicationId)->whereIn('status', [Status::Queued->value, Status::Processing->value])->update(['status' => Status::Failed->value, 'error' => __('social.verify_failed')]);
    }
}
