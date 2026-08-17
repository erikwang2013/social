import Foundation
import Security

struct TokenData: Codable {
    let accessToken: String
    let refreshToken: String
    let expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case expiresIn = "expires_in"
    }
}

struct LoginResponse: Codable {
    let code: Int
    let message: String
    let langKey: String
    let data: TokenData?

    enum CodingKeys: String, CodingKey {
        case code, message, data
        case langKey = "lang_key"
    }
}

struct PostItem: Codable, Identifiable {
    let id: Int64
    let content: String
    let likeCount: Int
    let commentCount: Int

    enum CodingKeys: String, CodingKey {
        case id, content
        case likeCount = "like_count"
        case commentCount = "comment_count"
    }
}

struct RelationData: Codable {
    let isFollowing: Bool
    let followerCount: Int
    let followingCount: Int

    enum CodingKeys: String, CodingKey {
        case isFollowing = "is_following"
        case followerCount = "follower_count"
        case followingCount = "following_count"
    }
}

struct UserBrief: Codable {
    let id: Int64
    let nickname: String
}

struct NotificationItem: Codable, Identifiable {
    let id: Int64
    let type: String
    let content: String
    let read: Bool
    let actor: UserBrief?

    enum CodingKeys: String, CodingKey {
        case id, type, content, read, actor
    }
}

enum Keychain {
    private static let service = "com.social.app"

    static func save(_ value: String, forKey key: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecValueData as String: Data(value.utf8),
        ]
        SecItemDelete(query as CFDictionary)
        SecItemAdd(query as CFDictionary, nil)
    }

    static func load(_ key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }
}

final class APIClient {
    static let shared = APIClient()
    private let base = "http://127.0.0.1:8787" // 模拟器访问宿主机
    private let session = URLSession.shared
    var accessToken: String = ""

    private init() {
        accessToken = Keychain.load("access_token") ?? ""
    }

    func request(_ method: String, _ path: String, body: [String: Any]? = nil,
                 completion: @escaping (Data?, Error?) -> Void) {
        var req = URLRequest(url: URL(string: base + path)!)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if !accessToken.isEmpty {
            req.setValue("Bearer \(accessToken)", forHTTPHeaderField: "Authorization")
        }
        if let body = body {
            req.httpBody = try? JSONSerialization.data(withJSONObject: body)
        }
        session.dataTask(with: req) { data, _, error in
            completion(data, error)
        }.resume()
    }

    func login(email: String, password: String, completion: @escaping (LoginResponse?) -> Void) {
        request("POST", "/api/v1/auth/login", body: ["email": email, "password": password]) { data, _ in
            guard let data = data else { completion(nil); return }
            let resp = try? JSONDecoder().decode(LoginResponse.self, from: data)
            resp?.data.map {
                self.accessToken = $0.accessToken
                Keychain.save($0.accessToken, forKey: "access_token")
            }
            completion(resp)
        }
    }

    func register(email: String, password: String, nickname: String, completion: @escaping (LoginResponse?) -> Void) {
        request("POST", "/api/v1/auth/register",
                body: ["email": email, "password": password, "nickname": nickname]) { data, _ in
            guard let data = data else { completion(nil); return }
            let resp = try? JSONDecoder().decode(LoginResponse.self, from: data)
            resp?.data.map {
                self.accessToken = $0.accessToken
                Keychain.save($0.accessToken, forKey: "access_token")
            }
            completion(resp)
        }
    }

    func timeline(completion: @escaping ([PostItem]) -> Void) {
        request("GET", "/api/v1/posts") { data, _ in
            guard let data = data,
                  let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let d = root["data"] as? [String: Any],
                  let list = d["list"] else { completion([]); return }
            let listData = try? JSONSerialization.data(withJSONObject: list)
            let items = listData.flatMap { try? JSONDecoder().decode([PostItem].self, from: $0) } ?? []
            completion(items)
        }
    }

    func createPost(content: String, completion: @escaping (Bool) -> Void) {
        request("POST", "/api/v1/posts", body: ["content": content]) { data, _ in
            guard let data = data,
                  let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                completion(false); return
            }
            completion((root["code"] as? Int) == 0)
        }
    }

    func me(completion: @escaping (Int64?) -> Void) {
        request("GET", "/api/v1/me") { data, _ in
            completion(self.dataDict(data)?["user_id"] as? Int64)
        }
    }

    func follow(id: Int64, completion: @escaping (Bool) -> Void) {
        request("POST", "/api/v1/users/\(id)/follow") { data, _ in completion(self.ok(data)) }
    }

    func unfollow(id: Int64, completion: @escaping (Bool) -> Void) {
        request("POST", "/api/v1/users/\(id)/unfollow") { data, _ in completion(self.ok(data)) }
    }

    func relation(id: Int64, completion: @escaping (RelationData?) -> Void) {
        request("GET", "/api/v1/users/\(id)/relation") { data, _ in
            guard let d = self.dataDict(data),
                  let json = try? JSONSerialization.data(withJSONObject: d) else { completion(nil); return }
            completion(try? JSONDecoder().decode(RelationData.self, from: json))
        }
    }

    func notifications(completion: @escaping ([NotificationItem]) -> Void) {
        request("GET", "/api/v1/notifications") { data, _ in
            guard let d = self.dataDict(data),
                  let list = d["list"],
                  let json = try? JSONSerialization.data(withJSONObject: list) else { completion([]); return }
            completion((try? JSONDecoder().decode([NotificationItem].self, from: json)) ?? [])
        }
    }

    func unreadCount(completion: @escaping (Int) -> Void) {
        request("GET", "/api/v1/notifications/unread-count") { data, _ in
            completion(self.dataDict(data)?["unread_count"] as? Int ?? 0)
        }
    }

    func markAllRead(completion: @escaping (Bool) -> Void) {
        request("POST", "/api/v1/notifications/read-all") { data, _ in completion(self.ok(data)) }
    }

    private func dataDict(_ data: Data?) -> [String: Any]? {
        guard let data = data,
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        return root["data"] as? [String: Any]
    }

    private func ok(_ data: Data?) -> Bool {
        guard let data = data,
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return false }
        return (root["code"] as? Int) == 0
    }
}
