#!/usr/bin/env node
// Generates the tag_keys translation catalogues consumed by the Symfony
// backend (translations/tag_keys.<locale>.xlf) from the OSM tag key import
// (scripts/data/flensburg-node-tag-keys.tsv, "<count>\t<key>" lines).
//
// The German dictionary below covers the common keys; everything else gets
// the prettified key as a placeholder target. Re-running the script merges:
// targets already in the .xlf files are kept, so hand edits survive.
//
// Usage: node scripts/generate-tag-key-catalog.mjs [input.tsv]

import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const inputPath = process.argv[2] ?? join(scriptDir, 'data', 'flensburg-node-tag-keys.tsv')
const translationsDir = join(scriptDir, '..', 'backend', 'translations')

const LOCALES = ['en', 'de']

// Keep in sync with TagKeyLabeler::prettify (backend) and the frontend store.
const prettify = (key) => key.replaceAll('_', ' ')

// German labels for common OSM keys (fallback: prettified key).
const GERMAN = {
  entrance: 'Eingang',
  highway: 'Straßentyp',
  amenity: 'Einrichtung',
  name: 'Name',
  natural: 'Natur',
  'addr:street': 'Straße',
  'addr:housenumber': 'Hausnummer',
  'addr:city': 'Stadt',
  'addr:postcode': 'Postleitzahl',
  'addr:country': 'Land',
  'addr:suburb': 'Stadtteil',
  leaf_type: 'Blatttyp',
  leaf_cycle: 'Blattzyklus',
  opening_hours: 'Öffnungszeiten',
  barrier: 'Barriere',
  emergency: 'Notfall',
  'fire_hydrant:type': 'Hydrantenart',
  wheelchair: 'Rollstuhlzugänglichkeit',
  'fire_hydrant:diameter': 'Hydrantendurchmesser',
  'fire_hydrant:position': 'Hydrantenposition',
  phone: 'Telefonnummer',
  website: 'Website',
  shop: 'Geschäft',
  backrest: 'Rückenlehne',
  tactile_paving: 'Taktiler Bodenbelag',
  check_date: 'Prüfdatum',
  access: 'Zugang',
  operator: 'Betreiber',
  public_transport: 'Öffentlicher Verkehr',
  level: 'Ebene',
  bus: 'Bus',
  material: 'Material',
  foot: 'Fußweg',
  bench: 'Bank',
  bin: 'Mülleimer',
  bicycle: 'Fahrrad',
  shelter: 'Unterstand',
  email: 'E-Mail',
  lit: 'Beleuchtung',
  man_made: 'Bauwerk',
  covered: 'Überdacht',
  brand: 'Marke',
  crossing: 'Kreuzung',
  ref: 'Referenznummer',
  railway: 'Eisenbahn',
  'crossing:island': 'Verkehrsinsel',
  'crossing:markings': 'Kreuzungsmarkierungen',
  'crossing:signals': 'Ampeln an der Kreuzung',
  'crossing:barrier': 'Sperre an der Kreuzung',
  capacity: 'Kapazität',
  tourism: 'Tourismus',
  leisure: 'Freizeit',
  bollard: 'Poller',
  network: 'Netz',
  vending: 'Verkaufsart',
  office: 'Büro',
  bicycle_parking: 'Fahrradabstellplatz',
  outdoor_seating: 'Sitzplätze im Freien',
  indoor_seating: 'Sitzplätze im Innenraum',
  fee: 'Gebühr',
  'seamark:type': 'Seezeichenart',
  button_operated: 'Tastenbetrieb',
  armrest: 'Armlehne',
  cuisine: 'Küche',
  noexit: 'Sackgasse',
  old_name: 'Alter Name',
  alt_name: 'Alternativer Name',
  short_name: 'Kurzname',
  'traffic_signals:sound': 'Akustisches Signal',
  'traffic_signals:vibration': 'Vibrationssignal',
  'traffic_signals:direction': 'Richtung der Ampel',
  traffic_signals: 'Ampel',
  traffic_calming: 'Verkehrsberuhigung',
  traffic_sign: 'Verkehrszeichen',
  direction: 'Richtung',
  'payment:credit_cards': 'Zahlung: Kreditkarten',
  'payment:debit_cards': 'Zahlung: Debitkarten',
  'payment:cash': 'Zahlung: Bargeld',
  'payment:coins': 'Zahlung: Münzen',
  'payment:cards': 'Zahlung: Karten',
  'payment:visa': 'Zahlung: Visa',
  'payment:mastercard': 'Zahlung: Mastercard',
  source: 'Quelle',
  information: 'Information',
  power: 'Strom',
  fax: 'Fax',
  contact: 'Kontakt',
  'contact:website': 'Kontakt: Website',
  historic: 'Historisch',
  note: 'Notiz',
  fixme: 'Korrekturbedürftig',
  recycling_type: 'Recyclingart',
  description: 'Beschreibung',
  maxwidth: 'Maximale Breite',
  'maxwidth:physical': 'Maximale physische Breite',
  'toilets:wheelchair': 'Rollstuhlzugängliche Toilette',
  playground: 'Spielplatz',
  board_type: 'Tafelart',
  image: 'Bild',
  inscription: 'Inschrift',
  smoking: 'Rauchen',
  wikidata: 'Wikidata',
  wikipedia: 'Wikipedia',
  'brand:wikidata': 'Marke (Wikidata)',
  'operator:wikidata': 'Betreiber (Wikidata)',
  'brand:wikipedia': 'Marke (Wikipedia)',
  'operator:wikipedia': 'Betreiber (Wikipedia)',
  memorial: 'Denkmal',
  departures_board: 'Abfahrtstafel',
  'opening_hours:signed': 'Öffnungszeiten ausgewiesen',
  'check_date:opening_hours': 'Prüfdatum der Öffnungszeiten',
  'disused:shop': 'Aufgelassenes Geschäft',
  'disused:amenity': 'Aufgelassene Einrichtung',
  'seamark:mooring:category': 'Liegeplatzkategorie',
  genus: 'Gattung',
  'genus:de': 'Gattung (deutsch)',
  species: 'Art',
  'surveillance:type': 'Überwachungsart',
  'diet:vegetarian': 'Vegetarische Gerichte',
  'diet:vegan': 'Vegane Gerichte',
  colour: 'Farbe',
  male: 'Männlich',
  female: 'Weiblich',
  internet_access: 'Internetzugang',
  'internet_access:fee': 'Gebühr für Internetzugang',
  takeaway: 'Zum Mitnehmen',
  seats: 'Sitzplätze',
  drive_through: 'Drive-in',
  drinking_water: 'Trinkwasser',
  design: 'Bauform',
  'flag:type': 'Flaggentyp',
  changing_table: 'Wickeltisch',
  kerb: 'Bordstein',
  indoor: 'Im Innenraum',
  healthcare: 'Gesundheitseinrichtung',
  'healthcare:speciality': 'Fachrichtung',
  artwork_type: 'Kunstwerktyp',
  waste: 'Abfall',
  cycle_barrier: 'Umlaufschranke',
  door: 'Tür',
  place: 'Ort',
  craft: 'Handwerksbetrieb',
  parking: 'Parken',
  sport: 'Sportart',
  segregated: 'Getrennte Wege',
  'recycling:clothes': 'Recycling: Kleidung',
  'recycling:glass_bottles': 'Recycling: Glasflaschen',
  'recycling:glass': 'Recycling: Glas',
  'recycling:shoes': 'Recycling: Schuhe',
  wikimedia_commons: 'Wikimedia Commons',
  start_date: 'Errichtungsdatum',
  mapillary: 'Mapillary',
  panoramax: 'Panoramax',
  locked: 'Abgeschlossen',
  lamp_mount: 'Lampenhalterung',
  lamp_type: 'Lampentyp',
  'line_attachment': 'Leitungsanbindung',
  'route_ref': 'Linienreferenz',
  'local_ref': 'Lokale Linienreferenz',
  collection_times: 'Leerungszeiten',
}

const escapeXml = (text) =>
  text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

const unitId = (key) => 'k_' + createHash('md5').update(key).digest('hex').slice(0, 12)

// Reads "<unit id=... name="<key>"> ... <target>label</target>" entries.
function readExistingTargets(filePath) {
  if (!existsSync(filePath)) {
    return {}
  }
  const existing = {}
  const content = readFileSync(filePath, 'utf8')
  for (const match of content.matchAll(/<unit[^>]*\sname="([^"]*)"[^>]*>([\s\S]*?)<\/unit>/g)) {
    const target = match[2].match(/<target[^>]*>([\s\S]*?)<\/target>/)
    if (target) {
      existing[match[1]] = target[1]
    }
  }
  return existing
}

const keys = readFileSync(inputPath, 'utf8')
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line !== '')
  .map((line) => {
    const parts = line.split('\t')
    return { count: Number(parts[0]), key: parts.slice(1).join('\t') }
  })
  .filter((entry) => entry.count > 0 && entry.key !== '')
  .sort((a, b) => b.count - a.count)

if (keys.length === 0) {
  console.error(`No keys found in ${inputPath}`)
  process.exit(1)
}

let totalTranslated = 0
for (const locale of LOCALES) {
  const outputPath = join(translationsDir, `tag_keys.${locale}.xlf`)
  const existing = readExistingTargets(outputPath)

  const units = keys.map(({ key }) => {
    const fallback = locale === 'de' ? (GERMAN[key] ?? prettify(key)) : prettify(key)
    const target = existing[key] ?? fallback
    if (target !== prettify(key)) {
      totalTranslated++
    }
    return [
      `    <unit id="${unitId(key)}" name="${escapeXml(key)}">`,
      '      <segment>',
      `        <source>${escapeXml(key)}</source>`,
      `        <target>${escapeXml(target)}</target>`,
      '      </segment>',
      '    </unit>',
    ].join('\n')
  })

  const document = [
    '<?xml version="1.0" encoding="utf-8"?>',
    `<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en" trgLang="${locale}">`,
    `  <file id="tag_keys.${locale}">`,
    units.join('\n'),
    '  </file>',
    '</xliff>',
    '',
  ].join('\n')

  writeFileSync(outputPath, document)
  console.log(`${outputPath}: ${keys.length} keys`)
}
console.log(`Total translated targets: ${totalTranslated}`)
