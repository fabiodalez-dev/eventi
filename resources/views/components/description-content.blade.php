@props(['text'])
<div class="max-w-prose space-y-3 [overflow-wrap:anywhere] [&_a]:underline [&_a]:text-accent [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:font-bold [&_blockquote]:italic">
    {{ \App\Support\Description::render($text) }}
</div>
