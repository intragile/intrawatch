<?php

namespace Tests\Feature\Monitoring;

use App\Monitoring\Engine\PostCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\RecordingQueue;
use Tests\TestCase;

class PostCommitQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_failing_on_the_sync_queue_is_rethrown(): void
    {
        config(['queue.default' => 'sync']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evaluation failed');

        PostCommit::queue([static function (): void {
            throw new RuntimeException('evaluation failed');
        }]);
    }

    public function test_a_queue_that_refuses_the_push_is_logged_and_swallowed(): void
    {
        RecordingQueue::install('failing', failing: true);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message;
        });

        PostCommit::queue([static function (): void {}]);

        $this->assertContains('Queueing a job failed after its data was committed.', $logged);
    }

    public function test_only_a_database_queue_on_this_connection_joins_the_transaction(): void
    {
        config(['queue.default' => 'database']);
        $this->assertTrue(PostCommit::queueJoinsTransaction());

        config(['queue.connections.database.after_commit' => true]);
        $this->assertFalse(PostCommit::queueJoinsTransaction());

        config(['queue.connections.database.after_commit' => false, 'queue.connections.database.connection' => 'elsewhere']);
        $this->assertFalse(PostCommit::queueJoinsTransaction());

        config(['queue.default' => 'sync']);
        $this->assertFalse(PostCommit::queueJoinsTransaction());
    }
}
