<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\WXR;

/**
 * WordPressメディア（添付ファイル）の抽出処理
 */
class MediaExtractor
{
    /**
     * WXRアイテムからメディア情報を抽出
     *
     * @param array{
     *     post_type: string,
     *     wp_post_id: int,
     *     title: string,
     *     link: string,
     *     post_date: string,
     *     post_date_gmt: string,
     *     creator: string,
     *     description: string,
     *     encoded_excerpt: string,
     *     wp_post_parent: int,
     *     wp_attachment_url: string,
     *     wp_postmeta: array
     * } $item WXRアイテムデータ
     * @return WXRMedia|null メディア情報、無効な場合はnull
     */
    public function extractMedia(array $item): ?WXRMedia
    {
        if ($item['post_type'] !== 'attachment') {
            return null;
        }

        // 必須フィールドの確認
        if (!($item['wp_attachment_url'] ?? '')) {
            return null;
        }

        $media = new WXRMedia();
        $media->wpPostId = $item['wp_post_id'];
        $media->wpParentId = $item['wp_post_parent'];
        $media->title = $this->sanitizeText($item['title']);
        $media->originalUrl = $item['wp_attachment_url'];
        $media->description = $this->sanitizeText($item['description']);
        $media->uploadDate = $this->parseDateTime($item['post_date_gmt']);
        $media->creator = $item['creator'];

        // ファイル情報を抽出
        $this->extractFileInfo($media, $item['wp_postmeta']);

        return $media;
    }

    /**
     * ファイル情報を抽出してメディアオブジェクトに設定
     *
     * @param WXRMedia $media
     * @param array<array{meta_key: string, meta_value: mixed}> $postMeta
     */
    private function extractFileInfo(WXRMedia $media, array $postMeta): void
    {
        foreach ($postMeta as $meta) {
            switch ($meta['meta_key']) {
                case '_wp_attached_file':
                    $media->filePath = $meta['meta_value'];
                    break;
                case '_wp_attachment_metadata':
                    if (is_string($meta['meta_value'])) {
                        $metadata = unserialize($meta['meta_value']);
                        if (is_array($metadata)) {
                            $media->width = (int)($metadata['width'] ?? 0);
                            $media->height = (int)($metadata['height'] ?? 0);
                            $media->fileSize = (int)($metadata['filesize'] ?? 0);
                            $media->mimeType = $metadata['mime-type'] ?? '';
                            $media->sizes = $metadata['sizes'] ?? [];
                        }
                    }
                    break;
                case '_wp_attachment_image_alt':
                    $media->altText = $this->sanitizeText((string)$meta['meta_value']);
                    break;
            }
        }

        // ファイル名とMIMEタイプの推定
        if (!$media->fileName && $media->originalUrl !== null && $media->originalUrl !== '') {
            $media->fileName = basename(parse_url($media->originalUrl, PHP_URL_PATH));
        }

        if (!$media->mimeType && $media->fileName !== null && $media->fileName !== '') {
            $media->mimeType = $this->guessMimeType($media->fileName);
        }
    }

    /**
     * ファイル拡張子からMIMEタイプを推定
     *
     * @param string $fileName
     * @return string
     */
    private function guessMimeType(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * テキストをサニタイズ
     *
     * @param string $text
     * @return string
     */
    private function sanitizeText(string $text): string
    {
        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 不要な空白を除去
        $text = trim($text);

        return $text;
    }

    /**
     * 日時文字列を解析してDateTimeオブジェクトに変換
     *
     * @param string $dateString
     * @return ?\DateTime
     */
    private function parseDateTime(string $dateString): ?\DateTime
    {
        if (!$dateString || $dateString === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTime($dateString, new \DateTimeZone('GMT'));
        } catch (\Throwable $th) {
            return null;
        }
    }
}