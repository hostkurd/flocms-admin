<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Admin;
use FloCMS\Core\Csrf;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Uploader\Chunked\ChunkedUploader;
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\UploadResult;

/**
 * Uploads of import files (0.25) through flocms-uploader, into the private
 * storage/admin-imports/ folder: in one request (pages without JavaScript) or
 * in parts of 2 MB (the browser), so a host's small upload limit is no obstacle.
 * Only .csv, .tsv and .txt files with a text type are accepted.
 */
final class ImportFiles
{
    public const CHUNK_BYTES = 2097152;
    private const TYPES = ['text/csv', 'text/plain', 'application/csv', 'text/tab-separated-values', 'application/vnd.ms-excel', 'text/x-csv'];

    public static function uploader(): Uploader
    {
        $folder = ImportStore::folder();
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) { throw new \RuntimeException('Cannot create the import folder ' . $folder . '.'); }
        return Uploader::make(['default_disk' => 'imports', 'disks' => ['imports' => ['driver' => 'local', 'root' => $folder, 'visibility' => 'private']]])
            ->allowExtensions(['csv', 'tsv', 'txt'])->allowMimeTypes(self::TYPES)->maxBytes(Admin::config()->import()['max_bytes']);
    }

    /** A file sent with the form. @param array<string, mixed> $file */
    public static function upload(array $file): UploadResult
    {
        foreach (['tmp_name', 'name', 'error'] as $key) { if (!isset($file[$key]) || !is_scalar($file[$key])) { throw new HttpException(422); } }
        $result = self::uploader()->upload($file);
        @chmod((string) $result->absolutePath(), 0600);
        return $result;
    }

    public static function chunks(): ChunkedUploader
    {
        $directory = ImportStore::folder() . '/parts';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create the import folder.'); }
        return self::uploader()->chunked($directory)->chunkSize(self::CHUNK_BYTES)->maxChunkBytes(self::CHUNK_BYTES * 2);
    }

    /** Only the user and session that started a part upload can continue it. */
    public static function owner(): string
    {
        $user = (string) Session::get('user_id', '');
        if ($user === '') { throw new HttpException(401); }
        return $user . '|' . hash('sha256', Csrf::token());
    }
}
