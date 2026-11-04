<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\Unit;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Bookings report: paginated list (list()) and Excel export (export()) scoped to what the caller may see; a copy is kept in the bucket's export-report folder. */
class BookingReportService
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MAX_PER_PAGE = 100;

    /** Bookings read from the database at a time while the file is written. */
    private const CHUNK = 500;

    private const SHEET_NAME = 'קריאות';

    private const DATE_FORMAT = 'yyyy-mm-dd hh:mm';

    /** @var array<int, string> The columns the list needs (the sheet needs the same). */
    private const SELECT = ['id', 'uuid', 'serial_number', 'unit_id', 'booking_type_id', 'user_id', 'status_id', 'status_changed_at', 'created_at', 'updated_at'];

    private const RELATIONS = ['user:id,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name'];

    /** @var array<string, array{0: string, 1: float}> the columns of the sheet in order: value of record() => [heading, width] */
    private const COLUMNS = [
        'serial_number' => ['מספר קריאה', 16],
        'booking_type_label' => ['תחום', 24],
        'status_label' => ['סטטוס', 28],
        'owner_name' => ['שם המבקש', 26],
        'owner_personal_id' => ['תעודת זהות', 16],
        'owner_personal_number' => ['מספר אישי', 14],
        'unit_name' => ['יחידה', 12],
        'created_at' => ['נוצרה', 18],
        'status_changed_at' => ['סטטוס עודכן', 18],
    ];

    public function __construct(
        private readonly StorageService $storage,
        private readonly AuditService $audit,
    ) {
    }

    /** One page of bookings, newest first; filters: booking_type_id, status_id, from/to_created_at (Y-m-d, report timezone), page, per_page (max 100). @param array<string, mixed> $filters @return array{data: array<int, array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}} */
    public function list(AccessScope $scope, array $filters): array
    {
        $this->assertMayReport($scope);

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), self::MAX_PER_PAGE));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $units = $this->unitNames();

        $paginator = $this->query($scope, $filters)
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, self::SELECT, 'page', $page);

        return [
            'data' => array_map(
                fn (Booking $booking): array => array_map(
                    fn (mixed $value): mixed => $value instanceof CarbonInterface ? $value->toIso8601String() : $value,
                    $this->record($booking, $units)
                ),
                $paginator->items()
            ),
            'meta' => ['total' => $paginator->total(), 'page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $perPage],
        ];
    }

    /** Writes all filtered bookings to a temp Excel file and keeps a copy in the bucket; the caller sends and deletes it. @param array<string, mixed> $filters @return array{path: string, file_name: string, key: string, rows: int} @throws DomainError 403 no permission, 503 bucket write failed */
    public function export(AccessScope $scope, array $filters): array
    {
        $this->assertMayReport($scope, 'report.export');

        $timezone = $this->timezone();
        $path = tempnam(sys_get_temp_dir(), 'bookings_');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the bookings report');
        }

        try {
            $rows = $this->writeWorkbook($scope, $filters, $path, $timezone);
            $key = $this->keepCopy($scope, $path, self::fileName(now($timezone)), $filters);
            $fileName = basename($key);

            $this->audit->record([
                'action' => 'report.export',
                'entity_type' => 'report',
                'entity_id' => $fileName,
                'booking_type_id' => $filters['booking_type_id'] ?? null,
                'after' => ['file' => $fileName, 'key' => $key, 'rows' => $rows, 'filters' => $this->reportFilters($filters)],
            ], $scope->user);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return ['path' => $path, 'file_name' => $fileName, 'key' => $key, 'rows' => $rows];
    }

    /** bookings_2026-11-03_14-35-20.xlsx */
    public static function fileName(\DateTimeInterface $at): string
    {
        return 'bookings_' . $at->format('Y-m-d_H-i-s') . '.xlsx';
    }

    /** The bookings the user may report on, with the filters applied (not ordered). @param array<string, mixed> $filters */
    private function query(AccessScope $scope, array $filters): Builder
    {
        $query = $scope->applyToBookings(Booking::query());

        // The admin reports on the booking types they administer; a type they only handle stays out (as in ReportService).
        if (!$scope->isSuperAdmin()) {
            $query->whereIn('booking_type_id', $scope->dashboardBookingTypeIds() ?: [-1]);
        }

        if (filled($filters['booking_type_id'] ?? null)) {
            $query->where('booking_type_id', (int) $filters['booking_type_id']);
        }

        if (filled($filters['status_id'] ?? null)) {
            $query->where('status_id', (int) $filters['status_id']);
        }

        // Whole days of the report timezone, as the UTC times the database holds.
        if (filled($filters['from_created_at'] ?? null)) {
            $query->where('created_at', '>=', $this->dayStart((string) $filters['from_created_at']));
        }

        if (filled($filters['to_created_at'] ?? null)) {
            $query->where('created_at', '<', $this->dayStart((string) $filters['to_created_at'], 1));
        }

        return $query;
    }

    /** Super admin, or whoever administers a booking type. */
    private function assertMayReport(AccessScope $scope, ?string $auditAction = null): void
    {
        if ($scope->can('reports.view')) {
            return;
        }

        if ($auditAction !== null) {
            $this->audit->denied($auditAction, 'report', null, 'no_permission', $scope->user);
        }

        throw new DomainError('דוח הקריאות זמין למנהל מערכת ולמפקד מחנה', 403);
    }

    /** Writes the sheet to $path streaming: ids read once (import cannot shift pages), bookings in chunks of 500. @param array<string, mixed> $filters @return int rows written */
    private function writeWorkbook(AccessScope $scope, array $filters, string $path, \DateTimeZone $timezone): int
    {
        $options = new Options();

        foreach (array_values(self::COLUMNS) as $index => [, $width]) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(self::SHEET_NAME);
            // Hebrew reads right to left; the headings stay in view while scrolling.
            $sheet->setSheetView(new SheetView(rightToLeft: true, freezeRow: 2));

            $writer->addRow($this->headingRow());

            $units = $this->unitNames();
            // The same font as the cells without a style, or the dates would be set in another one.
            $dateStyle = new Style(fontSize: Options::DEFAULT_FONT_SIZE, fontName: Options::DEFAULT_FONT_NAME, format: self::DATE_FORMAT);
            $rows = 0;
            $ids = $this->query($scope, $filters)->orderByDesc('created_at')->orderByDesc('id')->pluck('id');

            foreach ($ids->chunk(self::CHUNK) as $chunk) {
                $chunkIds = $chunk->values()->all();
                $bookings = Booking::query()->select(self::SELECT)->with(self::RELATIONS)->whereIn('id', $chunkIds)->get()->keyBy('id');

                foreach ($chunkIds as $id) {
                    // A booking deleted since the ids were read is simply not there.
                    if (($booking = $bookings->get($id)) === null) {
                        continue;
                    }

                    $writer->addRow($this->sheetRow($this->record($booking, $units), $timezone, $dateStyle));
                    $rows++;
                }
            }

            $sheet->setAutoFilter(new AutoFilter(0, 1, count(self::COLUMNS) - 1, $rows + 1));
        } finally {
            $writer->close();
        }

        return $rows;
    }

    private function headingRow(): Row
    {
        $style = new Style(
            fontBold: true,
            fontSize: Options::DEFAULT_FONT_SIZE,
            fontColor: Color::WHITE,
            fontName: Options::DEFAULT_FONT_NAME,
            cellAlignment: CellAlignment::CENTER,
            shouldWrapText: true,
            backgroundColor: '1F4E78'
        );

        return new Row(
            array_map(fn (array $column): Cell => new Cell\StringCell($column[0], $style), array_values(self::COLUMNS)),
            height: 22
        );
    }

    /** Types cells explicitly (not Cell::fromValue()) to prevent formula injection from user-supplied names and to preserve leading zeros. @param array<string, mixed> $record */
    private function sheetRow(array $record, \DateTimeZone $timezone, Style $dateStyle): Row
    {
        $cells = [];

        foreach (array_keys(self::COLUMNS) as $key) {
            $value = $record[$key];

            $cells[] = match (true) {
                $value instanceof CarbonInterface => new Cell\DateTimeCell($value->toImmutable()->setTimezone($timezone), $dateStyle),
                is_int($value) => new Cell\NumericCell($value),
                $value === null || $value === '' => new Cell\EmptyCell(null),
                default => new Cell\StringCell((string) $value),
            };
        }

        return new Row($cells);
    }

    /** Puts the file in the bucket under its name (or a free variant if two exports land the same second). @param array<string, mixed> $filters @return string the object key written */
    private function keepCopy(AccessScope $scope, string $path, string $fileName, array $filters): string
    {
        try {
            return $this->storage->putLocalFileToBucket($path, $fileName, (string) config('filesystems.folder_name.export_report'));
        } catch (\Throwable $e) {
            // A signed URL in the message would leak its signature.
            $error = mb_substr((string) preg_replace('/\?\S+/', '?<redacted>', $e->getMessage()), 0, 500);

            Log::error("BookingReportService: could not keep {$fileName} in the bucket: {$error}");
            $this->audit->denied('report.export', 'report', $fileName, $error, $scope->user, [
                'result' => 'failed',
                'booking_type_id' => $filters['booking_type_id'] ?? null,
                'after' => ['file' => $fileName, 'filters' => $this->reportFilters($filters)],
            ]);

            throw new DomainError('שמירת הדוח בגיבוי באחסון הקבצים נכשלה, ולכן הקובץ לא נשלח. נסו שוב בעוד כמה דקות.', 503);
        }
    }

    /** The fields of one booking row; dates are Carbon (UTC) so list() formats them as ISO 8601 and the sheet converts to local time. @param array<int, string> $units @return array<string, mixed> */
    private function record(Booking $booking, array $units): array
    {
        return [
            'uuid' => $booking->uuid,
            'serial_number' => $booking->serial_number === null ? null : (int) $booking->serial_number,
            'booking_type_id' => (int) $booking->booking_type_id,
            'booking_type_label' => $booking->bookingType?->display_name,
            'status_id' => (int) $booking->status_id,
            'status_label' => $booking->status?->display_name,
            'unit_id' => $booking->unit_id === null ? null : (int) $booking->unit_id,
            'unit_name' => $units[$booking->unit_id] ?? null,
            'owner_name' => $booking->user?->full_name,
            'owner_personal_id' => $booking->user?->personal_id,
            'owner_personal_number' => $booking->user?->personal_number,
            'created_at' => $booking->openedAt(),
            'status_changed_at' => $booking->statusSince(),
        ];
    }

    /** @return array<int, string> unit id => the name people see */
    private function unitNames(): array
    {
        return Unit::query()->get()->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->displayName()])->all();
    }

    /** What the audit log keeps of the filters. @param array<string, mixed> $filters @return array<string, mixed> */
    private function reportFilters(array $filters): array
    {
        return array_filter(
            array_intersect_key($filters, array_flip(['booking_type_id', 'status_id', 'from_created_at', 'to_created_at'])),
            fn (mixed $value): bool => filled($value)
        );
    }

    /** The start of a day of the report timezone (plus whole days), as the UTC time the database holds. */
    private function dayStart(string $day, int $plusDays = 0): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone())
            ->addDays($plusDays)
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) config('app.export_report.timezone'));
    }
}
