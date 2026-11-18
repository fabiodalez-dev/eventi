// Scarica da Wikimedia Commons le fotografie di `demo:quindicina`, con licenza
// libera (CC BY, CC BY-SA, CC0, pubblico dominio) e crediti in credits.json.
//
//   node database/seeders/import-quindicina-images.cjs            completa ciò che manca
//   node database/seeders/import-quindicina-images.cjs jazz-trio  riscarica quella voce
//
// Le immagini vengono ridotte a 1200 px di lato lungo con `sips` (macOS) o
// `convert` (ImageMagick), così il repository e la quota dell'hosting non
// crescono più del necessario.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

// Una foto per ogni evento nuovo del catalogo…
const events = {
  'string-quartet': 'string quartet concert', 'novello-wine': 'glasses of red wine on table', 'standup-microphone': 'stand-up comedy microphone stage',
  'cinema-projector': '35mm film projector projection booth', 'fresh-pasta': 'homemade tagliatelle', 'indie-concert': 'singer guitar concert stage lights',
  'padova-portici': 'Padua arcades street', 'vinyl-records': 'vinyl records turntable', 'bookshop-reading': 'bookshop interior books',
  'antique-market': 'antique market stalls', 'kids-clay': 'children pottery class', 'rugby-match': 'rugby union match scrum',
  'techno-club': 'techno club dj crowd', 'padova-ghetto': 'Padova centro storico', 'bollito-misto': 'bollito misto',
  'puppet-theatre': 'puppet theatre glove puppets', 'darkroom': 'darkroom photography', 'jazz-trio': 'jazz club double bass guitar drums',
  'public-assembly': 'public meeting audience', 'theatre-comedy': 'commedia dell arte actors stage', 'women-march': 'women march protest',
  'poetry-reading': 'poetry reading microphone', 'pub-quiz': 'pub interior people tables', 'contemporary-dance': 'contemporary dance performance',
  'christmas-market': 'Christmas market stalls lights', 'gospel-choir': 'gospel choir singing', 'chess-tournament': 'chess tournament players',
  'roasted-chestnuts': 'roasted chestnuts', 'rock-band': 'acoustic concert band stage', 'planetarium': 'planetarium dome',
  'city-run': 'city marathon runners', 'church-organ': 'pipe organ church', 'cinema-hall': 'cinema auditorium seats screen',
  'sketchbook-drawing': 'urban sketching', 'old-university': 'Palazzo Bo Padova', 'karaoke-night': 'karaoke singing bar',
  'giotto-fresco': 'Cappella degli Scrovegni', 'community-dinner': 'community dinner long table', 'symphony-orchestra': 'symphony orchestra concert hall',
  'climbing-gym': 'indoor climbing wall', 'farmers-market': 'farmers market vegetables stall',
};

// …e tre per categoria per le date nuove degli eventi già esistenti, così
// due eventi della stessa sera non mostrano la stessa locandina.
const pool = {
  'musica-dal-vivo': ['live music pub band', 'saxophone player concert', 'folk music concert'],
  'dj-set-nightlife': ['dj mixer club', 'dance floor party lights', 'cocktail bar night'],
  'teatro-e-danza': ['theatre stage performance', 'ballet dancers stage', 'theatre audience red seats'],
  cinema: ['outdoor cinema screen', 'film reel', 'cinema ticket box office'],
  'arte-e-mostre': ['museum gallery paintings visitors', 'sculpture exhibition', 'contemporary art installation'],
  'libri-e-presentazioni': ['library reading room', 'second hand books stall', 'book presentation author'],
  'politica-e-attivismo': ['protest march banners', 'climate march', 'bicycle demonstration'],
  sport: ['volleyball match indoor', 'cycling race road', 'football match amateur'],
  'food-e-sagre': ['Italian food festival', 'polenta cooking', 'spritz aperitivo'],
  mercatini: ['flea market', 'handicraft market', 'Christmas market wooden stalls'],
  'corsi-e-workshop': ['pottery wheel workshop', 'cooking class', 'painting class easel'],
  'bambini-e-famiglie': ['children playground', 'children reading books library', 'face painting children'],
  'comunita-e-assemblee': ['volunteers community garden', 'neighbourhood festival street', 'meeting room chairs'],
  altro: ['Prato della Valle', 'Padova Orto botanico', 'Padova Palazzo della Ragione'],
};
const subjects = {...events};
for (const [category, queries] of Object.entries(pool)) queries.forEach((q, i) => { subjects[`pool-${category}-${i + 1}`] = q; });

const directory = path.join(__dirname, 'quindicina-media');
fs.mkdirSync(directory, {recursive: true});
const manifestPath = path.join(directory, 'credits.json');
const manifest = fs.existsSync(manifestPath) ? JSON.parse(fs.readFileSync(manifestPath, 'utf8')) : {};
for (const slug of process.argv.slice(2)) delete manifest[slug];
const used = new Set(Object.values(manifest).map((m) => m.source));
const strip = (html) => String(html ?? '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

const sleep = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);

// Wikimedia risponde 429 a chi chiede troppo in fretta: si aspetta e si riprova.
function fetchWithBackoff(args) {
  for (let attempt = 0; ; attempt++) {
    try { return execFileSync('curl', ['-fLsS', '-A', 'incitta-demo-import/1.0 (demo catalogue import)', ...args], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']}); }
    catch (error) {
      if (attempt >= 5 || !/429|503/.test(String(error.stderr))) throw error;
      sleep(15000 * (attempt + 1));
    }
  }
}

function resize(file) {
  try { execFileSync('sips', ['-Z', '1200', '-s', 'formatOptions', '78', file], {stdio: 'ignore'}); return; } catch {}
  execFileSync('convert', [file, '-resize', '1200x1200>', '-quality', '78', file]);
}

// Prima Openverse (Flickr e altri archivi con licenza libera, ricerca più pertinente),
// poi Wikimedia Commons come riserva.
function fromOpenverse(slug, subject) {
  const params = new URLSearchParams({q: subject, license_type: 'commercial,modification', aspect_ratio: 'wide', size: 'large,medium', page_size: '12', mature: 'false'});
  let data;
  try { data = JSON.parse(fetchWithBackoff(['--max-time', '30', 'https://api.openverse.org/v1/images/?' + params])); } catch { return null; }
  for (const r of data.results ?? []) {
    const license = `CC ${String(r.license).toUpperCase()} ${r.license_version ?? ''}`.replace('CC CC0', 'CC0').replace('CC PDM', 'Public domain').trim();
    if (!r.url || used.has(r.foreign_landing_url) || /NC|ND/.test(license) || (r.width && r.width < 900)) continue;
    const file = path.join(directory, slug + '.jpg');
    try { fetchWithBackoff(['--max-time', '60', r.url, '-o', file]); execFileSync('sips', ['-s', 'format', 'jpeg', file, '--out', file], {stdio: 'ignore'}); resize(file); } catch { continue; }
    return {file: slug + '.jpg', title: r.title ?? subject, source: r.foreign_landing_url, image: r.url, author: r.creator || r.source || 'Openverse',
      license, license_url: r.license_url ?? '', description: subject};
  }
  return null;
}

function fromWikimedia(slug, subject) {
  const params = new URLSearchParams({action: 'query', generator: 'search', gsrsearch: `${subject} filetype:bitmap`, gsrnamespace: '6', gsrlimit: '15',
    prop: 'imageinfo', iiprop: 'url|extmetadata|size', iiurlwidth: '1400', format: 'json'});
  const data = JSON.parse(fetchWithBackoff(['--max-time', '30', 'https://commons.wikimedia.org/w/api.php?' + params]));
  const candidates = Object.values(data.query?.pages ?? {}).sort((a, b) => a.index - b.index);
  for (const page of candidates) {
    const info = page.imageinfo?.[0]; const meta = info?.extmetadata;
    const license = meta?.LicenseShortName?.value ?? '';
    if (!info?.thumburl || !/\.jpe?g$/i.test(page.title) || used.has(info.descriptionurl)) continue;
    if (!/^(CC BY|CC0|Public domain|PD)/i.test(license) || /NC|ND/.test(license)) continue;
    if (info.width < 900 || info.width < info.height) continue; // orizzontali e abbastanza grandi per la locandina
    const file = path.join(directory, slug + '.jpg');
    try { fetchWithBackoff(['--max-time', '60', info.thumburl, '-o', file]); resize(file); } catch { continue; }
    return {file: slug + '.jpg', title: page.title.replace(/^File:/, ''), source: info.descriptionurl, image: info.thumburl,
      author: strip(meta.Artist?.value) || 'Wikimedia Commons', license, license_url: meta.LicenseUrl?.value ?? '', description: strip(meta.ImageDescription?.value).slice(0, 300)};
  }
  return null;
}

for (const [slug, subject] of Object.entries(subjects)) {
  if (manifest[slug]) continue;
  const found = fromOpenverse(slug, subject) ?? fromWikimedia(slug, subject);
  if (found) {
    manifest[slug] = found;
    used.add(found.source);
    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
    console.log(slug.padEnd(34), found.license.padEnd(14), found.title);
  } else {
    console.error('NESSUNA FOTO: ' + slug + ' (' + subject + ')');
  }
  sleep(3000);
}
