// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import Foundation
import SwiftUI

struct CallRecordItem: Codable, Identifiable {
    let id: Int64
    let callerId: Int64
    let calleeId: Int64
    let status: Int
    let createdAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case callerId = "caller_id"
        case calleeId = "callee_id"
        case status
        case createdAt = "created_at"
    }
}

struct VoiceRoomItem: Codable, Identifiable {
    let id: Int64
    let ownerId: Int64
    let name: String
    let onlineCount: Int
    let micCount: Int

    enum CodingKeys: String, CodingKey {
        case id
        case ownerId = "owner_id"
        case name
        case onlineCount = "online_count"
        case micCount = "mic_count"
    }
}

/// 语音 API。所有请求带 Authorization + X-Api-Version 头；
/// 语音文件 GET /api/v1/voice/{file} 同样需要带头播放。
final class VoiceApi {
    static let shared = VoiceApi()
    static let apiVersion = "v1"
    private let base = "http://127.0.0.1:8787" // 模拟器访问宿主机
    private let session = URLSession.shared
    private var token: String { APIClient.shared.accessToken }

    private init() {}

    func calls(page: Int = 1, completion: @escaping ([CallRecordItem]) -> Void) {
        request("GET", "/api/v1/voice/calls?page=\(page)") { data, _ in
            completion(self.listData(data, as: [CallRecordItem].self))
        }
    }

    func createRoom(name: String, completion: @escaping (Int64?) -> Void) {
        request("POST", "/api/v1/voice/rooms", form: ["name": name]) { data, _ in
            completion(self.dataDict(data)?["room_id"] as? Int64)
        }
    }

    func rooms(page: Int = 1, completion: @escaping ([VoiceRoomItem]) -> Void) {
        request("GET", "/api/v1/voice/rooms?page=\(page)") { data, _ in
            completion(self.listData(data, as: [VoiceRoomItem].self))
        }
    }

    func uploadVoice(fileURL: URL, completion: @escaping ([String: Any]?) -> Void) {
        let boundary = "----SocialBoundary" + String(Int(Date().timeIntervalSince1970 * 1000))
        var body = Data()
        body.append(Data("--\(boundary)\r\n".utf8))
        body.append(Data("Content-Disposition: form-data; name=\"voice\"; filename=\"voice.m4a\"\r\n".utf8))
        body.append(Data("Content-Type: audio/mp4\r\n\r\n".utf8))
        body.append((try? Data(contentsOf: fileURL)) ?? Data())
        body.append(Data("\r\n--\(boundary)--\r\n".utf8))
        request("POST", "/api/v1/im/voice", body: body,
                contentType: "multipart/form-data; boundary=\(boundary)") { data, _ in
            completion(self.dataDict(data))
        }
    }

    private func request(_ method: String, _ path: String, form: [String: String]? = nil,
                         body: Data? = nil, contentType: String = "application/json",
                         completion: @escaping (Data?, Error?) -> Void) {
        var req = URLRequest(url: URL(string: base + path)!)
        req.httpMethod = method
        req.setValue(Self.apiVersion, forHTTPHeaderField: "X-Api-Version")
        if !token.isEmpty {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        if let form = form {
            let pairs = form.map { "\($0.key)=\($0.value)" }.joined(separator: "&")
            req.httpBody = Data(pairs.utf8)
            req.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        } else if let body = body {
            req.httpBody = body
            req.setValue(contentType, forHTTPHeaderField: "Content-Type")
        }
        session.dataTask(with: req) { data, _, error in
            completion(data, error)
        }.resume()
    }

    private func dataDict(_ data: Data?) -> [String: Any]? {
        guard let data = data,
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        return root["data"] as? [String: Any]
    }

    private func listData<T: Decodable>(_ data: Data?, as type: T.Type) -> T {
        guard let d = dataDict(data), let list = d["list"],
              let json = try? JSONSerialization.data(withJSONObject: list) else { return [] as! T }
        return (try? JSONDecoder().decode(type, from: json)) ?? ([] as! T)
    }
}

/// 来电 UI 骨架：call_* 信令由 ImClient.onEvent 驱动（媒体面 WebRTC 未实现）。
struct CallView: View {
    @State private var status = "等待来电…"
    @State private var peerId: Int64 = 0
    @State private var canAnswer = false

    var body: some View {
        VStack(spacing: 16) {
            Text(status)
            HStack {
                Button("接听") { send("call_accept") }.disabled(!canAnswer)
                Button("拒绝") { send("call_reject") }.disabled(!canAnswer)
            }
        }
        .onAppear {
            ImClient.shared.onEvent = { type, data in
                DispatchQueue.main.async {
                    switch type {
                    case "call_invite":
                        peerId = data["caller_id"] as? Int64 ?? 0
                        status = "来电 用户#\(peerId)"
                        canAnswer = true
                    case "call_accept": status = "通话中（WebRTC TODO）"
                    case "call_cancel", "call_reject", "call_hangup", "call_failed", "call_timeout":
                        status = "通话结束"
                        canAnswer = false
                    case "call_offer", "call_answer", "call_ice":
                        // TODO 真机联调：WebRTC 媒体面，本里程碑不实现
                        status = "已收到 \(type)（WebRTC TODO）"
                    default: break
                    }
                }
            }
        }
    }

    private func send(_ type: String) {
        ImClient.shared.send(type, data: ["peer_id": peerId])
        canAnswer = false
    }
}

/// 语聊房 UI 骨架：room_* 信令处理（媒体面 WebRTC 未实现）。
struct VoiceRoomView: View {
    @State private var rooms: [VoiceRoomItem] = []
    @State private var status = "房间列表"
    @State private var roomId: Int64 = 0
    @State private var micOn = false

    var body: some View {
        VStack(spacing: 12) {
            Text(status)
            List(rooms) { room in
                Button("\(room.name)  #\(room.id)  在线\(room.onlineCount) 麦\(room.micCount)") {
                    roomId = room.id
                    ImClient.shared.send("room_join", data: ["room_id": room.id])
                    status = "已加入房间 #\(room.id)"
                }
            }
            HStack {
                Button(micOn ? "下麦" : "上麦") {
                    micOn.toggle()
                    ImClient.shared.send(micOn ? "room_up_mic" : "room_down_mic", data: ["room_id": roomId])
                }.disabled(roomId == 0)
                Button("刷新") { load() }
            }
        }
        .onAppear { load() }
    }

    private func load() {
        VoiceApi.shared.rooms { items in
            DispatchQueue.main.async { rooms = items }
        }
    }
}
