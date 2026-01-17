<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Content;

use Acms\Services\Facades\Application as Container;
use Acms\Plugins\WPImport\Services\Import\MediaImporter;

/**
 * コンテンツ内のWordPress URL をa-blog cms URLに書き換える処理
 */
class UrlRewriter
{

    /** @var array<string, string> WordPressサイトのベースURLからa-blog cmsのベースURLへのマッピング */
    private array $baseUrlMap = [];

    /** @var array<string, string> メディアURLのマッピングキャッシュ */
    private array $mediaUrlCache = [];

    private MediaImporter $mediaImporter;

    public function __construct()
    {
        $this->mediaImporter = Container::make(MediaImporter::class);
    }

    /**
     * コンテンツ内のURLを書き換え
     *
     * @param string $content
     * @param array{
     *     wp_base_url?: string,
     *     cms_base_url?: string,
     *     media_mapping?: array<int, int>
     * } $options
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     media_replaced: int,
     *     link_replaced: int
     * }
     */
    public function rewriteUrls(string $content, array $options = []): array
    {
        $replacedUrls = [];
        $mediaReplaced = 0;
        $linkReplaced = 0;

        // WordPress ベースURLの設定
        if (($options['wp_base_url'] ?? '') !== '') {
            $wpBaseUrl = rtrim($options['wp_base_url'], '/');
            $cmsBaseUrl = rtrim($options['cms_base_url'] ?? '', '/') ?: rtrim(HTTP_REQUEST_URL, '/');
            $this->baseUrlMap[$wpBaseUrl] = $cmsBaseUrl;
        }

        // メディアURLの書き換え
        $result = $this->rewriteMediaUrls($content, $options['media_mapping'] ?? []);
        $content = $result['content'];
        $mediaReplaced = $result['replaced_count'];
        $replacedUrls = array_merge($replacedUrls, $result['replaced_urls']);

        // 内部リンクの書き換え
        $result = $this->rewriteInternalLinks($content);
        $content = $result['content'];
        $linkReplaced = $result['replaced_count'];
        $replacedUrls = array_merge($replacedUrls, $result['replaced_urls']);

        // 絶対URLの書き換え
        $result = $this->rewriteAbsoluteUrls($content);
        $content = $result['content'];
        $replacedUrls = array_merge($replacedUrls, $result['replaced_urls']);

        return [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'media_replaced' => $mediaReplaced,
            'link_replaced' => $linkReplaced,
        ];
    }

    /**
     * メディアURLを書き換え
     *
     * @param string $content
     * @param array<int, int> $mediaMapping WordPress投稿IDからa-blog cmsメディアIDへのマッピング
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function rewriteMediaUrls(string $content, array $mediaMapping): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        // img タグのsrc属性を書き換え
        $content = preg_replace_callback(
            '/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>/i',
            function($matches) use ($mediaMapping, &$replacedUrls, &$replacedCount) {
                $fullTag = $matches[0];
                $beforeSrc = $matches[1];
                $srcUrl = $matches[2];
                $afterSrc = $matches[3];

                $newUrl = $this->replaceMediaUrl($srcUrl, $mediaMapping);
                if ($newUrl !== $srcUrl) {
                    $replacedUrls[$srcUrl] = $newUrl;
                    $replacedCount++;
                    return '<img' . $beforeSrc . 'src="' . $newUrl . '"' . $afterSrc . '>';
                }

                return $fullTag;
            },
            $content
        );

        // a タグのhref属性でメディアファイルリンクを書き換え
        $content = preg_replace_callback(
            '/<a([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
            function($matches) use ($mediaMapping, &$replacedUrls, &$replacedCount) {
                $fullTag = $matches[0];
                $beforeHref = $matches[1];
                $hrefUrl = $matches[2];
                $afterHref = $matches[3];

                // メディアファイルの拡張子をチェック
                if ($this->isMediaFile($hrefUrl)) {
                    $newUrl = $this->replaceMediaUrl($hrefUrl, $mediaMapping);
                    if ($newUrl !== $hrefUrl) {
                        $replacedUrls[$hrefUrl] = $newUrl;
                        $replacedCount++;
                        return '<a' . $beforeHref . 'href="' . $newUrl . '"' . $afterHref . '>';
                    }
                }

                return $fullTag;
            },
            $content
        );

        return [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }

    /**
     * 内部リンクを書き換え
     *
     * @param string $content
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function rewriteInternalLinks(string $content): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        foreach ($this->baseUrlMap as $wpBaseUrl => $cmsBaseUrl) {
            // a タグのhref属性を書き換え
            $content = preg_replace_callback(
                '/<a([^>]*?)href=["\'](' . preg_quote($wpBaseUrl, '/') . '[^"\']*)["\']([^>]*?)>/i',
                function($matches) use ($wpBaseUrl, $cmsBaseUrl, &$replacedUrls, &$replacedCount) {
                    $fullTag = $matches[0];
                    $beforeHref = $matches[1];
                    $hrefUrl = $matches[2];
                    $afterHref = $matches[3];

                    // WordPressの投稿URLパターンを検出
                    $newUrl = $this->convertWordPressUrl($hrefUrl, $wpBaseUrl, $cmsBaseUrl);
                    if ($newUrl !== $hrefUrl) {
                        $replacedUrls[$hrefUrl] = $newUrl;
                        $replacedCount++;
                        return '<a' . $beforeHref . 'href="' . $newUrl . '"' . $afterHref . '>';
                    }

                    return $fullTag;
                },
                $content
            );
        }

        return [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }

    /**
     * 絶対URLを相対URLに書き換え
     *
     * @param string $content
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function rewriteAbsoluteUrls(string $content): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        foreach ($this->baseUrlMap as $wpBaseUrl => $cmsBaseUrl) {
            // 単純なURL置換
            $pattern = '/' . preg_quote($wpBaseUrl, '/') . '/i';
            $content = preg_replace_callback(
                $pattern,
                function($matches) use ($wpBaseUrl, $cmsBaseUrl, &$replacedUrls, &$replacedCount) {
                    $replacedUrls[$wpBaseUrl] = $cmsBaseUrl;
                    $replacedCount++;
                    return $cmsBaseUrl;
                },
                $content
            );
        }

        return [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }

    /**
     * メディアURLを置換
     *
     * @param string $url
     * @param array<int, int> $mediaMapping
     * @return string
     */
    private function replaceMediaUrl(string $url, array $mediaMapping): string
    {
        // キャッシュから確認
        if (isset($this->mediaUrlCache[$url])) {
            return $this->mediaUrlCache[$url];
        }

        // WordPress のメディアURL パターンを解析
        $wpPostId = $this->extractWpPostIdFromUrl($url);
        if ($wpPostId && isset($mediaMapping[$wpPostId])) {
            $mediaId = $mediaMapping[$wpPostId];
            $newPath = $this->mediaImporter->getMediaPath($mediaId);

            if ($newPath) {
                $newUrl = ARCHIVES_URL . $newPath;
                $this->mediaUrlCache[$url] = $newUrl;
                return $newUrl;
            }
        }

        // WordPressのwp-content/uploads構造を検出
        if (preg_match('/\/wp-content\/uploads\/(.+)$/', $url, $matches)) {
            $filePath = $matches[1];
            $newUrl = ARCHIVES_URL . BID . '/media/' . $filePath;
            $this->mediaUrlCache[$url] = $newUrl;
            return $newUrl;
        }

        return $url;
    }

    /**
     * URLからWordPress投稿IDを抽出
     *
     * @param string $url
     * @return int|null
     */
    private function extractWpPostIdFromUrl(string $url): ?int
    {
        // WordPress attachment URLのパターンを検索
        // 例: https://example.com/?attachment_id=123
        if (preg_match('/[?&]attachment_id=(\d+)/', $url, $matches)) {
            return intval($matches[1]);
        }

        // その他のパターンは今後追加
        return null;
    }

    /**
     * WordPressのURLをa-blog cmsのURLに変換
     *
     * @param string $wpUrl
     * @param string $wpBaseUrl
     * @param string $cmsBaseUrl
     * @return string
     */
    private function convertWordPressUrl(string $wpUrl, string $wpBaseUrl, string $cmsBaseUrl): string
    {
        // WordPressのパーマリンク構造を解析
        $path = str_replace($wpBaseUrl, '', $wpUrl);

        // 投稿のパターン: /2023/12/31/post-name/
        if (preg_match('/\/(\d{4})\/(\d{2})\/(\d{2})\/([^\/]+)\/$/', $path, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];
            $slug = $matches[4];

            // a-blog cms の URL構造に変換
            return $cmsBaseUrl . '/entry-' . date('Ymd', strtotime($year . '-' . $month . '-' . $day)) . '.html';
        }

        // カテゴリーのパターン: /category/category-name/
        if (preg_match('/\/category\/([^\/]+)\/$/', $path, $matches)) {
            $categorySlug = $matches[1];
            return $cmsBaseUrl . '/category/' . $categorySlug . '/';
        }

        // ページのパターン: /page-name/
        if (preg_match('/\/([^\/]+)\/$/', $path, $matches)) {
            $pageSlug = $matches[1];
            return $cmsBaseUrl . '/' . $pageSlug . '.html';
        }

        // デフォルトは単純置換
        return str_replace($wpBaseUrl, $cmsBaseUrl, $wpUrl);
    }

    /**
     * メディアファイルかどうかを判定
     *
     * @param string $url
     * @return bool
     */
    private function isMediaFile(string $url): bool
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        $mediaExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'zip', 'mp3', 'mp4', 'avi', 'mov'
        ];

        return in_array($extension, $mediaExtensions);
    }

    /**
     * ベースURLマッピングを設定
     *
     * @param array<string, string> $mapping
     */
    public function setBaseUrlMapping(array $mapping): void
    {
        $this->baseUrlMap = $mapping;
    }

    /**
     * メディアURLキャッシュをクリア
     */
    public function clearMediaUrlCache(): void
    {
        $this->mediaUrlCache = [];
    }

    /**
     * ショートコードを除去または変換
     *
     * @param string $content
     * @return string
     */
    public function removeShortcodes(string $content): string
    {
        // WordPress ショートコードパターン
        $patterns = [
            '/\[caption[^\]]*\](.*?)\[\/caption\]/s', // キャプション
            '/\[gallery[^\]]*\]/i', // ギャラリー
            '/\[embed[^\]]*\](.*?)\[\/embed\]/s', // 埋め込み
            '/\[[^\]]+\]/', // その他のショートコード
        ];

        $replacements = [
            '$1', // キャプションは中身だけ残す
            '', // ギャラリーは削除
            '$1', // 埋め込みはURLだけ残す
            '', // その他は削除
        ];

        return preg_replace($patterns, $replacements, $content);
    }
}
