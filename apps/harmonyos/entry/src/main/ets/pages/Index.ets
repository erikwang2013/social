import { http } from '@kit.NetworkKit';

@Entry
@Component
struct Index {
  @State health: string = 'checking…';

  aboutToAppear(): void {
    const req = http.createHttp();
    req.request('http://127.0.0.1:8787/health', (err, data) => {
      this.health = err ? 'error' : (data.responseCode === 200 ? 'ok' : `fail:${data.responseCode}`);
      req.destroy();
    });
  }

  build() {
    Column({ space: 16 }) {
      Text('Social').fontSize(32)
      Text(this.health).fontSize(14)
    }.width('100%').height('100%').justifyContent(FlexAlign.Center)
  }
}
