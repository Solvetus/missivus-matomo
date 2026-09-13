<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Missivus\Mail;

use Piwik\Mail;
use Piwik\Mail\Transport;
use Piwik\Plugins\Missivus\Configuration\ConfigurationInterface;
use Solvetus\Missivus\Attachment;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Contract\TokenCacheInterface;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\GraphMailer;
use Solvetus\Missivus\Message;
use Solvetus\Missivus\Redactor;

/**
 * The transport Matomo gets instead of the PHPMailer one.
 *
 * Bound to the container key `Piwik\Mail\Transport` in config/config.php — the seam added by
 * matomo-org/matomo#14041, which is what Piwik\Mail::send() resolves. Extending the stock class
 * rather than merely duck-typing it means anything type-hinting Piwik\Mail\Transport still works,
 * and the optional fallback is literally parent::send() — the original PHPMailer path, with no
 * second container lookup and no way to recurse.
 */
class GraphTransport extends Transport
{
    /** @var ConfigurationInterface */
    private $config;

    /** @var HttpClientInterface */
    private $http;

    /** @var TokenCacheInterface */
    private $cache;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param ConfigurationInterface $config
     * @param HttpClientInterface $http
     * @param TokenCacheInterface $cache
     * @param LoggerInterface     $logger
     */
    public function __construct(
        ConfigurationInterface $config,
        HttpClientInterface $http,
        TokenCacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->http = $http;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * @param Mail $mail
     * @return bool
     * @throws GraphException When Graph fails and the fallback is off.
     */
    public function send(Mail $mail)
    {
        // Not an error: the switch is off, so Matomo's configured transport is the right one.
        if (!$this->config->isEnabled()) {
            return $this->sendWithDefaultTransport($mail);
        }

        try {
            $this->deliver($mail);
            return true;
        } catch (GraphException $e) {
            return $this->handleFailure($e, $mail);
        }
    }

    /**
     * Matomo's stock PHPMailer transport, reached through the parent class.
     *
     * Isolated in its own method purely so tests can observe whether the fallback was taken
     * without standing up PHPMailer and an SMTP server.
     *
     * @param Mail $mail
     * @return bool
     */
    protected function sendWithDefaultTransport(Mail $mail)
    {
        return parent::send($mail);
    }

    /**
     * Send over Graph with no fallback, whatever the setting says. Used by the test-email button:
     * a test that quietly succeeds over SMTP tells the operator nothing about their tenant.
     *
     * @param Mail $mail
     * @return void
     * @throws GraphException
     */
    public function sendWithoutFallback(Mail $mail)
    {
        try {
            $this->deliver($mail);
        } catch (GraphException $e) {
            throw $e->redactedWith($this->redactor());
        }
    }

    /**
     * The single redaction pass every string leaving this class goes through, exposed because the
     * API method that renders a failed test email to a superuser is the one caller outside it.
     *
     * @param string $text
     * @return string
     */
    public function redact($text)
    {
        return $this->redactor()->redact($text);
    }

    /**
     * A redactor loaded with whatever literal secrets the current configuration holds.
     *
     * Built per call rather than cached: a configuration broken enough that getCredentials()
     * throws must still get the shape-matching layer, and must not leave a literal-less redactor
     * behind for the next send once it is fixed.
     *
     * @return Redactor
     */
    private function redactor()
    {
        try {
            return new Redactor($this->config->getCredentials()->getSecretLiterals());
        } catch (\Exception $e) {
            // No credentials to blank by literal. The patterns still apply, and they are the layer
            // that catches a value we were never given in the first place.
            return new Redactor();
        }
    }

    /**
     * @param Mail $mail
     * @return void
     * @throws GraphException
     */
    private function deliver(Mail $mail)
    {
        $problem = $this->config->getConfigurationProblem();

        if ($problem !== '') {
            throw new GraphException($problem);
        }

        $credentials = $this->config->getCredentials();
        $redactor = $this->redactor();

        $tokens = new TokenProvider(
            $credentials,
            $this->http,
            $this->cache,
            $redactor,
            $this->config->getLoginBaseUrl()
        );

        $mailer = new GraphMailer(
            $tokens,
            $this->http,
            $redactor,
            $this->config->getSenderMailbox(),
            $this->config->shouldSaveToSentItems(),
            $this->config->getGraphBaseUrl(),
            $this->logger
        );

        $mailer->send($this->toMessage($mail));
    }

    /**
     * @param GraphException $e
     * @param Mail           $mail
     * @return bool
     * @throws GraphException
     */
    private function handleFailure(GraphException $e, Mail $mail)
    {
        // Logged at error level either way — the brief's hard rule is that nothing is swallowed.
        $this->logger->error($this->redact('Missivus: sending over Microsoft Graph failed: ' . $e->getMessage()));

        if (!$this->config->shouldFallBackToDefaultTransport()) {
            throw $e->redactedWith($this->redactor());
        }

        $this->logger->error('Missivus: falling back to Matomo\'s default mail transport');

        return $this->sendWithDefaultTransport($mail);
    }

    /**
     * Translate Matomo's mail object into the portable one.
     *
     * Piwik\Mail exposes no CC API, so Message::addCc() is never reached from here. It exists for
     * the WordPress sibling, which vendors the same class.
     *
     * @param Mail $mail
     * @return Message
     */
    private function toMessage(Mail $mail)
    {
        $message = new Message();

        $message->setSubject($mail->getSubject());
        $message->setHtmlBody($mail->getBodyHtml());
        $message->setTextBody($mail->getBodyText());

        foreach ($mail->getRecipients() as $address => $name) {
            $message->addTo($address, $name);
        }

        foreach ($mail->getBccs() as $address => $name) {
            $message->addBcc($address, $name);
        }

        foreach ($mail->getReplyTos() as $address => $name) {
            $message->addReplyTo($address, $name);
        }

        foreach ($mail->getAttachments() as $attachment) {
            $message->addAttachment(new Attachment(
                isset($attachment['filename']) ? $attachment['filename'] : '',
                isset($attachment['content']) ? $attachment['content'] : '',
                isset($attachment['mimetype']) ? $attachment['mimetype'] : '',
                isset($attachment['cid']) ? (string) $attachment['cid'] : ''
            ));
        }

        $this->applyForcedFrom($mail, $message);

        return $message;
    }

    /**
     * App-only Graph sends as /users/{sender} and Exchange rejects a mismatched From, so the
     * configured mailbox always wins. When Matomo asked for something else, say so loudly and keep
     * the requested address reachable as a Reply-To rather than dropping it.
     *
     * @param Mail    $mail
     * @param Message $message
     * @return void
     */
    private function applyForcedFrom(Mail $mail, Message $message)
    {
        $sender = $this->config->getSenderMailbox();
        $requested = trim((string) $mail->getFrom());

        $message->setFrom($sender, $mail->getFromName());

        if ($requested === '' || strcasecmp($requested, $sender) === 0) {
            return;
        }

        $this->logger->warning($this->redact(
            'Missivus: forcing From to ' . $sender . '; Matomo asked for ' . $requested
            . '. Set [General] noreply_email_address to the shared mailbox to silence this.'
        ));

        // Only when nothing else claimed Reply-To, so an explicit one is never clobbered.
        if (!$message->hasReplyTo()) {
            $message->addReplyTo($requested, $mail->getFromName());
        }
    }
}
