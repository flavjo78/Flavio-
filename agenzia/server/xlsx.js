'use strict';
// Scrittore minimo di file Excel (.xlsx) senza dipendenze: un archivio zip (senza compressione) con i file XML del formato.
// Uso: creaXlsx([{ nome, colonne:[larghezza,...], righe:[[cella,...],...], unioni:['E1:H1'], bloccaRighe:3, filtro:'A3:K40' }, ...]) → Buffer
// Una cella è { v, t, s, f }: t = 'n' numero · 's' testo · 'd' data (stringa AAAA-MM-GG) · 'f' formula (f = testo, v = valore calcolato); s = stile (vedi STILE).
const zlib = require('node:zlib');

const STILE = { base: 0, intestazione: 1, testo: 2, euro: 3, data: 4, intero: 5, totTesto: 6, totEuro: 7, gruppo: 8, titolo: 9, totIntero: 10, nota: 11 };

const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '');
const nomeColonna = i => { let s = '', n = i + 1; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
const seriale = iso => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); if (!m) return null; return Math.round((Date.UTC(+m[1], +m[2] - 1, +m[3]) - Date.UTC(1899, 11, 30)) / 86400000); };
const numero = n => (Math.round(n * 100) / 100).toString();

function xmlCella(c, riga, colonna) {
  if (c === null || c === undefined || c === '') return '';
  const cella = typeof c === 'object' ? c : { v: c, t: typeof c === 'number' ? 'n' : 's' };
  const rif = nomeColonna(colonna) + (riga + 1);
  const s = cella.s ? ` s="${cella.s}"` : '';
  if (cella.t === 'f') return `<c r="${rif}"${s}><f>${esc(cella.f)}</f><v>${numero(cella.v || 0)}</v></c>`;
  if (cella.t === 'n') return `<c r="${rif}"${s}><v>${numero(cella.v)}</v></c>`;
  if (cella.t === 'd') { const d = seriale(cella.v); return d === null ? '' : `<c r="${rif}"${s}><v>${d}</v></c>`; }
  if (cella.v === '' || cella.v === null || cella.v === undefined) return `<c r="${rif}"${s}/>`;
  return `<c r="${rif}"${s} t="inlineStr"><is><t xml:space="preserve">${esc(cella.v)}</t></is></c>`;
}
function xmlFoglio(f) {
  const nCol = Math.max(0, ...f.righe.map(r => r.length));
  const colonne = (f.colonne || []).map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('');
  const blocca = f.bloccaRighe ? `<pane ySplit="${f.bloccaRighe}" topLeftCell="A${f.bloccaRighe + 1}" activePane="bottomLeft" state="frozen"/>` : '';
  const righe = f.righe.map((r, i) => {
    const celle = r.map((c, j) => xmlCella(c, i, j)).join('');
    return celle ? `<row r="${i + 1}">${celle}</row>` : '';
  }).join('');
  const unioni = (f.unioni || []).length ? `<mergeCells count="${f.unioni.length}">${f.unioni.map(u => `<mergeCell ref="${u}"/>`).join('')}</mergeCells>` : '';
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">` +
    `<dimension ref="A1:${nomeColonna(Math.max(0, nCol - 1))}${Math.max(1, f.righe.length)}"/>` +
    `<sheetViews><sheetView workbookViewId="0"${f.primo ? ' tabSelected="1"' : ''}>${blocca}</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>` +
    (colonne ? `<cols>${colonne}</cols>` : '') + `<sheetData>${righe}</sheetData>` +
    (f.filtro ? `<autoFilter ref="${f.filtro}"/>` : '') + unioni +
    `<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape"/></worksheet>`;
}
const STILI_XML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00\\ &quot;€&quot;;\\-#,##0.00\\ &quot;€&quot;"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>
<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF5B6470"/><name val="Calibri"/></font></fonts>
<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F3F6"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFC9CFD6"/></left><right style="thin"><color rgb="FFC9CFD6"/></right><top style="thin"><color rgb="FFC9CFD6"/></top><bottom style="thin"><color rgb="FFC9CFD6"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="12">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="1" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

// ---- archivio zip senza compressione ----
function creaZip(file) {
  const parti = [], centrale = []; let offset = 0;
  const d = new Date(); const ora = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1); const giorno = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
  for (const { nome, dati } of file) {
    const n = Buffer.from(nome, 'utf8'), contenuto = Buffer.isBuffer(dati) ? dati : Buffer.from(dati, 'utf8'), crc = zlib.crc32(contenuto) >>> 0;
    const loc = Buffer.alloc(30);
    loc.writeUInt32LE(0x04034b50, 0); loc.writeUInt16LE(20, 4); loc.writeUInt16LE(0x0800, 6); loc.writeUInt16LE(0, 8); loc.writeUInt16LE(ora, 10); loc.writeUInt16LE(giorno, 12);
    loc.writeUInt32LE(crc, 14); loc.writeUInt32LE(contenuto.length, 18); loc.writeUInt32LE(contenuto.length, 22); loc.writeUInt16LE(n.length, 26); loc.writeUInt16LE(0, 28);
    parti.push(loc, n, contenuto);
    const cen = Buffer.alloc(46);
    cen.writeUInt32LE(0x02014b50, 0); cen.writeUInt16LE(20, 4); cen.writeUInt16LE(20, 6); cen.writeUInt16LE(0x0800, 8); cen.writeUInt16LE(0, 10); cen.writeUInt16LE(ora, 12); cen.writeUInt16LE(giorno, 14);
    cen.writeUInt32LE(crc, 16); cen.writeUInt32LE(contenuto.length, 20); cen.writeUInt32LE(contenuto.length, 24); cen.writeUInt16LE(n.length, 28); cen.writeUInt32LE(offset, 42);
    centrale.push(cen, n);
    offset += 30 + n.length + contenuto.length;
  }
  const dimCentrale = centrale.reduce((s, b) => s + b.length, 0);
  const fine = Buffer.alloc(22);
  fine.writeUInt32LE(0x06054b50, 0); fine.writeUInt16LE(file.length, 8); fine.writeUInt16LE(file.length, 10); fine.writeUInt32LE(dimCentrale, 12); fine.writeUInt32LE(offset, 16);
  return Buffer.concat([...parti, ...centrale, fine]);
}

function creaXlsx(fogli) {
  const nomi = fogli.map((f, i) => ({ ...f, id: i + 1, primo: i === 0 }));
  const file = [
    { nome: '[Content_Types].xml', dati: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${nomi.map(f => `<Override PartName="/xl/worksheets/sheet${f.id}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}</Types>` },
    { nome: '_rels/.rels', dati: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>` },
    { nome: 'xl/workbook.xml', dati: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews><sheets>${nomi.map(f => `<sheet name="${esc(f.nome)}" sheetId="${f.id}" r:id="rId${f.id}"/>`).join('')}</sheets>` +
      (nomi.some(f => f.filtro) ? `<definedNames>${nomi.filter(f => f.filtro).map(f => { const [a, b] = f.filtro.split(':'); const ass = x => x.replace(/^([A-Z]+)(\d+)$/, '$$$1$$$2'); return `<definedName name="_xlnm._FilterDatabase" localSheetId="${f.id - 1}" hidden="1">'${esc(f.nome)}'!${ass(a)}:${ass(b)}</definedName>`; }).join('')}</definedNames>` : '') + `<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>` },
    { nome: 'xl/_rels/workbook.xml.rels', dati: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${nomi.map(f => `<Relationship Id="rId${f.id}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${f.id}.xml"/>`).join('')}<Relationship Id="rId${nomi.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>` },
    { nome: 'xl/styles.xml', dati: STILI_XML },
    ...nomi.map(f => ({ nome: `xl/worksheets/sheet${f.id}.xml`, dati: xmlFoglio(f) })),
  ];
  return creaZip(file);
}

module.exports = { creaXlsx, STILE, nomeColonna };
