<?php

namespace App\Services;

use App\Traits\GuardsDigitalHatchRuns;
use App\Traits\HatchImportable;

/**
 * The b2r side of the digital hatch: what the mobile side put on the FTP is taken into the bucket, where the importers read it.
 *  - fetch: new booking / application log files go into the bucket folders the importers read;
 *  - fetch attachments: the files and images named by the bookings go into the folder AttachmentService reads.
 * (The r2b side is one service per stream: UsersExportService and BookingStatusSnapshotService.)
 * Each run is serialized by a lock, so the scheduler and a manual run cannot overlap. HATCH_ENABLED=false turns it off.
 */
class DigitalHatchService
{
    use GuardsDigitalHatchRuns, HatchImportable;

    /** @return array{enabled: bool, busy?: bool, files?: array<string, array<int, string>>, errors?: array<int, string>} the names landed, by stream */
    public function fetch(): array
    {
        return $this->locked('fetch', fn (): array => $this->downloadFiles());
    }

    /** @return array{enabled: bool, busy?: bool, files?: array<int, string>, errors?: array<int, string>} the names landed */
    public function fetchAttachments(): array
    {
        return $this->locked('fetch-attachments', fn (): array => $this->downloadAttachments());
    }
}
