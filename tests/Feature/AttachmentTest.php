<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\File;
use App\Models\Image;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/** The files and images of a booking: linked when the json names them, found in the bucket when they are wanted, served only to who may see the booking. */
class AttachmentTest extends ImportJsonTestCase
{
    private const API = '/api/bookings-approver-management';

    private const PDF = 'SrvOnClickFiles_6adf010810404_j0iNc8FqHf';
    private const DOC = 'SrvOnClickFiles_77aa3b_form';
    private const PHOTO = 'SrvOnClickFiles_9c01de_photo';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    private function import(): array
    {
        return app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    /** A booking with two files (a pdf and a document) and one image, as the mobile export names them: no extension. */
    private function putBookingWithAttachments(int $serial = 2001): void
    {
        $this->putBookingFile([
            $this->foodBooking($serial, [
                'files' => [['file_id' => 7, 'path' => self::PDF], ['file_id' => 8, 'path' => self::DOC]],
                'images' => [['image_id' => 3, 'path' => self::PHOTO]],
            ]),
        ]);
    }

    private function deliver(string $name, string $extension, string $bytes = 'bytes'): void
    {
        Storage::disk('minio')->put("import-files/{$name}.{$extension}", $bytes);
    }

    public function test_the_import_links_the_files_and_images_a_booking_names_and_reading_it_again_adds_nothing(): void
    {
        $this->putBookingWithAttachments();

        $first = $this->import();

        $this->assertSame([], $first['errors']);
        $this->assertSame(3, $first['files'][0]['stats']['attachments_added']);
        $this->assertSame(2, File::count());
        $this->assertSame(1, Image::count());

        $booking = Booking::where('serial_number', 2001)->firstOrFail();
        $this->assertEqualsCanonicalizing([self::PDF, self::DOC], $booking->files->pluck('name')->all());
        $this->assertSame([self::PHOTO], $booking->images->pluck('name')->all());

        // The bytes are not there yet: the row keeps the key without an extension.
        $this->assertSame('import-files/' . self::PDF, File::where('name', self::PDF)->value('path'));

        // The same file read again (the mobile side sends full records again after a restart): nothing is added, nothing changes.
        $this->putBookingWithAttachments();
        $second = $this->import();

        $this->assertSame(0, $second['files'][0]['stats']['attachments_added']);
        $this->assertSame(1, $second['files'][0]['stats']['unchanged']);
        $this->assertSame(2, File::count());
        $this->assertSame(1, Image::count());
    }

    public function test_a_later_record_that_names_one_more_file_links_only_that_one(): void
    {
        $this->putBookingWithAttachments();
        $this->import();

        $this->putBookingFile([$this->foodBooking(2001, ['files' => [['file_id' => 7, 'path' => self::PDF], ['file_id' => 9, 'path' => 'SrvOnClickFiles_new_one']]])]);
        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['attachments_added']);
        $this->assertSame(1, $result['files'][0]['stats']['updated']);
        $this->assertSame(3, Booking::where('serial_number', 2001)->firstOrFail()->files()->count());
    }

    public function test_a_name_that_is_not_a_plain_name_is_never_saved(): void
    {
        $this->putBookingFile([
            $this->foodBooking(2002, ['files' => [
                ['file_id' => 1, 'path' => '../../etc/passwd'],
                ['file_id' => 2, 'path' => 'a/b'],
                ['file_id' => 3, 'path' => ''],
                ['file_id' => 4, 'path' => 'SrvOnClickFiles_ok_1'],
                ['file_id' => 5],
                'not an object',
            ]]),
        ]);

        $result = $this->import();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['files'][0]['stats']['created']);
        $this->assertSame(['SrvOnClickFiles_ok_1'], File::pluck('name')->all());
    }

    public function test_the_detail_says_which_attachments_have_arrived_and_the_list_counts_them(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::PDF, 'pdf');

        $detail = $this->as(self::SUPER_ADMIN)->getJson(self::API . '/bookings/2001')->assertOk();

        $attachments = collect($detail->json('data.attachments'))->keyBy('name');
        $this->assertCount(3, $attachments);

        $pdf = $attachments[self::PDF];
        $this->assertTrue($pdf['available']);
        $this->assertSame('application/pdf', $pdf['mime']);
        $this->assertTrue($pdf['inline']);
        $this->assertSame(self::API . '/bookings/2001/attachments/file/' . $pdf['id'], $pdf['url']);

        $this->assertFalse($attachments[self::DOC]['available']);
        $this->assertNull($attachments[self::DOC]['url']);
        $this->assertFalse($attachments[self::PHOTO]['available']);

        // The key that was found is kept (with its extension); the others still wait.
        $this->assertSame('import-files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('import-files/' . self::DOC, File::where('name', self::DOC)->value('path'));

        $row = collect($this->as(self::SUPER_ADMIN)->getJson(self::API . '/bookings')->assertOk()->json('data'))->firstWhere('serial_number', 2001);
        $this->assertSame(3, $row['attachments_count']);
    }

    public function test_a_file_is_found_once_its_bytes_arrive_even_when_they_came_after_the_json(): void
    {
        $this->putBookingFile([$this->bookingRecord(2003, 13, [], ['files' => [['file_id' => 1, 'path' => self::PDF]]])]);
        $this->import();

        $service = app(AttachmentService::class);
        $file = File::where('name', self::PDF)->firstOrFail();

        $this->assertNull($service->resolve($file), 'the json came first - nothing to find yet');

        $this->deliver(self::PDF, 'pdf');

        $this->assertSame('import-files/' . self::PDF . '.pdf', $service->resolve($file->fresh()));
        $this->assertSame(self::PDF . '.pdf', $file->fresh()->file_name);
    }

    public function test_a_file_is_served_inline_with_its_own_type_and_cannot_be_sniffed(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::PDF, 'pdf', '%PDF-1.4 the form');
        $this->deliver(self::PHOTO, 'png', 'PNGDATA');

        $pdfId = File::where('name', self::PDF)->value('id');
        $photoId = Image::where('name', self::PHOTO)->value('id');

        $pdf = $this->as(self::SUPER_ADMIN)->get(self::API . "/bookings/2001/attachments/file/{$pdfId}")->assertOk();
        $this->assertSame('%PDF-1.4 the form', $pdf->streamedContent());
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $pdf->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $pdf->headers->get('X-Content-Type-Options'));

        $photo = $this->as(self::SUPER_ADMIN)->get(self::API . "/bookings/2001/attachments/image/{$photoId}")->assertOk();
        $this->assertSame('PNGDATA', $photo->streamedContent());
        $this->assertSame('image/png', $photo->headers->get('Content-Type'));
    }

    public function test_a_document_is_downloaded_not_shown(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::DOC, 'docx', 'DOCX');

        $id = File::where('name', self::DOC)->value('id');

        $response = $this->as(self::SUPER_ADMIN)->get(self::API . "/bookings/2001/attachments/file/{$id}")->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('wordprocessingml', $response->headers->get('Content-Type'));
    }

    public function test_a_type_that_is_not_listed_is_never_served(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::PDF, 'html', '<script>alert(1)</script>');
        $this->deliver(self::DOC, 'exe', 'MZ');

        $ids = File::pluck('id', 'name');

        foreach ([self::PDF, self::DOC] as $name) {
            $this->as(self::SUPER_ADMIN)->get(self::API . "/bookings/2001/attachments/file/{$ids[$name]}")->assertNotFound();
        }
    }

    public function test_the_refusals_are_one_vague_answer(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::PDF, 'pdf');

        $pdfId = File::where('name', self::PDF)->value('id');
        $docId = File::where('name', self::DOC)->value('id');
        $url = fn (int $id, string $kind = 'file', int $serial = 2001): string => self::API . "/bookings/{$serial}/attachments/{$kind}/{$id}";

        // The bytes have not arrived / no such attachment / the id of a file asked as an image / no such booking.
        $this->as(self::SUPER_ADMIN)->get($url($docId))->assertNotFound();
        $this->as(self::SUPER_ADMIN)->get($url(99999))->assertNotFound();
        $this->as(self::SUPER_ADMIN)->get($url($pdfId, 'image'))->assertNotFound();
        $this->as(self::SUPER_ADMIN)->get($url($pdfId, 'file', 424242))->assertNotFound();

        // Not a kind this system knows.
        $this->as(self::SUPER_ADMIN)->get($url($pdfId, 'video'))->assertNotFound();

        // Nobody signed in / a person with only the user role / a person with no role.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson($url($pdfId))->assertUnauthorized();
        $this->as(self::PLAIN_USER)->get($url($pdfId))->assertForbidden();
        $this->as(self::NO_ROLE)->get($url($pdfId))->assertForbidden();
    }

    public function test_a_manager_who_may_not_see_the_booking_cannot_open_its_attachments(): void
    {
        $this->putBookingWithAttachments();
        $this->import();
        $this->deliver(self::PDF, 'pdf');

        $pdfId = File::where('name', self::PDF)->value('id');
        $url = self::API . "/bookings/2001/attachments/file/{$pdfId}";

        // The approver may reach the route, but without the permission of the booking's type the booking is not theirs to see.
        $this->as(self::APPROVER)->get($url)->assertOk();

        $approver = User::where('personal_number', self::APPROVER)->firstOrFail();
        $approver->revokePermissionTo('approver_11');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as(self::APPROVER)->get($url)->assertNotFound();
    }
}
