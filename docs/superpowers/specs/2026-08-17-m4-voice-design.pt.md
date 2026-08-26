# Design do marco de voz M4 (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Data: 2026-08-17
- Status: confirmado
- Escopo: mensagens de voz + chamadas 1v1 + salas de voz (os três componentes); o mecanismo de versionamento de API (por header) é implementado primeiro
- Design a montante: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Arquitetura de voz)

## 1. Objetivo

Entregar o trio de voz M4: mensagens de voz (extensão do tipo de mensagem IM + transcodificação), chamadas 1v1 (máquina de estados de sinalização WS + plano de mídia P2P), salas de voz (máquina de estados de sala + SFU mediasoup). Implementar também o mecanismo de versionamento de API por header.

## 2. Versionamento de API (por header, tarefa 0 primeiro)

**Estado atual**: todos os endpoints estão registrados no grupo de prefixo `/api/v1` (`config/route.php`), 10 controladores, com `AuthMiddleware` montado no grupo.

**Mecanismo**: o cliente envia um caminho sem versão `/api/xxx` + `Header: X-Api-Version: v1`; o middleware global `ApiVersionMiddleware` (`config/middleware.php`) reescreve o caminho e o entrega ao roteador.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Versão inválida (não é `v1|v2|...`) → 400 + `lang_key`
- Migração zero: controladores/rotas/caminhos E2E existentes não mudam
- V2 futura: registrar o grupo `/api/v2` → `app\api\v2\*`, sem alterações no middleware
- Os novos endpoints M4 são registrados em `/api/v1/voice/*` (prefixo de versão mantido; o cliente usa caminho sem versão + header)

## 3. Modelo de dados (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**Novas tabelas**:

```sql
CREATE TABLE `social_call_records` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  caller_id BIGINT UNSIGNED NOT NULL,
  callee_id BIGINT UNSIGNED NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1呼叫中 2接通 3未接 4取消 5结束',
  started_at TIMESTAMP NULL COMMENT '接通时间',
  ended_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_callee(callee_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1v1通话记录';

CREATE TABLE `social_voice_rooms` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  owner_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1开 0关',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_status(status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房';

CREATE TABLE `social_voice_room_members` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role TINYINT NOT NULL DEFAULT 0 COMMENT '0听众 1麦位',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), UNIQUE KEY uk_room_uid(room_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房成员';
```

## 4. Mensagens de voz

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- A REST do histórico inclui automaticamente `voice_url/voice_duration` (cast do modelo)
- A transcodificação é concluída de forma síncrona dentro da requisição (segundos por arquivo); colocar em fila quando o volume crescer (nota ponytail)
- Pré-requisito de ambiente: o host do service precisa do binário FFmpeg (verificar durante a implementação; instalar se ausente)

## 5. Sinalização de chamada 1v1

**Frames WS** (reutilizando a porta de entrada existente, prefixo `call_*`):

```
call_invite   {to_user_id}            主叫发起
call_accept   {call_id}               被叫接听
call_reject   {call_id}               被叫拒绝
call_cancel   {call_id}               主叫取消
call_timeout  {call_id}               30s 无人接听（服务端推双方）
call_offer    {call_id, sdp}          主叫 offer（经服务端转发被叫）
call_answer   {call_id, sdp}          被叫 answer 回传
call_ice      {call_id, candidate}    ICE 候选双向转发
call_hangup   {call_id}               任一方挂断 → 推双方
call_failed   {call_id}               P2P 15s 未连通 → 推双方
```

**Máquina de estados** (uma única chave Redis):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Mutex de inatividade: `SETNX im:callbusy:{uid}` (TTL 5 min), em conflito retornar o frame de erro `already_in_call`
- Sem resposta em 30 s → não atendida, enviar `call_timeout` para ambos os lados, persistir
- accept → `call_records` status=2 + started_at
- hangup/fim → status=5 + ended_at, liberar a chave busy
- Desconexão WS de qualquer lado → enviar `call_hangup` para o outro e encerrar (ponytail: sem recuperação por reconexão)
- Plano de mídia em conexão P2P direta (offer/answer/ICE apenas retransmitidos, fluxos de mídia nunca passam pelo servidor); fallback TURN (coturn é entregue com as salas de voz)
- ICE P2P não conectado em 15 s → `call_failed` + fim (v1 não troca automaticamente para SFU, nota ponytail); persistir status=5

**Histórico**: `GET /api/v1/voice/calls?page=` resposta paginada (caller/callee/status/duração).

## 6. Salas de voz

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**Frames WS** (prefixo `room_*`):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Vagas de microfone limitadas a 8 (1 dono + 7 vagas, constante, configurável depois no admin); retornar frame de erro quando lotado
- join/leave/alterações de microfone persistidos na tabela `voice_room_members` + estado da sala no Redis; enviar alterações para todos os membros online da sala
- Dono sai → fechar a sala (enviar `room_closed` para todos)

**Caminho de sinalização SFU** (conforme o documento de design: "toda a sinalização reutiliza a porta de entrada WS do service"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service retransmite frames → SFU (POST HTTP traduzindo a API do mediasoup: rtpCapabilities, criação/connect de WebRtcTransport, produce/consume); resposta SFU → service → push WS para o cliente
- Um Router mediasoup por sala; liberação automática após 5 min ocioso (nota ponytail)

**Implantação**: `media/sfu` processo Node puro (desenvolvimento) + `docker-compose.yml` reservado para produção; o contêiner `coturn` é entregue no mesmo bloco.

## 7. Estratégia de teste

| Camada | Cobertura |
|---|---|
| Testes unitários | ApiVersionMiddleware (padrão/explícito/inválido/caminho antigo), máquina de estados de chamada (invite/accept/reject/cancel/timeout/hangup/mutex), máquina de estados de sala de voz (join/vagas de mic/fechar/lotado/kick), validação de upload de voz (tipo/tamanho/duração) |
| E2E caixa preta | Mensagens de voz: upload → envio de frame → recebimento de frame → histórico com duração; 1v1: invite→accept→verificar retransmissão offer/answer/ICE→hangup→call_records persistido; salas de voz: join→up_mic→down_mic→leave→fechar sala |
| Build | Build Android testado de verdade; commits iOS/HarmonyOS indicam que Linux não compila (padrão estabelecido no M3) |
| Manual em dispositivo | Áudio/vídeo SFU reais, qualidade de chamada P2P (caixa preta não pode automatizar WebRTC) |

## 8. Ordem de implementação (pipeline em ordem inversa de dependência)

0. Middleware de versionamento de API (primeiro, entregável independente)
1. Mensagens de voz (upload + transcodificação + armazenamento + modelo + tipo de mensagem)
2. Máquina de estados de sinalização de chamada 1v1 (+ call_records + REST de histórico)
3. Salas de voz (REST + máquina de estados de sala + vagas de microfone)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Clientes das três plataformas (gravação/reprodução de voz / UI de chamada / UI de sala de voz)
6. E2E + regressão completa
