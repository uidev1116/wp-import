<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Import;

use SQL;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Media;
use Acms\Services\Facades\Common;
use Acms\Plugins\WPImport\Services\WXR\WXRMedia;

/**
 * メディアファイルのa-blog cmsへの移行処理
 */
class MediaImporter
{

    /**
     * メディアをa-blog cmsに移行
     *
     * @param WXRMedia $media
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param string $localPath ダウンロード済みのローカルファイルパス
     * @return array{success: bool, media_id?: int, path?: string, error?: string}
     */
    public function importMedia(WXRMedia $media, array $settings, string $localPath): array
    {
        if (config('media_library') !== 'on') {
            return [
                'success' => false,
                'error' => 'メディアライブラリーが無効です'
            ];
        }
        try {
            // ファイルの存在確認
            if (!LocalStorage::exists($localPath)) {
                return [
                    'success' => false,
                    'error' => 'ローカルファイルが存在しません: ' . $localPath
                ];
            }

            // メディアファイルのアップロード処理
            $uploadData = $this->processMediaUpload($localPath, $media, $settings);
            if (!$uploadData) {
                return [
                    'success' => false,
                    'error' => 'メディアファイルのアップロードに失敗しました'
                ];
            }

            // データベースに登録
            $mediaId = $this->registerInDatabase($media, $uploadData, $settings);
            if (!$mediaId) {
                return [
                    'success' => false,
                    'error' => 'データベースへの登録に失敗しました'
                ];
            }

            Logger::debug('【WPImport plugin】メディアインポート成功', [
                'media_id' => $mediaId,
                'wp_post_id' => $media->wpPostId,
                'path' => $uploadData['path'],
            ]);

            return [
                'success' => true,
                'media_id' => $mediaId,
                'path' => $uploadData['path'],
            ];

        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】メディアインポートエラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId,
                'local_path' => $localPath
            ]));

            return [
                'success' => false,
                'error' => 'インポート中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }


    /**
     * ダウンロード済みファイルをa-blog cmsメディアに登録
     *
     * @param string $localPath
     * @param WXRMedia $media
     * @param array $settings
     * @return array{path: string, type: string, name: string, size: string, filesize: int, extension: string}|null
     */
    private function processMediaUpload(string $localPath, WXRMedia $media, array $settings): ?array
    {
        // ファイル情報を準備
        $fileInfo = $this->prepareFileInfo($localPath, $media);
        if (!$fileInfo) {
            return null;
        }

        // MIMEタイプに基づいて適切なアップロード処理を実行
        $mimeType = $fileInfo['mime_type'];

        if (Media::isImageFile($mimeType)) {
            return $this->uploadImageFromFile($fileInfo);
        } elseif (Media::isSvgFile($mimeType)) {
            return $this->uploadSvgFromFile($fileInfo);
        } else {
            return $this->uploadFileFromFile($fileInfo);
        }
    }

    /**
     * ファイル情報を準備
     *
     * @param string $localPath
     * @param WXRMedia $media
     * @return array{tmp_name: string, name: string, type: string, size: int, mime_type: string}|null
     */
    private function prepareFileInfo(string $localPath, WXRMedia $media): ?array
    {
        if (!LocalStorage::exists($localPath)) {
            Logger::error('【WPImport plugin】ローカルファイルが存在しません', [
                'path' => $localPath
            ]);
            return null;
        }

        $size = LocalStorage::getFileSize($localPath);
        $mimeType = LocalStorage::getMimeType($localPath);

        if (!$mimeType) {
            Logger::error('【WPImport plugin】MIMEタイプの取得に失敗', [
                'path' => $localPath
            ]);
            return null;
        }

        return [
            'tmp_name' => $localPath,
            'name' => $media->fileName,
            'type' => $mimeType,
            'size' => $size,
            'mime_type' => $mimeType
        ];
    }

    /**
     * 画像ファイルをアップロード
     *
     * @param array $fileInfo
     * @return array|null
     */
    private function uploadImageFromFile(array $fileInfo): ?array
    {
        // $_FILESの形式に合わせて一時的に設定
        $_FILES['temp_import_file'] = $fileInfo;

        try {
            $data = Media::uploadImage('temp_import_file');
            return $data;
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】画像アップロードに失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name']
            ]);
            return null;
        } finally {
            unset($_FILES['temp_import_file']);
        }
    }

    /**
     * SVGファイルをアップロード
     *
     * @param array $fileInfo
     * @return array|null
     */
    private function uploadSvgFromFile(array $fileInfo): ?array
    {
        $_FILES['temp_import_file'] = $fileInfo;

        try {
            $data = Media::uploadSvg($fileInfo['size'], 'temp_import_file');
            return $data;
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】SVGアップロードに失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name']
            ]);
            return null;
        } finally {
            unset($_FILES['temp_import_file']);
        }
    }

    /**
     * 一般ファイルをアップロード
     *
     * @param array $fileInfo
     * @return array|null
     */
    private function uploadFileFromFile(array $fileInfo): ?array
    {
        $_FILES['temp_import_file'] = $fileInfo;

        try {
            $data = Media::uploadFile($fileInfo['size'], 'temp_import_file');
            return $data;
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】ファイルアップロードに失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name']
            ]);
            return null;
        } finally {
            unset($_FILES['temp_import_file']);
        }
    }

    /**
     * データベースにメディア情報を登録
     *
     * @param WXRMedia $media
     * @param array $uploadData Media::upload*から返されたデータ
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @return int|null メディアID（失敗時はnull）
     */
    private function registerInDatabase(WXRMedia $media, array $uploadData, array $settings): ?int
    {
        try {
            Database::connection()->beginTransaction();

            // メディアIDを取得
            $mediaId = (int)Database::query(SQL::nextval('media_id', dsn()), 'seq');

            // Media Facadeのメソッドを使用してメディアデータを挿入
            $mediaData = $this->prepareMediaData($media, $uploadData, $settings);
            Media::insertMedia($mediaId, $mediaData, $settings['target_blog_id']);

            Database::connection()->commit();

            return intval($mediaId);

        } catch (\Throwable $th) {
            Database::connection()->rollBack();
            Logger::error('【WPImport plugin】メディアデータベース登録エラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId
            ]));
            return null;
        }
    }

    /**
     * Media Facade用のデータを準備
     *
     * @param WXRMedia $media
     * @param array $uploadData
     * @param array $settings
     * @return array
     */
    private function prepareMediaData(WXRMedia $media, array $uploadData, array $settings): array
    {
        $data = [
            'type' => $uploadData['type'],
            'extension' => $uploadData['extension'],
            'path' => $uploadData['path'],
            'name' => $uploadData['name'],
            'filesize' => $uploadData['filesize'],
            'size' => $uploadData['size'] ?? '',
        ];

        // WordPressから引き継ぐメタデータをカスタムフィールドに設定
        if ($media->title) {
            $data['field_1'] = $media->title; // caption
        }
        if ($media->description) {
            $data['field_1'] = $media->description; // caption (titleより優先)
        }
        if ($media->altText) {
            $data['field_3'] = $media->altText; // alt
        }

        return $data;
    }

    /**
     * a-blog cmsのメディアパスを取得
     *
     * @param int $mediaId
     * @return string|null
     */
    public function getMediaPath(int $mediaId): ?string
    {
        $SQL = SQL::newSelect('media');
        $SQL->addSelect('media_path');
        $SQL->addWhereOpr('media_id', $mediaId);
        $SQL->setLimit(1);

        $result = Database::query($SQL->get(dsn()), 'one');
        return $result ?: null;
    }
}
