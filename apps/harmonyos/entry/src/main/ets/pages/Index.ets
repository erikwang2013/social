import { ApiService, PostItem } from '../service/ApiService'

@Entry
@Component
struct Index {
  @State email: string = ''
  @State password: string = ''
  @State nickname: string = ''
  @State message: string = ''
  @State loggedIn: boolean = false
  @State posts: PostItem[] = []
  @State content: string = ''

  build() {
    Column() {
      if (this.loggedIn) {
        this.timelinePanel()
      } else {
        this.loginPanel()
      }
    }
    .width('100%')
    .height('100%')
  }

  @Builder
  loginPanel() {
    Column({ space: 12 }) {
      TextInput({ placeholder: '邮箱', text: this.email })
        .onChange(v => this.email = v)
      TextInput({ placeholder: '密码', text: this.password })
        .type(InputType.Password)
        .onChange(v => this.password = v)
      TextInput({ placeholder: '昵称(注册)', text: this.nickname })
        .onChange(v => this.nickname = v)
      Button('登录').width('100%').onClick(async () => {
        const r = await ApiService.login(this.email, this.password)
        if (r.code === 0) { this.loggedIn = true } else { this.message = r.message }
      })
      Button('注册并登录').width('100%').onClick(async () => {
        const r = await ApiService.register(this.email, this.password, this.nickname)
        if (r.code === 0) { this.loggedIn = true } else { this.message = r.message }
      })
      Text(this.message).fontColor(Color.Red)
    }
    .padding(24)
    .margin({ top: 80 })
  }

  @Builder
  timelinePanel() {
    Column() {
      Row({ space: 8 }) {
        TextInput({ placeholder: '说点什么…', text: this.content })
          .layoutWeight(1)
          .onChange(v => this.content = v)
        Button('发布').onClick(async () => {
          const ok = await ApiService.createPost(this.content)
          if (ok) { this.content = ''; await this.load() }
        })
      }
      .padding(12)

      List({ space: 8 }) {
        ForEach(this.posts, (post: PostItem) => {
          ListItem() {
            Column() {
              Text(post.content).width('100%')
              Text(`♥${post.like_count}  💬${post.comment_count}`)
                .fontSize(12).fontColor(Color.Gray).width('100%')
            }
            .alignItems(HorizontalAlign.Start)
            .padding(12)
          }
        }, (post: PostItem) => post.id.toString())
      }
      .layoutWeight(1)
    }
    .onAppear(() => { this.load() })
  }

  async load() {
    this.posts = await ApiService.timeline()
  }
}
