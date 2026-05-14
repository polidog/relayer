# Relayer

[English](README.md) · [日本語](README.ja.md)

[polidog/use-php](https://github.com/polidog/usePHP) の上に構築された、バッ
テリー同梱型のオピニオネイテッドなフレームワークです。以下を一式で提供します:

- Next.js App Router 風のファイルベースルーター (`src/app/page.psx`,
  `layout.psx`, 動的セグメント, エラーページ)
- [Symfony DependencyInjection](https://symfony.com/doc/current/components/dependency_injection.html)
  によるサービス配線（autowire、YAML/PHP の自動ロード）
- [symfony/dotenv](https://github.com/symfony/dotenv) による `.env` 読み込み
  （`.env` / `.env.local` / `.env.{APP_ENV}` の cascade 対応）
- HTTP キャッシュ用 `#[Cache]` アトリビュート + `If-None-Match` による
  304 応答、差し替え可能な `EtagStore`（デフォルトはファイル、Redis 等にも
  簡単に切り替え可能）

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
    app/               # AppRouter のファイルベースルートを置く場所
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
DATABASE_URL=mysql://localhost/app
```

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
`vendor/bin/usephp compile src/app` で事前コンパイルします。

## ルーティングとページ

ルーターは `src/app/` を走査し、ファイル配置を URL にマッピングします（Next.js
App Router の規約に倣っています）。

| ファイル              | 役割                                                                |
| --------------------- | ------------------------------------------------------------------- |
| `page.psx`            | ルートのレンダリング本体。ディレクトリにつき 1 つ。                 |
| `layout.psx`          | 配下のページを包む。ルートから葉まで階層的にスタックされる。        |
| `error.psx`           | 404 / 未マッチルートのフォールバック（ルート直下のみ）。            |
| `[param]/`            | 動的セグメント。`$this->getParam('param')` で取得できる。           |

`.psx` は JSX 風ソース。実行時はそれをコンパイルした `*.psx.php` を読みます。
dev では自動コンパイル（`APP_ENV=dev`）、本番では
`vendor/bin/usephp compile src/app` でデプロイ時に生成します。素の `.php`
ページもそのまま動き、その場合はコンパイル不要です。

### クラス型ページ

```php
<?php
// src/app/users/[id]/page.psx
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
// src/app/about/page.psx
return fn() => <main><h1>About</h1></main>;
```

サービスは型で解決されます。`PageContext` はリクエストごとのハンドル、
それ以外の型付き引数はすべて DI コンテナから注入されます:

```php
<?php
// src/app/users/page.psx
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
src/app/
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

### フォームアクション (CSRF 保護付き)

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

関数スタイルのページでは `PageContext::action()` でサーバアクションを宣言します。
ファクトリクロージャはリクエスト毎（フォーム送信時の POST も含む）に再実行され
るため、ディスパッチ前にアクションテーブルが再構築され、トークンは `(pageId,
name)` のみを保持すれば十分です:

```php
<?php
// src/app/users/page.psx
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
// src/AppConfigurator.php
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
// src/app/users/page.psx
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
// src/app/signup/page.psx
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

## `#[Cache]` による HTTP キャッシュ制御

`Polidog\Relayer\Http\Cache` をページクラスに付与すると、
`Cache-Control` / `Vary` / `ETag` ヘッダーが自動送出されます。
AppRouter がページをコンテナから解決するタイミングでアトリビュートが
評価され、レスポンスボディの前にヘッダーが出ます。

```php
<?php
// src/app/page.psx
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
// src/app/feed/page.psx
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

## ソース構成

| Namespace                                              | 役割                                                                |
| ------------------------------------------------------ | ------------------------------------------------------------------- |
| `Polidog\Relayer\Relayer`                    | エントリポイント（env 読込 + DI 構築 + router 配線）                |
| `Polidog\Relayer\AppConfigurator`              | サービス登録の拡張点                                                |
| `Polidog\Relayer\InjectorContainer`            | PSR-11 アダプタ（リフレクション autowire + 304 短絡）               |
| `Polidog\Relayer\Router\AppRouter`             | `src/app/` を対象としたファイルベースルーター                       |
| `Polidog\Relayer\Router\Component\*`           | `PageComponent` / `ErrorPageComponent` / `FunctionPage` ほか        |
| `Polidog\Relayer\Router\Layout\*`              | `LayoutComponent` + ネストレイアウトのレンダリング                  |
| `Polidog\Relayer\Router\Document\*`            | HTML ドキュメントラッパ・メタデータ                                 |
| `Polidog\Relayer\Router\Form\*`                | CSRF トークン + フォームアクションのディスパッチ                    |
| `Polidog\Relayer\Router\Routing\*`             | ページスキャナ / ルートテーブル / マッチャ                          |
| `Polidog\Relayer\Http\Cache`                   | `#[Cache]` アトリビュート                                           |
| `Polidog\Relayer\Http\CachePolicy`             | ヘッダー送出 + 条件付き GET 評価                                    |
| `Polidog\Relayer\Http\EtagStore`               | 差し替え可能な ETag ストレージインターフェース                      |
| `Polidog\Relayer\Http\FileEtagStore`           | ファイルベースのデフォルト `EtagStore` 実装                         |

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
