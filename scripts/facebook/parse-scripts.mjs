import { collectEventNodes, parseEventNodes, facebookEventId } from './event-parser.mjs';

try {
    if (process.argv[2] === '--check') {
        const data = parseEventNodes(collectEventNodes([JSON.stringify({ id: '1', name: 'Runtime check', event_description: { text: 'HTTP parser check' } })], '1'), '1');
        if (data.title !== 'Runtime check' || data.description !== 'HTTP parser check') throw new Error('Parser check failed.');
        process.stdout.write(JSON.stringify({ http_parser: true }));
    } else {
        const id = facebookEventId(process.argv[2]);
        let size = 0;
        const chunks = [];
        for await (const chunk of process.stdin) {
            size += chunk.length;
            if (size > 24 * 1024 * 1024) throw new Error('Input exceeds the parser limit.');
            chunks.push(chunk);
        }
        const scripts = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        if (!Array.isArray(scripts) || scripts.length > 512 || scripts.some(script => typeof script !== 'string')) throw new Error('Invalid script data.');
        const output = JSON.stringify(parseEventNodes(collectEventNodes(scripts, id), id));
        if (Buffer.byteLength(output) > 1024 * 1024) throw new Error('Output exceeds the parser limit.');
        process.stdout.write(output);
    }
} catch (error) {
    process.stderr.write(error.message + '\n');
    process.exitCode = 1;
}
