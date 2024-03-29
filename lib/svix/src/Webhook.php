<?php

namespace Orkestapay_Svix;

class Orkestapay_Webhook
{
    const SECRET_PREFIX = 'whsec_';
    const TOLERANCE = 5 * 60;
    private $secret;

    public function __construct($secret)
    {
        if (substr($secret, 0, strlen(Orkestapay_Webhook::SECRET_PREFIX)) === Orkestapay_Webhook::SECRET_PREFIX) {
            $secret = substr($secret, strlen(Orkestapay_Webhook::SECRET_PREFIX));
        }
        $this->secret = base64_decode($secret);
    }

    public static function fromRaw($secret)
    {
        $obj = new self();
        $obj->secret = $secret;
        return $obj;
    }

    public function verify($payload, $headers)
    {
        if (isset($headers['svix-id']) && isset($headers['svix-timestamp']) && isset($headers['svix-signature'])) {
            $msgId = $headers['svix-id'];
            $msgTimestamp = $headers['svix-timestamp'];
            $msgSignature = $headers['svix-signature'];
        } elseif (isset($headers['webhook-id']) && isset($headers['webhook-timestamp']) && isset($headers['webhook-signature'])) {
            $msgId = $headers['webhook-id'];
            $msgTimestamp = $headers['webhook-timestamp'];
            $msgSignature = $headers['webhook-signature'];
        } else {
            throw new Exception\SvixWebhookVerificationException('Missing required headers');
        }

        $timestamp = self::verifyTimestamp($msgTimestamp);

        $signature = $this->sign($msgId, $timestamp, $payload);
        $expectedSignature = explode(',', $signature, 2)[1];

        $passedSignatures = explode(' ', $msgSignature);
        foreach ($passedSignatures as $versionedSignature) {
            $sigParts = explode(',', $versionedSignature, 2);
            $version = $sigParts[0];
            $passedSignature = $sigParts[1];

            if (strcmp($version, 'v1') != 0) {
                continue;
            }

            if (hash_equals($expectedSignature, $passedSignature)) {
                return json_decode($payload);
            }
        }
        throw new Exception\SvixWebhookVerificationException('No matching signature found');
    }

    public function sign($msgId, $timestamp, $payload)
    {
        $is_positive_integer = ctype_digit($timestamp);
        if (!$is_positive_integer) {
            throw new Exception\SvixWebhookSigningException('Invalid timestamp');
        }
        $toSign = "{$msgId}.{$timestamp}.{$payload}";
        $hex_hash = hash_hmac('sha256', $toSign, $this->secret);
        $signature = base64_encode(pack('H*', $hex_hash));
        return "v1,{$signature}";
    }

    private function verifyTimestamp($timestampHeader)
    {
        $now = time();
        try {
            $timestamp = intval($timestampHeader, 10);
        } catch (\Exception $e) {
            throw new Exception\SvixWebhookVerificationException('Invalid Signature Headers');
        }

        if ($timestamp < $now - Orkestapay_Webhook::TOLERANCE) {
            throw new Exception\SvixWebhookVerificationException('Message timestamp too old');
        }
        if ($timestamp > $now + Orkestapay_Webhook::TOLERANCE) {
            throw new Exception\SvixWebhookVerificationException('Message timestamp too new');
        }
        return $timestamp;
    }
}
