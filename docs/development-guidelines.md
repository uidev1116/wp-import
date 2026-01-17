# 開発ガイドライン

WordPress移行プラグイン開発における規約・手順を定義します。

## コーディング規約

### PHP

#### PSR-12準拠
- PSR-12コーディングスタンダードに従う
- インデント: スペース4文字
- 行末: Unix LF
- ファイル末尾: 改行必須

#### 命名規則
```php
// クラス: パスカルケース
class EntryImporter {}
class WXRParser {}

// メソッド: キャメルケース
public function importEntry() {}
public function validateWxrFile() {}

// 変数: キャメルケース
$entryData = [];
$sessionId = 'wp_import_123';

// 定数: SCREAMING_SNAKE_CASE
const MAX_FILE_SIZE = 50 * 1024 * 1024;
```

#### 型宣言
```php
// 厳密な型宣言を使用
declare(strict_types=1);

// 引数・戻り値の型を明示
public function importEntry(WXREntry $entry, array $settings): array
{
    // 実装
}
```

### HTML/テンプレート

#### a-blog cms規約準拠

[公式ドキュメント](https://developer.a-blogcms.jp/document/template/)に従う

#### CSS クラス命名

[公式スタイルガイド](https://developer.a-blogcms.jp/document/reference/styleguide/)に従う

### JavaScript

#### ES6+使用
```javascript
// const/let使用（var禁止）
const fileInput = document.getElementById('wxr_file');

// アロー関数使用
fileInput.addEventListener('change', (e) => {
    handleFile(e.target.files[0]);
});

// async/await使用
async function uploadFile(file) {
    const response = await fetch('/upload', {
        method: 'POST',
        body: formData
    });
}
```

## ファイル構成

### ディレクトリ構造
```
src/
├── ServiceProvider.php          # プラグインエントリーポイント
├── GET/WpImport/               # 画面表示モジュール
├── POST/WpImport/              # 処理実行モジュール
├── Services/                   # ビジネスロジック
└── template/admin/             # 管理画面テンプレート
```

### 責任分離
- **GET**: 画面表示のみ、ビジネスロジック禁止
- **POST**: 処理実行のみ、Servicesクラス呼び出し
- **Services**: ビジネスロジック実装、HTTP要素依存禁止

## エラーハンドリング

### ログ出力
```php
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;

// 情報ログ
Logger::info('WXR解析開始', ['file' => $fileName]);

// エラーログ
Logger::error('インポートエラー', Common::exceptionArray($e));
```

[監査ログ機能についての開発者向けドキュメント](https://developer.a-blogcms.jp/document/auditlog/entry-3974.html)

### 例外処理
```php
try {
    $result = $this->processEntry($entry);
    return ['success' => true, 'data' => $result];
} catch (\Exception $e) {
    Logger::error('処理エラー', Common::exceptionArray($e));
    return ['success' => false, 'error' => $e->getMessage()];
}
```

## データベース操作

### テーブル構造:
- @../../../ablogcms/php/config/schema/db.schema.yaml: テーブル定義
- @../../../ablogcms/php/config/schema/db.index.yaml: インデックス定義
- @../../../ablogcms/php/config/schema/db.engine.yaml: エンジン定義

### SQL実行
```php
use Acms\Services\Facades\Database;
use SQL;

// INSERT
$sql = SQL::newInsert('entry');
$sql->addInsert('entry_title', $title);
$eid = Database::query($sql->get(dsn()), 'seq');

// SELECT
$sql = SQL::newSelect('entry');
$sql->addSelect('entry_id', 'entry_title');
$sql->addWhere(SQL::newOpr('entry_status', 'open'));
$entries = Database::query($sql->get(dsn()), 'all');
```

### トランザクション
```php
Database::connection()->beginTransaction();
try {
    // 処理
    Database::connection()->commit();
} catch (\Exception $e) {
    Database::connection()->rollBack();
    throw $e;
}
```

## テスト

### 単体テスト（PHPUnit）
```php
class ParserTest extends \PHPUnit\Framework\TestCase
{
    public function testParseValidWXR(): void
    {
        $parser = new Parser();
        $items = iterator_to_array($parser->parse($this->getValidWXRPath()));

        $this->assertGreaterThan(0, count($items));
        $this->assertArrayHasKey('post_type', $items[0]);
    }
}
```

### 実行コマンド
```bash
# コーディング規約チェック
composer run lint

# フォーマット修正
composer run format

# テスト実行（将来実装）
composer run test
```

## セキュリティ

### 基本原則
- 管理者権限必須: `sessionWithAdministration()`
- ファイルアップロード検証: サイズ・拡張子・MIMEタイプ
- SQLインジェクション対策: SQLクラス使用必須
- XSS対策: テンプレート変数自動エスケープ

### バリデーション例
```php
// ファイル検証
private function validateFile(array $file): bool
{
    return $file['error'] === UPLOAD_ERR_OK &&
           $file['size'] <= $this->getMaxFileSize() &&
           $this->isValidExtension($file['name']);
}

// 権限チェック
public function post()
{
    if (!sessionWithAdministration()) {
        $this->addError('管理者権限が必要です。');
        return $this->Post;
    }
    // 処理続行
}
```

## Git運用

### コミットメッセージ
```
feat: WXR解析エンジンの実装
fix: メディアダウンロードのメモリリーク修正
docs: API仕様書の更新
style: コーディングスタイルの統一
refactor: EntryImporterクラスのリファクタリング
test: WXRParserの単体テスト追加
```

### ブランチ運用
- メイン: `master`
- 機能開発: `feature/機能名`
- バグ修正: `fix/問題名`

## パフォーマンス

### メモリ効率
```php
// Generatorを使用してメモリ効率化
public function parse(string $filePath): \Generator
{
    foreach ($this->parseItems($filePath) as $item) {
        yield $item; // 一度に全てロードしない
    }
}
```

### 実行時間制限
```php
// 大量データ処理時の時間管理
private function shouldPause(): bool
{
    return (time() - $this->startTime) > $this->maxExecutionTime;
}
```

## 開発環境セットアップ

### 初期設定
```bash
# 依存関係インストール
composer install

# 開発環境セットアップ
npm run setup

# プラグインビルド
npm run build:app
```

### 必要な拡張機能
- PHP 8.1+
- XMLReader
- cURL
- mbstring

## a-blog cms コアについて

a-blog cms のコア実装に関する情報は、親ディレクトリの `@../../../ablogcms/` を参照してください：

- **データベース操作**: `@../../../ablogcms/php/Services/Database` - データベースアクセス層、PDOベースの統一インターフェース
- **SQLクエリビルダー**: `@../../../ablogcms/php/SQL` - Doctrine DBALを使用したSQLクエリ構築
- **スキーマ定義**: `@../../../ablogcms/php/config/schema/` - データベース構造、エンジン、インデックス設定
- **プラグイン構造**: `@../../../ablogcms/extension/plugins/` - プラグインの配置場所（シンボリックリンク）

詳しくは公式の [API Reference](https://developer.a-blogcms.jp/document/reference/php/) も参照してください。

効率的で保守性の高いコード開発を心がけ、a-blog cmsとの統合を重視した開発を行います。
