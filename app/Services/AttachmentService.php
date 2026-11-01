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
 * Files and images of bookings. The booking json only names them ("SrvOnClickFiles_<stored name>", no extension) and the bytes arrive
 * separately in one bucket folder, so register() links them when the json is read and resolve() finds the object when it is wanted.
 * Served only through the API, to users who may see the booking, and only the types below.
 */
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

    /**
     * Links a booking to the files and images its json names; names and links already saved are kept, so a re-read changes nothing.
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
     * The bucket key of the object when its bytes are there (kept on the row, with extension, for later calls);
     * null while they have not arrived or the bucket cannot be read.
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
     * The attachments of a booking as a screen shows them; loaded relations cost no extra query.
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
     * @throws DomainError 404 for every miss, including a booking the user may not see (one answer for all)
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
        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return isset(self::TYPES[$extension]) ? $extension : null;
    }
}
