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

/** Attachments of bookings: register() saves the name from the JSON, ImportFileService links the bytes once received, resolve() finds the object key. API-only, served by the types listed below. */
class AttachmentService
{
    public const KIND_FILE = 'file';
    public const KIND_IMAGE = 'image';

    /** @var array<string, array{0: string, 1: bool}> extension => [served type, whether a browser may show it inline (else downloaded)] */
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

    /** Extensions to look for by kind, most likely first. */
    private const EXTENSIONS = [
        self::KIND_IMAGE => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        self::KIND_FILE => ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'],
    ];

    public function __construct(private readonly StorageService $storage)
    {
    }

    /** Whether this can be a stored attachment name (letters, digits, "_", "-" and "." inside; never a path). */
    public static function isValidName(mixed $name): bool
    {
        return is_string($name)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,149}$/', $name) === 1
            && !str_contains($name, '..');
    }

    /** The extension of a key or file name when it is a type this serves (lower case), else null. */
    public static function servedExtension(string $key): ?string
    {
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return isset(self::TYPES[$extension]) ? $extension : null;
    }

    /** The extensions the bytes of an attachment may have, most likely first. @return array<int, string> */
    public static function extensionsFor(File|Image $attachment): array
    {
        return self::EXTENSIONS[$attachment instanceof File ? self::KIND_FILE : self::KIND_IMAGE];
    }

    /** Whether the row already knows where its bytes are (its path ends in a served extension). */
    public static function isLinked(File|Image $attachment): bool
    {
        return self::servedExtension((string) $attachment->path) !== null;
    }

    /** Keeps the key the bytes were found at, with its extension, on the row so later calls need not look again. */
    public function link(File|Image $attachment, string $key): void
    {
        $attachment->forceFill(['path' => $key, 'file_name' => basename($key)])->save();
    }

    /** Links a booking to the named files and images; already-saved names are kept so a re-read is idempotent. @param array<int, string> $files stored names (no extension) @param array<int, string> $images @return int links added */
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

    /** The bucket key of the object when its bytes are in place (cached on the row); null while bytes have not arrived or the bucket is unavailable. */
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

            // Search 'files' first (the live location after import), then 'loaded-files' as fallback.
            foreach ([
                (string) config('filesystems.folder_name.attachments'),
                (string) config('filesystems.folder_name.loaded_files'),
            ] as $folder) {
                $base = $this->storage->buildBucketKey($folder, $name);

                foreach (self::extensionsFor($attachment) as $extension) {
                    if ($disk->exists("{$base}.{$extension}")) {
                        $this->link($attachment, "{$base}.{$extension}");

                        return "{$base}.{$extension}";
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('AttachmentService: could not look for ' . $attachment->name . ': ' . $e->getMessage());

            return null;
        }
    }

    /** All attachments of a booking for the screen; preload relations to avoid extra queries. @return array<int, array{kind: string, id: int, name: string, mime: ?string, inline: bool, available: bool, url: ?string}> */
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
                    'url' => $available ? $this->signedUrl($key) : null,
                ];
            }
        }

        return $rows;
    }

    /** Streams one attachment the user may see; 404 for every miss including unauthorized bookings (one error for all). @throws DomainError */
    public function open(AccessScope $scope, int $serial, string $kind, int $id): StreamedResponse
    {
        $booking = Booking::query()->where('serial_number', $serial)->first(['id', 'serial_number', 'unit_id', 'booking_type_id', 'status_id', 'parked_status_id']);

        if ($booking === null || !$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
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
            // The name was validated above, so it cannot break out of the header.
            'Content-Disposition' => ($type[1] ? 'inline' : 'attachment') . '; filename="' . $fileName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** @return class-string<File>|class-string<Image> */
    private function model(string $kind): string
    {
        return $kind === self::KIND_FILE ? File::class : Image::class;
    }

    private function create(string $kind, string $name): File|Image
    {
        // Key without extension: the bytes may not be there yet and the json carries no extension.
        $path = $this->storage->buildBucketKey((string) config('filesystems.folder_name.attachments'), $name);

        return $kind === self::KIND_FILE
            ? File::query()->create(['name' => $name, 'display_name' => $name, 'path' => $path, 'file_name' => $name])
            : Image::query()->create(['name' => $name, 'path' => $path, 'file_name' => $name]);
    }

    /** The extension of a key when it is a served type, else null. */
    private function extensionOf(string $key): ?string
    {
        return self::servedExtension($key);
    }

    /** Pre-signed MinIO/S3 URL valid for TEMP_URL_LIFETIME minutes; falls back to the stream endpoint when the disk cannot sign. */
    private function signedUrl(string $key): string
    {
        try {
            return $this->storage->generateImageTemporaryUrl($key);
        } catch (\Throwable) {
            return '';
        }
    }
}
