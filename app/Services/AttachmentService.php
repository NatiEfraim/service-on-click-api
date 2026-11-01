<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\File;
use App\Models\Image;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files and images of the bookings. The booking json (SrvOnClick_b2r_export_bookings_*.json) only NAMES them - "SrvOnClickFiles_<stored name>",
 * without the extension - and the bytes arrive by their own way, into one folder of the bucket (filesystems.folder_name.attachments), later than the
 * json or earlier. So a booking is linked to its attachments when the json is read (register), and the object is looked up in the folder when it
 * is wanted (resolve): the first time it is found, the full key is kept on the row.
 * Served only through the API, to a person who may see the booking, and only the types below (anything else is never read, whatever it is called).
 */
class AttachmentService
{
    public const KIND_FILE = 'file';
    public const KIND_IMAGE = 'image';

    /** @var array<string, array{0: string, 1: bool}> extension => [type it is served as, whether a browser may show it in the page (the rest is downloaded)] */
    public const TYPES = [
        'jpg' => ['image/jpeg', true],
        'jpeg' => ['image/jpeg', true],
        'png' => ['image/png', true],
        'webp' => ['image/webp', true],
        'gif' => ['image/gif', true],
        'pdf' => ['application/pdf', true],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
    ];

    /** The extensions to look for, by kind, most likely first. */
    private const EXTENSIONS = [
        self::KIND_IMAGE => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        self::KIND_FILE => ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'],
    ];

    public function __construct(private readonly StorageService $storage)
    {
    }

    /** May this be the stored name of an attachment? (Letters, digits, "_", "-" and "." inside - never a path.) */
    public static function isValidName(mixed $name): bool
    {
        return is_string($name)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,149}$/', $name) === 1
            && !str_contains($name, '..');
    }

    /**
     * Link a booking to the files and images its json names. A name that is saved already is not saved again, and a link that exists stays - reading
     * the same file twice changes nothing.
     * @param array<int, string> $files stored names (without extension) @param array<int, string> $images
     * @return int the links added
     */
    public function register(Booking $booking, array $files, array $images): int
    {
        $added = 0;

        foreach ([self::KIND_FILE => $files, self::KIND_IMAGE => $images] as $kind => $names) {
            foreach (array_values(array_unique($names)) as $name) {
                $attachment = $this->model($kind)::query()->where('name', $name)->first() ?? $this->create($kind, $name);
                $pivot = $kind === self::KIND_FILE ? 'booking_has_files' : 'booking_has_images';
                $column = $kind === self::KIND_FILE ? 'file_id' : 'image_id';

                if (DB::table($pivot)->where('booking_id', $booking->id)->where($column, $attachment->id)->exists()) {
                    continue;
                }

                DB::table($pivot)->insert(['booking_id' => $booking->id, $column => $attachment->id, 'form_field_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                $added++;
            }
        }

        return $added;
    }

    /**
     * The key of the object in the bucket, when its bytes are there. The row is kept with the key found (with its extension), so later calls
     * look at that one key. Null while the bytes have not arrived (or the bucket cannot be read - the caller shows it as "not available yet").
     */
    public function resolve(File|Image $attachment): ?string
    {
        try {
            $disk = Storage::disk(config('filesystems.storage_service'));
            $path = (string) $attachment->path;

            if ($path !== '' && $this->extensionOf($path) !== null) {
                return $disk->exists($path) ? $path : null;
            }

            $name = (string) $attachment->name;

            if (!self::isValidName($name)) {
                return null;
            }

            $base = $this->storage->buildBucketKey((string) config('filesystems.folder_name.attachments'), $name);

            foreach (self::EXTENSIONS[$attachment instanceof File ? self::KIND_FILE : self::KIND_IMAGE] as $extension) {
                if ($disk->exists("{$base}.{$extension}")) {
                    $attachment->forceFill(['path' => "{$base}.{$extension}", 'file_name' => "{$name}.{$extension}"])->save();

                    return "{$base}.{$extension}";
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('AttachmentService: could not look for ' . $attachment->name . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The attachments of a booking as a screen shows them. A booking whose relations are loaded costs no query for the list itself.
     * @return array<int, array{kind: string, id: int, name: string, mime: ?string, inline: bool, available: bool, url: ?string}>
     */
    public function describe(Booking $booking): array
    {
        $rows = [];

        foreach ([self::KIND_FILE => $booking->files, self::KIND_IMAGE => $booking->images] as $kind => $attachments) {
            foreach ($attachments as $attachment) {
                $key = $this->resolve($attachment);
                $type = $key === null ? null : (self::TYPES[$this->extensionOf($key)] ?? null);
                $available = $key !== null && $type !== null;

                $rows[] = [
                    'kind' => $kind,
                    'id' => (int) $attachment->id,
                    'name' => (string) $attachment->name,
                    'mime' => $available ? $type[0] : null,
                    'inline' => $available && $type[1],
                    'available' => $available,
                    'url' => $available ? "/api/bookings-approver-management/bookings/{$booking->serial_number}/attachments/{$kind}/{$attachment->id}" : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * The stream of one attachment of a booking the user may see.
     * @throws DomainError 404 when the booking, the attachment or its bytes are not there - or the user may not see the booking (one answer for all)
     */
    public function open(AccessScope $scope, int $serial, string $kind, int $id): StreamedResponse
    {
        $booking = Booking::query()->where('serial_number', $serial)->first(['id', 'serial_number', 'unit_id', 'booking_type_id', 'status_id']);

        if ($booking === null || !$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, (int) $booking->status_id)) {
            throw new DomainError('לא נמצא', 404);
        }

        $relation = $kind === self::KIND_FILE ? $booking->files() : $booking->images();
        $attachment = $relation->where($kind === self::KIND_FILE ? 'files.id' : 'images.id', $id)->first();

        if ($attachment === null) {
            throw new DomainError('לא נמצא', 404);
        }

        $key = $this->resolve($attachment);
        $type = $key === null ? null : (self::TYPES[$this->extensionOf($key)] ?? null);

        if ($key === null || $type === null) {
            throw new DomainError('הקובץ עדיין לא התקבל', 404);
        }

        $disk = Storage::disk(config('filesystems.storage_service'));
        $fileName = basename($key);

        return response()->stream(function () use ($disk, $key): void {
            $stream = $disk->readStream($key);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $type[0],
            // The name only goes in the header after it was checked above (letters, digits, "_", "-" and "."), so it cannot break out of it.
            'Content-Disposition' => ($type[1] ? 'inline' : 'attachment') . '; filename="' . $fileName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    //----------------------------- Private Methods ------------------------//

    /** @return class-string<File>|class-string<Image> */
    private function model(string $kind): string
    {
        return $kind === self::KIND_FILE ? File::class : Image::class;
    }

    private function create(string $kind, string $name): File|Image
    {
        // The key without the extension: the bytes may not be there yet, and the extension is not in the json.
        $path = $this->storage->buildBucketKey((string) config('filesystems.folder_name.attachments'), $name);

        return $kind === self::KIND_FILE
            ? File::query()->create(['name' => $name, 'display_name' => $name, 'path' => $path, 'file_name' => $name])
            : Image::query()->create(['name' => $name, 'path' => $path, 'file_name' => $name]);
    }

    /** The extension of a key when it is one of the types served; otherwise null. */
    private function extensionOf(string $key): ?string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return isset(self::TYPES[$extension]) ? $extension : null;
    }
}
