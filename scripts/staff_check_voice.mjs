#!/usr/bin/env node
/**
 * Make the spoken questions for the Staff app's driver daily check.
 *
 *   OPENAI_API_KEY=… node scripts/staff_check_voice.mjs [--force]
 *
 * Reads staff/audio/check/texts.json and writes staff/audio/check/{lang}/{key}.mp3
 * with OpenAI's gpt-4o-mini-tts — the same voice model the Jarvis app uses. Run
 * it once, and again after changing a question in texts.json: only clips whose
 * words changed are made again (manifest.json remembers what each was made
 * from), so a rerun costs a few cents at most. --force remakes all of them.
 *
 * The clips are plain files shipped with the page: nothing calls OpenAI while a
 * driver is using the app, and they play with no signal.
 */
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', 'staff', 'audio', 'check');
const KEY = process.env.OPENAI_API_KEY;
const FORCE = process.argv.includes('--force');
const VOICE = 'sage';
const LANGUAGE_NAMES = { en: 'English', ur: 'Urdu', hi: 'Hindi', ar: 'Arabic (Gulf)', ml: 'Malayalam' };

if (!KEY) {
  console.error('Set OPENAI_API_KEY first.');
  process.exit(1);
}

const texts = JSON.parse(readFileSync(join(ROOT, 'texts.json'), 'utf8'));
const manifestPath = join(ROOT, 'manifest.json');
const manifest = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : {};

const jobs = [];
for (const lang of Object.keys(texts.languages)) {
  for (const [key, byLang] of Object.entries(texts.prompts)) jobs.push({ lang, key, text: byLang[lang] });
  for (const [key, byLang] of Object.entries(texts.items)) jobs.push({ lang, key, text: byLang[lang] });
}

const hash = (s) => createHash('sha256').update(VOICE + '|' + s).digest('hex').slice(0, 16);

async function speak(text, lang) {
  const res = await fetch('https://api.openai.com/v1/audio/speech', {
    method: 'POST',
    headers: { Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: 'gpt-4o-mini-tts',
      voice: VOICE,
      input: text,
      instructions: `Speak in ${LANGUAGE_NAMES[lang] || lang}, slowly and very clearly, in a warm, calm voice — like a phone menu helping a driver who may not read well. Pause briefly between sentences.`,
      response_format: 'mp3',
    }),
  });
  if (!res.ok) throw new Error(`${res.status} ${await res.text()}`);
  return Buffer.from(await res.arrayBuffer());
}

let made = 0;
let kept = 0;
for (const job of jobs) {
  if (!job.text) { console.warn(`missing text: ${job.lang}/${job.key}`); continue; }
  const id = `${job.lang}/${job.key}`;
  const file = join(ROOT, job.lang, `${job.key}.mp3`);
  const want = hash(job.text);
  if (!FORCE && manifest[id] === want && existsSync(file)) { kept++; continue; }
  mkdirSync(dirname(file), { recursive: true });
  process.stdout.write(`making ${id} … `);
  writeFileSync(file, await speak(job.text, job.lang));
  manifest[id] = want;
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  made++;
  console.log('ok');
}
console.log(`${made} made, ${kept} already up to date.`);
