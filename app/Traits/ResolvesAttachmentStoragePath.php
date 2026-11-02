<?php

namespace App\Traits;

use App\Services\StorageService;

/**
 * Maps an attachment file name to where it lands in the bucket: the folder the received files wait in (IMPORT_FILES_FOLDER_NAME, "import-files"),
 * from which ImportFileService saves them in the database and moves them on. Used by the FTP import of the files (importFile:ftp).
 */
trait ResolvesAttachmentStoragePath
{
    /** The bucket key of a received attachment, e.g. "import-files/SrvOnClickFiles_<stored name>.pdf" (with the bucket name in front outside prod). */
    public function attachmentStoragePathFor(string $fileName): string
    {
        return app(StorageService::class)->buildBucketKey((string) config('filesystems.folder_name.import_files'), $fileName);
    }
}
