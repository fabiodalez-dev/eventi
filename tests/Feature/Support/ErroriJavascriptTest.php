<?php

declare(strict_types=1);

/**
 * Il filtro degli errori JavaScript delle prove nel browser.
 *
 * Serve una prova perché il filtro è un compromesso: toglie di mezzo una
 * notifica che il browser manda sui tempi degli osservatori di
 * ridimensionamento, e un filtro scritto largo nasconderebbe guasti veri. Qui
 * si verifica che sia stretto come deve: passa quella riga esatta e nient'altro.
 */
it('toglie solo la nota di ResizeObserver e tiene gli errori veri', function (): void {
    $pagina = new class
    {
        public function page(): object
        {
            return new class
            {
                /** @return list<array<string, string>> */
                public function javaScriptErrors(): array
                {
                    return [
                        ['message' => 'ResizeObserver loop completed with undelivered notifications'],
                        ['message' => 'TypeError: undefined is not a function'],
                        ['message' => 'ResizeObserver loop limit exceeded'],
                    ];
                }
            };
        }
    };

    $rimasti = array_column(erroriJavascriptVeri($pagina), 'message');

    expect($rimasti)->toBe([
        'TypeError: undefined is not a function',
        // Un messaggio simile ma diverso resta: il filtro non lavora per somiglianza.
        'ResizeObserver loop limit exceeded',
    ]);
});

it('non toglie niente quando non c’è niente da togliere', function (): void {
    $pagina = new class
    {
        public function page(): object
        {
            return new class
            {
                /** @return list<array<string, string>> */
                public function javaScriptErrors(): array
                {
                    return [];
                }
            };
        }
    };

    expect(erroriJavascriptVeri($pagina))->toBe([]);
});
