import SwiftUI

struct ContentView: View {
    @State private var loggedIn = false

    var body: some View {
        if loggedIn {
            TimelineView()
        } else {
            LoginView(onSuccess: { loggedIn = true })
        }
    }
}

struct LoginView: View {
    let onSuccess: () -> Void
    @State private var email = ""
    @State private var password = ""
    @State private var nickname = ""
    @State private var message = ""

    var body: some View {
        VStack(spacing: 16) {
            TextField("邮箱", text: $email).textFieldStyle(.roundedBorder)
            SecureField("密码", text: $password).textFieldStyle(.roundedBorder)
            TextField("昵称(注册)", text: $nickname).textFieldStyle(.roundedBorder)
            Button("登录") {
                APIClient.shared.login(email: email, password: password) { resp in
                    DispatchQueue.main.async {
                        if resp?.code == 0 { onSuccess() } else { message = resp?.message ?? "登录失败" }
                    }
                }
            }
            Button("注册并登录") {
                APIClient.shared.register(email: email, password: password, nickname: nickname) { resp in
                    DispatchQueue.main.async {
                        if resp?.code == 0 { onSuccess() } else { message = resp?.message ?? "注册失败" }
                    }
                }
            }
            Text(message).foregroundColor(.red)
        }
        .padding()
    }
}

struct TimelineView: View {
    @State private var posts: [PostItem] = []
    @State private var content = ""
    @State private var unread = 0

    var body: some View {
        NavigationStack {
            VStack {
                HStack {
                    TextField("说点什么…", text: $content).textFieldStyle(.roundedBorder)
                    Button("发布") {
                        APIClient.shared.createPost(content: content) { _ in
                            DispatchQueue.main.async { content = ""; load() }
                        }
                    }
                }
                .padding()
                List(posts) { post in
                    VStack(alignment: .leading) {
                        Text(post.content)
                        Text("♥\(post.likeCount)  💬\(post.commentCount)")
                            .font(.caption).foregroundColor(.gray)
                    }
                }
            }
            .navigationTitle("时间线")
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    NavigationLink("通知\(unread > 0 ? "(\(unread))" : "")") { NotificationView() }
                    NavigationLink("主页") { UserProfileView() }
                }
            }
            .onAppear(perform: load)
        }
    }

    func load() {
        APIClient.shared.timeline { items in
            DispatchQueue.main.async { posts = items }
        }
        APIClient.shared.unreadCount { n in
            DispatchQueue.main.async { unread = n }
        }
    }
}

struct UserProfileView: View {
    @State private var userId: Int64 = 0
    @State private var relation: RelationData?

    var body: some View {
        VStack(spacing: 16) {
            if let r = relation {
                Text("粉丝 \(r.followerCount)   关注 \(r.followingCount)")
                Button(r.isFollowing ? "取消关注" : "关注") {
                    if r.isFollowing {
                        APIClient.shared.unfollow(id: userId) { _ in load() }
                    } else {
                        APIClient.shared.follow(id: userId) { _ in load() }
                    }
                }
                .buttonStyle(.borderedProminent)
            } else {
                Text("加载中…")
            }
        }
        .navigationTitle("我的主页")
        .onAppear(perform: load)
    }

    func load() {
        APIClient.shared.me { id in
            guard let id = id else { return }
            DispatchQueue.main.async { userId = id }
            APIClient.shared.relation(id: id) { r in
                DispatchQueue.main.async { relation = r }
            }
        }
    }
}

struct NotificationView: View {
    @State private var items: [NotificationItem] = []

    var body: some View {
        List(items) { n in
            HStack {
                VStack(alignment: .leading) {
                    Text(label(n))
                    Text(n.content).font(.caption).foregroundColor(.gray)
                }
                Spacer()
                if !n.read {
                    Circle().fill(.red).frame(width: 8, height: 8)
                }
            }
        }
        .navigationTitle("通知")
        .toolbar {
            Button("全部已读") {
                APIClient.shared.markAllRead { _ in load() }
            }
        }
        .onAppear(perform: load)
    }

    func label(_ n: NotificationItem) -> String {
        let name = n.actor?.nickname ?? ""
        switch n.type {
        case "like": return "\(name) 赞了你的动态"
        case "comment": return "\(name) 评论了你的动态"
        case "follow": return "\(name) 关注了你"
        default: return "\(name) \(n.type)"
        }
    }

    func load() {
        APIClient.shared.notifications { list in
            DispatchQueue.main.async { items = list }
        }
    }
}
