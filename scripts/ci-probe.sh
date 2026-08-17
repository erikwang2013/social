#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
(cd infrastructure && cargo run -p social_grpc) &
INFRA_PID=$!
(cd service && php start.php start) &
SERVICE_PID=$!
trap 'kill $INFRA_PID $SERVICE_PID 2>/dev/null || true' EXIT
# 等待 infra gRPC 端口就绪（首次编译可达 60s+），超时 120s
for i in $(seq 1 60); do
  (echo > /dev/tcp/127.0.0.1/50051) 2>/dev/null && break
  sleep 2
done
sleep 3
PORT=$(grep -oP "listen' => 'http://[^:]+:\K\d+" service/config/process.php)
curl -sf "http://127.0.0.1:${PORT}/health" | grep -q '"ok"' || { echo "health check failed"; exit 1; }
out=$(cd service && php scripts/probe_ping.php)
[[ "$out" == "pong from service" ]] || { echo "gRPC probe failed: $out"; exit 1; }
echo "E2E OK"
