# LINE 連携セットアップ

## 1. LINE Developers でチャネル作成

1. [LINE Developers](https://developers.line.biz/) にログイン
2. プロバイダーを作成（未作成の場合）
3. **Messaging API** チャネルを作成
4. チャネル基本設定から以下を取得:
   - **Channel secret** → `LINE_CHANNEL_SECRET`
   - **Channel access token**（長期）→ `LINE_CHANNEL_ACCESS_TOKEN`

## 2. .env に設定

```env
LINE_CHANNEL_SECRET=your_channel_secret
LINE_CHANNEL_ACCESS_TOKEN=your_channel_access_token
```

## 3. Webhook URL の設定

LINE Developers コンソール > Messaging API 設定 > Webhook URL に以下を設定:

```
https://your-domain.com/api/line/webhook
```

- **Webhook の利用**: オン
- **自動応答メッセージ**: オフ（推奨）

## 4. 動作確認

1. LINE アプリで公式アカウントを友だち追加
2. メッセージを送信（例: 「こんにちは」）
3. AI 秘書が応答すれば成功

## 5. TickTick 連携（LINE ユーザー）

1. LINE で「連携」や「TickTick」と送信
2. 返ってきたリンクをタップ
3. ブラウザで TickTick 認証を完了
4. 「連携完了」ページが表示されたら LINE に戻る
5. 予定の確認やタスク追加が可能に

## 6. デフォルトプロジェクトの設定

LINE 専用ユーザー向けの設定画面（LIFF）は今後の拡張で対応可能です。現時点では、タスク追加時はデフォルトプロジェクトが未設定の場合はインボックスに保存されます。
