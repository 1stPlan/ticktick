# AI秘書（Assistant）

Gemini のプランに基づく「最強のAI秘書」アプリ。Laravel + Supabase (PostgreSQL + pgvector) + Livewire + PWA で構築されています。

## 主な機能

- **RAG（想起）**: 過去の会話をベクトル検索で記憶し、関連するコンテキストを召喚
- **チャットUI**: Livewire によるリアルタイム対話
- **PWA**: スマホのホーム画面に追加可能

## セットアップ

### 1. 環境変数

`.env` をコピーして設定：

```bash
cp .env.example .env
php artisan key:generate
```

### 2. データベース（Supabase または PostgreSQL）

**Supabase の場合:**
- [Supabase](https://supabase.com) でプロジェクト作成
- Database > Connection string で接続情報を取得
- pgvector 拡張は Supabase でデフォルト有効

```env
DB_CONNECTION=pgsql
DB_HOST=db.xxxx.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=your-password
```

**ローカル PostgreSQL の場合:**
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=assistant
DB_USERNAME=postgres
DB_PASSWORD=
```

### 3. OpenAI API

```env
OPENAI_API_KEY=sk-...
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_CHAT_MODEL=gpt-4o-mini
```

### 4. マイグレーション

```bash
php artisan migrate
```

### 5. 起動

```bash
npm install && npm run dev
php artisan serve
```

`http://localhost:8000` でアクセス

## アーキテクチャ

| レイヤー | 技術 |
|---------|------|
| フロントエンド | Livewire + PWA |
| バックエンド | Laravel 10 |
| データベース | Supabase (PostgreSQL) |
| ベクターDB | pgvector |
| AI | OpenAI GPT-4o mini / text-embedding-3-small |

## TickTick 連携

1. [TickTick Developer](https://developer.ticktick.com/manage) でアプリを作成
2. Redirect URI に `https://your-domain.com/ticktick/callback` を登録
3. `.env` に `TICKTICK_CLIENT_ID` と `TICKTICK_CLIENT_SECRET` を設定
4. アプリ内の「TickTick と連携」をクリックして OAuth 認証

連携後、「明日10時に〇〇するタスクを追加して」「今日のタスクは？」などと話しかけると、AI が TickTick を操作します。

## 今後の拡張（プランより）

- [ ] Supabase Auth による認証
- [x] TickTick API 連携
- [ ] 音声入力（Whisper）・音声出力（TTS）
- [ ] FCM プッシュ通知
