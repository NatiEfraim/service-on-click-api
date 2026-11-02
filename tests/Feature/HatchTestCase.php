<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The digital hatch (FTP) between this system and the mobile side, with the bucket (ImportJsonTestCase) and the FTP both a folder in memory
 * (Storage::fake on the "digital-hatch" disk): no test built on this reaches a real server.
 */
abstract class HatchTestCase extends ImportJsonTestCase
{
    protected const RED_TO_BLACK = 'R2B/Tikshoret-R2B-Prod/';
    protected const BLACK_TO_RED = 'Tikshoret-B2R-Prod/';
    protected const ATTACHMENTS = 'DigitalFiles-B2R-Prod/';

    /** A folder of the test's own that takes the place of the FTP (DIGITAL_HATCH_EXPORT_PATH); removed in tearDown. */
    protected ?string $localDir = null;

    /** The folder of the test's own that holds the b2r folders of useLocalImportFolders; removed in tearDown. */
    protected ?string $localImportBase = null;

    /** The json files (DIGITAL_HATCH_IMPORT_PATH) and the files and images (DIGITAL_HATCH_IMPORT_FILES_PATH) a useLocalImportFolders test reads. */
    protected string $localJsonDir = '';

    protected string $localFilesDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('digital-hatch');

        config([
            'services.digital-hatch.enabled' => true,
            // Whatever the .env says: a test reads and writes folders of its own (useLocalFolder, useLocalImportFolders) or the fake FTP, never a real folder.
            'services.digital-hatch.local.export.path' => '',
            'services.digital-hatch.local.import.path' => '',
            'services.digital-hatch.local.import.files_path' => '',
            'services.digital-hatch.red_to_black_ftp_path' => '/' . self::RED_TO_BLACK,
            'services.digital-hatch.black_to_red_ftp_path' => '/' . self::BLACK_TO_RED,
            'services.digital-hatch.black_to_red_ftp_attachments_path' => '/' . self::ATTACHMENTS,
        ]);

        // The mobile app knows the people by their ID number.
        User::where('personal_number', self::SUPER_ADMIN)->update(['personal_id' => '000000001', 'phone' => '0501111111']);
        User::where('personal_number', self::VICE_APPROVER)->update(['personal_id' => '123456789', 'phone' => '0502222222']);
        User::where('personal_number', self::PLAIN_USER)->update(['personal_id' => '223456789']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->localDir, $this->localImportBase] as $dir) {
            if ($dir !== null) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    protected function useLocalFolder(): void
    {
        $this->localDir = sys_get_temp_dir() . '/hatch-r2b-' . uniqid();

        config(['services.digital-hatch.local.export.path' => $this->localDir]);
    }

    /** Two folders that exist and take the place of the FTP's b2r folders: the json files (like tikshort-ftp/b2r) and the attachments (tikshort-ftp/r2b_files). */
    protected function useLocalImportFolders(): void
    {
        $this->localImportBase = sys_get_temp_dir() . '/hatch-b2r-' . uniqid();
        $this->localJsonDir = $this->localImportBase . '/b2r';
        $this->localFilesDir = $this->localImportBase . '/r2b_files';

        mkdir($this->localJsonDir, 0777, true);
        mkdir($this->localFilesDir, 0777, true);

        config([
            'services.digital-hatch.local.import.path' => $this->localJsonDir,
            'services.digital-hatch.local.import.files_path' => $this->localFilesDir,
        ]);
    }

    /** Nothing listens on port 1: the connection to the "FTP" is refused at once. */
    protected function useUnreachableFtp(): void
    {
        config(['filesystems.disks.digital-hatch' => [
            'driver' => 'ftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p',
            'timeout' => 2, 'throw' => true, 'report' => false,
        ]]);
        Storage::forgetDisk('digital-hatch');
    }

    protected function ftp(): Filesystem
    {
        return Storage::disk('digital-hatch');
    }

    protected function bucket(): Filesystem
    {
        return Storage::disk('minio');
    }

    protected function today(): string
    {
        return now(config('app.export_json.timezone'))->format('Y-m-d');
    }

    protected function usersFileName(): string
    {
        return 'SrvOnClick_r2b_users_' . $this->today() . '.json';
    }

    protected function statusFileName(): string
    {
        return 'SrvOnClick_r2b_booking_status_' . $this->today() . '.json';
    }
}
