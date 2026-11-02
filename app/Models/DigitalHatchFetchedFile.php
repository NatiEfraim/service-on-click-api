<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A file already taken from the digital hatch (by its name there), so it is not fetched again: the ":ftp" import commands record every b2r
 * json file and attachment they take, with the stream it belongs to and where it came from.
 */
class DigitalHatchFetchedFile extends Model
{
    use HasUuid;

    /** source: the FTP server, or the local folder DIGITAL_HATCH_IMPORT_PATH / DIGITAL_HATCH_IMPORT_FILES_PATH stands in for it. */
    public const SOURCE_FTP = 'ftp';
    public const SOURCE_LOCAL = 'local';

    protected $fillable = [
        'uuid',
        'file_name',
        'stream',
        'source',
    ];
}
