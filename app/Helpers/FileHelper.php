<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Helpers;

use App\Models\File;
use App\Models\FileUsage;
use Illuminate\Support\Str;

class FileHelper
{
    // get file storage config by type
    public static function fresnsFileStorageConfigByType(int $type)
    {
        $key = match ($type) {
            1 => 'image',
            2 => 'video',
            3 => 'audio',
            4 => 'document',
        };

        $data = ConfigHelper::fresnsConfigByItemKeys([
            "{$key}_service",
            "{$key}_secret_id",
            "{$key}_secret_key",
            "{$key}_bucket_name",
            "{$key}_bucket_area",
            "{$key}_bucket_domain",
            "{$key}_filesystem_disk",
            "{$key}_url_status",
            "{$key}_url_key",
            "{$key}_url_expire",
        ]);

        $config = [
            'service' => $data["{$key}_service"],
            'secretId' => $data["{$key}_secret_id"],
            'secretKey' => $data["{$key}_secret_key"],
            'bucketName' => $data["{$key}_bucket_name"],
            'bucketArea' => $data["{$key}_bucket_area"],
            'bucketDomain' => $data["{$key}_bucket_domain"],
            'filesystemDisk' => $data["{$key}_filesystem_disk"],
            'antiLinkStatus' => $data["{$key}_url_status"],
            'antiLinkKey' => $data["{$key}_url_key"],
            'antiLinkExpire' => $data["{$key}_url_expire"],
        ];

        $config['storageConfigStatus'] = true;
        if (empty($config['secretId']) || empty($config['secretKey']) || empty($config['bucketName']) || empty($config['bucketDomain'])) {
            $config['storageConfigStatus'] = false;
        }

        $config['antiLinkConfigStatus'] = true;
        if (! $config['antiLinkStatus']) {
            $config['antiLinkConfigStatus'] = false;
        }
        if (empty($config['service']) || empty($config['antiLinkKey']) || empty($config['antiLinkExpire'])) {
            $config['antiLinkConfigStatus'] = false;
        }

        return $config;
    }

    // get file accept by type
    public static function fresnsFileAcceptByType(?int $type = null)
    {
        if (empty($type)) {
            return null;
        }

        $fileExt = match ($type) {
            1 => ConfigHelper::fresnsConfigByItemKey('image_extension_names'),
            2 => ConfigHelper::fresnsConfigByItemKey('video_extension_names'),
            3 => ConfigHelper::fresnsConfigByItemKey('audio_extension_names'),
            4 => ConfigHelper::fresnsConfigByItemKey('document_extension_names'),
        };

        $fileExt = Str::lower($fileExt);

        // $accept = str_replace(',', ',.', $fileExt);
        // $fileAccept = '';
        // if ($accept) {
        //     $fileAccept = Str::start($accept, '.');
        // }

        // $builder = \Mimey\MimeMappingBuilder::create();
        // $mapping = $builder->getMapping();
        // $mapping['mimes'];
        // $mapping['extensions'];
        // foreach ($mapping['mimes'] as $ext => $mimes) {
        // }
        // foreach ($mapping['extensions'] as $mime => $exts) {
        // }

        $mimes = new \Mimey\MimeTypes;

        $fileExts = explode(',', $fileExt);

        $result = [];
        foreach ($fileExts as $ext) {
            $fileExtMimes = $mimes->getAllMimeTypes($ext);
            foreach ($fileExtMimes as $fileExtMime) {
                $result[] = $fileExtMime;
            }
        }

        $fileAccept = implode(',', $result);

        return $fileAccept;
    }

    // get file storage path
    public static function fresnsFileStoragePath(int $fileType, int $usageType)
    {
        $fileTypeDir = match ($fileType) {
            1 => 'images',
            2 => 'videos',
            3 => 'audios',
            4 => 'documents',
        };

        $usageTypeDir = match ($usageType) {
            1 => '/others/{YYYYMM}/',
            2 => '/systems/{YYYYMM}/',
            3 => '/operations/{YYYYMM}/',
            4 => '/stickers/{YYYYMM}/',
            5 => '/users/{YYYYMM}/{DD}/',
            6 => '/conversations/{YYYYMM}/{DD}/',
            7 => '/posts/{YYYYMM}/{DD}/',
            8 => '/comments/{YYYYMM}/{DD}/',
            9 => '/extends/{YYYYMM}/{DD}/',
            10 => '/plugins/{YYYYMM}/{DD}/',
        };

        $replaceUseTypeDir = str_replace(
            ['{YYYYMM}', '{DD}'],
            [date('Ym'), date('d')],
            $usageTypeDir
        );

        return sprintf('%s/%s', trim($fileTypeDir, '/'), trim($replaceUseTypeDir, '/'));
    }

    // get file info by file id or fid
    public static function fresnsFileInfoById(string $fileIdOrFid)
    {
        /** @var File $file */
        if (StrHelper::isPureInt($fileIdOrFid)) {
            $file = File::whereId($fileIdOrFid)->first();
        } else {
            $file = File::whereFid($fileIdOrFid)->first();
        }

        if (empty($file)) {
            return null;
        }

        $storageConfig = FileHelper::fresnsFileStorageConfigByType($file->type);

        if ($storageConfig['antiLinkConfigStatus']) {
            $fresnsResponse = \FresnsCmdWord::plugin($storageConfig['service'])->getAntiLinkFileInfo([
                'type' => $file->type,
                'fileIdOrFid' => strval($file->id),
            ]);

            return $fresnsResponse->getData() ?? null;
        }

        return $file->getFileInfo();
    }

    // get file info list by file id or fid
    public static function fresnsFileInfoListByIds(array $fileIdsOrFids)
    {
        $files = File::whereIn('id', $fileIdsOrFids)
            ->orWhereIn('fid', $fileIdsOrFids)
            ->get()
            ->groupBy('type');

        $data['images'] = $files->get(File::TYPE_IMAGE)?->all() ?? [];
        $data['videos'] = $files->get(File::TYPE_VIDEO)?->all() ?? [];
        $data['audios'] = $files->get(File::TYPE_AUDIO)?->all() ?? [];
        $data['documents'] = $files->get(File::TYPE_DOCUMENT)?->all() ?? [];

        $fileList = FileHelper::handleAntiLinkFileInfoList($data);

        return $fileList;
    }

    // get file info list by table column
    public static function fresnsFileInfoListByTableColumn(string $tableName, string $tableColumn, ?int $tableId = null, ?string $tableKey = null)
    {
        $fileUsageQuery = FileUsage::with('file')
            ->where('table_name', $tableName)
            ->where('table_column', $tableColumn)
            ->orderBy('rating');

        if (empty($tableId)) {
            $fileUsageQuery->where('table_key', $tableKey);
        } else {
            $fileUsageQuery->where('table_id', $tableId);
        }

        $fileUsages = $fileUsageQuery->get();

        $fileData = $fileUsages->map(fn ($fileUsage) => $fileUsage->file)->groupBy('type');

        $data['images'] = $fileData->get(File::TYPE_IMAGE)?->all() ?? [];
        $data['videos'] = $fileData->get(File::TYPE_VIDEO)?->all() ?? [];
        $data['audios'] = $fileData->get(File::TYPE_AUDIO)?->all() ?? [];
        $data['documents'] = $fileData->get(File::TYPE_DOCUMENT)?->all() ?? [];

        $fileList = FileHelper::handleAntiLinkFileInfoList($data);

        return $fileList;
    }

    // get file url by table column
    public static function fresnsFileUrlByTableColumn(?int $idColumn = null, ?string $urlColumn = null, ?string $urlType = null)
    {
        if (! $idColumn && ! $urlColumn) {
            return null;
        }

        if (! $idColumn) {
            return $urlColumn;
        }

        $file = File::where('id', $idColumn)->first();

        if (empty($file)) {
            return null;
        }

        $urlType = $urlType ?: 'imageConfigUrl';

        $antiLinkConfigStatus = FileHelper::fresnsFileStorageConfigByType($file->type)['antiLinkConfigStatus'];

        if ($antiLinkConfigStatus) {
            $fresnsResponse = \FresnsCmdWord::plugin()->getAntiLinkFileInfo([
                'type' => $file->type,
                'fileIdOrFid' => strval($file->id),
            ]);

            return $fresnsResponse->getData($urlType) ?? null;
        }

        return $file->getFileInfo()[$urlType] ?? null;
    }

    // get file original url by file id or fid
    public static function fresnsFileOriginalUrlById(string $fileIdOrFid)
    {
        if (StrHelper::isPureInt($fileIdOrFid)) {
            $file = File::whereId($fileIdOrFid)->first();
        } else {
            $file = File::whereFid($fileIdOrFid)->first();
        }

        if (empty($file)) {
            return null;
        }

        $storageConfig = FileHelper::fresnsFileStorageConfigByType($file->type);

        if ($storageConfig['antiLinkConfigStatus']) {
            $fresnsResponse = \FresnsCmdWord::plugin($storageConfig['service'])->getAntiLinkFileOriginalUrl([
                'type' => $file->type,
                'fileIdOrFid' => strval($file->id),
            ]);

            return $fresnsResponse->getData('originalUrl') ?? null;
        }

        return $file->getFileOriginalUrl();
    }

    // get file type number
    public static function fresnsFileTypeNumber(?string $fileName = null)
    {
        $fileTypeNumber = match ($fileName) {
            'image' => 1,
            'video' => 2,
            'audio' => 3,
            'document' => 4,
            'images' => 1,
            'videos' => 2,
            'audios' => 3,
            'documents' => 4,
            default => null,
        };

        return $fileTypeNumber;
    }

    public static function handleAntiLinkFileInfoList(array $files)
    {
        $imageStorageConfig = FileHelper::fresnsFileStorageConfigByType(File::TYPE_IMAGE);
        $videoStorageConfig = FileHelper::fresnsFileStorageConfigByType(File::TYPE_VIDEO);
        $audioStorageConfig = FileHelper::fresnsFileStorageConfigByType(File::TYPE_AUDIO);
        $documentStorageConfig = FileHelper::fresnsFileStorageConfigByType(File::TYPE_DOCUMENT);

        // image
        if ($imageStorageConfig['antiLinkConfigStatus'] && ! empty($files['images'])) {
            $fids = array_column($files['images'], 'fid');

            $fresnsResponse = \FresnsCmdWord::plugin($imageStorageConfig['service'])->getAntiLinkFileInfoList([
                'type' => 1,
                'fileIdsOrFids' => $fids,
            ]);

            $files['images'] = $fresnsResponse->getData();
        } else {
            $files['images'] = array_map(fn ($item) => $item->getFileInfo(), $files['images']);
        }

        // video
        if ($videoStorageConfig['antiLinkConfigStatus'] && ! empty($files['videos'])) {
            $fids = array_column($files['videos'], 'fid');

            $fresnsResponse = \FresnsCmdWord::plugin($videoStorageConfig['service'])->getAntiLinkFileInfoList([
                'type' => 2,
                'fileIdsOrFids' => $fids,
            ]);

            $files['videos'] = $fresnsResponse->getData();
        } else {
            $files['videos'] = array_map(fn ($item) => $item->getFileInfo(), $files['videos']);
        }

        // audio
        if ($audioStorageConfig['antiLinkConfigStatus'] && ! empty($files['audios'])) {
            $fids = array_column($files['audios'], 'fid');

            $fresnsResponse = \FresnsCmdWord::plugin($audioStorageConfig['service'])->getAntiLinkFileInfoList([
                'type' => 3,
                'fileIdsOrFids' => $fids,
            ]);

            $files['audios'] = $fresnsResponse->getData();
        } else {
            $files['audios'] = array_map(fn ($item) => $item->getFileInfo(), $files['audios']);
        }

        // document
        if ($documentStorageConfig['antiLinkConfigStatus'] && ! empty($files['documents'])) {
            $fids = array_column($files['documents'], 'fid');

            $fresnsResponse = \FresnsCmdWord::plugin($documentStorageConfig['service'])->getAntiLinkFileInfoList([
                'type' => 4,
                'fileIdsOrFids' => $fids,
            ]);

            $files['documents'] = $fresnsResponse->getData();
        } else {
            $files['documents'] = array_map(fn ($item) => $item->getFileInfo(), $files['documents']);
        }

        return $files;
    }
}
