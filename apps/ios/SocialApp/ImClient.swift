import Foundation

final class ImClient {
    static let shared = ImClient()
    var onEvent: ((String, [String: Any]) -> Void)?
    private var task: URLSessionWebSocketTask?
    private var host = ""
    private var lastToken = ""
    private var reconnectDelay: TimeInterval = 1
    private var pending: [[String: Any]] = [] // 未 ack 的 send 帧
    private let lock = NSLock()

    func connect(host: String, token: String) {
        self.host = host
        if token != lastToken { pending.removeAll() } // 换用户不重发旧 pending
        lastToken = token
        task?.cancel()
        guard let url = URL(string: "ws://\(host):8789?token=\(token)") else { return }
        let task = URLSession.shared.webSocketTask(with: url)
        self.task = task
        task.resume()
        lock.lock()
        let snapshot = pending
        lock.unlock()
        for frame in snapshot { task.send(.string(jsonString(frame))) { _ in } }
        receiveLoop(task)
    }

    func send(_ type: String, data: [String: Any]) {
        let frame: [String: Any] = ["type": type, "data": data]
        if type == "send", data["client_msg_id"] != nil {
            lock.lock()
            pending.append(frame)
            lock.unlock()
        }
        task?.send(.string(jsonString(frame))) { _ in }
    }

    private func receiveLoop(_ task: URLSessionWebSocketTask) {
        task.receive { [weak self] result in
            guard let self = self else { return }
            switch result {
            case .success(let msg):
                if case .string(let text) = msg { self.handle(text) }
                self.receiveLoop(task)
            case .failure:
                self.scheduleReconnect()
            }
        }
    }

    private func handle(_ text: String) {
        guard let data = text.data(using: .utf8),
              let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let type = root["type"] as? String else { return }
        let d = root["data"] as? [String: Any] ?? [:]
        if type == "ack", let cmid = d["client_msg_id"] as? String {
            lock.lock()
            pending.removeAll { ($0["data"] as? [String: Any])?["client_msg_id"] as? String == cmid }
            lock.unlock()
        }
        onEvent?(type, d)
    }

    private func jsonString(_ obj: [String: Any]) -> String {
        (try? JSONSerialization.data(withJSONObject: obj))
            .flatMap { String(data: $0, encoding: .utf8) } ?? "{}"
    }

    private func scheduleReconnect() {
        let delay = reconnectDelay + Double.random(in: 0..<reconnectDelay)
        reconnectDelay = min(reconnectDelay * 2, 30)
        DispatchQueue.global().asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self = self, !self.host.isEmpty else { return }
            self.connect(host: self.host, token: APIClient.shared.accessToken)
        }
    }
}
