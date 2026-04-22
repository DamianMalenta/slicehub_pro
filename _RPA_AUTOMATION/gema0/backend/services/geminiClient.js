import { GoogleGenerativeAI } from '@google/generative-ai';

let rateWindow = [];

function rateLimited(limitPerMinute) {
  const now = Date.now();
  rateWindow = rateWindow.filter((t) => now - t < 60_000);
  if (rateWindow.length >= limitPerMinute) return true;
  rateWindow.push(now);
  return false;
}

export async function askGemini({ apiKey, model, prompt, rateLimit = 20, systemInstruction }) {
  if (!apiKey) throw new Error('Brak GEMINI_API_KEY w .env.');
  if (rateLimited(rateLimit)) throw new Error('Rate limit Gemini przekroczony.');

  const client = new GoogleGenerativeAI(apiKey);
  const g = client.getGenerativeModel({
    model: model || 'gemini-1.5-flash',
    systemInstruction,
  });

  const res = await g.generateContent(prompt);
  const text = res?.response?.text?.() ?? '';
  return { text };
}
