import { parseCommand } from '../backend/services/commandParser.js';

const tests = [
  ['HEALTHCHECK', { type: 'healthcheck' }],
  ['ZAPISZ raport', { type: 'save_note', name: 'raport.md', body: '' }],
  ['ZAPISZ raport.md', { type: 'save_note', name: 'raport.md', body: '' }],
  [
    'PUSH TO CURSOR_1 @raport.md zrob kod',
    {
      type: 'push',
      dry_run: false,
      target: 'CURSOR_1',
      note_name: 'raport.md',
      extra_instruction: 'zrob kod',
      mention_syntax: true,
    },
  ],
  [
    'DRY PUSH CURSOR_2 po prostu tekst',
    {
      type: 'push',
      dry_run: true,
      target: 'CURSOR_2',
      note_name: '',
      extra_instruction: 'po prostu tekst',
      mention_syntax: false,
    },
  ],
  [
    'BROADCAST @plan.md wykonaj',
    {
      type: 'push',
      dry_run: false,
      target: 'CURSOR_ALL',
      note_name: 'plan.md',
      extra_instruction: 'wykonaj',
      mention_syntax: true,
    },
  ],
  ['ASK GEMINI jak sie masz', { type: 'gemini_ask', prompt: 'jak sie masz' }],
  ['blabla', { type: 'chat_text', text: 'blabla' }],
];

let fails = 0;
for (const [input, expected] of tests) {
  const actual = parseCommand(input, { pendingBody: '' });
  const ok = Object.entries(expected).every(([k, v]) => JSON.stringify(actual[k]) === JSON.stringify(v));
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${JSON.stringify(input)} -> ${JSON.stringify(actual)}`);
  if (!ok) fails += 1;
}

if (fails) {
  console.error(`\n${fails} testow nieudanych.`);
  process.exit(1);
}
console.log('\nPARSER: wszystkie testy OK.');
