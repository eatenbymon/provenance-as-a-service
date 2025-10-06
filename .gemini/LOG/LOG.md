概要（Purpose）

このタスクは「アプリケーションのすべての重要処理に対して、構造化ログを一貫して出力する機能」を実装することを目的とします。  
主目的：

障害時の原因追跡を速くすること

モニタリング／アラートの信頼性を高めること

PII等の漏洩を防ぎつつフォレンジックに耐える監査ログを残すこと

\---

要件（Requirements）

ログ形式：構造化 JSON（1行1イベント）

必須フィールド（全イベントに共通、下記スキーマ参照）

相関情報：全リクエストに trace\_id と request\_id を付与・伝播すること

PII対策：生データ（パスワード、完全なカード番号、SSN 等）はログに出さない。ハッシュ／マスクで出すこと

レベル：DEBUG/INFO/WARN/ERROR/CRITICAL を厳守。運用ルールに従い本番では DEBUG を通常無効化。

サンプリング：成功系はサンプリング（設定可能）、エラーは基本全保存。

メトリクス連携：p50/p90/p99 レイテンシ算出が可能なデータを出力すること（duration\_ms）

モデル利用時（AI）：推論イベントは model\_version と input\_hash などのメタを含める（PII は除外・ハッシュ化）。

可観測性ツール：OpenTelemetry 互換であれば歓迎（trace \-\> logs 連携を想定）

\---

ログイベント スキーマ（推奨 JSON）

{  
  "timestamp": "2025-09-28T10:15:30.123Z",  
  "service": "orders-api",  
  "env": "production",  
  "version": "git:abc123",  
  "level": "ERROR",  
  "event": "charge\_failed",  
  "message": "Payment processor returned 402",  
  "trace\_id": "6f1e...b2",  
  "span\_id": "aa11",  
  "request\_id": "r-12345",  
  "user\_id\_hash": "sha256:...",  
  "duration\_ms": 512,  
  "status\_code": 402,  
  "error\_type": "PaymentFailedError",  
  "stacktrace": "...",  
  "tags": {"region":"ap-northeast-1", "feature\_flag":"new-checkout"},  
  "meta": {"external\_api":"stripe", "attempts":3}  
}

備考：user\_id\_hash のように PII はハッシュで保存する。meta は任意のキー値を入れる場所。

\---

実装指針（what to log / where）

優先度順にログを残す場所と中身：

1\. エラー / 例外（必須）

stacktrace、例外タイプ、発生箇所、trace\_id、入力コンテキスト（PII除去）

2\. リクエスト終了ログ（成功・失敗ともに）

route, status\_code, duration\_ms, trace\_id, user\_id\_hash（存在する場合）

3\. 外部呼び出し（DB, API, キュー）

backend id, latency\_ms, response\_status, retries

4\. セキュリティ / 監査イベント

ログイン成功/失敗、認可拒否、権限変更、APIキーの使用（誰が、いつ、どこで）

5\. 重要状態遷移 / バッチ完了

ジョブID, start/end, success/fail, affected\_count

6\. モデル推論

model\_version, input\_hash, latency\_ms, confidence（ただし機微情報は省く or マスク）

7\. デバッグ詳細（開発/ステージングでのみ有効化）

\---

実装例（コードスニペット）

Python — FastAPI ミドルウェア（簡易）

\# logging\_middleware.py  
import time, uuid, hashlib, logging, json  
from starlette.middleware.base import BaseHTTPMiddleware

logger \= logging.getLogger("app")

def hash\_pii(value: str) \-\> str:  
    return "sha256:" \+ hashlib.sha256(value.encode()).hexdigest()

def new\_trace\_id() \-\> str:  
    return uuid.uuid4().hex

class StructuredLoggingMiddleware(BaseHTTPMiddleware):  
    async def dispatch(self, request, call\_next):  
        trace\_id \= request.headers.get("X-Request-ID") or new\_trace\_id()  
        start \= time.time()  
        try:  
            response \= await call\_next(request)  
            status \= response.status\_code  
            return response  
        except Exception as exc:  
            status \= 500  
            raise  
        finally:  
            duration\_ms \= int((time.time() \- start) \* 1000\)  
            event \= {  
                "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime()),  
                "service": "orders-api",  
                "env": "production",  
                "version": "git:abc123",  
                "level": "ERROR" if status \>= 500 else "INFO",  
                "event": "http\_request",  
                "message": f"{request.method} {request.url.path}",  
                "trace\_id": trace\_id,  
                "request\_id": getattr(request.state, "request\_id", None),  
                "duration\_ms": duration\_ms,  
                "status\_code": status,  
            }  
            logger.info(json.dumps(event))

TypeScript — Express ミドルウェア（簡易）

// loggingMiddleware.ts  
import { Request, Response, NextFunction } from "express";  
import crypto from "crypto";  
import { v4 as uuidv4 } from "uuid";

function hashPii(v: string) {  
  return "sha256:" \+ crypto.createHash("sha256").update(v).digest("hex");  
}

export function loggingMiddleware(service \= "api") {  
  return (req: Request, res: Response, next: NextFunction) \=\> {  
    const traceId \= req.headers\["x-request-id"\] || uuidv4();  
    const start \= Date.now();  
    res.on("finish", () \=\> {  
      const duration \= Date.now() \- start;  
      const log \= {  
        timestamp: new Date().toISOString(),  
        service,  
        env: process.env.NODE\_ENV || "development",  
        version: process.env.VERSION || "dev",  
        level: res.statusCode \>= 500 ? "ERROR" : "INFO",  
        event: "http\_request",  
        message: \`${req.method} ${req.path}\`,  
        trace\_id: traceId,  
        status\_code: res.statusCode,  
        duration\_ms: duration,  
      };  
      console.log(JSON.stringify(log));  
    });  
    next();  
  };  
}

\---

テスト要件（自動テストで検証すること）

trace\_id 伝播テスト：リクエストに X-Request-ID があるとログに同じ trace\_id が含まれる。

PII マスキングテスト：入力に email/cc\_number を渡しても生の値がログに含まれない（ハッシュまたはマスクされている）。

エラー時のスタック出力：例外発生時、ログに error\_type と stacktrace が含まれる。

duration\_ms が存在し合理的な範囲（\>=0）であること。

サンプリングロジック：成功系ログのサンプリング比率が設定通りに動作する（確率的テストで確認）。

モデルログ：推論イベントが model\_version を含む（該当機能がある場合）。

テストの例（Python pytest 風）：

def test\_trace\_propagation(client, caplog):  
    headers \= {"X-Request-ID": "trace-123"}  
    client.get("/health", headers=headers)  
    assert "trace-123" in caplog.text

\---

受け入れ基準（Acceptance Criteria）

1\. すべての CI テストが通る（ユニット \+ ログ検査テスト）。

2\. 本番向けのデフォルト設定で DEBUG ログが出ない。

3\. 重要イベント（エラー・監査・外部呼び出し）に必須フィールドが含まれる。

4\. PII はログに残らない（自動テストで検証済み）。

5\. trace\_id が全プロセスで伝播することを確認。

6\. ログは JSON 1行形式で出力され、外部集約（例：ELK / Datadog / Logflare）でパース可能。

\---

サンプリング・保持・コスト管理（運用ルール）

成功リクエスト：デフォルト 5% サンプリング（設定可能）

エラー：全保存

監査ログ：全保存で最低 1 年（規制有ならそれに準拠）

DEBUG：ステージング 100%、本番 0%（feature-flagで一時的に上げられる）

古いログは圧縮してコールドストレージへ。保持ポリシーは明記する（例：ERROR=365日、INFO=90日、DEBUG=7日）

\---

実装を自動化するための LLM プロンプト（テンプレート）

\[CONTEXT\]  
Project: \<プロジェクト名\>  
Language: \<python|typescript|go|...\>  
Framework: \<例: FastAPI | Express\>  
ServiceName: \<サービス名\>  
Env: \<production|staging|development\>

\[TASK\]  
1\) 以下のログ仕様に従いミドルウェアとユーティリティを実装する:  
   \- 構造化JSONログ  
   \- trace\_id/request\_id の注入・伝播  
   \- PII マスク/ハッシュ関数  
   \- 外部呼び出しラッパ（latency, status, retries をログ）  
   \- model 推論ログ（存在する場合）  
2\) すべての公開 API/エンドポイントでリクエスト終了ログ（duration, status）を出すこと。  
3\) 単体テストを作成する（trace\_id, PII マスキング, error stack）。  
4\) README に実装内容と設定項目（サンプリング率、保持期間）を記載する。

\[LOG\_SCHEMA\]  
（ここに先の JSON スキーマを貼る）

\[ACCEPTANCE\]  
\- 全ユニットテスト合格  
\- 本番設定で DEBUG ログ無し  
\- PII テスト合格  
\- 1行JSONログであること

\[OUTPUT\_FORMAT\]  
\- 生成するファイルの path と content を列挙して返す（files オブジェクト）  
\- テストの実行結果サンプル（期待値）を返す

\---

デプロイ後の運用（短く）

デプロイ時に version を自動挿入（git SHA）。

ログをダッシュボード化：エラー率、p99 レイテンシ、外部API失敗率を SLO として監視。

異常検知：エラー率の急騰 or p99 レイテンシ上昇でアラート。

定期レビュー：保持ポリシーとコストの最適化（月次）。

\---

チェックリスト（LLMが実装したらレビュワーが確認すること）

\[ \] 1行JSON 構造化ログになっている。

\[ \] trace\_id がリクエスト→内部呼び出しに渡っている。

\[ \] PII はマスク／ハッシュされテストで検証済み。

\[ \] エラーは stacktrace を含む。

\[ \] サンプリング設定が環境設定可能になっている。

\[ \] テスト（trace, PII, duration）が CI で通る。

\[ \] README に運用ルール（保持期間・サンプリング率）が記載されている。

\[ \] ログ出力に service, env, version が含まれている。

\---

注意点（トレードオフの明示）

詳細ログは原因追跡に強力だがコストとプライバシー負担が増える。

サンプリングは検査コストを下げるがレアケース調査の再現性を下げる。

本設計では「エラー全保存 \+ 成功系サンプリング」でバランスを取ることを推奨。

\---

すぐ使える短い指示（ワンライナー：コピペ用）

\> このリポジトリで 構造化JSONログのミドルウェア を実装してください。trace\_id の注入・伝播、PII マスキング、リクエスト終了ログ（duration,status）、外部呼び出しラップ、ユニットテスト（trace, PII, error stack）を含め、出力は1行JSONとすること。デフォルトで本番は DEBUG オフ、成功ログは 5% サンプリング、エラーは全保存。README に設定方法と保持ポリシーを追記してください。  
