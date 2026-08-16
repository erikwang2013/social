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

    var body: some View {
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
        .onAppear(perform: load)
    }

    func load() {
        APIClient.shared.timeline { items in
            DispatchQueue.main.async { posts = items }
        }
    }
}
