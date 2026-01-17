# リポジトリ構造定義書

## 概要

a-blog cms用WordPress移行プラグインの基本的なディレクトリ構成と責任分離を定義します。

## ディレクトリ構造

```
plugins/WPImport/
├── README.md
├── composer.json
├── phpcs.xml
├── phpunit.xml
│
├── docs/                         # プロジェクトドキュメント
│   ├── product-requirements.md
│   ├── functional-design.md
│   ├── architecture.md
│   ├── repository-structure.md
│   └── glossary.md
│
├── src/                          # プラグインコード
│   ├── ServiceProvider.php      # エントリーポイント
│   ├── GET/                     # 画面表示
│   ├── POST/                    # 処理実行
│   └── Services/                # ビジネスロジック
│
├── template/                     # テンプレートファイル
│   └── admin/                   # 管理画面
│
├── tests/                        # テストコード
│   ├── Unit/
│   └── fixtures/
│
└── vendor/                       # Composer依存関係 (.gitignore対象)
```

## 主要ディレクトリの役割

### /src - プラグインコア

#### GET/ - 画面表示モジュール
```
GET/WpImport/
├── Upload.php       # WXRアップロード画面
├── Progress.php     # 進捗表示画面
└── Report.php       # 結果レポート画面
```

#### POST/ - 処理実行モジュール
```
POST/WpImport/
├── Upload.php       # WXRファイルアップロード処理
└── Execute.php      # 移行実行処理
```

#### Services/ - ビジネスロジック
```
Services/
├── WXR/
│   ├── Parser.php           # XMLパーサー
│   └── EntryExtractor.php   # データ抽出
│
├── Import/
│   ├── EntryImporter.php    # エントリー移行
│   ├── MediaImporter.php    # メディア移行
│   └── UrlRewriter.php      # URL書き換え
│
└── Progress/
    └── ProgressTracker.php   # 進捗管理
```

### /template - テンプレートファイル

```
template/admin/
├── upload.html              # WXRアップロード画面
├── upload-topicpath.html    # パンくずリスト
├── progress.html            # 進捗表示画面
├── progress-topicpath.html
├── report.html              # 結果レポート画面
└── report-topicpath.html
```

### /tests - テストコード

```
tests/
├── Unit/
│   └── Services/            # Services層テスト
└── fixtures/
    └── wxr/                 # サンプルWXRファイル
```

## 基本ルール

### 責任分離
- **GET/**: 画面表示のみ、ビジネスロジック禁止
- **POST/**: 処理実行のみ、Servicesクラス呼び出し
- **Services/**: ビジネスロジック実装、HTTP要素への依存禁止

### 命名規則
- **PHPクラス**: パスカルケース（`EntryExtractor.php`）
- **テンプレート**: ケバブケース（`upload.html`）
- **進捗ファイル**: JSONファイル（`{session_id}.json`）

### 階層制限
- 最大3階層まで（`src/Services/WXR/Parser.php`）
- 機能別分類（技術別分類禁止）

## a-blog cms 統合

a-blog cmsプラグインディレクトリ構造：

```
ablogcms/extension/plugins/WPImport/
├── GET/                   # → src/GET/ へのシンボリックリンク
├── POST/                  # → src/POST/ へのシンボリックリンク
├── template/              # → template/ へのシンボリックリンク
└── ServiceProvider.php    # → src/ServiceProvider.php へのシンボリックリンク
```

## 開発環境

### 必須ファイル
- `composer.json`: PHP依存関係
- `phpcs.xml`: コーディング規約（PSR12）
- `phpunit.xml`: テスト設定
- `.gitignore`

### Git管理
- メインブランチ: `master`
- 機能ブランチ: `feature/{機能名}`
- セマンティックバージョニング: `v1.0.0`

MVP実装に必要最小限の構造で効率的な開発を行います。
