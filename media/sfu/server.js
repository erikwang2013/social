// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// 语聊房 SFU：service 经 HTTP /signal 转译 mediasoup API。
// 每房间一个 Router；空置 5min 自动释放（ponytail: 首版简单 reap）。
import http from 'node:http';
import mediasoup from 'mediasoup';

const PORT = Number(process.env.SFU_PORT || 8790);
const EMPTY_TTL_MS = 5 * 60 * 1000;

let worker;
const rooms = new Map(); // roomId -> { router, transports, producers, consumers, lastActive }

async function ensureWorker() {
  if (!worker) {
    // rtc 端口范围须与 docker-compose 发布的 10000-10200/udp 对齐，否则容器内媒体不通
    worker = await mediasoup.createWorker({ logLevel: 'warn', rtcMinPort: 10000, rtcMaxPort: 10200 });
  }
  return worker;
}

async function routerFor(roomId) {
  let room = rooms.get(roomId);
  if (!room) {
    const w = await ensureWorker();
    const router = await w.createRouter({
      mediaCodecs: [{ kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 }],
    });
    // 并发首触竞态：await 期间对方可能已 set，取先到者，后到者关掉自己的 router
    room = rooms.get(roomId);
    if (room) {
      router.close();
    } else {
      room = { router, transports: new Map(), producers: new Map(), consumers: new Map(), lastActive: Date.now() };
      rooms.set(roomId, room);
    }
  }
  room.lastActive = Date.now();
  return room;
}

function json(res, status, data) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(data));
}

// mediasoup 自带 SIGTERM/SIGINT 处理器只 close worker 不退出进程（v3 Worker.onSignal），
// 不覆盖的话 docker stop / kill 会挂住。此处显式退出。
process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/signal') {
    json(res, 404, { error: 'not found' });
    return;
  }
  let raw = '';
  let tooBig = false;
  req.on('data', (c) => {
    raw += c;
    if (!tooBig && raw.length > 64e3) {
      tooBig = true;
      json(res, 413, { error: 'payload too large' });
      req.destroy();
    }
  });
  req.on('end', async () => {
    if (tooBig) return;
    try {
      const { room_id, method, ...body } = JSON.parse(raw || '{}');
      const room = await routerFor(room_id);
      const out = await handle(room, method, body, room_id);
      json(res, 200, out);
    } catch (e) {
      json(res, 400, { error: e.message });
    }
  });
});

async function handle(room, method, body, roomId) {
  switch (method) {
    case 'rtpCapabilities':
      return { rtpCapabilities: room.router.rtpCapabilities };
    case 'transport': {
      const t = await room.router.createWebRtcTransport({
        listenIps: [{ ip: process.env.SFU_LISTEN_IP || '0.0.0.0' }],
        enableUdp: true, enableTcp: true, preferUdp: true,
      });
      room.transports.set(t.id, t);
      return { transport_id: t.id, iceParameters: t.iceParameters, iceCandidates: t.iceCandidates, dtlsParameters: t.dtlsParameters };
    }
    case 'connect': {
      const t = room.transports.get(body.transport_id);
      if (!t) throw new Error('unknown transport');
      await t.connect({ dtlsParameters: body.dtlsParameters });
      return { ok: true };
    }
    case 'produce': {
      const t = room.transports.get(body.transport_id);
      if (!t) throw new Error('unknown transport');
      const p = await t.produce({ kind: body.kind || 'audio', rtpParameters: body.rtpParameters });
      room.producers.set(p.id, p);
      return { producer_id: p.id };
    }
    case 'consume': {
      const t = room.transports.get(body.transport_id);
      if (!t) throw new Error('unknown transport');
      const p = room.producers.get(body.producer_id);
      if (!p) throw new Error('unknown producer');
      const c = await t.consume({ producerId: p.id, rtpCapabilities: body.rtpCapabilities });
      room.consumers.set(c.id, c);
      return { consumer_id: c.id, kind: c.kind, rtpParameters: c.rtpParameters };
    }
    case 'resume': {
      const c = room.consumers.get(body.consumer_id);
      if (!c) throw new Error('unknown consumer');
      await c.resume();
      return { ok: true };
    }
    case 'close':
      rooms.get(roomId)?.router.close(); // 不关 router 则其 transports/producers 泄漏在 worker 里
      rooms.delete(roomId);
      return { ok: true };
    default:
      throw new Error('unknown method: ' + method);
  }
}

setInterval(() => {
  const now = Date.now();
  for (const [roomId, room] of rooms) {
    if (now - room.lastActive > EMPTY_TTL_MS) {
      room.router.close();
      rooms.delete(roomId);
    }
  }
}, 60 * 1000).unref();

server.listen(PORT, () => console.log(`sfu listening :${PORT}`));
