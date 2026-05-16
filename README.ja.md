# Relayer

[English](README.md) · [日本語](README.ja.md)

[polidog/use-php](https://github.com/polidog/usePHP) の上に構築された、バッ
テリー同梱型のオピニオネイテッドなフレームワークです。以下を一式で提供します:

- Next.js App Router 風のファイルベースルーター (`src/Pages/page.psx`,
  `layout.psx`, 動的セグメント, エラーページ)
- ファイルベースの JSON API ルート (`src/Pages/.../route.php`) —
  HTTP メソッド別のハンドラマップ、戻り値を JSON 化
- ページ／レイアウト単位の外部スクリプト (`$ctx->js()` /
  `PageComponent::addJs()` / `LayoutComponent::addJs()`) — バンドルの後、
  宣言順に `<body>` 末尾へ出力
- React アイランド (`Island::mount()`) — リッチ UI 用の脱出ハッチ。
  サーバ描画シェル＋クライアント React、props は PHP から、バンドルは自前
- 任意のルートミドルウェア (`src/Pages/middleware.php`) が全ディスパッチを
  ラップ。同梱の `Cors` ミドルウェアも提供
- CSRF 保護付きサーバアクション（`$ctx->action()` /
  `PageComponent::action()`、フォーム送信をページ内ハンドラへディスパッチ）
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html)
  によるサービス配線（autowire、YAML/PHP の自動ロード）
- [symfony/dotenv](https://github.com/symfony/dotenv) による `.env` 読み込み
  （`.env` / `.env.local` / `.env.{APP_ENV}` の cascade 対応）
- HTTP キャッシュ用 `#[Cache]` アトリビュート + `If-None-Match` による
  304 応答、差し替え可能な `EtagStore`（デフォルトはファイル、Redis 等にも
  簡単に切り替え可能）
- セッションベースの認証: `#[Auth]` アトリビュート / `$ctx->requireAuth()`、
  ロール検査、パスワードハッシュ、差し替え可能な `UserProvider` /
  `SessionStorage`
- [Zod](https://zod.dev/) 風のスキーマバリデーション（`Validator::object()`
  など、`safeParse` / `parse`、フォーム入力の coercion とフィールド別エラー）
- dev 限定のリクエストプロファイラ（`/_profiler` ビュー、本番では no-op）

`Relayer::boot()` の 1 行だけがエントリポイント。アプリ側のコードを
最小に保てます。

## 必要環境

- PHP >= 8.5
- [polidog/use-php](https://github.com/polidog/usePHP) ^0.1.0
- [symfony/dependency-injection](https://github.com/symfony/dependency-injection) ^7.1
- [symfony/config](https://github.com/symfony/config) ^7.1
- [symfony/yaml](https://github.com/symfony/yaml) ^7.1
- [symfony/dotenv](https://github.com/symfony/dotenv) ^7.1

## インストール

```bash
composer require polidog/relayer
```

## 新規プロジェクトの雛形生成

`relayer init` は**カレントディレクトリ**にプロジェクト構成を展開します。
framework を require した後、プロジェクトルートで実行します:

```bash
composer require polidog/relayer
vendor/bin/relayer init
composer install
php -S 127.0.0.1:8000 -t public
```

`dump-autoload` ではなく `composer install` を使うのは、`App\` の autoload と
`init` が追加した publish スクリプトの両方を効かせるためです（後者が
`public/usephp.js` を生成し、既定ドキュメントがこれを参照します）。

冪等かつ非破壊です:

- 既存ファイルは決して上書きしません（skip として報告）。何度でも安全に再実行できます
- 既存の `composer.json` は**加算的に**パッチします。`App\` の PSR-4 autoload、
  usePHP のアセット publish スクリプト、`extra.relayer.structure_version` の
  マーカーを追加するだけで、他は一切変更しません

`structure_version` マーカーはプロジェクトがどの雛形バージョンで生成されたかを
記録します。これにより後から構造マイグレーションを適用できます。

`init` は **`RELAYER.md`** も生成します — エージェント/LLM 向けの簡潔で
権威ある実装規約（ファイル規約、`route.php` / `middleware.php` / `Island`
の契約、最小主義、「やらない」一覧）。加えて、エージェントツールが自動で
読むファイル名である **`AGENTS.md`**（2 行・`RELAYER.md` を指すだけ）も
生成します。いずれも `polidog/relayer` に同梱され **フレームワークと
co-version され陳腐化しません**。どちらも skip-if-exists なので、利用者
自身の `AGENTS.md` を上書きしません。実ルートは
`vendor/bin/relayer routes` で確認できます。

## プロジェクト構成

```
your-app/
  .env                 # 存在すれば自動で読み込まれる
  composer.json
  config/
    services.yaml      # 存在すれば自動ロード (services.php / .yml も可)
  public/
    index.php
  src/
    Pages/             # AppRouter のファイルベースルートを置く場所
      layout.psx
      page.psx
      about/
        page.psx
    AppConfigurator.php # Polidog\Relayer\AppConfigurator を継承
```

## クイックスタート

`public/index.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\Relayer\Relayer;

Relayer::boot(__DIR__ . '/..')->run();
```

これだけで完結します。`boot()` の中では以下が行われます:

1. プロジェクトルートに `.env` があれば `$_ENV` / `$_SERVER` に読み込む
2. Symfony `ContainerBuilder` を生成し、`config/services.{yaml,yml,php}` が
   あれば自動ロード、その後 `AppConfigurator` で追加登録
3. コンテナをコンパイルし、PSR-11 アダプタに包んで `AppRouter` に渡す
4. `APP_ENV=dev` の場合は `autoCompilePsx` を有効化

戻り値の `AppRouter` はそのまま `run()` するだけで動きますが、必要なら
カスタマイズも可能です:

```php
$router = Relayer::boot(__DIR__ . '/..');
$router->setJsPath('/assets/app.js');
$router->addCssPath('/assets/style.css');
$router->run();
```

## 環境変数

プロジェクトルートに `.env` を置きます:

```
APP_ENV=dev
DATABASE_DSN=mysql:host=127.0.0.1;dbname=app;charset=utf8mb4
DATABASE_USER=app
DATABASE_PASSWORD=secret
```

`DATABASE_*` は任意です。DB 層は `DATABASE_DSN` が設定されているときだけ
配線されます（[データベース](#データベース)を参照）。

[`symfony/dotenv`](https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files)
で読まれ、Symfony 標準の cascade に従います:

1. `.env`                  — コミット用デフォルト
2. `.env.local`            — ローカル上書き（gitignore）
3. `.env.{APP_ENV}`        — 環境別デフォルト（コミット）
4. `.env.{APP_ENV}.local`  — 環境別ローカル上書き（gitignore）

存在しないファイルは黙ってスキップされます。`$_ENV` / `$_SERVER` / `getenv()`
に既に値があれば `.env` を優先せず維持され、`.local` 系ファイルはコミット
されたカウンタパートを上書きします。

`APP_ENV=dev`（または `development`）で PSX ファイルの auto-compile が
有効になります。それ以外（未設定含む）は本番扱いで、デプロイ時に
`vendor/bin/usephp compile src/Pages` で事前コンパイルします。

## ルーティングとページ

ルーターは `src/Pages/` を走査し、ファイル配置を URL にマッピングします（Next.js
App Router の規約に倣っています）。

| ファイル              | 役割                                                                |
| --------------------- | ------------------------------------------------------------------- |
| `page.psx`            | ルートのレンダリング本体。ディレクトリにつき 1 つ。                 |
| `layout.psx`          | 配下のページを包む。ルートから葉まで階層的にスタックされる。        |
| `error.psx`           | 404 / 未マッチルートのフォールバック（ルート直下のみ）。            |
| `route.php`           | JSON API ルート（HTML なし）。メソッド別ハンドラマップ。1 ディレクトリ 1 つ。 |
| `[param]/`            | 動的セグメント。`$this->getParam('param')` で取得できる。           |

`.psx` は JSX 風ソース。実行時はそれをコンパイルした `*.psx.php` を読みます。
dev では自動コンパイル（`APP_ENV=dev`）、本番では
`vendor/bin/usephp compile src/Pages` でデプロイ時に生成します。素の `.php`
ページもそのまま動き、その場合はコンパイル不要です。

### クラス型ページ

```php
<?php
// src/Pages/users/[id]/page.psx
declare(strict_types=1);

namespace App\Pages\Users;

use App\Service\UserRepository;
use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

final class UserDetailPage extends PageComponent
{
    public function __construct(private readonly UserRepository $users) {}

    public function render(): Element
    {
        $user = $this->users->find($this->getParam('id'));
        return <h1>{$user->name}</h1>;
    }
}
```

コンストラクタ引数は DI コンテナから自動解決されます。詳細は
[ページへのサービス注入](#ページへのサービス注入) を参照。

### 関数型ページ

クラスを書かずに closure を `return` するだけでもページを定義できます。
ファクトリ closure はクラス型ページのコンストラクタと同じ autowire が効くので、
型付き引数を宣言すれば DI コンテナから注入されます。

```php
<?php
// src/Pages/about/page.psx
return fn() => <main><h1>About</h1></main>;
```

サービスは型で解決されます。`PageContext` はリクエストごとのハンドル、
それ以外の型付き引数はすべて DI コンテナから注入されます:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, UserRepository $users): Closure {
    $ctx->metadata(['title' => 'Users']);

    return function () use ($users): Element {
        $list = $users->all();
        return <ul>{...\array_map(fn($u) => <li>{$u->name}</li>, $list)}</ul>;
    };
};
```

ファクトリ closure はリクエストごとに 1 回呼ばれます。内側の render closure は
レスポンスが `304` でないときだけ走るので、重い処理は内側に寄せてください
（[関数型ページ: `$ctx->cache()`](#関数型ページ-ctxcache) 参照）。

### レイアウト

`layout.psx` は配下のすべてのページを包みます。階層的にスタックされます:

```
src/Pages/
  layout.psx          # 外殻
  dashboard/
    layout.psx        # ダッシュボード枠
    page.psx          # /dashboard
    users/
      page.psx        # /dashboard/users — 2 つのレイアウトを通る
```

### エラーページ

ルート直下に `error.psx`（`ErrorPageComponent` を継承）を置くと、ルートレイ
アウト内に 404 がレンダリングされます。無ければ最小限のデフォルト表示。

### API ルート

`route.php` は、ページをレンダリングする代わりに JSON を返すエンドポイント
です。HTTP メソッドをキーにしたマップを返し、各ハンドラは関数型ページの
ファクトリと全く同じ方式でオートワイヤされます（`PageContext` / `Request` /
`Identity` / コンテナのサービスを型で注入）。戻り値がそのままレスポンスに
なり、レイアウトや HTML パイプラインは一切通りません。

```php
<?php
// src/Pages/api/users/route.php
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Http\Request;

return [
    'GET'  => fn (UserRepository $users): array => ['users' => $users->all()],
    'POST' => function (Request $req, UserRepository $users): array {
        $users->create($req->allPost());
        return ['ok' => true];
    },
];
```

- ページと同じ `src/Pages/` 配下に置き、動的セグメント `[param]` も同じ規約
  （`$ctx->params['id']` で取得）。1 ディレクトリはページ **か** ルートの
  どちらか一方（両方あるとスキャナがエラーにします）。
- 戻り値は `Content-Type: application/json` で JSON 化されます。`null` は
  `204 No Content`。エラー時は先にステータスを設定して本文を返せば
  （`\http_response_code(404); return ['error' => '…'];`）、そのステータスが
  そのまま使われます。
- ハンドラの無いメソッドへのリクエストは `405 Method Not Allowed` と
  `Allow` ヘッダになります。`HEAD` / `OPTIONS` は自動生成しません — 必要な
  ルートには明示的に定義してください。
- `route.php` はマップを `return` するだけにしてください（クラス／関数定義
  を置かない）。リクエストごとに再評価されます。
- 認証はページと同じ `$ctx->requireAuth()` / `Identity` の仕組みですが、
  失敗時はページの HTML ログイン `302` ではなく JSON の `401`（未認証）
  または `403`（ロール不足）になります。ハンドラ自身が
  `$ctx->redirect()` を呼んだ場合は通常どおり `Location` を返します
  （認証ゲートではなくハンドラの意図的な動作のため）。

### ページ単位のスクリプト（`$ctx->js()` / `addJs()`）

ページ（やその上のレイアウト）は、すべてを 1 本のグローバルバンドルに
乗せる代わりに、自前の外部スクリプトを宣言できます。関数スタイル:

```php
return function (PageContext $ctx): Closure {
    $ctx->js('/assets/chart.js', defer: true);

    return fn (): Element => <canvas id="chart"></canvas>;
};
```

クラススタイルのページ・レイアウトは `$this->addJs(...)` で同じことが
できます:

```php
final class Dashboard extends LayoutComponent
{
    public function render(): Element
    {
        $this->addJs('/assets/dashboard.js', module: true);
        return <div>{...$this->getChildren()}</div>;
    }
}
```

- 出力位置は **`<body>` 末尾、メインの usePHP バンドルの後**、宣言順。
  レイアウトのスクリプトがページより先、外側（ルート）レイアウトが
  内側より先。
- **src 指定のみ。** フラグは `defer` / `async` / `module`
  (`type="module"`)。インライン JS は `$document->addHeadHtml()` を使う
  ── アイランドのローダ（後述）が乗っているのと同じフック。
- **重複排除なし** ── レイアウトとページが同じ src を宣言すれば 2 つの
  タグになる。調整せず宣言するだけ（`metadata()` と同じ方針）。

### React アイランド（リッチ UI 用の脱出ハッチ）

サーバ描画の defer/partial モデルでは表現しきれない、本当にリッチな
クライアント UI が必要な箇所だけ、実 React コンポーネントを *アイランド*
としてマウントします。ページの主導権はサーバが握ったまま、1 ノードだけを
PHP からの初期 props 付きで React に渡します。

```php
<?php
// src/Pages/dashboard/page.psx
declare(strict_types=1);

use Polidog\Relayer\React\Island;
use Polidog\Relayer\Router\Component\PageContext;

return fn (PageContext $ctx) => (
    <section>
        <h1>Dashboard</h1>
        {Island::mount('Chart', ['points' => $ctx->params])}
    </section>
);
```

`Island::mount()` は
`<div data-react-island="Chart" data-react-props='…'></div>` を描画します。
フレームワークの小さな React 非依存ローダをドキュメントに 1 度追加し、
続けて自前バンドルを読み込みます。

```php
$document->addHeadHtml(Island::loaderScript());
$document->addHeadHtml('<script type="module" src="/islands.js"></script>');
```

`islands.js` はあなたの所有物 — 自前のツールチェーン (vite / esbuild) で
React を同梱してビルドします。契約は 1 回の呼び出しだけ:

```js
import { createRoot } from 'react-dom/client';
import Chart from './islands/Chart';

window.relayerIslands.register('Chart', (el, props) => {
    createRoot(el).render(<Chart {...props} />);
});
```

- フレームワークが提供するのは PHP プリミティブとローダ **だけ** で、
  Node 非依存のままです。React・JSX・バンドルはあなたの担当。ローダは
  アイランド（usePHP の defer/partial で後から差し込まれたものも
  `MutationObserver` で）を発見し、props を解析し、登録済みのマウント関数を
  呼びます。register と DOM の順序はどちらが先でも構いません。
- props は **一方向**（PHP → 初期 props）。以降サーバから必要なものは
  JSON API ルート（`route.php`）を `fetch` してください — 別途
  アイランド↔サーバのチャネルはありません。
- 名前は素の識別子のみ。JSON 化できない props は明確なエラーになります。
- 意図的な残課題は 1 つ: **SSR はなし**（クライアント描画のみ。マウント
  ノードはハイドレートまで空なので、ローディング表示はコンポーネント内で
  描画）。`loaderScript()` は インライン `<script>` で、厳格な
  `script-src` CSP 下では `loaderScript($nonce)` を渡せば
  `<script nonce="…">` で出力されます（`window.relayerIslands.register`
  の契約は不変）。

### ミドルウェア

任意のルート直下 `src/Pages/middleware.php` が、すべての page/route
ディスパッチをラップします。`fn(Request $request, Closure $next)`
クロージャを 1 つ `return` し、`$next($request)` を呼べばマッチした
ルートへ進み、**呼ばなければ**短絡します（CORS プリフライト・レート制限・
メンテナンスモード等）:

```php
<?php
// src/Pages/middleware.php
declare(strict_types=1);

use Polidog\Relayer\Http\Request;

return function (Request $request, Closure $next): void {
    if (null === $request->header('x-api-key')) {
        \http_response_code(401);
        echo '{"error":"missing api key"}';
        return; // ルートは実行されない
    }
    $next($request);
};
```

- クロージャ 1 本。チェーンランナーは持ちません（設計）。複数走らせたい
  ときは手で合成: `fn ($r, $next) => $a($r, fn ($r) => $b($r, $next))`。
- リクエストごとに `require`（`route.php` 同様、宣言を置かない契約）。
  クロージャ以外を返すと明確なエラー。フレームワークの defer/profiler
  エンドポイントは意図的にこの外で動きます。

**CORS** は同梱ミドルウェアとして提供 — 提供実装は 1 個で、別系統では
ありません:

```php
<?php
// src/Pages/middleware.php
use Polidog\Relayer\Http\Cors;

return Cors::middleware([
    'origins' => ['https://app.example.com'], // または ['*']
    // methods / headers / credentials / maxAge は任意
]);
```

`OPTIONS` プリフライトには自身が `204` で応答し、実リクエストには
`Access-Control-Allow-Origin` を付与します。`credentials: true` と
`origins: ['*']` の併用時はリクエスト Origin を反映します（仕様上 `*` と
credentials は併用不可のため）。

### ルート確認

`vendor/bin/relayer routes` は、ルーターと同じスキャナで `src/Pages`
配下に検出される全ルート（page と `route.php` をメソッド付きで）を表示
します:

```
METHODS    PATH            TYPE  FILE
GET,POST   /               page  src/Pages/page.psx
GET,POST   /api/users      api   src/Pages/api/users/route.php
GET,POST   /users/[id]     page  src/Pages/users/[id]/page.psx
```

page は `GET,POST` を表示します（POST はサーバアクション / `useState`
がページに到達する経路）。API ルートは宣言済みメソッドを列挙します。
読み込みに失敗した `route.php` は隠さず `?` ＋警告行で表示します。

## サーバアクション (フォーム / CSRF 保護付き)

フォーム送信を、ページに紐づくサーバ側ハンドラへディスパッチする仕組みです
（Next.js の Server Actions に相当）。トークンは CSRF 保護され、ハンドラは
`render()` の **前** に実行されます。クラス型・関数型の両方で使えます。

### クラス型: `PageComponent::action()`

`PageComponent::action([$this, 'handler'])` が CSRF トークン付きのアクション
識別子を返します。フォーム送信時に対応するメソッドが `render()` の前に呼ばれます:

```php
public function render(): Element
{
    return (
        <form method="post">
            <input type="hidden" name="_usephp_action" value={$this->action([$this, 'save'])} />
            <input name="title" />
        </form>
    );
}

public function save(array $form): void
{
    // ... $form['title'] を処理
    header('Location: /dashboard', true, 303); // PRG
    exit;
}
```

CSRF トークンが無効な場合は `403` が返ります。

### 関数型: `PageContext::action()`

関数スタイルのページでは `PageContext::action()` でサーバアクションを宣言します。
ファクトリクロージャはリクエスト毎（フォーム送信時の POST も含む）に再実行され
るため、ディスパッチ前にアクションテーブルが再構築され、トークンは `(pageId,
name)` のみを保持すれば十分です:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, UserRepository $users): Closure {
    $save = $ctx->action('save', function (array $form) use ($users): void {
        $users->create($form['name']);
        \header('Location: /users', true, 303);
        exit;
    });

    return function () use ($save, $users): Element {
        return (
            <main>
                <ul>{...\array_map(fn($u) => <li>{$u->name}</li>, $users->all())}</ul>
                <form action={$save}>
                    <input name="name" />
                    <button>save</button>
                </form>
            </main>
        );
    };
};
```

ハンドラの第1引数には POST ボディが渡されます (`_usephp_action` /
`_usephp_csrf` は除外済み)。アクション名はページごとに一意で、同じ名前を
2 回登録すると例外になります。

### 引数のバインド

第3引数 `$args` でハンドラに値をバインドできます。フォームボディの **後ろ**
に渡されます:

```php
// リスト → 位置引数:        handler($form, 42)
$delete = $ctx->action('delete', function (array $form, int $id) use ($repo): void {
    $repo->delete($id);
}, [$user->id]);

// 連想配列 → 名前付き引数:  handler(formData: $form, id: 42)
$ctx->action('delete', fn (array $formData, int $id) => $repo->delete($id), ['id' => $user->id]);
```

`$args` はアクショントークンに base64 で **そのまま埋め込まれます**（署名は
されません — 改ざん検知は CSRF トークンが担います）。バインドする値は
識別子程度に留め、認可・整合性はハンドラ内で必ず再検証してください
（例: 渡ってきた `$id` の所有権をサーバ側で確認する）。

### 送信失敗時の再レンダリング

関数型ページのファクトリクロージャはリクエスト毎に再実行され、アクション
ハンドラはレンダラ生成の **後** に走ります。検証エラーなどで同じページを
出し直したいときは、状態を参照渡し (`&$errors`) でキャプチャし、ディス
パッチ後の値をレンダラ側で読みます（[バリデーション](#バリデーション)の
`safeParse` と組み合わせる典型パターン。完全な例は
`example/src/Pages/signup/page.psx`）:

```php
return function (PageContext $ctx) use ($schema): Closure {
    $errors = [];
    $save = $ctx->action('save', function (array $form) use ($schema, &$errors): void {
        $result = $schema->safeParse($form);
        if (!$result->success) { $errors = $result->errors; return; }
        // ... 成功時は PRG リダイレクト (header('Location: ...', true, 303); exit;)
    });

    // $errors はアクション実行後に書き換わる → 参照で取り込んで描画する
    return function () use ($save, &$errors): Element { /* $errors を表示 */ };
};
```

## サービス登録

サービス登録の方法は 2 通り。両方を併用できます。YAML/PHP ファイルが先に
ロードされ、その後 `AppConfigurator` が走るので、後者で上書きできます。

### Option A — `config/services.yaml` (自動ロード)

`composer.json` と同じ階層に `config/services.yaml` を置けば自動でロード
されます。Symfony 流のイディオマティックな書き方:

```yaml
# config/services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  App\Service\PdoUserRepository: ~

  App\Service\UserRepository:
    alias: App\Service\PdoUserRepository
```

`config/services.php`（`ContainerConfigurator` クロージャを return する形）
や `config/services.yml` も認識されます。

### Option B — `AppConfigurator` (PHP)

`AppConfigurator` を継承し、`ContainerBuilder` 上でサービス登録します。
フレームワーク側で autowire + public がデフォルトで適用されるので、最小
構成なら `register()` 一発で済みます:

```php
<?php
// src/PagesConfigurator.php
declare(strict_types=1);

namespace App;

use App\Service\UserRepository;
use App\Service\PdoUserRepository;
use Polidog\Relayer\AppConfigurator as BaseConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AppConfigurator extends BaseConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(PdoUserRepository::class);
        $container->setAlias(UserRepository::class, PdoUserRepository::class)
            ->setPublic(true);
    }
}
```

`boot()` に渡します:

```php
Relayer::boot(__DIR__ . '/..', new App\AppConfigurator(__DIR__ . '/..'))->run();
```

### autowire のデフォルト挙動

フレームワークは登録済みの `Definition` を一通り見て:

- 引数を明示していなければ `autowired = true` を立てる
- PSR-11 `get($id)` から取れるよう `public = true` を立てる

を行います。private にしたい等の意図がある場合は `Definition` 側で明示
すれば、利用者の設定が勝ちます。

## ページへのサービス注入

クラス型ページは自動的にコンストラクタ注入されます。コンテナへの登録は
**不要** です（未登録クラスはリフレクションベースの autowire でフォール
バック処理され、各引数は Symfony コンテナから解決されます）:

```php
<?php
// src/Pages/users/page.psx
declare(strict_types=1);

namespace App\Pages\Users;

use App\Service\UserRepository;
use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

final class UsersPage extends PageComponent
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function render(): Element
    {
        $users = $this->users->all();
        // ...
    }
}
```

サービスタグやデコレータ、ファクトリ経由の生成など、独自の挙動が必要な
場合のみ `AppConfigurator` で登録してください。

## HTTP リクエストへのアクセス

`Polidog\Relayer\Http\Request` をページ (関数型ファクトリでもクラス
コンストラクタでも) の引数に宣言すると、現在のリクエストの imutable な
スナップショットが注入されます。`$_GET` / `$_POST` / `$_SERVER` を
ページから直接触る必要はありません。

```php
<?php
// src/Pages/signup/page.psx
declare(strict_types=1);

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, Request $req): Closure {
    $errors = [];

    if ($req->isPost()) {
        $email = $req->post('email') ?? '';
        if (!\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'メールアドレスの形式が正しくありません';
        }
        if ([] === $errors) {
            \header('Location: /thanks', true, 303);
            exit;
        }
    }

    return function () use ($errors, $req): Element {
        // ... フォームを描画、$req->post('email') を <input> に流し込み
    };
};
```

`Request` API (すべて immutable):

| メソッド                     | 戻り値                                            |
| ---------------------------- | ------------------------------------------------- |
| `$req->method`               | 大文字化済み HTTP メソッド                        |
| `$req->path`                 | クエリ文字列を除いたパス                          |
| `$req->isGet()` / `isPost()` | `bool`                                            |
| `$req->isMethod('PUT')`      | `bool`                                            |
| `$req->post($key)`           | `?string` (存在しない / 文字列でないと null)      |
| `$req->query($key)`          | `?string`                                         |
| `$req->header($name)`        | `?string` (大文字小文字を無視)                    |
| `$req->allPost()`            | `array<string, mixed>` (生のボディ全体)           |
| `$req->allQuery()`           | `array<string, mixed>`                            |
| `$req->allHeaders()`         | `array<string, string>` (キーは小文字化)          |

テストでは `new Request(method: 'POST', path: '/signup', post: [...])` を
直接構築すれば良く、スーパーグローバルを書き換える必要はありません。

## 認証

セッションベースの認証が標準で組み込まれています。アプリ側はユーザー
検索（`UserProvider`）だけを実装し、パスワードハッシュ・セッション保存
された principal・リクエスト時のガード処理はフレームワークが配線します。

### 1. `UserProvider` の実装

ユーザーが入力した識別子（典型的にはメールアドレス）から `Credentials`
を返します。`Credentials` はセッションに保存される `Identity` と、
検証に使うパスワードハッシュのペアです。識別子が見つからなければ
`null` を返してください。

```php
<?php
declare(strict_types=1);

namespace App\Auth;

use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;

final class PdoUserProvider implements UserProvider
{
    public function __construct(private readonly \PDO $pdo) {}

    public function findByIdentifier(string $identifier): ?Credentials
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, password_hash, roles FROM users WHERE email = ?'
        );
        $stmt->execute([\strtolower(\trim($identifier))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            return null;
        }

        return new Credentials(
            identity: new Identity(
                id: (int) $row['id'],
                displayName: (string) $row['name'],
                roles: \json_decode((string) $row['roles'], true) ?: [],
            ),
            passwordHash: (string) $row['password_hash'],
        );
    }
}
```

### 2. プロバイダのバインド

`Authenticator`、`PasswordHasher`（デフォルトは `NativePasswordHasher` /
`PASSWORD_DEFAULT`）、`SessionStorage`（`NativeSession`）はフレームワーク
が自動登録します。アプリ側で必要なのは `UserProvider` のバインドだけです:

```yaml
# config/services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  App\Auth\PdoUserProvider: ~

  Polidog\Relayer\Auth\UserProvider:
    alias: App\Auth\PdoUserProvider
```

`UserProvider` をバインドしないアプリではコストはかかりません —
`Authenticator` はインターフェースがバインドされたときだけ登録される
ので、認証を使わないプロジェクトはこれまで通りに動きます。

### 3. ログイン

ログインページに `Authenticator` を注入し、`attempt()` を呼びます。
成功時はセッション ID が再生成され（session fixation 対策）、`Identity`
のスナップショットがセッションに保存されます。

```php
<?php
// src/Pages/login/page.psx
declare(strict_types=1);

use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx, Authenticator $auth): Closure {
    $error = null;

    $login = $ctx->action('login', function (array $form) use ($auth, &$error): void {
        $identity = $auth->attempt(
            (string) ($form['email']    ?? ''),
            (string) ($form['password'] ?? ''),
        );

        if (null === $identity) {
            $error = 'メールアドレスかパスワードが違います。';

            return;
        }

        \header('Location: /dashboard', true, 303);
        exit;
    });

    return function () use ($login, $error): Element {
        // ... フォームを描画、$error は単一の汎用メッセージで表示
    };
};
```

`Authenticator` API:

| メソッド                          | 戻り値        | 備考                                                     |
| --------------------------------- | ------------- | -------------------------------------------------------- |
| `attempt($id, $password)`         | `?Identity`   | `UserProvider` + hasher で検証。成功時はログイン処理も。 |
| `login(Identity $identity)`       | `void`        | 解決済みの principal を即セッションに（SSO・signup）。   |
| `logout()`                        | `void`        | principal を破棄し、セッション ID を再生成。             |
| `user()`                          | `?Identity`   | 現在ログイン中の principal、または `null`。              |
| `check()`                         | `bool`        | `user() !== null` のショートカット。                     |
| `hasRole($role)` / `hasAnyRole`   | `bool`        | ロール検査。                                             |

`attempt()` は識別子が見つからなくてもダミーハッシュを使って
`password_verify` を実行するので、応答時間からアカウントの存在を
推測することはできません。失敗は常に `null` を返すので、呼び出し側は
どのフィールドが弾かれたかを開示せず、単一の汎用エラーを表示してください。

### 4. ページの保護

#### クラス型: `#[Auth]`

`PageComponent` の派生クラスに `Polidog\Relayer\Auth\Auth` アトリビュート
を付けます。ガードは `InjectorContainer` でページがインスタンス化される
**前** に走るので、未認証リクエストはページ本体もその依存サービスも
構築しません。

```php
<?php
namespace App\Pages;

use Polidog\Relayer\Auth\Auth;
use Polidog\Relayer\Router\Component\PageComponent;

#[Auth] // 認証済みなら誰でも
final class DashboardPage extends PageComponent { /* ... */ }

#[Auth(roles: ['admin'])] // ロールゲート。一般ユーザーは 403
final class AdminPage extends PageComponent { /* ... */ }

#[Auth(redirectTo: '')] // 空文字 → リダイレクトせず 401（JSON / API 向け）
final class ApiEndpoint extends PageComponent { /* ... */ }
```

| パラメータ      | 既定値      | 効果                                                   |
| --------------- | ----------- | ------------------------------------------------------ |
| `roles`         | `[]`        | いずれか 1 つのロールが必要（空 = 認証済みなら誰でも） |
| `redirectTo`    | `'/login'`  | 未認証リクエストの転送先。空文字 → `401` を返す        |

未認証リクエストは `302 Location: /login?next=<元のパス>` でリダイレクト
されます（URL エンコード済み・同一オリジンのみ）。認証済みでもロールが
足りない場合は `403 Forbidden`。

`#[Auth]` は `#[Cache]` より先に評価されるので、未認証リクエストが
キャッシュ可能な `304` を作って共有キャッシュ経由で漏れることはありません。
`#[Auth]` と `#[Cache]` の併用は問題ありませんが、ユーザー単位で
ガートしているページは `Cache-Control: private` を選ぶのが無難です。

#### 関数型: `$ctx->requireAuth()` / `Identity` 注入

関数型ファクトリでは `PageContext` の宣言的ガードを使います:

```php
<?php
// src/Pages/dashboard/page.psx
declare(strict_types=1);

use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx): Closure {
    $user = $ctx->requireAuth(); // 失敗時 AuthorizationException

    return fn(): Element => <h1>こんにちは、{$user->displayName}</h1>;
};
```

`requireAuth($roles = [], $redirectTo = '/login')` は `Identity` を返す
ので、そのまま埋め込めます。例外は AppRouter が捕捉し、`#[Auth]` と
同じ `302` / `401` / `403` レスポンスを返します。

ログイン状態によって描画を切り替えるページなら、ファクトリに `?Identity`
を宣言してください。フレームワークが現在の principal を注入します
（未ログインなら `null`）:

```php
return function (PageContext $ctx, ?Identity $user): Closure {
    $ctx->metadata(['title' => $user?->displayName ?? 'ようこそ']);

    return fn(): Element => null !== $user
        ? <p>こんにちは、{$user->displayName}</p>
        : <a href="/login">ログイン</a>;
};
```

null 許容でない `Identity` を宣言すると「認証必須」とみなされ、未認証
時には `requireAuth()` と同じくリダイレクトされます（クラス型での
`#[Auth]` 相当）。

### 5. 差し替え可能なパーツ

既定値は実用的ですが、すべて差し替え可能です。`services.yaml`（または
`AppConfigurator`）で別の実装をバインドすれば上書きできます:

| インターフェース                            | 既定値                    | 差し替えどき…                                |
| ------------------------------------------- | ------------------------- | -------------------------------------------- |
| `Polidog\Relayer\Auth\UserProvider`         | *(未バインド・アプリ供給)*| 常に — これがアプリの user lookup            |
| `Polidog\Relayer\Auth\PasswordHasher`       | `NativePasswordHasher`    | 特定アルゴリズム / pepper を使いたいとき     |
| `Polidog\Relayer\Auth\SessionStorage`       | `NativeSession`           | Redis / DB 等で session を持ちたいとき       |

`NativePasswordHasher` は `PASSWORD_DEFAULT` を使うので、その時点の
PHP が最強と判断するアルゴリズムを自動採用します（現状は bcrypt）。
libargon2 が使える環境で argon2id を強制したい場合は:

```php
$container->register(NativePasswordHasher::class)
    ->setArguments([\PASSWORD_ARGON2ID]);
```

`NativeSession` は最初の read/write で `session_start()` を遅延起動する
ので、DI で解決しただけでは `Set-Cookie` は送りません。既存の CSRF
トークン機構と `$_SESSION` を共有するため、`session_start` の重複も
起きません。

### 注意点

- 認証ガードは `InjectorContainer`（クラス型ページ）と factory 引数
  リゾルバ（`Identity` 注入・`requireAuth`）で走ります。レイアウトは
  別経路で解決されるため自動ガードの対象外。レイアウト側で認証状態を
  読みたい場合は、コンストラクタに `?Authenticator` を注入して
  `render()` から `$auth?->user()` を呼んでください。
- ログインリダイレクトの `?next=<path>` は同一オリジン専用です。
  `//` 始まりや絶対 URL は破棄してログインページからの open redirect
  を防ぎます。
- セッションはログインとログアウト **両方** で再生成します。攻撃者が
  ログイン前のセッション ID を奪取しても、ユーザーが認証した時点で
  そのセッション ID は無効になります。

## `#[Cache]` による HTTP キャッシュ制御

`Polidog\Relayer\Http\Cache` をページクラスに付与すると、
`Cache-Control` / `Vary` / `ETag` ヘッダーが自動送出されます。
AppRouter がページをコンテナから解決するタイミングでアトリビュートが
評価され、レスポンスボディの前にヘッダーが出ます。

```php
<?php
// src/Pages/page.psx
declare(strict_types=1);

namespace App\Pages;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Http\Cache;
use Polidog\UsePhp\Runtime\Element;

#[Cache(
    maxAge: 3600,
    sMaxAge: 86400,
    public: true,
    vary: ['Accept-Language'],
    etag: 'home-v1',
)]
final class HomePage extends PageComponent
{
    public function render(): Element { /* ... */ }
}
```

サポートしているパラメータ:

| Parameter         | 効果                                                |
| ----------------- | --------------------------------------------------- |
| `maxAge`          | `Cache-Control: max-age=<n>`                        |
| `sMaxAge`         | `Cache-Control: s-maxage=<n>` (CDN 用)              |
| `public`          | `Cache-Control: public`                             |
| `private`         | `Cache-Control: private`                            |
| `noStore`         | `Cache-Control: no-store`                           |
| `noCache`         | `Cache-Control: no-cache`                           |
| `mustRevalidate`  | `Cache-Control: must-revalidate`                    |
| `immutable`       | `Cache-Control: immutable`                          |
| `vary`            | `Vary: <カンマ区切り>`                              |
| `etag`            | `ETag: "<value>"` (生値は自動でクオート)            |
| `etagWeak`        | ETag を弱バリデータ `W/"…"` で送出                  |
| `lastModified`    | `Last-Modified: <RFC 7231 GMT>` (`strtotime()` 可)  |
| `etagKey`         | `EtagStore` から動的に引く論理キー。`etag` 優先。   |

### 条件付き GET / `304 Not Modified`

`etag` か `lastModified` が指定されていれば、安全メソッド（`GET`, `HEAD`）
に対してリクエストの `If-None-Match` / `If-Modified-Since` を評価し、
クライアントが最新版を持っていれば短絡します:

1. 検証用ヘッダー（`ETag`, `Last-Modified`, `Cache-Control`, `Vary`）が送出される
2. ステータスが `304 Not Modified` に設定される
3. ボディは出力されずリクエストが終わる

ETag 比較は RFC 7232 §2.3.2 の弱比較に準拠。`W/"v1"` と `"v1"` は一致と
みなされ、`*` はあらゆるタグにマッチします。

### サンプル

```php
#[Cache(
    maxAge: 3600,
    public: true,
    vary: ['Accept-Language'],
    etag: 'home-v1',
    etagWeak: true,
    lastModified: '2025-01-15 10:00:00 UTC',
)]
final class HomePage extends PageComponent { /* ... */ }
```

### 関数型ページ: `$ctx->cache()`

PHP アトリビュートはクラスにしか付けられないので、関数型 `page.psx` は
`PageContext` 経由でキャッシュ方針を宣言します:

```php
<?php
// src/Pages/feed/page.psx
declare(strict_types=1);

use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx): Closure {
    // 軽い初期化のみ: キャッシュ宣言・パラメータ参照。ここで DB を叩かない。
    $ctx->cache(new Cache(maxAge: 60, public: true, etagKey: 'feed'));

    return function () use ($ctx): Element {
        // 重い処理はこちら — 304 で短絡されたときは実行されません。
        // ... DB クエリやページ構築
    };
};
```

外側の factory は毎リクエスト走る（軽量想定）、内側の render closure は
304 で短絡されなかったときだけ走ります。重い処理を内側に置けば、クラス
型と同じ「DB を叩かない 304 ヒット」が成立します。

`#[Cache]` で使えるパラメータはすべて `Cache` コンストラクタでも指定できます。

### `EtagStore` による動的 ETag

`etag: 'home-v1'` のような静的値はデプロイで内容が変わるコンテンツ向け。
データ駆動なページは `etagKey:` を宣言して、`EtagStore` から実行時に
値を引きます:

```php
#[Cache(maxAge: 60, public: true, etagKey: 'user-list')]
final class UsersPage extends PageComponent { /* ... */ }
```

フレームワークは、ページをインスタンス化する **前** に `EtagStore` を
参照します。クライアントの `If-None-Match` がストアの値と一致すれば
そのまま `304` を返すので、ページのコンストラクタもリポジトリも DB も
一切走りません。

データ更新側（リポジトリやコマンドハンドラ）は更新時にストアを書き換えます:

```php
final class UserRepository
{
    public function __construct(private readonly EtagStore $etags) {}

    public function save(User $user): void
    {
        // ... 永続化
        $this->etags->set('user-list', \sha1((string) \microtime(true)));
    }
}
```

#### デフォルト実装: `FileEtagStore`

ゼロコンフィグで使えるよう、フレームワークは `FileEtagStore` を
`$projectRoot/var/cache/etags/` 配下に自動登録します（1 キー 1 ファイル、
ファイル名は `sha1(key)`、書き込みは tmp → rename でアトミック）。

#### 別バックエンド (Redis 等)

`Polidog\Relayer\Http\EtagStore` を実装したクラスを `EtagStore`
の alias として登録すれば差し替え可能。phpredis を使う例:

```php
final class RedisEtagStore implements EtagStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'etag:',
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->prefix . $key);
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $etag): void
    {
        $this->redis->set($this->prefix . $key, $etag);
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
```

`services.yaml` で配線:

```yaml
services:
  _defaults:
    autowire: true
    public: true

  Redis:
    factory: ['App\Factory\RedisFactory', 'connect']

  App\Infrastructure\RedisEtagStore: ~

  Polidog\Relayer\Http\EtagStore:
    alias: App\Infrastructure\RedisEtagStore
```

`AppConfigurator::configure()` で書く場合:

```php
$container->register(RedisEtagStore::class);
$container->setAlias(EtagStore::class, RedisEtagStore::class)->setPublic(true);
```

### 注意事項

- `#[Cache]` は `PageComponent` サブクラスでのみ評価されます。レイアウト
  や一般サービスに付けても無視されます（意図しないヘッダー送出を避ける
  ため）。
- `headers_sent()` が `true` の段階ではヘッダー書き込みはスキップされます。
- 304 短絡時の `exit;` は PSR-11 アダプタから出ます。**ページのコンスト
  ラクタも依存サービスも実行されない** 段階で短絡するので、DB アクセス
  を完全に回避できます。
- リクエスト・ユーザー単位で動的にキャッシュ方針を変えたい場合は、
  アトリビュートではなく `render()` 内で `header()` を直接書いてください。

## データベース

PDO の薄いラッパーです。生 SQL を渡し、素の配列が返ります。クエリビルダ
も SQL ファイルローダもありません。名前付き (`:id`) または位置 (`?`)
プレースホルダ付きの SQL を直接渡してください。手で配線すると面倒な 4 点
——Profiler 可視化・明示的なタイムアウト・単一の例外型・リクエスト内の
読み取りメモ化——をまとめて提供することが目的です。

### 有効化

DB 層は **`DATABASE_DSN` が設定されているときだけ** 登録されます。DB を
使わないアプリは何のコストも負わず、設定も不要です。

```
DATABASE_DSN=mysql:host=127.0.0.1;dbname=app;charset=utf8mb4
DATABASE_USER=app
DATABASE_PASSWORD=secret
DATABASE_TIMEOUT=5            # 接続タイムアウト・秒 (PDO::ATTR_TIMEOUT)
DATABASE_READ_TIMEOUT=10      # MySQL 読み取りタイムアウト・秒（任意）
```

`DATABASE_DSN` は標準の PDO DSN なので SQLite
(`sqlite:/path/app.db`)、PostgreSQL (`pgsql:host=...`) なども使えます。
`DATABASE_READ_TIMEOUT` は `mysql:` DSN のときだけ適用されます。

### 使い方

ページ／コンポーネントのコンストラクタで `Database` を受け取ります:

```php
use Polidog\Relayer\Db\Database;

final class UserPage extends PageComponent
{
    public function __construct(private readonly Database $db) {}

    public function render(): string
    {
        $user = $this->db->fetchOne(
            'SELECT id, name FROM users WHERE id = :id',
            ['id' => 42],
        );
        // ...
    }
}
```

| メソッド                         | 戻り値                              |
| ------------------------------- | ----------------------------------- |
| `fetchAll($sql, $params)`       | `list<array<string,mixed>>`         |
| `fetchOne($sql, $params)`       | `array<string,mixed>` または `null` |
| `fetchValue($sql, $params)`     | 先頭行の先頭カラム、なければ `null` |
| `perform($sql, $params)`        | 影響行数 (`int`)                    |
| `lastInsertId($name = null)`    | 直近の insert id (`string`)         |
| `transactional($callback)`      | コールバックの戻り値                |

```php
$db->transactional(function (Database $tx): void {
    $tx->perform('INSERT INTO orders (user_id) VALUES (?)', [$userId]);
    $tx->perform('UPDATE users SET order_count = order_count + 1 WHERE id = ?', [$userId]);
});
```

コールバックはトランザクション内で実行され、正常終了で commit、例外発生
で rollback して再送出します。トレース・キャッシュを効かせるため、渡って
くる `$tx` 引数を使ってください。

### 自動で得られるもの

- **エラー** — すべてのドライバ例外は単一の
  `Polidog\Relayer\Db\DatabaseException` として送出されます。元の
  `PDOException` は previous として保持されます。
- **タイムアウト** — DB が詰まった場合、ワーカーをハングさせず設定した
  タイムアウト内に `DatabaseException` として表面化します。
- **リクエスト内キャッシュ** — 同一の読み取り（同じ SQL + パラメータの
  `fetchAll` / `fetchOne` / `fetchValue`）はリクエストの間プロセス内
  キャッシュにヒットします。同じ参照を必要とする複数コンポーネントで
  構成されるページでも往復は 1 回で済みます。`perform` /
  `transactional` でキャッシュは全フラッシュ。リクエストスコープ限定で、
  TTL もクロスリクエスト共有もありません。
- **Profiler**（dev）— 実際のクエリはリクエストプロファイルに
  `db.query` / `db.mutate` / `db.transaction` の計時スパンとして SQL・
  バインド値付きで記録され、キャッシュヒットは `db.cache_hit` マーカー
  として残ります。本番では Profiler は no-op なのでオーバーヘッドは
  ありません。

## バリデーション

`Polidog\Relayer\Validation` は [Zod](https://zod.dev/)（TypeScript）に
着想を得たスキーマバリデータです。フォーム入力（常に文字列で届く）を
coercion しつつ検証し、フィールドごとのエラーメッセージを 1 パスでまとめて
返します。追加の依存はありません。

### スキーマの宣言

`Validator` ファサードで組み立てます:

```php
use Polidog\Relayer\Validation\Validator;

$schema = Validator::object([
    'email' => Validator::string()->trim()->email(),
    'name'  => Validator::string()->trim()->min(1, 'Name is required.'),
    'age'   => Validator::int()->min(0)->optional(),
    'role'  => Validator::enum(['admin', 'member'])->default('member'),
]);
```

| ファクトリ                     | スキーマ                                                  |
| ------------------------------ | --------------------------------------------------------- |
| `Validator::string()`          | 文字列。`min/max/length/regex/email/url/trim/lower/upper`  |
| `Validator::int()`             | 整数。数値文字列を coercion。`min/max/positive/nonNegative`|
| `Validator::float()`           | 浮動小数点数。数値文字列を coercion                        |
| `Validator::bool()`            | 真偽値                                                    |
| `Validator::enum([...])`       | 許可値のいずれか。`literal()` は単一値                     |
| `Validator::object([...])`     | 連想配列。未知キーは既定で除去、`passthrough()` で保持     |
| `Validator::array($element)`   | 各要素を `$element` スキーマで検証                         |
| `Validator::email()` / `url()` | `string()->trim()->email()` / `url()` のショートカット     |

すべてのスキーマで使える修飾子（イミュータブル — 元のスキーマは変化せず
clone を返すので部品として再利用できます）:

| 修飾子                       | 意味                                            |
| ---------------------------- | ----------------------------------------------- |
| `optional()`                 | 未入力なら `null`。以降の検証はスキップ          |
| `nullable()`                 | `null` を許容（キー自体は必須）                  |
| `default($value)`            | 未入力時に使う既定値                            |
| `required(?$message)`        | 必須に戻す＋未入力メッセージの上書き             |
| `refine($predicate, $msg)`   | 任意の述語で追加検証                            |
| `transform($fn)`             | 検証通過後に最終変換                            |

`StringSchema` / `IntSchema` / `EnumSchema` では空文字を「未入力」として
扱うため、`optional` / `required` / `default` がフォームで直感的に効きます。

### パースする

```php
$result = $schema->safeParse($_POST);

if ($result->success) {
    $data = $result->data;          // coercion 済みの値
} else {
    $errors = $result->errors;      // ['email' => '...', 'address.zip' => '...']
}
```

- `safeParse($input): ParseResult` — 検証エラーで例外を投げません。
  `success` を見て分岐します。
- `parse($input): mixed` — 失敗時に `ParseError`（`$errors` を保持）を送出。
- ネストした `object` のエラーキーはドットパス（`address.zip`）になります。

### フォームアクションとの組み合わせ

`$ctx->action()` と一緒に使うのが典型例です
（`example/src/Pages/signup/page.psx`）:

```php
$schema = Validator::object([
    'name'     => Validator::string()->trim()->min(1, 'Name is required.'),
    'email'    => Validator::string()->trim()->email(),
    'password' => Validator::string()->min(8, 'Password must be at least 8 characters.'),
]);

$signup = $ctx->action('signup', function (array $form) use ($schema, &$errors): void {
    $result = $schema->safeParse($form);
    if (!$result->success) {
        $errors = $result->errors;   // フィールド別にビューへ
        return;
    }
    // $result->data は coercion 済み
});
```

## プロファイラ

dev 限定のリクエストプロファイラです。各リクエストを `Profile`（URL・
メソッド・ステータス・イベント列）として記録し、`/_profiler` の Web
ビューで確認できます。**本番ではゼロコスト** — ユーザーコードは環境を
気にせず `Profiler` 依存を受け取れます。

### 仕組み

`Profiler::class` は常に DI にバインドされます:

- **prod**（`APP_ENV` が dev/development 以外）→ `NullProfiler`。全メソッド
  no-op で、`if profiler enabled` 分岐なしに呼び出せます。
- **dev**（`APP_ENV=dev`）→ `RecordingProfiler`。イベントは `Profile` に
  溜まり、リクエスト終了時に `FileProfilerStorage` が
  `<projectRoot>/var/cache/profiler` へ JSON 永続化します。

dev では Traceable デコレータ群が AppRouter / Database / EtagStore /
SessionStorage / Authenticator をラップし、`db.query`・`cache.etag_*`・
`session.*` などのスパンを自動でプロファイルへ流します。`<X defer />`
によるサブリクエストは `parentToken` で親リクエストに紐づきます。

### Web ビュー

`TraceableAppRouter` が通常ディスパッチの **前に** `/_profiler` を横取り
します（プロファイラ自身はプロファイルされません）:

| URL                  | 内容                                                |
| -------------------- | --------------------------------------------------- |
| `/_profiler`         | 直近のリクエスト一覧（defer は親行に折り畳み）       |
| `/_profiler/<token>` | 1 リクエストの詳細（イベント時系列・サブリクエスト） |

純粋な HTML のみ（JS も外部 CSS もなし）でオフラインでも動きます。

### コードから計測する

ページやサービスのコンストラクタで `Profiler` を受け取れます:

```php
use Polidog\Relayer\Profiler\Profiler;

public function __construct(private readonly Profiler $profiler) {}

// 一発イベント
$this->profiler->collect('app', 'cache warmed', ['keys' => 12]);

// 計時スパン（stop() で確定）
$span = $this->profiler->start('app', 'heavy compute');
$result = $this->compute();
$span->stop(['rows' => \count($result)]);
```

`NullProfiler` でも同じ呼び出しがそのまま no-op になるため、環境による
分岐は不要です。

## ソース構成

| Namespace                                              | 役割                                                                |
| ------------------------------------------------------ | ------------------------------------------------------------------- |
| `Polidog\Relayer\Relayer`                    | エントリポイント（env 読込 + DI 構築 + router 配線）                |
| `Polidog\Relayer\AppConfigurator`              | サービス登録の拡張点                                                |
| `Polidog\Relayer\InjectorContainer`            | PSR-11 アダプタ（リフレクション autowire + 304 短絡）               |
| `Polidog\Relayer\Router\AppRouter`             | `src/Pages/` を対象としたファイルベースルーター                       |
| `Polidog\Relayer\Router\Component\*`           | `PageComponent` / `ErrorPageComponent` / `FunctionPage` ほか        |
| `Polidog\Relayer\Router\Layout\*`              | `LayoutComponent` + ネストレイアウトのレンダリング                  |
| `Polidog\Relayer\Router\Document\*`            | HTML ドキュメントラッパ・メタデータ                                 |
| `Polidog\Relayer\Router\Form\*`                | CSRF トークン + フォームアクションのディスパッチ                    |
| `Polidog\Relayer\Router\Routing\*`             | ページスキャナ / ルートテーブル / マッチャ                          |
| `Polidog\Relayer\Db\Database`                  | 最小 SQL コントラクト（既定 `PdoDatabase`・キャッシュ・dev トレース）|
| `Polidog\Relayer\Db\DatabaseException`         | DB 層が送出する単一の例外型                                          |
| `Polidog\Relayer\Http\Cache`                   | `#[Cache]` アトリビュート                                           |
| `Polidog\Relayer\Http\CachePolicy`             | ヘッダー送出 + 条件付き GET 評価                                    |
| `Polidog\Relayer\Http\EtagStore`               | 差し替え可能な ETag ストレージインターフェース                      |
| `Polidog\Relayer\Http\FileEtagStore`           | ファイルベースのデフォルト `EtagStore` 実装                         |
| `Polidog\Relayer\Auth\Auth`                    | `#[Auth]` アトリビュート                                            |
| `Polidog\Relayer\Auth\Authenticator`           | セッションベースの認証オーケストレータ                              |
| `Polidog\Relayer\Auth\Identity` / `Credentials`| principal とログインハンドシェイクの値オブジェクト                  |
| `Polidog\Relayer\Auth\UserProvider`            | アプリ供給のユーザー検索インターフェース                            |
| `Polidog\Relayer\Auth\PasswordHasher`          | パスワードハッシュ抽象（既定: `NativePasswordHasher`）              |
| `Polidog\Relayer\Auth\SessionStorage`          | セッションストレージ抽象（既定: `NativeSession`）                   |
| `Polidog\Relayer\Validation\Validator`         | Zod 風スキーマビルダのファサード（`safeParse` / `parse`）          |
| `Polidog\Relayer\Validation\Schema`            | スキーマ基底＋各型（string/int/float/bool/enum/array/object）       |
| `Polidog\Relayer\Profiler\Profiler`            | リクエストトレーシングのファサード（dev: 記録 / prod: no-op）       |
| `Polidog\Relayer\Profiler\ProfilerWebView`     | `/_profiler` の dev ビュー（一覧＋詳細）                            |

サードパーティのランタイム依存は `polidog/use-php`（JSX 風コンポーネント
ランタイム）のみ。DI、dotenv、Symfony の YAML config はすべて
`Relayer::boot()` 内で配線されるので、追加でインストールするものは
ありません。

## テスト実行

```bash
vendor/bin/phpunit
```

## ライセンス

MIT
