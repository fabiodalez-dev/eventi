<?php

use App\Models\Event;
use App\Support\Description;
use App\Support\EditorContent;
use Illuminate\Validation\ValidationException;

it('preserves useful formatting but removes executable markup and CSS', function (): void {
    $html = '<p style="position:fixed" class="evil" onclick="alert(1)"><strong>Grassetto</strong> <em>Corsivo</em></p><ul><li>Voce</li></ul><a href="https://example.com">Link</a>';
    $html .= '<script>alert(1)</script><style>body{display:none}</style><iframe srcdoc="evil"></iframe><svg onload="alert(1)"></svg><img src=x onerror="alert(1)"><a href="javascript:alert(1)">Male</a>';
    $safe = Description::sanitize($html);
    expect($safe)->toContain('<strong>Grassetto</strong>', '<em>Corsivo</em>', '<ul>', 'href="https://example.com"')
        ->not->toContain('style=', 'class=', 'onclick', '<script', '<style', '<iframe', '<svg', '<img', 'javascript:');
});

it('sanitizes descriptions at model save even when bypassing the form', function (): void {
    $date = occurrenceAtLocal(testCity(), testCategory(), '2026-09-20 21:00:00');
    $date->event->update([
        'title' => '<b>Nome</b><script>alert(1)</script>',
        'description' => '<p style="color:red"><strong>Testo</strong><script>alert(1)</script></p>',
    ]);
    $event = $date->event->fresh();
    expect($event->title)->toBe('Nome')
        ->and($event->description)->toContain('<strong>Testo</strong>')->not->toContain('style=', '<script');
});

it('cleans nested editorial fields according to their purpose', function (): void {
    expect(EditorContent::clean('seo', ['description' => '<b>Riassunto</b>']))->toBe(['description' => 'Riassunto'])
        ->and(EditorContent::clean('content_details', ['faqs' => [['question' => '<b>Domanda</b>', 'answer' => '<strong>Risposta</strong><style>x</style>']]]))
        ->toBe(['faqs' => [['question' => 'Domanda', 'answer' => '<strong>Risposta</strong>']]]);
});

it('rejects unsafe URL schemes', function (string $url): void {
    EditorContent::clean('website', $url);
})->with(['javascript:alert(1)', 'data:text/html,evil', 'file:///etc/passwd'])->throws(ValidationException::class);

it('treats SQL fragments as literal text without executing them', function (): void {
    $date = occurrenceAtLocal(testCity(), testCategory(), '2026-09-20 21:00:00');
    $text = "Locale d'arte'; DROP TABLE events; --";
    $date->event->update(['title' => $text]);
    expect($date->event->fresh()->title)->toBe($text)->and(Event::find($date->event_id))->not->toBeNull();
});

it('escapes legacy plain text and sanitizes legacy HTML on display', function (): void {
    expect((string) Description::render("Uno & due\nTre"))->toContain('Uno &amp; due', '<br')
        ->and((string) Description::render('<p onclick="evil()">Bene</p><script>evil()</script>'))->toBe('<p>Bene</p>');
});

it('removes encoded protocols, CSS and active elements', function (string $payload): void {
    $safe = Description::sanitize($payload);
    expect($safe)->not->toMatch('/javascript:|data:|<script|<style|<svg|<math|<iframe|onerror=|onload=|style=|srcdoc=/i');
})->with([
    '<a href="java&#x73;cript:alert(1)">Link</a>',
    '<a href="java&#10;script:alert(1)">Link</a>',
    '<a href="data:text/html;base64,PHNjcmlwdD4=">Link</a>',
    '<p style="background:url(javascript:alert(1))">Testo</p>',
    '<svg><a xlink:href="javascript:alert(1)">X</a></svg>',
    '<math><mtext><img src=x onerror=alert(1)></mtext></math>',
    '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
]);

it('keeps long permitted text without silently truncating it', function (): void {
    $text = str_repeat('a', 40000);
    expect(Description::sanitize('<p>'.$text.'</p>'))->toBe('<p>'.$text.'</p>');
});

it('exports rich descriptions as readable plain text for existing clients', function (): void {
    expect(Description::plain('<p>Uno &amp; <strong>due</strong></p><p>Tre</p>'))->toBe("Uno & due\nTre")
        ->and(Description::plain(null))->toBeNull();
});

it('does not persist unknown editorial or SEO keys', function (): void {
    expect(EditorContent::clean('content_details', ['introduction' => 'Testo', 'script' => 'evil()', 'style' => 'body{}']))
        ->toBe(['introduction' => 'Testo'])
        ->and(EditorContent::clean('seo', ['title' => 'Titolo', 'code' => 'evil()']))->toBe(['title' => 'Titolo']);
});
