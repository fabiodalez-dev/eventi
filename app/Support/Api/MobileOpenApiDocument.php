<?php

declare(strict_types=1);

namespace App\Support\Api;

use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

final class MobileOpenApiDocument
{
    public function __invoke(OpenApi $document): void
    {
        $schemas = $this->schemas($document);

        foreach ($document->components->schemas as $schema) {
            $this->sanitize($schema->type);
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $this->documentOperation($operation, $path->path, $schemas);
                $this->ticketingOperation($operation, $path->path, $document);
            }
        }
    }

    /** The shared web/API controller also returns redirects, so specify its JSON contract explicitly. */
    private function ticketingOperation(Operation $operation, string $path, OpenApi $document): void
    {
        if (! in_array($path, ['occurrences/{occurrence}/booking', 'occurrences/{occurrence}/bookings', 'me/bookings', 'me/bookings/{booking}/cancel', 'me/bookings/{booking}/email', 'ticketing/{occurrence}/check-in'], true)) {
            return;
        }

        $text = fn (): StringType => new StringType;
        $time = fn (): StringType => (new StringType)->format('date-time')->nullable(true);
        $ticket = (new ObjectType)
            ->addProperty('id', new IntegerType)->addProperty('attendee_name', $text())
            ->addProperty('first_name', $text()->nullable(true))->addProperty('last_name', $text()->nullable(true))
            ->addProperty('status', $text())->addProperty('qr_payload', $text()->nullable(true))
            ->addProperty('checked_in_at', $time())
            ->setRequired(['id', 'attendee_name', 'status', 'qr_payload', 'checked_in_at']);
        $booking = (new ObjectType)
            ->addProperty('id', new IntegerType)->addProperty('occurrence_id', new IntegerType)
            ->addProperty('title', $text())->addProperty('event_slug', $text()->nullable(true))
            ->addProperty('venue', $text()->nullable(true))->addProperty('address', $text()->nullable(true))
            ->addProperty('starts_at', $time())->addProperty('status', $text())
            ->addProperty('instructions', $text()->nullable(true))->addProperty('cancellation_reason', $text()->nullable(true))
            ->addProperty('booker', (new ObjectType)->additionalProperties(new StringType)->nullable(true))
            ->addProperty('privacy_accepted_at', $time())
            ->addProperty('can_cancel', new BooleanType)->addProperty('tickets', (new ArrayType)->setItems($ticket))
            ->setRequired(['id', 'occurrence_id', 'title', 'event_slug', 'venue', 'address', 'starts_at', 'status', 'instructions', 'cancellation_reason', 'can_cancel', 'tickets']);
        $bookingRef = $document->components->addSchema('MobileBooking', Schema::fromType($booking));
        $availability = (new ObjectType)
            ->addProperty('enabled', new BooleanType)->addProperty('open', new BooleanType)
            ->addProperty('capacity', (new IntegerType)->nullable(true))->addProperty('remaining', (new IntegerType)->nullable(true))
            ->addProperty('limit_per_account', new IntegerType)->addProperty('waitlist', new BooleanType)
            ->addProperty('opens_at', $time())->addProperty('closes_at', $time())
            ->addProperty('cancellation_closes_at', $time())->addProperty('instructions', $text()->nullable(true))
            ->addProperty('booker_fields', (new ArrayType)->setItems((new ObjectType)->addProperty('key', $text())->addProperty('label', $text())->addProperty('required', new BooleanType)->setRequired(['key', 'label', 'required'])))
            ->addProperty('privacy_url', $text()->format('uri'))
            ->setRequired(['enabled', 'open', 'capacity', 'remaining', 'limit_per_account', 'waitlist', 'opens_at', 'closes_at', 'cancellation_closes_at', 'instructions']);
        $data = match ($path) {
            'occurrences/{occurrence}/booking' => $availability,
            'me/bookings' => (new ArrayType)->setItems($bookingRef),
            'me/bookings/{booking}/email' => (new ObjectType)->addProperty('message', $text())->setRequired(['message']),
            'ticketing/{occurrence}/check-in' => (new ObjectType)->addProperty('id', new IntegerType)->addProperty('attendee_name', $text())->addProperty('status', $text())->setRequired(['id', 'attendee_name', 'status']),
            default => $bookingRef,
        };
        $body = (new ObjectType)->addProperty('data', $data)->setRequired(['data']);
        if ($path === 'me/bookings') {
            $body->addProperty('meta', (new ObjectType)->addProperty('next_page', (new IntegerType)->nullable(true))->setRequired(['next_page']))->setRequired(['data', 'meta']);
        }
        $operation->responses = array_values(array_filter($operation->responses, fn ($response): bool => ! $response instanceof Response || (int) $response->code < 200 || (int) $response->code >= 300));
        $operation->addResponse(Response::make($path === 'occurrences/{occurrence}/bookings' ? 201 : 200)
            ->setContent('application/json', Schema::fromType($body))
            ->addHeader('Cache-Control', new Header(schema: Schema::fromType($text()))));
        if ($path !== 'occurrences/{occurrence}/booking') {
            $operation->security = [new SecurityRequirement(['http' => []])];
        }
    }

    /** @return array<string, Reference> */
    private function schemas(OpenApi $document): array
    {
        $components = $document->components;
        $genericObject = fn (): ObjectType => (new ObjectType)->additionalProperties(new MixedType);

        $venue = (new ObjectType)
            ->addProperty('id', new IntegerType)
            ->addProperty('slug', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('municipality', new StringType)
            ->addProperty('zone', (new StringType)->nullable(true))
            ->addProperty('lat', new NumberType)
            ->addProperty('lng', new NumberType)
            ->addProperty('cover', $genericObject()->nullable(true))
            ->setRequired(['id', 'slug', 'name', 'municipality', 'lat', 'lng']);
        $venueRef = $components->addSchema('MobileVenue', Schema::fromType($venue));

        $occurrence = (new ObjectType)
            ->addProperty('occurrence_id', new IntegerType)
            ->addProperty('event_id', new IntegerType)
            ->addProperty('event_slug', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('starts_at', (new StringType)->format('date-time'))
            ->addProperty('ends_at', (new StringType)->format('date-time')->nullable(true))
            ->addProperty('business_date', (new StringType)->format('date'))
            ->addProperty('status', new StringType)
            ->addProperty('booking_enabled', new BooleanType)
            ->addProperty('poster', $genericObject()->nullable(true))
            ->addProperty('venue', (clone $venueRef)->nullable(true))
            ->addProperty('category', $genericObject()->nullable(true))
            ->addProperty('price', $genericObject())
            ->addProperty('sponsored', $genericObject()->nullable(true))
            ->addProperty('actions', $genericObject())
            ->addProperty('content_updated_at', (new StringType)->format('date-time')->nullable(true))
            ->addProperty('updated_at', (new StringType)->format('date-time')->nullable(true))
            ->setRequired([
                'occurrence_id', 'event_id', 'event_slug', 'title', 'starts_at',
                'business_date', 'status', 'booking_enabled', 'poster', 'venue', 'category', 'price',
                'sponsored', 'actions', 'content_updated_at', 'updated_at',
            ]);
        $occurrenceRef = $components->addSchema('MobileOccurrence', Schema::fromType($occurrence));

        $event = (new ObjectType)
            ->addProperty('id', new IntegerType)
            ->addProperty('slug', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('description', (new StringType)->nullable(true))
            ->addProperty('poster', $genericObject()->nullable(true))
            ->addProperty('venue', (clone $venueRef)->nullable(true))
            ->addProperty('sponsored', $genericObject()->nullable(true))
            ->addProperty('occurrences', (new ArrayType)->setItems($occurrenceRef))
            ->addProperty('url', (new StringType)->nullable(true))
            ->setRequired(['id', 'slug', 'title', 'poster', 'venue', 'sponsored', 'occurrences', 'url']);
        $eventRef = $components->addSchema('MobileEvent', Schema::fromType($event));

        $simple = [];

        foreach (['Notification', 'Follow', 'Device', 'Session', 'Sponsorship'] as $name) {
            $simple[$name] = $components->addSchema('Mobile'.$name, Schema::fromType($genericObject()));
        }

        $error = (new ObjectType)
            ->addProperty('code', new StringType)
            ->addProperty('message', new StringType)
            ->addProperty('fields', $genericObject())
            ->setRequired(['code', 'message', 'fields']);
        $errorEnvelope = (new ObjectType)->addProperty('error', $error)->setRequired(['error']);

        return [
            'occurrence' => $occurrenceRef,
            'event' => $eventRef,
            'venue' => $venueRef,
            'notification' => $simple['Notification'],
            'follow' => $simple['Follow'],
            'device' => $simple['Device'],
            'session' => $simple['Session'],
            'sponsorship' => $simple['Sponsorship'],
            'error' => $components->addSchema('ApiErrorEnvelope', Schema::fromType($errorEnvelope)),
        ];
    }

    /** @param array<string, Reference> $schemas */
    private function documentOperation(Operation $operation, string $path, array $schemas): void
    {
        $liveAvailability = $path === 'occurrences/{occurrence}/booking';
        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response) {
                continue;
            }

            foreach ($response->content as $schema) {
                $this->sanitize($schema->type);
            }

            if (in_array((string) $response->code, ['200', '201'], true)) {
                $this->replaceDataSchema($response, $path, $schemas);
            }

            if ($operation->method === 'get' && (string) $response->code === '200') {
                $response->addHeader('X-RateLimit-Limit', new Header(schema: Schema::fromType(new IntegerType)));
                $response->addHeader('X-RateLimit-Remaining', new Header(schema: Schema::fromType(new IntegerType)));

                if (! str_starts_with($path, 'me/') && ! $liveAvailability) {
                    $response->addHeader('ETag', new Header(schema: Schema::fromType(new StringType)));
                    $response->addHeader('Cache-Control', new Header(schema: Schema::fromType(new StringType)));
                }
            }
        }

        $this->addErrorResponse($operation, 429, 'Troppe richieste.', $schemas['error']);

        if ($operation->method === 'get' && ! str_starts_with($path, 'me/') && ! $liveAvailability) {
            $this->addEmptyResponse($operation, 304, 'Contenuto non modificato.');
        }

        if ($operation->method === 'get' && ! str_starts_with($path, 'auth/') && ! str_starts_with($path, 'me/')) {
            $operation->security = [new SecurityRequirement([]), new SecurityRequirement(['http' => []])];
        }
    }

    /** @param array<string, Reference> $schemas */
    private function replaceDataSchema(Response $response, string $path, array $schemas): void
    {
        $schema = $response->content['application/json'] ?? null;

        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
            return;
        }

        $data = $schema->type->properties['data'] ?? null;
        $resource = $this->resourceForPath($path, $schemas);

        if ($resource === null) {
            return;
        }

        if ($data instanceof ArrayType) {
            $data->setItems($resource);
        } elseif ($data instanceof Type) {
            $schema->type->properties['data'] = $resource;
        }
    }

    /** @param array<string, Reference> $schemas */
    private function resourceForPath(string $path, array $schemas): ?Reference
    {
        return match (true) {
            $path === 'events',
            preg_match('#^events/\{slug\}/(similar|occurrences)$#', $path) === 1,
            preg_match('#^venues/\{slug\}/(events|past)$#', $path) === 1,
            in_array($path, ['me/saved', 'me/feed', 'sync'], true) => $schemas['occurrence'],
            $path === 'events/{slug}' => $schemas['event'],
            $path === 'occurrences/{occurrence}' => $schemas['occurrence'],
            in_array($path, ['venues', 'venues/{slug}'], true) => $schemas['venue'],
            $path === 'me/notifications' => $schemas['notification'],
            $path === 'me/follows' => $schemas['follow'],
            $path === 'me/devices' => $schemas['device'],
            $path === 'me/sessions' => $schemas['session'],
            $path === 'sponsorships' => $schemas['sponsorship'],
            default => null,
        };
    }

    private function sanitize(Type $type): void
    {
        if ($type instanceof ObjectType) {
            unset($type->properties['']);
            $type->required = array_values(array_filter(
                $type->required,
                static fn (?string $name): bool => (string) $name !== '' && array_key_exists((string) $name, $type->properties),
            ));

            foreach ($type->properties as $property) {
                if ($property instanceof Type) {
                    $this->sanitize($property);
                }
            }
        }

        if ($type instanceof ArrayType) {
            $this->sanitize($type->items);
        }
    }

    private function addErrorResponse(Operation $operation, int $code, string $description, Reference $schema): void
    {
        if (! $this->hasResponse($operation, $code)) {
            $operation->addResponse(Response::make($code)->setDescription($description)->setContent('application/json', $schema));
        }
    }

    private function addEmptyResponse(Operation $operation, int $code, string $description): void
    {
        if (! $this->hasResponse($operation, $code)) {
            $operation->addResponse(Response::make($code)->setDescription($description));
        }
    }

    private function hasResponse(Operation $operation, int $code): bool
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === (string) $code) {
                return true;
            }
        }

        return false;
    }
}
