// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// SFU 冒烟：spawn server.js，走通 rtpCapabilities→transport→connect→produce→consume 全链路。
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const port = 8799;
const child = spawn('node', ['server.js'], { cwd: root, env: { ...process.env, SFU_PORT: String(port) }, stdio: ['ignore', 'pipe', 'inherit'], detached: true });
// 冷启动 mediasoup 加载可超 800ms：轮询端口就绪，最多 5s
for (let i = 0; i < 50; i++) {
  try { await fetch(`http://127.0.0.1:${port}/signal`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }); break; } catch { await new Promise((r) => setTimeout(r, 100)); }
}

const sfu = async (body) => {
  const res = await fetch(`http://127.0.0.1:${port}/signal`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ room_id: 1, ...body }),
  });
  return res.json();
};

const checks = [];
const check = (cond, msg) => { checks.push([cond, msg]); if (!cond) console.error('FAIL:', msg); };

try {
  const caps = await sfu({ method: 'rtpCapabilities' });
  check(!!caps.rtpCapabilities, 'rtpCapabilities');

  const t = await sfu({ method: 'transport' });
  check(!!t.transport_id && Array.isArray(t.iceCandidates), 'transport created');

  const dtls = { role: 'server', fingerprints: [{ algorithm: 'sha-256', value: 'DE:AD:BE:EF:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00:00' }] };
  const conn = await sfu({ method: 'connect', transport_id: t.transport_id, dtlsParameters: dtls });
  check(conn.ok === true, 'transport connect');

  const rtp = { codecs: [{ mimeType: 'audio/opus', payloadType: 111, clockRate: 48000, channels: 2, parameters: { spropstereo: 1 } }], encodings: [{ ssrc: 1001 }] };
  const prod = await sfu({ method: 'produce', transport_id: t.transport_id, kind: 'audio', rtpParameters: rtp });
  check(!!prod.producer_id, 'produce');

  const t2 = await sfu({ method: 'transport' });
  const cons = await sfu({ method: 'consume', transport_id: t2.transport_id, producer_id: prod.producer_id, rtpCapabilities: caps.rtpCapabilities });
  check(!!cons.consumer_id, 'consume');

  const res = await sfu({ method: 'resume', consumer_id: cons.consumer_id });
  check(res.ok === true, 'resume');

  const bad = await sfu({ method: 'nope' });
  check(!!bad.error, 'unknown method rejected');
} finally {
  // detached group so the mediasoup worker subprocess dies too (else it holds stdout open)
  try { process.kill(-child.pid, 'SIGTERM'); } catch {} // child may have already exited
}

if (checks.every(([c]) => c)) {
  console.log('SFU SMOKE OK');
  process.exit(0);
}
process.exit(1);
