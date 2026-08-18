// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import http from '@ohos.net.http'
import request from '@ohos.request'
import { common } from '@kit.AbilityKit'
import { ApiService } from '../service/ApiService'

export interface CallRecordItem {
  id: number
  caller_id: number
  callee_id: number
  status: string
  created_at: string
}

export interface VoiceRoomItem {
  id: number
  owner_id: number
  name: string
  online_count: number
  mic_count: number
}

const BASE = 'http://10.0.2.2:8787' // 模拟器访问宿主机（真机改为局域网 IP）
const API_VERSION = 'v1'

/** 语音 API。所有请求带 Authorization + X-Api-Version 头；语音文件同样需要带头播放。 */
export class VoiceApi {
  private static authHeader(): Record<string, string> {
    return {
      'X-Api-Version': API_VERSION,
      ...(ApiService.accessToken ? { Authorization: 'Bearer ' + ApiService.accessToken } : {})
    }
  }

  private static async request(method: http.RequestMethod, path: string): Promise<any> {
    const client = http.createHttp()
    const res = await client.request(BASE + path, {
      method: method,
      header: this.authHeader(),
      expectDataType: http.HttpDataType.STRING,
    })
    return JSON.parse(res.result as string)
  }

  static async calls(page: number = 1): Promise<CallRecordItem[]> {
    const r = await this.request(http.RequestMethod.GET, '/api/v1/voice/calls?page=' + page)
    return r.data?.list ?? []
  }

  static async createRoom(name: string): Promise<number> {
    const client = http.createHttp()
    const res = await client.request(BASE + '/api/v1/voice/rooms', {
      method: http.RequestMethod.POST,
      header: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...this.authHeader(),
      },
      extraData: 'name=' + encodeURIComponent(name),
      expectDataType: http.HttpDataType.STRING,
    })
    const r = JSON.parse(res.result as string)
    return r.data?.room_id ?? 0
  }

  static async rooms(page: number = 1): Promise<VoiceRoomItem[]> {
    const r = await this.request(http.RequestMethod.GET, '/api/v1/voice/rooms?page=' + page)
    return r.data?.list ?? []
  }

  /** 上传语音：multipart field=voice → data.voice_url / data.voice_duration */
  static async uploadVoice(uri: string, context: common.UIAbilityContext): Promise<any> {
    return new Promise((resolve) => {
      const task = request.uploadFile(context, {
        url: BASE + '/api/v1/im/voice',
        method: 'POST',
        header: this.authHeader(),
        files: [{ filename: 'voice.m4a', name: 'voice', uri: uri, type: 'audio/mp4' }],
        data: [],
      })
      task.on('complete', () => resolve(null)) // 响应体需在真机上读取 UploadTask 回调，这里先置空
      task.on('fail', () => resolve(null))
    })
  }
}
