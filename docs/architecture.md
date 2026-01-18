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
- **a-blog cms**: 3.2+
