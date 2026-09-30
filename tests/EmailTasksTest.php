<?php

namespace LeKoala\EmailTemplates\Test;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\Group;
use SilverStripe\PolyExecution\PolyOutput;
use LeKoala\EmailTemplates\Models\SentEmail;
use LeKoala\EmailTemplates\Models\EmailTemplate;
use LeKoala\EmailTemplates\Tasks\EmailImportTask;
use LeKoala\EmailTemplates\Tasks\CompressEmailBodiesTask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test for the build tasks
 */
#[Group('EmailTemplates')]
class EmailTasksTest extends SapphireTest
{
    protected static $fixture_file = 'EmailTemplatesTest.yml';

    // OPTIMIZE TABLE causes an implicit commit, which would break transaction based isolation
    protected $usesTransactions = false;

    /**
     * Run a task the way sake does and return [exit code, output]
     */
    protected function runTask(BuildTask $task, array $options = []): array
    {
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, OutputInterface::VERBOSITY_NORMAL, false, $buffer);
        $exitCode = $task->run($input, $output);
        return [$exitCode, $buffer->fetch()];
    }

    public function testTasksKeepTheirLegacyNames()
    {
        // Required for sake dev/tasks/<name> and existing cron entries to keep working
        $this->assertEquals('tasks:EmailImportTask', EmailImportTask::getName());
        $this->assertEquals('tasks:CompressEmailBodiesTask', CompressEmailBodiesTask::getName());
    }

    public function testCompressTaskIsCliOnly()
    {
        $this->assertFalse(CompressEmailBodiesTask::config()->get('can_run_in_browser'));
    }

    public function testImportOnlyChosenTemplates()
    {
        [$exitCode, $out] = $this->runTask(EmailImportTask::create(), ['--templates' => 'ForgotPassword']);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Imported ForgotPassword', $out);
        $this->assertStringContainsString('Template with code ChangePassword was ignored', $out);
        $this->assertNotNull(EmailTemplate::get()->filter('Code', 'ForgotPassword')->first());
    }

    public function testImportDoesNotOverwriteWithoutOption()
    {
        [$exitCode, $out] = $this->runTask(EmailImportTask::create(), ['--templates' => 'ChangePassword']);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Template with code ChangePassword already exists', $out);
        $template = EmailTemplate::get()->filter('Code', 'ChangePassword')->first();
        $this->assertEquals('Hey, your password has been changed', $template->Subject);
    }

    public function testImportClearRemovesExistingTemplates()
    {
        [$exitCode] = $this->runTask(EmailImportTask::create(), ['--clear' => true, '--templates' => 'ForgotPassword']);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertNull(EmailTemplate::get()->filter('Code', 'Default')->first());
    }

    public function testCompressBodies()
    {
        // Store an uncompressed body, as written before compression was enabled
        SentEmail::config()->set('compress_body', false);
        $sent = SentEmail::create(['Subject' => 'Test', 'Body' => '<p>Hello there</p>']);
        $sent->write();

        SentEmail::config()->set('compress_body', true);
        $task = CompressEmailBodiesTask::create();
        $this->assertTrue($task->isEnabled());

        [$exitCode, $out] = $this->runTask($task);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Found 1 non-compressed emails', $out);
        $raw = DB::prepared_query('SELECT "Body" FROM "SentEmail" WHERE "ID" = ?', [$sent->ID])->value();
        $this->assertStringStartsWith(SentEmail::COMPRESSED_SIGNATURE, $raw);
        $this->assertEquals('<p>Hello there</p>', SentEmail::get()->byID($sent->ID)->getBody());
    }

    public function testCompressTaskDisabledWithoutConfig()
    {
        SentEmail::config()->set('compress_body', false);
        $this->assertFalse(CompressEmailBodiesTask::create()->isEnabled());
    }
}
