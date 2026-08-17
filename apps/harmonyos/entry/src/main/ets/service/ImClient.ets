import webSocket from '@ohos.net.webSocket'
import util from '@ohos.util'
import { ApiService } from './ApiService'

export type ImEventCallback = (type: string, data: Record<string, Object>) => void

export class ImClient {
  private static ws?: webSocket.WebSocket
  private static host: string = ''
  private static lastToken: string = ''
  private static reconnectDelayMs: number = 1000
  private static pending: Record<string, string> = {} // client_msg_id -> 未 ack 的 send 帧
  private static decoder = new util.TextDecoder('utf-8')
  static onEvent?: ImEventCallback

  static connect(host: string, token: string): void {
    this.host = host
    if (token !== this.lastToken) this.pending = {} // 换用户不重发旧 pending
    this.lastToken = token
    this.ws?.close()
    const ws = webSocket.createWebSocket()
    this.ws = ws
    ws.on('open', () => {
      this.reconnectDelayMs = 1000
      for (const frame of Object.values(this.pending)) ws.send(frame)
    })
    ws.on('message', (err, data) => {
      if (err) return
      const text = typeof data === 'string' ? data : this.decoder.decodeToString(new Uint8Array(data))
      this.handle(text)
    })
    ws.on('close', () => this.scheduleReconnect())
    ws.on('error', () => this.scheduleReconnect())
    ws.connect(`ws://${host}:8789?token=${token}`)
  }

  static send(type: string, data: Record<string, Object>): void {
    const frame = JSON.stringify({ type: type, data: data })
    if (type === 'send') this.pending[data['client_msg_id'] as string] = frame
    this.ws?.send(frame)
  }

  private static handle(text: string): void {
    const root = JSON.parse(text) as Record<string, Object>
    const type = root['type'] as string
    if (!type) return
    const data = (root['data'] ?? {}) as Record<string, Object>
    if (type === 'ack') delete this.pending[data['client_msg_id'] as string]
    this.onEvent?.(type, data)
  }

  private static scheduleReconnect(): void {
    const delay = this.reconnectDelayMs + Math.floor(Math.random() * this.reconnectDelayMs)
    this.reconnectDelayMs = Math.min(this.reconnectDelayMs * 2, 30000)
    setTimeout(() => {
      const token = ApiService.accessToken
      if (this.host && token) this.connect(this.host, token)
    }, delay)
  }
}
