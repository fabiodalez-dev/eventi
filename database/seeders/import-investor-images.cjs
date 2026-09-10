// Import reusable, attributed category illustrations from Wikimedia Commons.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const subjects = {
 'musica-dal-vivo':'jazz concert instruments', 'dj-set-nightlife':'DJ turntable',
 'teatro-e-danza':'theatre stage curtain', cinema:'"Cinema Seats I"',
 'arte-e-mostre':'art gallery exhibition interior', 'libri-e-presentazioni':'books library shelves',
 'politica-e-attivismo':'bicycle urban street', sport:'basketball court',
 'food-e-sagre':'Italian food pasta', mercatini:'flea market',
 'corsi-e-workshop':'pottery workshop', 'bambini-e-famiglie':'board games table',
 'comunita-e-assemblee':'meeting room chairs', altro:'Padua botanical garden',
};
const directory=path.join(__dirname,'investor-media'); fs.mkdirSync(directory,{recursive:true});
const manifestPath=path.join(directory,'credits.json');
const manifest=fs.existsSync(manifestPath)?JSON.parse(fs.readFileSync(manifestPath,'utf8')):{};
for (const slug of process.argv.slice(2)) delete manifest[slug];
for(const [slug,subject] of Object.entries(subjects)) {
 if(manifest[slug]) continue;
 const params=new URLSearchParams({action:'query',generator:'search',gsrsearch:subject+' filetype:bitmap',gsrnamespace:'6',gsrlimit:'8',prop:'imageinfo',iiprop:'url|extmetadata',iiurlwidth:'1000',format:'json'});
 const data=JSON.parse(execFileSync('curl',['-fLsS','--retry','3','--retry-delay','10','--max-time','30','https://commons.wikimedia.org/w/api.php?'+params],{encoding:'utf8'}));
 const candidates=Object.values(data.query?.pages??{}).sort((a,b)=>a.index-b.index);
 for(const page of candidates) {
  const info=page.imageinfo?.[0], meta=info?.extmetadata;
  const license=meta?.LicenseShortName?.value??'';
  if(!info?.thumburl || !/^(CC BY|CC0|Public domain)/i.test(license) || !/\.jpe?g$/i.test(page.title)) continue;
  try {execFileSync('curl',['-fLsS','--max-time','45',info.thumburl,'-o',path.join(directory,slug+'.jpg')]);}
  catch {continue;}
  manifest[slug]={file:slug+'.jpg',title:page.title,source:info.descriptionurl,image:info.thumburl,author:meta.Artist?.value??'',license,license_url:meta.LicenseUrl?.value??'',description:meta.ImageDescription?.value??''};
  fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,2)+'\n');
  console.log(slug, page.title, license); break;
 }
 if(!manifest[slug]) throw Error('No licensed image: '+slug);
 Atomics.wait(new Int32Array(new SharedArrayBuffer(4)),0,0,4000);
}
fs.writeFileSync(path.join(directory,'credits.json'),JSON.stringify(manifest,null,2)+'\n');
