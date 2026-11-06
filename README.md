# sitemap

MODX Evolution 用の、検索エンジン向けサイトマップ（XML・テキスト）を出力するスニペットです。

## 入れ方

1. `snippets/sitemap/` を、サイトの `assets/snippets/sitemap/` に置く
2. 管理画面でスニペットを作り、名前を `sitemap` にして、`sitemap.snippet_code.php` の中身を貼る
3. テンプレートを「(blank)」にしたリソースを作り、本文に `[[sitemap]]` と書く。コンテンツタイプは `text/xml` にする

## パラメータ

| 名前 | 既定 | 内容 |
|---|---|---|
| `startid` | `0` | この文書の下を出力する |
| `format` | `sp` | `sp`（XML サイトマップ）・`txt`（URL の一覧）・`ulli`（HTML の箇条書き） |
| `seeThruUnpub` | `1` | 非公開の文書の下の文書も出力するか |
| `priority` | `sitemap_priority` | priority を持つ TV の名前。TV が無ければ更新日などから決める |
| `changefreq` | `sitemap_changefreq` | changefreq を持つ TV の名前。値が `exclude` の文書は出力しない |
| `excludeTemplates` | （なし） | 出力しないテンプレート。名前か id のカンマ区切り |
| `excludeTV` | `sitemap_exclude` | 値があれば出力しない、という TV の名前 |
| `xsl` | （なし） | XSL の URL か文書 id |
| `excludeWeblinks` | `1` | ウェブリンクを出力しないか |

出力するのは、公開されていて、検索の対象で、テンプレートのある文書です。

## 変更履歴

### 1.2（2026-11-06）

- 文書の多いサイトで遅いのを直した。文書・TV の有無・別名を最初に 1 回ずつ読み、URL は出力する文書の分だけ作る。文書約 4,900 件のサイトで、キャッシュの無い表示が約 56 秒から約 6 秒になった
- `excludeTemplates` が効いていなかったのを直した
- `seeThruUnpub` の既定を、説明どおり `1` にした
- 1 つのページで 2 回呼ぶと、クラスや関数の二重宣言で止まっていたのを直した
- 文書の一覧の連結で、文書が抜けたり重なったりすることがあったのを直した
- 子の文書の更新日が親より新しいときだけ、親の更新日に使うようにした
- URL が空の文書は出力しない
- PHP 8 の非推奨の書き方を直した

### 1.1（2017-08-18）

- priority・changefreq の TV が無いときは、更新日・フォルダかどうか・画像の有無から決める（1.0.11 では TV が無いと出力しなかった）
- フォルダの更新日に子の文書の更新日を反映し、トップページの更新日はサイトで最後に更新された日にする
- `excludeWeblinks` の既定を `1` にした

### 1.0.11 以前

DivanDesign・ncrossland ほかによる元の版。変更履歴は `snippets/sitemap/changelog.txt`。
