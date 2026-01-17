<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Media;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Http;
use Acms\Services\Facades\Common;
use Acms\Plugins\WPImport\Services\WXR\WXRMedia;

/**
 * WordPressメディアファイルのダウンロード機能
 */
class Downloader
{

    /** @var string ダウンロードディレクトリのベースパス */
    private string $downloadDir;


    /** @var int 最大ファイルサイズ（バイト） */
    private int $maxFileSize = 50 * 1024 * 1024; // 50MB

    /** @var array<string> 許可するMIMEタイプ */
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    /** @var int ダウンロード間隔（マイクロ秒） */
    private int $downloadDelay = 500000; // 0.5秒

    /** @var array<string, float> ドメイン別の最後のアクセス時刻 */
    private static array $lastAccessTimes = [];

    public function __construct()
    {
        $this->downloadDir = ARCHIVES_DIR . 'wp-import/media/';
        $this->ensureDownloadDirectory();
    }

    /**
     * メディアファイルをダウンロード
     *
     * @param WXRMedia $media
     * @return array{
     *     success: bool,
     *     local_path?: string,
     *     file_name?: string,
     *     file_size?: int,
     *     error?: string
     * }
     */
    public function downloadMedia(WXRMedia $media): array
    {
        if (!$media->isDownloadable()) {
            return [
                'success' => false,
                'error' => 'メディアファイルがダウンロード可能ではありません'
            ];
        }

        // MIMEタイプチェック
        if (!$this->isMimeTypeAllowed($media->mimeType)) {
            return [
                'success' => false,
                'error' => '許可されていないファイルタイプです: ' . $media->mimeType
            ];
        }

        try {
            // ダウンロード先パスを生成
            $localPath = $this->generateLocalPath($media);

            // 既にダウンロード済みの場合はスキップ
            if (LocalStorage::exists($localPath)) {
                Logger::debug('【WPImport plugin】メディアファイルは既に存在します', [
                    'wp_post_id' => $media->wpPostId,
                    'url' => $media->originalUrl,
                    'local_path' => $localPath
                ]);

                return [
                    'success' => true,
                    'local_path' => $localPath,
                    'file_name' => basename($localPath),
                    'file_size' => filesize($localPath),
                ];
            }

            // レート制限を適用
            $this->applyRateLimit($media->originalUrl);

            // ファイルをダウンロード
            $downloadResult = $this->downloadFile($media->originalUrl, $localPath);

            if (!$downloadResult['success']) {
                return $downloadResult;
            }

            Logger::info('【WPImport plugin】メディアファイルダウンロード成功', [
                'wp_post_id' => $media->wpPostId,
                'original_url' => $media->originalUrl,
                'local_path' => $localPath,
                'file_size' => $downloadResult['file_size']
            ]);

            return $downloadResult;

        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】メディアダウンロードエラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId,
                'url' => $media->originalUrl
            ]));

            return [
                'success' => false,
                'error' => 'ダウンロード中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }
    
    /**
     * ファイルをダウンロード
     *
     * @param string $url
     * @param string $localPath
     * @return array{
     *     success: bool,
     *     local_path?: string,
     *     file_name?: string,
     *     file_size?: int,
     *     error?: string
     * }
     */
    private function downloadFile(string $url, string $localPath): array
    {
        try {
            // ディレクトリを作成
            $dir = dirname($localPath);
            if (!LocalStorage::exists($dir)) {
                if (!LocalStorage::makeDirectory($dir)) {
                    return [
                        'success' => false,
                        'error' => 'ディレクトリの作成に失敗しました: ' . $dir
                    ];
                }
            }

            // a-blog cms HTTP Facadeを使用してダウンロード
            try {
                $httpClient = Http::init($url, 'GET')->send();
                $body = $httpClient->getResponseBody();
                $httpCode = (int) $httpClient->getResponseHeader('http_code');

                if ($httpCode !== 200) {
                    return [
                        'success' => false,
                        'error' => sprintf('ダウンロード失敗 (HTTP %d)', $httpCode)
                    ];
                }
            } catch (\Throwable $th) {
                return [
                    'success' => false,
                    'error' => 'ダウンロードに失敗しました: ' . $th->getMessage()
                ];
            }

            // ファイルサイズチェック
            $fileSize = strlen($body);
            if ($fileSize === 0) {
                return [
                    'success' => false,
                    'error' => 'ダウンロードしたファイルが空です'
                ];
            }

            if ($fileSize > $this->maxFileSize) {
                return [
                    'success' => false,
                    'error' => 'ファイルサイズが上限を超えています: ' . $this->formatFileSize($fileSize)
                ];
            }

            // ファイルを保存
            if (!LocalStorage::put($localPath, $body)) {
                return [
                    'success' => false,
                    'error' => 'ファイルの保存に失敗しました: ' . $localPath
                ];
            }

            return [
                'success' => true,
                'local_path' => $localPath,
                'file_name' => basename($localPath),
                'file_size' => $fileSize,
            ];

        } catch (\Throwable $th) {
            return [
                'success' => false,
                'error' => 'ダウンロード中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }

    /**
     * ローカルパスを生成
     *
     * @param WXRMedia $media
     * @return string
     */
    private function generateLocalPath(WXRMedia $media): string
    {
        // 安全なファイル名を生成
        $fileName = $this->sanitizeFileName($media->fileName);

        // 年月ディレクトリを作成（WordPressのアップロード構造に合わせる）
        $datePath = '';
        if ($media->uploadDate !== null) {
            $datePath = $media->uploadDate->format('Y/m/');
        }

        return $this->downloadDir . $datePath . $fileName;
    }

    /**
     * ファイル名をサニタイズ
     *
     * @param string $fileName
     * @return string
     */
    private function sanitizeFileName(string $fileName): string
    {
        // 拡張子を分離
        $pathInfo = pathinfo($fileName);
        $name = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';

        // 不正な文字を除去
        $name = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');

        // ファイル名が空の場合はデフォルト名を使用
        if (!$name) {
            $name = 'unnamed_' . uniqid();
        }

        return $name . ($extension ? '.' . $extension : '');
    }

    /**
     * MIMEタイプが許可されているかチェック
     *
     * @param string $mimeType
     * @return bool
     */
    private function isMimeTypeAllowed(string $mimeType): bool
    {
        return in_array($mimeType, $this->allowedMimeTypes, true);
    }

    /**
     * ダウンロードディレクトリを確保
     */
    private function ensureDownloadDirectory(): void
    {
        if (!LocalStorage::exists($this->downloadDir)) {
            if (!LocalStorage::makeDirectory($this->downloadDir)) {
                throw new \RuntimeException('ダウンロードディレクトリの作成に失敗しました: ' . $this->downloadDir);
            }
        }
    }

    /**
     * ファイルサイズをフォーマット
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return number_format($bytes, $unitIndex > 0 ? 1 : 0) . ' ' . $units[$unitIndex];
    }

    /**
     * ドメイン別のレート制限を適用
     *
     * @param string $url
     */
    private function applyRateLimit(string $url): void
    {
        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain) {
            return;
        }

        $now = microtime(true);

        // 同一ドメインの最後のアクセス時刻をチェック
        if (isset(self::$lastAccessTimes[$domain])) {
            $timeDiff = $now - self::$lastAccessTimes[$domain];
            $requiredDelay = $this->downloadDelay / 1000000; // マイクロ秒を秒に変換

            if ($timeDiff < $requiredDelay) {
                $sleepTime = ($requiredDelay - $timeDiff) * 1000000; // 秒をマイクロ秒に変換
                usleep((int)$sleepTime);
                Logger::debug('【WPImport plugin】レート制限適用', [
                    'domain' => $domain,
                    'sleep_time_ms' => $sleepTime / 1000,
                    'last_access' => self::$lastAccessTimes[$domain],
                    'now' => $now
                ]);
            }
        }

        // 最後のアクセス時刻を更新
        self::$lastAccessTimes[$domain] = microtime(true);
    }

    /**
     * 設定を変更
     *
     * @param array{
     *     max_file_size?: int,
     *     allowed_mime_types?: array<string>,
     *     download_delay?: int
     * } $config
     */
    public function configure(array $config): void
    {
        if (isset($config['max_file_size'])) {
            $this->maxFileSize = max(1024, (int)$config['max_file_size']);
        }

        if (isset($config['allowed_mime_types'])) {
            $this->allowedMimeTypes = $config['allowed_mime_types'];
        }

        if (isset($config['download_delay'])) {
            $this->downloadDelay = max(0, (int)$config['download_delay']);
        }
    }
}
