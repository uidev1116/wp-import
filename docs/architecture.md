# 技術仕様書

## テクノロジースタック

### 基盤技術
- **PHP 8.1+**: コア実装言語（XMLReader, cURL, mbstring）
- **a-blog cms v3.2+**: ベースCMS（Logger, Template, ServiceProvider）
- **Vanilla JavaScript**: 管理画面UI（Fetch API）

### 外部依存関係
```json
{
    "require": {
        "php": ">=8.1",
        "ext-xml": "*",
        "ext-xmlreader": "*",
        "ext-curl": "*",
        "ext-mbstring": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "squizlabs/php_codesniffer": "^3.7"
    }
}
```

## 開発環境

### 必須ツール
- PHP 8.1+ ローカル環境
- Composer 2.x

### コード品質
```bash
composer run lint  # PSR12準拠チェック
composer run test  # PHPUnit実行
```

## システム要件

### 動作環境
- **PHP**: 8.1+（xmlreader, curl, mbstring拡張）
- **a-blog cms**: 3.2+（Logger, Template, ServiceProvider）
- **メモリ**: 256MB（memory_limit）
- **ストレージ**: 1GB空き容量

### パフォーマンス設計

#### ストリーミング処理
```php
class Parser {
    public function parse(string $filePath): \Generator {
        $reader = new XMLReader();
        $reader->open($filePath);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
                yield $this->extractItem();
            }
        }
    }
}
```

## セキュリティ

### 基本検証
```php
class SecurityValidator {
    public function validateWXRFile(string $filePath): bool {
        return file_exists($filePath) &&
               in_array(mime_content_type($filePath), ['application/xml', 'text/xml']) &&
               filesize($filePath) <= 50 * 1024 * 1024; // 50MB制限
    }

    public function validateMediaUrl(string $url): bool {
        $parsed = parse_url($url);
        return isset($parsed['scheme'], $parsed['host']) &&
               in_array($parsed['scheme'], ['http', 'https']);
    }
}
```

## データ管理

### JSON ファイルベース進捗
```json
// cache/wp-import/progress/{session_id}.json
{
    "session_id": "wp_import_20250111_143020",
    "status": "running",
    "percentage": 75,
    "current_message": "メディアダウンロード中... (543/567)",
    "statistics": {
        "total_items": 1801,
        "processed_items": 1350,
        "error_count": 24
    }
}
```

### 一時ファイル管理
```php
class TemporaryFileManager {
    private string $cacheDir = CACHE_DIR . 'wp-import/';

    public function createSessionDir(string $sessionId): string {
        $sessionDir = $this->cacheDir . $sessionId . '/';
        mkdir($sessionDir, 0755, true);
        return $sessionDir;
    }

    public function cleanup(string $sessionId, int $days = 7): void {
        $sessionDir = $this->cacheDir . $sessionId;
        if (filemtime($sessionDir) < time() - ($days * 86400)) {
            unlink($sessionDir);
        }
    }
}
```

## ログ統合

```php
class LogManager {
    public function info(string $message): void {
        Logger::info("[WPImport] {$message}");
    }

    public function error(string $message): void {
        Logger::error("[WPImport] {$message}");
    }
}
```

## 実装優先度

### Phase 1: コア機能
- WXR解析（Parser.php）
- エントリー・メディア移行（EntryImporter.php, MediaImporter.php）
- URL書き換え（UrlRewriter.php）

### Phase 2: UI・進捗管理
- ProgressTracker実装
- SecurityValidator実装
- ウィザード画面

### Phase 3: 運用機能
- TemporaryFileManager実装
- 基本エラーハンドリング

MVPとして必要最小限の機能で効率的に実装します。
