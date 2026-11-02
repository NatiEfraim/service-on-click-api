<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The ADFS login only finds the person in the database - by personal number, else by the ID number Vatican holds
 * for that personal number - fills in the identifier the row lacks, and never creates anyone.
 * Vatican is a Guzzle mock here; the ADFS controller itself is checked against a mock ADFS outside PHPUnit,
 * because it replaces the app's AuthController only in the Docker image.
 */
class AdfsLoginLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: Request, response: ?Response}> what the app sent to the mock Vatican, in order */
    private array $vaticanRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.vatican.url' => 'http://vatican.test', 'services.vatican.token' => 'vatican-secret']);

        // Vatican failures are logged; keep them out of the real log file.
        Log::spy();
    }

    public function test_a_person_found_by_personal_number_with_an_id_number_is_not_looked_up_in_vatican(): void
    {
        $user = User::factory()->create(['personal_number' => '8000001', 'personal_id' => '123456782']);

        $this->vaticanAnswers([]);

        $this->assertTrue($user->is($this->findForAdfsLogin('8000001')));
        $this->assertCount(0, $this->vaticanRequests);
    }

    public function test_a_person_found_by_personal_number_without_an_id_number_gets_it_from_vatican(): void
    {
        $user = User::factory()->create(['personal_number' => '8000002', 'personal_id' => null]);

        $this->vaticanKnows('8000002', '123456782');

        $this->assertTrue($user->is($this->findForAdfsLogin('8000002')));
        $this->assertSame('123456782', $user->fresh()->personal_id);

        // The systems login first, then the person, asking only for the numbers and the name.
        $this->assertCount(2, $this->vaticanRequests);
        $login = $this->vaticanRequests[0]['request'];
        $lookup = $this->vaticanRequests[1]['request'];
        $this->assertSame('/api/systems/login', $login->getUri()->getPath());
        $this->assertSame('/api/users/8000002', $lookup->getUri()->getPath());
        $this->assertSame('Bearer vatican-token', $lookup->getHeaderLine('Authorization'));
        parse_str($lookup->getUri()->getQuery(), $query);
        $this->assertSame('personalNumber,personalId,firstName,surname', $query['columns']);
    }

    public function test_a_bare_row_found_by_the_id_number_gets_the_personal_number_and_name_without_a_duplicate(): void
    {
        // Created from an ID number alone: no personal number, no name, no phone.
        $bare = User::factory()->bookingOwner()->create(['personal_id' => '315552810', 'full_name' => null, 'phone' => '0532157802']);

        $this->vaticanKnows('9910738', '315552810', 'נתנאל', 'אפרים');

        $found = $this->findForAdfsLogin('9910738');

        $this->assertTrue($bare->is($found));
        $bare->refresh();
        $this->assertSame('9910738', $bare->personal_number);
        $this->assertSame('נתנאל אפרים', $bare->full_name);
        $this->assertSame('0532157802', $bare->phone, 'what the row already has stays');
        $this->assertSame(1, User::count());
    }

    public function test_a_row_with_both_numbers_but_no_name_gets_the_name_from_vatican(): void
    {
        $user = User::factory()->create(['personal_number' => '8000017', 'personal_id' => '123456782', 'full_name' => null]);

        $this->vaticanKnows('8000017', '123456782', 'רועי', 'סחייק');

        $this->assertTrue($user->is($this->findForAdfsLogin('8000017')));
        $this->assertSame('רועי סחייק', $user->fresh()->full_name);
    }

    public function test_a_name_the_row_already_has_is_never_overwritten(): void
    {
        $user = User::factory()->create(['personal_number' => '8000018', 'personal_id' => null, 'full_name' => 'שם שנערך ידנית']);

        $this->vaticanKnows('8000018', '123456782', 'רועי', 'סחייק');

        $this->findForAdfsLogin('8000018');

        $this->assertSame('שם שנערך ידנית', $user->fresh()->full_name);
        $this->assertSame('123456782', $user->fresh()->personal_id);
    }

    public function test_an_id_number_vatican_holds_without_its_leading_zero_is_padded_to_nine_digits(): void
    {
        $user = User::factory()->create(['personal_number' => '8000003', 'personal_id' => null]);

        $this->vaticanKnows('8000003', 57723439);

        $this->findForAdfsLogin('8000003');

        $this->assertSame('057723439', $user->fresh()->personal_id);
    }

    public function test_an_unknown_personal_number_is_found_by_the_id_number_vatican_holds_and_the_row_gets_the_personal_number(): void
    {
        // Someone who placed bookings in the mobile app: an ID number and a phone, no personal number.
        $owner = User::factory()->bookingOwner()->create(['personal_id' => '314159265']);

        $this->vaticanKnows('8000004', '314159265');

        $this->assertTrue($owner->is($this->findForAdfsLogin('8000004')));
        $this->assertSame('8000004', $owner->fresh()->personal_number);
        $this->assertSame(1, User::count(), 'nobody was created');
    }

    public function test_an_id_number_in_vatican_can_find_a_row_that_keeps_a_different_personal_number(): void
    {
        $user = User::factory()->create(['personal_number' => '8000005', 'personal_id' => '444444443']);

        $this->vaticanKnows('8000006', '444444443');

        $this->assertTrue($user->is($this->findForAdfsLogin('8000006')));
        $this->assertSame('8000005', $user->fresh()->personal_number, 'a personal number the row has is never overwritten');
    }

    public function test_nobody_is_created_for_a_person_the_database_does_not_know(): void
    {
        User::factory()->create(['personal_number' => '8000007', 'personal_id' => '111111118']);
        $before = User::count();

        // Vatican knows the person and their ID number; the database has neither.
        $this->vaticanKnows('8000008', '222222226');

        $this->assertNull($this->findForAdfsLogin('8000008'));
        $this->assertSame($before, User::count());
    }

    public function test_vatican_not_knowing_the_person_does_not_stop_a_known_personal_number(): void
    {
        $user = User::factory()->create(['personal_number' => '8000009', 'personal_id' => null]);

        $this->vaticanAnswers([
            new Response(200, [], json_encode(['access_token' => 'vatican-token'])),
            new Response(404, [], json_encode(['message' => 'not found'])),
        ]);

        $this->assertTrue($user->is($this->findForAdfsLogin('8000009')));
        $this->assertNull($user->fresh()->personal_id);
    }

    public function test_an_unreachable_vatican_keeps_a_known_personal_number_in_and_an_unknown_one_out(): void
    {
        $user = User::factory()->create(['personal_number' => '8000010', 'personal_id' => null]);
        $down = fn() => new ConnectException('Vatican is down', new Request('POST', 'http://vatican.test/api/systems/login'));

        $this->vaticanAnswers([$down()]);
        $this->assertTrue($user->is($this->findForAdfsLogin('8000010')));

        $this->vaticanAnswers([$down()]);
        $this->assertNull($this->findForAdfsLogin('8000011'));
    }

    public function test_an_identifier_another_row_already_holds_is_not_filled_in(): void
    {
        // The same person twice: a row with the personal number, and the mobile app's row with the ID number.
        $user = User::factory()->create(['personal_number' => '8000012', 'personal_id' => null]);
        User::factory()->bookingOwner()->create(['personal_id' => '333333331']);

        $this->vaticanKnows('8000012', '333333331');

        $this->assertTrue($user->is($this->findForAdfsLogin('8000012')), 'the login still works');
        $this->assertNull($user->fresh()->personal_id, 'the unique ID number stays with the other row');
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('idNumbersThatCannotBeOne')]
    public function test_an_id_number_that_cannot_be_one_matches_nobody(mixed $fromVatican): void
    {
        // A placeholder in Vatican must never log a stranger in as whoever holds it.
        User::factory()->bookingOwner()->create(['personal_id' => '000000000']);
        $known = User::factory()->create(['personal_number' => '8000013', 'personal_id' => null]);

        $this->vaticanKnows('8000014', $fromVatican);
        $this->assertNull($this->findForAdfsLogin('8000014'));

        $this->vaticanKnows('8000013', $fromVatican);
        $this->assertTrue($known->is($this->findForAdfsLogin('8000013')));
        $this->assertNull($known->fresh()->personal_id, 'and it is not written into a row');
    }

    /** @return array<string, array{mixed}> */
    public static function idNumbersThatCannotBeOne(): array
    {
        return [
            'zero' => ['0'],
            'only zeros' => ['000000000'],
            'empty' => [''],
            'too long' => ['1234567890'],
            'letters' => ['12a456789'],
            'missing' => [null],
            'a list' => [[1]],
        ];
    }

    public function test_the_number_in_the_adfs_user_name_finds_the_person_too(): void
    {
        $user = User::factory()->create(['personal_number' => '9810738', 'personal_id' => '666666664']);

        $this->vaticanAnswers([]);

        $this->assertTrue($user->is($this->findForAdfsLogin('99810738', '9810738')));
        $this->assertCount(0, $this->vaticanRequests);
    }

    public function test_a_deleted_user_is_still_found_so_the_login_can_refuse_them(): void
    {
        $byNumber = User::factory()->deleted()->create(['personal_number' => '8000015', 'personal_id' => '555555559']);
        $this->vaticanAnswers([]);

        $found = $this->findForAdfsLogin('8000015');
        $this->assertTrue($byNumber->is($found));
        $this->assertTrue($found->is_deleted);

        $byIdNumber = User::factory()->deleted()->bookingOwner()->create(['personal_id' => '777777775']);
        $this->vaticanKnows('8000016', '777777775');

        $found = $this->findForAdfsLogin('8000016');
        $this->assertTrue($byIdNumber->is($found));
        $this->assertTrue($found->is_deleted);
    }

    /** What the mock Vatican answers, in order; every request the app sends is kept in $vaticanRequests. @param array<int, Response|\Throwable> $answers */
    private function vaticanAnswers(array $answers): void
    {
        $this->vaticanRequests = [];

        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->vaticanRequests));

        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    /** Vatican knows the person: it logs the app in, then returns the record with this ID number. */
    private function vaticanKnows(string $personalNumber, mixed $personalId, ?string $firstName = null, ?string $surname = null): void
    {
        $this->vaticanAnswers([
            new Response(200, [], json_encode(['access_token' => 'vatican-token'])),
            new Response(200, [], json_encode([[
                'personal_number' => $personalNumber,
                'personal_id' => $personalId,
                'first_name' => $firstName,
                'surname' => $surname,
            ]])),
        ]);
    }

    private function findForAdfsLogin(string $personalNumber, ?string $usernameNumber = null): ?User
    {
        return app(UserService::class)->findForAdfsLogin($personalNumber, $usernameNumber);
    }
}
