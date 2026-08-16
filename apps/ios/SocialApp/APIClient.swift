import Foundation

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

final class APIClient {
    static let shared = APIClient()
    private let base = "http://127.0.0.1:8787" // 模拟器访问宿主机
    private let session = URLSession.shared
    var accessToken: String = ""

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
            resp?.data.map { self.accessToken = $0.accessToken }
            completion(resp)
        }
    }

    func register(email: String, password: String, nickname: String, completion: @escaping (LoginResponse?) -> Void) {
        request("POST", "/api/v1/auth/register",
                body: ["email": email, "password": password, "nickname": nickname]) { data, _ in
            guard let data = data else { completion(nil); return }
            let resp = try? JSONDecoder().decode(LoginResponse.self, from: data)
            resp?.data.map { self.accessToken = $0.accessToken }
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
}
