'use strict';
// Unisce i file di server/ in un unico script (serve per creare l'eseguibile "single executable").
const fs = require('node:fs');
const path = require('node:path');
const RADICE = path.resolve(__dirname, '..');
const FILE = ['db', 'api', 'server'];
let out = `'use strict';\n// FILE GENERATO da costruisci/bundle.js — non modificare a mano.\nconst __moduli = {};\nconst __cache = {};\nfunction __require(nome) {\n  if (nome.startsWith('./')) { const k = nome.slice(2).replace(/\\.js$/, ''); if (__cache[k]) return __cache[k].exports; const m = { exports: {} }; __cache[k] = m; __moduli[k](m, m.exports, __require); return m.exports; }\n  return require(nome);\n}\n`;
for (const f of FILE) {
  const src = fs.readFileSync(path.join(RADICE, 'server', f + '.js'), 'utf8');
  out += `__moduli[${JSON.stringify(f)}] = function (module, exports, require) {\nconst __dirname = require('node:path').dirname(process.execPath);\n${src}\n};\n`;
}
out += `__require('./server');\n`;
fs.mkdirSync(path.join(RADICE, 'dist'), { recursive: true });
fs.writeFileSync(path.join(RADICE, 'dist', 'agenzia.js'), out);
console.log('creato dist/agenzia.js');
