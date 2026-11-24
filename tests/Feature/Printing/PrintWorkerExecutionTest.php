<?php

namespace Tests\Feature\Printing;

use App\Console\Commands\PrintWorkerCommand;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Printing\KitchenTicketRendererService;
use App\Services\Printing\ReceiptRendererService;
use App\Services\ShiftService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class PrintWorkerExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected PrintWorkerCommand $workerCommand;

    protected ReflectionClass $reflector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Buka shift'
        );

        $this->workerCommand = new PrintWorkerCommand(
            app(ReceiptRendererService::class),
            app(KitchenTicketRendererService::class)
        );
        $this->workerCommand->setOutput(
            new OutputStyle(
                new ArrayInput([]),
                new NullOutput
            )
        );

        $this->reflector = new ReflectionClass(PrintWorkerCommand::class);
    }

    protected function createOrder(): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        return app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );
    }

    protected function createJob(array $attributes = []): PrintJob
    {
        $order = $this->createOrder();

        return PrintJob::create(array_merge([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'paper_width' => 80,
            'is_reprint' => false,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ], $attributes));
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $m = $this->reflector->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->workerCommand, $args);
    }

    public function test_claim_next_job_acquires_lock_and_assigns_claim_token(): void
    {
        $job = $this->createJob();

        $claimed = $this->invokeMethod('claimNextJob');

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
        $this->assertSame('processing', $claimed->status);
        $this->assertSame(1, $claimed->attempts);
        $this->assertNotNull($claimed->claim_token);
        $this->assertNotNull($claimed->locked_at);

        // Subsequent claim returns null because queue is empty
        $next = $this->invokeMethod('claimNextJob');
        $this->assertNull($next);
    }

    public function test_apply_exit_code_zero_marks_job_completed(): void
    {
        $job = $this->createJob();
        $claimed = $this->invokeMethod('claimNextJob');

        $this->invokeMethod('applyExitCode', [$claimed, 0]);

        $fresh = $job->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->locked_at);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_apply_exit_code_one_to_four_marks_job_failed_safely(): void
    {
        foreach ([1, 2, 3, 4] as $exitCode) {
            $job = $this->createJob();
            $claimed = $this->invokeMethod('claimNextJob');

            $this->invokeMethod('applyExitCode', [$claimed, $exitCode]);

            $fresh = $job->fresh();
            $this->assertSame('failed', $fresh->status, "Exit code {$exitCode} should mark job failed.");
            $this->assertNull($fresh->claim_token);
            $this->assertNull($fresh->locked_at);
            $this->assertNull($fresh->completed_at);
            $this->assertStringContainsString((string) $exitCode, $fresh->last_error);
        }
    }

    public function test_apply_exit_code_five_to_seven_and_timeout_marks_job_uncertain(): void
    {
        foreach ([5, 6, 7, 99] as $exitCode) {
            $job = $this->createJob();
            $claimed = $this->invokeMethod('claimNextJob');

            $this->invokeMethod('applyExitCode', [$claimed, $exitCode]);

            $fresh = $job->fresh();
            $this->assertSame('uncertain', $fresh->status, "Exit code {$exitCode} should mark job uncertain.");
            $this->assertNull($fresh->claim_token);
            $this->assertNull($fresh->locked_at);
            $this->assertNull($fresh->completed_at);
            $this->assertNotNull($fresh->last_error);
        }
    }

    public function test_stale_claim_token_cannot_update_job_after_ownership_lost(): void
    {
        $job = $this->createJob();
        $claimed = $this->invokeMethod('claimNextJob');
        $oldToken = $claimed->claim_token;

        // Simulate ownership lost (e.g. recovery stole the job and reset the claim token)
        DB::table('print_jobs')
            ->where('id', $job->id)
            ->update([
                'claim_token' => (string) Str::uuid(), // new different token
                'status' => 'uncertain',
            ]);

        // Stale worker tries to complete using old token
        $this->invokeMethod('fencedUpdate', [$claimed, 'completed', null, now()]);

        // Job must NOT be marked completed because token did not match
        $fresh = $job->fresh();
        $this->assertSame('uncertain', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }
}
