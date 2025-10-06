Gemini 生成コードに関す運用ルール

目的

Geminiによって生成・更新されたコードの再現性・説明責任・安全性・監査性を確実に担保するための必須ルール群。  
Geminiが生成した成果物を自動的に追跡・検証し、人間が安全に判断・承認できる状態にする。

\---

適用範囲

すべての「Gemini が直接生成または大幅に編集したファイル」

Geminiの実行記録（コマンド実行ログ）

生成プロンプト・生成メタデータ・レビュー状態・チャンジログ

\---

定義

生成ファイル：Geminiによって作られた新規ファイル、あるいは Geminiが主導で編集したファイル。

生成メタ：生成日時、プロンプト原文（またはプロンプト参照）、モデル識別子、生成パラメータ（temperature, seed など）等。

Human-in-the-loop（HITL）：人間によるレビューと承認プロセス。

監査ログ（Audit Log）：Geminiコマンドを含む操作履歴の不変ログ。

\---

要点（短く）

1\. 生成ファイルは必ず ファイルヘッダに生成メタ を残す。

2\. 生成後は マージ／本番反映前に必ず人間のレビュー を受ける（自動チェックリストだけでは不可）。

3\. 大きな設計変更は 最低2つの代替案 と選定理由を必ず提出する。

4\. 生成は 再現可能かつ決定的（deterministic） にできるよう、すべての生成パラメータをログ・保存する。

\---

1\. 生成ファイルヘッダ（必須）

すべての生成ファイル先頭に、コメント形式で 下記メタデータブロック を入れる。

メタは YAML 風のキー: 値形式（パース可能）で記述すること。

機微情報（シークレット等）は保存してはいけない。もしプロンプトに機密が含まれるなら、プロンプトは「参照のみ」を保存し、実パラはシークレットストア参照にする。

ヘッダ項目（必須）

generated: true

generated\_at: ISO8601 (UTC)

generator\_model: モデル名 \+ バージョン（例: gpt-5-mini@2025-09-28）

generator\_id: モデルのユニーク識別子（プロバイダが出すID）

generator\_version\_hash: モデルやツールチェーン識別ハッシュ（可能なら）

prompt\_id: repository 内のプロンプトファイルパス（例: .prompts/xxx.md） または prompt\_hash

prompt\_snapshot\_path: （存在する場合）完全プロンプト保存先（暗号化またはアクセス制御）

system\_prompt\_summary: 50〜200文字の要約（プロンプト全文をヘッダに置かない場合）

temperature, top\_p, max\_tokens, seed（数値が無い場合は null と明記）

generation\_tool\_version: Gemini のバージョン

git\_sha\_at\_generation: 生成時のリポジトリ HEAD（短縮可）

task\_id（存在するなら）: Geminiに渡したタスク識別子

status: pending\_review | approved | rejected

human\_reviewer: null または user:timestamp:comment（承認後に埋める）

notes: 生成時の短い補足（任意）

例（Python コメントヘッダ）

\# \---  
\# generated: true  
\# generated\_at: 2025-09-28T11:22:33Z  
\# generator\_model: gpt-5-thinking-mini@2025-09-28  
\# generator\_id: provider:gpt-5-thinking-mini:2025-09-28  
\# generator\_version\_hash: ab12cd34  
\# prompt\_id: .prompts/orders\_create\_v1.md  
\# prompt\_snapshot\_path: .prompts/snapshots/orders\_create\_v1\_20250928.enc  
\# system\_prompt\_summary: "Implement safe payment flow; include logging and retries"  
\# temperature: 0.0  
\# top\_p: 1.0  
\# max\_tokens: 1024  
\# seed: 123456789  
\# generation\_tool\_version: cli-agent/1.3.0  
\# git\_sha\_at\_generation: a1b2c3d  
\# task\_id: task-20250928-001  
\# status: pending\_review  
\# human\_reviewer: null  
\# notes: "Generated with enforced logging rules"  
\# \---

\> ヘッダはファイル先頭の 200 行以内に置くこと。言語によるコメント形式に従う（\#、/\* ... \*/、//など）。

\---

2\. 生成メタ・プロンプト保存方針

プロンプト全文は原則で保存する（再現性のため）ただしプロンプトにシークレットが含まれる場合は保存しない。

保存先は ./.generated/prompts/\<prompt\_id\>/ を推奨し、アクセス制御（ACL）と暗号化を必須とする。

プロンプトは prompt.md（原文）、prompt.json（structured metadata）、response.json（LLM の raw response）を保存する。

prompt\_hash（SHA256）で参照できるようにする。プロンプトを丸ごとレポに置けない環境では prompt\_hash と prompt\_snapshot\_path（暗号化）をヘッダに残す。

\---

3\. Human-in-the-loop（HITL）運用

原則

生成ファイルは必ず人がレビューして status: approved にするまで main ブランチにマージしてはならない。

自動チェック（lint/test/security）が合格していても、人の承認がなければマージ不可。

実装方法（運用）

GitHub 等であれば Branch Protection → 必須レビュワー（Code Owners）を設定する。

PR テンプレートに 「生成物メタ」セクション を自動で埋める（下にテンプレあり）。

レビュー合格時、レビュワーはファイルヘッダ human\_reviewer を次の形式で更新すること：

human\_reviewer: "alice:2025-09-28T12:00:00Z:OK \- tests passed, no secrets"  
status: approved

Geminiは承認前に status: pending\_review を自動で設定する。人が承認するときにのみ approved にできるスクリプトを提供する。

レビュワーのチェック項目（必須）

生成ヘッダが存在し正しいか（必須フィールドの有無）

テストがあるか/CI が合格しているか

PII/Secret がないか（自動スキャン \+ 目視）

ライセンス問題がないか（新規依存がある場合）

代替案・設計理由が PR または design.md に記載されているか（必要時）

パフォーマンス・セキュリティ懸念に対する説明があるか

\---

4\. 代替案（Design Alternatives）ルール

大きな設計判断（構造変化／外部依存追加／アーキテクチャ変更等） を伴う場合、Gemini（または実装者）は必ず次を提出すること：

1\. 少なくとも 2つの実現案（案A, 案B）。

2\. 各案について 利点（Pros）/欠点（Cons）/リスク を箇条書きにする。

3\. 推奨案（Recommended）とその選定理由（コスト、労力、互換性、性能、セキュリティ）を短く書く。

4\. 重大な差分については概算の移行コスト（作業時間 or 危険度）を示す。

ドキュメント保存先：docs/designs/\<task\_id\>-alternatives.md。PR 本文にも短く要約する。

レビュワーは代替案が存在しない重大変更は拒否できる。

代替案テンプレ（抜粋）

\# Alternatives for task-20250928-001

\#\# Option A: Keep current architecture, add adapter layer  
Pros:  
\- Minimal breakage  
\- Quick to implement  
Cons:  
\- Slight runtime overhead  
Risk: Medium

\#\# Option B: Replace legacy module with new service  
Pros:  
\- Long-term maintainability  
\- Better scalability  
Cons:  
\- Breaking change, migration needed  
Risk: High

Recommended: Option A (reason: backward compatibility \+ short-term deliverable)

\---

5\. 再現性（Deterministic generation）ポリシー

生成を再現するために、以下のすべてのパラメータを保存すること（生成ヘッダ \+ prompt snapshot）：

generator\_model, generator\_id, generator\_version\_hash

generation\_tool\_version（CLI/SDK バージョン）

temperature, top\_p, max\_tokens, seed（乱数シード）

system\_prompt（保存できる場合）または system\_prompt\_summary

user\_prompt（原文、保存できない場合は prompt\_hash）

stop\_sequences（ある場合）

timestamp（UTC）

git\_sha\_at\_generation

推奨運用：決定的生成（再現性が必須のワークフロー）では temperature: 0.0 と seed を明示的に指定する。

再生成スクリプト：リポジトリに scripts/reproduce\_generation.sh（あるいは Python スクリプト）を置き、prompt\_id を与えるだけで同じ出力が得られることを目標にする（外部モデルのバージョン差で完全一致しない場合は差分説明を残す）。

\---

6\. Changelog（マージ時の必須追記ルール）

PR マージ時に必ず CHANGELOG に短いエントリを追加すること（自動化可）。

生成コード→CHANGELOG.md のエントリは下記テンプレに従う。

エントリ項目（必須）

date（YYYY-MM-DD）

author（PR 作成者）

type（feat|fix|refactor|chore|docs|security）

summary（1行）

details（1〜4行）: なぜ変更したか、何を変えたか、影響範囲

generated: true / false

meta: generator\_model, prompt\_id, git\_sha\_at\_generation, task\_id（任意のキー）

例

\- 2025-09-28 | alice | feat | Implement payment retry logic | Added PaymentProcessor with retry/backoff. Affects billing workflows. generated: true | meta: {generator\_model: "gpt-5-thinking-mini@2025-09-28", prompt\_id: ".prompts/payment\_retry\_v1.md", git\_sha: a1b2c3d}

7\. 監査ログ（Gemini操作ログ）

Geminiが実行するすべてのコマンドはappend-only JSONL（1行=1イベント）で保存する。保存先は中央監査ストア（推奨：セキュア S3 バケットや監査DB）。ローカルだけに残さない。

監査ログは改ざん防止を行う（WORM ストレージ、署名、あるいは順次ハッシュでチェーン化）。

監査ログ項目（必須）

timestamp（UTC ISO8601）

actor（CLI を実行したユーザーの ID）

command（実行されたコマンドの文字列）

args（省略可能／マスキング可）

cwd（作業ディレクトリ）

repo（リポジトリURL \+ git\_sha\_before \+ git\_sha\_after）

task\_id（該当する生成タスクがあれば）

prompt\_id（生成タスクに対応）

generator\_model / generator\_id（生成が含まれる場合）

result\_status（success/failure）

exit\_code（実行がある場合）

diff\_summary（生成・変更したファイルのリストと短い diff 統計）

approval\_required（true/false）

approved\_by（承認者情報があれば）

signature（ログ行を署名するための hmac/署名）

例（1行 JSON）

{  
  "timestamp":"2025-09-28T11:30:00Z",  
  "actor":"alice",  
  "command":"cli-agent generate \--task task-20250928-001",  
  "args":"--task task-20250928-001",  
  "cwd":"/workspace/project",  
  "repo":"git@github.com:org/repo.git",  
  "git\_sha\_before":"a1b2c3d",  
  "git\_sha\_after":"a1b2c3e",  
  "task\_id":"task-20250928-001",  
  "prompt\_id":".prompts/orders\_create\_v1.md",  
  "generator\_model":"gpt-5-thinking-mini@2025-09-28",  
  "result\_status":"success",  
  "exit\_code":0,  
  "diff\_summary":\[{"path":"src/orders.py","lines\_added":120,"lines\_removed":2}\],  
  "approval\_required":true,  
  "approved\_by":null,  
  "signature":"hmac:..."  
}

\---

8\. CI / 自動検証チェック（必須チェックリスト）

CI は PR 作成時に以下を自動実行し、全て合格でない限り approved にできない／保護ブランチへマージ不可とする：

1\. 生成ヘッダ検査：生成ファイルに正しいヘッダがあるか（必須フィールド）

2\. プロンプト存在確認：prompt\_id が指すファイルが存在するか（または prompt\_hash が snapshot に存在するか）

3\. 静的解析（lint/format）合格

4\. ユニットテスト合格（生成が機能に関する場合）

5\. セキュリティスキャン（シークレット検出、ライセンススキャン）合格

6\. 依存チェック（新規依存のライセンス/脆弱性）

7\. changelog 更新確認（生成系なら generated: true を明記）

8\. 監査ログ書込み完了（CI が監査ストアへログを push する）

\> CI は失敗詳細を PR にコメントする。自動チェックが合格しても、人の承認が必須。

9\. アクセス管理・セキュリティ

生成メタ、プロンプト、監査ログは最小権限でアクセス可能にする。

プロンプトに含まれ得る機密情報（顧客データ、APIキー）は保存禁止。代替として参照トークンを使う。

\---

10\. 例：PR テンプレ（自動で埋められるセクション）

\#\# 概要  
\<短い変更の要約\>

\#\# 生成メタ（自動挿入）  
\- generated: true  
\- generated\_at: 2025-09-28T11:22:33Z  
\- prompt\_id: .prompts/orders\_create\_v1.md  
\- generator\_model: gpt-5-thinking-mini@2025-09-28  
\- seed: 123456789  
\- status: pending\_review

\#\# 変更内容（diff summary）  
\- src/orders.py (+120/-2)  
\- tests/test\_orders.py (+30/-0)

\#\# 代替案（必要なら）  
\- Option A: ...  
\- Option B: ...  
\- Recommended: Option A (理由)

\#\# テスト  
\- Unit tests: PASS  
\- Lint: PASS  
\- Security scan: PASS

\#\# 承認  
\- Reviewer: \<to fill\>  
\- Approval timestamp: \<to fill\>  
\- Notes: \<to fill\>

\---

11\. レビュワー向けチェックリスト（最終確認）

\[ \] 生成ヘッダが存在し、必須フィールドが埋まっている。

\[ \] CI テスト（lint/test/security）がすべてパスしている。

\[ \] PII／シークレットの出力がない（自動スキャン \+ 目視）

\[ \] 代替案（必要時）と選定理由が明示されている。

\[ \] 再現に必要な prompt, seed, model 情報が存在する。

\[ \] CHANGELOG にエントリが追加されている。

\[ \] 監査ログが出力されている（CI またはエージェントが記録）。

\[ \] human\_reviewer ヘッダを埋め、status: approved に更新する（承認時）。

\---

付録 A：生成ファイルヘッダテンプレ（複数言語）

Python / Ruby / Shell: \# \--- ブロック（上の例参照）

Java / C / JS / TS: /\* \--- ... \--- \*/ ブロック

YAML / JSON ファイル: コメント不可な場合は別ファイル .generated/\<file\>.meta.json を作成する（必須）

例（JS）

/\*  
\---  
generated: true  
generated\_at: 2025-09-28T11:22:33Z  
generator\_model: gpt-5-thinking-mini@2025-09-28  
prompt\_id: .prompts/orders\_create\_v1.md  
temperature: 0.0  
seed: 123456789  
status: pending\_review  
\---  
\*/

\---

付録 B：再現スクリプト（雛形）

./scripts/reproduce\_generation.sh

\#\!/usr/bin/env bash  
\# usage: ./scripts/reproduce\_generation.sh \<prompt\_id\>  
PROMPT\_ID="$1"  
META\_DIR=".generated/prompts/${PROMPT\_ID}"  
if \[ \! \-d "$META\_DIR" \]; then  
  echo "prompt snapshot not found: $META\_DIR"  
  exit 1  
fi  
\# load metadata  
PROMPT\_JSON="$META\_DIR/prompt.json"  
MODEL=$(jq \-r .generator\_model "$PROMPT\_JSON")  
SEED=$(jq \-r .seed "$PROMPT\_JSON")  
TEMPERATURE=$(jq \-r .temperature "$PROMPT\_JSON")  
\# call CLI with fixed params  
cli-agent generate \--prompt "$META\_DIR/prompt.md" \--model "$MODEL" \--seed "$SEED" \--temperature "$TEMPERATURE"

