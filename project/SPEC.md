# 目的（PoC / MVP の一文）

クリエイター（漫画家・イラストレーター等）が自分の作品を自己所有で公開・保全し、作品ごとに**署名付きマニフェスト**を自動生成・公開、さらに**カナリア検出**と**ダウンロード時の課金ゲート**を組み合わせて「無断利用の検出と経済的抑止」を実現する。最小実装は WordPress プラグインで提供し、将来PWA/モバイルに拡張可能な設計。

---

# 目次

1. 要件（機能要件／非機能要件）
2. 業務フロー図（テキスト図）
3. 機能一覧（詳細）
4. データフロー図（DFD） — レベル0/1
5. 構成図（アーキテクチャ）
6. API仕様（主要エンドポイント）
7. データモデル（DBスキーマ／manifest JSON スキーマ）
8. AIエージェント利用設計（役割・プロンプト例・入出力）
9. セキュリティ・法務・運用要点（重要事項）
10. テスト・受入基準・MVP スケジュール
11. 開発タスク分解（Issue レベル）

---

# 1. 要件

## 機能要件（必須：MVP）

* FR1: 投稿保存時に **manifest** を自動生成（content_hash, content_uri, created_at, author.pubkey）。
* FR2: ユーザーがブラウザで公開鍵・秘密鍵（ECDSA P-256 推奨）を生成し、秘密鍵はローカル（ブラウザ）保管。公開鍵を WP に登録できる。
* FR3: ブラウザで manifest に署名し、署名済 manifest（署名、公開鍵）をサーバに保存。
* FR4: 投稿ページに「検証バッジ」を表示（署名検証OK/NG/未署名）。
* FR5: カナリア（テキスト/画像）を作品に埋め込む簡易ジェネレータ（ユーザー選択／自動）。
* FR6: 管理画面にカナリア検出ダッシュボード（手動/定期スキャン可）。初期は手動スキャンボタン。
* FR7: ダウンロード保護：フルファイルは暗号化して配布、ダウンロード時は支払い（テストは擬似）→鍵発行で復号可能にするUX。
* FR8: manifest のエクスポート（JSON/CSV）、オンチェーンアンカー（オプション）を行う API 呼び出し用ボタン。
* FR9: ログ（検証履歴・アンカー履歴・カナリア検出ログ）を保存／エクスポート。

## 非機能要件

* NFR1: 秘密鍵はサーバに保存しない（原則）。署名はクライアントサイドで完結。
* NFR2: manifest は canonical JSON 形式で署名対象を固定化する（JCS または RFC8785 準拠）。
* NFR3: すべての外部API呼び出しは非同期キューで制御（rate limit, retry）。
* NFR4: 最低限のユーザー数で動くよう CPUのみでの実行を前提（重い類似検索はサンプリング）。
* NFR5: GDPR 等削除要求に対応できるデータ削除フローを用意（オンチェーンはハッシュのみ）。

---

# 2. 業務フロー図（テキスト図）

## A. 作品公開（署名付きマニフェスト生成）フロー

```
[クリエイター: ブラウザ] 
   │ (1) 投稿作成（画像/テキスト）
   │
   ├─ (2) ブラウザで鍵生成（WebCrypto） -> 秘密鍵はブラウザに保存 / 公開鍵をサーバに登録
   │
   ├─ (3) ブラウザで content_hash を計算（SHA-256 of canonical content）
   │
   ├─ (4) manifest(JSON canonical) を組み立て
   │
   ├─ (5) ブラウザで manifest を秘密鍵で署名 -> signature (base64)
   │
   └─ (6) 署名済 manifest + 公開鍵をサーバに POST -> 保存 (post_meta / manifest table)
        → 表示ページに検証バッジを表示
```

## B. 検証／第三者確認フロー

```
[検証者: Web] -> (検証ボタン押下)
   ├─ (1) フロントからサーバへ manifest を要求
   ├─ (2) サーバ返却 manifest + signature + pubkey
   ├─ (3) クライアント側（またはサーバ側）で canonical manifest を再構築し signature を検証 (verify)
   └─ (4) 結果を UI に表示（OK/NG/未署名）
```

## C. カナリア検出（初期は手動）

```
[管理者] -> (1) ダッシュボードでカナリア一覧を確認 -> (2) 検索ボタン押下
   ├─ (3) サーバが外部検索APIに問い合わせ (検索ワード or image pHash)
   ├─ (4) 検出結果を受け取り、類似度閾値で判断
   └─ (5) 検出ログを保存・アラート（メール）
```

## D. ダウンロード課金フロー（シンプル）

```
[閲覧者]
  ├─ (1) プレビュー表示
  ├─ (2) ダウンロードボタン -> JS が課金モーダルを表示（Stripe等）
  ├─ (3) 支払い完了 -> サーバは鍵(短期)を発行
  ├─ (4) ブラウザは暗号化ファイルをダウンロードし、鍵で復号 -> 完全ファイル入手
```

---

# 3. 機能一覧（詳細でタスク化しやすく）

### ユーザー管理

* U1: WP ユーザープロファイルに `pubkey_pem`, `did`, `key_fingerprint`
* U2: 鍵生成 UI（WebCrypto） + 秘密鍵ダウンロード／バックアップ案内
* U3: 鍵リプレース（公開鍵更新）フローと既存 manifest の再署名案内

### マニフェスト管理

* M1: manifest 自動生成（post save hook）
* M2: manifest署名アップロードエンドポイント
* M3: manifest 表示・エクスポート（JSON）機能
* M4: manifest canonicalizer（署名対象の一貫化）

### 検証機能

* V1: 検証ボタン（クライアントサイドで verify）
* V2: 検証履歴ログ（timestamp, verifier_ip, result）
* V3: 署名アルゴリズムの選択（ECDSA-P256推奨）

### カナリア管理

* C1: カナリアジェネレータ（テキスト／画像）
* C2: カナリアタグ（manifest に埋め込み）
* C3: 検索API プラグイン（Google Custom Search, Bing, TinEye, SNS API）
* C4: pHash/類似度チェック（画像）、embedding-basedチェック（テキスト、将来）

### ダウンロード保護／課金

* P1: ファイル暗号化（AES-256）で保存 or on-demand encryption
* P2: 支払いゲート（Stripe の test flow で実装）
* P3: 鍵発行 API（短期トークン TTL）
* P4: クライアント側復号ライブラリ（JS）で復号して保存を可能にする

### アンカー／外部保存

* A1: IPFS ピン操作（ユーザーが API キー提供）または GitHub commit にハッシュ記録
* A2: OpenTimestamps 対応ボタン（アンカー履歴を manifest に追記）

### 管理／運用

* Ops1: ジョブキュー（検出リクエスト、アンカー処理）
* Ops2: ログストレージ（S3 or local）とエクスポート
* Ops3: 設定画面（API keys, quotas）
* Ops4: エラーハンドリングと通知（メール/Slack）

---

# 4. データフロー図（DFD）

図はレベル0（全体）とレベル1（重要な詳細）で示す。

## DFD レベル0（概観）

```
[ユーザー] --> (1) -> [WP プラグイン]
[WP プラグイン] --> (2) -> [Manifest DB]
[WP プラグイン] --> (3) -> [外部検索API / IPFS / OpenTimestamps]
[検証者] --> (4) -> [WP プラグイン] -> verify -> [Manifest DB]
```

## DFD レベル1（詳細）

```
[ブラウザ (Creator)]
  ├─ POST /publish -> [WP プラグイン] (生成: content_hash)
  ├─ POST /pubkey -> [WP プラグイン] (store pubkey)
  └─ POST /signed-manifest -> [Manifest DB]

[WP プラグイン]
  ├─ Reads: posts, attachments
  ├─ Writes: manifest_table, logs_table
  ├─ Calls: ExternalSearchAPI (検出), IPFS Pin API (保存), Stripe API (支払い)
  └─ Returns: verification_result

[ExternalSearchAPI] <--> [WP プラグイン] (Crawl/Check)
[IPFS/OpenTimestamps] <--> [WP プラグイン] (Anchor/Pin)
```

---

# 5. 構成図（アーキテクチャ） — テキスト／ASCII 図

```
                                 +----------------+
                                 |  Third-party   |
                                 |  Services      |
                                 | (Google/Bing,  |
                                 |  IPFS, OTS,    |
                                 |  Stripe)       |
                                 +----------------+
                                          ^
                                          |
                                          |
+-----------+      HTTPS     +----------------------+    Queue    +-------------+
| Browser   | <------------>  |  WordPress Server    | <--------> | Job Worker  |
| (Creator, |                | - WP + Plugin        |            | (cron/queue)|
|  Verifier)|                | - PHP endpoints      |            | - search    |
+-----------+                | - manifest storage   |            | - pin/anchor|
      |                      +----------------------+            +-------------+
      |                                |
      |                                |
      v                                v
+-----------+                    +---------------+
| Browser   |                    | Storage       |
| WebCrypto |                    | - Media (WP)  |
| (keys)    |  local keys         | - Manifest DB |
+-----------+                    | - Logs        |
                                 +---------------+
```

**説明**：

* Browser は秘密鍵を保持し署名処理を行う（WebCrypto）。サーバには公開鍵と署名済 manifest を渡す。
* WP Server は manifest を保管（post_meta あるいは独立テーブル）、表示・検証 API を提供。
* Job Worker は外部API呼び出し（検出／アンカー）をキュー処理し、結果を DB とダッシュボードに反映。
* 外部サービスは検索API、IPFS/Arweave、OpenTimestamps、Stripe など。

---

# 6. API仕様（主要エンドポイント：REST / JSON）

> 認証：WP の既存認証（Cookie）を用いる。外部APIは API Key を管理画面で設定。

### 1) POST /api/pubkey

* 説明：ユーザーの公開鍵を登録
* 入力：

```json
{ "user_id": 123, "pubkey_pem": "-----BEGIN PUBLIC KEY-----...\n" }
```

* 出力：

```json
{ "status": "ok", "fingerprint": "sha256:aaa..." }
```

### 2) POST /api/signed-manifest

* 説明：署名済 manifest を保存
* 入力：

```json
{
 "post_id": 456,
 "manifest": { /* canonical JSON */ },
 "signature": "base64sig",
 "pubkey_pem": "..."
}
```

* 出力：

```json
{ "status":"ok", "manifest_id": 789, "verified": true }
```

### 3) GET /api/manifest/{post_id}

* 説明：manifest を取得（公開）
* 出力：manifest JSON

### 4) POST /api/verify/{manifest_id}

* 説明：サーバ内で検証（オプション）。基本はクライアント検証。
* 出力：

```json
{ "verified": true, "verifier": "server", "timestamp": "..." }
```

### 5) POST /api/scan-canary

* 説明：手動でカナリア検索をキュー登録
* 入力：

```json
{ "manifest_id": 789, "search_providers": ["google","bing"] }
```

* 出力：

```json
{ "queued": true, "job_id": "job-xxx" }
```

### 6) POST /api/request-download/{file_id}

* 説明：支払い／鍵発行フローの開始
* 入力：

```json
{ "file_id": 999, "buyer_email": "a@b.c" }
```

* 出力：

```json
{ "checkout_url": "https://checkout.test/...", "payment_id": "pay-xxx" }
```

### 7) GET /api/anchor/{manifest_id}

* 説明：オンチェーンアンカー（ボタン押下でキュー）
* 出力：

```json
{ "queued": true, "anchor_tx": null }
```

---

# 7. データモデル（DBスキーマ）

### テーブル: manifests

| カラム           |        型 | 説明                   |
| ------------- | -------: | -------------------- |
| id            |   int PK | manifest id          |
| post_id       |      int | WP post id           |
| content_uri   |  varchar | media URL / IPFS URI |
| content_hash  |  varchar | sha256:...           |
| manifest_json |     text | canonical JSON       |
| signature_b64 |     text | base64               |
| pubkey_pem    |     text | author pubkey        |
| verified      |     bool | 検証結果                 |
| created_at    | datetime | 保存時刻                 |
| anchored      |     bool | オンチェーンアンカー済み         |
| anchor_info   |     text | tx/hash details      |

### テーブル: canaries

| カラム         |                    型 | 説明           |
| ----------- | -------------------: | ------------ |
| id          |               int PK |              |
| manifest_id |               int FK |              |
| type        | enum('text','image') |              |
| value       |                 text | canary value |
| created_at  |             datetime |              |

### テーブル: detection_logs

| カラム         |        型 | 説明                 |
| ----------- | -------: | ------------------ |
| id          |   int PK |                    |
| canary_id   |   int FK |                    |
| source_url  |     text | 検出URL              |
| provider    |  varchar | google/bing/tineye |
| score       |    float | 類似度                |
| detected_at | datetime |                    |
| actioned    |     bool |                    |

### テーブル: download_requests

| カラム            |                               型 | 説明 |
| -------------- | ------------------------------: | -- |
| id             |                          int PK |    |
| file_id        |                             int |    |
| buyer_email    |                         varchar |    |
| payment_status | enum('pending','paid','failed') |    |
| key_issued     |                            bool |    |
| issued_at      |                        datetime |    |

---

## manifest JSON スキーマ（canonical）

必ずこのスキーマで canonical JSON を生成し署名対象にする。JCS 準拠でソートされたキー順を前提。

```json
{
  "manifest_version": "1.0",
  "post_id": 456,
  "content_uri": "https://example.com/wp-content/uploads/2025/10/abc.jpg",
  "content_hash": "sha256:3f8a...9b2c",
  "content_type": "image/jpeg",
  "created_at": "2025-10-05T10:00:00Z",
  "author": {
    "user_id": 123,
    "display_name": "作者名",
    "pubkey_fingerprint": "sha256:aaa...",
    "did": "did:example:xxxx"
  },
  "license": "All Rights Reserved. No training without paid license.",
  "canaries": [
    {"type":"text","value":"unique-phrase-xxxx"}
  ],
  "provenance": [
    /* array of prior events: {event_type, actor, timestamp, note} */
  ]
}
```

署名対象は上記 `manifest` を UTF-8 bytes にして JCS canonicalization 後、EcdsaP256/SHA256 で署名（Base64）。

---

# 8. AIエージェント利用設計（エンジニア向け）

目的：AI をコード生成・テスト自動化・CI スクリプト生成に利用する。以下は Agent の役割と入出力テンプレート。

## Agent 役割

* A-CodeGen: プラグインの PHP/JS/HTML コードを生成
* A-Test: 自動ユニット／E2E テスト生成（PHPUnit / Cypress）
* A-Infra: Docker + CI (GitHub Actions) ワークフローを生成
* A-Doc: README、導入手順、ユーザー向けガイド生成
* A-Security: 静的解析ルール（PHPStan, ESLint）・脆弱性チェックテンプレ生成

## プロンプトテンプレ（例：A-CodeGen に渡す）

```
Role: A-CodeGen
Task: Generate a minimal WordPress plugin (PHP) named "provenance-starter" with:
 - Hook into 'save_post' to compute SHA-256 of featured image and create canonical manifest JSON (schema provided below).
 - Expose REST endpoint POST /wp-json/provenance/v1/signed-manifest to accept {post_id, manifest, signature, pubkey_pem}.
 - Save manifest into a new DB table 'manifests' with fields (post_id, manifest_json, signature_b64, pubkey_pem, created_at).
 - Implement a public verification function that verifies ECDSA-P256 signatures using openssl_verify.
 - Include client-side JS (using WebCrypto) to generate key pair, compute content hash (file input), canonicalize manifest and sign it, then POST to endpoint.
Constraints: Keep code minimal, no external PHP dependencies, use JSON canonicalization function (pseudo provided) and clear TODOs where developer should replace env variables.
Deliverables: plugin.zip containing PHP files and JS assets, and short README with install steps.
```

### AI 入出力形式

* 入力：ファイルパス、manifest schema、coding style guide、constraints (no DB migrations beyond WP standards)
* 出力：コードファイル一式 + テストケース（unit tests） + README

## 自動テストシナリオ（Agent が生成）

* T1: 公開鍵登録 → 署名済 manifest POST → manifest 保存 → verify returns true
* T2: manifest 改ざんシナリオ → verify returns false
* T3: カナリア検出ワークフロー（模擬検索API）→ detection_log 入力生成

---

# 9. セキュリティ・法務・運用（重要事項）

## セキュリティ

* 秘密鍵は**ブラウザのみ**に保存。暗号化を推奨（WebCrypto `CryptoKey` + IndexedDB exportable false）。ユーザーにはバックアップ方法（PEM ダウンロード）を明示。
* 公開鍵の登録は HTTPS のみ。署名検証は canonical JSON を再構築して行う。署名アルゴリズムは ECDSA P-256 + SHA-256 を推奨。
* サーバ側では署名済 manifest と公開鍵のみを保存。秘密鍵は保存しない。
* 支払い鍵（Stripe）等の API Keys は WP 設定で暗号化して保存（wp_options に保管する場合はオプション暗号化ライブラリを使用）。
* ログの保護：検証ログ・発見ログは改ざん防止（append-only ファイル or DB with tamper alerts）。必要なら定期的に Merkle root を外部にアンカー。

## 法務

* manifest に「機械可読ライセンス（例: `no-training=true`）」を明記しておく。法的効力は国・事案により差があるため、テンプレ法的文面は弁護士レビュー推奨。
* DMCA/削除要求テンプレを用意し自動化する。ただし送信前は人的確認ルールを設ける（誤送信のリスク）。
* GDPR 対応：個人データの扱い、削除要求フローを定義。オンチェーンに個人データを置かない。

## 運用

* 定期ジョブ（cron / queue worker）でカナリアスキャンを実行。外部APIは有償枠を想定し、ユーザー毎にAPIキー設定機能を持たせる（初期はユーザー自己設定）。
* モニタリング：エラー率、署名検証失敗件数、キュー滞留時間を監視。
* バックアップ：manifest DB と logs を定期的にバックアップ。必要なら IPFS/Arweave にハッシュをエクスポート。

---

# 10. テスト・受入基準・MVP スケジュール（30日）

## 30日ロードマップ（短期MVP）

* Day 0–3: プロジェクト初期構成（Git repo, plugin skeleton, DB migration）
* Day 4–10: 投稿保存で manifest を生成し DB 保存（テストコンテンツ3件）
* Day 11–16: ブラウザ鍵生成 + manifest 署名 UI（最低限）を実装
* Day 17–20: /signed-manifest エンドポイント + verify 機能を実装
* Day 21–24: 公開ページで検証バッジを表示、検証ボタンを動かす
* Day 25–28: カナリアジェネレータ（テキスト） + 手動スキャンボタンを追加
* Day 29–30: テスト（T1~T3）、README と導入ガイド作成

## 受入基準（完成とみなす条件）

* UC1: 署名済 manifest を投稿に紐づけて保存できること
* UC2: 公開ページで検証ボタンを押すと `verified:true` を返すこと
* UC3: カナリアの手動検索を起動し、検出ログが生成されること（モックでも可）
* UC4: ダウンロード課金フロー（テストモード）で鍵発行して復号できること（プロトタイプ）

---

# 11. 開発タスク分解（Issue レベル・優先順）

## 高優先（MVP コア）

1. PLG-001: プラグインスケルトン作成（ヘッダ、Activation hook）
2. PLG-002: manifests テーブル作成マイグレーション
3. PLG-003: post_save hook で content_hash と content_uri を作る
4. PLG-004: manifest canonicalizer 関数実装（JCS）
5. PLG-005: REST endpoint /signed-manifest 実装（保存 + verify）
6. PLG-006: Simple UI: 公開鍵登録フォーム（profile）
7. PLG-007: ブラウザ鍵生成 + manifest 署名 JS（WebCrypto）
8. PLG-008: 検証ボタンとバッジ表示
9. PLG-009: Unit tests (PHPUnit) for manifest generation & verify
10. PLG-010: README: インストール手順（日本語）

## 次フェーズ（MVP後）

11. CAN-001: カナリアジェネレータ（テキスト）
12. CAN-002: スキャンジョブキューと外部検索APIコネクタ（モック含む）
13. PAY-001: ダウンロード暗号化 & payment mock flow（Stripe）
14. ANCH-001: IPFS pin / OpenTimestamps ボタン（キュー化）
15. OPS-001: ログ／監視実装

---

# 付録：推奨技術スタック（簡潔）

* サーバ：WordPress (PHP 8.x)
* DB：MySQL（WP デフォルト）
* フロント：Vanilla JS / small module（WebCrypto）
* Job Worker：WP Cron / WP-CLI / PHP Resque（最初はWP cron）
* Search API：Google Custom Search / Bing Web Search（初期は手動検証 or mock）
* 支払い：Stripe（test mode）
* 署名アルゴリズム：ECDSA P-256 (secp256r1) + SHA-256
* JSON canonicalization：JCS (JSON Canonicalization Scheme) or RFC8785
* CI：GitHub Actions

