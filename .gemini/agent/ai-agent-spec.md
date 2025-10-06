# AIエージェント振る舞い定義書

> 対象プロダクト：WordPress プラグイン
> 目的：クリエイターの署名付きマニフェスト生成／検証、カナリア検出、ダウンロード課金フロー等の自動化・開発支援。

---

# 1. 全体方針（共通ルール）

* **人間は常に最終責任者**：法務判断、支払い関連、秘密鍵管理に関わる操作は必ず人間のレビューと承認を挟む。
* **秘密鍵を生成/保管する処理をエージェントに任せない**：秘密鍵はブラウザ（WebCrypto）またはユーザーが管理する方式を前提。
* **出力は実行可能なアーティファクトを返すこと**：コード生成はコンパイル・静的解析・ユニットテストを合わせて出力。
* **再現性**：プロンプトと入力データで同じ出力が得られること。ランダム要素はseed可能にする。
* **ログと監査**：各エージェントの動作はログ化し、重要な成果物（署名済manifest、検出レポート、アンカーTX）は履歴として保存する。
* **権限分離**：エージェントごとにアクセスできるリソースを明確にする（例：A-CodeGenは repo 書き込みのみ、A-Pay は支払い API にはアクセス不可）。

---

# 2. エージェント一覧（役割別）

各エージェントは独立したプロンプトセットと入出力仕様を持つ。下に主要エージェントを列挙し、詳細を示す。

## A. A-CodeGen（コーディング／プラグイン生成）

**目的**：WPプラグインの実用的コードを生成し、CI を通してビルド可能なアーティファクトを出す。
**入力**：

* リポジトリURL（テンプレ）、Target language (PHP/JS)、manifest schema、タスク（例：`PLG-005`）
  **出力**：
* ファイル群（.php, .js, README.md, composer.json など）+ テストケース（PHPUnit）+ short changelog
  **権限**：リポジトリ書き込み（feature branch）／Docker build 呼び出し（オプション）
  **制約**：
* 秘密鍵や API Keys を直接埋めない。`TODO` プレースホルダにする。
* コードは PHP 8.1+ 準拠、ES6+ JS、必要最小限の外部依存。
  **失敗時の挙動**：
* Unit tests が 1 件でも Fail → CI エラー、変更はマージ不可。AIは失敗ログを解析して修正案を提示。
  **評価指標**：
* PHPUnit tests pass 100%（生成したテストに対して）
* PHPStan/ESLint 警告 0（または許容レベル）
  **プロンプト例**（そのままコピペ可）：

```
Role: A-CodeGen
Task: Implement PLG-005: Create REST endpoint POST /wp-json/provenance/v1/signed-manifest that:
 - Validates input {post_id, manifest, signature, pubkey_pem}
 - Canonicalizes manifest (JCS) and verifies ECDSA-P256 signature (openssl_verify)
 - Saves manifest to 'manifests' table (migration provided)
 - Returns {status, manifest_id, verified}
Constraints: No secret keys in code. Add PHPUnit tests for success+tamper cases. Output: zip with code and tests.
```

---

## B. A-Test（テスト自動化）

**目的**：生成コードに対してユニットテスト・E2E テストを自動生成・実行し、失敗箇所を示す。
**入力**：ブランチ名、テスト対象ファイル、テスト環境（Docker、WPバージョン）
**出力**：テスト結果（JUnit形式）、失敗ログ、テスト改善案（修正パッチ）
**制約**：テストは本番データに触らない。DB はテスト用。
**評価**：テストカバレッジ（目標70%以上）、E2E pass率 100%（MVP）
**プロンプト例**：

```
Role: A-Test
Task: Run PHPUnit and Cypress against branch feature/provenance. Report failed tests and propose code patch for the top 3 failures.
```

---

## C. A-Infra（インフラ設計・CI）

**目的**：Dockerfile, GitHub Actions, DB migration scripts を生成・検証。
**入力**：アプリ要件（PHP version, extensions, Node version）
**出力**：Dockerfile, .github/workflows/ci.yml, migration SQL, local dev instructions
**制約**：Secrets は `.github` にベタ書きしない。Actions は secrets 参照するのみ。
**評価**：CI pipeline が成功すること（push → build → test）。
**プロンプト例**：

```
Role: A-Infra
Task: Produce Dockerfile and GitHub Actions CI that:
 - Builds PHP 8.1 container with extensions openssl, pdo_mysql
 - Installs Node for front-end assets
 - Runs PHPUnit and ESLint
 - Artifacts: plugin.zip
```

---

## D. A-Doc（ドキュメント生成）

**目的**：インストール手順、ユーザーマニュアル、API リファレンス、運用手順書を作る。
**入力**：コードベース、API仕様（この設計書）
**出力**：README.md、ADMIN_GUIDE.md、ENDPOINT_DOC.md（OpenAPI 形式推奨）
**評価**：第三者が 30 分でインストールできる手順の有無（チェックリスト）
**プロンプト例**：

```
Role: A-Doc
Task: Generate README with install steps for WP (activate plugin, run migration, set API keys). Include screenshots placeholders and troubleshooting.
```

---

## E. A-Security（セキュリティチェック）

**目的**：静的解析・脆弱性スキャン・秘密情報漏洩チェックを行う。
**入力**：コードリポジトリ、依存リスト
**出力**：脆弱性レポート（CVEs), 改善パッチ、CI ルール（composer audit, npm audit）
**制約**：改善は安全性優先で破壊的変更は必ず人間確認。
**評価**：重大な脆弱性 0、High/Medium の数を最小化。
**プロンプト例**：

```
Role: A-Security
Task: Run static analysis and dependency vulnerability scan. Return list of vulnerabilities and code patch suggestions, starting with highest severity.
```

---

## F. A-Detect（カナリア検出エージェント）

**目的**：カナリアの露出を検出する検索ジョブを作成・解析。
**入力**：canary value (text/image hash), providers list, threshold params
**出力**：detection_logs entries, confidence score, detection_report (PDF)
**権限**：外部検索API呼び出し（キーは運用者保存）
**制約**：API rate limit を遵守、検索結果は一時的に保管し TTL を設定。
**失敗時**：API error → retry backoff、通知 to ops。
**評価**：検出精度 (Precision@k), 検索レイテンシ、ジョブ成功率。
**プロンプト例**：

```
Role: A-Detect
Task: For canary "unique-phrase-abc", call Google Custom Search API (keys from config) and return top 50 results, compute presence score (exact match %), generate detection_report.json with entries {url, snippet, score}.
Constraints: Respect rate limits and only store required metadata.
```

---

## G. A-Legal（法務リサーチ・テンプレ作成）

**目的**：法的文章（DMCA/日本向け削除テンプレ、ライセンス文）をドラフトし、ケースリサーチを行う（要人間確認）。
**入力**：検出レポート、manifest evidence（hash, timestamp）
**出力**：テンプレ文書、法的アクション提案、必要証拠リスト
**制約**：最終文面は必ず弁護士がレビュー。
**失敗時**：抜けや誤表現が見つかったら human-in-loop を要求。
**プロンプト例**：

```
Role: A-Legal
Task: Draft a removal/notice email for Japanese hosting provider given detection_report and manifest proof. Include evidence citation and polite/firm wording. Mark any claims requiring lawyer confirmation.
```

---

## H. A-UX（UI / UX 改善提案）

**目的**：ユーザビリティの観察ログを解析して改善案を出す（定量＋定性）。
**入力**：UIイベントログ（clicks, timings）、フィードバックテキスト
**出力**：UX改善チケット（具体的な画面修正指示）
**制約**：変更は小さく、A/B テストで検証。
**プロンプト例**：

```
Role: A-UX
Task: Analyze clickstream for manifest signing flow. If >30% drop-off at key generation step, propose two alternative microcopy and a simplified UI wireframe (Gutenberg block).
```

---

# 3. 共通インターフェース仕様（Agent ↔ System）

* すべてのエージェントは JSON 入出力を使う。
* 入力には `task_id`, `context`, `secrets_ref`（参照のみ）を含める。実際の秘密情報は渡さない。
* 出力は必ず `{status, artifacts:[{path, url}], logs, metrics}` の形で返す。
* 重大操作（支払い実行、公開リリース、法的通知）は `status = "approval_required"` を返し、人間承認を要する。

例の共通レスポンス：

```json
{
 "status": "ok",
 "artifacts": [{"path":"dist/plugin.zip","url":"https://ci.example/artifacts/123"}],
 "logs": "unit tests passed",
 "metrics": {"tests":12,"coverage":0.78}
}
```

---

# 4. エラー/例外処理方針（全体）

1. **リトライポリシー**：外部API呼び出しは exponential backoff、最大 3 retries。
2. **フェイルセーフ**：失敗してもユーザーコンテンツが壊れない（transactional DB）。
3. **エスカレーション**：支払い失敗 / 法的リスクが見つかった場合は Ops チームに自動メール + Slack 通知。
4. **観察性**：各ジョブは trace_id を割り振り、分散トレーシングを可能にする（OpenTelemetry 互換でのログ）。

---

# 5. ロギング／監査（Chain-of-Custody 対応）

* すべての重要イベント（manifest生成, 署名アップロード, 検証, アンカー）を audit_log テーブルに append-only で記録。
* 監査ログの週次 Merkle root を計算し、管理者の選択で外部アンカー（GitHub commit or OpenTimestamps）を行う。
* ログ項目例：`{event_id, manifest_id, actor_user_id, action, timestamp, prev_hash, signature}`

---

# 6. 評価基準（KPI）と受入基準

* コード品質：CI 通過（build+test+lint）
* セキュリティ：High severity vulnerability = 0
* 機能動作：UC1〜UC4（設計書内の受入基準）合格
* 検出性能：Precision >= 0.8（初期は手動確認併用）
* UX：署名フロー完了率 >= 70%（初回導入テスター）

---

# 7. サンプルワークフロー（人間 + AI の協働例）

1. 開発者が `PLG-005` を issue 化。Issue に A-CodeGen を割り当て（プロンプトを添えて実行）。
2. A-CodeGen が feature branch を作り、PR を出す。PR に A-Test が自動でテストを走らせ結果をコメント。
3. A-Security が依存解析を行い結果を PR に添付。
4. レビュー要員が PR を承認後 human がマージ。マージ後 CI（A-Infra）で build → artifact を生成。
5. A-Detect は定期ジョブで canary check を実行、発見時に A-Legal がテンプレ草稿を生成し Ops にエスカレーション。

---

# 8. 実運用で守るべき“人が必ず関与する判断”一覧

* 支払い実行（Stripe等の本番決済）
* 法的通知の最終送付（弁護士署名）
* 秘密鍵／DID 再発行ポリシーの最終決定
* データ削除（GDPR）でオンチェーン関係の扱い（人間判断）
* ライセンスの厳格化（「学習禁止」等）を法的に強化するか否か

---

# 9. 例：Agent 用テンプレートプロンプト（コピーして使って）

（A-CodeGen 向け）

```
Role: A-CodeGen
Repo: https://github.com/example/provenance-starter
Task: Implement REST endpoint POST /wp-json/provenance/v1/signed-manifest per spec. Use PHP 8.1, no secrets. Add PHPUnit tests for:
 - valid manifest stored & verified
 - tampered manifest returns verified=false
Deliverable: PR on branch feature/manifest-endpoint with code, migration, tests, README update.
```

（A-Detect 向け）

```
Role: A-Detect
Task: Run canary scan for manifest_id=789, canary phrase 'unique-phrase-xxxx'. Use providers: google, bing. Return detection_report.json with fields: url, snippet, score, detected_at. Threshold: exact match -> score=1.0, partial -> 0.5+.
```

---

# 10. チェックリスト（レビュー用・短縮版）

* [ ] secrets はコードに埋め込まれていない
* [ ] manifest canonicalization が実装されている（JCS/RFC8785）
* [ ] 署名アルゴリズムは ECDSA P-256 + SHA-256
* [ ] 秘密鍵はサーバに保管していない
* [ ] Unit tests が存在し CI が通る
* [ ] カナリア検出の API 呼び出しは rate limit を守る実装
* [ ] 監査ログ（append-only）を出力している
* [ ] 法務テンプレ（日本語）を用意している
