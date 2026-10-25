<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\BookingFieldMode;
use App\Enums\BookingQuestionType;
use App\Models\EventOccurrence;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class BookingForm
{
    public const FIELDS = ['phone', 'address', 'city', 'postal_code', 'country'];

    public const PRIVACY_VERSION = 'ticketing-2026-09-05';

    public const MAX_QUESTIONS = 5;

    public const MAX_OPTIONS = 10;

    public const ANSWER_MAX = 500;

    /** Giorni dopo la data oltre i quali le risposte si cancellano. */
    public const ANSWERS_RETENTION_DAYS = 30;

    /** @return list<array{key: string, label: string, required: bool}> */
    public function fields(EventOccurrence $date): array
    {
        $fields = [];
        foreach (self::FIELDS as $key) {
            $mode = BookingFieldMode::tryFrom($date->booking_fields[$key] ?? '') ?? BookingFieldMode::Hidden;
            if ($mode !== BookingFieldMode::Hidden) {
                $fields[] = ['key' => $key, 'label' => __('ticketing.fields.'.$key), 'required' => $mode === BookingFieldMode::Required];
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    public function validate(EventOccurrence $date, array $input): array
    {
        // Kept compatible with the old APK only when no extra data is required.
        // Never guess how an existing full name should be split.
        $rules = [];
        if ($input !== []) {
            $rules = ['first_name' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'last_name' => ['required', 'string', 'max:120', 'regex:/\S/u']];
        }
        $labels = ['first_name' => __('ticketing.fields.first_name'), 'last_name' => __('ticketing.fields.last_name')];
        foreach ($this->fields($date) as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:255', 'regex:/\S/u'];
            $labels[$field['key']] = $field['label'];
        }
        $validated = Validator::make($input, $rules, [], $labels)->validate();

        return array_map(fn ($value) => is_string($value) ? trim($value) : null, $validated);
    }

    /**
     * Le domande del gestore per questa data, nella forma che vedono il sito
     * e l'app. Le righe malformate rimaste nel database si saltano invece di
     * rompere il modulo di chi prenota.
     *
     * @return list<array{id: string, label: string, type: string, options: list<string>, required: bool}>
     */
    public function questions(EventOccurrence $date): array
    {
        $questions = [];
        foreach ((array) ($date->booking_questions ?? []) as $question) {
            $type = is_array($question) ? BookingQuestionType::tryFrom((string) ($question['type'] ?? '')) : null;
            if ($type === null || ! is_string($question['id'] ?? null) || ! is_string($question['label'] ?? null)) {
                continue;
            }
            $options = $type === BookingQuestionType::Choice ? array_values(array_filter((array) ($question['options'] ?? []), 'is_string')) : [];
            if ($type === BookingQuestionType::Choice && count($options) < 2) {
                continue;
            }
            $questions[] = ['id' => $question['id'], 'label' => $question['label'], 'type' => $type->value, 'options' => $options, 'required' => (bool) ($question['required'] ?? false)];
        }

        return array_slice($questions, 0, self::MAX_QUESTIONS);
    }

    /**
     * Le domande come le scrive il gestore nel pannello: righe senza testo si
     * ignorano, le scelte arrivano una per riga, un identificativo già dato si
     * conserva così che le risposte vecchie restino legate alla loro domanda.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array{id: string, label: string, type: string, options: list<string>, required: bool}>
     */
    public function normalizeQuestions(array $rows): array
    {
        $questions = [];
        $seen = [];
        foreach ($rows as $row) {
            $label = is_array($row) ? trim((string) ($row['label'] ?? '')) : '';
            if ($label === '') {
                continue;
            }
            $type = BookingQuestionType::from((string) $row['type']);
            $options = $type === BookingQuestionType::Choice
                ? array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string) ($row['options'] ?? '')) ?: []), fn (string $option): bool => $option !== '')))
                : [];
            $id = is_string($row['id'] ?? null) && preg_match('/^q[a-z0-9]{8}$/', $row['id']) && ! isset($seen[$row['id']]) ? $row['id'] : 'q'.Str::lower(Str::random(8));
            $seen[$id] = true;
            $questions[] = ['id' => $id, 'label' => $label, 'type' => $type->value, 'options' => $options, 'required' => (bool) ($row['required'] ?? false)];
        }
        Validator::make(['questions' => $questions], [
            'questions' => ['array', 'max:'.self::MAX_QUESTIONS],
            'questions.*.label' => ['string', 'max:160'],
            'questions.*.options' => ['array', 'max:'.self::MAX_OPTIONS],
            'questions.*.options.*' => ['string', 'max:80'],
        ], [], ['questions' => __('ticketing.questions.title'), 'questions.*.label' => __('ticketing.questions.label'), 'questions.*.options' => __('ticketing.questions.options')])->after(function ($validator) use ($questions): void {
            foreach ($questions as $index => $question) {
                if ($question['type'] === BookingQuestionType::Choice->value && count($question['options']) < 2) {
                    $validator->errors()->add('booking_questions.'.$index.'.options', __('ticketing.questions.two_options'));
                }
            }
        })->validate();

        return $questions;
    }

    /**
     * Le risposte di chi prenota, controllate sulle domande di adesso. Si
     * salvano con il testo della domanda, così restano leggibili anche se il
     * gestore la cambia o la toglie dopo.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{id: string, question: string, answer: string}>
     */
    public function answers(EventOccurrence $date, array $input): array
    {
        $questions = $this->questions($date);
        $rules = [];
        $labels = [];
        foreach ($questions as $question) {
            $rules[$question['id']] = [$question['required'] ? 'required' : 'nullable', ...match ($question['type']) {
                BookingQuestionType::Choice->value => ['string', Rule::in($question['options'])],
                BookingQuestionType::YesNo->value => ['boolean'],
                default => ['string', 'max:'.self::ANSWER_MAX],
            }];
            $labels[$question['id']] = $question['label'];
        }
        $validated = Validator::make($input, $rules, [], $labels)->validate();
        $answers = [];
        foreach ($questions as $question) {
            $value = $validated[$question['id']] ?? null;
            if ($question['type'] === BookingQuestionType::YesNo->value) {
                $value = $value === null || $value === '' ? null : __(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'ticketing.questions.yes' : 'ticketing.questions.no');
            }
            $value = is_string($value) ? trim($value) : null;
            if ($value !== null && $value !== '') {
                $answers[] = ['id' => $question['id'], 'question' => $question['label'], 'answer' => $value];
            }
        }

        return $answers;
    }
}
