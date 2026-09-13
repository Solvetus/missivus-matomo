<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Mail;
use Piwik\Plugins\Missivus\Mail\GraphTransport;
use Piwik\Plugins\Missivus\tests\Framework\ArrayTokenCache;
use Piwik\Plugins\Missivus\tests\Framework\FakeConfiguration;
use Piwik\Plugins\Missivus\tests\Framework\FakeHttpClient;
use Piwik\Plugins\Missivus\tests\Framework\RecordingLogger;
use Solvetus\Missivus\Exception\GraphException;

require_once __DIR__ . '/../Framework/PiwikStubs.php';
require_once __DIR__ . '/../Framework/Doubles.php';
require_once __DIR__ . '/../../Mail/GraphTransport.php';

/**
 * A GraphTransport whose fallback is observable — the parent's PHPMailer path is replaced with a
 * flag, so "did it fall back?" is answerable without an SMTP server.
 */
class ObservableGraphTransport extends GraphTransport
{
    /** @var int */
    public $defaultTransportCalls = 0;

    protected function sendWithDefaultTransport(Mail $mail)
    {
        $this->defaultTransportCalls++;

        return true;
    }
}

/**
 * The Matomo-facing half: mapping Piwik\Mail onto the portable Message, the forced From, and the
 * fallback switch.
 *
 * @group Missivus
 */
class GraphTransportTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    /** @var FakeConfiguration */
    private $config;

    /** @var RecordingLogger */
    private $logger;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->config = new FakeConfiguration();
        $this->logger = new RecordingLogger();
    }

    public function testAMatomoMailIsMappedOntoTheGraphMessage()
    {
        $this->queueSuccessfulSend();

        $mail = new Mail();
        $mail->setFrom('noreply@example.com', 'Matomo Analytics');
        $mail->addTo('someone@example.org', 'Someone');
        $mail->addBcc('audit@example.org');
        $mail->addReplyTo('replies@example.org');
        $mail->setSubject('Your weekly report');
        $mail->setBodyHtml('<p>Report</p>');
        $mail->setBodyText('Report');
        $mail->addAttachment('PDF-BYTES', 'application/pdf', 'report.pdf');

        $transport = $this->transport();
        $this->assertTrue($transport->send($mail));

        $message = $this->http->requestJson(1)['message'];
        $this->assertSame('Your weekly report', $message['subject']);
        $this->assertSame('HTML', $message['body']['contentType']);
        $this->assertSame('someone@example.org', $message['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('Someone', $message['toRecipients'][0]['emailAddress']['name']);
        $this->assertSame('audit@example.org', $message['bccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('replies@example.org', $message['replyTo'][0]['emailAddress']['address']);
        $this->assertSame('report.pdf', $message['attachments'][0]['name']);
        $this->assertSame('Matomo Analytics', $message['from']['emailAddress']['name']);
        $this->assertSame(0, $transport->defaultTransportCalls);
    }

    public function testAnInlineAttachmentKeepsItsContentId()
    {
        $this->queueSuccessfulSend();

        $mail = new Mail();
        $mail->setFrom('noreply@example.com');
        $mail->addTo('someone@example.org');
        $mail->setSubject('With a logo');
        $mail->setBodyHtml('<img src="cid:logo-cid">');
        $mail->addAttachment('PNG-BYTES', 'image/png', 'logo.png', 'logo-cid');

        $this->transport()->send($mail);

        $attachment = $this->http->requestJson(1)['message']['attachments'][0];
        $this->assertTrue($attachment['isInline']);
        $this->assertSame('logo-cid', $attachment['contentId']);
    }

    public function testADifferentFromIsOverriddenWithAWarningAndBecomesTheReplyTo()
    {
        $this->queueSuccessfulSend();

        $mail = new Mail();
        $mail->setFrom('someone-else@elsewhere.test', 'Someone Else');
        $mail->addTo('recipient@example.org');
        $mail->setSubject('Password reset');
        $mail->setBodyText('reset');

        $this->transport()->send($mail);

        $message = $this->http->requestJson(1)['message'];

        $this->assertSame('noreply@example.com', $message['from']['emailAddress']['address']);
        $this->assertSame(
            'someone-else@elsewhere.test',
            $message['replyTo'][0]['emailAddress']['address'],
            'The requested sender stays reachable rather than being dropped'
        );

        $warnings = implode("\n", $this->logger->warnings);
        $this->assertStringContainsString('forcing From', $warnings);
        $this->assertStringContainsString('someone-else@elsewhere.test', $warnings);
        $this->assertStringContainsString('noreply_email_address', $warnings);
    }

    public function testAMatchingFromProducesNoWarning()
    {
        $this->queueSuccessfulSend();

        $mail = new Mail();
        $mail->setFrom('NoReply@Example.com'); // Different case — still the same mailbox.
        $mail->addTo('recipient@example.org');
        $mail->setSubject('Fine');
        $mail->setBodyText('fine');

        $this->transport()->send($mail);

        $this->assertCount(0, $this->logger->warnings);
        $this->assertArrayNotHasKey('replyTo', $this->http->requestJson(1)['message']);
    }

    public function testAnExplicitReplyToIsNeverClobberedByTheForcedFrom()
    {
        $this->queueSuccessfulSend();

        $mail = new Mail();
        $mail->setFrom('someone-else@elsewhere.test');
        $mail->addTo('recipient@example.org');
        $mail->addReplyTo('support@example.org');
        $mail->setSubject('Keep my reply-to');
        $mail->setBodyText('body');

        $this->transport()->send($mail);

        $replyTo = $this->http->requestJson(1)['message']['replyTo'];
        $this->assertCount(1, $replyTo);
        $this->assertSame('support@example.org', $replyTo[0]['emailAddress']['address']);
    }

    public function testWithTheFallbackOffAFailureIsLoggedAndRethrown()
    {
        $this->config->fallback = false;
        $this->queueToken();
        $this->http->queueRaw(500, '{"error":{"code":"ServiceUnavailable"}}');

        $transport = $this->transport();

        try {
            $transport->send($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(0, $transport->defaultTransportCalls, 'The fallback is off');
            $this->assertStringContainsString('ServiceUnavailable', implode("\n", $this->logger->errors));
        }
    }

    public function testWithTheFallbackOnTheDefaultTransportIsUsedAndTheFailureIsStillLogged()
    {
        $this->config->fallback = true;
        $this->queueToken();
        $this->http->queueRaw(500, '{"error":{"code":"ServiceUnavailable"}}');

        $transport = $this->transport();

        $this->assertTrue($transport->send($this->simpleMail()));
        $this->assertSame(1, $transport->defaultTransportCalls);

        $errors = implode("\n", $this->logger->errors);
        $this->assertStringContainsString('ServiceUnavailable', $errors);
        $this->assertStringContainsString('falling back', $errors);
    }

    public function testAnUnconfiguredPluginFailsLoudlyRatherThanSilently()
    {
        $this->config->problem = 'Missivus is not configured: missing sender mailbox';

        $transport = $this->transport();

        try {
            $transport->send($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(0, $this->http->count(), 'Nothing should have been sent');
            $this->assertStringContainsString('missing sender mailbox', implode("\n", $this->logger->errors));
        }
    }

    public function testWhenSwitchedOffMatomosOwnTransportIsUsedWithoutTouchingGraph()
    {
        $this->config->enabled = false;

        $transport = $this->transport();

        $this->assertTrue($transport->send($this->simpleMail()));
        $this->assertSame(1, $transport->defaultTransportCalls);
        $this->assertSame(0, $this->http->count());
        $this->assertCount(0, $this->logger->errors, 'Being switched off is not an error');
    }

    public function testTheTestEmailPathIgnoresTheFallbackEvenWhenItIsOn()
    {
        $this->config->fallback = true;
        $this->queueToken();
        $this->http->queueRaw(500, '{"error":{"code":"ServiceUnavailable"}}');

        $transport = $this->transport();

        try {
            $transport->sendWithoutFallback($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(
                0,
                $transport->defaultTransportCalls,
                'A test that quietly succeeded over SMTP would tell the operator nothing'
            );
        }
    }

    public function testAGraphBaseUrlCarryingCredentialsNeverReachesTheLogOrTheException()
    {
        $this->config->fallback = false;
        $this->config->graphBaseUrl = 'https://admin:hunter2-correct-horse@graph.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE';

        $transport = $this->transport();

        try {
            $transport->send($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(0, $this->http->count(), 'Nothing left the process');
            $this->assertNoSecretIn($e->getMessage(), 'the rethrown exception');
            $this->assertNoSecretIn($this->logger->everything(), 'the log');
            $this->assertNoSecretIn($transport->redact($e->getMessage()), 'the test-email API response');
            $this->assertStringContainsString('graph_base_url', $e->getMessage(), 'The setting is still named');
        }
    }

    public function testALoginBaseUrlCarryingCredentialsNeverReachesTheLogOrTheException()
    {
        $this->config->fallback = false;
        $this->config->loginBaseUrl = 'https://admin:hunter2-correct-horse@login.evil.test/?client_secret=SECRET-VALUE#FRAGMENT-VALUE';

        $transport = $this->transport();

        try {
            $transport->send($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertSame(0, $this->http->count(), 'The secret must never leave the process');
            $this->assertNoSecretIn($e->getMessage(), 'the rethrown exception');
            $this->assertNoSecretIn($this->logger->everything(), 'the log');
            $this->assertNoSecretIn($transport->redact($e->getMessage()), 'the test-email API response');
        }
    }

    public function testTheTestEmailPathRedactsWhatItHandsBackToTheSuperuser()
    {
        // Exactly what API::sendTestEmail() does: send without the fallback, then render the
        // failure. Both halves are asserted, because the browser is the one place a leaked
        // endpoint credential would be read by a person rather than merely written to a file.
        $this->config->loginBaseUrl = 'https://admin:hunter2-correct-horse@login.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE';

        $transport = $this->transport();

        try {
            $transport->sendWithoutFallback($this->simpleMail());
            $this->fail('Expected a GraphException');
        } catch (GraphException $e) {
            $this->assertNoSecretIn($transport->redact($e->getMessage()), 'the API response');
        }
    }

    public function testAFailureThatEchoesTheEndpointStillLosesItsCredentialsOnTheWayToTheLog()
    {
        // The belt to the Endpoint braces: even if some future message did carry the raw URL, the
        // transport's final redaction pass has to blank it before anything is written.
        $transport = $this->transport();

        $redacted = $transport->redact(
            'talking to https://admin:hunter2-correct-horse@graph.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE'
        );

        $this->assertNoSecretIn($redacted, 'the redaction pass');
        $this->assertStringContainsString('graph.evil.test', $redacted, 'The host survives, and is the useful part');
    }

    public function testTheConfiguredClientSecretNeverSurvivesTheRedactionPass()
    {
        $transport = $this->transport();

        $this->assertStringNotContainsString(
            'super-secret-value',
            $transport->redact('Entra says super-secret-value was rejected')
        );
    }

    public function testMatomosTestModeDoesNotBypassTheTransport()
    {
        // Matomo's PHPUnit bootstrap defines PIWIK_TEST_MODE unconditionally and tests/run.php
        // mirrors it. A guard on that constant once sent every send() straight to PHPMailer under
        // ./console tests:run, so this whole class passed without one request reaching Graph.
        $this->assertTrue(defined('PIWIK_TEST_MODE'), 'Both runners define PIWIK_TEST_MODE');

        $this->queueSuccessfulSend();
        $transport = $this->transport();

        $this->assertTrue($transport->send($this->simpleMail()));
        $this->assertSame(2, $this->http->count(), 'The token request and the send reached the fake Graph endpoint');
        $this->assertSame(0, $transport->defaultTransportCalls, 'PHPMailer was not used');
    }

    /**
     * @param string $text
     * @param string $where
     * @return void
     */
    private function assertNoSecretIn($text, $where)
    {
        foreach (array('hunter2-correct-horse', 'admin:', 'TOKEN-VALUE', 'SECRET-VALUE', 'FRAGMENT-VALUE') as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'Leaked into ' . $where);
        }
    }

    /**
     * @return Mail
     */
    private function simpleMail()
    {
        $mail = new Mail();
        $mail->setFrom('noreply@example.com');
        $mail->addTo('recipient@example.org');
        $mail->setSubject('Subject');
        $mail->setBodyText('Body');

        return $mail;
    }

    private function queueToken()
    {
        $this->http->queueJson(200, array('access_token' => 'token-abc', 'expires_in' => 3600));
    }

    private function queueSuccessfulSend()
    {
        $this->queueToken();
        $this->http->queueRaw(202);
    }

    /**
     * @return ObservableGraphTransport
     */
    private function transport()
    {
        return new ObservableGraphTransport(
            $this->config,
            $this->http,
            new ArrayTokenCache(),
            $this->logger
        );
    }
}
