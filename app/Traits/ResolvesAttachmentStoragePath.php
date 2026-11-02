<?php

namespace App\Traits;

use App\Services\StorageService;

/**
 * Maps an attachment file name to its bucket destination: the folder AttachmentService looks in when somebody opens a booking
 * (ATTACHMENTS_FOLDER_NAME). Used by the automatic FTP fetch (digital-hatch:fetch-attachments).
 */
trait ResolvesAttachmentStoragePath
{
    /** The bucket key of an attachment, e.g. "import-files/SrvOnClickFiles_<stored name>.pdf" (with the bucket name in front outside prod). */
    public function attachmentStoragePathFor(string $fileName): string
    {
        return app(StorageService::class)->buildBucketKey((string) config('filesystems.folder_name.attachments'), $fileName);
    }
}
