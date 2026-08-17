import { ApiService, PostItem, RelationData, NotificationItem } from '../service/ApiService'

@Entry
@Component
struct Index {
  @State email: string = ''
  @State password: string = ''
  @State nickname: string = ''
  @State message: string = ''
  @State loggedIn: boolean = false
  @State page: string = 'timeline'
  @State posts: PostItem[] = []
  @State content: string = ''
  @State userId: number = 0
  @State relation: RelationData | null = null
  @State notifications: NotificationItem[] = []
  @State unread: number = 0
  @State notifTitle: string = '通知'

  build() {
    Column() {
      if (this.loggedIn) {
        if (this.page === 'profile') {
          this.profilePanel()
        } else if (this.page === 'notifications') {
          this.notificationPanel()
        } else {
          this.timelinePanel()
        }
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

      Row({ space: 8 }) {
        Button(this.notifTitle).onClick(() => { this.page = 'notifications' })
        Button('主页').onClick(() => { this.page = 'profile' })
      }
      .width('100%').padding({ left: 12, right: 12, bottom: 8 })

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

  @Builder
  profilePanel() {
    Column({ space: 12 }) {
      Button('返回').onClick(() => { this.page = 'timeline' })
        .width('100%').margin({ bottom: 8 })
      if (this.relation) {
        Text(`粉丝 ${this.relation.follower_count}   关注 ${this.relation.following_count}`)
        Button(this.relation.is_following ? '取消关注' : '关注').width('100%').onClick(async () => {
          const r = this.relation
          if (r) {
            if (r.is_following) {
              await ApiService.unfollow(this.userId)
            } else {
              await ApiService.follow(this.userId)
            }
            await this.loadProfile()
          }
        })
      } else {
        Text('加载中…')
      }
    }
    .padding(24)
    .onAppear(() => { this.loadProfile() })
  }

  @Builder
  notificationPanel() {
    Column() {
      Row({ space: 8 }) {
        Button('返回').onClick(() => { this.page = 'timeline' })
        Button('全部已读').onClick(async () => {
          await ApiService.markAllRead()
          await this.loadNotifications()
        })
      }
      .width('100%').padding(12)

      List({ space: 8 }) {
        ForEach(this.notifications, (n: NotificationItem) => {
          ListItem() {
            Row() {
              Column() {
                Text(this.notifLabel(n)).width('100%')
                Text(n.content).fontSize(12).fontColor(Color.Gray).width('100%')
              }
              .alignItems(HorizontalAlign.Start)
              .layoutWeight(1)
              if (!n.read) {
                Circle({ width: 8, height: 8 }).fill(Color.Red)
              }
            }
            .width('100%')
            .padding(12)
          }
        }, (n: NotificationItem) => n.id.toString())
      }
      .layoutWeight(1)
    }
    .onAppear(() => { this.loadNotifications() })
  }

  notifLabel(n: NotificationItem): string {
    const name = n.actor ? n.actor.nickname : ''
    if (n.type === 'like') { return `${name} 赞了你的动态` }
    if (n.type === 'comment') { return `${name} 评论了你的动态` }
    if (n.type === 'follow') { return `${name} 关注了你` }
    return `${name} ${n.type}`
  }

  async load() {
    this.posts = await ApiService.timeline()
    this.unread = await ApiService.unreadCount()
    this.notifTitle = this.unread > 0 ? `通知(${this.unread})` : '通知'
  }

  async loadProfile() {
    this.userId = await ApiService.me()
    if (this.userId > 0) {
      this.relation = await ApiService.relation(this.userId)
    }
  }

  async loadNotifications() {
    this.notifications = await ApiService.notifications()
    this.unread = await ApiService.unreadCount()
    this.notifTitle = this.unread > 0 ? `通知(${this.unread})` : '通知'
  }
}
