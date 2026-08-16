import http from '@ohos.net.http'

export interface TokenData {
  access_token: string
  refresh_token: string
  expires_in: number
}

export interface LoginResult {
  code: number
  message: string
  data?: TokenData
}

export interface PostItem {
  id: number
  content: string
  like_count: number
  comment_count: number
}

const BASE = 'http://10.0.2.2:8787' // 模拟器访问宿主机（真机改为局域网 IP）

export class ApiService {
  static accessToken: string = ''

  private static async request(method: http.RequestMethod, path: string,
                               body?: Record<string, Object>): Promise<any> {
    const client = http.createHttp()
    const extraData = body ? JSON.stringify(body) : undefined
    const res = await client.request(BASE + path, {
      method: method,
      header: {
        'Content-Type': 'application/json',
        ...(ApiService.accessToken ? { Authorization: 'Bearer ' + ApiService.accessToken } : {})
      },
      extraData: extraData,
      expectDataType: http.HttpDataType.STRING,
    })
    return JSON.parse(res.result as string)
  }

  static async login(email: string, password: string): Promise<LoginResult> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/auth/login', { email, password })
    if (r.data?.access_token) this.accessToken = r.data.access_token
    return r
  }

  static async register(email: string, password: string, nickname: string): Promise<LoginResult> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/auth/register', { email, password, nickname })
    if (r.data?.access_token) this.accessToken = r.data.access_token
    return r
  }

  static async timeline(): Promise<PostItem[]> {
    const r = await this.request(http.RequestMethod.GET, '/api/v1/posts')
    return r.data?.list ?? []
  }

  static async createPost(content: string): Promise<boolean> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/posts', { content })
    return r.code === 0
  }
}
