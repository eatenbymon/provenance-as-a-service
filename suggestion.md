# **推奨案（優先順・短評）**

## **A) GitHub Pages（静的サイト） \+ ブラウザ署名（推奨・最短で検証可）**

**利点**：無料、公開しやすい、既存の開発ツールで扱いやすい。CI（GitHub Actions）でアンカーやIPFSピンも自動化できる。  
 **欠点**：ダイナミック機能（課金ゲートや複雑な監視）は別サービスで補う必要あり。  
 **MVP（今すぐできる）**：

1. GitHubにリポジトリ作成 → GitHub Pages を有効化。

2. シンプルな静的ポートフォリオ（HTML）を作る。

3. ブラウザ側JSでファイルのSHA-256を計算し、JCSでcanonical manifestを作る。

4. WebCryptoで鍵生成→manifest署名→manifest.json をリポジトリに commit（GitHub API）or push（ユーザーのPATを使うか、代わりに manifest をGistで公開）。

5. GitHub Actionsで manifest のハッシュを OpenTimestamps や Git commit にアンカーするジョブを作る。  
    **なぜ現実的か**：全て無料枠で動く。秘密鍵はブラウザ保有。証跡（commit/OT）でチェーンを担保できる。

---

## **B) Static site（Netlify / Cloudflare Pages） \+ Serverless Functions（低コストで支払いゲート等を追加）**

**利点**：静的サイトの利便性＋サーバレスで簡易API（課金連携や鍵配布）を付けられる。無料枠が充実。  
 **欠点**：APIキー管理は注意が必要（Secrets）。  
 **MVP**：

* Netlify Functions / Cloudflare Workers に支払い開始・鍵発行エンドポイントを置く（Stripeのtestモードで試す）。

* フロントは静的HTML \+ JS（署名・manifest生成）で同じ流れ。

---

## **C) Git-based portfolio \+ IPFS（コンテンツの分散保存）**

**利点**：プラットフォーム削除リスク低下。IPFS+Pinata/Free gateway で実験可能。  
 **欠点**：永続性の確保に課金が必要（pinning）。UXが若干ハードル高い。  
 **MVP**：

* コンテンツをIPFSに手動ピン → IPFSハッシュをmanifestに入れる → manifestをGitHub Pagesで公開 → アンカー。

* 後で Pinning を自動化するスクリプトを追加。

---

## **D) Simple PWA（ブラウザで動くポートフォリオアプリ）**

**利点**：スマホ体験が良い。オフライン署名、カメラ撮影→即署名ができる。PWAならストア不要で配布も楽。  
 **欠点**：多少のフロント開発が必要。サーバ側機能（鍵交換、支払い）を外部で処理する必要あり。  
 **MVP**：

* 既存静的サイトをPWA化（manifest.json, service worker）→ WebCryptoで鍵管理／署名。

* ダウンロードは暗号化＋短期鍵発行は serverless で。

---

## **E) 専用軽量プラットフォーム（SaaS風） — 将来的選択肢**

**利点**：UXを完全にコントロールできる。課金・監視・法務機能を一体化できる。  
 **欠点**：初期開発コスト・運用コストが高い。今の君の条件には不向き。  
 **MVPアプローチ**：まずAまたはBで事例作ってから投資検討。

---

# **どう既存設計（manifest・カナリア・支払い）にマップするか**

* どのホストでも\*\*manifest生成（ブラウザ）→署名→公開（manifest.json）→アンカー（Git commit/OpenTimestamps/IPFS）\*\*の流れは同じ。

* カナリアは manifest 内に`canaries`フィールドを入れておき、外部監視は A-Detect が使う検索APIにリクエストするだけ。

* ダウンロード課金は**静的サイト＋serverless**の組合せでシンプルに実装できる（暗号化ファイルをS3/Netlifyでホスト、サーバレスで鍵を発行）。

---

# **今すぐできる具体アクション**

# **選択A（GitHub Pages 推奨）での即行手順（コピペで実行可）**

1. GitHubで新規リポジトリ `your-portfolio` を作る（public ok）。

2. `index.html` と `manifest_signer.js` をコミット（以下の最小JSを使う — 下にサンプルを付ける）。

3. GitHub Pages を有効化（Settings → Pages）。

4. ブラウザでページを開き、ファイルを選択 → 署名 → manifest を Gist または repo に保存（GitHub API を使う場合は個人トークンが必要）。

5. optional: GitHub Actions ワークフローを追加して `manifest.json` のハッシュを commit するたびに OpenTimestamps や simple echo to another repo を行う。

### **小さなサンプルJS（ブラウザで鍵生成・署名・SHA-256）**

（簡易版。実運用ではcanonical化・PEM変換等を追加してください）

\<input type="file" id="file"\>  
\<button id="genKey"\>鍵生成\</button\>  
\<button id="sign"\>署名して manifest を表示\</button\>  
\<pre id="out"\>\</pre\>

\<script\>  
async function hashFile(file){  
  const buf \= await file.arrayBuffer();  
  const hash \= await crypto.subtle.digest('SHA-256', buf);  
  return Array.from(new Uint8Array(hash)).map(b=\>b.toString(16).padStart(2,'0')).join('');  
}

async function genKey(){  
  const key \= await crypto.subtle.generateKey({name:"ECDSA", namedCurve:"P-256"}, true, \["sign","verify"\]);  
  window.\_privKey \= key;  
  const pub \= await crypto.subtle.exportKey("spki", key.publicKey);  
  window.\_pubPem \= '-----BEGIN PUBLIC KEY-----\\n'+btoa(String.fromCharCode(...new Uint8Array(pub)))+'\\n-----END PUBLIC KEY-----';  
  document.getElementById('out').textContent \= '公開鍵作成: \\\\n'+window.\_pubPem;  
}

async function signManifest(manifestStr){  
  const enc \= new TextEncoder().encode(manifestStr);  
  const sig \= await crypto.subtle.sign({name:"ECDSA", hash:{name:"SHA-256"}}, window.\_privKey, enc);  
  return btoa(String.fromCharCode(...new Uint8Array(sig)));  
}

document.getElementById('genKey').onclick \= genKey;  
document.getElementById('sign').onclick \= async ()=\>{  
  const f \= document.getElementById('file').files\[0\];  
  if(\!f) return alert('ファイル選んで');  
  const h \= await hashFile(f);  
  const manifest \= {  
    manifest\_version: "1.0",  
    content\_hash: "sha256:"+h,  
    content\_uri: f.name,  
    created\_at: (new Date()).toISOString(),  
    author: { pubkey\_pem: window.\_pubPem },  
    license: "All Rights Reserved"  
  };  
  const mStr \= JSON.stringify(manifest);  
  const sig \= await signManifest(mStr);  
  manifest.manifest\_signature \= sig;  
  document.getElementById('out').textContent \= JSON.stringify(manifest, null, 2);  
};  
\</script\>

これで「署名付きmanifest」の最小プロトタイプがブラウザで動きます。manifest をテキストとして保存して GitHub/Gist に貼れば公開証跡になります。

---

次の一手：どれで進める？  
 A: GitHub Pages（推奨・今すぐコード）  
 B: Static \+ Serverless（Netlify/Cloudflare）で課金ゲートも試す  
 C: IPFSを最初から組み込む（分散保存重視）  
 D: PWA（スマホ体験重視）

