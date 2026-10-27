'use strict';
// Crea l'eseguibile "agenzia" (o agenzia.exe su Windows) dal bundle, con la funzione
// "single executable application" di Node: nessuna dipendenza da installare sul PC dell'agenzia.
// Da eseguire sul sistema di destinazione (Windows per avere agenzia.exe) con Node >= 22.13 installato.
const fs = require('node:fs');
const path = require('node:path');
const { execSync } = require('node:child_process');
const RADICE = path.resolve(__dirname, '..');
const DIST = path.join(RADICE, 'dist');
const win = process.platform === 'win32';
const cfg = { main: path.join(DIST, 'agenzia.js'), output: path.join(DIST, 'sea-prep.blob'), disableExperimentalSEAWarning: true };
fs.writeFileSync(path.join(DIST, 'sea-config.json'), JSON.stringify(cfg));
execSync(`"${process.execPath}" --experimental-sea-config "${path.join(DIST, 'sea-config.json')}"`, { stdio: 'inherit' });
const exe = path.join(DIST, win ? 'agenzia.exe' : 'agenzia');
fs.copyFileSync(process.execPath, exe);
if (win) { try { execSync(`"${process.execPath}" -e "require('node:fs').chmodSync(process.argv[1], 0o755)" "${exe}"`); } catch (e) { /* ignora */ } }
// postject inietta il blob nell'eseguibile copiato (scaricato da npm solo per la costruzione).
execSync(`npx --yes postject "${exe}" NODE_SEA_BLOB "${cfg.output}" --sentinel-fuse NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2`, { stdio: 'inherit', cwd: DIST });
// Cartella pronta da copiare sul PC dell'agenzia
const PACK = path.join(DIST, 'AGENZIA');
fs.rmSync(PACK, { recursive: true, force: true });
fs.mkdirSync(PACK, { recursive: true });
fs.copyFileSync(exe, path.join(PACK, path.basename(exe)));
fs.cpSync(path.join(RADICE, 'web'), path.join(PACK, 'web'), { recursive: true });
for (const f of fs.readdirSync(path.join(RADICE, 'windows'))) if (f !== 'costruisci-exe.bat') fs.copyFileSync(path.join(RADICE, 'windows', f), path.join(PACK, f));
fs.copyFileSync(path.join(RADICE, 'LEGGIMI.md'), path.join(PACK, 'LEGGIMI.md'));
console.log('\nPRONTO: copia la cartella', PACK, 'sul PC centrale dell\'agenzia.');
